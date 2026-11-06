<?php

namespace App\Services\EstadoResultados\Export;

/**
 * Gráficas del PDF del Estado de Resultados como SVG generado en el servidor (sin JavaScript
 * ni recursos externos: Chrome las imprime tal cual, sin esperas). Reciben las MISMAS series
 * del contrato er-comparativas/1 (ComparisonService::charts) que dibuja la web.
 *
 * Un valor null es «sin datos / pendiente»: nunca se dibuja como cero (la línea se corta, la
 * barra no se dibuja y se rotula «s/d» o «Pendiente»).
 */
final class ErSvgCharts
{
    public const COLORS = [
        'ingresos_operativos' => '#10B981',
        'opex_total' => '#F97316',
        'ebitda' => '#0F766E',
        'ebit' => '#0891B2',
        'ebt' => '#7C3AED',
        'utilidad_neta' => '#D97706',
        'margen_ebitda' => '#0F766E',
        'margen_ebit' => '#0891B2',
        'margen_neto' => '#D97706',
    ];

    public const PALETTE = ['#0F766E', '#06B6D4', '#10B981', '#7C3AED', '#F59E0B', '#EF4444', '#64748B', '#0EA5E9'];

    private const SHORT = [
        'ingresos_operativos' => 'Ingresos', 'opex_total' => 'OPEX', 'ebitda' => 'EBITDA', 'depreciacion_amortizacion' => 'D&A',
        'ebit' => 'EBIT', 'resultado_financiero_neto' => 'Res. financiero', 'ebt' => 'EBT', 'isr' => 'ISR', 'utilidad_neta' => 'Utilidad neta',
    ];

    private const W = 720;

    /**
     * @param  list<string>  $labels
     * @param  list<array{label:string,color?:string,data:list<?string|float|null>}>  $series
     */
    public static function line(array $labels, array $series, string $unit = 'MXN', int $height = 230): string
    {
        $n = count($labels);
        if ($n === 0 || $series === [] || ! self::hasValues($series)) {
            return self::empty('Sin datos suficientes para la gráfica.', $height);
        }
        [$l, $r, $t, $b] = [78, 18, 30, 44];
        $pw = self::W - $l - $r;
        $ph = $height - $t - $b;
        [$min, $max, $step] = self::scale(self::flat($series));
        $y = fn (float $v) => $t + $ph - ($v - $min) / ($max - $min) * $ph;
        $x = fn (int $i) => $l + ($i + 0.5) * $pw / $n;

        $out = self::open($height).self::legend($series, $l).self::grid($min, $max, $step, $y, $l, $pw, $unit);
        $out .= self::xLabels($labels, $series, $x, $t + $ph);
        foreach ($series as $si => $s) {
            $color = $s['color'] ?? self::PALETTE[$si % count(self::PALETTE)];
            $path = '';
            $prev = false;
            $last = null;
            foreach (array_values($s['data']) as $i => $v) {
                if ($v === null || $v === '') {
                    $prev = false;

                    continue;
                }
                $px = round($x($i), 1);
                $py = round($y((float) $v), 1);
                $path .= ($prev ? 'L' : 'M').$px.' '.$py.' ';
                $prev = true;
                $last = [$px, $py, (float) $v];
                $out .= '<circle cx="'.$px.'" cy="'.$py.'" r="3" fill="#fff" stroke="'.$color.'" stroke-width="1.8"/>';
            }
            if ($path !== '') {
                $out .= '<path d="'.trim($path).'" fill="none" stroke="'.$color.'" stroke-width="'.max(1.4, 4.6 - $si * 1.1).'" stroke-linejoin="round"/>';
            }
            if ($last) {
                $out .= '<text x="'.($last[0] + 5).'" y="'.($last[1] - 6 + $si * 10).'" font-size="8.5" font-weight="700" fill="'.$color.'">'.e(self::fmt($last[2], $unit)).'</text>';
            }
        }

        return $out.'</svg>';
    }

