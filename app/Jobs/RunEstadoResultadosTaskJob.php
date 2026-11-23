<?php

namespace App\Jobs;

use App\Models\EstadoResultados\EstadoResultadosTask;
use App\Models\Period;
use App\Models\User;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Reporting\EstadoResultadosApprovalService;
use App\Services\EstadoResultados\Reporting\EstadoResultadosGovernanceException;
use App\Services\EstadoResultados\Support\ProcessingLockedException;
use App\Support\TechnicalErrorReference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * «Procesar conciliación» y «Aprobar» del Estado de Resultados en el worker de colas
 * (23-nov-2026). Antes corrían dentro de la petición web subiendo memory_limit a 1-2 GB
 * en PHP-FPM. El cálculo es EXACTAMENTE el mismo: se llama al mismo servicio
 * (EstadoResultadosProcessor::process / EstadoResultadosApprovalService::approve) con los
 * mismos argumentos; sólo cambia dónde se ejecuta.
 *
 * El resultado (JSON + código HTTP) se guarda en la tarea tal como lo devolvía la petición
 * síncrona: éxito 200, 409 en curso/conflicto, 422 bloqueos, 423 aprobado, 500 técnico con
 * referencia (el detalle técnico sólo va al log). Nunca relanza: la tarea siempre termina
 * en `ok` o `error` y la pantalla deja de esperar.
 */
class RunEstadoResultadosTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    /**
     * @param  int  $taskId  Id de la tarea (estado_resultados_tasks) a ejecutar.
     */
    public function __construct(public int $taskId) {}

    /**
     * Reclama la tarea con una transición atómica queued→running (si otra entrega
     * ya la tomó, no hace nada), ajusta memoria (2 GB aprobar / 1 GB procesar),
     * ejecuta la operación y guarda el resultado.
     * Efectos: actualiza la fila de la tarea; el servicio invocado escribe la
     * conciliación o la versión aprobada en BD.
     */
    public function handle(): void
    {
        $task = EstadoResultadosTask::query()->find($this->taskId);
        if (! $task) {
            return;
        }

        // Transición atómica queued→running: una re-entrega del mismo job (retry_after vencido)
        // o un job duplicado nunca ejecuta la tarea dos veces.
        $claimed = EstadoResultadosTask::query()->whereKey($task->id)->where('status', EstadoResultadosTask::STATUS_QUEUED)->update([
            'status' => EstadoResultadosTask::STATUS_RUNNING,
            'started_at' => now(),
            'progress_percent' => 10,
            'current_step' => $task->kind === EstadoResultadosTask::KIND_APPROVE
                ? 'Congelando las cifras de todos los alcances…'
                : 'Cruzando banco contra ERP y aplicando reglas…',
        ]);
        if ($claimed === 0) {
            Log::info('RunEstadoResultadosTaskJob: la tarea ya no está en cola; no se ejecuta.', ['task_id' => $task->id, 'status' => $task->status]);

            return;
        }
        $task->refresh();

        @ini_set('memory_limit', $task->kind === EstadoResultadosTask::KIND_APPROVE ? '2048M' : '1024M');
        @set_time_limit($this->timeout);

        [$status, $body] = $this->execute($task);

        $this->finish($task, $status, $body);
    }

    /**
     * Ejecuta procesar o aprobar y traduce cada excepción a un código HTTP y cuerpo
     * JSON: 404 periodo inexistente, 409 procesamiento en curso, status de la
     * excepción de gobernanza (p. ej. 423 aprobado), 422 argumento inválido,
     * 500 error técnico con referencia (detalle solo al log).
     *
     * @param  EstadoResultadosTask  $task  Tarea en ejecución.
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function execute(EstadoResultadosTask $task): array
    {
        $period = Period::query()->find($task->period_id);
        if (! $period) {
            return [404, ['message' => 'El periodo ya no existe.']];
        }

        try {
            return $task->kind === EstadoResultadosTask::KIND_APPROVE
                ? $this->approve($task, $period)
                : $this->process($task, $period);
        } catch (ProcessingLockedException $e) {
            return [409, ['message' => $e->getMessage(), 'processing' => true]];
        } catch (EstadoResultadosGovernanceException $e) {
            // Incluye EstadoResultadosLockedException (423): mismo cuerpo que su render().
            return [$e->status(), ['message' => $e->getMessage()] + $e->payload()];
        } catch (\InvalidArgumentException $e) {
            $prefix = $task->kind === EstadoResultadosTask::KIND_APPROVE ? 'No se pudo aprobar: ' : 'No se pudo procesar la conciliación: ';

            return [422, ['message' => $prefix.$e->getMessage()]];
        } catch (\Throwable $e) {
            // Nunca se muestra el detalle técnico (SQL, rutas): referencia para Sistemas, detalle en el log.
            $ref = 'ERR-'.strtoupper(Str::random(8));
            Log::error('estado_resultados.'.($task->kind === EstadoResultadosTask::KIND_APPROVE ? 'approve' : 'process').'_failed', [
                'period_id' => $task->period_id, 'task_id' => $task->id, 'error_ref' => $ref,
                'error' => $e->getMessage(), 'exception' => $e::class,
            ]);

            return [500, ['message' => TechnicalErrorReference::message($ref), 'reference' => $ref]];
        }
    }

    /**
     * Ejecuta la conciliación (EstadoResultadosProcessor::process) del periodo.
     *
     * @param  EstadoResultadosTask  $task  Tarea (aporta el usuario solicitante).
     * @param  Period  $period  Periodo a procesar.
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function process(EstadoResultadosTask $task, Period $period): array
    {
        $run = app(EstadoResultadosProcessor::class)->process($period, $task->user_id);

        return [200, ['message' => 'Conciliación procesada.', 'summary' => $run->summary]];
    }

    /**
     * Aprueba (congela) el Estado de Resultados del periodo con
     * EstadoResultadosApprovalService::approve, usando las notas y el hash esperado
     * guardados en la tarea. Devuelve 403 si el usuario solicitante ya no existe.
     *
     * @param  EstadoResultadosTask  $task  Tarea (aporta usuario e input).
     * @param  Period  $period  Periodo a aprobar.
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function approve(EstadoResultadosTask $task, Period $period): array
    {
        $user = $task->user_id ? User::query()->find($task->user_id) : null;
        if (! $user) {
            return [403, ['message' => 'No se encontró al usuario que pidió la aprobación. Vuelve a iniciar sesión e inténtalo de nuevo.']];
        }
        $input = (array) ($task->input ?? []);
        $version = app(EstadoResultadosApprovalService::class)->approve($period, $user, $input['notes'] ?? null, $input['expected_hash'] ?? null);

        return [200, [
            'message' => 'Estado de Resultados de '.$period->label.' aprobado (versión '.$version->version.'). Las cifras quedaron congeladas.',
            'version' => EstadoResultadosApprovalService::summary($version->load(['approver:id,name', 'reopener:id,name'])),
        ]];
    }

    /**
     * Guarda el resultado final en la tarea: estado ok (<400) o error, código HTTP,
     * cuerpo de respuesta, progreso 100 y fecha de término.
     *
     * @param  EstadoResultadosTask  $task  Tarea a cerrar.
     * @param  int  $status  Código HTTP equivalente.
     * @param  array  $body  Cuerpo JSON que leerá la pantalla.
     */
    private function finish(EstadoResultadosTask $task, int $status, array $body): void
    {
        $task->forceFill([
            'status' => $status < 400 ? EstadoResultadosTask::STATUS_OK : EstadoResultadosTask::STATUS_ERROR,
            'http_status' => $status,
            'response' => $body,
            'progress_percent' => 100,
            'current_step' => $status < 400 ? 'Terminado' : 'Terminó con aviso o error',
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Si la tarea sigue abierta, la cierra con error 500 y una referencia técnica
     * registrada en el log.
     *
     * Timeout o muerte del worker: la tarea no se queda «en proceso» para siempre.
     *
     * @param  \Throwable|null  $exception  Excepción que provocó la falla (si existe).
     */
    public function failed(?\Throwable $exception): void
    {
        $task = EstadoResultadosTask::query()->find($this->taskId);
        if (! $task || $task->isFinished()) {
            return;
        }
        $ref = 'ERR-'.strtoupper(Str::random(8));
        Log::error('estado_resultados.task_failed', [
            'task_id' => $task->id, 'period_id' => $task->period_id, 'error_ref' => $ref,
            'exception' => $exception ? $exception::class : null, 'error' => $exception?->getMessage(),
        ]);
        $this->finish($task, 500, ['message' => TechnicalErrorReference::message($ref), 'reference' => $ref]);
    }
}
