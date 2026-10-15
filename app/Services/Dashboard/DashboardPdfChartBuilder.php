<?php

namespace App\Services\Dashboard;

/**
 * Gráficas del Dashboard para el PDF exportado (15-oct-2026) — SVG inline
 * generado en el servidor con los MISMOS datos que pinta Dashboard.vue
 * (kpis/charts de DashboardController::payloadForPeriod() y la tendencia de
 * DashboardTrendService). Sin JavaScript ni librerías de gráficas en Chrome:
 * el PDF no depende de que ApexCharts termine de animar y nunca sale vacío.
 *
 * Solo PRESENTA: no recalcula ni redondea cifras (las etiquetas usan el valor
 * tal cual, formateado como moneda mexicana).
 */
class DashboardPdfChartBuilder
{
    private const FONT = "Montserrat, 'Segoe UI', Arial, sans-serif";
    private const GRID = '#e2e8f0';
    private const AXIS = '#64748b';

    /**
     * Tendencia EBITDA vs OPEX (línea con área).
     *
     * @param  array<int, array{label: string, ebitda: ?float, opex: ?float}>  $trend
     */
    public function trend(array $trend, int $width = 700, int $height = 230): string
    {
        if ($trend === []) {
            return '';
        }

        $series = [
            ['name' => 'EBITDA', 'color' => '#106A59', 'key' => 'ebitda'],
            ['name' => 'OPEX', 'color' => '#EF4444', 'key' => 'opex'],
        ];
        $values = [];
        foreach ($trend as $p) {
            foreach ($series as $s) {
                $values[] = (float) ($p[$s['key']] ?? 0);
            }
        }
        [$min, $max, $step] = $this->niceRange(min(0, ...$values), max(0, ...$values));

        $left = 74; $right = 16; $top = 30; $bottom = 30;
        $plotW = $width - $left - $right;
        $plotH = $height - $top - $bottom;
        $n = count($trend);
        $inset = 34; // margen interno: la primera/última etiqueta del eje X nunca se corta
        $x = fn (int $i) => $left + ($n === 1 ? $plotW / 2 : $inset + ($plotW - 2 * $inset) * $i / ($n - 1));
        $y = fn (float $v) => $top + $plotH - ($v - $min) / ($max - $min) * $plotH;

        $svg = $this->open($width, $height) . $this->yGrid($min, $max, $step, $left, $width - $right, $y);
        foreach ($trend as $i => $p) {
            $svg .= $this->text($x($i), $height - 10, $p['label'], 9, 'middle', self::AXIS);
        }
        foreach ($series as $s) {
            $pts = [];
            foreach ($trend as $i => $p) {
                $pts[] = round($x($i), 1) . ',' . round($y((float) ($p[$s['key']] ?? 0)), 1);
            }
            $base = round($y(max($min, 0)), 1);
            $area = 'M' . round($x(0), 1) . ',' . $base . ' L' . implode(' L', $pts) . ' L' . round($x($n - 1), 1) . ',' . $base . ' Z';
            $svg .= '<path d="' . $area . '" fill="' . $s['color'] . '" fill-opacity="0.10"/>';
            $svg .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $s['color'] . '" stroke-width="2.4" stroke-linejoin="round"/>';
            foreach ($pts as $pt) {
                [$px, $py] = explode(',', $pt);
                $svg .= '<circle cx="' . $px . '" cy="' . $py . '" r="3" fill="#fff" stroke="' . $s['color'] . '" stroke-width="1.8"/>';
            }
        }
        $svg .= $this->legend($series, $width - $right, 14);

        return $svg . '</svg>';
    }

