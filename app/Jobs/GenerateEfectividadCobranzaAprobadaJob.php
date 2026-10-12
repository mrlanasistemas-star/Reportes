<?php

namespace App\Jobs;

use App\Enums\EfectividadCobranzaStage;
use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use App\Services\Efectividad\EfectividadCobranzaAprobadaExcelExporter;
use App\Services\Efectividad\EfectividadCobranzaAprobadaReportService;
use App\Services\Efectividad\EfectividadCobranzaIssue;
use App\Services\Efectividad\EfectividadCobranzaProgressTracker;
use App\Services\Efectividad\EfectividadCobranzaReconciler;
use App\Services\Efectividad\EfectividadCobranzaRunNotifier;
use App\Services\Efectividad\EfectividadCobranzaSourceFingerprintService;
use App\Services\Efectividad\EfectividadCobranzaSourceInspector;
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
 * Genera el reporte de Efectividad de Cobranza Aprobada ÍNTEGRAMENTE en el
 * worker de colas (nunca en la petición HTTP). Reutiliza el adaptador y el
 * calculador aprobados (EfectividadCobranzaAprobadaReportService) y el
 * exportador Excel existente — no cambia ninguna fórmula.
 *
 * Etapas reales persistidas (ver EfectividadCobranzaStage):
 *   1 validación de archivos · 2 verificación de columnas · 3 cartera (medible)
 *   · 4 ingresos (medible) · 5 cálculo · 6 conciliación interna · 7 Excel ·
 *   8 finalización.
 *
 * Garantías:
 *  - Idempotente: si la ejecución ya está en un estado final, el Job no hace
 *    nada (un Job duplicado o un reintento tardío nunca recalcula ni duplica
 *    el Excel ni las notificaciones).
 *  - Reintento honesto: si un intento falla y quedan intentos, el estado es
 *    `retrying` (nunca `failed` seguido de un "resucitado"); solo el último
 *    intento fallido termina en `failed`.
 *  - `blocked` (datos insuficientes) es distinto de `failed` (error técnico).
 *  - Las notificaciones (interna + correo) tienen estado propio; una falla de
 *    SMTP nunca convierte un reporte exitoso en fallido.
 *  - Independiente de la sesión web: todo se lee/escribe en BD/almacenamiento.
 */
class GenerateEfectividadCobranzaAprobadaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout;

    public int $tries = 2;

    /** Un intento que excede el timeout no se reintenta (repetiría el mismo trabajo pesado). */
    public bool $failOnTimeout = true;

    /** Segundos de espera antes del reintento. */
    public int $backoff = 30;

    public function __construct(
        public int $runId,
    ) {
        $this->timeout = (int) config('efectividad.job_timeout', 900);
    }

    /** Evita que dos ejecuciones concurrentes de la MISMA identidad se pisen. */
    public function middleware(): array
    {
        $run = EfectividadCobranzaRun::query()->find($this->runId);
        $key = $run ? "efectividad-cobranza-{$run->period_id}-" . ($run->branch_id ?? 'general') : "efectividad-cobranza-run-{$this->runId}";

        return [(new WithoutOverlapping($key))->releaseAfter(60)->expireAfter($this->timeout + 60)];
    }

    public function handle(
        EfectividadCobranzaAprobadaReportService $reportService,
        EfectividadCobranzaAprobadaExcelExporter $exporter,
        EfectividadCobranzaSourceFingerprintService $fingerprintService,
        EfectividadCobranzaSourceInspector $inspector,
        EfectividadCobranzaReconciler $reconciler,
        EfectividadCobranzaRunNotifier $notifier,
    ): void {
        @ini_set('memory_limit', '1024M');
        @set_time_limit($this->timeout);

        $run = EfectividadCobranzaRun::query()->find($this->runId);

        if (!$run) {
            return;
        }

        if ($run->isTerminal()) {
            // Idempotencia: un Job duplicado/tardío nunca recalcula una ejecución cerrada.
            Log::info('Efectividad: Job omitido — la ejecución ya está en estado final.', ['run_id' => $run->id, 'status' => $run->status]);

            return;
        }

        $period = Period::query()->findOrFail($run->period_id);

        $run->forceFill([
            'status'         => EfectividadCobranzaRun::STATUS_RUNNING,
            'started_at'     => $run->started_at ?: now(),
            'heartbeat_at'   => now(),
            'attempts'       => $this->attempts(),
            'queue_job_uuid' => rescue(fn () => $this->job?->uuid(), null, false) ?: $run->queue_job_uuid,
            'failure_kind'   => null,
            'error_message'  => null,
            'issues'         => [],
            'stages'         => [],
        ])->save();

        $tracker = new EfectividadCobranzaProgressTracker($run);
        $issues = [];

        try {
            // ── 1. Validación de archivos ───────────────────────────────────
            $tracker->start(EfectividadCobranzaStage::ValidateFiles);
            $sources = $inspector->inspectSources($period, $run->branch_id);
            $issues = [...$issues, ...$sources['issues']];

            $run->forceFill([
                'sources'                   => $sources['sources'],
                'saldos_report_upload_id'   => $sources['saldos_upload_id'],
                'ingresos_report_upload_id' => $sources['ingresos_upload_id'],
                'source_fingerprint'        => $fingerprintService->compute($period, $run->branch_id),
                'issues'                    => EfectividadCobranzaIssue::normalize($issues),
            ])->save();

            $tracker->complete(EfectividadCobranzaStage::ValidateFiles, sprintf(
                'Saldos por Cliente (%s): %s registros en %d archivo(s). Ingresos Cobranza: %s movimientos del periodo en %d archivo(s); %s en el histórico.',
                $sources['sources']['saldos']['period_label'],
                number_format($sources['sources']['saldos']['rows_in_db']),
                count($sources['sources']['saldos']['uploads']),
                number_format($sources['sources']['ingresos']['rows_in_period']),
                count($sources['sources']['ingresos']['uploads']),
                number_format($sources['sources']['ingresos']['history_rows']),
            ));

            // ── 2. Verificación de columnas ─────────────────────────────────
            $tracker->start(EfectividadCobranzaStage::VerifyColumns);
            $columns = $inspector->inspectColumns($period, $run->branch_id, $sources['sources']);
            $issues = [...$issues, ...$columns['issues']];

            $run->forceFill([
                'issues'   => EfectividadCobranzaIssue::normalize($issues),
                'metadata' => [...($run->metadata ?? []), 'columns' => $columns['columns']],
            ])->save();

            $missingColumns = collect($columns['issues'])->where('category', EfectividadCobranzaIssue::CATEGORY_MISSING_COLUMNS)->count();
            $checkedFields = collect($columns['columns'])->sum(fn ($c) => count($c['fields']));
            $tracker->complete(EfectividadCobranzaStage::VerifyColumns, $missingColumns === 0
                ? "{$checkedFields} campos requeridos verificados contra el mapeo de encabezados de los importadores."
                : "{$checkedFields} campos verificados; {$missingColumns} columna(s) no encontradas en los archivos de origen.");

            // ── 3-5. Cartera, ingresos y cálculo (adaptador + calculador aprobados) ──
            $phase = null;
            $lastProcessed = 0;
            $onProgress = function (string $newPhase, int $processed, ?int $total) use ($tracker, &$phase, &$lastProcessed): void {
                if ($newPhase !== $phase) {
                    if ($phase === 'cartera') {
                        $tracker->complete(EfectividadCobranzaStage::PreparePortfolio, null, [], $lastProcessed);
                    }
                    $tracker->start($newPhase === 'cartera' ? EfectividadCobranzaStage::PreparePortfolio : EfectividadCobranzaStage::ProcessIncome, $total);
                    $phase = $newPhase;
                }
                $lastProcessed = $processed;
                $tracker->advance($processed);
            };

            $onStage = function (string $stage, array $coverage) use ($tracker, &$lastProcessed): void {
                $tracker->complete(EfectividadCobranzaStage::ProcessIncome, sprintf(
                    '%s movimientos revisados; %s pasan la regla de pagos válidos y %s tienen ambas fechas (utilizables).',
                    number_format($coverage['ingresos_rows_total'] ?? 0),
                    number_format($coverage['ingresos_rows_whitelist_pass'] ?? 0),
                    number_format($coverage['ingresos_rows_usables'] ?? 0),
                ), [], $lastProcessed);
                $tracker->start(EfectividadCobranzaStage::Calculate);
            };

            $result = $reportService->generate($period, $run->branch_id, $onProgress, $onStage);
            $coverage = $result['coverage'];

            $this->patchPortfolioSummary($tracker, $coverage);

            $coverageIssues = $inspector->coverageIssues($coverage, $period, $columns['incompatible_ingresos'], $columns['reprocess_ingresos_dates']);
            $issues = [...$issues, ...$coverageIssues['issues']];

            if (!$result['is_available']) {
                // Bloqueado por datos — NUNCA es success ni failed técnico.
                if (($coverage['status'] ?? null) === 'bloqueado_sin_fechas') {
                    $issues = $this->promoteDateColumnIssues($issues);
                }

                $tracker->halt('blocked', collect($coverageIssues['issues'])->firstWhere('severity', 'critical')['message'] ?? ($coverage['message'] ?? null));
                $run->forceFill([
                    'status'       => EfectividadCobranzaRun::STATUS_BLOCKED,
                    'finished_at'  => now(),
                    'heartbeat_at' => now(),
                    'coverage'     => $coverage,
                    'result_rows'  => [],
                    'issues'       => EfectividadCobranzaIssue::normalize($issues),
                    'failure_kind' => $coverageIssues['failure_kind'] ?? EfectividadCobranzaRun::FAILURE_MISSING_DATA,
                    'log'          => 'No se pudo generar el reporte porque faltan datos obligatorios.',
                    'metadata'     => [...($run->metadata ?? []), 'is_available' => false],
                ])->save();

                $notifier->notify($run);

                return;
            }

            $tracker->complete(EfectividadCobranzaStage::Calculate, sprintf(
                '%d filas calculadas (promotores y totales por oficina).',
                count($result['rows']),
            ));

            // ── 6. Conciliación interna ─────────────────────────────────────
            $tracker->start(EfectividadCobranzaStage::Reconcile);
            $reconciliation = $reconciler->check($result['rows']);

            if (!$reconciliation['ok']) {
                $issues[] = EfectividadCobranzaIssue::make(
                    'EC-TEC-CONCILIACION',
                    EfectividadCobranzaIssue::SEVERITY_CRITICAL,
                    EfectividadCobranzaIssue::CATEGORY_TECHNICAL,
                    'Los totales por oficina no coinciden con el resultado general. No se publicará un resultado inconsistente.',
                    'Contacta al equipo de sistemas con el identificador de esta ejecución.',
                    ['period' => $period->label, 'record_count' => count($reconciliation['differences'])],
                );
                Log::error('Efectividad: la conciliación interna detectó diferencias.', ['run_id' => $run->id, 'differences' => $reconciliation['differences']]);
                $tracker->halt('failed', $reconciliation['summary']);
                $this->closeAsFailed($run, $notifier, $issues, EfectividadCobranzaRun::FAILURE_TECHNICAL, 'Se produjo un error durante la generación: los totales internos no son consistentes.');

                return;
            }

            $tracker->complete(EfectividadCobranzaStage::Reconcile, $reconciliation['summary'], [
                'offices'   => $reconciliation['offices'],
                'promoters' => $reconciliation['promoters'],
                'external'  => false,
            ]);

            // ── 7. Generación de Excel ──────────────────────────────────────
            $tracker->start(EfectividadCobranzaStage::BuildExcel);

            // Disco PRIVADO ('local'), ruta RELATIVA guardada en BD — el controlador
            // de descarga la reconstruye en el servidor donde corre.
            $scopeLabel = $run->branch_id ? "sucursal_{$run->branch_id}" : 'general';
            $relativePath = "efectividad_cobranza/efectividad_aprobada_{$period->id}_{$scopeLabel}_run{$run->id}_" . now()->format('Ymd_His') . '.xlsx';

            Storage::disk('local')->makeDirectory('efectividad_cobranza');
            $exporter->export($result['rows'], $period->label ?? $period->code, Storage::disk('local')->path($relativePath));

            if (!Storage::disk('local')->exists($relativePath) || Storage::disk('local')->size($relativePath) === 0) {
                throw new \RuntimeException('El archivo Excel generado está vacío o no existe.');
            }

            $tracker->complete(EfectividadCobranzaStage::BuildExcel, sprintf(
                'Archivo XLSX generado (%s KB).',
                number_format(Storage::disk('local')->size($relativePath) / 1024, 1),
            ));

            // ── 8. Finalización ─────────────────────────────────────────────
            $tracker->start(EfectividadCobranzaStage::Finish);
            $run->forceFill([
                'coverage'          => $coverage,
                'result_rows'       => $result['rows'],
                'output_excel_path' => $relativePath,
                'issues'            => EfectividadCobranzaIssue::normalize($issues),
                'metadata'          => [...($run->metadata ?? []), 'is_available' => true, 'reconciliation' => $reconciliation],
            ])->save();
            $tracker->complete(EfectividadCobranzaStage::Finish, EfectividadCobranzaStage::Finish->description());

            $run->forceFill([
                'status'       => EfectividadCobranzaRun::STATUS_SUCCESS,
                'finished_at'  => now(),
                'heartbeat_at' => now(),
                'log'          => EfectividadCobranzaStage::Finish->description(),
            ])->save();
        } catch (\Throwable $exception) {
            $this->handleException($run, $tracker, $notifier, $issues, $exception, $period);

            throw $exception;
        }

        $notifier->notify($run);
    }

    /**
     * Llamado por el worker cuando el Job falla definitivamente (incluye
     * timeout y "máximo de intentos excedido", donde `handle()` nunca llega
     * a su `catch`). Cierra la ejecución como `failed` si seguía abierta.
     */
    public function failed(?\Throwable $exception): void
    {
        $run = EfectividadCobranzaRun::query()->find($this->runId);
        if (!$run) {
            return;
        }

        $notifier = app(EfectividadCobranzaRunNotifier::class);

        if ($run->isTerminal()) {
            $notifier->notify($run); // idempotente: solo completa lo que falte

            return;
        }

        $isTimeout = $exception instanceof TimeoutExceededException;
        $isMaxAttempts = $exception instanceof MaxAttemptsExceededException;

        Log::error('Efectividad: el Job terminó como fallido.', [
            'run_id'    => $run->id,
            'exception' => $exception ? get_class($exception) : null,
            'message'   => $exception?->getMessage(),
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

        $this->closeAsFailed(
            $run,
            $notifier,
            $issues,
            $isTimeout ? EfectividadCobranzaRun::FAILURE_TIMEOUT : EfectividadCobranzaRun::FAILURE_TECHNICAL,
            'Se produjo un error durante la generación.',
        );
    }

    private function handleException(EfectividadCobranzaRun $run, EfectividadCobranzaProgressTracker $tracker, EfectividadCobranzaRunNotifier $notifier, array $issues, \Throwable $exception, Period $period): void
    {
        $stage = $tracker->current();

        // Detalle técnico SOLO en el log del servidor — nunca en la UI ni en el correo.
        Log::error('GenerateEfectividadCobranzaAprobadaJob falló.', [
            'run_id'    => $run->id,
            'period_id' => $period->id,
            'branch_id' => $run->branch_id,
            'stage'     => $stage?->value,
            'attempt'   => $this->attempts(),
            'exception' => get_class($exception),
            'message'   => $exception->getMessage(),
            'trace'     => $exception->getTraceAsString(),
        ]);

        $tracker->halt('failed', 'Error técnico en esta etapa.');

        $stageLabel = $stage?->label() ?? 'inicio';
        $willRetry = $this->attempts() < $this->tries;

        if ($willRetry) {
            $run->forceFill([
                'status'       => EfectividadCobranzaRun::STATUS_RETRYING,
                'heartbeat_at' => now(),
                'log'          => "El intento {$this->attempts()} falló en la etapa «{$stageLabel}». Se reintentará automáticamente en {$this->backoff} segundos.",
                'metadata'     => [...($run->metadata ?? []), 'last_error_reference' => class_basename($exception)],
            ])->save();

            return;
        }

        $issues[] = EfectividadCobranzaIssue::make(
            'EC-TEC-ERROR',
            EfectividadCobranzaIssue::SEVERITY_CRITICAL,
            EfectividadCobranzaIssue::CATEGORY_TECHNICAL,
            "Se produjo un error técnico durante la etapa «{$stageLabel}». El detalle quedó registrado en el servidor.",
            'Vuelve a intentarlo. Si el problema persiste, contacta al equipo de sistemas con el identificador de esta ejecución.',
            ['period' => $period->label],
        );

        $this->closeAsFailed(
            $run,
            $notifier,
            $issues,
            EfectividadCobranzaRun::FAILURE_TECHNICAL,
            "Se produjo un error durante la generación (etapa «{$stageLabel}»).",
            class_basename($exception),
        );
    }

    private function closeAsFailed(EfectividadCobranzaRun $run, EfectividadCobranzaRunNotifier $notifier, array $issues, string $failureKind, string $message, ?string $reference = null): void
    {
        $run->forceFill([
            'status'        => EfectividadCobranzaRun::STATUS_FAILED,
            'finished_at'   => now(),
            'heartbeat_at'  => now(),
            'failure_kind'  => $failureKind,
            'error_message' => $message,
            'log'           => $message,
            'issues'        => EfectividadCobranzaIssue::normalize($issues),
            'metadata'      => [...($run->metadata ?? []), 'last_error_reference' => $reference ?? ($run->metadata['last_error_reference'] ?? null)],
        ])->save();

        $notifier->notify($run);
    }

    /** Resumen de la etapa de cartera con las exclusiones reales del adaptador. */
    private function patchPortfolioSummary(EfectividadCobranzaProgressTracker $tracker, array $coverage): void
    {
        $tracker->summarize(EfectividadCobranzaStage::PreparePortfolio, sprintf(
            '%s registros de cartera identificados y procesados (%s cerrados/inactivos, %s sin contrato).',
            number_format($coverage['cartera_rows_total'] ?? 0),
            number_format($coverage['cartera_rows_excluidas_cerrado_inactivo'] ?? 0),
            number_format($coverage['cartera_rows_sin_contrato'] ?? 0),
        ));
    }

    /** Si el bloqueo es por fechas, las columnas de fecha faltantes pasan a ser críticas. */
    private function promoteDateColumnIssues(array $issues): array
    {
        return array_map(function (array $issue) {
            if (in_array($issue['code'] ?? '', ['EC-COL-FALTANTE', 'EC-DATA-CAMPO-VACIO', 'EC-DATA-REPROCESAR', 'EC-COL-VARIANTE-INCOMPATIBLE'], true)
                && ($issue['source'] ?? null) === 'ingresos'
                && (in_array($issue['field'] ?? null, ['payment_date', 'installment_due_date'], true) || $issue['code'] === 'EC-COL-VARIANTE-INCOMPATIBLE')) {
                $issue['severity'] = EfectividadCobranzaIssue::SEVERITY_CRITICAL;
            }

            return $issue;
        }, $issues);
    }
}
