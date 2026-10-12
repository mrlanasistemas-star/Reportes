<?php

namespace App\Jobs;

use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\Efectividad\EfectividadCobranzaAprobadaExcelExporter;
use App\Services\Efectividad\EfectividadCobranzaAprobadaReportService;
use App\Services\Efectividad\EfectividadCobranzaSourceFingerprintService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Fase 10 (sesión anterior) — genera el reporte de Efectividad de Cobranza
 * Aprobada SIN bloquear HTTP. Reutiliza EfectividadCobranzaAprobadaReportService
 * (que ya reutiliza el calculador aprobado) y, si el resultado es válido,
 * exporta el Excel real con EfectividadCobranzaAprobadaExcelExporter (misma
 * clase que ya pasa sus propios tests de layout — no se reescribe nada de esa
 * exportación aquí).
 *
 * Auditoría 2026-10-12 (Fase 3/5/9):
 *  - `maxExceptions` ELIMINADO: antes era 1 con `$tries=2`, lo que anulaba el
 *    segundo intento por completo (maxExceptions corta los reintentos al
 *    llegar a esa cantidad de excepciones, sin importar $tries). Ahora $tries
 *    por sí solo gobierna los reintentos, como se esperaba.
 *  - memory_limit explícito (coherente con GenerateRadiographyJob) — antes
 *    este Job dependía del límite por defecto del worker.
 *  - status=blocked es un estado REAL y distinto de success (ver Fase 5):
 *    antes, cuando no había datos suficientes, se guardaba como "success"
 *    aunque NUNCA se generara un Excel.
 *  - Linaje de fuentes (`source_fingerprint`, uploads de Saldos/Ingresos de
 *    este periodo) para que la API pueda detectar snapshots obsoletos sin
 *    recalcular (ver EfectividadCobranzaSourceFingerprintService).
 */
class GenerateEfectividadCobranzaAprobadaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;
    public int $tries = 2;

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
        EfectividadCobranzaSourceFingerprintService $fingerprintService,
    ): void {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(600);

        $run = EfectividadCobranzaRun::query()->findOrFail($this->runId);
        $period = Period::query()->findOrFail($run->period_id);

        $lineage = $this->resolveLineage($period, $run->branch_id, $fingerprintService);

        $run->update([
            'status'     => EfectividadCobranzaRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?: now(),
            'log'        => 'Calculando Sano/Atraso/Vencido por cobrar y cobrado…',
            'metadata'   => ['progress_percent' => 10, 'current_step' => 'Calculando'],
            ...$lineage,
        ]);

        try {
            $result = $reportService->generate($period, $run->branch_id);

            if (!$result['is_available']) {
                // Fase 5 — bloqueado por datos insuficientes NUNCA es "success": no hay
                // Excel, no hay resultado utilizable, y la UI no debe ofrecer descarga.
                $run->update([
                    'status'        => EfectividadCobranzaRun::STATUS_BLOCKED,
                    'finished_at'   => now(),
                    'coverage'      => $result['coverage'],
                    'result_rows'   => [],
                    'log'           => $result['coverage']['message'],
                    'metadata'      => ['progress_percent' => 100, 'current_step' => 'Bloqueado — datos insuficientes', 'is_available' => false],
                ]);

                return;
            }

            $run->update([
                'coverage' => $result['coverage'],
                'log'      => 'Generando archivo Excel…',
                'metadata' => ['progress_percent' => 70, 'current_step' => 'Generando Excel'],
            ]);

            // Fase 10 (auditoría 2026-10-12) — disco PRIVADO ('local', storage/app/private,
            // no público), ruta RELATIVA guardada en DB. El controlador de descarga
            // reconstruye la ruta absoluta vía Storage::disk('local') en el servidor
            // donde corre — nunca asume el path Windows/Linux de quien lo generó.
            $scopeLabel = $run->branch_id ? "sucursal_{$run->branch_id}" : 'general';
            $relativePath = "efectividad_cobranza/efectividad_aprobada_{$period->id}_{$scopeLabel}_" . now()->format('Ymd_His') . '.xlsx';

            Storage::disk('local')->makeDirectory('efectividad_cobranza');
            $outputPath = Storage::disk('local')->path($relativePath);

            $exporter->export($result['rows'], $period->label ?? $period->code, $outputPath);

            if (!Storage::disk('local')->exists($relativePath) || Storage::disk('local')->size($relativePath) === 0) {
                throw new \RuntimeException('El archivo Excel generado está vacío o no existe.');
            }

            $run->update([
                'status'             => EfectividadCobranzaRun::STATUS_SUCCESS,
                'finished_at'        => now(),
                'coverage'           => $result['coverage'],
                'result_rows'        => $result['rows'],
                'output_excel_path'  => $relativePath,
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
                'status'        => EfectividadCobranzaRun::STATUS_FAILED,
                'finished_at'   => now(),
                'log'           => 'No se pudo generar el reporte: ' . $exception->getMessage(),
                'error_message' => $exception->getMessage(),
                'metadata'      => ['progress_percent' => 100, 'current_step' => 'Error'],
            ]);

            throw $exception;
        }
    }

    /**
     * Fase 9 — linaje de fuentes: el upload de Saldos por Cliente de ESTE
     * periodo/sucursal (snapshot único) y el de Ingresos Cobranza más
     * reciente de este periodo (referencia para pantalla/auditoría — el
     * histórico completo real usado por la macro abarca más uploads que
     * ese, por eso la invalidación real usa `source_fingerprint`, no estos
     * IDs). Nunca falla la generación si no encuentra un upload exacto —
     * son metadatos de trazabilidad, no un requisito para calcular.
     */
    private function resolveLineage(Period $period, ?int $branchId, EfectividadCobranzaSourceFingerprintService $fingerprintService): array
    {
        $saldosUpload = ReportUpload::query()
            ->where('period_id', $period->id)
            ->whereHas('dataSource', fn ($q) => $q->where('code', 'lendus_saldos_cliente'))
            ->latest('id')
            ->first();

        $ingresosUpload = ReportUpload::query()
            ->where('period_id', $period->id)
            ->whereHas('dataSource', fn ($q) => $q->where('code', 'lendus_ingresos_cobranza'))
            ->latest('id')
            ->first();

        return [
            'saldos_report_upload_id'   => $saldosUpload?->id,
            'ingresos_report_upload_id' => $ingresosUpload?->id,
            'source_fingerprint'        => $fingerprintService->compute($period, $branchId),
        ];
    }
}
