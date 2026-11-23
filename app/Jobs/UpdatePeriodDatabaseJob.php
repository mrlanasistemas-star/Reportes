<?php

namespace App\Jobs;

use App\Mail\DatabaseUpdateCompletedMail;
use App\Mail\DatabaseUpdateFailedMail;
use App\Models\Period;
use App\Models\PeriodDatabaseUpdateRun;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\DatabaseUpdateService;
use App\Services\Notifications\ReporteriaNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Job en cola de "Cargar registros": lee todos los archivos subidos del periodo
 * y los vuelca en las tablas de hechos (fact_*), vía DatabaseUpdateService.
 *
 * Lo despachan DatabaseUpdateService / ReportUploadController. Diseño de
 * concurrencia: el run se reclama con compare-and-set queued→running (una
 * re-entrega de la cola no importa dos veces) y el éxito/error también se
 * marca con compare-and-set sobre `running`, para que una cancelación manual
 * concurrente nunca termine como éxito ni se sobrescriba.
 * Un solo intento, timeout de 30 min.
 */
class UpdatePeriodDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public int $maxExceptions = 1;

    /**
     * @param  int  $periodId  Periodo a cargar.
     * @param  int  $runId  Id del PeriodDatabaseUpdateRun que refleja el avance.
     * @param  int|null  $userId  Usuario que lo solicitó (correo y notificación).
     */
    public function __construct(
        public int $periodId,
        public int $runId,
        public ?int $userId = null,
    ) {}

    /**
     * Ejecuta la carga del periodo y valida consistencia: aunque no haya excepción,
     * si fact_noi_movements o fact_recoveries quedan vacías para el periodo (y sus
     * semanas base) se considera error. Luego marca el run como success (anotando
     * fuentes con error, si las hubo) o failed y notifica al usuario.
     * Efectos: escribe tablas de hechos, actualiza period_database_update_runs,
     * envía notificación (campana/push) y correo.
     *
     * Lanza ModelNotFoundException (findOrFail) si el periodo o el run no existen.
     *
     * @param  DatabaseUpdateService  $service  Servicio que importa cada fuente del periodo.
     *
     * @throws \Throwable Relanza el error tras marcar el run como fallido (salvo que haya sido cancelado).
     */
    public function handle(DatabaseUpdateService $service): void
    {
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '1800');
        @set_time_limit(1800);

        $period = Period::query()->findOrFail($this->periodId);
        $run = PeriodDatabaseUpdateRun::query()->findOrFail($this->runId);

        // Si se canceló antes de que el worker lo tomara, termina sin hacer nada
        if (! in_array($run->status, ['queued', 'running'], true)) {
            Log::info('UpdatePeriodDatabaseJob: run ya no está activo al iniciar.', ['run_id' => $this->runId, 'status' => $run->status]);

            return;
        }

        // Fase 10 — transición ATÓMICA queued→running (compare-and-set): si entre la
        // lectura y esta escritura alguien canceló el run, no se "resucita".
        // Solo se reclama un run 'queued': si la cola re-entrega el mismo job mientras
        // otro worker ya lo procesa (retry_after vencido), el run está en 'running' y
        // esta segunda entrega sale sin tocar nada (evita importaciones dobles). Es
        // seguro: updateDatabase(), requeueRun() y processNow() solo despachan/ejecutan
        // runs en 'queued'; reportes:run-database-update no usa este job.
        $claimed = PeriodDatabaseUpdateRun::query()->whereKey($run->id)->where('status', 'queued')->update([
            'status' => 'running',
            'started_at' => now(),
            'log' => 'Procesando NOI Nómina y Cobranza…',
            'metadata' => json_encode(['current_step' => 'Iniciando proceso…', 'progress_percent' => 0]),
        ]);
        if ($claimed === 0) {
            Log::info('UpdatePeriodDatabaseJob: run ya no está en cola (cancelado o ya tomado por otro worker); no se procesa.', ['run_id' => $this->runId]);

            return;
        }
        $run->refresh();

        try {
            $service->updateForPeriod($period, $run);

            // Vuelve a revisar cancelación tras el servicio (por si se canceló en el último paso)
            $run->refresh();
            if (! in_array($run->status, ['running'], true)) {
                Log::info('UpdatePeriodDatabaseJob: run cancelado durante procesamiento.', ['run_id' => $this->runId]);

                return;
            }

            $warnings = $period->fresh()?->periodSummary?->warnings['db_update'] ?? [];

            // Valida consistencia antes de marcar éxito: los uploads deben estar procesados
            // y el correo solo se envía cuando las tablas fact_* realmente tienen datos.
            $allPeriods = Period::all();
            $dataIds = array_values(array_unique(array_merge([$this->periodId],
                $period->resolveBaseWeeklyIds($allPeriods))));
            $hasNoi = DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)->exists();
            $hasCobranza = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)->exists();

            if (! $hasNoi || ! $hasCobranza) {
                // Tablas de hechos vacías aunque no hubo excepción — se marca como fallido
                $missing = array_filter([! $hasNoi ? 'NOI' : null, ! $hasCobranza ? 'Cobranza' : null]);
                throw new \RuntimeException(
                    'El proceso terminó sin errores pero las tablas de hechos quedaron vacías ('.implode(', ', $missing).'). '
                    .'Verifica los archivos y vuelve a ejecutar Cargar registros.'
                );
            }

            $failedSources = $run->fresh()->metadata['stats']['import_error_sources'] ?? [];

            // Fase 10 — éxito solo si el run SIGUE en running en el mismo UPDATE
            // (compare-and-set): una cancelación concurrente nunca termina como éxito.
            $marked = PeriodDatabaseUpdateRun::query()->whereKey($run->id)->where('status', 'running')->update([
                'status' => 'success',
                'finished_at' => now(),
                'log' => empty($failedSources)
                    ? 'Base de datos actualizada correctamente. Todos los archivos importados y uploads procesados.'
                    : 'Base de datos actualizada con errores en: '.implode(', ', $failedSources).'.',
            ]);
            if ($marked === 0) {
                Log::info('UpdatePeriodDatabaseJob: run cancelado al terminar; se conserva como cancelado.', ['run_id' => $this->runId]);

                return;
            }
            $run->refresh();

            $emailNote = $this->notifyUser('success', $period, $run, $warnings, '', $failedSources);
            $run->update(['log' => $run->fresh()->log.' '.$emailNote]);
        } catch (\Throwable $exception) {
            // No sobrescribir una cancelación manual
            $run->refresh();
            if (! in_array($run->status, ['running'], true)) {
                Log::info('UpdatePeriodDatabaseJob: run cancelado, omitiendo marca de error.', ['run_id' => $this->runId]);

                return;
            }

            $errorMsg = mb_strimwidth($exception->getMessage(), 0, 2000);

            $marked = PeriodDatabaseUpdateRun::query()->whereKey($run->id)->where('status', 'running')->update([
                'status' => 'failed',
                'finished_at' => now(),
                'log' => 'El proceso terminó con error.',
                'error_message' => $errorMsg,
            ]);
            if ($marked === 0) {
                Log::info('UpdatePeriodDatabaseJob: run cancelado, omitiendo marca de error.', ['run_id' => $this->runId]);

                return;
            }
            $run->refresh();

            $emailNote = $this->notifyUser('failed', $period, $run, [], $errorMsg);
            $run->update(['log' => 'El proceso terminó con error. '.$emailNote]);

            throw $exception;
        }
    }

    /**
     * Envía la notificación de campana/push (ReporteriaNotificationService) y, si
     * el usuario tiene correo y su preferencia de "carga" lo permite, el correo
     * DatabaseUpdateCompletedMail o DatabaseUpdateFailedMail (envío inmediato, no en cola).
     * Nunca lanza: los errores SMTP se registran como warning.
     *
     * @param  string  $result  'success' o 'failed'.
     * @param  Period  $period  Periodo cargado.
     * @param  PeriodDatabaseUpdateRun  $run  Run terminado.
     * @param  array  $stats  Advertencias/estadísticas para el correo de éxito.
     * @param  string  $errorMessage  Mensaje de error para el correo de falla.
     * @param  array  $failedSources  Fuentes que no se pudieron importar (éxito parcial).
     * @return string Nota legible que se agrega al log del run (enviado, omitido o error).
     */
    private function notifyUser(
        string $result,
        Period $period,
        PeriodDatabaseUpdateRun $run,
        array $stats = [],
        string $errorMessage = '',
        array $failedSources = [],
    ): string {
        // 23-nov-2026 — campana + Web Push (en cola; el servicio nunca lanza excepción).
        $notifications = app(ReporteriaNotificationService::class);
        $notifications->databaseUpdateFinished($this->userId, $period, $run, $result === 'success', $failedSources);

        if (! $this->userId) {
            return 'Sin usuario asignado — correo no enviado.';
        }

        $user = User::query()->find($this->userId);

        if (! $user || ! $user->email) {
            Log::warning('UpdatePeriodDatabaseJob: usuario sin correo.', [
                'user_id' => $this->userId,
                'period_id' => $this->periodId,
            ]);

            return 'Usuario sin correo configurado — notificación omitida.';
        }

        if (! $notifications->wantsMail($user, UserNotificationPreference::TYPE_CARGA)) {
            return 'Correo desactivado en las preferencias del usuario.';
        }

        try {
            if ($result === 'success') {
                Mail::to($user->email)->send(
                    new DatabaseUpdateCompletedMail($period, $user, $run, $stats, $failedSources)
                );
            } else {
                Mail::to($user->email)->send(
                    new DatabaseUpdateFailedMail($period, $user, $run, $errorMessage)
                );
            }

            Log::info('UpdatePeriodDatabaseJob: correo enviado.', [
                'user_id' => $user->id, // Fase 10: sin correo electrónico en el log
                'result' => $result,
                'period_id' => $this->periodId,
            ]);

            return "Correo enviado a {$user->email}.";
        } catch (\Throwable $mailException) {
            Log::warning('UpdatePeriodDatabaseJob: no se pudo enviar correo.', [
                'user_id' => $this->userId,
                'period_id' => $this->periodId,
                'result' => $result,
                'error' => $mailException->getMessage(),
            ]);

            return 'No se pudo enviar correo a '.$user->email.': '.mb_strimwidth($mailException->getMessage(), 0, 200);
        }
    }
}
