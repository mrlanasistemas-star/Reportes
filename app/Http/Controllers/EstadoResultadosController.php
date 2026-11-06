<?php

namespace App\Http\Controllers;

use App\Models\EstadoResultados\EstadoResultadosExport;
use App\Models\Period;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Classification\ClassificationService;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Export\EstadoResultadosExcelExporter;
use App\Services\EstadoResultados\Export\EstadoResultadosPdfExporter;
use App\Services\EstadoResultados\Import\EstadoResultadosDuplicateUploadException;
use App\Services\EstadoResultados\Import\EstadoResultadosImportException;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use App\Services\EstadoResultados\Reporting\EstadoResultadosAbilities;
use App\Services\EstadoResultados\Reporting\EstadoResultadosApprovalService;
use App\Services\EstadoResultados\Reporting\EstadoResultadosLockedException;
use App\Services\EstadoResultados\Reporting\EstadoResultadosRedactor;
use App\Services\EstadoResultados\Reporting\EstadoResultadosReportResolver;
use App\Services\EstadoResultados\Reporting\PeriodLockGuard;
use App\Services\EstadoResultados\Reporting\ReportStatus;
use App\Services\EstadoResultados\Support\ProcessingLockedException;
use App\Services\EstadoResultados\Support\Treatment;
use App\Services\Pdf\PdfRenderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Estado de Resultados. Web, Excel y PDF obtienen el dataset del MISMO resolver
 * (EstadoResultadosReportResolver): snapshot aprobado o cálculo en vivo, con la
 * redacción de nómina/DG aplicada en backend según los permisos del usuario.
 * Toda escritura se rechaza con HTTP 423 si el periodo tiene una versión aprobada vigente.
 */
class EstadoResultadosController extends Controller
{
    public function __construct(
        private readonly EstadoResultadosDatasetBuilder $builder,
        private readonly EstadoResultadosReportResolver $resolver,
        private readonly PeriodLockGuard $lock,
        private readonly EstadoResultadosAbilities $abilities,
    ) {}

