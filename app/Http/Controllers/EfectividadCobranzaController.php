<?php

namespace App\Http\Controllers;

use App\Enums\EfectividadCobranzaStage;
use App\Jobs\GenerateEfectividadCobranzaAprobadaJob;
use App\Models\Branch;
use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use App\Models\User;
use App\Services\Efectividad\EfectividadCobranzaIssue;
use App\Services\Efectividad\EfectividadCobranzaRunPresenter;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

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

    public function __construct(private readonly EfectividadCobranzaRunPresenter $presenter) {}

    public function index(Request $request): Response
    {
        return $this->renderPage($request, null);
    }

    /** URL estable del detalle de una ejecución (enlaces de correo y notificaciones). */
    public function show(Request $request, EfectividadCobranzaRun $run): Response
    {
        return $this->renderPage($request, $run);
    }

    public function generate(Request $request, Dispatcher $dispatcher): JsonResponse
    {
        $data = $request->validate([
            'period_id' => ['required', 'integer', 'exists:periods,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $period   = Period::query()->findOrFail($data['period_id']);
        $branchId = $data['branch_id'] ?? null;
        $userId   = $request->user()?->id;

        // "check-then-create" atómico: el lock se adquiere ANTES de consultar si ya
        // hay una corrida activa. Dos POST simultáneos para la MISMA identidad nunca
        // crean dos filas/Jobs.
        $lockKey = "efectividad-cobranza-generate-{$period->id}-" . ($branchId ?? 'general');

        try {
            [$run, $reused] = Cache::lock($lockKey, 15)->block(5, function () use ($period, $branchId, $userId) {
                $existing = EfectividadCobranzaRun::query()
                    ->forIdentity($period->id, $branchId)
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
                    'period_id'  => $period->id,
                    'branch_id'  => $branchId,
                    'status'     => EfectividadCobranzaRun::STATUS_QUEUED,
                    'queued_at'  => now(),
                    'log'        => 'En cola. Esperando a que el servidor tome el trabajo.',
                    'created_by' => $userId,
                ]);

                return [$run, false];
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            return response()->json([
                'error' => 'Ya hay una solicitud de generación en curso para este periodo/sucursal. Intenta de nuevo en unos segundos.',
            ], 409);
        }

        if (!$reused) {
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
                        'status'        => EfectividadCobranzaRun::STATUS_FAILED,
                        'finished_at'   => now(),
                        'failure_kind'  => EfectividadCobranzaRun::FAILURE_TECHNICAL,
                        'error_message' => 'No fue posible enviar el trabajo a la cola de procesamiento.',
                        'log'           => 'No fue posible enviar el trabajo a la cola de procesamiento.',
                        'issues'        => [EfectividadCobranzaIssue::make(
                            'EC-TEC-COLA-NO-DISPONIBLE',
                            EfectividadCobranzaIssue::SEVERITY_CRITICAL,
                            EfectividadCobranzaIssue::CATEGORY_TECHNICAL,
                            'El servidor no aceptó el trabajo en la cola de procesamiento. El reporte no se está generando.',
                            'Intenta de nuevo en unos minutos. Si persiste, avisa al equipo de sistemas.',
                        )],
                    ])->save();

                    return response()->json([
                        'error'    => 'No fue posible enviar el reporte a la cola de procesamiento. El reporte NO se está generando; intenta de nuevo en unos minutos.',
                        'run_id'   => $run->id,
                        'accepted' => false,
                    ], 503);
                }
            }
        }

        $run->refresh()->load(['period', 'branch', 'createdBy']);

        return response()->json([
            'run_id'   => $run->id,
            'status'   => $run->status,
            'reused'   => $reused,
            'accepted' => true,
            'run'      => $this->presenter->summary($run),
        ]);
    }

    public function progress(Request $request, EfectividadCobranzaRun $run): JsonResponse
    {
        return response()->json($this->presenter->detail($run, $request->user()));
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

    public function download(EfectividadCobranzaRun $run): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $absolutePath = $this->presenter->resolveExcelAbsolutePath($run);

        if ($run->status !== EfectividadCobranzaRun::STATUS_SUCCESS || !$absolutePath) {
            abort(404, 'El archivo Excel de este reporte no está disponible.');
        }

        return response()->download($absolutePath, basename($absolutePath), [
            'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
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
        if (!$focus) {
            if ($periodId) {
                $focus = EfectividadCobranzaRun::latestFor($periodId, $branchId);
            } else {
                $focus = $activeRuns->first()
                    ?? EfectividadCobranzaRun::query()->requestedBy($user->id)->latest('id')->first();
            }
        }

        if ($focus && !$periodId) {
            $periodId = $focus->period_id;
            $branchId = $focus->branch_id;
        }

        $filters = $request->validate([
            'f_period'    => ['nullable', 'integer'],
            'f_branch'    => ['nullable', 'string', 'max:20'],
            'f_status'    => ['nullable', 'string', 'in:queued,running,retrying,success,blocked,failed'],
            'f_from'      => ['nullable', 'date'],
            'f_to'        => ['nullable', 'date'],
            'f_user'      => ['nullable', 'integer'],
            'page'        => ['nullable', 'integer', 'min:1'],
        ]);

        $historyQuery = EfectividadCobranzaRun::query()
            ->with(['period', 'branch', 'createdBy'])
            ->when(!$isAdmin, fn ($q) => $q->requestedBy($user->id))
            ->when($isAdmin && !empty($filters['f_user']), fn ($q) => $q->where('created_by', $filters['f_user']))
            ->when(!empty($filters['f_period']), fn ($q) => $q->where('period_id', $filters['f_period']))
            ->when(($filters['f_branch'] ?? null) === 'general', fn ($q) => $q->whereNull('branch_id'))
            ->when(ctype_digit((string) ($filters['f_branch'] ?? '')), fn ($q) => $q->where('branch_id', (int) $filters['f_branch']))
            ->when(!empty($filters['f_status']), fn ($q) => $q->where('status', $filters['f_status']))
            ->when(!empty($filters['f_from']), fn ($q) => $q->where('created_at', '>=', $filters['f_from'] . ' 00:00:00'))
            ->when(!empty($filters['f_to']), fn ($q) => $q->where('created_at', '<=', $filters['f_to'] . ' 23:59:59'))
            ->latest('id');

        $history = $historyQuery->paginate(self::HISTORY_PER_PAGE)->withQueryString();
        $successMap = $this->presenter->latestSuccessMap(collect($history->items()));

        return Inertia::render('EfectividadCobranza/Index', [
            'periods'    => Period::query()
                ->orderByDesc('start_date')
                ->get(['id', 'code', 'name', 'type', 'year', 'month', 'start_date', 'end_date'])
                ->map(fn (Period $p) => ['id' => $p->id, 'label' => $p->label, 'code' => $p->code, 'type' => $p->type]),
            'branches'   => Branch::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name]),
            'selected'   => ['period_id' => $periodId, 'branch_id' => $branchId],
            'focusRun'   => $focus ? $this->presenter->detail($focus, $user) : null,
            'activeRuns' => $activeRuns->map(fn (EfectividadCobranzaRun $r) => $this->presenter->summary($r))->values(),
            'history'    => [
                'data' => collect($history->items())->map(fn (EfectividadCobranzaRun $r) => $this->presenter->summary($r, $successMap))->values(),
                'meta' => [
                    'current_page' => $history->currentPage(),
                    'last_page'    => $history->lastPage(),
                    'total'        => $history->total(),
                    'per_page'     => $history->perPage(),
                ],
            ],
            'filters'    => [
                'f_period' => isset($filters['f_period']) ? (int) $filters['f_period'] : null,
                'f_branch' => $filters['f_branch'] ?? null,
                'f_status' => $filters['f_status'] ?? null,
                'f_from'   => $filters['f_from'] ?? null,
                'f_to'     => $filters['f_to'] ?? null,
                'f_user'   => isset($filters['f_user']) ? (int) $filters['f_user'] : null,
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
            'openDetail'   => $openDetail,
        ]);
    }
}
