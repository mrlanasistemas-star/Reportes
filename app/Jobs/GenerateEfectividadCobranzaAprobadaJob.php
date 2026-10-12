<?php

namespace App\Jobs;

use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use App\Services\Efectividad\EfectividadCobranzaAprobadaExcelExporter;
use App\Services\Efectividad\EfectividadCobranzaAprobadaReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Fase 10 — genera el reporte de Efectividad de Cobranza Aprobada SIN bloquear
 * HTTP. Reutiliza EfectividadCobranzaAprobadaReportService (que ya reutiliza el
 * calculador aprobado) y, si el resultado es válido, exporta el Excel real con
 * EfectividadCobranzaAprobadaExcelExporter (misma clase que ya pasa sus propios
 * tests de layout — no se reescribe nada de esa exportación aquí).
 */
class GenerateEfectividadCobranzaAprobadaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;
    public int $tries = 2;
    public int $maxExceptions = 1;

    public function __construct(
        public int $runId,
    ) {}

    /** Evita que dos ejecuciones concurrentes de la MISMA identidad se pisen. */
    public function middleware(): array
    {
        $run = EfectividadCobranzaRun::query()->find($this->runId);
        $key = $run ? "efectividad-cobranza-{$run->period_id}-" . ($run->branch_id ?? 'general') : "efectividad-cobranza-run-{$this->runId}";

        return [(new WithoutOverlapping($key))->expireAfter(600)];
    }

    public function handle(
        EfectividadCobranzaAprobadaReportService $reportService,
        EfectividadCobranzaAprobadaExcelExporter $exporter,
    ): void {
        @set_time_limit(600);

        $run = EfectividadCobranzaRun::query()->findOrFail($this->runId);
        $period = Period::query()->findOrFail($run->period_id);

        $run->update([
            'status'     => 'running',
            'started_at' => $run->started_at ?: now(),
            'log'        => 'Calculando Sano/Atraso/Vencido por cobrar y cobrado…',
            'metadata'   => ['progress_percent' => 10, 'current_step' => 'Calculando'],
        ]);

        try {
            $result = $reportService->generate($period, $run->branch_id);

            if (!$result['is_available']) {
                $run->update([
                    'status'        => 'success',
                    'finished_at'   => now(),
                    'coverage'      => $result['coverage'],
                    'result_rows'   => [],
                    'log'           => $result['coverage']['message'],
                    'metadata'      => ['progress_percent' => 100, 'current_step' => 'Sin datos suficientes', 'is_available' => false],
                ]);

                return;
            }

            $run->update([
                'coverage' => $result['coverage'],
                'log'      => 'Generando archivo Excel…',
                'metadata' => ['progress_percent' => 70, 'current_step' => 'Generando Excel'],
            ]);

            $directory = storage_path('app/efectividad_cobranza');
            File::ensureDirectoryExists($directory);
            $scopeLabel = $run->branch_id ? "sucursal_{$run->branch_id}" : 'general';
            $outputPath = $directory . "/efectividad_aprobada_{$period->id}_{$scopeLabel}_" . now()->format('Ymd_His') . '.xlsx';

            $exporter->export($result['rows'], $period->label ?? $period->code, $outputPath);

            if (!file_exists($outputPath) || filesize($outputPath) === 0) {
                throw new \RuntimeException('El archivo Excel generado está vacío o no existe.');
            }

            $run->update([
                'status'             => 'success',
                'finished_at'        => now(),
                'coverage'           => $result['coverage'],
                'result_rows'        => $result['rows'],
                'output_excel_path'  => $outputPath,
                'log'                => 'Efectividad de Cobranza Aprobada generada correctamente.',
                'metadata'           => ['progress_percent' => 100, 'current_step' => 'Completado', 'is_available' => true],
            ]);
        } catch (\Throwable $exception) {
            Log::error('GenerateEfectividadCobranzaAprobadaJob falló.', [
                'run_id'    => $run->id,
                'period_id' => $period->id,
                'branch_id' => $run->branch_id,
                'exception' => get_class($exception),
                'message'   => $exception->getMessage(),
            ]);

            $run->update([
                'status'        => 'failed',
                'finished_at'   => now(),
                'log'           => 'No se pudo generar el reporte: ' . $exception->getMessage(),
                'error_message' => $exception->getMessage(),
                'metadata'      => ['progress_percent' => 100, 'current_step' => 'Error'],
            ]);

            throw $exception;
        }
    }
}