    /** Barras agrupadas (una barra por serie y periodo). */
    public static function bars(array $labels, array $series, string $unit = 'MXN', int $height = 230): string
    {
        $n = count($labels);
        if ($n === 0 || $series === [] || ! self::hasValues($series)) {
            return self::empty('Sin datos suficientes para la gráfica.', $height);
        }
        [$l, $r, $t, $b] = [78, 18, 30, 44];
        $pw = self::W - $l - $r;
        $ph = $height - $t - $b;
        [$min, $max, $step] = self::scale(self::flat($series));
        $y = fn (float $v) => $t + $ph - ($v - $min) / ($max - $min) * $ph;
        $slot = $pw / $n;
        $k = count($series);
        $bw = min(26, $slot * 0.78 / $k);
        $x = fn (int $i) => $l + ($i + 0.5) * $slot;

        $out = self::open($height).self::legend($series, $l).self::grid($min, $max, $step, $y, $l, $pw, $unit);
        $out .= self::xLabels($labels, $series, $x, $t + $ph);
        $zero = $y(0.0);
        foreach ($series as $si => $s) {
            $color = $s['color'] ?? self::PALETTE[$si % count(self::PALETTE)];
            foreach (array_values($s['data']) as $i => $v) {
                $bx = $x($i) - $k * $bw / 2 + $si * $bw;
                if ($v === null || $v === '') {
                    continue;
                }
                $vy = $y((float) $v);
                $out .= '<rect x="'.round($bx + 1, 1).'" y="'.round(min($vy, $zero), 1).'" width="'.round($bw - 2, 1).'" height="'.round(max(0.6, abs($zero - $vy)), 1).'" rx="2" fill="'.$color.'"/>';
            }
        }

        return $out.'</svg>';
    }

    /** Dona de composición. Sólo importes positivos; el resto se informa aparte. */
    public static function donut(array $items, ?string $total, int $height = 210): string
    {
        $items = array_values(array_filter($items, fn ($i) => $i['amount'] !== null && (float) $i['amount'] > 0));
        $sum = array_sum(array_map(fn ($i) => (float) $i['amount'], $items));
        if ($items === [] || $sum <= 0) {
            return self::empty('Sin OPEX positivo que graficar.', $height);
        }
        $cx = 120;
        $cy = $height / 2;
        $ro = min(92, $height / 2 - 10);
        $ri = $ro * 0.58;
        $out = self::open($height);
        $a0 = -M_PI / 2;
        foreach ($items as $i => $it) {
            $frac = (float) $it['amount'] / $sum;
            $color = $it['color'] ?? self::PALETTE[$i % count(self::PALETTE)];
            if ($frac >= 0.9999) {
                $out .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.(($ro + $ri) / 2).'" fill="none" stroke="'.$color.'" stroke-width="'.($ro - $ri).'"/>';
            } else {
                $a1 = $a0 + $frac * 2 * M_PI;
                $large = $frac > 0.5 ? 1 : 0;
                $p = fn ($a, $rad) => round($cx + $rad * cos($a), 2).' '.round($cy + $rad * sin($a), 2);
                $out .= '<path d="M'.$p($a0, $ro).' A'.$ro.' '.$ro.' 0 '.$large.' 1 '.$p($a1, $ro).' L'.$p($a1, $ri).' A'.$ri.' '.$ri.' 0 '.$large.' 0 '.$p($a0, $ri).' Z" fill="'.$color.'" stroke="#fff" stroke-width="1.2"/>';
                $a0 = $a1;
            }
        }
        $out .= '<text x="'.$cx.'" y="'.($cy - 3).'" text-anchor="middle" font-size="8" fill="#64748B">OPEX total</text>';
        $out .= '<text x="'.$cx.'" y="'.($cy + 11).'" text-anchor="middle" font-size="11" font-weight="800" fill="#0F172A">'.e(ErFormat::axis((float) ($total ?? $sum))).'</text>';
        $ly = max(14, $cy - count($items) * 11);
        foreach ($items as $i => $it) {
            $color = $it['color'] ?? self::PALETTE[$i % count(self::PALETTE)];
            $yy = $ly + $i * 22;
            $out .= '<rect x="250" y="'.($yy - 8).'" width="10" height="10" rx="2" fill="'.$color.'"/>';
            $out .= '<text x="266" y="'.$yy.'" font-size="9" fill="#0F172A" font-weight="600">'.e(self::clip((string) $it['label'], 46)).'</text>';
            $out .= '<text x="266" y="'.($yy + 10).'" font-size="8.5" fill="#475569">'.e(ErFormat::money($it['amount'])).' · '.e(ErFormat::pct(round((float) $it['amount'] / $sum * 100, 2))).'</text>';
        }

        return $out.'</svg>';
    }

