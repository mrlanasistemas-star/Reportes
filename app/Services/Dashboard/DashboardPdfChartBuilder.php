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
 *
 * Lo usa el exporte PDF del Dashboard; cada método público devuelve un <svg>
 * como cadena (o '' si no hay datos) listo para incrustar en la vista Blade.
 */
class DashboardPdfChartBuilder
{
    /** Tipografía de todos los textos del SVG. */
    private const FONT = "Montserrat, 'Segoe UI', Arial, sans-serif";

    /** Color de las líneas de la cuadrícula. */
    private const GRID = '#e2e8f0';

    /** Color de las etiquetas de ejes. */
    private const AXIS = '#64748b';

    /**
     * Tendencia EBITDA vs OPEX (línea con área).
     *
     * Los meses sin dato (null) cortan la línea; se rotula el último valor con
     * dato de cada serie y se agrega leyenda.
     *
     * @param  array<int, array{label: string, ebitda: ?float, opex: ?float}>  $trend  Puntos por mes (índices 0..n-1).
     * @param  int  $width  Ancho del viewBox.
     * @param  int  $height  Alto del viewBox.
     * @return string SVG, o '' si no hay puntos.
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
        // Fase 7.2 — un punto null (sucursal sin radiografía ese mes) es un HUECO en la
        // línea, nunca un 0 inventado: no entra a la escala ni se dibuja.
        $values = [0.0];
        foreach ($trend as $p) {
            foreach ($series as $s) {
                if (($p[$s['key']] ?? null) !== null) {
                    $values[] = (float) $p[$s['key']];
                }
            }
        }
        [$min, $max, $step] = $this->niceRange(min(...$values), max(...$values));

        $left = 74;
        $right = 16;
        $top = 30;
        $bottom = 30;
        $plotW = $width - $left - $right;
        $plotH = $height - $top - $bottom;
        $n = count($trend);
        $inset = 34; // margen interno: la primera/última etiqueta del eje X nunca se corta
        $x = fn (int $i) => $left + ($n === 1 ? $plotW / 2 : $inset + ($plotW - 2 * $inset) * $i / ($n - 1));
        $y = fn (float $v) => $top + $plotH - ($v - $min) / ($max - $min) * $plotH;

        $svg = $this->open($width, $height).$this->yGrid($min, $max, $step, $left, $width - $right, $y);
        foreach ($trend as $i => $p) {
            $svg .= $this->text($x($i), $height - 10, $p['label'], 9, 'middle', self::AXIS);
        }
        $base = round($y(max($min, 0)), 1);
        foreach ($series as $s) {
            // Tramos continuos de puntos con dato (un null corta la línea).
            $segments = [[]];
            foreach ($trend as $i => $p) {
                if (($p[$s['key']] ?? null) === null) {
                    $segments[] = [];

                    continue;
                }
                $segments[count($segments) - 1][] = [round($x($i), 1), round($y((float) $p[$s['key']]), 1)];
            }
            foreach (array_filter($segments) as $seg) {
                $pts = array_map(fn ($pt) => $pt[0].','.$pt[1], $seg);
                $area = 'M'.$seg[0][0].','.$base.' L'.implode(' L', $pts).' L'.$seg[count($seg) - 1][0].','.$base.' Z';
                $svg .= '<path d="'.$area.'" fill="'.$s['color'].'" fill-opacity="0.10"/>';
                $svg .= '<polyline points="'.implode(' ', $pts).'" fill="none" stroke="'.$s['color'].'" stroke-width="2.4" stroke-linejoin="round"/>';
                foreach ($seg as [$px, $py]) {
                    $svg .= '<circle cx="'.$px.'" cy="'.$py.'" r="3" fill="#fff" stroke="'.$s['color'].'" stroke-width="1.8"/>';
                }
            }
        }
        // Valor del último punto con dato de cada serie (lectura rápida sin buscar en el eje).
        foreach ($series as $s) {
            for ($i = $n - 1; $i >= 0; $i--) {
                if (($trend[$i][$s['key']] ?? null) !== null) {
                    $v = (float) $trend[$i][$s['key']];
                    $svg .= $this->text($x($i), $y($v) - 8, $this->compactMoney($v), 8.5, 'middle', $s['color'], 700);
                    break;
                }
            }
        }
        $svg .= $this->legend($series, $width - $right, 14);

        return $svg.'</svg>';
    }

    /**
     * Dona: sucursales por categoría EBITDA.
     *
     * Dibuja los segmentos con conteo > 0 (círculo completo si una sola
     * categoría tiene todo), el total al centro y la leyenda con conteo y %.
     *
     * @param  string[]  $labels  Nombre de cada categoría.
     * @param  int[]  $counts  Sucursales por categoría (mismo índice que labels).
     * @param  string[]  $colors  Color por categoría (gris si falta).
     * @param  int  $width  Ancho del viewBox.
     * @param  int  $height  Alto del viewBox.
     * @return string SVG, o '' si el total es 0.
     */
    public function donut(array $labels, array $counts, array $colors, int $width = 340, int $height = 220): string
    {
        $total = array_sum($counts);
        if ($total <= 0) {
            return '';
        }

        $cx = 100;
        $cy = $height / 2;
        $r = 78;
        $inner = 50;
        $svg = $this->open($width, $height);
        $angle = -M_PI / 2;
        foreach ($counts as $i => $count) {
            if ($count <= 0) {
                continue;
            }
            $sweep = 2 * M_PI * $count / $total;
            $color = $colors[$i] ?? '#94A3B8';
            if ($count === $total) {
                $svg .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.(($r + $inner) / 2).'" fill="none" stroke="'.$color.'" stroke-width="'.($r - $inner).'"/>';
            } else {
                $svg .= '<path d="'.$this->arc($cx, $cy, $r, $inner, $angle, $angle + $sweep).'" fill="'.$color.'" stroke="#fff" stroke-width="1.5"/>';
            }
            $angle += $sweep;
        }
        $svg .= $this->text($cx, $cy - 2, (string) $total, 20, 'middle', '#0f172a', 800);
        $svg .= $this->text($cx, $cy + 14, $total === 1 ? 'sucursal' : 'sucursales', 8.5, 'middle', self::AXIS);

        $ly = max(18, $cy - count($labels) * 11);
        foreach ($labels as $i => $label) {
            $count = $counts[$i] ?? 0;
            $pct = round($count / $total * 100);
            $svg .= '<rect x="204" y="'.($ly - 8).'" width="10" height="10" rx="2" fill="'.($colors[$i] ?? '#94A3B8').'"/>';
            $svg .= $this->text(220, $ly + 1, $label, 9.5, 'start', '#1e293b', 700);
            $svg .= $this->text(220, $ly + 12, "{$count} · {$pct}%", 8.5, 'start', self::AXIS);
            $ly += 26;
        }

        return $svg.'</svg>';
    }

