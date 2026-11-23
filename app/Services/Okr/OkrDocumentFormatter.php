<?php

namespace App\Services\Okr;

use Illuminate\Support\Carbon;

/**
 * Formato de presentación para los documentos OKR (Carta Compromiso y
 * Warning) — 14-oct-2026. Solo PRESENTA: nunca altera el valor almacenado.
 * Moneda mexicana: "$1,234,567.89", negativos "-$1,234.00". Misma regla que
 * resources/js/lib/okrFormat.ts en la interfaz.
 */
class OkrDocumentFormatter
{
    /** Nombres de meses en español (índice 1..12) para fechas largas. */
    private const MONTHS = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /**
     * Formatea un importe como moneda mexicana ("$1,234.56", negativos "-$1,234.56").
     *
     * @param  float|int|string|null  $value  Importe; no numérico o vacío → "—".
     * @return string Texto formateado.
     */
    public static function money(float|int|string|null $value): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return '—';
        }
        $n = (float) $value;

        return ($n < 0 ? '-' : '').'$'.number_format(abs($n), 2, '.', ',');
    }

    /**
     * Moneda con signo explícito "+" para positivos (los negativos ya llevan "-").
     *
     * @param  float|int|string|null  $value  Importe; no numérico o vacío → "—".
     * @return string Texto formateado.
     */
    public static function signedMoney(float|int|string|null $value): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return '—';
        }

        return ((float) $value > 0 ? '+' : '').self::money($value);
    }

    /**
     * Formatea un porcentaje ("12.34%").
     *
     * @param  float|int|string|null  $value  Valor ya expresado en porcentaje; no numérico → "—".
     * @param  int  $digits  Decimales a mostrar.
     * @return string Texto formateado.
     */
    public static function percentage(float|int|string|null $value, int $digits = 2): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return '—';
        }

        return number_format((float) $value, $digits, '.', ',').'%';
    }

    /**
     * Formatea según la unidad del KPI (currency|percentage|integer|decimal).
     *
     * @param  float|int|string|null  $value  Valor a formatear; no numérico → "—".
     * @param  string|null  $unit  Unidad del KPI; desconocida/null → decimal con 2 cifras.
     * @return string Texto formateado.
     */
    public static function byUnit(float|int|string|null $value, ?string $unit): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return '—';
        }

        return match ($unit) {
            'currency' => self::money($value),
            'percentage' => self::percentage($value),
            'integer' => number_format((float) $value, 0, '.', ','),
            default => number_format((float) $value, 2, '.', ','),
        };
    }

    /**
     * Incremento con signo y su unidad (pp para porcentuales).
     *
     * @param  float|int|string|null  $value  Incremento; no numérico → "—".
     * @param  string|null  $unit  Unidad del KPI.
     * @return string Texto formateado con "+" en positivos.
     */
    public static function incrementByUnit(float|int|string|null $value, ?string $unit): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return '—';
        }
        $sign = (float) $value > 0 ? '+' : '';

        return match ($unit) {
            'currency' => self::signedMoney($value),
            'percentage' => $sign.number_format((float) $value, 2, '.', ',').' pp',
            default => $sign.self::byUnit($value, $unit),
        };
    }

    /**
     * Fecha larga en español ("14 de octubre de 2026").
     *
     * @param  Carbon|string|null  $date  Fecha; vacía → cadena vacía.
     * @return string Texto formateado.
     */
    public static function dateLong(Carbon|string|null $date): string
    {
        if (! $date) {
            return '';
        }
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $d->day.' de '.self::MONTHS[(int) $d->month].' de '.$d->year;
    }

    /**
     * Fecha corta dd/mm/aaaa.
     *
     * @param  Carbon|string|null  $date  Fecha; vacía → cadena vacía.
     * @return string Texto formateado.
     */
    public static function dateShort(Carbon|string|null $date): string
    {
        if (! $date) {
            return '';
        }
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);

        return sprintf('%02d/%02d/%04d', $d->day, $d->month, $d->year);
    }
}
