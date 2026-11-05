<?php

namespace App\Services\Efectividad\V12;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Helpers con la misma semántica que las funciones auxiliares de la metodología
 * V12.9.4 (`MR_Norm`, `MR_Num`, `MR_Fecha`, `CLng`, `Application.Round`…).
 *
 * Las fechas se representan como enteros: días desde 1970-01-01 (UTC, solo fecha).
 * `0` equivale a "sin fecha" (en la hoja original, una celda vacía = fecha 0).
 */
final class V12Vba
{
    /** E_MARGEN: tolerancia general de importes. */
    public const MARGEN = 0.005;

    /** Tolerancia de conciliación de centavos (0.02). */
    public const CENTAVOS = 0.02;

    /** MR_Norm — UCase(Trim(CStr(v))) sin acentos (Á É Í Ó Ú Ü). La Ñ se conserva. */
    public static function norm(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        $s = mb_strtoupper(trim(self::cstr($v), ' '));

        return str_replace(['Á', 'É', 'Í', 'Ó', 'Ú', 'Ü'], ['A', 'E', 'I', 'O', 'U', 'U'], $s);
    }

    /** CStr: enteros sin decimales; fechas dd/mm/aaaa. */
    public static function cstr(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        if (is_bool($v)) {
            return $v ? 'Verdadero' : 'Falso';
        }
        if ($v instanceof DateTimeInterface) {
            return $v->format('d/m/Y');
        }

        return (string) $v;
    }

    /** MR_Num — número de una celda (acepta "$1,234.50", "1.234,50"). */
    public static function num(mixed $v): float
    {
        if ($v === null || $v === '' || is_bool($v)) {
            return 0.0;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return 0.0;
        }
        if (is_numeric($s)) {
            return (float) $s;
        }
        $s = str_replace(['$', ' '], '', $s);
        $punto = strrpos($s, '.');
        $coma = strrpos($s, ',');
        if ($punto !== false && $coma !== false) {
            $s = $coma > $punto ? str_replace(',', '.', str_replace('.', '', $s)) : str_replace(',', '', $s);
        } elseif ($coma !== false) {
            $s = str_replace(',', '.', $s);
        }

        // Val(): prefijo numérico válido.
        return preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/', $s, $m) ? (float) $m[0] : 0.0;
    }

    /** IsNumeric de VBA sobre el valor de una celda (una celda vacía es numérica). */
    public static function esNumerico(mixed $v): bool
    {
        if ($v === null || $v === '' || is_int($v) || is_float($v)) {
            return true;
        }
        if (is_bool($v)) {
            return true;
        }
        $s = trim((string) $v);

        return $s !== '' && (is_numeric($s) || is_numeric(str_replace([',', '$'], '', $s)));
    }

    /** CLng(MR_Num(v)) — redondeo bancario. */
    public static function clng(mixed $v): int
    {
        return (int) round(self::num($v), 0, PHP_ROUND_HALF_EVEN);
    }

    /** Application.Round(x, 2) — mitad lejos de cero. */
    public static function round2(float $x): float
    {
        return round($x, 2);
    }

    /**
     * Suma de una sola expresión de varios operandos (`a + b + c`): resultado exacto
     * redondeado una vez (suma compensada), como la evaluación de la expresión original.
     */
    public static function suma(float ...$xs): float
    {
        $s = 0.0;
        $err = 0.0;
        foreach ($xs as $x) {
            $t = $s + $x;
            $bp = $t - $s;
            $err += ($s - ($t - $bp)) + ($x - $bp);
            $s = $t;
        }

        return $s + $err;
    }

    /**
     * MR_Fecha — fecha real, serial de Excel o texto "aaaa-mm-dd" / "dd/mm/aaaa"
     * (se ignora la hora). Devuelve días desde 1970-01-01 o null si no es fecha.
     */
    public static function fecha(mixed $v): ?int
    {
        if ($v === null || $v === '' || is_bool($v)) {
            return null;
        }
        if ($v instanceof DateTimeInterface) {
            return self::dia((int) $v->format('Y'), (int) $v->format('m'), (int) $v->format('d'));
        }
        if (is_int($v) || is_float($v) || (is_string($v) && is_numeric(trim($v)))) {
            $n = (float) $v;

            return $n >= 1 ? (int) floor($n) - 25569 : null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        $s = str_replace('-', '/', explode(' ', $s)[0]);
        $t = explode('/', $s);
        if (count($t) !== 3 || ! ctype_digit($t[0]) || ! ctype_digit($t[1]) || ! ctype_digit($t[2])) {
            return null;
        }
        [$y, $m, $d] = strlen($t[0]) === 4 ? [(int) $t[0], (int) $t[1], (int) $t[2]] : [(int) $t[2], (int) $t[1], (int) $t[0]];
        if ($y < 100) {
            $y += $y < 30 ? 2000 : 1900;
        }

        return checkdate($m, $d, $y) ? self::dia($y, $m, $d) : null;
    }

    public static function dia(int $y, int $m, int $d): int
    {
        return intdiv((int) (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $d), new DateTimeZone('UTC')))->getTimestamp(), 86400);
    }

    public static function ymd(int $dia): string
    {
        return gmdate('Y-m-d', $dia * 86400);
    }

    public static function dmy(int $dia): string
    {
        return gmdate('d/m/Y', $dia * 86400);
    }

    /** Format$(f, "yyyymmdd"). */
    public static function compacto(int $dia): string
    {
        return gmdate('Ymd', $dia * 86400);
    }

    /** Weekday(f, vbMonday): lunes = 1 … domingo = 7. */
    public static function diaSemana(int $dia): int
    {
        return (int) gmdate('N', $dia * 86400);
    }

    /** DateAdd("m", n, f): si el día no existe en el mes destino se ajusta al último día. */
    public static function sumarMeses(int $dia, int $n): int
    {
        [$y, $m, $d] = array_map('intval', explode('-', self::ymd($dia)));
        $m += $n;
        $y += intdiv($m - 1, 12);
        $m = (($m - 1) % 12) + 1;
        if ($m < 1) {
            $m += 12;
            $y--;
        }

        return self::dia($y, $m, min($d, cal_days_in_month(CAL_GREGORIAN, $m, $y)));
    }

    /** Format$(x, "#,##0.00"). */
    public static function dinero(float $x): string
    {
        return number_format($x, 2, '.', ',');
    }
}
