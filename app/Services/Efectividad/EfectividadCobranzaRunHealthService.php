<?php

namespace App\Services\Efectividad;

use App\Models\EfectividadCobranzaRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deriva el estado VISIBLE de una ejecución a partir de su estado persistido,
 * su última actividad y — cuando el driver lo permite (`database`) — el estado
 * REAL del Job en la cola (`jobs` / `failed_jobs`).
 *
 * Estados visibles:
 *   queued (En cola) · running (Procesando) · retrying (Reintentando) ·
 *   delayed (Retrasado) · stalled (Sin actividad del worker) ·
 *   blocked (Bloqueado por datos) · failed (Error) · success (Terminado) ·
 *   obsolete (Obsoleto: hay una versión más reciente o cambiaron las fuentes)
 *
 * Regla clave: "lleva varios minutos" NUNCA basta para declarar muerto un Job
 * (un Excel grande tarda). `stalled` exige además evidencia de la cola: el
 * Job ya no existe en `jobs` (o terminó en `failed_jobs`) y no hay actividad
 * reciente. Sin esa evidencia (otro driver), se muestra `delayed`, no `stalled`.
 */
class EfectividadCobranzaRunHealthService
{
    public const QUEUE_PENDING = 'pending';   // en `jobs`, sin reservar

    public const QUEUE_RESERVED = 'reserved';  // en `jobs`, tomado por un worker

    public const QUEUE_FAILED = 'failed';    // en `failed_jobs`

    public const QUEUE_ABSENT = 'absent';    // no está en ninguna tabla

    public const QUEUE_UNKNOWN = 'unknown';   // driver sin inspección (sync/redis/…)

    /**
     * @return array{state:string, label:string, message:?string, queue_state:string, minutes_since_activity:?int}
     */
    public function assess(EfectividadCobranzaRun $run): array
    {
        $now = Carbon::now();
        $lastActivity = $run->heartbeat_at ?? $run->started_at ?? $run->queued_at ?? $run->created_at;
        $minutesSince = $lastActivity ? (int) floor($lastActivity->diffInSeconds($now) / 60) : null;

        if (! $run->isActive()) {
            return $this->result($run->status, null, self::QUEUE_UNKNOWN, $minutesSince);
        }

        $queueState = $this->queueState($run);

        if ($queueState === self::QUEUE_FAILED) {
            return $this->result('stalled', 'La cola registró el trabajo como fallido, pero la ejecución no se cerró. Un administrador puede recuperarla.', $queueState, $minutesSince);
        }

        if ($run->status === EfectividadCobranzaRun::STATUS_QUEUED) {
            $waited = ($run->queued_at ?? $run->created_at)?->diffInMinutes($now) ?? 0;

            if ($waited >= (int) config('efectividad.queued_delay_minutes', 5)) {
                if ($queueState === self::QUEUE_ABSENT) {
                    return $this->result('stalled', 'El trabajo ya no está en la cola y nunca inició. Un administrador puede recuperarlo sin duplicar la ejecución.', $queueState, $minutesSince);
                }

                return $this->result('delayed', sprintf(
                    'Lleva %d min en cola sin que un worker lo tome. Verifica que el proceso de colas (queue:work) esté activo.',
                    $waited,
                ), $queueState, $minutesSince);
            }

            return $this->result('queued', null, $queueState, $minutesSince);
        }

        // running / retrying
        $staleAfter = (int) config('efectividad.heartbeat_stale_minutes', 15);

        if ($minutesSince !== null && $minutesSince >= $staleAfter) {
            if ($queueState === self::QUEUE_ABSENT) {
                return $this->result('stalled', sprintf(
                    'Sin actividad del worker desde hace %d min y el trabajo ya no está en la cola. Probablemente el proceso se detuvo; un administrador puede recuperarlo.',
                    $minutesSince,
                ), $queueState, $minutesSince);
            }

            return $this->result('delayed', sprintf(
                'Sin actividad registrada desde hace %d min. El worker %s; un archivo grande puede tardar en la etapa actual.',
                $minutesSince,
                $queueState === self::QUEUE_RESERVED ? 'sigue reteniendo el trabajo' : 'no ha confirmado avance',
            ), $queueState, $minutesSince);
        }

        return $this->result($run->status, null, $queueState, $minutesSince);
    }

    /** Estado real del Job en la cola `database` (por id de dispatch y por uuid). */
    public function queueState(EfectividadCobranzaRun $run): string
    {
        $connection = config('queue.default');
        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return self::QUEUE_UNKNOWN;
        }

        $jobsTable = config("queue.connections.{$connection}.table", 'jobs');
        $failedTable = config('queue.failed.table', 'failed_jobs');

        if (! Schema::hasTable($jobsTable)) {
            return self::QUEUE_UNKNOWN;
        }

        $job = null;
        if ($run->queue_job_id) {
            $job = DB::table($jobsTable)->where('id', $run->queue_job_id)->first(['id', 'reserved_at']);
        }
        if (! $job && $run->queue_job_uuid) {
            $job = DB::table($jobsTable)->where('payload', 'like', '%"uuid":"'.$run->queue_job_uuid.'"%')->first(['id', 'reserved_at']);
        }

        if ($job) {
            return $job->reserved_at ? self::QUEUE_RESERVED : self::QUEUE_PENDING;
        }

        if ($run->queue_job_uuid && Schema::hasTable($failedTable)
            && DB::table($failedTable)->where('uuid', $run->queue_job_uuid)->exists()) {
            return self::QUEUE_FAILED;
        }

        // Sin id ni uuid registrados (ejecuciones anteriores a este cambio) no se
        // puede afirmar que el Job desapareció.
        if (! $run->queue_job_id && ! $run->queue_job_uuid) {
            return self::QUEUE_UNKNOWN;
        }

        return self::QUEUE_ABSENT;
    }

    public static function label(string $state): string
    {
        return match ($state) {
            'queued' => 'En cola',
            'running' => 'Procesando',
            'retrying' => 'Reintentando',
            'delayed' => 'Retrasado',
            'stalled' => 'Sin actividad del worker',
            'blocked' => 'Bloqueado por datos',
            'failed' => 'Error',
            'success' => 'Terminado',
            'obsolete' => 'Obsoleto',
            default => $state,
        };
    }

    private function result(string $state, ?string $message, string $queueState, ?int $minutesSince): array
    {
        return [
            'state' => $state,
            'label' => self::label($state),
            'message' => $message,
            'queue_state' => $queueState,
            'minutes_since_activity' => $minutesSince,
        ];
    }
}