    /**
     * Cascada: totales/subtotales desde 0, variaciones de inicio a fin (forma de charts.cascada).
     *
     * @param  list<array{key:string,label:string,type:string,amount:?string,start:?string,end:?string,pending:bool}>  $steps
     */
    public static function waterfall(array $steps, int $height = 250): string
    {
        $vals = [];
        foreach ($steps as $s) {
            foreach (['start', 'end'] as $k) {
                if ($s[$k] !== null) {
                    $vals[] = (float) $s[$k];
                }
            }
        }
        if ($steps === [] || $vals === []) {
            return self::empty('Sin datos para la cascada.', $height);
        }
        [$l, $r, $t, $b] = [78, 12, 22, 40];
        $pw = self::W - $l - $r;
        $ph = $height - $t - $b;
        [$min, $max, $step] = self::scale($vals);
        $y = fn (float $v) => $t + $ph - ($v - $min) / ($max - $min) * $ph;
        $n = count($steps);
        $slot = $pw / $n;
        $bw = min(54, $slot * 0.62);
        $out = self::open($height).self::grid($min, $max, $step, $y, $l, $pw, 'MXN');
        $prevEnd = null;
        foreach ($steps as $i => $s) {
            $cx = $l + ($i + 0.5) * $slot;
            $label = self::SHORT[$s['key']] ?? $s['label'];
            $out .= '<text x="'.round($cx, 1).'" y="'.($t + $ph + 14).'" text-anchor="middle" font-size="8.2" font-weight="'.($s['type'] === 'delta' ? '500' : '700').'" fill="#334155">'.e($label).'</text>';
            if ($s['pending'] || $s['start'] === null || $s['end'] === null) {
                $by = $y(0.0);
                $out .= '<rect x="'.round($cx - $bw / 2, 1).'" y="'.round($by - 16, 1).'" width="'.round($bw, 1).'" height="16" rx="2" fill="#FFFBEB" stroke="#D97706" stroke-dasharray="3 2"/>';
                $out .= '<text x="'.round($cx, 1).'" y="'.round($by - 5, 1).'" text-anchor="middle" font-size="7.6" font-weight="700" fill="#B45309">Pendiente</text>';
                $prevEnd = null;

                continue;
            }
            $y1 = $y((float) $s['start']);
            $y2 = $y((float) $s['end']);
            $color = match (true) {
                $s['type'] === 'total' => (float) $s['end'] < 0 ? '#B91C1C' : '#0F766E',
                $s['type'] === 'subtotal' => (float) $s['end'] < 0 ? '#DC2626' : '#0E7490',
                (float) $s['amount'] >= 0 => '#10B981',
                default => '#EF4444',
            };
            $out .= '<rect x="'.round($cx - $bw / 2, 1).'" y="'.round(min($y1, $y2), 1).'" width="'.round($bw, 1).'" height="'.round(max(0.8, abs($y2 - $y1)), 1).'" rx="2" fill="'.$color.'"/>';
            if ($prevEnd !== null && $i > 0) {
                $out .= '<line x1="'.round($cx - $slot + $bw / 2, 1).'" x2="'.round($cx - $bw / 2, 1).'" y1="'.round($y($prevEnd), 1).'" y2="'.round($y($prevEnd), 1).'" stroke="#94A3B8" stroke-dasharray="2 2" stroke-width="0.8"/>';
            }
            $val = $s['type'] === 'delta' ? (float) $s['amount'] : (float) $s['end'];
            $ty = min($y1, $y2) - 4;
            $out .= '<text x="'.round($cx, 1).'" y="'.round(max($t - 4, $ty), 1).'" text-anchor="middle" font-size="7.8" font-weight="700" fill="#0F172A">'.e(ErFormat::axis($val)).'</text>';
            $prevEnd = (float) $s['end'];
        }

        return $out.'</svg>';
    }

