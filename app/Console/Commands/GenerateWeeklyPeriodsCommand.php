<?php

namespace App\Console\Commands;

use App\Services\WeeklyPeriodGeneratorService;
use Illuminate\Console\Command;

/**
 * Comando `periods:generate-weekly` — genera (o actualiza) los periodos semanales de un mes, o
 * de los 12 meses si se omite el mes, con la regla: del día 1 al primer domingo y luego semanas
 * lunes-domingo (WeeklyPeriodGeneratorService).
 *
 * Escribe en BD: SÍ (`periods` de tipo weekly; idempotente).
 *
 * Ejemplos:
 *   php artisan periods:generate-weekly 2026
 *   php artisan periods:generate-weekly 2026 11
 */
class GenerateWeeklyPeriodsCommand extends Command
{
    protected $signature = 'periods:generate-weekly {year : Año del periodo} {month? : Mes del periodo (1-12)}';

    protected $description = 'Genera periodos semanales para un mes con regla: día 1 al primer domingo, luego lunes-domingo.';

    /**
     * Valida año (2020–2100) y mes (1–12) y genera las semanas de cada mes indicado.
     *
     * @param  WeeklyPeriodGeneratorService  $service  Generador de periodos semanales.
     * @return int SUCCESS al terminar; INVALID si el año o el mes están fuera de rango.
     */
    public function handle(WeeklyPeriodGeneratorService $service): int
    {
        $year = (int) $this->argument('year');
        $monthArgument = $this->argument('month');

        if ($year < 2020 || $year > 2100) {
            $this->error('El año debe estar entre 2020 y 2100.');

            return self::INVALID;
        }

        $months = $monthArgument === null
            ? range(1, 12)
            : [(int) $monthArgument];

        foreach ($months as $month) {
            if ($month < 1 || $month > 12) {
                $this->error('El mes debe estar entre 1 y 12.');

                return self::INVALID;
            }

            $periods = $service->generateForMonth($year, $month);

            $this->line(sprintf('Mes %04d-%02d: %d periodos semanales generados/actualizados.', $year, $month, $periods->count()));
        }

        $this->info('Proceso finalizado.');

        return self::SUCCESS;
    }
}
