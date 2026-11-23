<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\PeriodDerivedDataCleaner;
use App\Services\ReportAnalysisService;
use Illuminate\Console\Command;

/**
 * Comando `reportes:reimport` — reprocesa uno o más archivos subidos regenerando sus registros
 * en las tablas fact_*. Con `--upload=ID` reprocesa ese archivo; con `--period=ID` reprocesa
 * todos los del periodo (limpiando primero todo el periodo si no hay `--source`), y `--source=`
 * filtra por nombre parcial de fuente (en ese caso la limpieza es por archivo).
 *
 * Escribe en BD: SÍ (borra y reinserta filas fact_* de los uploads procesados).
 *
 * Ejemplos:
 *   php artisan reportes:reimport --upload=345
 *   php artisan reportes:reimport --period=21 --source=cobranza
 */
class ReimportUploadsCommand extends Command
{
    protected $signature = 'reportes:reimport
        {--period= : ID del periodo a reprocesar}
        {--upload= : ID específico de un ReportUpload}
        {--source= : Filtrar por nombre de fuente (parcial, e.g. saldo, ministracion, cobranza, gastos, noi)}';

    protected $description = 'Reprocesa uno o más archivos subidos, regenerando todos los registros en las tablas fact_*.';

    /**
     * @param  ReportAnalysisService  $analysisService  Importa (analiza) cada archivo subido.
     * @param  PeriodDerivedDataCleaner  $cleaner  Limpia datos derivados por periodo o por upload.
     */
    public function __construct(
        private readonly ReportAnalysisService $analysisService,
        private readonly PeriodDerivedDataCleaner $cleaner,
    ) {
        parent::__construct();
    }

    /**
     * Despacha el reproceso de un upload o de los del periodo (con filtro opcional de fuente).
     *
     * @return int SUCCESS si todo se procesó; FAILURE si falta el upload/periodo, no se pasó ninguna opción o algún archivo falló.
     */
    public function handle(): int
    {
        $periodId = $this->option('period');
        $uploadId = $this->option('upload');
        $source = $this->option('source');

        if ($uploadId) {
            $upload = ReportUpload::query()->with('dataSource')->find((int) $uploadId);
            if (! $upload) {
                $this->error("ReportUpload #{$uploadId} no encontrado.");

                return self::FAILURE;
            }

            return $this->processUpload($upload);
        }

        if ($periodId) {
            $period = Period::query()->find((int) $periodId);
            if (! $period) {
                $this->error("Periodo #{$periodId} no encontrado.");

                return self::FAILURE;
            }

            $uploads = ReportUpload::query()
                ->with('dataSource')
                ->where('period_id', $period->id)
                ->get();

            if ($uploads->isEmpty()) {
                $this->warn("No hay archivos subidos para el periodo #{$periodId}.");

                return self::SUCCESS;
            }

            if ($source) {
                $uploads = $uploads->filter(
                    fn ($u) => str_contains(strtolower($u->dataSource?->name ?? ''), strtolower($source))
                );
            }

            if ($uploads->isEmpty()) {
                $this->warn("Ningún archivo coincide con --source={$source} en periodo #{$periodId}.");

                return self::SUCCESS;
            }

            // Full-period clear when no source filter; per-upload clear handled inside processUpload
            if (! $source) {
                $this->line("  Limpiando datos derivados del periodo #{$period->id}…");
                $this->cleaner->clearForPeriod($period);
            }

            $this->info('Reprocesando '.$uploads->count()." archivo(s) del periodo #{$periodId} — {$period->label}");
            $this->line('');

            $allOk = true;
            foreach ($uploads as $upload) {
                if ($this->processUpload($upload) !== self::SUCCESS) {
                    $allOk = false;
                }
                $this->line('');
            }

            return $allOk ? self::SUCCESS : self::FAILURE;
        }

        $this->error('Debes especificar --period=ID o --upload=ID');

        return self::FAILURE;
    }

    /**
     * Limpia los datos derivados del upload y lo vuelve a importar, imprimiendo el resultado.
     *
     * @param  ReportUpload  $upload  Archivo a reprocesar.
     * @return int SUCCESS si se importó; FAILURE si lanzó una excepción.
     */
    private function processUpload(ReportUpload $upload): int
    {
        $sourceName = $upload->dataSource?->name ?? 'Desconocida';
        $this->info("▶ Upload #{$upload->id} — {$sourceName} (periodo {$upload->period_id})");

        try {
            $this->cleaner->clearForUpload($upload);
            $run = $this->analysisService->analyze($upload);
            $this->line("  ✓ Completado: {$run->log}");
            $this->line("  Leídas: {$run->rows_read} | Insertadas: {$run->rows_inserted} | Omitidas: {$run->rows_skipped} | Errores: {$run->rows_with_errors}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('  ✗ Error: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