    /**
     * Barras horizontales divergentes (EBITDA por sucursal).
     *
     * @param  list<array{label:string,value:?string}>  $rows
     */
    public static function hbars(array $rows, string $unit = 'MXN'): string
    {
        $rows = array_values($rows);
        $vals = array_map(fn ($r) => (float) $r['value'], array_filter($rows, fn ($r) => $r['value'] !== null));
        $rowH = 17;
        $height = max(80, 30 + count($rows) * $rowH + 10);
        if ($vals === []) {
            return self::empty('Sin datos por sucursal.', 80);
        }
        if (max(array_map('abs', $vals)) < 0.005) {
            return self::empty('Todas las sucursales tienen EBITDA de $0.00 en el periodo.', 80);
        }
        [$l, $r, $t] = [150, 70, 18];
        $pw = self::W - $l - $r;
        [$min, $max, $step] = self::scale($vals, 4);
        $x = fn (float $v) => $l + ($v - $min) / ($max - $min) * $pw;
        $out = self::open($height);
        for ($v = $min; $v <= $max + $step / 2; $v += $step) {
            $gx = round($x($v), 1);
            $out .= '<line x1="'.$gx.'" x2="'.$gx.'" y1="'.$t.'" y2="'.($height - 10).'" stroke="'.(abs($v) < $step / 2 ? '#94A3B8' : '#EEF2F7').'" stroke-width="'.(abs($v) < $step / 2 ? 1 : 0.8).'"/>';
            $out .= '<text x="'.$gx.'" y="'.($t - 5).'" text-anchor="middle" font-size="7.6" fill="#64748B">'.e(self::fmt($v, $unit)).'</text>';
        }
        $zero = $x(0.0);
        foreach ($rows as $i => $row) {
            $cy = $t + 6 + $i * $rowH;
            $out .= '<text x="'.($l - 6).'" y="'.($cy + 8).'" text-anchor="end" font-size="8.2" fill="#0F172A">'.e(self::clip((string) $row['label'], 30)).'</text>';
            if ($row['value'] === null) {
                $out .= '<text x="'.round($zero + 4, 1).'" y="'.($cy + 8).'" font-size="7.6" fill="#94A3B8">Sin datos</text>';

                continue;
            }
            $v = (float) $row['value'];
            $vx = $x($v);
            $out .= '<rect x="'.round(min($zero, $vx), 1).'" y="'.$cy.'" width="'.round(max(0.8, abs($vx - $zero)), 1).'" height="'.($rowH - 5).'" rx="2" fill="'.($v < 0 ? '#EF4444' : '#0F766E').'"/>';
            $out .= '<text x="'.round(max($zero, $vx) + 4, 1).'" y="'.($cy + 8).'" font-size="7.8" font-weight="700" fill="#0F172A">'.e(ErFormat::money($v)).'</text>';
        }

        return $out.'</svg>';
    }

    // ── Utilidades ──────────────────────────────────────────────────

    /** Escala «bonita» que siempre incluye el cero. @return array{float,float,float} */
    public static function scale(array $values, int $ticks = 5): array
    {
        $values = array_map('floatval', $values);
        $min = min(0.0, ...($values ?: [0.0]));
        $max = max(0.0, ...($values ?: [0.0]));
        if ($max - $min < 0.01) {
            $max = $min + 1;
        }
        $rough = ($max - $min) / $ticks;
        $mag = 10 ** floor(log10($rough));
        $norm = $rough / $mag;
        $step = ($norm < 1.5 ? 1 : ($norm < 3 ? 2 : ($norm < 7 ? 5 : 10))) * $mag;

        return [floor($min / $step) * $step, ceil($max / $step) * $step, $step];
    }

