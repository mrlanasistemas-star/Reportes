<?php

namespace App\Services\EstadoResultados\Export;

/**
 * Formato único de cifras para PDF y Excel del Estado de Resultados (México):
 *   $1,234.56 · -$1,234.56 · 40.00% · +2.50 pp
 * Un importe null NUNCA se muestra como $0.00: se muestra el texto indicado (Pendiente / Sin datos).
 */
final class ErFormat
{
    public const PENDING = 'Pendiente';

    public const NO_DATA = 'Sin datos';

    public static function money(mixed $v, string $null = self::PENDING): string
    {
        if ($v === null || $v === '') {
            return $null;
        }
        $f = round((float) $v, 2);

        return ($f < 0 ? '-$' : '$').number_format(abs($f), 2);
    }

    /** Variación con signo explícito: +$1,234.56 / -$1,234.56 / $0.00. */
    public static function delta(mixed $v, string $null = '—'): string
    {
        if ($v === null || $v === '') {
            return $null;
        }
        $f = round((float) $v, 2);

        return ($f > 0 ? '+' : '').self::money($f);
    }

    /** Porcentaje ya expresado en % (40.0000 → 40.00%). */
    public static function pct(mixed $v, string $null = '—', bool $signed = false): string
    {
        if ($v === null || $v === '') {
            return $null;
        }
        $f = round((float) $v, 2);

        return ($signed && $f > 0 ? '+' : '').number_format($f, 2).'%';
    }

    public static function points(mixed $v, string $null = '—'): string
    {
        if ($v === null || $v === '') {
            return $null;
        }
        $f = round((float) $v, 2);

        return ($f > 0 ? '+' : '').number_format($f, 2).' pp';
    }

    /** Importe abreviado para ejes de gráficas: $1.2M / $350K / -$4.5K. */
    public static function axis(float $v): string
    {
        $a = abs($v);
        $s = $v < 0 ? '-$' : '$';

        return match (true) {
            $a >= 1_000_000 => $s.rtrim(rtrim(number_format($a / 1_000_000, 1), '0'), '.').'M',
            $a >= 1_000 => $s.rtrim(rtrim(number_format($a / 1_000, 1), '0'), '.').'K',
            default => $s.number_format($a, 0),
        };
    }

    /**
     * Enmascara números de cuenta / CLABE / tarjeta (8 o más dígitos seguidos, o «****1234»)
     * dejando sólo los últimos 4: «••6697». Se aplica a todo texto de origen bancario que se exporta.
     */
    public static function mask(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        $text = preg_replace('/\*{2,}(\d{4})\b/u', '••$1', $text);

        // Sólo dígitos seguidos (o en bloques separados por un espacio, formato de tarjeta):
        // fechas (2026-09-10) e importes (1,234,567.89) no coinciden.
        return preg_replace_callback('/(?<![\d,]|\d\.)\d(?: ?\d){7,}(?![\d,]|\.\d)/u', fn ($m) => '••'.substr(str_replace(' ', '', $m[0]), -4), $text);
    }
}
