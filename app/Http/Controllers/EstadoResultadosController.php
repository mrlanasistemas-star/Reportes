<?php

namespace App\Http\Controllers;

use App\Models\EstadoResultados\EstadoResultadosExport;
use App\Models\Period;
use App\Services\Access\DataScopeService;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Calculation\RadiographyBaseProvider;
use App\Services\EstadoResultados\Classification\ClassificationService;
use App\Services\EstadoResultados\Comparatives\ComparativesWarmer;
use App\Services\EstadoResultados\Comparatives\ComparisonAccessException;
use App\Services\EstadoResultados\Comparatives\ComparisonService;
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
use App\Support\TechnicalErrorReference;
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
        private readonly DataScopeService $dataScope,
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

        // Un enlace guardado puede apuntar a un periodo que ya no existe (o que no es mensual):
        // se abre el más reciente y se avisa, en lugar de un error técnico y un selector vacío.
        $requested = $request->integer('periodo');
        $latestId = (int) ($periods->first()['id'] ?? 0);
        $initialId = $requested && $periods->contains('id', $requested) ? $requested : $latestId;
        $periodNotice = $requested && $initialId !== $requested && $latestId
            ? 'El periodo del enlace ya no está disponible; te mostramos '.($periods->first()['label'] ?? 'el más reciente').'.'
            : null;

        return Inertia::render('EstadoResultados/Index', [
            'periods' => $periods,
            'initialPeriodId' => $initialId,
            'periodNotice' => $periodNotice,
            'initialScope' => $this->initialScope($request),
            // Alcance de datos por sucursal del usuario (DataScopeService): general = puede ver el consolidado.
            'branchScope' => $request->user() ? $this->dataScope->describe($request->user()) + ['allowed_names' => $this->dataScope->allowedBranchNames($request->user())] : null,
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
        $scope = $this->scope($request);
        $this->authorizeScope($request, $scope);

        try {
            $t0 = microtime(true);
            // Una sola foto de Radiografía para alcances + dataset de esta petición
            // (RadiographyBaseProvider es #[Scoped]: el builder del resolver comparte la sesión).
            $response = $this->builder->session(fn () => response()->json([
                'dataset' => $this->resolver->resolve($period, $scope, $request->user(), $version),
                'scopes' => $this->visibleScopes($request, $this->resolver->scopes($period, $version)),
            ]));
            // Deja calentando en segundo plano las comparativas que la pantalla pedirá enseguida.
            if ($version === null) {
                ComparativesWarmer::dispatchFor($period);
            }
            $base = app(RadiographyBaseProvider::class)->stats;
            $response->headers->set('Server-Timing', sprintf('er;desc="Estado de Resultados";dur=%.0f, base;desc="Radiografia %s";dur=%.0f',
                (microtime(true) - $t0) * 1000, $base['computed'] > 0 ? 'calculada' : 'en cache', $base['ms_computing']));

            return $response;
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ── Cargas ─────────────────────────────────────────────────────────

    public function upload(Request $request, Period $period, EstadoResultadosUploadService $service): JsonResponse
    {
        $this->ensureMonthly($period);
        $this->dataScope->requireGeneral($request->user(), 'La carga de estados de cuenta del Estado de Resultados');
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
        $this->dataScope->requireGeneral($request->user(), 'El historial de cargas del Estado de Resultados');

        return response()->json(['attempts' => $service->attempts($period)]);
    }

    public function process(Request $request, Period $period, EstadoResultadosProcessor $processor): JsonResponse
    {
        @ini_set('memory_limit', '1024M');
        $this->ensureMonthly($period);
        $this->dataScope->requireGeneral($request->user(), 'El procesamiento de la conciliación');
        $this->lock->assertUnlocked($period);
        try {
            $run = $processor->process($period, $request->user()?->id);
        } catch (ProcessingLockedException $e) {
            return response()->json(['message' => $e->getMessage(), 'processing' => true], 409);
        } catch (EstadoResultadosLockedException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            // Motivo de negocio redactado para el usuario (p. ej. datos faltantes).
            return response()->json(['message' => 'No se pudo procesar la conciliación: '.$e->getMessage()], 422);
        } catch (\Throwable $e) {
            // Nunca se muestra el detalle técnico (SQL, rutas): referencia para Sistemas, detalle en el log.
            $ref = TechnicalErrorReference::forCurrentRequest();
            Log::error('estado_resultados.process_failed', ['period_id' => $period->id, 'error_ref' => $ref, 'error' => $e->getMessage(), 'exception' => $e::class]);

            return response()->json(['message' => TechnicalErrorReference::message($ref), 'reference' => $ref], 500);
        }

        return response()->json(['message' => 'Conciliación procesada.', 'summary' => $run->summary]);
    }

    // ── Clasificación ──────────────────────────────────────────────────

    public function classify(Request $request, Period $period, ClassificationService $service): JsonResponse
    {
        $this->ensureMonthly($period);
        $this->dataScope->requireGeneral($request->user(), 'La clasificación de movimientos');
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
        $this->dataScope->requireGeneral($request->user(), 'La clasificación de movimientos');
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
        $this->dataScope->requireGeneral($request->user(), 'El historial de clasificación');
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
        $this->authorizeScope($request, $data['scope']);

        try {
            $service->save($period, $data['scope'], $data['concept'], $data['state'], $data['amount'] ?? null, $data['notes'] ?? null, $request->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Ajuste guardado.']);
    }

    public function adjustmentLogs(Request $request, Period $period, AdjustmentService $service): JsonResponse
    {
        $scope = $this->scope($request);
        $this->authorizeScope($request, $scope);

        return response()->json(['logs' => $service->logs($period->id, $scope)]);
    }

    // ── Aprobación y versiones ─────────────────────────────────────────

    public function approve(Request $request, Period $period, EstadoResultadosApprovalService $service): JsonResponse
    {
        // La aprobación congela el dataset de los ~14 alcances; con 128 MB no alcanza.
        @ini_set('memory_limit', '2048M');
        $this->ensureMonthly($period);
        $this->dataScope->requireGeneral($request->user(), 'La aprobación del Estado de Resultados');
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
        $this->dataScope->requireGeneral($request->user(), 'Abrir una nueva versión del Estado de Resultados');
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
        [$comparisons, $comparisonsError] = $this->exportComparisons($request, $period, $dataset);
        try {
            $exporter->export($dataset, $path, $comparisons, $comparisonsError);
        } catch (\Throwable $e) {
            @unlink($path);
            $ref = 'ER-XLSX-'.now()->format('ymdHis').'-'.Str::upper(Str::random(4));
            Log::error('estado_resultados.excel_failed', ['ref' => $ref, 'period_id' => $period->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'No se pudo generar el Excel. Referencia: '.$ref], 500);
        }
        $this->logExport($request, $period, $dataset, 'xlsx', $name);

        return response()->download($path, $name, $this->exportHeaders($dataset, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))->deleteFileAfterSend();
    }

    public function exportPdf(Request $request, Period $period, EstadoResultadosPdfExporter $exporter)
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(600);
        $this->ensureMonthly($period);
        $dataset = $this->exportDataset($request, $period);
        if ($dataset instanceof JsonResponse) {
            return $dataset;
        }
        $name = $this->fileName($dataset, 'pdf');
        $path = storage_path('app/tmp/estado-resultados-'.Str::uuid().'.pdf');
        [$comparisons, $comparisonsError] = $this->exportComparisons($request, $period, $dataset);

        try {
            $exporter->export($dataset, $path, $comparisons, $comparisonsError);
        } catch (PdfRenderException $e) {
            // El exportador ya eliminó el archivo parcial; al usuario sólo se le da una referencia (sin rutas internas).
            $ref = 'ER-PDF-'.now()->format('ymdHis').'-'.Str::upper(Str::random(4));
            Log::error('estado_resultados.pdf_failed', ['ref' => $ref, 'period_id' => $period->id, 'code' => $e->code(), 'error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage().' Referencia: '.$ref], 500);
        }
        $this->logExport($request, $period, $dataset, 'pdf', $name);

        return response()->download($path, $name, $this->exportHeaders($dataset, 'application/pdf'))->deleteFileAfterSend();
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * Comparativas y series de gráficas para los exportes: el MISMO servicio y contrato que la web
     * (ComparisonService, er-comparativas/1), con el alcance del reporte y los permisos del usuario.
     * Si no se pueden calcular, el exporte sale igual con un aviso (nunca con cifras inventadas).
     *
     * @return array{0: ?array, 1: ?string}
     */
    private function exportComparisons(Request $request, Period $period, array $dataset): array
    {
        @set_time_limit(600);
        try {
            return [app(ComparisonService::class)->build($period, (string) $dataset['meta']['scope']['key'], $request->user(), ['meses' => 6]), null];
        } catch (ComparisonAccessException|\InvalidArgumentException $e) {
            return [null, 'Comparativas no disponibles: '.$e->getMessage()];
        } catch (\Throwable $e) {
            Log::warning('estado_resultados.export_comparisons_failed', ['period_id' => $period->id, 'error' => $e->getMessage()]);

            return [null, 'Las comparativas no se pudieron calcular en este momento; el Estado de Resultados del periodo sí está completo.'];
        }
    }

    private function exportDataset(Request $request, Period $period): array|JsonResponse
    {
        $scope = $this->scope($request);
        $this->authorizeScope($request, $scope);
        try {
            return $this->resolver->resolve($period, $scope, $request->user(), $this->versionParam($request));
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
        $scope = $this->scope($request);
        $this->authorizeScope($request, $scope);
        try {
            $d = $this->resolver->unredacted($period, $scope, $this->versionParam($request));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        // La otra categoría restringida se sigue respetando (p. ej. nómina de DG sin ver_dg).
        $d = app(EstadoResultadosRedactor::class)->apply(
            $d,
            $this->abilities->can($request->user(), 'ver_nomina'),
            $this->abilities->can($request->user(), 'ver_dg'),
        );

        // Bitácora de consulta de detalle sensible (nómina / Dirección General).
        Log::info('estado_resultados.detalle_restringido', [
            'period_id' => $period->id, 'scope' => $scope, 'sections' => $sections,
            'user_id' => $request->user()?->id, 'version' => $d['meta']['report']['version'] ?? null, 'ip' => $request->ip(),
        ]);

        return response()->json([
            'sections' => $this->restrictedSections($d['statement'], $sections),
            'movements' => array_values(array_filter($d['movements'], $filter)),
            'report' => $d['meta']['report'],
        ]);
    }

    /**
     * Secciones restringidas: en el modelo de EBITDA único nómina y DG son categorías de la
     * sección «opex» (se devuelven con la forma de una sección: key, title, lines, total).
     */
    private function restrictedSections(array $statement, array $keys): array
    {
        $out = [];
        foreach ($statement as $b) {
            if (($b['type'] ?? null) !== 'section') {
                continue;
            }
            if (in_array($b['key'], $keys, true)) {
                $out[] = $b;
            }
            foreach ((array) ($b['categories'] ?? []) as $c) {
                if (in_array($c['key'] ?? null, $keys, true)) {
                    $out[] = ['type' => 'section', 'title' => $c['label'], 'parent' => $b['key']] + $c;
                }
            }
        }

        return $out;
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

    /**
     * Alcance por sucursal del usuario (DataScopeService): el CONSOLIDADO agrega todas las
     * sucursales → sólo alcance general; una sucursal → debe estar entre las autorizadas.
     */
    private function authorizeScope(Request $request, string $scope): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(403, 'Inicia sesión para consultar el Estado de Resultados.');
        }
        if ($scope === EstadoResultadosDatasetBuilder::CONSOLIDADO) {
            $this->dataScope->requireGeneral($user, 'El Estado de Resultados consolidado');

            return;
        }
        if (! $this->dataScope->allowsBranchName($user, $scope)) {
            abort(403, 'No tienes acceso al Estado de Resultados de la sucursal '.$scope.'. Tu alcance autorizado: '.$this->dataScope->label($user).'. '
                .'Si necesitas consultarla, pide a un administrador que amplíe tu alcance en Usuarios.');
        }
    }

    /** @param list<array{key:string,label:string}> $scopes */
    private function visibleScopes(Request $request, array $scopes): array
    {
        $user = $request->user();
        if ($user === null || $this->dataScope->isGeneral($user)) {
            return $scopes;
        }

        return array_values(array_filter($scopes, fn ($s) => $s['key'] !== EstadoResultadosDatasetBuilder::CONSOLIDADO && $this->dataScope->allowsBranchName($user, $s['key'])));
    }

    private function initialScope(Request $request): string
    {
        $requested = trim((string) $request->string('alcance'));
        $user = $request->user();
        if ($user === null || $this->dataScope->isGeneral($user)) {
            return $requested !== '' ? $requested : EstadoResultadosDatasetBuilder::CONSOLIDADO;
        }
        if ($requested !== '' && $requested !== EstadoResultadosDatasetBuilder::CONSOLIDADO && $this->dataScope->allowsBranchName($user, $requested)) {
            return $requested;
        }

        return (string) ($this->dataScope->allowedBranchNames($user)[0] ?? EstadoResultadosDatasetBuilder::CONSOLIDADO);
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
