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

    private ?Collection $all = null;

    /** @return Collection<int,Period> */
    public function monthly(): Collection
    {
        return $this->all ??= Period::query()
            ->where('type', 'monthly')
            ->where('code', 'not like', 'TEST-%')
            ->orderBy('start_date')
            ->get();
    }

    public function previous(Period $period): ?Period
    {
        return $this->monthly()->filter(fn (Period $p) => $p->start_date->lt($period->start_date))->last();
    }

    /** Periodo del mismo mes de reporte (si hay varios, el de inicio más reciente). */
    public function byYearMonth(int $year, int $month): ?Period
    {
        return $this->monthly()->filter(fn (Period $p) => (int) $p->year === $year && (int) $p->month === $month)->last();
    }

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
                $m = 12;
                $y--;
            }
        }

        return $out;
    }

    /** Rejilla entre dos periodos (inclusive), máximo $max meses. @return list<array{year:int,month:int,period:?Period}> */
    public function range(Period $from, Period $to, int $max = 24): array
    {
        if ([(int) $from->year, (int) $from->month] > [(int) $to->year, (int) $to->month]) {
            [$from, $to] = [$to, $from];
        }
        $n = ((int) $to->year - (int) $from->year) * 12 + (int) $to->month - (int) $from->month + 1;
        if ($n > $max) {
            throw new \InvalidArgumentException('El rango máximo es de '.$max.' meses.');
        }
        $grid = $this->grid($to, $n);
        $grid[0]['period'] = $from;

        return $grid;
    }

    public static function monthLabel(int $year, int $month): string
    {
        return (self::MONTHS[$month] ?? (string) $month).' '.$year;
    }
}