    /**
     * Dona: sucursales por categoría EBITDA.
     *
     * @param  string[]  $labels
     * @param  int[]  $counts
     * @param  string[]  $colors
     */
    public function donut(array $labels, array $counts, array $colors, int $width = 340, int $height = 220): string
    {
        $total = array_sum($counts);
        if ($total <= 0) {
            return '';
        }

        $cx = 100; $cy = $height / 2; $r = 78; $inner = 50;
        $svg = $this->open($width, $height);
        $angle = -M_PI / 2;
        foreach ($counts as $i => $count) {
            if ($count <= 0) {
                continue;
            }
            $sweep = 2 * M_PI * $count / $total;
            $color = $colors[$i] ?? '#94A3B8';
            if ($count === $total) {
                $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . (($r + $inner) / 2) . '" fill="none" stroke="' . $color . '" stroke-width="' . ($r - $inner) . '"/>';
            } else {
                $svg .= '<path d="' . $this->arc($cx, $cy, $r, $inner, $angle, $angle + $sweep) . '" fill="' . $color . '" stroke="#fff" stroke-width="1.5"/>';
            }
            $angle += $sweep;
        }
        $svg .= $this->text($cx, $cy - 2, (string) $total, 20, 'middle', '#0f172a', 800);
        $svg .= $this->text($cx, $cy + 14, $total === 1 ? 'sucursal' : 'sucursales', 8.5, 'middle', self::AXIS);

        $ly = max(18, $cy - count($labels) * 11);
        foreach ($labels as $i => $label) {
            $count = $counts[$i] ?? 0;
            $pct = round($count / $total * 100);
            $svg .= '<rect x="204" y="' . ($ly - 8) . '" width="10" height="10" rx="2" fill="' . ($colors[$i] ?? '#94A3B8') . '"/>';
            $svg .= $this->text(220, $ly + 1, $label, 9.5, 'start', '#1e293b', 700);
            $svg .= $this->text(220, $ly + 12, "{$count} · {$pct}%", 8.5, 'start', self::AXIS);
            $ly += 26;
        }

        return $svg . '</svg>';
    }

    /**
     * Barras verticales de una o varias series (mora por antigüedad, colocación vs recuperación).
     *
     * @param  string[]  $categories
     * @param  array<int, array{name: string, color: string, data: float[]}>  $series
     */
    public function bars(array $categories, array $series, int $width = 340, int $height = 220, bool $rotateLabels = false, bool $valueLabels = false): string
    {
        if ($categories === []) {
            return '';
        }

        $values = [];
        foreach ($series as $s) {
            foreach ($s['data'] as $v) {
                $values[] = (float) $v;
            }
        }
        [$min, $max, $step] = $this->niceRange(min(0, ...$values), max(0, ...$values));

        $left = 70; $right = 10; $top = count($series) > 1 ? 30 : 16; $bottom = $rotateLabels ? 78 : 28;
        $plotW = $width - $left - $right;
        $plotH = $height - $top - $bottom;
        $y = fn (float $v) => $top + $plotH - ($v - $min) / ($max - $min) * $plotH;

        $svg = $this->open($width, $height) . $this->yGrid($min, $max, $step, $left, $width - $right, $y);
        $slot = $plotW / count($categories);
        $groupW = $slot * 0.68;
        $barW = $groupW / max(1, count($series));
        $zero = $y(0);

        foreach ($categories as $i => $cat) {
            $gx = $left + $slot * $i + ($slot - $groupW) / 2;
            foreach ($series as $k => $s) {
                $v = (float) ($s['data'][$i] ?? 0);
                $yv = $y($v);
                $h = abs($zero - $yv);
                $svg .= '<rect x="' . round($gx + $barW * $k, 1) . '" y="' . round(min($yv, $zero), 1) . '" width="' . round(max(1, $barW - 1.5), 1) . '" height="' . round(max(0.5, $h), 1) . '" rx="2" fill="' . $s['color'] . '"/>';
                if ($valueLabels && $v != 0.0) {
                    $svg .= $this->text($gx + $barW * $k + $barW / 2, min($yv, $zero) - 4, $this->compactMoney($v), 8, 'middle', '#334155', 700);
                }
            }
            $cx = $left + $slot * $i + $slot / 2;
            if ($rotateLabels) {
                $svg .= '<text x="' . round($cx, 1) . '" y="' . ($height - $bottom + 12) . '" transform="rotate(-40 ' . round($cx, 1) . ' ' . ($height - $bottom + 12) . ')" font-family="' . self::FONT . '" font-size="8.5" fill="' . self::AXIS . '" text-anchor="end">' . $this->esc(mb_strlen($cat) > 18 ? mb_substr($cat, 0, 17) . '…' : $cat) . '</text>';
            } else {
                $svg .= $this->text($cx, $height - 10, $cat, 8.5, 'middle', self::AXIS);
            }
        }
        if (count($series) > 1) {
            $svg .= $this->legend(array_map(fn ($s) => ['name' => $s['name'], 'color' => $s['color']], $series), $width - $right, 14);
        }

        return $svg . '</svg>';
    }

