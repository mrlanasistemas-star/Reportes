<?php

namespace App\Http\Controllers;

use App\Enums\EfectividadCobranzaStage;
use App\Jobs\GenerateEfectividadCobranzaAprobadaJob;
use App\Models\Branch;
use App\Models\EfectividadCobranzaRun;
use App\Models\EfectividadCobranzaRunIncident;
use App\Models\Period;
use App\Models\User;
use App\Services\Efectividad\EfectividadCobranzaIssue;
use App\Services\Efectividad\EfectividadCobranzaRunPresenter;
use App\Services\Efectividad\V4\EfectividadV4DataAdapter;
use App\Services\Efectividad\V4\EfectividadV4Exception;
use App\Services\Efectividad\V4\EfectividadV4Incidents;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Módulo web "Efectividad de Cobranza" — generación en segundo plano con
 * seguimiento persistente.
 *
 * Por qué antes se "perdía" el seguimiento: la página solo cargaba la última
 * ejecución cuando la URL traía `period_id`; al volver desde el menú (sin
 * query string) `latestRun` llegaba en null y el ID del trabajo solo vivía
 * en una variable de Vue que se destruía al cambiar de módulo. El Job SÍ
 * seguía corriendo en el worker — solo se perdía su rastro en pantalla.
 *
 * Ahora la página SIEMPRE consulta en BD las ejecuciones activas del usuario
 * (independientemente del periodo seleccionado) y enfoca la más reciente; el
 * historial es una tabla paginada y filtrable; y el detalle de cualquier
 * ejecución tiene URL propia (`/efectividad-cobranza/runs/{run}`), que es la
 * que usan el correo y las notificaciones internas.
 *
 * Concurrencia (auditoría 2026-10-12): `generate()` corre dentro de un
 * `Cache::lock()` (INSERT atómico en `cache_locks`, válido también en MyISAM).
 */
class EfectividadCobranzaController extends Controller
{
    private const HISTORY_PER_PAGE = 10;

    public function __construct(
        private readonly EfectividadCobranzaRunPresenter $presenter,
        private readonly EfectividadV4DataAdapter $adapter,
    ) {}

    public function index(Request $request): Response
    {
        return $this->renderPage($request, null);
    }

    /** URL estable del detalle de una ejecución (enlaces de correo y notificaciones). */
    public function show(Request $request, EfectividadCobranzaRun $run): Response
    {
        $this->authorizeRun($request, $run);

        return $this->renderPage($request, $run);
    }

