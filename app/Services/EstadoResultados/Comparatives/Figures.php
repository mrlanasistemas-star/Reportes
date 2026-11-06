<?php

namespace App\Services\EstadoResultados\Comparatives;

use App\Services\EstadoResultados\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Aritmética de comparación (BigDecimal). Importes en cadena con 2 decimales,
 * porcentajes en cadena con 2 decimales, y null cuando no hay dato o la base es 0.
 *
 *   variación $  = actual − anterior
 *   variación %  = (actual − anterior) / |anterior| × 100   (|anterior| para que el signo indique dirección
 *                  aun cuando la base es negativa, p. ej. un EBITDA que pasa de −100 a −50 = +50 %)
 *   margen       = importe / ingresos × 100
 *   variación de un margen = puntos porcentuales (actual − anterior); nunca "% de un %".
 */
final class Figures
{
    public const REASONS = [
        'sin_datos_actual' => 'El periodo actual no tiene datos.',
        'sin_datos_anterior' => 'El periodo de comparación no tiene datos.',
        'pendiente_actual' => 'El concepto está pendiente (sin información) en el periodo actual.',
        'pendiente_anterior' => 'El concepto está pendiente (sin información) en el periodo de comparación.',
        'base_cero' => 'El periodo de comparación vale $0.00: no se calcula variación porcentual.',
        'no_comparable' => 'Los periodos no son comparables: se omiten las conclusiones porcentuales.',
        'incompleto' => 'El concepto está incompleto (ajustes sin información) en alguno de los periodos.',
        'margen_en_puntos' => 'Los márgenes se comparan en puntos porcentuales, no en %.',
        'base_ingresos_cero' => 'Los ingresos operativos son $0.00: no se calcula el margen.',
        'base_no_positiva' => 'El total de referencia es cero o negativo: la participación no es interpretable.',
        'sin_consolidado' => 'Tu alcance no incluye el consolidado: no se calcula la participación.',
    ];

    public static function amount(mixed $v): ?string
    {
        return $v === null ? null : Money::str2($v);
    }

    public static function sub(?string $a, ?string $b): ?string
    {
        return $a === null || $b === null ? null : Money::str2(Money::of($a)->minus(Money::of($b)));
    }

    /** @param list<?string> $values null si cualquiera es null */
    public static function sum(array $values): ?string
    {
        $t = BigDecimal::zero();
        foreach ($values as $v) {
            if ($v === null) {
                return null;
            }
            $t = $t->plus(Money::of($v));
        }

        return Money::str2($t);
    }

    /** part / base × 100 a 2 decimales; null si falta un valor o la base es 0. */
    public static function pct(?string $part, ?string $base): ?string
    {
        if ($part === null || $base === null || Money::of($base)->isZero()) {
            return null;
        }

        return (string) Money::of($part)->multipliedBy(100)->dividedBy(Money::of($base), 2, RoundingMode::HALF_UP);
    }

    /** (actual − anterior) / |anterior| × 100; null si falta o la base es 0. */
    public static function variationPct(?string $current, ?string $previous): ?string
    {
        if ($current === null || $previous === null || Money::of($previous)->isZero()) {
            return null;
        }

        return (string) Money::of($current)->minus(Money::of($previous))->multipliedBy(100)
            ->dividedBy(Money::of($previous)->abs(), 2, RoundingMode::HALF_UP);
    }

    /** Puntos porcentuales entre dos márgenes (cadenas con 2 decimales). */
    public static function points(?string $current, ?string $previous): ?string
    {
        if ($current === null || $previous === null) {
            return null;
        }

        return (string) BigDecimal::of($current)->minus(BigDecimal::of($previous))->toScale(2, RoundingMode::HALF_UP);
    }

    /** favorable / desfavorable / neutral según el tipo de concepto; null si no hay variación. */
    public static function assessment(?string $delta, string $kind): ?string
    {
        if ($delta === null) {
            return null;
        }
        $sign = BigDecimal::of($delta)->toScale(2, RoundingMode::HALF_UP)->getSign();
        if ($sign === 0) {
            return 'neutral';
        }
        $up = $sign > 0;

        return ($kind === ComparisonConcepts::EXPENSE ? ! $up : $up) ? 'favorable' : 'desfavorable';
    }

    public static function reason(?string $code): ?array
    {
        return $code === null ? null : ['code' => $code, 'message' => self::REASONS[$code] ?? $code];
    }
}
