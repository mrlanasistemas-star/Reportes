<?php

namespace App\Console\Commands;

use App\Models\EstadoResultados\EstadoResultadosCredit;
use App\Models\Period;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\FinancialExpenses\AmortizationUploadService;
use App\Services\EstadoResultados\Import\EstadoResultadosImportException;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Carga desde consola las tablas de amortización de los créditos (mismo servicio que la
 * pestaña «Gastos financieros»: valida, guarda el archivo en disco privado y deja bitácora)
 * y, opcionalmente, reprocesa periodos para reclasificar movimientos (p. ej. DEPOSITO LEROM).
 *
 * Los archivos NO viven en el repositorio (son documentos de la empresa): se pasan por ruta.
 *
 *   php artisan estado-resultados:cargar-tablas-credito --lerom=/ruta/LEROM.csv --bbva=/ruta/BBVA.csv
 *   php artisan estado-resultados:cargar-tablas-credito --reprocesar=19 --reprocesar=20
 */
class CargarTablasCreditoCommand extends Command
{
    protected $signature = 'estado-resultados:cargar-tablas-credito
        {--lerom= : Ruta del CSV/XLSX de la tabla LEROM}
        {--bbva= : Ruta del CSV/XLSX de la tabla BBVA}
        {--simulacion : Marcar las tablas como simulación (por omisión: definitivo)}
        {--reprocesar=* : ID(s) de periodo a reprocesar después de cargar}';

    protected $description = 'Carga las tablas de amortización LEROM/BBVA y reprocesa periodos del Estado de Resultados';

    public function handle(AmortizationUploadService $uploads, EstadoResultadosProcessor $processor): int
    {
        $kind = $this->option('simulacion') ? 'simulacion' : 'definitivo';
        $ok = true;

        foreach (['lerom', 'bbva'] as $key) {
            $path = $this->option($key);
            if (! $path) {
                continue;
            }
            if (! is_file($path)) {
                $this->error("No existe el archivo {$path}.");
                $ok = false;

                continue;
            }
            $credit = EstadoResultadosCredit::query()->where('key', $key)->first();
            if (! $credit) {
                $this->error("No existe el crédito «{$key}». Ejecuta primero php artisan migrate.");
                $ok = false;

                continue;
            }
            try {
                $file = new UploadedFile($path, basename($path), null, null, true);
                $res = $uploads->upload($credit, $file, null, 'Cargada por consola', $kind);
                $u = $res['upload'];
                $this->info(sprintf('%s: %s (%d pagos del %s al %s, %s).', $credit->name,
                    $res['idempotent'] ? 'ya estaba cargada' : 'tabla cargada', $u->rows_count,
                    $u->first_payment_date?->format('d/m/Y'), $u->last_payment_date?->format('d/m/Y'), $kind));
            } catch (EstadoResultadosImportException $e) {
                $this->error($credit->name.': '.$e->getMessage());
                $ok = false;
            }
        }

        foreach ((array) $this->option('reprocesar') as $id) {
            $period = Period::query()->find((int) $id);
            if (! $period) {
                $this->error("No existe el periodo {$id}.");
                $ok = false;

                continue;
            }
            try {
                $processor->process($period);
                $this->info("Periodo {$id} reprocesado.");
            } catch (\Throwable $e) {
                $this->error("Periodo {$id}: ".$e->getMessage());
                $ok = false;
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