    public function money(float $value): string
    {
        return ($value < 0 ? '-' : '') . '$' . number_format(abs($value), 2, '.', ',');
    }

    /** Etiqueta corta de eje/valor: $1.2M, $350K, $900. */
    public function compactMoney(float $value): string
    {
        $abs = abs($value);
        $sign = $value < 0 ? '-' : '';

        return match (true) {
            $abs >= 1_000_000 => $sign . '$' . rtrim(rtrim(number_format($abs / 1_000_000, 1, '.', ','), '0'), '.') . 'M',
            $abs >= 1_000 => $sign . '$' . number_format($abs / 1_000, 0, '.', ',') . 'K',
            default => $sign . '$' . number_format($abs, 0, '.', ','),
        };
    }

    private function open(int $w, int $h): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" preserveAspectRatio="xMidYMid meet" role="img">';
    }

    private function yGrid(float $min, float $max, float $step, float $x1, float $x2, callable $y): string
    {
        $out = '';
        // Marcas en múltiplos "redondos" del paso (1/2/5 × 10^n): nunca
        // etiquetas engañosas como "$2.3M" para 2,250,000.
        $steps = (int) round(($max - $min) / $step);
        for ($i = 0; $i <= $steps; $i++) {
            $v = $min + $step * $i;
            $yy = round($y($v), 1);
            $out .= '<line x1="' . $x1 . '" y1="' . $yy . '" x2="' . $x2 . '" y2="' . $yy . '" stroke="' . ($v == 0.0 ? '#94a3b8' : self::GRID) . '" stroke-width="' . ($v == 0.0 ? 1 : .7) . '"/>';
            $out .= $this->text($x1 - 6, $yy + 3, $this->compactMoney($v), 8.5, 'end', self::AXIS);
        }

        return $out;
    }

    /** @return array{0: float, 1: float, 2: float} [min, max, paso] */
    private function niceRange(float $min, float $max): array
    {
        if ($max == $min) {
            $max = $min + 1;
        }
        $span = $max - $min;
        $base = 10 ** floor(log10($span / 4));
        $step = $base * 10;
        foreach ([1, 2, 5, 10] as $m) {
            if ($span / ($base * $m) <= 5) {
                $step = $base * $m;
                break;
            }
        }

        return [floor($min / $step) * $step, ceil($max / $step) * $step, $step];
    }

    private function legend(array $series, float $rightX, float $y): string
    {
        $out = '';
        $x = $rightX;
        foreach (array_reverse($series) as $s) {
            $w = 18 + mb_strlen($s['name']) * 6.2;
            $x -= $w;
            $out .= '<rect x="' . round($x, 1) . '" y="' . ($y - 7) . '" width="9" height="9" rx="2" fill="' . $s['color'] . '"/>';
            $out .= $this->text($x + 13, $y + 1, $s['name'], 9, 'start', '#334155', 600);
            $x -= 10;
        }

        return $out;
    }

    private function arc(float $cx, float $cy, float $r, float $ri, float $a0, float $a1): string
    {
        $large = ($a1 - $a0) > M_PI ? 1 : 0;
        $p = fn (float $rad, float $a) => round($cx + $rad * cos($a), 2) . ',' . round($cy + $rad * sin($a), 2);

        return 'M' . $p($r, $a0) . ' A' . $r . ',' . $r . ' 0 ' . $large . ' 1 ' . $p($r, $a1)
            . ' L' . $p($ri, $a1) . ' A' . $ri . ',' . $ri . ' 0 ' . $large . ' 0 ' . $p($ri, $a0) . ' Z';
    }

    private function text(float $x, float $y, string $value, float $size, string $anchor, string $color, int $weight = 500): string
    {
        return '<text x="' . round($x, 1) . '" y="' . round($y, 1) . '" font-family="' . self::FONT . '" font-size="' . $size . '" font-weight="' . $weight . '" fill="' . $color . '" text-anchor="' . $anchor . '">' . $this->esc($value) . '</text>';
    }

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
