<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\EstadoResultados\Comparatives\ComparativesWarmer;
use Illuminate\Console\Command;

/**
 * Comando `er:precalentar-comparativas` — precalienta las comparativas del Estado de Resultados
 * (mismo cálculo que la pestaña, resultados en caché firmada) y las bases de Radiografía que
 * usan. Programado a diario a las 05:30 (`--ultimos=3`) antes de la jornada porque la firma
 * incluye la fecha del día: sin esto, la primera consulta de cada día sería en frío.
 * Excluye periodos con código TEST-%.
 *
 * Escribe en BD: NO (solo escribe en la caché).
 *
 * Ejemplos:
 *   php artisan er:precalentar-comparativas
 *   php artisan er:precalentar-comparativas --periodo=21 --periodo=24
 *   php artisan er:precalentar-comparativas --ultimos=6
 */
class WarmEstadoResultadosComparativesCommand extends Command
{
    protected $signature = 'er:precalentar-comparativas
                                {--periodo=* : id(s) de periodo; por defecto los más recientes}
                                {--ultimos=3 : cuántos periodos mensuales recientes calentar si no se indica --periodo}';

    protected $description = 'Estado de Resultados: deja listas en caché las comparativas (y las bases de Radiografía) de los periodos indicados';

    /**
     * Selecciona los periodos (explícitos o los N mensuales más recientes) y calienta cada uno,
     * reportando el tiempo; un error en un periodo no detiene a los demás.
     *
     * @param  ComparativesWarmer  $warmer  Servicio que calcula y guarda las comparativas en caché.
     * @return int Siempre self::SUCCESS.
     */
    public function handle(ComparativesWarmer $warmer): int
    {
        @ini_set('memory_limit', '1024M');
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('periodo'))));
        $query = Period::query()->where('type', 'monthly')->where('code', 'not like', 'TEST-%');
        $periods = $ids !== []
            ? $query->whereIn('id', $ids)->orderByDesc('start_date')->get()
            : $query->orderByDesc('start_date')->limit(max(1, (int) $this->option('ultimos')))->get();

        if ($periods->isEmpty()) {
            $this->warn('No hay periodos mensuales que calentar.');

            return self::SUCCESS;
        }
        foreach ($periods as $period) {
            try {
                $diag = $warmer->warm($period);
                $this->line(sprintf('%s (%d): %d ms · %s', $period->code, $period->id, $diag['ms'], json_encode($diag['periods'])));
            } catch (\Throwable $e) {
                $this->error($period->code.': '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