    public function index(Request $request): Response
    {
        $periods = Period::query()
            ->where('type', 'monthly')
            ->where('code', 'not like', 'TEST-%')
            ->orderByDesc('start_date')
            ->get(['id', 'code', 'name', 'year', 'month', 'start_date', 'end_date', 'type'])
            ->map(fn (Period $p) => [
                'id' => $p->id, 'code' => $p->code, 'label' => $p->label,
                'start' => $p->start_date->format('Y-m-d'), 'end' => $p->end_date->format('Y-m-d'),
            ])->values();

        return Inertia::render('EstadoResultados/Index', [
            'periods' => $periods,
            'initialPeriodId' => (int) ($request->integer('periodo') ?: ($periods->first()['id'] ?? 0)),
            'initialScope' => (string) ($request->string('alcance') ?: EstadoResultadosDatasetBuilder::CONSOLIDADO),
            'slots' => collect(EstadoResultadosUploadService::SLOTS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'treatments' => Treatment::catalog(),
            'adjustmentStates' => AdjustmentService::STATES,
            'can' => $this->abilities->for($request->user()),
            'reportStatuses' => ReportStatus::catalog(),
            'uploadRules' => [
                'max_mb' => EstadoResultadosUploadService::maxMb(),
                'extensions' => EstadoResultadosUploadService::EXTENSIONS,
                'accept' => '.'.implode(',.', EstadoResultadosUploadService::EXTENSIONS),
            ],
            'toleranceDays' => (int) config('estado_resultados.conciliacion.tolerancia_dias', 3),
        ]);
    }

    public function dataset(Request $request, Period $period): JsonResponse
    {
        @ini_set('memory_limit', '1024M');
        $this->ensureMonthly($period);
        $version = $this->versionParam($request);

        try {
            return response()->json([
                'dataset' => $this->resolver->resolve($period, $this->scope($request), $request->user(), $version),
                'scopes' => $this->resolver->scopes($period, $version),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ── Cargas ─────────────────────────────────────────────────────────

    public function upload(Request $request, Period $period, EstadoResultadosUploadService $service): JsonResponse
    {
        $this->ensureMonthly($period);
        $this->lock->assertUnlocked($period);
        $data = $request->validate([
            'slot' => ['required', 'in:'.implode(',', array_keys(EstadoResultadosUploadService::SLOTS))],
            'file' => ['required', 'file'],
        ], [
            'slot.required' => 'Elige la cuenta del estado de cuenta.',
            'slot.in' => 'Cuenta no válida.',
            'file.required' => 'Selecciona el archivo del estado de cuenta.',
            'file.file' => 'No se recibió el archivo completo. Vuelve a intentarlo.',
        ]);

        try {
            // Extensión, contenido, tamaño, estructura y celdas se validan en el servicio
            // para que TODO rechazo quede registrado como intento con sus errores por fila.
            $upload = $service->upload($period, $data['file'], $data['slot'], $request->user()?->id);
        } catch (EstadoResultadosDuplicateUploadException $e) {
            if ($e->existing->account_slot === $data['slot']) {
                // Reintento idempotente: mismo archivo, misma cuenta → no se duplica nada.
                return response()->json([
                    'message' => $e->getMessage(),
                    'idempotent' => true,
                    'attempt_id' => $e->attemptId(),
                    'upload' => ['id' => $e->existing->id, 'warnings' => $e->existing->warnings ?? [], 'control_ok' => $e->existing->control_ok],
                ]);
            }

            return $this->importError($e);
        } catch (EstadoResultadosImportException $e) {
            return $this->importError($e);
        }

        return response()->json([
            'message' => 'Archivo cargado: '.$upload->movements_count.' movimientos. Presiona "Procesar conciliación" para conciliar y clasificar.',
            'idempotent' => false,
            'upload' => ['id' => $upload->id, 'warnings' => $upload->warnings ?? [], 'control_ok' => $upload->control_ok],
        ]);
    }

    public function uploadAttempts(Request $request, Period $period, EstadoResultadosUploadService $service): JsonResponse
    {
        return response()->json(['attempts' => $service->attempts($period)]);
    }

    public function process(Request $request, Period $period, EstadoResultadosProcessor $processor): JsonResponse
    {
        @ini_set('memory_limit', '1024M');
        $this->ensureMonthly($period);
        $this->lock->assertUnlocked($period);
        try {
            $run = $processor->process($period, $request->user()?->id);
        } catch (ProcessingLockedException $e) {
            return response()->json(['message' => $e->getMessage(), 'processing' => true], 409);
        } catch (EstadoResultadosLockedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('estado_resultados.process_failed', ['period_id' => $period->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'No se pudo procesar la conciliación: '.$e->getMessage()], 500);
        }

        return response()->json(['message' => 'Conciliación procesada.', 'summary' => $run->summary]);
    }

    // ── Clasificación ──────────────────────────────────────────────────

    public function classify(Request $request, Period $period, ClassificationService $service): JsonResponse
    {
        $this->ensureMonthly($period);
        $this->lock->assertUnlocked($period);
        $data = $request->validate([
            'fingerprints' => ['required', 'array', 'min:1', 'max:2000'],
            'fingerprints.*' => ['string', 'max:64'],
            'treatment' => ['required', 'string', 'in:'.implode(',', Treatment::ASSIGNABLE)],
            'notes' => ['required', 'string', 'min:3', 'max:2000'],
        ], ['notes.required' => 'Escribe el motivo de la clasificación.', 'notes.min' => 'Escribe el motivo de la clasificación.']);

        $items = $this->itemsByFingerprint($request, $period, $data['fingerprints']);
        if ($items === []) {
            return response()->json(['message' => 'No se encontraron los movimientos seleccionados.'], 422);
        }
        $count = $service->classify($period, $items, $data['treatment'], $data['notes'], $request->user()?->id);

        return response()->json(['message' => $count.' movimiento(s) clasificados como "'.Treatment::label($data['treatment']).'".', 'count' => $count]);
    }

    public function acceptSuggestions(Request $request, Period $period, ClassificationService $service): JsonResponse
    {
        $this->ensureMonthly($period);
        $this->lock->assertUnlocked($period);
        $data = $request->validate([
            'fingerprints' => ['required', 'array', 'min:1', 'max:2000'],
            'fingerprints.*' => ['string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $items = $this->itemsByFingerprint($request, $period, $data['fingerprints'], withSuggestion: true);
        $byTreatment = [];
        foreach ($items as $item) {
            if ($item['current'] === Treatment::PENDIENTE && $item['suggested'] && Treatment::isAssignable($item['suggested'])) {
                $byTreatment[$item['suggested']][] = $item;
            }
        }
        $count = 0;
        $notes = trim((string) ($data['notes'] ?? '')) ?: 'Aceptó la sugerencia automática.';
        foreach ($byTreatment as $treatment => $list) {
            $count += $service->classify($period, $list, $treatment, $notes, $request->user()?->id);
        }

        return response()->json(['message' => $count.' sugerencia(s) aceptadas.', 'count' => $count]);
    }

    public function history(Request $request, Period $period, ClassificationService $service): JsonResponse
    {
        $fp = (string) $request->query('fingerprint', '');
        $movement = collect($this->resolver->unredacted($period, EstadoResultadosDatasetBuilder::CONSOLIDADO)['movements'] ?? [])->firstWhere('fingerprint', $fp);
        if ($movement) {
            $this->ensureCanSeeMovement($request, $movement);
        }

        return response()->json(['history' => $service->history($period->id, $fp)]);
    }

    // ── Ajustes ────────────────────────────────────────────────────────

    public function saveAdjustment(Request $request, Period $period, AdjustmentService $service): JsonResponse
    {
        $this->ensureMonthly($period);
        $this->lock->assertUnlocked($period);
        $data = $request->validate([
            'scope' => ['required', 'string', 'max:80'],
            'concept' => ['required', 'string', 'in:'.implode(',', array_keys(AdjustmentService::CONCEPTS))],
            'state' => ['required', 'string', 'in:'.implode(',', AdjustmentService::STATES)],
            'amount' => ['nullable'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->validScope($period, $data['scope']);

        try {
            $service->save($period, $data['scope'], $data['concept'], $data['state'], $data['amount'] ?? null, $data['notes'] ?? null, $request->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Ajuste guardado.']);
    }

    public function adjustmentLogs(Request $request, Period $period, AdjustmentService $service): JsonResponse
    {
        return response()->json(['logs' => $service->logs($period->id, $this->scope($request))]);
    }

    // ── Aprobación y versiones ─────────────────────────────────────────

    public function approve(Request $request, Period $period, EstadoResultadosApprovalService $service): JsonResponse
    {
        // La aprobación congela el dataset de los ~14 alcances; con 128 MB no alcanza.
        @ini_set('memory_limit', '2048M');
        $this->ensureMonthly($period);
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'expected_hash' => ['nullable', 'string', 'size:64'],
        ]);
        $version = $service->approve($period, $request->user(), $data['notes'] ?? null, $data['expected_hash'] ?? null);

        return response()->json([
            'message' => 'Estado de Resultados de '.$period->label.' aprobado (versión '.$version->version.'). Las cifras quedaron congeladas.',
            'version' => EstadoResultadosApprovalService::summary($version->load(['approver:id,name', 'reopener:id,name'])),
        ]);
    }

    public function openNewVersion(Request $request, Period $period, EstadoResultadosApprovalService $service): JsonResponse
    {
        $this->ensureMonthly($period);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'reason.required' => 'Escribe el motivo para abrir una nueva versión.',
            'reason.min' => 'Escribe el motivo para abrir una nueva versión (mínimo 10 caracteres).',
        ]);
        $previous = $service->openNewVersion($period, $request->user(), $data['reason']);

        return response()->json([
            'message' => 'Se abrió la versión '.($previous->version + 1).' de '.$period->label.'. La versión '.$previous->version.' sigue vigente hasta que se apruebe la nueva.',
            'draft_version' => $previous->version + 1,
            'previous' => EstadoResultadosApprovalService::summary($previous->load(['approver:id,name', 'reopener:id,name'])),
        ]);
    }

    public function versions(Request $request, Period $period, EstadoResultadosApprovalService $service): JsonResponse
    {
        return response()->json($service->history($period));
    }

    // ── Detalle restringido (nómina / DG) ──────────────────────────────

    public function payrollDetail(Request $request, Period $period): JsonResponse
    {
        return $this->restrictedDetail($request, $period, EstadoResultadosRedactor::NOMINA_SECTIONS, fn ($m) => EstadoResultadosRedactor::isPayroll($m));
    }

    public function dgDetail(Request $request, Period $period): JsonResponse
    {
        return $this->restrictedDetail($request, $period, EstadoResultadosRedactor::DG_SECTIONS, fn ($m) => EstadoResultadosRedactor::isDg($m));
    }

    // ── Exportes ───────────────────────────────────────────────────────

    public function exportExcel(Request $request, Period $period, EstadoResultadosExcelExporter $exporter)
    {
        @ini_set('memory_limit', '1024M');
        $this->ensureMonthly($period);
        $dataset = $this->exportDataset($request, $period);
        if ($dataset instanceof JsonResponse) {
            return $dataset;
        }
        $name = $this->fileName($dataset, 'xlsx');
        $path = storage_path('app/tmp/estado-resultados-'.Str::uuid().'.xlsx');
        $exporter->export($dataset, $path);
        $this->logExport($request, $period, $dataset, 'xlsx', $name);

        return response()->download($path, $name, $this->exportHeaders($dataset, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))->deleteFileAfterSend();
    }

    public function exportPdf(Request $request, Period $period, EstadoResultadosPdfExporter $exporter)
    {
        @ini_set('memory_limit', '1024M');
        $this->ensureMonthly($period);
        $dataset = $this->exportDataset($request, $period);
        if ($dataset instanceof JsonResponse) {
            return $dataset;
        }
        $name = $this->fileName($dataset, 'pdf');
        $path = storage_path('app/tmp/estado-resultados-'.Str::uuid().'.pdf');

        try {
            $exporter->export($dataset, $path);
        } catch (PdfRenderException $e) {
            $ref = 'ER-PDF-'.now()->format('ymdHis').'-'.Str::upper(Str::random(4));
            Log::error('estado_resultados.pdf_failed', ['ref' => $ref, 'period_id' => $period->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'No se pudo generar el PDF. Referencia: '.$ref], 500);
        }
        $this->logExport($request, $period, $dataset, 'pdf', $name);

        return response()->download($path, $name, $this->exportHeaders($dataset, 'application/pdf'))->deleteFileAfterSend();
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function exportDataset(Request $request, Period $period): array|JsonResponse
    {
        try {
            return $this->resolver->resolve($period, $this->scope($request), $request->user(), $this->versionParam($request));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function exportHeaders(array $dataset, string $contentType): array
    {
        $report = $dataset['meta']['report'];

        return [
            'Content-Type' => $contentType,
            'X-Dataset-Hash' => $dataset['meta']['dataset_hash'],
            'X-Report-Version' => (string) $report['version'],
            'X-Report-Status' => $report['status'],
            'X-Report-Preliminary' => $report['preliminary'] ? '1' : '0',
        ];
    }

    private function importError(EstadoResultadosImportException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'details' => $e->details(),
            'errors' => $e->errors(),
            'attempt_id' => $e->attemptId(),
        ], $e->status());
    }

    private function restrictedDetail(Request $request, Period $period, array $sections, callable $filter): JsonResponse
    {
        $this->ensureMonthly($period);
        try {
            $d = $this->resolver->unredacted($period, $this->scope($request), $this->versionParam($request));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        // La otra categoría restringida se sigue respetando (p. ej. nómina de DG sin ver_dg).
        $d = app(EstadoResultadosRedactor::class)->apply(
            $d,
            $this->abilities->can($request->user(), 'ver_nomina'),
            $this->abilities->can($request->user(), 'ver_dg'),
        );

        return response()->json([
            'sections' => array_values(array_filter($d['statement'], fn ($b) => ($b['type'] ?? null) === 'section' && in_array($b['key'], $sections, true))),
            'movements' => array_values(array_filter($d['movements'], $filter)),
            'report' => $d['meta']['report'],
        ]);
    }

    private function itemsByFingerprint(Request $request, Period $period, array $fingerprints, bool $withSuggestion = false): array
    {
        $wanted = array_flip($fingerprints);
        $dataset = $this->resolver->unredacted($period, EstadoResultadosDatasetBuilder::CONSOLIDADO);
        $out = [];
        foreach ($dataset['movements'] as $m) {
            if (isset($wanted[$m['fingerprint']])) {
                $this->ensureCanSeeMovement($request, $m);
                $out[] = ['fingerprint' => $m['fingerprint'], 'source' => $m['source'], 'current' => $m['treatment']]
                    + ($withSuggestion ? ['suggested' => $m['suggested_treatment']] : []);
            }
        }

        return $out;
    }

    /** Un movimiento de nómina / DG sólo lo consulta o reclasifica quien tiene el permiso de detalle. */
    private function ensureCanSeeMovement(Request $request, array $movement): void
    {
        if (EstadoResultadosRedactor::isPayroll($movement) && ! $this->abilities->can($request->user(), 'ver_nomina')) {
            abort(403, 'Tu rol no permite consultar el detalle de nómina en el Estado de Resultados.');
        }
        if (EstadoResultadosRedactor::isDg($movement) && ! $this->abilities->can($request->user(), 'ver_dg')) {
            abort(403, 'Tu rol no permite consultar el detalle de Dirección General en el Estado de Resultados.');
        }
    }

    private function versionParam(Request $request): ?int
    {
        $v = $request->query('version', $request->input('version'));
        if ($v === null || $v === '') {
            return null;
        }
        abort_unless(ctype_digit((string) $v) && (int) $v > 0, 422, 'Versión no válida.');

        return (int) $v;
    }

    private function scope(Request $request): string
    {
        $scope = trim((string) $request->query('alcance', $request->input('alcance', EstadoResultadosDatasetBuilder::CONSOLIDADO)));

        return $scope === '' ? EstadoResultadosDatasetBuilder::CONSOLIDADO : mb_substr($scope, 0, 80);
    }

    private function validScope(Period $period, string $scope): void
    {
        $keys = array_column($this->builder->scopes($period), 'key');
        abort_unless(in_array($scope, $keys, true), 422, 'Alcance no válido.');
    }

    private function ensureMonthly(Period $period): void
    {
        abort_unless($period->type === 'monthly', 422, 'El Estado de Resultados se genera sobre periodos mensuales.');
    }

    private function fileName(array $dataset, string $ext): string
    {
        $scope = Str::slug($dataset['meta']['scope']['key'] === EstadoResultadosDatasetBuilder::CONSOLIDADO ? 'consolidado' : $dataset['meta']['scope']['key']);
        $report = $dataset['meta']['report'];
        $suffix = '_v'.$report['version'].($report['preliminary'] ? '_PRELIMINAR' : ($report['status'] === ReportStatus::APROBADO ? '_APROBADO' : ''));

        return 'MR_LANA_Estado_Resultados_'.$dataset['meta']['period']['code'].'_'.$scope.$suffix.'.'.$ext;
    }

    private function logExport(Request $request, Period $period, array $dataset, string $format, string $name): void
    {
        EstadoResultadosExport::query()->create([
            'period_id' => $period->id,
            'scope_key' => $dataset['meta']['scope']['key'],
            'format' => $format,
            'dataset_hash' => $dataset['meta']['dataset_hash'],
            'version_number' => $dataset['meta']['report']['version'],
            'report_status' => $dataset['meta']['report']['status'],
            'preliminary' => (bool) $dataset['meta']['report']['preliminary'],
            'file_name' => $name,
            'user_id' => $request->user()?->id,
        ]);
    }
}