    public function generate(Request $request, Dispatcher $dispatcher): JsonResponse
    {
        $data = $request->validate([
            'period_id' => ['required', 'integer', 'exists:periods,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'mode' => ['nullable', 'string', 'in:SEMANAL,DIARIO'],
            'cutoff_date' => ['nullable', 'date', 'required_if:mode,DIARIO'],
            'confirm_partial' => ['nullable', 'boolean'],
        ]);

        $period = Period::query()->findOrFail($data['period_id']);
        $branchId = $data['branch_id'] ?? null;
        $userId = $request->user()?->id;
        $mode = $data['mode'] ?? EfectividadCobranzaRun::MODE_SEMANAL;
        $confirm = (bool) ($data['confirm_partial'] ?? false);

        // Motor V4: solo semanas operativas (lunes-domingo) o un día de ellas.
        try {
            $window = $this->adapter->window($period, $mode, $data['cutoff_date'] ?? null);
        } catch (EfectividadV4Exception $e) {
            return response()->json(['error' => $e->getMessage(), 'code' => $e->codigo, 'accepted' => false], 422);
        }
        $cutoff = $window['fecha_corte'];

        // Igual que la macro (MsgBox "¿Confirmas que descargaste la SEMANA COMPLETA…?"):
        // con ingresos aparentemente parciales se pide confirmación explícita antes de encolar.
        $hasActive = EfectividadCobranzaRun::query()->forV4Identity($period->id, $branchId, $mode, $cutoff)->active()->exists();
        if (! $confirm && ! $hasActive) {
            $disp = $this->adapter->availability($period, $branchId, $mode, $cutoff);
            if ($disp['requiere_confirmacion']) {
                return response()->json([
                    'accepted' => false,
                    'requires_confirmation' => true,
                    'message' => collect($disp['issues'])->whereIn('code', ['EC-V4-INGRESOS-SEMANA-PARCIAL', 'EC-V4-INGRESOS-SIN-MOVIMIENTOS'])->pluck('message')->implode(' '),
                    'issues' => $disp['issues'],
                ], 409);
            }
        }

        // "check-then-create" atómico: el lock se adquiere ANTES de consultar si ya
        // hay una corrida activa. Dos POST simultáneos para la MISMA identidad nunca
        // crean dos filas/Jobs.
        $lockKey = "efectividad-cobranza-generate-{$period->id}-".($branchId ?? 'general')."-{$mode}-{$cutoff}";

        try {
            [$run, $reused] = Cache::lock($lockKey, 15)->block(5, function () use ($period, $branchId, $userId, $mode, $cutoff, $window, $confirm) {
                $existing = EfectividadCobranzaRun::query()
                    ->forV4Identity($period->id, $branchId, $mode, $cutoff)
                    ->active()
                    ->latest('id')
                    ->first();

                if ($existing) {
                    // Quien pide lo mismo mientras está en proceso queda suscrito: también
                    // verá la ejecución en su historial y recibirá la notificación.
                    $existing->addSubscriber($userId);

                    return [$existing, true];
                }

                $run = EfectividadCobranzaRun::query()->create([
                    'period_id' => $period->id,
                    'branch_id' => $branchId,
                    'engine_version' => EfectividadCobranzaRun::currentEngineVersion(),
                    'mode' => $mode,
                    'base_date' => $window['domingo_base'],
                    'cutoff_date' => $cutoff,
                    'metadata' => ['confirmed_partial' => $confirm],
                    'status' => EfectividadCobranzaRun::STATUS_QUEUED,
                    'queued_at' => now(),
                    'log' => 'En cola. Esperando a que el servidor tome el trabajo.',
                    'created_by' => $userId,
                ]);

                return [$run, false];
            });
        } catch (LockTimeoutException) {
            return response()->json([
                'error' => 'Ya hay una solicitud de generación en curso para este periodo/sucursal. Intenta de nuevo en unos segundos.',
            ], 409);
        }

        if (! $reused) {
            try {
                // Bus::dispatch devuelve el id del registro en `jobs` (driver database):
                // se guarda para poder consultar el estado REAL del Job después.
                $jobId = $dispatcher->dispatch(new GenerateEfectividadCobranzaAprobadaJob($run->id));

                if (is_scalar($jobId) && (string) $jobId !== '' && (string) $jobId !== '0') {
                    EfectividadCobranzaRun::query()->whereKey($run->id)->whereNull('queue_job_id')->update(['queue_job_id' => (string) $jobId]);
                }
            } catch (\Throwable $e) {
                $run->refresh();

                // Con QUEUE_CONNECTION=sync el Job corre aquí mismo: si ya cambió de estado,
                // la cola SÍ lo aceptó y lo ejecutó (el error quedó registrado en la ejecución).
                if ($run->status === EfectividadCobranzaRun::STATUS_QUEUED) {
                    Log::error('Efectividad: no fue posible encolar el trabajo.', [
                        'run_id' => $run->id, 'exception' => get_class($e), 'message' => $e->getMessage(),
                    ]);

                    $run->forceFill([
                        'status' => EfectividadCobranzaRun::STATUS_FAILED,
                        'finished_at' => now(),
                        'failure_kind' => EfectividadCobranzaRun::FAILURE_TECHNICAL,
                        'error_message' => 'No fue posible enviar el trabajo a la cola de procesamiento.',
                        'log' => 'No fue posible enviar el trabajo a la cola de procesamiento.',
                        'issues' => [EfectividadCobranzaIssue::make(
                            'EC-TEC-COLA-NO-DISPONIBLE',
                            EfectividadCobranzaIssue::SEVERITY_CRITICAL,
                            EfectividadCobranzaIssue::CATEGORY_TECHNICAL,
                            'El servidor no aceptó el trabajo en la cola de procesamiento. El reporte no se está generando.',
                            'Intenta de nuevo en unos minutos. Si persiste, avisa al equipo de sistemas.',
                        )],
                    ])->save();

                    return response()->json([
                        'error' => 'No fue posible enviar el reporte a la cola de procesamiento. El reporte NO se está generando; intenta de nuevo en unos minutos.',
                        'run_id' => $run->id,
                        'accepted' => false,
                    ], 503);
                }
            }
        }

        $run->refresh()->load(['period', 'branch', 'createdBy']);

        return response()->json([
            'run_id' => $run->id,
            'status' => $run->status,
            'reused' => $reused,
            'accepted' => true,
            'run' => $this->presenter->summary($run),
        ]);
    }

    public function progress(Request $request, EfectividadCobranzaRun $run): JsonResponse
    {
        $this->authorizeRun($request, $run);

        return response()->json($this->presenter->detail($run, $request->user()));
    }

    /**
     * Solicita detener una generación en curso. Nunca cambia el estado aquí
     * mismo: solo marca la solicitud (`cancel_requested_at`) de forma atómica;
     * es el propio Job, en sus puntos de cancelación cooperativa, quien cierra
     * la ejecución como `cancelled` — así nunca hay una carrera entre esta
     * petición HTTP y el worker que está escribiendo el mismo registro.
     */
    public function cancel(Request $request, EfectividadCobranzaRun $run): JsonResponse
    {
        $this->authorizeRun($request, $run);

        if (! $run->isActive()) {
            return response()->json([
                'error' => 'Esta ejecución ya terminó; no se puede cancelar.',
            ], 409);
        }

        // Atómico: solo la primera solicitud de cancelación tiene efecto; una
        // segunda petición (doble clic, dos pestañas) nunca pisa el usuario/hora.
        EfectividadCobranzaRun::query()
            ->whereKey($run->id)
            ->whereIn('status', EfectividadCobranzaRun::ACTIVE_STATUSES)
            ->whereNull('cancel_requested_at')
            ->update(['cancel_requested_at' => now(), 'cancelled_by' => $request->user()?->id]);

        $run->refresh()->load(['period', 'branch', 'createdBy']);

        if (! $run->isCancelRequested()) {
            // La ejecución cambió de estado entre la validación y el UPDATE (terminó justo ahora).
            return response()->json([
                'error' => 'Esta ejecución ya terminó; no se puede cancelar.',
            ], 409);
        }

        return response()->json([
            'run_id' => $run->id,
            'message' => 'Se solicitó detener la generación. El sistema la finalizará en cuanto alcance un punto seguro.',
            'run' => $this->presenter->summary($run),
        ]);
    }

    /** Ejecuciones activas del usuario (indicador de navegación y restauración). */
    public function activity(Request $request): JsonResponse
    {
        $runs = EfectividadCobranzaRun::query()
            ->with(['period', 'branch', 'createdBy'])
            ->requestedBy($request->user()->id)
            ->active()
            ->latest('id')
            ->limit(10)
            ->get();

        return response()->json([
            'active' => $runs->map(fn (EfectividadCobranzaRun $r) => $this->presenter->summary($r))->values(),
        ]);
    }

    /** Incidencias de cálculo (bitácora _AUDITORIA_EFECTIVIDAD) — paginadas y filtrables. */
    public function incidents(Request $request, EfectividadCobranzaRun $run): JsonResponse
    {
        $this->authorizeRun($request, $run);
        $filters = $request->validate([
            'code' => ['nullable', 'string', 'max:20'],
            'q' => ['nullable', 'string', 'max:60'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = $this->incidentQuery($run, $filters)->paginate(25)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (EfectividadCobranzaRunIncident $i) => [
                'id' => $i->id,
                'position' => $i->position,
                'code' => $i->code,
                'label' => EfectividadV4Incidents::etiqueta($i->code),
                'key' => $i->incident_key,
                'contract' => $i->contract,
                'reference' => $i->reference,
                'date' => $i->occurred_on?->format('Y-m-d'),
                'amount' => $i->amount,
                'description' => $i->description,
                'status' => $i->treatment,
                'action' => EfectividadV4Incidents::accion($i->code),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
            'summary' => array_values($run->metadata['incidencias_resumen'] ?? []),
        ]);
    }

    /** Exporta las incidencias a CSV (UTF-8 con BOM para abrir directo en Excel). */
    public function exportIncidents(Request $request, EfectividadCobranzaRun $run): StreamedResponse
    {
        $this->authorizeRun($request, $run);
        $filters = $request->validate(['code' => ['nullable', 'string', 'max:20'], 'q' => ['nullable', 'string', 'max:60']]);
        $query = $this->incidentQuery($run, $filters);
        $name = "incidencias_efectividad_run{$run->id}.csv";

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['#', 'ID', 'Código', 'Contrato', 'Fecha reconocimiento', 'Importe', 'Motivo', 'Tratamiento', 'Acción recomendada']);
            foreach ($query->lazy(500) as $i) {
                fputcsv($out, [$i->position, $this->csvText($i->incident_key), $this->csvText($i->code), $this->csvText($i->contract), $i->occurred_on?->format('d/m/Y'),
                    $i->amount !== null ? number_format($i->amount, 2, '.', '') : '', $this->csvText($i->description), $this->csvText($i->treatment), EfectividadV4Incidents::accion($i->code)]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * Un ID de ejecución ajeno responde 404 (no revela que existe): admin ve todas; los
     * demás roles de Reportería, solo las que solicitaron o a las que quedaron suscritos.
     */
    private function authorizeRun(Request $request, EfectividadCobranzaRun $run): void
    {
        if (! $run->isVisibleTo($request->user())) {
            abort(404);
        }
    }

    /** Texto de origen (Lendus) que empiece con = + - @ o tabulador se neutraliza: Excel no lo evalúa como fórmula. */
    private function csvText(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private function incidentQuery(EfectividadCobranzaRun $run, array $filters)
    {
        return EfectividadCobranzaRunIncident::query()
            ->where('efectividad_cobranza_run_id', $run->id)
            ->when(! empty($filters['code']), fn ($q) => $q->where('code', $filters['code']))
            ->when(! empty($filters['q']), fn ($q) => $q->where('contract', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%'))
            ->orderBy('position');
    }

    public function download(Request $request, EfectividadCobranzaRun $run): BinaryFileResponse
    {
        $this->authorizeRun($request, $run);

        $absolutePath = $this->presenter->resolveExcelAbsolutePath($run);

        if ($run->status !== EfectividadCobranzaRun::STATUS_SUCCESS || ! $absolutePath) {
            abort(404, 'El archivo Excel de este reporte no está disponible.');
        }

        return response()->download($absolutePath, basename($absolutePath), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    private function renderPage(Request $request, ?EfectividadCobranzaRun $focus): Response
    {
        /** @var User $user */
        $user = $request->user();
        $isAdmin = $user->isAdmin();
        $openDetail = $focus !== null; // /runs/{run} (correo, notificación) abre el detalle

        $periodId = $request->integer('period_id') ?: null;
        $branchId = $request->integer('branch_id') ?: null;

        $activeRuns = EfectividadCobranzaRun::query()
            ->with(['period', 'branch', 'createdBy'])
            ->requestedBy($user->id)
            ->active()
            ->latest('id')
            ->limit(10)
            ->get();

        // Restauración: sin ejecución explícita, se enfoca la del periodo/sucursal
        // seleccionado; si no hay selección, la ejecución activa más reciente del
        // usuario; si no hay activas, su ejecución más reciente.
        if (! $focus) {
            if ($periodId) {
                // Misma regla de visibilidad que el historial: un no-admin solo enfoca lo que solicitó.
                $focus = EfectividadCobranzaRun::query()
                    ->forIdentity($periodId, $branchId)
                    ->when(! $isAdmin, fn ($q) => $q->requestedBy($user->id))
                    ->latest('id')
                    ->first();
            } else {
                $focus = $activeRuns->first()
                    ?? EfectividadCobranzaRun::query()->requestedBy($user->id)->latest('id')->first();
            }
        }

        if ($focus && ! $periodId) {
            $periodId = $focus->period_id;
            $branchId = $focus->branch_id;
        }

        $filters = $request->validate([
            'f_period' => ['nullable', 'integer'],
            'f_branch' => ['nullable', 'string', 'max:20'],
            'f_status' => ['nullable', 'string', 'in:queued,running,retrying,success,blocked,failed,cancelled'],
            'f_from' => ['nullable', 'date'],
            'f_to' => ['nullable', 'date'],
            'f_user' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $historyQuery = EfectividadCobranzaRun::query()
            ->with(['period', 'branch', 'createdBy'])
            ->when(! $isAdmin, fn ($q) => $q->requestedBy($user->id))
            ->when($isAdmin && ! empty($filters['f_user']), fn ($q) => $q->where('created_by', $filters['f_user']))
            ->when(! empty($filters['f_period']), fn ($q) => $q->where('period_id', $filters['f_period']))
            ->when(($filters['f_branch'] ?? null) === 'general', fn ($q) => $q->whereNull('branch_id'))
            ->when(ctype_digit((string) ($filters['f_branch'] ?? '')), fn ($q) => $q->where('branch_id', (int) $filters['f_branch']))
            ->when(! empty($filters['f_status']), fn ($q) => $q->where('status', $filters['f_status']))
            ->when(! empty($filters['f_from']), fn ($q) => $q->where('created_at', '>=', $filters['f_from'].' 00:00:00'))
            ->when(! empty($filters['f_to']), fn ($q) => $q->where('created_at', '<=', $filters['f_to'].' 23:59:59'))
            ->latest('id');

        $history = $historyQuery->paginate(self::HISTORY_PER_PAGE)->withQueryString();
        $successMap = $this->presenter->latestSuccessMap(collect($history->items()));

        return Inertia::render('EfectividadCobranza/Index', [
            'periods' => Period::query()
                ->orderByDesc('start_date')
                ->get(['id', 'code', 'name', 'type', 'year', 'month', 'start_date', 'end_date'])
                ->map(fn (Period $p) => ['id' => $p->id, 'label' => $p->label, 'code' => $p->code, 'type' => $p->type,
                    'start_date' => $p->start_date?->format('Y-m-d'), 'end_date' => $p->end_date?->format('Y-m-d')]),
            'branches' => Branch::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name]),
            'selected' => ['period_id' => $periodId, 'branch_id' => $branchId, 'mode' => $focus?->mode ?? EfectividadCobranzaRun::MODE_SEMANAL, 'cutoff_date' => $focus?->mode === EfectividadCobranzaRun::MODE_DIARIO ? $focus->cutoff_date?->format('Y-m-d') : null],
            'engineVersion' => EfectividadCobranzaRun::currentEngineVersion(),
            'focusRun' => $focus ? $this->presenter->detail($focus, $user) : null,
            'activeRuns' => $activeRuns->map(fn (EfectividadCobranzaRun $r) => $this->presenter->summary($r))->values(),
            'history' => [
                'data' => collect($history->items())->map(fn (EfectividadCobranzaRun $r) => $this->presenter->summary($r, $successMap))->values(),
                'meta' => [
                    'current_page' => $history->currentPage(),
                    'last_page' => $history->lastPage(),
                    'total' => $history->total(),
                    'per_page' => $history->perPage(),
                ],
            ],
            'filters' => [
                'f_period' => isset($filters['f_period']) ? (int) $filters['f_period'] : null,
                'f_branch' => $filters['f_branch'] ?? null,
                'f_status' => $filters['f_status'] ?? null,
                'f_from' => $filters['f_from'] ?? null,
                'f_to' => $filters['f_to'] ?? null,
                'f_user' => isset($filters['f_user']) ? (int) $filters['f_user'] : null,
            ],
            'canFilterByUser' => $isAdmin,
            'requesters' => $isAdmin
                ? User::query()
                    ->whereIn('id', EfectividadCobranzaRun::query()->whereNotNull('created_by')->distinct()->pluck('created_by'))
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
                : [],
            'stageCatalog' => EfectividadCobranzaStage::catalog(),
            'openDetail' => $openDetail,
        ]);
    }
}
