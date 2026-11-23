<?php

namespace App\Services\EstadoResultados\Comparatives;

use App\Models\Period;
use Illuminate\Support\Collection;

/**
 * Ubica periodos MENSUALES del sistema (no calendario: p. ej. Junio 2026 = 2026-05-25..2026-06-21).
 * Se identifican por `year` + `month` (mes de reporte) y se ordenan por fecha de inicio.
 * Se excluyen los periodos técnicos de prueba (código TEST-*), igual que la pantalla del ER.
 */
class PeriodCalendar
{
    public const MONTHS = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    /** Periodos mensuales memorizados (una consulta por instancia). */
    private ?Collection $all = null;

    /**
     * Todos los periodos mensuales (sin TEST-*) ordenados por fecha de inicio; se consulta una vez.
     *
     * @return Collection<int,Period>
     */
    public function monthly(): Collection
    {
        return $this->all ??= Period::query()
            ->where('type', 'monthly')
            ->where('code', 'not like', 'TEST-%')
            ->orderBy('start_date')
            ->get();
    }

    /**
     * Periodo mensual inmediatamente anterior (el de inicio más reciente antes de $period).
     *
     * @param  Period  $period  periodo de referencia
     * @return Period|null null si no hay anterior
     */
    public function previous(Period $period): ?Period
    {
        return $this->monthly()->filter(fn (Period $p) => $p->start_date->lt($period->start_date))->last();
    }

    /** Periodo del mismo mes de reporte (si hay varios, el de inicio más reciente). */
    public function byYearMonth(int $year, int $month): ?Period
    {
        return $this->monthly()->filter(fn (Period $p) => (int) $p->year === $year && (int) $p->month === $month)->last();
    }

    /**
     * Periodo del mismo mes de reporte del año anterior.
     *
     * @param  Period  $period  periodo de referencia
     * @return Period|null null si no existe en el sistema
     */
    public function sameMonthPreviousYear(Period $period): ?Period
    {
        return $this->byYearMonth((int) $period->year - 1, (int) $period->month);
    }

    /**
     * Rejilla de meses (año, mes) que termina en $period y retrocede $months meses.
     *
     * @return list<array{year:int,month:int,period:?Period}>
     */
    public function grid(Period $period, int $months): array
    {
        $out = [];
        $y = (int) $period->year;
        $m = (int) $period->month;
        for ($i = 0; $i < $months; $i++) {
            array_unshift($out, ['year' => $y, 'month' => $m, 'period' => $i === 0 ? $period : $this->byYearMonth($y, $m)]);
            if (--$m === 0) {
                $y--;
                // Calendario de 13 periodos: el año anterior termina en P13 si existe.
                $m = $this->lastMonthOf($y);
            }
        }

        return $out;
    }

    /**
     * Rejilla entre dos periodos (inclusive), máximo $max meses. Si vienen invertidos se
     * intercambian.
     *
     * @param  Period  $from  periodo inicial
     * @param  Period  $to  periodo final
     * @param  int  $max  número máximo de meses permitido
     * @return list<array{year:int,month:int,period:?Period}>
     *
     * @throws \InvalidArgumentException si el rango excede $max meses
     */
    public function range(Period $from, Period $to, int $max = 24): array
    {
        if ([(int) $from->year, (int) $from->month] > [(int) $to->year, (int) $to->month]) {
            [$from, $to] = [$to, $from];
        }
        // Se cuentan los periodos recorriendo hacia atrás (respeta años con P13).
        $n = 1;
        [$y, $m] = [(int) $to->year, (int) $to->month];
        while ([$y, $m] !== [(int) $from->year, (int) $from->month]) {
            if (--$m === 0) {
                $y--;
                $m = $this->lastMonthOf($y);
            }
            if (++$n > $max) {
                throw new \InvalidArgumentException('El rango máximo es de '.$max.' meses.');
            }
        }
        $grid = $this->grid($to, $n);
        $grid[0]['period'] = $from;

        return $grid;
    }

    /**
     * Etiqueta «Mes Año» en español (p. ej. «Junio 2026»).
     *
     * @param  int  $year  año
     * @param  int  $month  mes 1–12
     */
    public static function monthLabel(int $year, int $month): string
    {
        return (self::MONTHS[$month] ?? sprintf('P%02d', $month)).' '.$year;
    }

    /**
     * Último número de periodo mensual del año (13 si tiene P13; si no, 12).
     *
     * @param  int  $year  Año.
     */
    private function lastMonthOf(int $year): int
    {
        return $this->monthly()->contains(fn (Period $p) => (int) $p->year === $year && (int) $p->month === 13) ? 13 : 12;
    }
}
