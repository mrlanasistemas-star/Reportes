<?php

namespace App\Jobs;

use App\Mail\ReprocessPeriodCompletedMail;
use App\Mail\ReprocessPeriodFailedMail;
use App\Models\Period;
use App\Models\PeriodReprocessRun;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\PeriodDerivedDataCleaner;
use App\Services\ReportAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Job en cola que REPROCESA todas las fuentes de un periodo (botón
 * "Reprocesar periodo" en Carga de archivos, ReportUploadController).
 *
 * Flujo:
 * 1. Marca el PeriodReprocessRun como `running`.
 * 2. Borra TODOS los datos derivados del periodo (PeriodDerivedDataCleaner::clearForPeriod)
 *    para que el reproceso sea idempotente.
 * 3. Toma los uploads cuyo covered_period_ids se cruza con los periodos indicados,
 *    los ordena en el orden canónico de fuentes (NOI → Lendus → gastos) y los
 *    importa uno por uno con ReportAnalysisService, actualizando progreso en metadata.
 *    El error de una fuente no detiene a las demás.
 * 4. Cierra el run como `success` o `failed` y envía correo al usuario.
 * Un solo intento, timeout de 1 h.
 */
class ReprocessPeriodUploadsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $maxExceptions = 1;

    // Porcentaje de avance que se alcanza al terminar cada fuente (por código)
    private const SOURCE_PROGRESS = [
        'noi_nomina' => 15,
        'lendus_ministraciones' => 30,
        'lendus_ingresos_cobranza' => 45,
        'lendus_saldos_cliente' => 60,
        'gastos_lendus' => 75,
        'gastos_erp' => 88,
        'gastos' => 88, // respaldo heredado
    ];

    /**
     * @param  int  $periodId  Periodo que se reprocesa.
     * @param  int  $runId  Id del PeriodReprocessRun que refleja el avance en pantalla.
     * @param  array  $coveredPeriodIds  Ids de periodos cuyos uploads se reprocesan (se cruzan con covered_period_ids de cada upload).
     * @param  int|null  $userId  Usuario que lo solicitó (destinatario del correo).
     */
    public function __construct(
        public int $periodId,
        public int $runId,
        public array $coveredPeriodIds,
        public ?int $userId = null,
    ) {}

    /**
     * Efectos: borra y vuelve a insertar los hechos del periodo, actualiza
     * period_reprocess_runs (status, log, metadata con progreso y resultados por
     * fuente) y encola ReprocessPeriodCompletedMail / ReprocessPeriodFailedMail.
     *
     * Lanza ModelNotFoundException (findOrFail) si el periodo o el run no existen.
     *
     * @param  ReportAnalysisService  $service  Servicio de importación.
     * @param  PeriodDerivedDataCleaner  $cleaner  Limpia los datos derivados del periodo.
     *
     * @throws \Throwable Relanza un error inesperado (fuera del ciclo por fuente) tras marcar el run como fallido.
     */
    public function handle(ReportAnalysisService $service, PeriodDerivedDataCleaner $cleaner): void
    {
        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '3600');
        @set_time_limit(3600);

        $period = Period::query()->findOrFail($this->periodId);
        $run = PeriodReprocessRun::query()->findOrFail($this->runId);
        $user = $this->userId ? User::query()->find($this->userId) : null;

        $run->update(['status' => 'running', 'started_at' => now(), 'log' => 'Limpiando datos previos del periodo…']);

        // Borra TODOS los datos derivados del periodo antes de insertar (idempotencia)
        $cleaner->clearForPeriod($period);

        try {
            $uploads = ReportUpload::query()
                ->with('dataSource')
                ->get()
                ->filter(fn (ReportUpload $u) => collect($u->covered_period_ids ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->intersect($this->coveredPeriodIds)
                    ->isNotEmpty()
                )
                ->unique('id')
                ->values();

            $total = $uploads->count();
            $metadata = ['progress' => 10, 'total' => $total, 'completed' => 0];

            $run->update([
                'log' => "Iniciando reprocesamiento de {$total} fuente(s)...",
                'metadata' => $metadata,
            ]);

            // Ordena por el orden canónico de fuentes para que los hitos de avance tengan sentido
            $sortOrder = array_keys(self::SOURCE_PROGRESS);
            $sorted = $uploads->sortBy(
                fn (ReportUpload $u) => ($pos = array_search($u->dataSource?->code ?? '', $sortOrder)) !== false ? $pos : 99
            )->values();

            $results = [];
            $errors = [];
            $completed = 0;

            foreach ($sorted as $upload) {
                $sourceCode = $upload->dataSource?->code ?? '';
                $sourceName = $upload->dataSource?->name ?? 'Fuente desconocida';

                $metadata['current_source'] = $sourceName;
                $run->update(['log' => "Procesando {$sourceName}...", 'metadata' => $metadata]);

                try {
                    $processRun = $service->analyze($upload);

                    $results[$sourceCode] = [
                        'ok' => true,
                        'name' => $sourceName,
                        'inserted' => $processRun->rows_inserted ?? 0,
                        'read' => $processRun->rows_read ?? 0,
                    ];
                } catch (\Throwable $e) {
                    $results[$sourceCode] = [
                        'ok' => false,
                        'name' => $sourceName,
                        'error' => mb_strimwidth($e->getMessage(), 0, 200),
                    ];
                    $errors[] = "{$sourceName}: ".mb_strimwidth($e->getMessage(), 0, 120);

                    Log::warning('ReprocessPeriodUploadsJob source error', [
                        'upload_id' => $upload->id,
                        'source_code' => $sourceCode,
                        'error' => $e->getMessage(),
                    ]);
                }

                $completed++;
                $metadata['completed'] = $completed;
                $metadata['progress'] = self::SOURCE_PROGRESS[$sourceCode] ?? (int) (10 + ($completed / max($total, 1)) * 80);
                $run->update(['metadata' => $metadata]);
            }

            $hasErrors = ! empty($errors);
            $finalLog = $hasErrors
                ? 'Reprocesamiento terminado con '.count($errors).' error(s). '.implode('; ', $errors)
                : "Reprocesamiento completado. {$total} fuente(s) procesadas correctamente.";

            $metadata['progress'] = 100;
            $metadata['results'] = $results;

            $run->update([
                'status' => $hasErrors ? 'failed' : 'success',
                'log' => $finalLog,
                'metadata' => $metadata,
                'finished_at' => now(),
            ]);

            if ($user?->email) {
                $mail = $hasErrors
                    ? new ReprocessPeriodFailedMail($period, $user, $run, $results)
                    : new ReprocessPeriodCompletedMail($period, $user, $run, $results);
                Mail::to($user->email)->queue($mail);
            }
        } catch (\Throwable $e) {
            Log::error('ReprocessPeriodUploadsJob failed unexpectedly', [
                'period_id' => $this->periodId,
                'run_id' => $this->runId,
                'error' => $e->getMessage(),
            ]);

            $run->update([
                'status' => 'failed',
                'log' => 'El reprocesamiento falló de forma inesperada.',
                'error_message' => mb_strimwidth($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ]);

            if ($user?->email) {
                Mail::to($user->email)->queue(new ReprocessPeriodFailedMail($period, $user, $run, []));
            }

            throw $e;
        }
    }
}