    /**
     * Barras verticales de una o varias series (mora por antigüedad, colocación vs recuperación).
     *
     * Escala que siempre incluye el 0 (admite negativos); leyenda solo con más
     * de una serie.
     *
     * @param  string[]  $categories  Etiquetas del eje X.
     * @param  array<int, array{name: string, color: string, data: float[]}>  $series  Series (data alineada a categories; faltante = 0).
     * @param  int  $width  Ancho del viewBox.
     * @param  int  $height  Alto del viewBox.
     * @param  bool  $rotateLabels  Rota -40° las etiquetas X (corta a 18 caracteres).
     * @param  bool  $valueLabels  Rotula el valor compacto encima de cada barra distinta de 0.
     * @return string SVG, o '' si no hay categorías.
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

        $left = 70;
        $right = 10;
        $top = count($series) > 1 ? 30 : 16;
        $bottom = $rotateLabels ? 78 : 28;
        $plotW = $width - $left - $right;
        $plotH = $height - $top - $bottom;
        $y = fn (float $v) => $top + $plotH - ($v - $min) / ($max - $min) * $plotH;

        $svg = $this->open($width, $height).$this->yGrid($min, $max, $step, $left, $width - $right, $y);
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
                $svg .= '<rect x="'.round($gx + $barW * $k, 1).'" y="'.round(min($yv, $zero), 1).'" width="'.round(max(1, $barW - 1.5), 1).'" height="'.round(max(0.5, $h), 1).'" rx="2" fill="'.$s['color'].'"/>';
                if ($valueLabels && $v != 0.0) {
                    $svg .= $this->text($gx + $barW * $k + $barW / 2, min($yv, $zero) - 4, $this->compactMoney($v), 8, 'middle', '#334155', 700);
                }
            }
            $cx = $left + $slot * $i + $slot / 2;
            if ($rotateLabels) {
                $svg .= '<text x="'.round($cx, 1).'" y="'.($height - $bottom + 12).'" transform="rotate(-40 '.round($cx, 1).' '.($height - $bottom + 12).')" font-family="'.self::FONT.'" font-size="8.5" fill="'.self::AXIS.'" text-anchor="end">'.$this->esc(mb_strlen($cat) > 18 ? mb_substr($cat, 0, 17).'…' : $cat).'</text>';
            } else {
                $svg .= $this->text($cx, $height - 10, $cat, 8.5, 'middle', self::AXIS);
            }
        }
        if (count($series) > 1) {
            $svg .= $this->legend(array_map(fn ($s) => ['name' => $s['name'], 'color' => $s['color']], $series), $width - $right, 14);
        }

        return $svg.'</svg>';
    }

    /**
     * Formato moneda MXN con 2 decimales: -$1,234.56.
     *
     * @param  float  $value  Importe.
     */
    public function money(float $value): string
    {
        return ($value < 0 ? '-' : '').'$'.number_format(abs($value), 2, '.', ',');
    }

