<?php

namespace App\Jobs;

use App\Enums\EfectividadCobranzaStage;
use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use App\Models\PortfolioSnapshot;
use App\Services\Efectividad\EfectividadCobranzaCancelledException;
use App\Services\Efectividad\EfectividadCobranzaIssue;
use App\Services\Efectividad\EfectividadCobranzaProgressTracker;
use App\Services\Efectividad\EfectividadCobranzaReconciler;
use App\Services\Efectividad\EfectividadCobranzaRunNotifier;
use App\Services\Efectividad\V4\EfectividadV4Calculator;
use App\Services\Efectividad\V4\EfectividadV4DataAdapter;
use App\Services\Efectividad\V4\EfectividadV4ExcelExporter;
use App\Services\Efectividad\V4\EfectividadV4Exception;
use App\Services\Efectividad\V4\EfectividadV4FingerprintService;
use App\Services\Efectividad\V4\EfectividadV4Incidents;
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
 * Genera la Efectividad de Cobranza con el motor V4 (MR_LANA_EFECTIVIDAD.xlsm)
 * ÍNTEGRAMENTE en el worker de colas (nunca en la petición HTTP).
 *
 * Etapas reales persistidas (ver EfectividadCobranzaStage):
 *   1 fotografías + ingresos disponibles · 2 encabezados · 3 fotografías diarias
 *   (medible) · 4 ingresos (medible) · 5 cálculo V4 · 6 comparación de totales
 *   · 7 auditoría de incidencias · 8 Excel · 9 finalización.
 *
 * Garantías (sin cambios respecto a la infraestructura existente):
 *  - Idempotente: una ejecución en estado final nunca se recalcula.
 *  - Reintento honesto (`retrying`), `blocked` (datos) ≠ `failed` (técnico).
 *  - Notificaciones con estado propio; una falla SMTP no altera el resultado.
 *  - Guarda versión del motor y huella de las fuentes: si cambian fotografías o
 *    ingresos, el resultado queda obsoleto (nunca se presenta como vigente).
 *  - Nunca fabrica fotografías faltantes ni devuelve 0% por falta de datos.
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
        $key = $run
            ? "efectividad-cobranza-{$run->period_id}-".($run->branch_id ?? 'general').'-'.($run->mode ?? 'SEMANAL').'-'.($run->cutoff_date?->format('Ymd') ?? 'semana')
            : "efectividad-cobranza-run-{$this->runId}";

        return [(new WithoutOverlapping($key))->releaseAfter(60)->expireAfter($this->timeout + 60)];
    }

    public function handle(
        EfectividadV4DataAdapter $adapter,
        EfectividadV4Calculator $calculator,
        EfectividadV4ExcelExporter $exporter,
        EfectividadV4FingerprintService $fingerprints,
        EfectividadCobranzaReconciler $reconciler,
        EfectividadCobranzaRunNotifier $notifier,
    ): void {
        @ini_set('memory_limit', '1024M');
        @set_time_limit($this->timeout);

        $run = EfectividadCobranzaRun::query()->find($this->runId);

        if (! $run) {
            return;
        }

        if ($run->isTerminal()) {
            // Idempotencia: un Job duplicado/tardío nunca recalcula una ejecución cerrada.
            Log::info('Efectividad: Job omitido — la ejecución ya está en estado final.', ['run_id' => $run->id, 'status' => $run->status]);

            return;
        }

        // Cancelación solicitada antes de que el worker tomara el trabajo (p.ej.
        // seguía `queued`): se cierra de inmediato, sin ejecutar ningún paso.
        if ($run->isCancelRequested()) {
            $this->closeAsCancelled($run, new EfectividadCobranzaProgressTracker($run), $notifier);

            return;
        }

        $period = Period::query()->findOrFail($run->period_id);
        $mode = $run->mode ?: EfectividadCobranzaRun::MODE_SEMANAL;
        $cutoff = $run->cutoff_date?->format('Y-m-d');

        $run->forceFill([
            'status' => EfectividadCobranzaRun::STATUS_RUNNING,
            'engine_version' => EfectividadCobranzaRun::currentEngineVersion(),
            'mode' => $mode,
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
        $issues = [];

        try {
            // ── 1. Fotografías e ingresos disponibles ──────────────────────
            $tracker->start(EfectividadCobranzaStage::ValidateFiles);
            try {
                $disp = $adapter->availability($period, $run->branch_id, $mode, $cutoff);
            } catch (EfectividadV4Exception $e) {
                $this->closeAsBlocked($run, $tracker, $notifier, [$this->issueFromException($e, $period)], EfectividadCobranzaRun::FAILURE_INCOMPATIBLE, $e->getMessage());

                return;
            }
            $issues = $disp['issues'];
            $w = $disp['ventana'];

            $run->forceFill([
                'base_date' => $w['domingo_base'],
                'cutoff_date' => $w['fecha_corte'],
                'sources' => ['ventana' => $w, 'fotos' => $disp['fotos'], 'ingresos' => $disp['ingresos']],
                'source_fingerprint' => $fingerprints->compute($period, $run->branch_id, $mode, $w['fecha_corte']),
                'issues' => EfectividadCobranzaIssue::normalize($issues),
            ])->save();

            if ($disp['bloqueante']) {
                $critica = collect($issues)->firstWhere('severity', EfectividadCobranzaIssue::SEVERITY_CRITICAL);
                $this->closeAsBlocked($run, $tracker, $notifier, $issues, $this->failureKindFor($critica['category'] ?? null), $critica['message'] ?? 'Faltan fuentes indispensables.');

                return;
            }

            if ($disp['requiere_confirmacion'] && empty($run->metadata['confirmed_partial'])) {
                $issues[] = EfectividadCobranzaIssue::make(
                    'EC-V4-CONFIRMACION-REQUERIDA',
                    EfectividadCobranzaIssue::SEVERITY_CRITICAL,
                    EfectividadCobranzaIssue::CATEGORY_PARTIAL_COVERAGE,
                    'Los Ingresos del periodo parecen incompletos y no se confirmó que la semana esté completa (la calculadora V4 exige esa confirmación).',
                    'Revisa la disponibilidad de información y, si los datos son correctos, genera de nuevo confirmando la semana.',
                    ['source' => 'ingresos', 'period' => $period->label],
                );
                $this->closeAsBlocked($run, $tracker, $notifier, $issues, EfectividadCobranzaRun::FAILURE_PARTIAL_COVERAGE, 'Se requiere confirmar que los Ingresos cubren la semana completa.');

                return;
            }

            $tracker->complete(EfectividadCobranzaStage::ValidateFiles, sprintf(
                '%d de %d fotografías de cartera disponibles (%s a %s). Ingresos Cobranza: %s movimientos del %s al %s en %d día(s); histórico desde %s.',
                $disp['fotos_disponibles'], $disp['fotos_requeridas'],
                date('d/m/Y', strtotime($w['domingo_base'])), date('d/m/Y', strtotime($w['fecha_corte'])),
                number_format($disp['ingresos']['movimientos_corte']),
                date('d/m/Y', strtotime($w['inicio'])), date('d/m/Y', strtotime($w['fin'])),
                $disp['ingresos']['dias_con_movimientos'],
                $disp['ingresos']['historial_desde'] ? date('d/m/Y', strtotime($disp['ingresos']['historial_desde'])) : 'sin histórico previo',
            ));

            // ── 2. Encabezados de fotografías e ingresos ───────────────────
            $this->throwIfCancelRequested($run);
            $tracker->start(EfectividadCobranzaStage::VerifyColumns);
            $snapshotIds = collect($disp['fotos'])->flatMap(fn ($f) => collect($f['archivos'])->pluck('snapshot_id'))->all();
            $faltantes = [];
            foreach (PortfolioSnapshot::query()->whereIn('id', $snapshotIds)->get(['id', 'snapshot_date', 'headers']) as $s) {
                $falta = array_diff(EfectividadV4Calculator::COLUMNAS_FOTO_OBLIGATORIAS, $s->headers ?? []);
                if ($falta) {
                    $faltantes[] = $s->snapshot_date->format('d/m/Y').': '.implode(', ', $falta);
                }
            }
            if ($faltantes) {
                $issues[] = EfectividadCobranzaIssue::make('EC-V4-920', EfectividadCobranzaIssue::SEVERITY_CRITICAL, EfectividadCobranzaIssue::CATEGORY_MISSING_COLUMNS,
                    'Columnas obligatorias faltantes en fotografías: '.implode(' | ', $faltantes).'.',
                    'Carga el reporte Saldos por Cliente completo (156 columnas) de esas fechas.', ['source' => 'fotos']);
                $this->closeAsBlocked($run, $tracker, $notifier, $issues, EfectividadCobranzaRun::FAILURE_MISSING_COLUMNS, 'Faltan columnas obligatorias en las fotografías.');

                return;
            }
            $tracker->complete(EfectividadCobranzaStage::VerifyColumns, sprintf(
                'Encabezados obligatorios de la calculadora V4 presentes en %d archivo(s) de fotografía; Ingresos con Fecha transacción, Fecha cuota y Oficina.',
                count($snapshotIds),
            ));

            // ── 3-4. Fotografías diarias e ingresos (medibles) ─────────────
            $phase = null;
            $lastProcessed = 0;
            $onProgress = function (string $newPhase, int $processed, ?int $total) use ($run, $tracker, &$phase, &$lastProcessed): void {
                if ($newPhase !== $phase) {
                    if ($phase === 'cartera') {
                        $tracker->complete(EfectividadCobranzaStage::PreparePortfolio, null, [], $lastProcessed);
                    }
                    $tracker->start($newPhase === 'cartera' ? EfectividadCobranzaStage::PreparePortfolio : EfectividadCobranzaStage::ProcessIncome, $total);
                    $phase = $newPhase;
                }
                $lastProcessed = $processed;
                $tracker->advance($processed);
                // Punto de cancelación cooperativa: misma cadencia que la escritura de
                // avance (cada ~1000 registros) — nunca una consulta por fila.
                $this->throwIfCancelRequested($run);
            };

            $input = $adapter->build($period, $run->branch_id, $mode, $cutoff, $onProgress);
            $cob = $input['cobertura'];
            $tracker->summarize(EfectividadCobranzaStage::PreparePortfolio, sprintf(
                '%s registros leídos de %d fotografías diarias (%s).',
                number_format($cob['filas_fotos']), count($cob['fotos']),
                collect($cob['fotos'])->map(fn ($f) => date('d/m', strtotime($f['fecha'])).': '.number_format($f['filas']))->implode(' · '),
            ));
            $tracker->complete(EfectividadCobranzaStage::ProcessIncome, sprintf(
                '%s movimientos del corte; %s movimientos del historial con reconocimiento en el periodo.%s',
                number_format($cob['ingresos_corte']), number_format($cob['ingresos_historial']),
                $cob['fechas_con_cargas_solapadas'] ? ' '.count($cob['fechas_con_cargas_solapadas']).' fecha(s) estaban en más de una carga: se usó la más reciente.' : '',
            ), [], $lastProcessed);
            if ($cob['fechas_con_cargas_solapadas']) {
                $issues[] = EfectividadCobranzaIssue::make('EC-V4-INGRESOS-SOLAPADOS', EfectividadCobranzaIssue::SEVERITY_WARNING, EfectividadCobranzaIssue::CATEGORY_INCOMPATIBLE,
                    'Hay fechas de transacción presentes en más de un archivo de Ingresos: '.implode(', ', array_map(fn ($d) => date('d/m/Y', strtotime($d)), array_keys($cob['fechas_con_cargas_solapadas']))).'. Se usó el archivo más reciente para cada fecha (no se suman).',
                    'Verifica que los archivos de Ingresos cargados no se traslapen.', ['source' => 'ingresos']);
            }

            // ── 5. Cálculo V4 ───────────────────────────────────────────────
            $this->throwIfCancelRequested($run);
            $tracker->start(EfectividadCobranzaStage::Calculate);
            try {
                $result = $calculator->calculate($input['parametros'], $input['fotos'], $input['ingresos'], $input['historial']);
            } catch (EfectividadV4Exception $e) {
                $issues[] = $this->issueFromException($e, $period);
                $this->closeAsBlocked($run, $tracker, $notifier, $issues, EfectividadCobranzaRun::FAILURE_MISSING_DATA, $e->getMessage());

                return;
            }
            $tracker->complete(EfectividadCobranzaStage::Calculate, sprintf(
                '%d filas calculadas (promotores, totales por oficina y GRAN TOTAL); %s pagos clasificados.',
                count($result['filas']), number_format($result['estadisticas']['ingresos_clasificados'] ?? 0),
            ));

            // ── 6. Comparación de totales ──────────────────────────────────
            $tracker->start(EfectividadCobranzaStage::Reconcile);
            $reconciliation = $result['sin_movimientos']
                ? ['ok' => true, 'offices' => 0, 'promoters' => 0, 'differences' => [], 'summary' => 'Sin movimientos para el periodo: no hay totales que comparar.']
                : $reconciler->check($result['filas']);

            if (! $reconciliation['ok']) {
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
                'offices' => $reconciliation['offices'],
                'promoters' => $reconciliation['promoters'],
                'external' => false,
            ]);

            // ── 7. Auditoría de incidencias ────────────────────────────────
            $this->throwIfCancelRequested($run);
            $tracker->start(EfectividadCobranzaStage::Audit);
            $resumenIncidencias = EfectividadV4Incidents::persist($run, $result['incidencias']);
            $tracker->complete(EfectividadCobranzaStage::Audit, $result['incidencias']
                ? count($result['incidencias']).' incidencia(s) registradas: '.collect($resumenIncidencias)->map(fn ($r) => "{$r['cantidad']} {$r['codigo']}")->implode(', ').'. Ningún movimiento se forzó al cálculo.'
                : 'Sin incidencias de cálculo.', ['por_codigo' => $resumenIncidencias]);

            // ── 8. Excel ────────────────────────────────────────────────────
            $this->throwIfCancelRequested($run);
            $tracker->start(EfectividadCobranzaStage::BuildExcel);
            // Disco PRIVADO ('local'), ruta RELATIVA guardada en BD.
            $scopeLabel = $run->branch_id ? "sucursal_{$run->branch_id}" : 'general';
            $relativePath = "efectividad_cobranza/efectividad_v4_{$period->id}_{$scopeLabel}_".strtolower($mode).'_'.str_replace('-', '', $w['fecha_corte'])."_run{$run->id}_".now()->format('Ymd_His').'.xlsx';

            Storage::disk('local')->makeDirectory('efectividad_cobranza');
            $exporter->export($result['filas'], $result['parametros'], $result['incidencias'], Storage::disk('local')->path($relativePath), $cob, $result['engine_version']);

            if (! Storage::disk('local')->exists($relativePath) || Storage::disk('local')->size($relativePath) === 0) {
                throw new \RuntimeException('El archivo Excel generado está vacío o no existe.');
            }
            $tracker->complete(EfectividadCobranzaStage::BuildExcel, sprintf(
                'Archivo XLSX generado (%s KB): hoja EFECTIVIDAD, _AUDITORIA_EFECTIVIDAD y FUENTES.',
                number_format(Storage::disk('local')->size($relativePath) / 1024, 1),
            ));

            // ── 9. Finalización ─────────────────────────────────────────────
            $tracker->start(EfectividadCobranzaStage::Finish);
            $run->forceFill([
                'coverage' => $cob + ['estadisticas' => $result['estadisticas'], 'sin_movimientos' => $result['sin_movimientos']],
                'result_rows' => $result['filas'],
                'output_excel_path' => $relativePath,
                'incidents_count' => count($result['incidencias']),
                'issues' => EfectividadCobranzaIssue::normalize($issues),
                'metadata' => [...($run->metadata ?? []), 'is_available' => true, 'reconciliation' => $reconciliation,
                    'parametros' => $result['parametros'], 'incidencias_resumen' => $resumenIncidencias],
            ])->save();
            $tracker->complete(EfectividadCobranzaStage::Finish, EfectividadCobranzaStage::Finish->description());

            $run->forceFill([
                'status' => EfectividadCobranzaRun::STATUS_SUCCESS,
                'finished_at' => now(),
                'heartbeat_at' => now(),
                'log' => EfectividadCobranzaStage::Finish->description(),
            ])->save();
        } catch (EfectividadCobranzaCancelledException) {
            // Cancelación cooperativa: no es una falla, no se reintenta, no se publica nada parcial.
            $this->closeAsCancelled($run, $tracker, $notifier);

            return;
        } catch (\Throwable $exception) {
            $this->handleException($run, $tracker, $notifier, $issues, $exception, $period);

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

    /**
     * Cierra una ejecución cancelada por solicitud del usuario: nunca `failed`
     * (no fue un error) ni `success` (no se publica un resultado parcial). No
     * borra las incidencias/avance ya registrados — solo deja de avanzar.
     */
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

    /**
     * Llamado por el worker cuando el Job falla definitivamente (incluye
     * timeout y "máximo de intentos excedido", donde `handle()` nunca llega
     * a su `catch`). Cierra la ejecución como `failed` si seguía abierta.
     */
    public function failed(?\Throwable $exception): void
    {
        $run = EfectividadCobranzaRun::query()->find($this->runId);
        if (! $run) {
            return;
        }

        $notifier = app(EfectividadCobranzaRunNotifier::class);

        if ($run->isTerminal()) {
            $notifier->notify($run); // idempotente: solo completa lo que falte

            return;
        }

        // Se pidió cancelar mientras el Job estaba dentro de una llamada que no
        // pudo revisar el punto de cancelación (p.ej. timeout en el cálculo) y
        // terminó agotando sus reintentos: es una cancelación, no un error técnico.
        if ($run->isCancelRequested()) {
            $this->closeAsCancelled($run, new EfectividadCobranzaProgressTracker($run), $notifier);

            return;
        }

        $isTimeout = $exception instanceof TimeoutExceededException;
        $isMaxAttempts = $exception instanceof MaxAttemptsExceededException;

        Log::error('Efectividad: el Job terminó como fallido.', [
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
            'run_id' => $run->id,
            'period_id' => $period->id,
            'branch_id' => $run->branch_id,
            'stage' => $stage?->value,
            'attempt' => $this->attempts(),
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Se pidió cancelar y el error ocurrió antes de que el Job llegara a revisarlo
        // (p.ej. dentro de la llamada al calculador): se cierra como cancelada, nunca
        // se reintenta algo que el usuario ya pidió detener.
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
            'status' => EfectividadCobranzaRun::STATUS_FAILED,
            'finished_at' => now(),
            'heartbeat_at' => now(),
            'failure_kind' => $failureKind,
            'error_message' => $message,
            'log' => $message,
            'issues' => EfectividadCobranzaIssue::normalize($issues),
            'metadata' => [...($run->metadata ?? []), 'last_error_reference' => $reference ?? ($run->metadata['last_error_reference'] ?? null)],
        ])->save();

        $notifier->notify($run);
    }

    /** Bloqueo por datos: nunca es success ni failed técnico, y nunca muestra 0%. */
    private function closeAsBlocked(EfectividadCobranzaRun $run, EfectividadCobranzaProgressTracker $tracker, EfectividadCobranzaRunNotifier $notifier, array $issues, string $failureKind, string $message): void
    {
        $tracker->halt('blocked', $message);
        $run->forceFill([
            'status' => EfectividadCobranzaRun::STATUS_BLOCKED,
            'finished_at' => now(),
            'heartbeat_at' => now(),
            'result_rows' => [],
            'issues' => EfectividadCobranzaIssue::normalize($issues),
            'failure_kind' => $failureKind,
            'log' => 'No se pudo generar el reporte porque faltan datos obligatorios.',
            'metadata' => [...($run->metadata ?? []), 'is_available' => false],
        ])->save();

        $notifier->notify($run);
    }

    private function issueFromException(EfectividadV4Exception $e, Period $period): array
    {
        return EfectividadCobranzaIssue::make(
            $e->codigo,
            EfectividadCobranzaIssue::SEVERITY_CRITICAL,
            $e->codigo === 'EC-V4-PERIODO' ? EfectividadCobranzaIssue::CATEGORY_INCOMPATIBLE : EfectividadCobranzaIssue::CATEGORY_MISSING_DATA,
            $e->getMessage(),
            $e->codigo === 'EC-V4-PERIODO'
                ? 'Selecciona una semana operativa (lunes a domingo).'
                : 'Revisa la disponibilidad de información del periodo y carga las fuentes faltantes.',
            ['period' => $period->label, 'sheet' => $e->contexto['foto'] ?? null],
        );
    }

    private function failureKindFor(?string $category): string
    {
        return match ($category) {
            EfectividadCobranzaIssue::CATEGORY_MISSING_COLUMNS => EfectividadCobranzaRun::FAILURE_MISSING_COLUMNS,
            EfectividadCobranzaIssue::CATEGORY_PARTIAL_COVERAGE => EfectividadCobranzaRun::FAILURE_PARTIAL_COVERAGE,
            EfectividadCobranzaIssue::CATEGORY_INCOMPATIBLE => EfectividadCobranzaRun::FAILURE_INCOMPATIBLE,
            EfectividadCobranzaIssue::CATEGORY_STALE => 'stale',
            default => EfectividadCobranzaRun::FAILURE_MISSING_DATA,
        };
    }
}
