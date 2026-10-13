<?php

namespace App\Jobs;

use App\Enums\EfectividadAprobadaStage;
use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use App\Services\Efectividad\EfectividadCobranzaAprobadaExcelExporter;
use App\Services\Efectividad\EfectividadCobranzaAprobadaReportService;
use App\Services\Efectividad\EfectividadCobranzaCancelledException;
use App\Services\Efectividad\EfectividadCobranzaIssue;
use App\Services\Efectividad\EfectividadCobranzaProgressTracker;
use App\Services\Efectividad\EfectividadCobranzaRunNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Genera la Efectividad de Cobranza con el motor APROBADO anterior
 * (`CALCULADORA DE EFECTIVIDAD DE COBRANZA APROBADO.xlsm`) para periodos SIN
 * fotografías diarias — exactamente la operación real del usuario: un corte
 * de Saldos por Cliente (`fact_portfolios`) + histórico de Ingresos Cobranza
 * (`fact_recoveries`) cada 4 semanas, ya importados por el flujo normal de
 * Reportería (Histórico General). Nunca pide fotografías.
 *
 * Deliberadamente SEPARADO de `GenerateEfectividadCobranzaAprobadaJob` (motor
 * V4): comparten el mismo modelo (`EfectividadCobranzaRun`) y el mismo patrón
 * de cancelación cooperativa/transición atómica, pero NINGUNA fórmula ni
 * etapa — así un cambio aquí nunca arriesga el Job V4 ya probado contra 372
 * valores de referencia.
 *
 * Es una metodología DISTINTA de V4 (otra macro aprobada, otro Excel de
 * referencia) — nunca se presenta como V4 ni se mezcla con sus resultados
 * (`engine_version` e identidad de la ejecución ya lo garantizan).
 */
class GenerateEfectividadAprobadaPeriodicaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout;

    public int $tries = 2;

    public bool $failOnTimeout = true;

    public int $backoff = 30;

    public function __construct(
        public int $runId,
    ) {
        $this->timeout = (int) config('efectividad.job_timeout', 900);
    }

    public function middleware(): array
    {
        $run = EfectividadCobranzaRun::query()->find($this->runId);
        $key = $run
            ? "efectividad-aprobada-periodica-{$run->period_id}-".($run->branch_id ?? 'general')
            : "efectividad-aprobada-periodica-run-{$this->runId}";

        return [(new WithoutOverlapping($key))->releaseAfter(60)->expireAfter($this->timeout + 60)];
    }

    public function handle(
        EfectividadCobranzaAprobadaReportService $service,
        EfectividadCobranzaAprobadaExcelExporter $exporter,
        EfectividadCobranzaRunNotifier $notifier,
    ): void {
        @ini_set('memory_limit', '1024M');
        @set_time_limit($this->timeout);

        $run = EfectividadCobranzaRun::query()->find($this->runId);

        if (! $run) {
            return;
        }

        if ($run->isTerminal()) {
            Log::info('Efectividad Aprobada (periódica): Job omitido — la ejecución ya está en estado final.', ['run_id' => $run->id, 'status' => $run->status]);

            return;
        }

        if ($run->isCancelRequested()) {
            $this->closeAsCancelled($run, new EfectividadCobranzaProgressTracker($run), $notifier);

            return;
        }

        $period = Period::query()->findOrFail($run->period_id);

        $run->forceFill([
            'status' => EfectividadCobranzaRun::STATUS_RUNNING,
            'engine_version' => EfectividadCobranzaRun::ENGINE_APROBADA_2026_10,
            'started_at' => $run->started_at ?: now(),
            'heartbeat_at' => now(),
            'attempts' => $this->attempts(),
            'queue_job_uuid' => rescue(fn () => $this->job?->uuid(), null, false) ?: $run->queue_job_uuid,
            'failure_kind' => null,
            'error_message' => null,
            'issues' => [],
            'stages' => [],
        ])->save();

        $tracker = new EfectividadCobranzaProgressTracker($run);

        try {
            // ── 1. Lectura de cartera e ingresos (indeterminada: dos fases,
            // dos totales distintos — nunca se mezclan en un solo porcentaje) ──
            $tracker->start(EfectividadAprobadaStage::ReadSources);
            $onProgress = function (string $fase, int $procesadas, ?int $total) use ($run, $tracker): void {
                $tracker->heartbeat();
                $this->throwIfCancelRequested($run);
            };

            $result = $service->generate($period, $run->branch_id, $onProgress, function (string $stage) use ($run): void {
                $this->throwIfCancelRequested($run);
            });

            if (! $result['is_available']) {
                $this->closeAsBlocked($run, $tracker, $notifier, $result['coverage']);

                return;
            }

            $tracker->complete(EfectividadAprobadaStage::ReadSources, $result['coverage']['message']);

            // ── 2. Cálculo ───────────────────────────────────────────────────
            $this->throwIfCancelRequested($run);
            $tracker->start(EfectividadAprobadaStage::Calculate);
            $rows = $result['rows'];
            $tracker->complete(EfectividadAprobadaStage::Calculate, sprintf(
                '%d fila(s) calculadas (promotores, totales por oficina y GRAN TOTAL).',
                count($rows),
            ));

            // ── 3. Excel ───────────────────────────────────────────────────────
            $this->throwIfCancelRequested($run);
            $tracker->start(EfectividadAprobadaStage::BuildExcel);
            $scopeLabel = $run->branch_id ? "sucursal_{$run->branch_id}" : 'general';
            $relativePath = "efectividad_cobranza/efectividad_aprobada_{$period->id}_{$scopeLabel}_run{$run->id}_".now()->format('Ymd_His').'.xlsx';

            Storage::disk('local')->makeDirectory('efectividad_cobranza');
            $exporter->export($rows, $period->label, Storage::disk('local')->path($relativePath));

            if (! Storage::disk('local')->exists($relativePath) || Storage::disk('local')->size($relativePath) === 0) {
                throw new \RuntimeException('El archivo Excel generado está vacío o no existe.');
            }
            $tracker->complete(EfectividadAprobadaStage::BuildExcel, sprintf(
                'Archivo XLSX generado (%s KB): hoja EFECTIVIDAD (formato aprobado).',
                number_format(Storage::disk('local')->size($relativePath) / 1024, 1),
            ));

            // ── 4. Finalización (atómica, condicionada a no-cancelación) ──────
            $tracker->start(EfectividadAprobadaStage::Finish);

            $published = EfectividadCobranzaRun::query()
                ->whereKey($run->id)
                ->whereNull('cancel_requested_at')
                ->update([
                    'status' => EfectividadCobranzaRun::STATUS_SUCCESS,
                    'coverage' => json_encode($result['coverage']),
                    'result_rows' => json_encode($rows),
                    'output_excel_path' => $relativePath,
                    'incidents_count' => 0,
                    'metadata' => json_encode([...($run->metadata ?? []), 'is_available' => true, 'methodology' => 'aprobada_periodica']),
                    'finished_at' => now(),
                    'heartbeat_at' => now(),
                    'log' => EfectividadAprobadaStage::Finish->description(),
                ]);

            $run->refresh();

            if ($published === 0) {
                Storage::disk('local')->delete($relativePath);
                $this->closeAsCancelled($run, $tracker, $notifier);

                return;
            }

            $tracker->complete(EfectividadAprobadaStage::Finish, EfectividadAprobadaStage::Finish->description());
        } catch (EfectividadCobranzaCancelledException) {
            $this->closeAsCancelled($run, $tracker, $notifier);

            return;
        } catch (\Throwable $exception) {
            $this->handleException($run, $tracker, $notifier, $exception);

            throw $exception;
        }

        $notifier->notify($run);
    }

    /** @throws EfectividadCobranzaCancelledException si se solicitó detener la generación. */
    private function throwIfCancelRequested(EfectividadCobranzaRun $run): void
    {
        $cancelled = EfectividadCobranzaRun::query()->whereKey($run->id)->whereNotNull('cancel_requested_at')->exists();

        if ($cancelled) {
            throw new EfectividadCobranzaCancelledException;
        }
    }

    private function closeAsCancelled(EfectividadCobranzaRun $run, EfectividadCobranzaProgressTracker $tracker, EfectividadCobranzaRunNotifier $notifier): void
    {
        $tracker->halt('cancelled', 'La generación fue cancelada por solicitud del usuario.');
        $run->forceFill([
            'status' => EfectividadCobranzaRun::STATUS_CANCELLED,
            'finished_at' => now(),
            'cancelled_at' => now(),
            'heartbeat_at' => now(),
            'result_rows' => [],
            'log' => 'La generación fue cancelada por solicitud del usuario.',
        ])->save();

        $notifier->notify($run);
    }

    /** Bloqueo por datos: refleja honestamente qué falta según `coverage` del adaptador aprobado. */
    private function closeAsBlocked(EfectividadCobranzaRun $run, EfectividadCobranzaProgressTracker $tracker, EfectividadCobranzaRunNotifier $notifier, array $coverage): void
    {
        $failureKind = match ($coverage['status']) {
            'bloqueado_sin_fechas' => EfectividadCobranzaRun::FAILURE_MISSING_COLUMNS,
            default => EfectividadCobranzaRun::FAILURE_MISSING_DATA,
        };

        $issues = [EfectividadCobranzaIssue::make(
            'EC-APR-'.mb_strtoupper($coverage['status']),
            EfectividadCobranzaIssue::SEVERITY_CRITICAL,
            $failureKind === EfectividadCobranzaRun::FAILURE_MISSING_COLUMNS ? EfectividadCobranzaIssue::CATEGORY_MISSING_COLUMNS : EfectividadCobranzaIssue::CATEGORY_MISSING_DATA,
            $coverage['message'],
            'Revisa las fuentes del periodo en Histórico General (Saldos por Cliente / Ingresos Cobranza).',
        )];

        $tracker->halt('blocked', $coverage['message']);
        $run->forceFill([
            'status' => EfectividadCobranzaRun::STATUS_BLOCKED,
            'finished_at' => now(),
            'heartbeat_at' => now(),
            'result_rows' => [],
            'coverage' => $coverage,
            'issues' => EfectividadCobranzaIssue::normalize($issues),
            'failure_kind' => $failureKind,
            'log' => $coverage['message'],
            'metadata' => [...($run->metadata ?? []), 'is_available' => false],
        ])->save();

        $notifier->notify($run);
    }

    public function failed(?\Throwable $exception): void
    {
        $run = EfectividadCobranzaRun::query()->find($this->runId);
        if (! $run) {
            return;
        }

        $notifier = app(EfectividadCobranzaRunNotifier::class);

        if ($run->isTerminal()) {
            $notifier->notify($run);

            return;
        }

        if ($run->isCancelRequested()) {
            $this->closeAsCancelled($run, new EfectividadCobranzaProgressTracker($run), $notifier);

            return;
        }

        $isTimeout = $exception instanceof TimeoutExceededException;
        $isMaxAttempts = $exception instanceof MaxAttemptsExceededException;

        Log::error('Efectividad Aprobada (periódica): el Job terminó como fallido.', [
            'run_id' => $run->id,
            'exception' => $exception ? get_class($exception) : null,
            'message' => $exception?->getMessage(),
        ]);

        $issues = $run->issues ?? [];
        $issues[] = EfectividadCobranzaIssue::make(
            $isTimeout ? 'EC-TEC-TIEMPO-EXCEDIDO' : ($isMaxAttempts ? 'EC-TEC-INTENTOS-AGOTADOS' : 'EC-TEC-ERROR'),
            EfectividadCobranzaIssue::SEVERITY_CRITICAL,
            EfectividadCobranzaIssue::CATEGORY_TECHNICAL,
            $isTimeout
                ? sprintf('La generación superó el tiempo máximo permitido (%d min) y se detuvo.', intdiv($this->timeout, 60))
                : 'Se produjo un error durante la generación y se agotaron los reintentos.',
            'Vuelve a intentarlo. Si el problema persiste, contacta al equipo de sistemas con el identificador de esta ejecución.',
        );

        $run->forceFill([
            'status' => EfectividadCobranzaRun::STATUS_FAILED,
            'finished_at' => now(),
            'heartbeat_at' => now(),
            'failure_kind' => $isTimeout ? EfectividadCobranzaRun::FAILURE_TIMEOUT : EfectividadCobranzaRun::FAILURE_TECHNICAL,
            'error_message' => 'Se produjo un error durante la generación.',
            'log' => 'Se produjo un error durante la generación.',
            'issues' => EfectividadCobranzaIssue::normalize($issues),
        ])->save();

        $notifier->notify($run);
    }

    private function handleException(EfectividadCobranzaRun $run, EfectividadCobranzaProgressTracker $tracker, EfectividadCobranzaRunNotifier $notifier, \Throwable $exception): void
    {
        $stage = $tracker->current();

        Log::error('GenerateEfectividadAprobadaPeriodicaJob falló.', [
            'run_id' => $run->id,
            'stage' => $stage?->value,
            'attempt' => $this->attempts(),
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        if ($run->refresh()->isCancelRequested()) {
            $this->closeAsCancelled($run, $tracker, $notifier);

            return;
        }

        $tracker->halt('failed', 'Error técnico en esta etapa.');

        $stageLabel = $stage?->label() ?? 'inicio';
        $willRetry = $this->attempts() < $this->tries;

        if ($willRetry) {
            $run->forceFill([
                'status' => EfectividadCobranzaRun::STATUS_RETRYING,
                'heartbeat_at' => now(),
                'log' => "El intento {$this->attempts()} falló en la etapa «{$stageLabel}». Se reintentará automáticamente en {$this->backoff} segundos.",
                'metadata' => [...($run->metadata ?? []), 'last_error_reference' => class_basename($exception)],
            ])->save();

            return;
        }

        $issues = [EfectividadCobranzaIssue::make(
            'EC-TEC-ERROR',
            EfectividadCobranzaIssue::SEVERITY_CRITICAL,
            EfectividadCobranzaIssue::CATEGORY_TECHNICAL,
            "Se produjo un error técnico durante la etapa «{$stageLabel}». El detalle quedó registrado en el servidor.",
            'Vuelve a intentarlo. Si el problema persiste, contacta al equipo de sistemas con el identificador de esta ejecución.',
        )];

        $run->forceFill([
            'status' => EfectividadCobranzaRun::STATUS_FAILED,
            'finished_at' => now(),
            'heartbeat_at' => now(),
            'failure_kind' => EfectividadCobranzaRun::FAILURE_TECHNICAL,
            'error_message' => "Se produjo un error durante la generación (etapa «{$stageLabel}»).",
            'log' => "Se produjo un error durante la generación (etapa «{$stageLabel}»).",
            'issues' => EfectividadCobranzaIssue::normalize($issues),
            'metadata' => [...($run->metadata ?? []), 'last_error_reference' => class_basename($exception)],
        ])->save();

        $notifier->notify($run);
    }
}
