<?php

namespace App\Services;

use App\Models\Period;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Genera los periodos semanales (type = 'weekly') de un mes calendario.
 *
 * Regla de corte: la semana 1 va del día 1 al primer domingo; las siguientes son
 * bloques de 7 días; la última se recorta al último día del mes. Se usa al dar de
 * alta periodos para que las cargas y reportes semanales tengan su contenedor.
 */
class WeeklyPeriodGeneratorService
{
    /**
     * Crea o actualiza (updateOrCreate por type/year/month/sequence) las semanas del mes.
     *
     * Efectos en BD: escribe en `periods` el nombre ("Semana N - Mes AAAA"), el código
     * W-AAAA-MM-NN, las fechas de inicio/fin y deja is_closed = false (también reabre
     * una semana existente que estuviera cerrada).
     *
     * @param  int  $year  Año del mes a generar.
     * @param  int  $month  Mes (1-12).
     * @return Collection<int, Period> Semanas del mes en orden de secuencia.
     */
    public function generateForMonth(int $year, int $month): Collection
    {
        $cursor = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $cursor->copy()->endOfMonth()->startOfDay();

        $sequence = 1;
        $periods = collect();

        while ($cursor->lte($monthEnd)) {
            $start = $cursor->copy();

            $end = $sequence === 1
                ? $start->copy()->endOfWeek(Carbon::SUNDAY)
                : $start->copy()->addDays(6);

            if ($end->gt($monthEnd)) {
                $end = $monthEnd->copy();
            }

            $name = sprintf('Semana %d - %s %d', $sequence, $start->translatedFormat('F'), $year);
            $code = sprintf('W-%04d-%02d-%02d', $year, $month, $sequence);

            $period = Period::query()->updateOrCreate(
                [
                    'type' => 'weekly',
                    'year' => $year,
                    'month' => $month,
                    'sequence' => $sequence,
                ],
                [
                    'name' => ucfirst($name),
                    'code' => $code,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'is_closed' => false,
                ],
            );

            $periods->push($period);

            $cursor = $end->copy()->addDay()->startOfDay();
            $sequence++;
        }

        return $periods;
    }
}