    /**
     * Etiqueta corta de eje/valor: $1.2M, $350K, $900.
     *
     * Solo para rotular: millones con 1 decimal (sin ceros sobrantes), miles sin decimales.
     *
     * @param  float  $value  Importe.
     */
    public function compactMoney(float $value): string
    {
        $abs = abs($value);
        $sign = $value < 0 ? '-' : '';

        return match (true) {
            $abs >= 1_000_000 => $sign.'$'.rtrim(rtrim(number_format($abs / 1_000_000, 1, '.', ','), '0'), '.').'M',
            $abs >= 1_000 => $sign.'$'.number_format($abs / 1_000, 0, '.', ',').'K',
            default => $sign.'$'.number_format($abs, 0, '.', ','),
        };
    }

    /**
     * Etiqueta de apertura <svg> responsiva con el viewBox indicado.
     *
     * @param  int  $w  Ancho del viewBox.
     * @param  int  $h  Alto del viewBox.
     */
    private function open(int $w, int $h): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$w.' '.$h.'" width="100%" preserveAspectRatio="xMidYMid meet" role="img">';
    }

    /**
     * Líneas horizontales de cuadrícula con su etiqueta de eje Y (la línea del 0 se resalta).
     *
     * @param  float  $min  Mínimo de la escala.
     * @param  float  $max  Máximo de la escala.
     * @param  float  $step  Paso entre marcas.
     * @param  float  $x1  X inicial del área de trazo.
     * @param  float  $x2  X final del área de trazo.
     * @param  callable  $y  Convierte valor → coordenada Y.
     * @return string Fragmento SVG.
     */
    private function yGrid(float $min, float $max, float $step, float $x1, float $x2, callable $y): string
    {
        $out = '';
        // Marcas en múltiplos "redondos" del paso (1/2/5 × 10^n): nunca
        // etiquetas engañosas como "$2.3M" para 2,250,000.
        $steps = (int) round(($max - $min) / $step);
        for ($i = 0; $i <= $steps; $i++) {
            $v = $min + $step * $i;
            $yy = round($y($v), 1);
            $out .= '<line x1="'.$x1.'" y1="'.$yy.'" x2="'.$x2.'" y2="'.$yy.'" stroke="'.($v == 0.0 ? '#94a3b8' : self::GRID).'" stroke-width="'.($v == 0.0 ? 1 : .7).'"/>';
            $out .= $this->text($x1 - 6, $yy + 3, $this->compactMoney($v), 8.5, 'end', self::AXIS);
        }

        return $out;
    }

    /**
     * Escala "redonda" para el eje: paso 1/2/5/10 × 10^n con a lo más ~5 marcas,
     * mínimo y máximo ajustados a múltiplos del paso. Si min = max amplía en 1.
     *
     * @param  float  $min  Valor mínimo de los datos.
     * @param  float  $max  Valor máximo de los datos.
     * @return array{0: float, 1: float, 2: float} [min, max, paso]
     */
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

    /**
     * Leyenda alineada a la derecha (cuadro de color + nombre por serie).
     *
     * @param  array<int, array{name: string, color: string}>  $series  Series a listar.
     * @param  float  $rightX  Borde derecho donde termina la leyenda.
     * @param  float  $y  Línea base vertical.
     * @return string Fragmento SVG.
     */
    private function legend(array $series, float $rightX, float $y): string
    {
        $out = '';
        $x = $rightX;
        foreach (array_reverse($series) as $s) {
            $w = 18 + mb_strlen($s['name']) * 6.2;
            $x -= $w;
            $out .= '<rect x="'.round($x, 1).'" y="'.($y - 7).'" width="9" height="9" rx="2" fill="'.$s['color'].'"/>';
            $out .= $this->text($x + 13, $y + 1, $s['name'], 9, 'start', '#334155', 600);
            $x -= 10;
        }

        return $out;
    }

    /**
     * Trazo "d" de un sector de anillo (dona) entre dos ángulos.
     *
     * @param  float  $cx  Centro X.
     * @param  float  $cy  Centro Y.
     * @param  float  $r  Radio exterior.
     * @param  float  $ri  Radio interior.
     * @param  float  $a0  Ángulo inicial (radianes).
     * @param  float  $a1  Ángulo final (radianes).
     * @return string Atributo d del <path>.
     */
    private function arc(float $cx, float $cy, float $r, float $ri, float $a0, float $a1): string
    {
        $large = ($a1 - $a0) > M_PI ? 1 : 0;
        $p = fn (float $rad, float $a) => round($cx + $rad * cos($a), 2).','.round($cy + $rad * sin($a), 2);

        return 'M'.$p($r, $a0).' A'.$r.','.$r.' 0 '.$large.' 1 '.$p($r, $a1)
            .' L'.$p($ri, $a1).' A'.$ri.','.$ri.' 0 '.$large.' 0 '.$p($ri, $a0).' Z';
    }

    /**
     * Elemento <text> con el texto escapado.
     *
     * @param  float  $x  Posición X.
     * @param  float  $y  Posición Y (línea base).
     * @param  string  $value  Texto a mostrar.
     * @param  float  $size  Tamaño de fuente.
     * @param  string  $anchor  text-anchor (start, middle, end).
     * @param  string  $color  Color de relleno.
     * @param  int  $weight  Peso de fuente.
     */
    private function text(float $x, float $y, string $value, float $size, string $anchor, string $color, int $weight = 500): string
    {
        return '<text x="'.round($x, 1).'" y="'.round($y, 1).'" font-family="'.self::FONT.'" font-size="'.$size.'" font-weight="'.$weight.'" fill="'.$color.'" text-anchor="'.$anchor.'">'.$this->esc($value).'</text>';
    }

    /**
     * Escapa texto para XML/SVG.
     *
     * @param  string  $v  Texto original.
     */
    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
