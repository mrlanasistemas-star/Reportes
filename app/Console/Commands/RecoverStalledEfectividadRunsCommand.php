<?php

namespace App\Console\Commands;

use App\Jobs\GenerateEfectividadCobranzaAprobadaJob;
use App\Models\EfectividadCobranzaRun;
use App\Services\Efectividad\EfectividadCobranzaIssue;
use App\Services\Efectividad\EfectividadCobranzaRunHealthService;
use App\Services\Efectividad\EfectividadCobranzaRunNotifier;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;

/**
 * Procedimiento SEGURO para ejecuciones de Efectividad de Cobranza huérfanas.
 *
 * Por defecto solo DIAGNOSTICA (no modifica nada). Con `--apply`:
 *  - Solo actúa sobre ejecuciones `stalled` — es decir, con evidencia REAL de
 *    la cola de que el Job ya no existe (no está en `jobs`, o terminó en
 *    `failed_jobs`) además de no tener actividad reciente. Un Job que sigue
 *    en `jobs` (pendiente o reservado por un worker) NUNCA se toca: eso es un
 *    worker detenido/lento, no un huérfano, y re-encolarlo lo duplicaría.
 *  - Sin `--requeue`: la ejecución se cierra como `failed` (worker_stalled) y
 *    se notifica al solicitante — nunca se queda "Procesando" indefinidamente.
 *  - Con `--requeue`: se vuelve a encolar la MISMA ejecución (misma fila, mismo
 *    ID; sin crear otra), bajo el mismo lock de identidad que usa el
 *    controlador, para que no se dupliquen ejecuciones.
 *
 * También libera correos atascados en `sending` (proceso interrumpido a mitad
 * de envío) para que `efectividad:retry-notifications` los reintente.
 */
class RecoverStalledEfectividadRunsCommand extends Command
{
    protected $signature = 'efectividad:recover-stalled
        {--apply : Aplica los cambios (sin esta opción solo muestra el diagnóstico)}
        {--requeue : Re-encola la misma ejecución en lugar de cerrarla como fallida}
        {--run= : Limitar a una ejecución específica}';

    protected $description = 'Diagnostica y recupera ejecuciones de Efectividad de Cobranza sin actividad del worker, sin duplicarlas.';

    public function handle(EfectividadCobranzaRunHealthService $health, EfectividadCobranzaRunNotifier $notifier, Dispatcher $dispatcher): int
    {
        $apply = (bool) $this->option('apply');
        $requeue = (bool) $this->option('requeue');

        $runs = EfectividadCobranzaRun::query()
            ->active()
            ->when($this->option('run'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->get();

        if ($runs->isEmpty()) {
            $this->info('No hay ejecuciones activas.');
        }

        $rows = [];
        foreach ($runs as $run) {
            $assessment = $health->assess($run);
            $action = 'ninguna';

            if ($assessment['state'] === 'stalled' && $apply) {
                $action = $requeue && $assessment['queue_state'] === EfectividadCobranzaRunHealthService::QUEUE_ABSENT
                    ? $this->requeue($run, $dispatcher)
                    : $this->closeAsStalled($run, $notifier, $assessment['message']);
            } elseif ($assessment['state'] === 'stalled') {
                $action = 'se cerraría (usa --apply)';
            } elseif ($assessment['state'] === 'delayed') {
                $action = 'ninguna — el Job sigue en la cola; verifica el worker';
            }

            $rows[] = [$run->id, $run->status, $assessment['label'], $assessment['queue_state'], $assessment['minutes_since_activity'] ?? '—', $action];
        }

        if ($rows !== []) {
            $this->table(['Run', 'Estado BD', 'Diagnóstico', 'Cola', 'Min. sin actividad', 'Acción'], $rows);
        }

        $stuckMails = EfectividadCobranzaRun::query()
            ->where('notification_status', EfectividadCobranzaRun::NOTIFICATION_SENDING)
            ->where('updated_at', '<', now()->subMinutes(15))
            ->get();

        foreach ($stuckMails as $run) {
            $this->line("Correo de la ejecución #{$run->id} atascado en 'sending'".($apply ? ' → marcado para reintento.' : ' (usa --apply).'));
            if ($apply) {
                $run->forceFill([
                    'notification_status' => EfectividadCobranzaRun::NOTIFICATION_FAILED,
                    'notification_error' => 'El envío se interrumpió antes de confirmarse; se reintentará.',
                ])->save();
            }
        }

        return self::SUCCESS;
    }

    private function closeAsStalled(EfectividadCobranzaRun $run, EfectividadCobranzaRunNotifier $notifier, ?string $reason): string
    {
        $issues = $run->issues ?? [];
        $issues[] = EfectividadCobranzaIssue::make(
            'EC-WRK-SIN-ACTIVIDAD',
            EfectividadCobranzaIssue::SEVERITY_CRITICAL,
            EfectividadCobranzaIssue::CATEGORY_WORKER_STALLED,
            'El proceso que generaba este reporte dejó de responder y el trabajo ya no está en la cola. '.($reason ?? ''),
            'Genera el reporte nuevamente. Si se repite, verifica que el proceso de colas (queue:work) esté activo en el servidor.',
            ['period' => $run->period?->label],
        );

        $run->forceFill([
            'status' => EfectividadCobranzaRun::STATUS_FAILED,
            'finished_at' => now(),
            'failure_kind' => EfectividadCobranzaRun::FAILURE_WORKER_STALLED,
            'error_message' => 'El proceso de generación dejó de responder (worker sin actividad).',
            'log' => 'El proceso de generación dejó de responder (worker sin actividad).',
            'issues' => EfectividadCobranzaIssue::normalize($issues),
        ])->save();

        $notifier->notify($run);

        return 'cerrada como fallida (worker sin actividad)';
    }

    private function requeue(EfectividadCobranzaRun $run, Dispatcher $dispatcher): string
    {
        $lockKey = "efectividad-cobranza-generate-{$run->period_id}-".($run->branch_id ?? 'general');

        return Cache::lock($lockKey, 15)->block(5, function () use ($run, $dispatcher) {
            $run->refresh();
            if (! $run->isActive()) {
                return 'omitida (ya no está activa)';
            }

            $run->forceFill([
                'status' => EfectividadCobranzaRun::STATUS_QUEUED,
                'queued_at' => now(),
                'current_stage' => null,
                'heartbeat_at' => null,
                'progress_current' => null,
                'progress_total' => null,
                'queue_job_id' => null,
                'queue_job_uuid' => null,
                'log' => 'Recuperada: se volvió a encolar tras perder actividad del worker.',
                'metadata' => [...($run->metadata ?? []), 'recovered_at' => now()->toISOString()],
            ])->save();

            $jobId = $dispatcher->dispatch(new GenerateEfectividadCobranzaAprobadaJob($run->id));
            if (is_scalar($jobId) && (string) $jobId !== '' && (string) $jobId !== '0') {
                EfectividadCobranzaRun::query()->whereKey($run->id)->whereNull('queue_job_id')->update(['queue_job_id' => (string) $jobId]);
            }

            return 're-encolada (misma ejecución)';
        });
    }
}