    private static function open(int $height): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.self::W.' '.$height.'" width="100%" preserveAspectRatio="xMidYMid meet" font-family="Montserrat, Helvetica, Arial, sans-serif" role="img">';
    }

    private static function empty(string $text, int $height): string
    {
        $h = min($height, 70);

        return self::open($h).'<rect x="1" y="1" width="'.(self::W - 2).'" height="'.($h - 2).'" rx="8" fill="#F8FAFC" stroke="#E2E8F0"/>'
            .'<text x="'.(self::W / 2).'" y="'.($h / 2 + 4).'" text-anchor="middle" font-size="10" fill="#64748B">'.e($text).'</text></svg>';
    }

    private static function grid(float $min, float $max, float $step, callable $y, int $l, float $pw, string $unit): string
    {
        $out = '';
        for ($v = $min; $v <= $max + $step / 2; $v += $step) {
            $gy = round($y($v), 1);
            $isZero = abs($v) < $step / 2;
            $out .= '<line x1="'.$l.'" x2="'.round($l + $pw, 1).'" y1="'.$gy.'" y2="'.$gy.'" stroke="'.($isZero ? '#94A3B8' : '#EEF2F7').'" stroke-width="'.($isZero ? 1 : 0.8).'"/>';
            $out .= '<text x="'.($l - 6).'" y="'.($gy + 3).'" text-anchor="end" font-size="7.8" fill="#64748B">'.e(self::fmt($isZero ? 0.0 : $v, $unit)).'</text>';
        }

        return $out;
    }

    private static function legend(array $series, int $l): string
    {
        $out = '';
        $x = $l;
        foreach ($series as $si => $s) {
            $color = $s['color'] ?? self::PALETTE[$si % count(self::PALETTE)];
            $out .= '<rect x="'.$x.'" y="6" width="10" height="10" rx="2" fill="'.$color.'"/>';
            $out .= '<text x="'.($x + 14).'" y="14.5" font-size="8.6" font-weight="600" fill="#334155">'.e((string) $s['label']).'</text>';
            $x += 24 + (int) (mb_strlen((string) $s['label']) * 5.2);
        }

        return $out;
    }

    private static function xLabels(array $labels, array $series, callable $x, float $base): string
    {
        $out = '';
        $n = count($labels);
        foreach (array_values($labels) as $i => $label) {
            $noData = true;
            foreach ($series as $s) {
                $v = $s['data'][$i] ?? null;
                if ($v !== null && $v !== '') {
                    $noData = false;
                }
            }
            $short = self::shortPeriod((string) $label, $n);
            $cx = round($x($i), 1);
            $out .= '<text x="'.$cx.'" y="'.($base + 14).'" text-anchor="middle" font-size="7.8" fill="#334155">'.e($short).'</text>';
            if ($noData) {
                $out .= '<text x="'.$cx.'" y="'.($base + 25).'" text-anchor="middle" font-size="7" fill="#B45309">Sin datos</text>';
            }
        }

        return $out;
    }

    /** «Junio 2026» → «Jun 2026» cuando hay muchos meses. */
    private static function shortPeriod(string $label, int $n): string
    {
        if ($n <= 6 || ! preg_match('/^(\p{L}+)\s+(\d{4})$/u', $label, $m)) {
            return $label;
        }

        return mb_substr($m[1], 0, 3).' '.$m[2];
    }

    private static function fmt(float $v, string $unit): string
    {
        return $unit === '%' ? number_format($v, abs($v) < 10 && fmod($v, 1.0) != 0.0 ? 1 : 0).'%' : ErFormat::axis($v);
    }

    private static function flat(array $series): array
    {
        $out = [];
        foreach ($series as $s) {
            foreach ($s['data'] as $v) {
                if ($v !== null && $v !== '') {
                    $out[] = (float) $v;
                }
            }
        }

        return $out;
    }

    private static function hasValues(array $series): bool
    {
        return self::flat($series) !== [];
    }

    private static function clip(string $s, int $n): string
    {
        return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1).'…' : $s;
    }
}
