<?php

namespace App\Services\EstadoResultados;

use App\Jobs\RunEstadoResultadosTaskJob;
use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\EstadoResultados\EstadoResultadosRun;
use App\Models\EstadoResultados\EstadoResultadosTask;
use App\Models\Period;
use App\Services\EstadoResultados\Classification\ConceptCatalogService;
use App\Services\EstadoResultados\Reporting\PeriodLockGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Reproceso AUTOMÁTICO de periodos clasificados con reglas viejas (25-nov-2026).
 *
 * Cada corrida guarda la versión de reglas (MovementClassifier::RULES_VERSION) y la firma del
 * catálogo de conceptos. Si un periodo NO aprobado tiene movimientos procesados y su última corrida
 * correcta usó otra versión de reglas u otra firma del catálogo, se encola el mismo procesamiento
 * del botón «Procesar conciliación» (RunEstadoResultadosTaskJob), sin acción del usuario:
 *  - al desplegar (migración 2026_11_25_000002, sólo encola: nunca procesa dentro de `migrate`),
 *  - al abrir el ER (EstadoResultadosController::dataset).
 *
 * Nunca toca periodos aprobados (PeriodLockGuard) ni las clasificaciones manuales (tabla aparte,
 * append-only; el procesador no la modifica). Una tarea abierta o encolada hace poco
 * (COOLDOWN_MINUTES) evita encolar otra: si el reproceso falla no se reintenta en bucle.
 */
class RulesRefreshService
{
    /** Minutos sin volver a encolar el reproceso automático del mismo periodo. */
    public const COOLDOWN_MINUTES = 15;

    /** Paso que ve la pantalla mientras corre el reproceso automático. */
    public const STEP = 'Actualizando la clasificación con las reglas nuevas…';

    /**
     * @param  PeriodLockGuard  $lock  detecta periodos aprobados (nunca se reprocesan)
     */
    public function __construct(private readonly PeriodLockGuard $lock) {}

    /**
     * ¿Por qué el periodo está desactualizado? null = al día (o no aplica: aprobado, sin
     * movimientos procesados o sin corrida correcta).
     *
     * @param  Period  $period  periodo
     * @return string|null 'reglas' | 'catalogo' | null
     */
    public function staleReason(Period $period, ?string $catalogSignature = null): ?string
    {
        if (! Schema::hasTable('estado_resultados_runs') || $this->lock->isLocked($period)) {
            return null;
        }
        $lastOk = EstadoResultadosRun::query()->where('period_id', $period->id)->where('status', 'ok')->latest('id')->first();
        if (! $lastOk || ! EstadoResultadosMovement::query()->where('period_id', $period->id)->whereNotNull('processed_at')->exists()) {
            return null;
        }
        if ((string) $lastOk->rules_version !== (string) config('estado_resultados.rules_version')) {
            return 'reglas';
        }
        $catalogSignature ??= app(ConceptCatalogService::class)->signature();
        if (($lastOk->summary['catalogo']['firma'] ?? ConceptCatalogService::EMPTY_SIGNATURE) !== $catalogSignature) {
            return 'catalogo';
        }

        return null;
    }

    /**
     * Si el periodo está desactualizado, encola su reproceso (sin usuario: lo pide el sistema).
     * Con cola `sync` se ejecuta en el acto. Nunca lanza: un error queda en el log.
     *
     * @param  Period  $period  periodo
     * @return EstadoResultadosTask|null tarea encolada (o ya terminada con cola sync); null si no hacía falta
     */
    public function refreshIfStale(Period $period): ?EstadoResultadosTask
    {
        try {
            $reason = $this->staleReason($period);
            if ($reason === null) {
                return null;
            }

            return $this->enqueue($period, $reason);
        } catch (\Throwable $e) {
            Log::warning('estado_resultados.auto_refresh_failed', ['period_id' => $period->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Encola el reproceso de TODOS los periodos desactualizados (lo usa la migración del despliegue).
     *
     * @return list<int> ids de periodo encolados
     */
    public function refreshAllStale(): array
    {
        if (! Schema::hasTable('estado_resultados_runs') || ! Schema::hasTable('estado_resultados_tasks')) {
            return [];
        }
        $signature = app(ConceptCatalogService::class)->signature();
        $ids = EstadoResultadosMovement::query()->whereNotNull('processed_at')->distinct()->pluck('period_id')->all();
        $queued = [];
        foreach (Period::query()->whereIn('id', $ids ?: [0])->orderBy('start_date')->get() as $period) {
            try {
                if (($reason = $this->staleReason($period, $signature)) !== null && $this->enqueue($period, $reason)) {
                    $queued[] = (int) $period->id;
                }
            } catch (\Throwable $e) {
                Log::warning('estado_resultados.auto_refresh_failed', ['period_id' => $period->id, 'error' => $e->getMessage()]);
            }
        }

        return $queued;
    }

    /**
     * Crea la tarea de procesamiento (una abierta por periodo; fila del periodo bloqueada) y la
     * despacha. No encola si ya hay una abierta o si se encoló una en los últimos COOLDOWN_MINUTES.
     *
     * @param  Period  $period  periodo
     * @param  string  $reason  motivo (para el log)
     */
    private function enqueue(Period $period, string $reason): ?EstadoResultadosTask
    {
        EstadoResultadosTask::closeAbandoned($period->id);

        $task = DB::transaction(function () use ($period) {
            DB::table('periods')->where('id', $period->id)->lockForUpdate()->first();
            $recent = EstadoResultadosTask::query()->where('period_id', $period->id)->where('kind', EstadoResultadosTask::KIND_PROCESS)
                ->where(fn ($q) => $q->whereIn('status', [EstadoResultadosTask::STATUS_QUEUED, EstadoResultadosTask::STATUS_RUNNING])
                    ->orWhere('queued_at', '>=', now()->subMinutes(self::COOLDOWN_MINUTES)))
                ->exists();
            if ($recent) {
                return null;
            }

            return EstadoResultadosTask::query()->create([
                'period_id' => $period->id,
                'kind' => EstadoResultadosTask::KIND_PROCESS,
                'status' => EstadoResultadosTask::STATUS_QUEUED,
                'user_id' => null,
                'input' => ['auto' => true],
                'progress_percent' => 0,
                'current_step' => self::STEP,
                'queued_at' => now(),
            ]);
        });
        if (! $task) {
            return null;
        }

        Log::info('estado_resultados.auto_refresh_queued', ['period_id' => $period->id, 'reason' => $reason, 'task_id' => $task->id]);
        RunEstadoResultadosTaskJob::dispatch($task->id);

        return $task->refresh();
    }
}
