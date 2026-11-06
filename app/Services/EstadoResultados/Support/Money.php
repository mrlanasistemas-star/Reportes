<?php

namespace App\Services\EstadoResultados\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Aritmética monetaria exacta del módulo Estado de Resultados.
 *
 * Toda suma/resta del módulo se hace con BigDecimal (escala interna 6) para no
 * arrastrar errores de punto flotante. El redondeo a 2 decimales (HALF_UP) se
 * aplica SÓLO al presentar (dataset/Excel/PDF), nunca para "hacer cuadrar" una
 * diferencia de conciliación.
 */
final class Money
{
    public const INTERNAL_SCALE = 6;

    public static function zero(): BigDecimal
    {
        return BigDecimal::zero();
    }

    /**
     * Convierte un valor de BD/Excel/float a BigDecimal sin perder precisión
     * decimal. Los floats se pasan por sprintf('%.6F') para descartar el ruido
     * binario (0.1+0.2) sin redondear a centavos.
     */
    public static function of(mixed $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }
        if (is_int($value)) {
            return BigDecimal::of($value);
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                return BigDecimal::zero();
            }

            return BigDecimal::of(sprintf('%.'.self::INTERNAL_SCALE.'F', $value))->strippedOfTrailingZeros();
        }

        $clean = str_replace(['$', ',', ' ', "\u{00A0}"], '', trim((string) $value));
        if ($clean === '' || $clean === '-') {
            return BigDecimal::zero();
        }
        // Formato contable con paréntesis: (1,234.50) = -1234.50
        if (preg_match('/^\((.+)\)$/', $clean, $m)) {
            $clean = '-'.$m[1];
        }
        if (! is_numeric($clean)) {
            throw new \InvalidArgumentException("Importe no numérico: '{$value}'.");
        }

        // is_numeric acepta notación científica (1.5E3) que BigDecimal también acepta.
        return BigDecimal::of($clean)->strippedOfTrailingZeros();
    }

    /** Igual que of() pero devuelve null cuando el texto no es un importe válido. */
    public static function tryOf(mixed $value): ?BigDecimal
    {
        try {
            return self::of($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param iterable<mixed> $values */
    public static function sum(iterable $values): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($values as $v) {
            $total = $total->plus(self::of($v));
        }

        return $total;
    }

    /** Cadena con 2 decimales (redondeo de PRESENTACIÓN, HALF_UP). */
    public static function str2(mixed $value): string
    {
        return (string) self::of($value)->toScale(2, RoundingMode::HALF_UP);
    }

    /** Float de presentación (2 decimales) para JSON/Excel — nunca usar para volver a calcular. */
    public static function float2(mixed $value): float
    {
        return (float) self::str2($value);
    }

    /** Centavos enteros (redondeo HALF_UP) — clave de comparación exacta de importes. */
    public static function cents(mixed $value): int
    {
        return self::of($value)->toScale(2, RoundingMode::HALF_UP)->getUnscaledValue()->toInt();
    }

    public static function equalsCents(mixed $a, mixed $b): bool
    {
        return self::cents($a) === self::cents($b);
    }

    /** ¿La diferencia es $0.00 a precisión de centavos? */
    public static function isZeroCents(mixed $value): bool
    {
        return self::cents($value) === 0;
    }

    /** Porcentaje (0-100) a 4 decimales; null si la base es 0. */
    public static function pct(mixed $part, mixed $base): ?string
    {
        $b = self::of($base);
        if ($b->isZero()) {
            return null;
        }

        return (string) self::of($part)->multipliedBy(100)->dividedBy($b, 4, RoundingMode::HALF_UP);
    }
}
