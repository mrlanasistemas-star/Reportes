<?php

namespace App\Http\Controllers;

use App\Models\EstadoResultados\EstadoResultadosExport;
use App\Models\Period;
use App\Services\Access\ActionPermissionService;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Classification\ClassificationService;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Export\EstadoResultadosExcelExporter;
use App\Services\EstadoResultados\Export\EstadoResultadosPdfExporter;
use App\Services\EstadoResultados\Import\EstadoResultadosImportException;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use App\Services\EstadoResultados\Support\Treatment;
use App\Services\Pdf\PdfRenderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EstadoResultadosController extends Controller
{
    public function __construct(
        private readonly EstadoResultadosDatasetBuilder $builder,
    ) {}

    public function index(Request $request, ActionPermissionService $actions): Response
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

        $user = $request->user();
        $can = fn (string $a) => $actions->canAction($user, 'estado_resultados', $a);

        return Inertia::render('EstadoResultados/Index', [
            'periods' => $periods,
            'initialPeriodId' => (int) ($request->integer('periodo') ?: ($periods->first()['id'] ?? 0)),
            'initialScope' => (string) ($request->string('alcance') ?: EstadoResultadosDatasetBuilder::CONSOLIDADO),
            'slots' => collect(EstadoResultadosUploadService::SLOTS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'treatments' => Treatment::catalog(),
            'adjustmentStates' => AdjustmentService::STATES,
            'can' => [
                'cargar' => $can('cargar'),
                'procesar' => $can('procesar'),
                'clasificar' => $can('clasificar'),
                'ajustes' => $can('ajustes'),
                'exportar' => $can('exportar'),
            ],
            'toleranceDays' => (int) config('estado_resultados.conciliacion.tolerancia_dias', 3),
        ]);
    }

    public function dataset(Request $request, Period $period): JsonResponse
    {
        $this->ensureMonthly($period);
        $scope = $this->scope($request);

        try {
            return response()->json([
                'dataset' => $this->builder->build($period, $scope),
                'scopes' => $this->builder->scopes($period),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function upload(Request $request, Period $period, EstadoResultadosUploadService $service): JsonResponse
    {
        $this->ensureMonthly($period);
        $data = $request->validate([
            'slot' => ['required', 'in:'.implode(',', array_keys(EstadoResultadosUploadService::SLOTS))],
            'file' => ['required', 'file', 'mimes:xlsx,xls,xlsm', 'max:51200'],
        ], [
            'file.mimes' => 'El archivo debe ser Excel (.xlsx, .xls o .xlsm).',
            'file.max' => 'El archivo excede 50 MB.',
        ]);

        try {
            $upload = $service->upload($period, $data['file'], $data['slot'], $request->user()?->id);
        } catch (EstadoResultadosImportException $e) {
            return response()->json(['message' => $e->getMessage(), 'details' => $e->details()], 422);
        }

        return response()->json([
            'message' => 'Archivo cargado: '.$upload->movements_count.' movimientos. Presiona "Procesar conciliación" para conciliar y clasificar.',
            'upload' => ['id' => $upload->id, 'warnings' => $upload->warnings ?? [], 'control_ok' => $upload->control_ok],
        ]);
    }

    public function process(Request $request, Period $period, EstadoResultadosProcessor $processor): JsonResponse
    {
        $this->ensureMonthly($period);
        try {
            $run = $processor->process($period, $request->user()?->id);
        } catch (\Throwable $e) {
            Log::error('estado_resultados.process_failed', ['period_id' => $period->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'No se pudo procesar la conciliación: '.$e->getMessage()], 500);
        }

        return response()->json(['message' => 'Conciliación procesada.', 'summary' => $run->summary]);
    }

    public function classify(Request $request, Period $period, ClassificationService $service): JsonResponse
    {
        $this->ensureMonthly($period);
        $data = $request->validate([
            'fingerprints' => ['required', 'array', 'min:1', 'max:2000'],
            'fingerprints.*' => ['string', 'max:64'],
            'treatment' => ['required', 'string', 'in:'.implode(',', Treatment::ASSIGNABLE)],
            'notes' => ['required', 'string', 'min:3', 'max:2000'],
        ], ['notes.required' => 'Escribe el motivo de la clasificación.', 'notes.min' => 'Escribe el motivo de la clasificación.']);

        $items = $this->itemsByFingerprint($period, $data['fingerprints']);
        if ($items === []) {
            return response()->json(['message' => 'No se encontraron los movimientos seleccionados.'], 422);
        }
        $count = $service->classify($period, $items, $data['treatment'], $data['notes'], $request->user()?->id);

        return response()->json(['message' => $count.' movimiento(s) clasificados como "'.Treatment::label($data['treatment']).'".', 'count' => $count]);
    }

    public function acceptSuggestions(Request $request, Period $period, ClassificationService $service): JsonResponse
    {
        $this->ensureMonthly($period);
        $data = $request->validate([
            'fingerprints' => ['required', 'array', 'min:1', 'max:2000'],
            'fingerprints.*' => ['string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $items = $this->itemsByFingerprint($period, $data['fingerprints'], withSuggestion: true);
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

        return response()->json(['history' => $service->history($period->id, $fp)]);
    }

    public function saveAdjustment(Request $request, Period $period, AdjustmentService $service): JsonResponse
    {
        $this->ensureMonthly($period);
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

    public function exportExcel(Request $request, Period $period, EstadoResultadosExcelExporter $exporter)
    {
        $this->ensureMonthly($period);
        $dataset = $this->builder->build($period, $this->scope($request));
        $name = $this->fileName($dataset, 'xlsx');
        $path = storage_path('app/tmp/estado-resultados-'.Str::uuid().'.xlsx');
        $exporter->export($dataset, $path);
        $this->logExport($request, $period, $dataset, 'xlsx', $name);

        return response()->download($path, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Dataset-Hash' => $dataset['meta']['dataset_hash'],
        ])->deleteFileAfterSend();
    }

    public function exportPdf(Request $request, Period $period, EstadoResultadosPdfExporter $exporter)
    {
        $this->ensureMonthly($period);
        $dataset = $this->builder->build($period, $this->scope($request));
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

        return response()->download($path, $name, [
            'Content-Type' => 'application/pdf',
            'X-Dataset-Hash' => $dataset['meta']['dataset_hash'],
        ])->deleteFileAfterSend();
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function itemsByFingerprint(Period $period, array $fingerprints, bool $withSuggestion = false): array
    {
        $wanted = array_flip($fingerprints);
        $dataset = $this->builder->build($period, EstadoResultadosDatasetBuilder::CONSOLIDADO);
        $out = [];
        foreach ($dataset['movements'] as $m) {
            if (isset($wanted[$m['fingerprint']])) {
                $out[] = ['fingerprint' => $m['fingerprint'], 'source' => $m['source'], 'current' => $m['treatment']]
                    + ($withSuggestion ? ['suggested' => $m['suggested_treatment']] : []);
            }
        }

        return $out;
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

        return 'MR_LANA_Estado_Resultados_'.$dataset['meta']['period']['code'].'_'.$scope.'.'.$ext;
    }

    private function logExport(Request $request, Period $period, array $dataset, string $format, string $name): void
    {
        EstadoResultadosExport::query()->create([
            'period_id' => $period->id,
            'scope_key' => $dataset['meta']['scope']['key'],
            'format' => $format,
            'dataset_hash' => $dataset['meta']['dataset_hash'],
            'file_name' => $name,
            'user_id' => $request->user()?->id,
        ]);
    }
}
