<?php

namespace App\Services\EstadoResultados\Export;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel del Estado de Resultados (modelo de EBITDA único). NO recalcula reglas: escribe el
 * dataset resuelto (el mismo de la web y el PDF, ya redactado) y las comparativas de
 * ComparisonService. Los subtotales y resultados son FÓRMULAS sobre las celdas del propio
 * libro (EBITDA = Ingresos − OPEX, …) y cada una lleva una columna de CONTROL contra la cifra
 * del dataset (debe dar ✓). Un dato pendiente se escribe como texto «Pendiente» / «Sin
 * información»; las fórmulas lo tratan explícitamente (ISNUMBER / N()), nunca en silencio.
 *
 * Hojas: 1 Resumen ejecutivo · 2 Estado de Resultados · 3 Comparativa mensual · 4 Evolución
 * histórica · 5 Sucursales · 6 Conciliación y trazabilidad · anexos de detalle.
 */
class EstadoResultadosExcelExporter
{
    public const SHEETS = [
        'resumen' => 'Resumen ejecutivo',
        'er' => 'Estado de Resultados',
        'comparativa' => 'Comparativa mensual',
        'evolucion' => 'Evolución histórica',
        'sucursales' => 'Sucursales',
        'conciliacion' => 'Conciliación y trazabilidad',
    ];

    private const MONEY = '$#,##0.00;[Red]-$#,##0.00;$0.00';

    private const MONEY_DELTA = '+$#,##0.00;[Red]-$#,##0.00;$0.00';

    private const PCT = '0.00%;[Red]-0.00%';

    private const PCT_DELTA = '+0.00%;[Red]-0.00%;0.00%';

    private const PP = '+0.00" pp";[Red]-0.00" pp";0.00" pp"';

    private const CONTROL = '0.00;-0.00;"✓"';

    private const FONT = 'Montserrat';

    private const TEAL = '0F766E';

    private const TEAL_DARK = '0B4F4A';

    private const GREEN = '10B981';

    private const INK = '0F172A';

    private const MUTED = '64748B';

    private const SOFT = 'ECFDF5';

    private const AMBER = 'B45309';

    private const BAD = 'B91C1C';

    private const SERIES = [
        'ingresos_operativos' => '10B981', 'opex_total' => 'F97316', 'ebitda' => '0F766E', 'ebit' => '0891B2',
        'ebt' => '7C3AED', 'utilidad_neta' => 'D97706', 'margen_ebitda' => '0F766E', 'margen_ebit' => '0891B2', 'margen_neto' => 'D97706',
    ];

    private const PALETTE = ['0F766E', '06B6D4', '10B981', '7C3AED', 'F59E0B', 'EF4444', '64748B', '0EA5E9'];

    private array $d;

    private array $x;

    /** Celda (en «Estado de Resultados») de cada resultado del modelo: key => 'C12'. */
    private array $erCells = [];

    public function __construct(private readonly EstadoResultadosExportContext $context) {}

    public function export(array $dataset, string $outputPath, ?array $comparisons = null, ?string $comparisonsError = null): string
    {
        $this->d = $dataset;
        $this->x = $this->context->make($dataset, $comparisons, $comparisonsError);
        $this->erCells = [];
        $book = new Spreadsheet;
        $book->getDefaultStyle()->getFont()->setName(self::FONT)->setSize(10);
        $book->getProperties()->setCreator('MR LANA · Reportes')->setTitle('Estado de Resultados '.$this->x['meta']['period']['label']);

        $book->removeSheetByIndex(0);
        $ws = [];
        foreach (self::SHEETS as $k => $title) {
            $ws[$k] = $book->createSheet()->setTitle($title);
        }
        // El ER primero: el resumen referencia sus celdas.
        $this->sheetStatement($ws['er']);
        $this->sheetSummary($ws['resumen']);
        $this->sheetComparative($ws['comparativa']);
        $this->sheetEvolution($ws['evolucion']);
        $this->sheetBranches($ws['sucursales']);
        $this->sheetControl($ws['conciliacion']);

        // Anexos de detalle (sin números de cuenta completos; nómina/DG respetan la redacción).
        $this->sheetIndicators($book->createSheet()->setTitle('Anexo Indicadores'));
        $this->sheetLines($book->createSheet()->setTitle('Anexo OPEX sucursales'), 'DETALLE · GASTOS OPERATIVOS DE SUCURSALES', ['opex_sucursales']);
        $this->sheetItems($book->createSheet()->setTitle('Anexo OPEX corporativo'), 'DETALLE · GASTOS CORPORATIVOS', fn ($m) => $m['treatment'] === 'opex_corporativo' || ($m['treatment'] === 'pendiente' && ($m['branch_kind'] ?? null) === 'corporativo' && ! str_contains((string) $m['rule_code'], 'dg')));
        $this->sheetPayroll($book->createSheet()->setTitle('Anexo Nómina'));
        $this->sheetItems($book->createSheet()->setTitle('Anexo DG'), 'DETALLE · DIRECCIÓN GENERAL', fn ($m) => in_array($m['treatment'], ['dg_operativo', 'dg_remuneracion'], true) || in_array($m['rule_code'], ['dg_requiere_revision', 'erp_dg'], true), 'dg');
        $this->sheetMovements($book->createSheet()->setTitle('Anexo Movimientos'));
        $this->sheetAdjustments($book->createSheet()->setTitle('Anexo Ajustes'));
        $this->sheetOutside($book->createSheet()->setTitle('Anexo Fuera del ER'));
        $book->setActiveSheetIndex(0);

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $prev = ini_get('precision');
        ini_set('precision', '-1');
        try {
            $writer = new Xlsx($book);
            $writer->setIncludeCharts(true);
            $writer->save($outputPath);
        } finally {
            ini_set('precision', (string) $prev);
            $book->disconnectWorksheets();
        }

        return $outputPath;
    }

    // ── 2 · Estado de Resultados ────────────────────────────────────────

    private function sheetStatement(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 62, 'C' => 20, 'D' => 12, 'E' => 11, 'F' => 46, 'G' => 18]);
        $this->banner($ws, 'ESTADO DE RESULTADOS', 'G');
        if ($this->x['legacy']) {
            $this->note($ws, 'B5', $this->x['legacy_notice'], self::AMBER);
        }
        foreach (['B6' => 'CONCEPTO', 'C6' => 'IMPORTE (MXN)', 'D6' => '% INGRESOS', 'E6' => 'CONTROL', 'F6' => 'FUENTE / NOTA'] as $c => $t) {
            $this->put($ws, $c, $t);
        }
        $this->headerRow($ws, 'B6:F6');
        $ws->freezePane('A7');

        $r = 7;
        $ing = null;
        $pctRows = [];
        foreach ($this->x['statement'] as $block) {
            if (($block['type'] ?? '') === 'section') {
                $this->put($ws, "B{$r}", ($block['number'] ?? '').'   '.($block['title'] ?? ''));
                $ws->getStyle("B{$r}:F{$r}")->getFont()->setBold(true)->getColor()->setRGB(self::TEAL);
                $ws->getStyle("B{$r}:F{$r}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::GREEN);
                $r++;
                if (! ($block['applies'] ?? true)) {
                    $this->note($ws, "B{$r}", (string) ($block['note'] ?? ''));
                    $r += 2;

                    continue;
                }
                $partRows = [];
                if (! empty($block['categories']) && ! $this->x['legacy']) {
                    foreach ($block['categories'] as $cat) {
                        $this->put($ws, "B{$r}", '   '.$cat['label'].(in_array($cat['key'], ['opex_corporativo', 'nomina_corporativa', 'dg'], true) ? '  · CENTRALIZADO (no se asigna a sucursales)' : ''));
                        $ws->getStyle("B{$r}")->getFont()->setBold(true)->getColor()->setRGB('0E7490');
                        $r++;
                        [$first, $last, $r] = $this->writeLines($ws, $r, $cat['lines'], '      ', $pctRows);
                        $this->put($ws, "B{$r}", '   '.$cat['total']['label']);
                        $this->formulaOrValue($ws, "C{$r}", $last >= $first ? "=SUM(C{$first}:C{$last})" : null, $cat['total']['amount']);
                        $this->control($ws, $r, $cat['total']['amount']);
                        $ws->getStyle("B{$r}:D{$r}")->getFont()->setBold(true);
                        $ws->getStyle("B{$r}:D{$r}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB(self::MUTED);
                        $pctRows[] = $r;
                        $partRows[] = $r;
                        $r++;
                    }
                    $totalFormula = $partRows ? '='.implode('+', array_map(fn ($x) => "C{$x}", $partRows)) : null;
                } else {
                    [$first, $last, $r] = $this->writeLines($ws, $r, $block['lines'] ?? [], '   ', $pctRows);
                    $totalFormula = $last >= $first ? "=SUM(C{$first}:C{$last})" : null;
                }
                $total = $block['total']['amount'] ?? null;
                $unknown = collect($block['lines'] ?? [])->contains(fn ($l) => ($l['state'] ?? null) === 'sin_informacion');
                $this->put($ws, "B{$r}", $block['total']['label'] ?? 'TOTAL');
                if ($total === null) {
                    $this->put($ws, "C{$r}", ErFormat::PENDING);
                    $this->put($ws, "F{$r}", (string) ($block['note'] ?? 'Sin información: no se asume $0.00.'));
                } elseif ($unknown && (float) $total == 0.0) {
                    // Un dato desconocido nunca se escribe como $0.00.
                    $this->put($ws, "C{$r}", 'Sin información');
                    $this->put($ws, "F{$r}", 'Ajuste sin capturar: el resultado que depende de él se marca incompleto.');
                } else {
                    $this->formulaOrValue($ws, "C{$r}", $this->x['legacy'] ? null : $totalFormula, $total);
                    $this->control($ws, $r, $total);
                    if ($unknown) {
                        $this->put($ws, "F{$r}", 'Incompleto: hay ajustes sin información.');
                    }
                }
                $pctRows[] = $r;
                $ws->getStyle("B{$r}:D{$r}")->getFont()->setBold(true);
                $ws->getStyle("B{$r}:D{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::SOFT);
                $ws->getStyle("B{$r}:D{$r}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::TEAL);
                $stepKey = ['ingresos' => 'ingresos_operativos', 'opex' => 'opex_total', 'da' => 'depreciacion_amortizacion', 'financiero' => 'resultado_financiero_neto', 'isr' => 'isr'][$block['key'] ?? ''] ?? null;
                if ($stepKey && ! $this->x['legacy']) {
                    $this->erCells[$stepKey] = "C{$r}";
                }
                if ($stepKey === 'ingresos_operativos') {
                    $ing = "C{$r}";
                }
                $r += 2;

                continue;
            }

            // Bloque de resultado.
            $key = $block['key'] ?? '';
            $label = ($block['label'] ?? '').(($block['complete'] ?? true) || ($block['amount'] ?? null) === null ? '' : '  (INCOMPLETO)');
            $this->put($ws, "B{$r}", $label);
            $formula = $this->x['legacy'] ? null : $this->resultFormula($key);
            if (($block['amount'] ?? null) === null && $formula === null) {
                $this->put($ws, "C{$r}", ErFormat::PENDING);
            } else {
                $this->formulaOrValue($ws, "C{$r}", $formula, $block['amount'] ?? null);
                if (($block['amount'] ?? null) !== null) {
                    $this->control($ws, $r, $block['amount']);
                }
            }
            if (! $this->x['legacy'] && in_array($key, ['ebitda', 'ebit', 'ebt', 'utilidad_neta'], true)) {
                $this->erCells[$key] = "C{$r}";
            }
            $pctRows[] = $r;
            $fill = $key === 'utilidad_neta' ? '065F46' : ($key === 'ebitda' ? self::TEAL : ($key === 'ebt' ? self::TEAL_DARK : '0E7490'));
            $ws->getStyle("B{$r}:D{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
            $ws->getStyle("B{$r}:D{$r}")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('FFFFFF');
            $ws->getRowDimension($r)->setRowHeight(22);
            $ws->getStyle("B{$r}:D{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $r++;
            $missing = ($block['complete'] ?? true) ? '' : ' · Falta: '.implode(', ', (array) ($block['missing'] ?? []));
            $this->put($ws, "B{$r}", '   '.($block['formula'] ?? '').$missing.(! empty($block['note']) ? ' · '.$block['note'] : ''));
            $ws->getStyle("B{$r}")->getFont()->setItalic(true)->setSize(8)->getColor()->setRGB(self::MUTED);
            $r += 2;
        }

        // % sobre ingresos: fórmula propia (sólo con ingresos ≠ 0 y celda numérica).
        if ($ing) {
            foreach ($pctRows as $pr) {
                $ws->setCellValue("D{$pr}", "=IF(AND(ISNUMBER(C{$pr}),N({$ing})<>0),C{$pr}/{$ing},\"—\")");
                $ws->getStyle("D{$pr}")->getNumberFormat()->setFormatCode(self::PCT);
                $ws->getStyle("D{$pr}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
        }
        $this->note($ws, 'B'.($r + 1), 'CONTROL = fórmula del libro − cifra del dataset (✓ = $0.00). «Pendiente» / «Sin información» nunca equivalen a $0.00. CAPEX, capital de deuda y gastos personales quedan fuera del Estado de Resultados.');
        $ws->getPageSetup()->setPrintArea('A1:G'.($r + 1));
    }

    /** @return array{int,int,int} [primera fila, última fila, siguiente fila] */
    private function writeLines(Worksheet $ws, int $r, array $lines, string $indent, array &$pctRows): array
    {
        $first = $r;
        foreach ($lines as $line) {
            $state = $line['state'] ?? null;
            $this->put($ws, "B{$r}", $indent.$line['label'].($state === 'sin_informacion' ? '  · SIN INFORMACIÓN' : ''));
            if ($state === 'sin_informacion') {
                $this->put($ws, "C{$r}", 'Sin información');
                $ws->getStyle("B{$r}:D{$r}")->getFont()->getColor()->setRGB(self::AMBER);
            } else {
                $this->money($ws, "C{$r}", $line['amount']);
            }
            if ($state === 'restringido') {
                $ws->getStyle("B{$r}")->getFont()->setItalic(true)->getColor()->setRGB(self::MUTED);
            }
            $this->put($ws, "F{$r}", trim(($line['source'] ?? '').(! empty($line['hint']) ? ' · '.$line['hint'] : '')));
            $ws->getStyle("F{$r}")->getFont()->setSize(8)->getColor()->setRGB(self::MUTED);
            $pctRows[] = $r;
            $r++;
        }

        return [$first, $r - 1, $r];
    }

    /** Fórmulas de los resultados del modelo sobre las celdas del libro (pendiente explícito). */
    private function resultFormula(string $key): ?string
    {
        $c = $this->erCells;

        return match ($key) {
            'ebitda' => isset($c['ingresos_operativos'], $c['opex_total']) ? "={$c['ingresos_operativos']}-{$c['opex_total']}" : null,
            // Un ajuste «Sin información» se toma como 0 de forma EXPLÍCITA (N) y el renglón se rotula INCOMPLETO, igual que la web.
            'ebit' => isset($c['ebitda'], $c['depreciacion_amortizacion']) ? "={$c['ebitda']}-N({$c['depreciacion_amortizacion']})" : null,
            'ebt' => isset($c['ebit'], $c['resultado_financiero_neto']) ? "={$c['ebit']}+N({$c['resultado_financiero_neto']})" : null,
            'utilidad_neta' => isset($c['ebt'], $c['isr']) ? "=IF(ISNUMBER({$c['isr']}),{$c['ebt']}-{$c['isr']},\"Pendiente\")" : null,
            default => null,
        };
    }

    // ── 1 · Resumen ejecutivo ──────────────────────────────────────────

    private function sheetSummary(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 40, 'C' => 19, 'D' => 19, 'E' => 18, 'F' => 12, 'G' => 15, 'H' => 52, 'I' => 3, 'J' => 22, 'K' => 16, 'L' => 16, 'M' => 16, 'N' => 16, 'O' => 16, 'P' => 16]);
        $this->banner($ws, 'RESUMEN EJECUTIVO', 'H');
        $pair = $this->x['comparisons']['pairs']['anterior'] ?? null;
        $prevLabel = $pair ? mb_strtoupper($pair['comparison_label']) : 'PERIODO ANTERIOR';
        if ($this->x['legacy']) {
            $this->note($ws, 'B5', $this->x['legacy_notice'], self::AMBER);
        } elseif ($n = $this->x['comparisons']['notice'] ?? null) {
            $this->note($ws, 'B5', $n, self::AMBER);
        }
        foreach (['B6' => 'INDICADOR', 'C6' => 'ACTUAL', 'D6' => $prevLabel, 'E6' => 'VAR $', 'F6' => 'VAR %', 'G6' => 'EVALUACIÓN', 'H6' => 'NOTA'] as $c => $t) {
            $this->put($ws, $c, $t);
        }
        $this->headerRow($ws, 'B6:H6');
        $ws->freezePane('A7');

        $rowOf = fn (string $k) => $pair ? collect($pair['rows'])->firstWhere('key', $k) : null;
        $r = 7;
        $cell = [];
        foreach ($this->x['steps'] as $s) {
            $this->put($ws, "B{$r}", $s['step'].'. '.$s['label']);
            if (isset($this->erCells[$s['key']])) {
                $ws->setCellValue("C{$r}", "='".self::SHEETS['er']."'!".$this->erCells[$s['key']]);
                $ws->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::MONEY);
            } else {
                $s['amount'] === null ? $this->put($ws, "C{$r}", ErFormat::PENDING) : $this->money($ws, "C{$r}", $s['amount']);
            }
            $cell[$s['key']] = "C{$r}";
            $row = $rowOf($s['key']);
            $this->comparisonCells($ws, $r, $row, $pair, false);
            $notes = [];
            if (! $s['complete'] && ! $s['pending']) {
                $notes[] = 'Incompleto'.($s['missing'] ? ': falta '.implode(', ', $s['missing']) : '');
            }
            if ($s['pending']) {
                $notes[] = 'Pendiente: sin información (no se asume $0.00)';
            }
            if ($row && $row['reason'] && $row['reason']['code'] !== 'margen_en_puntos') {
                $notes[] = $row['reason']['message'];
            }
            $this->put($ws, "H{$r}", implode(' · ', $notes));
            if ($s['is_result']) {
                $ws->getStyle("B{$r}:G{$r}")->getFont()->setBold(true);
                $ws->getStyle("B{$r}:G{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::SOFT);
            }
            $r++;
        }
        $r++;
        $this->put($ws, "B{$r}", 'MÁRGENES (% SOBRE INGRESOS OPERATIVOS)');
        $this->headerRow($ws, "B{$r}:H{$r}");
        $r++;
        $marginKeys = ['ebitda' => 'margen_ebitda', 'ebit' => 'margen_ebit', 'ebt' => null, 'neto' => 'margen_neto'];
        $of = ['ebitda' => 'ebitda', 'ebit' => 'ebit', 'ebt' => 'ebt', 'neto' => 'utilidad_neta'];
        foreach (EstadoResultadosExportContext::MARGINS as $k => $label) {
            $this->put($ws, "B{$r}", $label);
            $ws->setCellValue("C{$r}", "=IF(AND(ISNUMBER({$cell[$of[$k]]}),N({$cell['ingresos_operativos']})<>0),{$cell[$of[$k]]}/{$cell['ingresos_operativos']},\"Pendiente\")");
            $ws->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::PCT);
            $ws->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $row = $marginKeys[$k] ? $rowOf($marginKeys[$k]) : null;
            $this->comparisonCells($ws, $r, $row, $pair, true);
            $r++;
        }
        $r++;
        $this->note($ws, "B{$r}", 'ACTUAL se lee con fórmulas de la hoja «'.self::SHEETS['er'].'». La comparación usa la misma fuente que la web (versión aprobada o cálculo en vivo de cada periodo). Var % no se calcula con base $0.00, datos pendientes o periodos no comparables; los márgenes se comparan en puntos porcentuales.');
        $chartTop = $r + 2;

        // Datos de gráficas (mismas cifras; celdas vacías = pendiente).
        $this->put($ws, 'J6', 'DATOS DE GRÁFICAS');
        $this->headerRow($ws, 'J6:P6');
        $this->put($ws, 'J7', 'Concepto');
        $this->put($ws, 'K7', 'Importe');
        $rr = 8;
        foreach (['ingresos_operativos', 'opex_total', 'ebitda', 'ebit', 'ebt', 'utilidad_neta'] as $k) {
            $this->put($ws, "J{$rr}", ['ingresos_operativos' => 'Ingresos', 'opex_total' => 'OPEX', 'ebitda' => 'EBITDA', 'ebit' => 'EBIT', 'ebt' => 'EBT', 'utilidad_neta' => 'Utilidad neta'][$k]);
            $this->money($ws, "K{$rr}", $this->x['results'][$k]);
            $rr++;
        }
        ErExcelCharts::add($ws, DataSeries::TYPE_BARCHART, 'Resultados del periodo · '.$this->x['meta']['period']['label'], 'J', 7, 8, $rr - 1,
            [['col' => 'K', 'color' => self::TEAL]], "B{$chartTop}", 'E'.($chartTop + 18));

        // Cascada nativa: columnas apiladas con base transparente (cruces de cero en serie aparte).
        $wr = $rr + 2;
        foreach (['J' => 'Paso', 'K' => 'Base', 'L' => 'Total / subtotal', 'M' => 'Aumento', 'N' => 'Disminución', 'O' => 'Tramo bajo cero'] as $c => $t) {
            $this->put($ws, "{$c}{$wr}", $t);
        }
        $first = $wr + 1;
        $row = $first;
        foreach ($this->x['cascade'] as $st) {
            $this->put($ws, "J{$row}", $st['label'].($st['pending'] ? ' (pendiente)' : ''));
            if (! $st['pending'] && $st['start'] !== null && $st['end'] !== null) {
                [$base, $tot, $up, $down, $cross] = $this->waterfallParts($st);
                foreach (['K' => $base, 'L' => $tot, 'M' => $up, 'N' => $down, 'O' => $cross] as $c => $v) {
                    if ($v !== null) {
                        $this->put($ws, "{$c}{$row}", $v);
                        $ws->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode(self::MONEY);
                    }
                }
            }
            $row++;
        }
        ErExcelCharts::add($ws, DataSeries::TYPE_BARCHART, 'Cascada del resultado', 'J', $wr, $first, $row - 1, [
            ['col' => 'K', 'color' => 'FFFFFF', 'transparent' => true],
            ['col' => 'L', 'color' => self::TEAL],
            ['col' => 'M', 'color' => self::GREEN],
            ['col' => 'N', 'color' => 'EF4444'],
            ['col' => 'O', 'color' => 'F87171'],
        ], "F{$chartTop}", 'H'.($chartTop + 18), '"$"#,##0', DataSeries::GROUPING_STACKED);
        $this->note($ws, 'J'.($row + 1), 'La serie «Base» es transparente (técnica de cascada con columnas apiladas). Un paso pendiente queda vacío.');
    }

    /** Partes de un paso de cascada para columnas apiladas: [base, total, aumento, disminución, tramo bajo cero]. */
    private function waterfallParts(array $st): array
    {
        $s = round((float) $st['start'], 2);
        $e = round((float) $st['end'], 2);
        if ($st['type'] !== 'delta') {
            return [null, $e, null, null, null];
        }
        $up = $e >= $s;
        if (($s >= 0 && $e >= 0) || ($s <= 0 && $e <= 0)) {
            $base = $s >= 0 && $e >= 0 ? min($s, $e) : max($s, $e);
            $size = round(abs($e - $s), 2) * ($s >= 0 && $e >= 0 ? 1 : -1);

            return [$base == 0.0 ? null : $base, null, $up ? $size : null, $up ? null : $size, null];
        }
        // Cruza el cero: tramo positivo en su serie y tramo negativo aparte.
        $pos = max($s, $e);
        $negPart = min($s, $e);

        return [null, null, $up ? $pos : null, $up ? null : $pos, $negPart];
    }

    private function comparisonCells(Worksheet $ws, int $r, ?array $row, ?array $pair, bool $isMargin): void
    {
        if (! $pair || ! $row) {
            $this->put($ws, "D{$r}", $pair && ! $pair['available'] ? ErFormat::NO_DATA : '—');
            $this->put($ws, "E{$r}", '—');
            $this->put($ws, "F{$r}", '—');
            $this->put($ws, "G{$r}", '—');
            foreach (['D', 'E', 'F'] as $c) {
                $ws->getStyle("{$c}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }

            return;
        }
        if ($isMargin) {
            $row['previous'] === null ? $this->put($ws, "D{$r}", $pair['available'] ? ErFormat::PENDING : ErFormat::NO_DATA) : $this->pct($ws, "D{$r}", $row['previous']);
            if ($row['variation'] === null) {
                $this->put($ws, "E{$r}", '—');
            } else {
                $this->put($ws, "E{$r}", (float) $row['variation']);
                $ws->getStyle("E{$r}")->getNumberFormat()->setFormatCode(self::PP);
            }
        } else {
            $row['previous'] === null ? $this->put($ws, "D{$r}", $pair['available'] ? ErFormat::PENDING : ErFormat::NO_DATA) : $this->money($ws, "D{$r}", $row['previous']);
            $ws->setCellValue("E{$r}", "=IF(AND(ISNUMBER(C{$r}),ISNUMBER(D{$r})),ROUND(C{$r}-D{$r},2),\"—\")");
            $ws->getStyle("E{$r}")->getNumberFormat()->setFormatCode(self::MONEY_DELTA);
        }
        if ($row['variation_pct'] === null) {
            $this->put($ws, "F{$r}", '—');
        } else {
            $this->put($ws, "F{$r}", round((float) $row['variation_pct'] / 100, 6));
            $ws->getStyle("F{$r}")->getNumberFormat()->setFormatCode(self::PCT_DELTA);
        }
        foreach (['D', 'E', 'F'] as $c) {
            $ws->getStyle("{$c}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $this->put($ws, "G{$r}", $row['assessment_label'] ?? '—');
        $color = match ($row['assessment'] ?? null) {
            'favorable' => '047857', 'desfavorable' => self::BAD, default => self::MUTED
        };
        $ws->getStyle("G{$r}")->getFont()->setBold(true)->getColor()->setRGB($color);
    }

    // ── 3 · Comparativa mensual ────────────────────────────────────────

    private function sheetComparative(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 42, 'C' => 19, 'D' => 19, 'E' => 18, 'F' => 12, 'G' => 15, 'H' => 56, 'I' => 3, 'J' => 18, 'K' => 16, 'L' => 16]);
        $this->banner($ws, 'COMPARATIVA MENSUAL', 'H');
        $ws->freezePane('A6');
        $cmp = $this->x['comparisons'];
        if (! $cmp['available']) {
            $this->note($ws, 'B6', (string) $cmp['error'], self::AMBER);

            return;
        }
        $r = 6;
        if ($cmp['notice']) {
            $this->note($ws, "B{$r}", $cmp['notice'], self::AMBER);
            $r += 2;
        }
        $chartBlock = null;
        foreach (['anterior', 'anio_anterior', 'acumulado'] as $k) {
            $p = $cmp['pairs'][$k] ?? null;
            if (! $p) {
                continue;
            }
            $this->put($ws, "B{$r}", mb_strtoupper($p['title']).($p['partial'] ? ' · PARCIAL' : ''));
            $ws->getStyle("B{$r}")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB(self::TEAL);
            $r++;
            $meta = $p['current_label'].' vs '.$p['comparison_label'].($p['comparison_source'] ? ' · comparación: '.$p['comparison_source'] : '').($p['comparison_status'] ? ' ('.$p['comparison_status'].')' : '');
            $this->note($ws, "B{$r}", $meta);
            $r++;
            if ($p['partial']) {
                $this->note($ws, "B{$r}", 'Acumulado parcial: incluye sólo los meses con datos'.($p['missing_months'] ? '; faltan '.implode(', ', $p['missing_months']) : '').'. No representa el año completo.', self::AMBER);
                $r++;
            }
            if (! $p['available']) {
                $this->note($ws, "B{$r}", $p['unavailable'] ?? 'Sin datos de comparación.', self::AMBER);
                $r += 2;

                continue;
            }
            if (! $p['comparable'] && $p['reasons']) {
                $this->note($ws, "B{$r}", 'No comparable: '.implode(' ', $p['reasons']), self::AMBER);
                $r++;
            }
            foreach (['B' => 'CONCEPTO', 'C' => 'ACTUAL', 'D' => 'COMPARACIÓN', 'E' => 'VAR $ / PP', 'F' => 'VAR %', 'G' => 'EVALUACIÓN', 'H' => 'NOTA'] as $c => $t) {
                $this->put($ws, "{$c}{$r}", $t);
            }
            $this->headerRow($ws, "B{$r}:H{$r}");
            $r++;
            $start = $r;
            foreach ($p['rows'] as $row) {
                $isPct = $row['unit'] === '%';
                $this->put($ws, "B{$r}", $row['label']);
                if ($row['current'] === null) {
                    $this->put($ws, "C{$r}", ErFormat::PENDING);
                } else {
                    $isPct ? $this->pct($ws, "C{$r}", $row['current']) : $this->money($ws, "C{$r}", $row['current']);
                }
                $this->comparisonCells($ws, $r, $row, $p, $isPct);
                $this->put($ws, "H{$r}", $row['reason'] && $row['reason']['code'] !== 'margen_en_puntos' ? $row['reason']['message'] : '');
                $ws->getStyle("H{$r}")->getFont()->setSize(8)->getColor()->setRGB(self::MUTED);
                if (in_array($row['key'], ['ebitda', 'ebit', 'ebt', 'utilidad_neta'], true)) {
                    $ws->getStyle("B{$r}:G{$r}")->getFont()->setBold(true);
                    $ws->getStyle("B{$r}:G{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::SOFT);
                }
                $r++;
            }
            if ($k === 'anterior') {
                $chartBlock = [$p, $start];
            }
            $r += 2;
        }

        // Gráfica nativa: actual vs anterior (importes clave).
        if ($chartBlock) {
            [$p] = $chartBlock;
            $this->put($ws, 'J6', 'Concepto');
            $this->put($ws, 'K6', mb_substr($p['current_label'], 0, 30));
            $this->put($ws, 'L6', mb_substr($p['comparison_label'], 0, 30));
            $this->headerRow($ws, 'J6:L6');
            $rr = 7;
            foreach (['ingresos_operativos', 'opex_total', 'ebitda', 'ebit', 'ebt', 'utilidad_neta'] as $key) {
                $row = collect($p['rows'])->firstWhere('key', $key);
                $this->put($ws, "J{$rr}", $row['label'] ?? $key);
                $this->money($ws, "K{$rr}", $row['current'] ?? null);
                $this->money($ws, "L{$rr}", $row['previous'] ?? null);
                $rr++;
            }
            ErExcelCharts::add($ws, DataSeries::TYPE_BARCHART, 'Actual vs periodo anterior', 'J', 6, 7, $rr - 1,
                [['col' => 'K', 'color' => self::TEAL], ['col' => 'L', 'color' => '94A3B8']], 'J'.($rr + 1), 'P'.($rr + 20));
        }
    }

    // ── 4 · Evolución histórica ────────────────────────────────────────

    private function sheetEvolution(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 18, 'C' => 22, 'D' => 20, 'E' => 17, 'F' => 17, 'G' => 17, 'H' => 17, 'I' => 17, 'J' => 17, 'K' => 12, 'L' => 12, 'M' => 12, 'N' => 3, 'O' => 16, 'P' => 15, 'Q' => 15, 'R' => 15, 'S' => 15, 'T' => 15, 'U' => 15, 'V' => 12, 'W' => 12, 'X' => 12]);
        $this->banner($ws, 'EVOLUCIÓN HISTÓRICA', 'M');
        $evo = $this->x['comparisons']['evolution'] ?? null;
        if (! $evo) {
            $this->note($ws, 'B6', (string) ($this->x['comparisons']['error'] ?? 'Sin evolución disponible.'), self::AMBER);

            return;
        }
        $this->note($ws, 'B5', $evo['label'].' · '.$evo['range']['from'].' a '.$evo['range']['to'].' · '.$evo['note']);
        $amountKeys = ['ingresos_operativos' => 'INGRESOS', 'opex_total' => 'OPEX', 'ebitda' => 'EBITDA', 'ebit' => 'EBIT', 'ebt' => 'EBT', 'utilidad_neta' => 'UTILIDAD NETA'];
        $marginKeys = ['margen_ebitda' => 'MARGEN EBITDA', 'margen_ebit' => 'MARGEN EBIT', 'margen_neto' => 'MARGEN NETO'];
        $headers = ['B' => 'PERIODO', 'C' => 'ESTADO', 'D' => 'ORIGEN'];
        $col = 'E';
        foreach ($amountKeys + $marginKeys as $t) {
            $headers[$col] = $t;
            $col++;
        }
        foreach ($headers as $c => $t) {
            $this->put($ws, "{$c}6", $t);
        }
        $this->headerRow($ws, 'B6:M6');
        $ws->freezePane('C7');
        $r = 7;
        foreach ($evo['periods'] as $p) {
            $this->put($ws, "B{$r}", $p['period']['label']);
            $this->put($ws, "C{$r}", $p['status_label']);
            $this->put($ws, "D{$r}", $p['source_label'] ?? ($p['unavailable_reason']['message'] ?? '—'));
            $col = 'E';
            foreach (array_keys($amountKeys) as $k) {
                $v = $p['values'][$k] ?? null;
                $v === null ? $this->put($ws, "{$col}{$r}", $p['available'] ? ErFormat::PENDING : ErFormat::NO_DATA) : $this->money($ws, "{$col}{$r}", $v);
                $col++;
            }
            foreach (array_keys($marginKeys) as $k) {
                $v = $p['margins'][$k] ?? null;
                $v === null ? $this->put($ws, "{$col}{$r}", $p['available'] ? '—' : ErFormat::NO_DATA) : $this->pct($ws, "{$col}{$r}", $v);
                $col++;
            }
            if (! $p['available']) {
                $ws->getStyle("B{$r}:M{$r}")->getFont()->getColor()->setRGB(self::MUTED);
            }
            $r++;
        }
        $last = $r - 1;
        $ws->setAutoFilter("B6:M{$last}");
        $ws->getStyle("E7:M{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // Datos de gráficas (celdas vacías = sin datos / pendiente → hueco en la gráfica).
        $dataHeaders = ['O' => 'Periodo', 'P' => 'Ingresos operativos', 'Q' => 'OPEX total', 'R' => 'EBITDA', 'S' => 'EBIT', 'T' => 'EBT', 'U' => 'Utilidad neta', 'V' => 'Margen EBITDA', 'W' => 'Margen EBIT', 'X' => 'Margen neto'];
        foreach ($dataHeaders as $c => $t) {
            $this->put($ws, "{$c}6", $t);
        }
        $this->headerRow($ws, 'O6:X6');
        $series = $evo['series'];
        $map = ['P' => 'ingresos_operativos', 'Q' => 'opex_total', 'R' => 'ebitda', 'S' => 'ebit', 'T' => 'ebt', 'U' => 'utilidad_neta', 'V' => 'margen_ebitda', 'W' => 'margen_ebit', 'X' => 'margen_neto'];
        foreach ($evo['labels'] as $i => $label) {
            $rr = 7 + $i;
            $this->put($ws, "O{$rr}", $label);
            foreach ($map as $c => $k) {
                $v = $series[$k][$i] ?? null;
                if ($v === null) {
                    continue;
                }
                str_starts_with($k, 'margen_') ? $this->pct($ws, "{$c}{$rr}", $v) : $this->money($ws, "{$c}{$rr}", $v);
            }
        }
        $dLast = 6 + count($evo['labels']);
        $top = $last + 3;
        ErExcelCharts::add($ws, DataSeries::TYPE_LINECHART, 'Evolución de EBITDA, EBIT, EBT y utilidad neta', 'O', 6, 7, $dLast, [
            ['col' => 'R', 'color' => self::SERIES['ebitda']], ['col' => 'S', 'color' => self::SERIES['ebit']],
            ['col' => 'T', 'color' => self::SERIES['ebt']], ['col' => 'U', 'color' => self::SERIES['utilidad_neta']],
        ], "B{$top}", 'G'.($top + 20));
        ErExcelCharts::add($ws, DataSeries::TYPE_BARCHART, 'Ingresos operativos vs OPEX', 'O', 6, 7, $dLast, [
            ['col' => 'P', 'color' => self::SERIES['ingresos_operativos']], ['col' => 'Q', 'color' => self::SERIES['opex_total']],
        ], "H{$top}", 'M'.($top + 20));
        ErExcelCharts::add($ws, DataSeries::TYPE_LINECHART, 'Márgenes EBITDA, EBIT y neto', 'O', 6, 7, $dLast, [
            ['col' => 'V', 'color' => self::SERIES['margen_ebitda']], ['col' => 'W', 'color' => self::SERIES['margen_ebit']], ['col' => 'X', 'color' => self::SERIES['margen_neto']],
        ], 'B'.($top + 22), 'G'.($top + 42), '0%');

        // Composición del OPEX por periodo (categorías reales, columnas apiladas).
        $cats = $evo['opex_categories'] ?? [];
        if ($cats) {
            $base = $dLast + 3;
            $this->put($ws, "O{$base}", 'Periodo');
            $c = 'P';
            $cols = [];
            foreach ($cats as $i => $cat) {
                $this->put($ws, "{$c}{$base}", $cat['label']);
                $cols[] = ['col' => $c, 'color' => self::PALETTE[$i % count(self::PALETTE)]];
                foreach ($evo['labels'] as $j => $label) {
                    $this->put($ws, 'O'.($base + 1 + $j), $label);
                    if (($v = $cat['data'][$j] ?? null) !== null) {
                        $this->money($ws, $c.($base + 1 + $j), $v);
                    }
                }
                $c++;
            }
            $this->headerRow($ws, "O{$base}:".chr(ord('O') + count($cats))."{$base}");
            ErExcelCharts::add($ws, DataSeries::TYPE_BARCHART, 'Composición del OPEX por periodo', 'O', $base, $base + 1, $base + count($evo['labels']), $cols,
                'H'.($top + 22), 'M'.($top + 42), '"$"#,##0', DataSeries::GROUPING_STACKED);
        }
    }

    // ── 5 · Sucursales ─────────────────────────────────────────────────

    private function sheetBranches(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 30, 'C' => 22, 'D' => 18, 'E' => 18, 'F' => 18, 'G' => 13, 'H' => 13, 'I' => 18, 'J' => 18, 'K' => 15, 'L' => 3, 'M' => 22, 'N' => 16, 'O' => 16, 'P' => 16]);
        $this->banner($ws, 'SUCURSALES', 'K');
        $br = $this->x['comparisons']['branches'] ?? null;
        if (! $br || ! $br['rows']) {
            $this->note($ws, 'B6', $this->x['comparisons']['available'] ? 'Sin sucursales con datos para este alcance.' : (string) $this->x['comparisons']['error'], self::AMBER);

            return;
        }
        $this->note($ws, 'B5', $br['note']);
        $h = ['B' => 'SUCURSAL', 'C' => 'ESTADO', 'D' => 'INGRESOS OPERATIVOS', 'E' => 'OPEX DIRECTO', 'F' => 'EBITDA SUCURSAL', 'G' => 'MARGEN EBITDA', 'H' => 'PART. INGRESOS', 'I' => 'EBITDA ANTERIOR', 'J' => 'VAR EBITDA $', 'K' => 'EVALUACIÓN'];
        foreach ($h as $c => $t) {
            $this->put($ws, "{$c}6", $t);
        }
        $this->headerRow($ws, 'B6:K6');
        $ws->freezePane('C7');
        $r = 7;
        foreach ($br['rows'] as $row) {
            $this->put($ws, "B{$r}", $row['label']);
            $this->put($ws, "C{$r}", $row['status_label']);
            foreach (['D' => 'ingresos_operativos', 'E' => 'opex_total', 'F' => 'ebitda'] as $c => $k) {
                $row[$k] === null ? $this->put($ws, "{$c}{$r}", ErFormat::NO_DATA) : $this->money($ws, "{$c}{$r}", $row[$k]);
            }
            $ws->setCellValue("G{$r}", "=IF(AND(ISNUMBER(F{$r}),N(D{$r})<>0),F{$r}/D{$r},\"—\")");
            $ws->getStyle("G{$r}")->getNumberFormat()->setFormatCode(self::PCT);
            $this->pct($ws, "H{$r}", $row['participacion_ingresos']);
            $prev = $row['variation']['ebitda']['previous'] ?? null;
            $prev === null ? $this->put($ws, "I{$r}", ErFormat::NO_DATA) : $this->money($ws, "I{$r}", $prev);
            $ws->setCellValue("J{$r}", "=IF(AND(ISNUMBER(F{$r}),ISNUMBER(I{$r})),ROUND(F{$r}-I{$r},2),\"—\")");
            $ws->getStyle("J{$r}")->getNumberFormat()->setFormatCode(self::MONEY_DELTA);
            $this->put($ws, "K{$r}", $row['variation']['ebitda']['assessment_label'] ?? '—');
            $ws->getStyle("D{$r}:J{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $r++;
        }
        $last = $r - 1;
        $ws->setAutoFilter("B6:K{$last}");
        $totalRow = null;
        if ($t = $br['totals']['sucursales'] ?? null) {
            $totalRow = $r;
            $this->put($ws, "B{$r}", $t['label']);
            foreach (['D', 'E', 'F'] as $c) {
                $ws->setCellValue("{$c}{$r}", "=SUM({$c}7:{$c}{$last})");
                $ws->getStyle("{$c}{$r}")->getNumberFormat()->setFormatCode(self::MONEY);
            }
            $ws->setCellValue("G{$r}", "=IF(N(D{$r})<>0,F{$r}/D{$r},\"—\")");
            $ws->getStyle("G{$r}")->getNumberFormat()->setFormatCode(self::PCT);
            $ws->getStyle("B{$r}:K{$r}")->getFont()->setBold(true);
            $ws->getStyle("B{$r}:K{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::SOFT);
            $ws->setCellValue("H{$r}", '=ROUND(F'.$r.'-('.$this->num($t['ebitda']).'),2)');
            $ws->getStyle("H{$r}")->getNumberFormat()->setFormatCode(self::CONTROL);
            $ws->getStyle("H{$r}")->getFont()->setSize(8)->getColor()->setRGB(self::GREEN);
            $r++;
        }
        $r++;
        $centralRow = null;
        if ($c = $br['totals']['centralizados'] ?? null) {
            $this->put($ws, "B{$r}", 'GASTOS CENTRALIZADOS (CORPORATIVO Y DIRECCIÓN GENERAL) · NO SE ASIGNAN A SUCURSALES');
            $this->headerRow($ws, "B{$r}:F{$r}");
            $r++;
            $f = $r;
            foreach ($c['categories'] as $cat) {
                $this->put($ws, "B{$r}", $cat['label'].($cat['restricted'] ? ' (detalle restringido)' : ''));
                $this->money($ws, "F{$r}", $cat['amount']);
                $r++;
            }
            $centralRow = $r;
            $this->put($ws, "B{$r}", 'Total centralizados');
            $ws->setCellValue("F{$r}", $r > $f ? "=SUM(F{$f}:F".($r - 1).')' : '=0');
            $ws->getStyle("F{$r}")->getNumberFormat()->setFormatCode(self::MONEY);
            $ws->getStyle("B{$r}:F{$r}")->getFont()->setBold(true);
            $r += 2;
        }
        if (($k = $br['totals']['conciliacion'] ?? null) && $totalRow && $centralRow) {
            $this->put($ws, "B{$r}", 'CONCILIACIÓN AL EBITDA CONSOLIDADO');
            $this->headerRow($ws, "B{$r}:F{$r}");
            $r++;
            $a = $r;
            $this->put($ws, "B{$r}", 'Σ EBITDA de sucursales');
            $ws->setCellValue("F{$r}", "=F{$totalRow}");
            $r++;
            $this->put($ws, "B{$r}", '− Gastos centralizados');
            $ws->setCellValue("F{$r}", "=-F{$centralRow}");
            $r++;
            $this->put($ws, "B{$r}", '± Ingresos / gastos no asignados a sucursal');
            $this->money($ws, "F{$r}", $k['no_asignado_a_sucursal']);
            $r++;
            $this->put($ws, "B{$r}", '= EBITDA consolidado');
            $ws->setCellValue("F{$r}", "=SUM(F{$a}:F".($r - 1).')');
            $ws->setCellValue("G{$r}", '=ROUND(F'.$r.'-('.$this->num($k['ebitda_consolidado']).'),2)');
            $ws->getStyle("G{$r}")->getNumberFormat()->setFormatCode(self::CONTROL);
            $ws->getStyle("G{$r}")->getFont()->setSize(8)->getColor()->setRGB(self::GREEN);
            $ws->getStyle("B{$r}:F{$r}")->getFont()->setBold(true);
            $ws->getStyle("F{$a}:F{$r}")->getNumberFormat()->setFormatCode(self::MONEY);
            $r++;
            $this->note($ws, "B{$r}", $k['note']);
            $r += 2;
        }

        // Datos y gráficas por sucursal.
        $this->put($ws, 'M6', 'Sucursal');
        $this->put($ws, 'N6', 'EBITDA sucursal');
        $this->put($ws, 'O6', 'Ingresos operativos');
        $this->put($ws, 'P6', 'OPEX directo');
        $this->headerRow($ws, 'M6:P6');
        $rows = $br['rows'];
        usort($rows, fn ($a, $b) => (float) ($b['ebitda'] ?? -INF) <=> (float) ($a['ebitda'] ?? -INF));
        foreach (array_values($rows) as $i => $row) {
            $rr = 7 + $i;
            $this->put($ws, "M{$rr}", $row['label']);
            $this->money($ws, "N{$rr}", $row['ebitda']);
            $this->money($ws, "O{$rr}", $row['ingresos_operativos']);
            $this->money($ws, "P{$rr}", $row['opex_total']);
        }
        $dLast = 6 + count($rows);
        $top = $r + 1;
        ErExcelCharts::add($ws, DataSeries::TYPE_BARCHART, 'EBITDA por sucursal', 'M', 6, 7, $dLast, [['col' => 'N', 'color' => self::TEAL]],
            "B{$top}", 'F'.($top + max(14, count($rows) + 6)), '"$"#,##0', DataSeries::GROUPING_CLUSTERED, true);
        ErExcelCharts::add($ws, DataSeries::TYPE_BARCHART, 'Ingresos vs OPEX por sucursal', 'M', 6, 7, $dLast, [
            ['col' => 'O', 'color' => self::SERIES['ingresos_operativos']], ['col' => 'P', 'color' => self::SERIES['opex_total']],
        ], "G{$top}", 'K'.($top + max(14, count($rows) + 6)));
    }

    // ── 6 · Conciliación y trazabilidad ────────────────────────────────

    private function sheetControl(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 50, 'C' => 24, 'D' => 22, 'E' => 18, 'F' => 60]);
        $this->banner($ws, 'CONCILIACIÓN Y TRAZABILIDAD', 'F');
        $m = $this->x['meta'];
        $rep = $this->x['report'];
        $rows = [
            ['Hash del dataset (igual en web, Excel y PDF)', $m['dataset_hash']],
            ['Versión de reglas de clasificación', $rep['rules_version']],
            ['Modelo del Estado de Resultados', $this->x['legacy'] ? 'Modelo anterior (3 niveles de EBITDA) — versión aprobada histórica' : 'EBITDA único · '.$this->x['model_version']],
            ['Tolerancia de fechas en conciliación (días)', (string) ($m['tolerance_days'] ?? '—')],
            ['Generado', $m['generated_at']],
            ['Versión del reporte', (string) $rep['version']],
            ['Estado del reporte', $rep['status_label'].($rep['preliminary'] ? ' · PRELIMINAR' : '')],
            ['Origen de las cifras', $rep['source_label']],
            ['Aprobado por / fecha', $rep['approved_at'] ? ($rep['approved_by'] ?? '—').' · '.$rep['approved_at'] : 'Sin aprobar'],
            ['ID de la versión aprobada', $rep['version_id'] ? (string) $rep['version_id'] : '—'],
            ['Hash del snapshot aprobado', $rep['snapshot_hash'] ?? '—'],
            ['Criterio gerencial aplicado', $rep['criterion']],
            ['Documento emitido', $rep['issued_at']],
            ['Estado de validación', $this->x['validation']['status_label']],
        ];
        foreach ($rep['preliminary_reasons'] as $reason) {
            $rows[] = ['Motivo PRELIMINAR', $reason];
        }
        if (! empty($this->x['redaction_message'])) {
            $rows[] = ['Acceso restringido', $this->x['redaction_message']];
        }
        $r = 6;
        foreach ($rows as [$l, $v]) {
            $this->put($ws, "B{$r}", $l);
            $this->put($ws, "C{$r}", (string) $v);
            $ws->mergeCells("C{$r}:F{$r}");
            if (in_array($l, ['Criterio gerencial aplicado', 'Motivo PRELIMINAR'], true)) {
                $ws->getStyle("C{$r}")->getAlignment()->setWrapText(true);
                $ws->getRowDimension($r)->setRowHeight($l === 'Criterio gerencial aplicado' ? 52 : 26);
            }
            if ($l === 'Motivo PRELIMINAR' || ($l === 'Estado del reporte' && $rep['preliminary'])) {
                $ws->getStyle("B{$r}:C{$r}")->getFont()->setBold(true)->getColor()->setRGB(self::BAD);
            }
            $r++;
        }

        $r++;
        foreach (['B' => 'FUENTE', 'C' => 'ESTADO', 'D' => 'PERIODO DE LA FUENTE', 'E' => '', 'F' => 'DETALLE'] as $c => $t) {
            $this->put($ws, "{$c}{$r}", $t);
        }
        $this->headerRow($ws, "B{$r}:F{$r}");
        $r++;
        foreach ($this->x['sources'] as $s) {
            $this->put($ws, "B{$r}", $s['label']);
            $this->put($ws, "C{$r}", $s['status_label']);
            $this->put($ws, "D{$r}", $s['period']);
            $ws->mergeCells("D{$r}:E{$r}");
            $this->put($ws, "F{$r}", $s['detail']);
            $ws->getStyle("C{$r}")->getFont()->setBold(true)->getColor()->setRGB($s['status'] === 'procesado' ? '047857' : ($s['status'] === 'pendiente' ? self::MUTED : self::AMBER));
            $r++;
        }

        $v = $this->x['validation'];
        if ($rad = $v['radiography']) {
            $r++;
            foreach (['B' => 'CONCILIACIÓN CON RADIOGRAFÍA (EBITDA DE SUCURSALES)', 'C' => 'ESTADO DE RESULTADOS', 'D' => 'RADIOGRAFÍA', 'E' => 'DIFERENCIA'] as $c => $t) {
                $this->put($ws, "{$c}{$r}", $t);
            }
            $this->headerRow($ws, "B{$r}:F{$r}");
            $r++;
            foreach ($rad['components'] as $row) {
                $this->put($ws, "B{$r}", $row['label']);
                $this->money($ws, "C{$r}", $row['er']);
                $this->money($ws, "D{$r}", $row['radiografia']);
                $ws->setCellValue("E{$r}", "=ROUND(C{$r}-D{$r},2)");
                $ws->getStyle("E{$r}")->getNumberFormat()->setFormatCode(self::MONEY);
                $r++;
            }
            $this->put($ws, "B{$r}", 'EBITDA de sucursales');
            $this->money($ws, "C{$r}", $rad['er']);
            $this->money($ws, "D{$r}", $rad['radiografia']);
            $ws->setCellValue("E{$r}", "=ROUND(C{$r}-D{$r},2)");
            $ws->getStyle("E{$r}")->getNumberFormat()->setFormatCode(self::MONEY);
            $ws->getStyle("B{$r}:E{$r}")->getFont()->setBold(true)->getColor()->setRGB($rad['ok'] ? '047857' : self::BAD);
            $r++;
            foreach ($rad['explanations'] as $e) {
                $this->put($ws, "B{$r}", '· '.$e['label']);
                $this->money($ws, "E{$r}", $e['amount']);
                $ws->getStyle("B{$r}")->getAlignment()->setWrapText(true);
                $r++;
            }
            foreach ($rad['informative'] as $txt) {
                $this->note($ws, "B{$r}", $txt);
                $r++;
            }
        }

        $r++;
        foreach (['B' => 'VALIDACIÓN MATEMÁTICA', 'C' => 'ESPERADO', 'D' => 'CALCULADO', 'E' => 'DIFERENCIA', 'F' => 'OK'] as $c => $t) {
            $this->put($ws, "{$c}{$r}", $t);
        }
        $this->headerRow($ws, "B{$r}:F{$r}");
        $hdr = $r;
        $r++;
        foreach ($v['checks'] as $c) {
            $this->put($ws, "B{$r}", $c['label']);
            $this->money($ws, "C{$r}", $c['expected']);
            $this->money($ws, "D{$r}", $c['actual']);
            $this->money($ws, "E{$r}", $c['difference']);
            $this->put($ws, "F{$r}", $c['ok'] ? '✓' : '✗');
            $ws->getStyle("F{$r}")->getFont()->setBold(true)->getColor()->setRGB($c['ok'] ? self::GREEN : self::BAD);
            $r++;
        }
        if ($r - 1 > $hdr) {
            $ws->setAutoFilter("B{$hdr}:F".($r - 1));
        }

        if ($v['unresolved']) {
            $r++;
            foreach (['B' => 'PENDIENTES POR RESOLVER', 'C' => 'CANTIDAD', 'D' => 'IMPORTE', 'E' => 'BLOQUEA'] as $c => $t) {
                $this->put($ws, "{$c}{$r}", $t);
            }
            $this->headerRow($ws, "B{$r}:F{$r}");
            $r++;
            foreach ($v['unresolved'] as $u) {
                $this->put($ws, "B{$r}", $u['label']);
                $this->put($ws, "C{$r}", $u['count']);
                $this->money($ws, "D{$r}", $u['amount']);
                $this->put($ws, "E{$r}", $u['blocking'] ? 'Sí' : 'No (informativo)');
                $r++;
            }
        }

        // Periodos que alimentan comparativas y gráficas (origen de cada uno).
        if ($evo = $this->x['comparisons']['evolution'] ?? null) {
            $r++;
            foreach (['B' => 'PERIODO EN COMPARATIVAS', 'C' => 'ESTADO', 'D' => 'ORIGEN', 'E' => 'VERSIÓN', 'F' => 'DATASET'] as $c => $t) {
                $this->put($ws, "{$c}{$r}", $t);
            }
            $this->headerRow($ws, "B{$r}:F{$r}");
            $r++;
            foreach ($evo['periods'] as $p) {
                $this->put($ws, "B{$r}", $p['period']['label']);
                $this->put($ws, "C{$r}", $p['status_label']);
                $this->put($ws, "D{$r}", $p['source_label'] ?? '—');
                $this->put($ws, "E{$r}", isset($p['report']['version']) ? 'v'.$p['report']['version'] : '—');
                $this->put($ws, "F{$r}", isset($p['report']['dataset_hash']) && $p['report']['dataset_hash'] !== '' ? substr((string) $p['report']['dataset_hash'], 0, 16).'…' : '—');
                $r++;
            }
        }
        $ws->freezePane('A6');
    }

    // ── Anexos ─────────────────────────────────────────────────────────

    private function sheetIndicators(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 44, 'C' => 22, 'D' => 50]);
        $this->banner($ws, 'INDICADORES', 'D');
        $r = 6;
        foreach (['financial' => 'INDICADORES FINANCIEROS', 'informative' => 'INDICADORES OPERATIVOS (INFORMATIVOS, NO AFECTAN EL RESULTADO)'] as $group => $title) {
            $this->put($ws, "B{$r}", $title);
            $this->put($ws, "C{$r}", 'VALOR');
            $this->headerRow($ws, "B{$r}:C{$r}");
            $r++;
            foreach ((array) ($this->d['indicators'][$group] ?? []) as $ind) {
                $this->put($ws, "B{$r}", $ind['label']);
                $this->indicatorValue($ws, "C{$r}", $ind);
                $r++;
            }
            $r++;
        }
    }

    /** Líneas de una categoría de OPEX (modelo vigente) o de una sección (modelo anterior). */
    private function sections(array $keys): array
    {
        $out = [];
        foreach ($this->x['statement'] as $b) {
            if (($b['type'] ?? '') !== 'section') {
                continue;
            }
            foreach ((array) ($b['categories'] ?? []) as $c) {
                if (in_array($c['key'], $keys, true)) {
                    $out[] = ['lines' => $c['lines'], 'total' => $c['total']];
                }
            }
            if (in_array($b['key'] ?? '', $keys, true) && ($b['applies'] ?? true)) {
                $out[] = ['lines' => $b['lines'] ?? [], 'total' => $b['total']];
            }
        }

        return $out;
    }

    private function sheetLines(Worksheet $ws, string $title, array $keys): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 56, 'C' => 20, 'D' => 12, 'E' => 50]);
        $this->banner($ws, $title, 'E');
        $r = 6;
        foreach ($this->sections($keys) as $sec) {
            foreach (['B' => 'CONCEPTO', 'C' => 'IMPORTE', 'D' => '% ING.', 'E' => 'FUENTE'] as $c => $t) {
                $this->put($ws, "{$c}{$r}", $t);
            }
            $this->headerRow($ws, "B{$r}:E{$r}");
            $hdr = $r;
            $r++;
            foreach ($sec['lines'] as $l) {
                $this->put($ws, "B{$r}", $l['label']);
                ($l['state'] ?? null) === 'sin_informacion' ? $this->put($ws, "C{$r}", 'Sin información') : $this->money($ws, "C{$r}", $l['amount']);
                $this->pct($ws, "D{$r}", $l['pct'] ?? null);
                $this->put($ws, "E{$r}", trim(($l['source'] ?? '').(! empty($l['hint']) ? ' · '.$l['hint'] : '')));
                $r++;
            }
            if ($r - 1 > $hdr) {
                $ws->setAutoFilter("B{$hdr}:E".($r - 1));
            }
            $this->put($ws, "B{$r}", $sec['total']['label']);
            $this->money($ws, "C{$r}", $sec['total']['amount']);
            $this->pct($ws, "D{$r}", $sec['total']['pct'] ?? null);
            $ws->getStyle("B{$r}:E{$r}")->getFont()->setBold(true);
            $ws->getStyle("B{$r}:E{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::SOFT);
            $r += 2;
        }
        $ws->freezePane('A7');
    }

    private function sheetItems(Worksheet $ws, string $title, callable $filter, ?string $restrictedKey = null): void
    {
        $this->setup($ws, $this->movementWidths());
        $this->banner($ws, $title, 'R');
        if ($restrictedKey !== null && ! empty($this->d['meta']['redactions'][$restrictedKey])) {
            $this->restrictedNote($ws, 6, 'El detalle de Dirección General está restringido para tu rol (permiso «Consultar detalle de Dirección General»). El total sí está incluido en el OPEX y en el EBITDA.');

            return;
        }
        $items = array_values(array_filter((array) ($this->d['movements'] ?? []), fn ($m) => ($m['in_period'] ?? false) && $filter($m)));
        $this->put($ws, 'B6', count($items).' movimiento(s). Sólo los marcados «Suma» afectan el resultado; los pendientes NO suman hasta clasificarse.');
        $ws->getStyle('B6')->getFont()->setItalic(true)->getColor()->setRGB(self::MUTED);
        $this->movementTable($ws, 8, $items);
    }

    private function restrictedNote(Worksheet $ws, int $row, string $text): void
    {
        $this->put($ws, "B{$row}", $text);
        $ws->getStyle("B{$row}")->getFont()->setBold(true)->getColor()->setRGB(self::AMBER);
    }

    private function sheetPayroll(Worksheet $ws): void
    {
        $this->sheetLines($ws, 'DETALLE · NÓMINA Y CAPITAL HUMANO', ['nomina_sucursales', 'nomina_corporativa']);
        if (! empty($this->d['meta']['redactions']['nomina'])) {
            $this->restrictedNote($ws, $ws->getHighestRow() + 2, 'El detalle de nómina está restringido para tu rol (permiso «Consultar detalle de nómina»). Los totales sí están incluidos en el resultado.');

            return;
        }
        $items = array_values(array_filter((array) ($this->d['movements'] ?? []), fn ($m) => ($m['in_period'] ?? false) && $m['treatment'] === 'nomina_corporativa'));
        if ($items !== []) {
            $start = $ws->getHighestRow() + 2;
            $this->put($ws, "B{$start}", 'DETALLE NÓMINA CORPORATIVA');
            $ws->getStyle("B{$start}")->getFont()->setBold(true)->getColor()->setRGB(self::TEAL);
            foreach ($this->movementWidths() as $col => $w) {
                if ($col > 'E') {
                    $ws->getColumnDimension($col)->setWidth($w);
                }
            }
            $this->movementTable($ws, $start + 1, $items, false);
        }
    }

    private function sheetMovements(Worksheet $ws): void
    {
        $this->setup($ws, $this->movementWidths());
        $this->banner($ws, 'CONCILIACIÓN DE MOVIMIENTOS', 'R');
        $c = (array) ($this->d['conciliation'] ?? []);
        $summary = [
            ['Movimientos leídos', $c['movimientos_leidos'] ?? null, null],
            ['Movimientos en el periodo', $c['movimientos_en_periodo'] ?? null, null],
            ['Total cargos (periodo)', null, $c['total_cargos'] ?? null],
            ['Total abonos (periodo)', null, $c['total_abonos'] ?? null],
            ['Cargos candidatos a conciliar', $c['candidatos'] ?? null, null],
            ['Conciliados con ERP', $c['conciliados_erp']['count'] ?? null, $c['conciliados_erp']['amount'] ?? null],
            ['Nuevos (sin ERP)', $c['nuevos']['count'] ?? null, $c['nuevos']['amount'] ?? null],
            ['Transferencias internas', $c['transferencias']['count'] ?? null, $c['transferencias']['amount'] ?? null],
            ['Fuera del ER (otros)', $c['fuera_er']['count'] ?? null, $c['fuera_er']['amount'] ?? null],
            ['Pendientes de revisión (no suman)', $c['pendientes']['count'] ?? null, $c['pendientes']['amount'] ?? null],
            ['Importe incorporado al ER desde bancos', null, $c['incorporado_bancos'] ?? null],
            ['Importe incorporado desde ERP fuera de Radiografía', null, $c['incorporado_erp_fuera_radiografia'] ?? null],
        ];
        $this->put($ws, 'B6', 'RESUMEN');
        $this->put($ws, 'E6', 'CANTIDAD');
        $this->put($ws, 'F6', 'IMPORTE');
        $this->headerRow($ws, 'B6:F6');
        $r = 7;
        foreach ($summary as [$label, $count, $amount]) {
            $this->put($ws, "B{$r}", $label);
            $ws->mergeCells("B{$r}:D{$r}");
            if ($count !== null) {
                $this->put($ws, "E{$r}", (int) $count);
            }
            $this->money($ws, "F{$r}", $amount);
            $r++;
        }
        $this->movementTable($ws, $r + 1, (array) ($this->d['movements'] ?? []));
    }

    private function sheetAdjustments(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 46, 'C' => 18, 'D' => 20, 'E' => 40, 'F' => 40]);
        $this->banner($ws, 'AJUSTES CONTABLES Y FLUJO', 'F');
        $this->note($ws, 'B5', 'Un campo «Sin información» NO equivale a $0.00: el resultado afectado se marca incompleto (y el ISR pendiente deja pendiente la utilidad neta).', self::AMBER);
        foreach (['B7' => 'CONCEPTO', 'C7' => 'ESTADO', 'D7' => 'IMPORTE', 'E7' => 'EFECTO', 'F7' => 'NOTAS / CAPTURÓ'] as $c => $t) {
            $this->put($ws, $c, $t);
        }
        $this->headerRow($ws, 'B7:F7');
        $r = 8;
        $stateLabel = ['sin_informacion' => 'Sin información', 'confirmado_cero' => 'Confirmado $0', 'capturado' => 'Capturado'];
        foreach ((array) ($this->d['adjustments'] ?? []) as $a) {
            $this->put($ws, "B{$r}", $a['label']);
            $this->put($ws, "C{$r}", $stateLabel[$a['state']] ?? $a['state']);
            $a['amount'] !== null ? $this->money($ws, "D{$r}", $a['amount']) : $this->put($ws, "D{$r}", '—');
            $this->put($ws, "E{$r}", $a['effect'] ?? '');
            $this->put($ws, "F{$r}", trim(($a['notes'] ?? '').(! empty($a['updated_by']) ? ' · '.$a['updated_by'].' '.($a['updated_at'] ?? '') : '')));
            if ($a['state'] === 'sin_informacion') {
                $ws->getStyle("B{$r}:F{$r}")->getFont()->getColor()->setRGB(self::AMBER);
            }
            $r++;
        }
        $r += 2;
        $this->put($ws, "B{$r}", 'IMPORTANTE: CAPEX no reduce el EBITDA (flujo de inversión). El capital pagado de préstamos no es gasto financiero: sólo los intereses afectan el EBT. Los movimientos personales de socios/CEO no son OPEX.');
        $ws->mergeCells("B{$r}:F{$r}");
        $ws->getStyle("B{$r}")->getAlignment()->setWrapText(true);
        $ws->getRowDimension($r)->setRowHeight(32);
        $ws->freezePane('A8');
    }

    private function sheetOutside(Worksheet $ws): void
    {
        $this->setup($ws, $this->movementWidths());
        $this->banner($ws, 'MOVIMIENTOS FUERA DEL ESTADO DE RESULTADOS', 'R');
        foreach (['B6' => 'TRATAMIENTO', 'E6' => 'CANTIDAD', 'F6' => 'CARGOS', 'G6' => 'ABONOS', 'H6' => 'POR QUÉ NO AFECTA EL RESULTADO'] as $c => $t) {
            $this->put($ws, $c, $t);
        }
        $this->headerRow($ws, 'B6:R6');
        $r = 7;
        foreach ((array) ($this->d['outside']['groups'] ?? []) as $g) {
            $this->put($ws, "B{$r}", $g['label']);
            $ws->mergeCells("B{$r}:D{$r}");
            $this->put($ws, "E{$r}", (int) $g['count']);
            $this->money($ws, "F{$r}", $g['cargos']);
            $this->money($ws, "G{$r}", $g['abonos']);
            $this->put($ws, "H{$r}", $g['explain']);
            $ws->mergeCells("H{$r}:R{$r}");
            $r++;
        }
        $items = array_values(array_filter((array) ($this->d['movements'] ?? []), fn ($m) => ! ($m['impact']['included'] ?? false)));
        $this->movementTable($ws, $r + 1, $items);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function movementWidths(): array
    {
        return ['A' => 2, 'B' => 11, 'C' => 18, 'D' => 34, 'E' => 12, 'F' => 14, 'G' => 26, 'H' => 30, 'I' => 16, 'J' => 14, 'K' => 14, 'L' => 13, 'M' => 14, 'N' => 12, 'O' => 30, 'P' => 28, 'Q' => 14, 'R' => 60];
    }

    /** Tabla de movimientos (anexos). Referencias, conceptos y beneficiarios con cuentas enmascaradas. */
    private function movementTable(Worksheet $ws, int $r, array $items, bool $freeze = true): void
    {
        $headers = ['FECHA', 'ORIGEN', 'REFERENCIA', 'FOLIO/REQ', 'UUID', 'BENEFICIARIO', 'CONCEPTO', 'SUCURSAL', 'CARGO', 'ABONO', 'ERP MONTO', 'ERP FECHA', 'ESTADO CONCILIACIÓN', 'CLASIFICACIÓN', 'IMPACTO', 'SUGERENCIA', 'MOTIVO / EVIDENCIA'];
        $col = 'B';
        foreach ($headers as $h) {
            $this->put($ws, "{$col}{$r}", $h);
            $col++;
        }
        $this->headerRow($ws, "B{$r}:R{$r}");
        $ws->setAutoFilter("B{$r}:R".max($r, $r + count($items)));
        if ($freeze) {
            $ws->freezePane('C'.($r + 1));
        }
        $r++;
        foreach ($items as $m) {
            $erp = $m['erp'][0] ?? null;
            $origin = ($m['source'] ?? '') === 'erp' ? 'ERP' : ErFormat::mask((string) ($m['account_label'] ?? $m['account_slot'] ?? '')).' f.'.($m['source_row'] ?? '');
            $values = [
                'B' => $m['date'] ?? '', 'C' => $origin, 'D' => ErFormat::mask($m['reference'] ?? null), 'E' => $m['folio'] ?? null, 'F' => $m['uuid'] ?? null,
                'G' => ErFormat::mask($m['beneficiary'] ?? null), 'H' => ErFormat::mask($m['concept'] ?? null), 'I' => $m['branch_name'] ?? $m['sucursal_raw'] ?? null,
            ];
            foreach ($values as $c => $v) {
                $this->put($ws, "{$c}{$r}", (string) ($v ?? ''));
            }
            $this->money($ws, "J{$r}", $m['cargo'] ?? null);
            $this->money($ws, "K{$r}", $m['abono'] ?? null);
            if ($erp) {
                $this->money($ws, "L{$r}", $erp['amount'] ?? null);
                $this->put($ws, "M{$r}", (string) ($erp['date'] ?? ''));
            }
            $this->put($ws, "N{$r}", $m['reconciliation_label'] ?? '');
            $this->put($ws, "O{$r}", ($m['treatment_label'] ?? '').(($m['treatment_source'] ?? null) === 'manual' ? ' (manual)' : ''));
            $included = (bool) ($m['impact']['included'] ?? false);
            $this->put($ws, "P{$r}", $included ? 'Suma '.ErFormat::money($m['impact']['amount'] ?? 0) : 'No suma');
            $reason = ! empty($m['override']) ? ('Manual: '.$m['override']['notes'].' — '.$m['override']['user'].' '.$m['override']['at'].' (antes: '.$m['override']['previous'].')') : ($m['rule_reason'] ?? '');
            if (! empty($m['reconciliation_message']) && $m['reconciliation_message'] !== $reason) {
                $reason = trim($reason.' | '.$m['reconciliation_message']);
            }
            $this->put($ws, "Q{$r}", ! empty($m['suggested_label']) ? 'Sugerido: '.$m['suggested_label'] : '');
            $this->put($ws, "R{$r}", ErFormat::mask($reason));
            $color = $included ? self::GREEN : (! empty($m['needs_review']) ? self::AMBER : self::MUTED);
            $ws->getStyle("P{$r}")->getFont()->setBold(true)->getColor()->setRGB($color);
            $r++;
        }
    }

    private function setup(Worksheet $ws, array $widths): void
    {
        $ws->setShowGridlines(false);
        foreach ($widths as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }
        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_LETTER)->setFitToWidth(1)->setFitToHeight(0);
        $esc = fn (string $t) => str_replace('&', '&&', $t);
        $rep = $this->x['report'];
        $ws->getHeaderFooter()->setOddFooter('&L&8MR LANA · Estado de Resultados · '.$esc($this->x['meta']['period']['label']).' · '.$esc($this->x['meta']['scope']['label'])
            .' · v'.$rep['version'].' '.$esc($rep['display_status']).' · dataset '.$this->x['meta']['hash_short'].'&R&8Página &P de &N');
    }

    private function banner(Worksheet $ws, string $title, string $lastCol): void
    {
        $logo = public_path('logo-mr-lana.png');
        if (is_file($logo)) {
            $drawing = new Drawing;
            $drawing->setName('MR LANA')->setDescription('Logo MR LANA')->setPath($logo);
            $drawing->setHeight(46);
            $drawing->setCoordinates('B1')->setOffsetX(2)->setOffsetY(6);
            $drawing->setWorksheet($ws);
        }
        $ws->getRowDimension(1)->setRowHeight(24);
        $ws->getRowDimension(2)->setRowHeight(22);
        $this->put($ws, 'C1', $title);
        $ws->getStyle('C1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::INK);
        $m = $this->x['meta'];
        $this->put($ws, 'C2', $m['scope']['label'].'  ·  '.mb_strtoupper((string) $m['period']['label']).'  ·  CIFRAS EN MXN');
        $ws->getStyle('C2')->getFont()->setBold(true)->setSize(10)->getColor()->setRGB(self::TEAL);
        $this->put($ws, 'C3', 'Periodo '.$m['period']['code'].' ('.$m['period']['start'].' a '.$m['period']['end'].') · Generado '.$m['generated_at'].' · Dataset '.$m['hash_short']);
        $ws->getStyle('C3')->getFont()->setSize(8)->getColor()->setRGB(self::MUTED);
        $rep = $this->x['report'];
        $this->put($ws, 'C4', 'Versión '.$rep['version'].' · Estado: '.mb_strtoupper((string) $rep['status_label'])
            .($rep['approved_at'] ? ' · Aprobado por '.($rep['approved_by'] ?? '—').' el '.$rep['approved_at'] : '')
            .' · Reglas '.$rep['rules_version'].' · Emitido '.$rep['issued_at']);
        $ws->getStyle('C4')->getFont()->setBold(true)->setSize(8)->getColor()->setRGB($rep['preliminary'] ? self::BAD : self::TEAL_DARK);
        if ($rep['preliminary']) {
            $this->put($ws, "{$lastCol}1", 'PRELIMINAR');
            $ws->getStyle("{$lastCol}1")->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::BAD);
            $ws->getStyle("{$lastCol}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $this->put($ws, "{$lastCol}2", 'Faltan datos obligatorios: las cifras pueden cambiar.');
            $ws->getStyle("{$lastCol}2")->getFont()->setSize(7)->getColor()->setRGB(self::BAD);
            $ws->getStyle("{$lastCol}2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $ws->getStyle("B4:{$lastCol}4")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THICK)->getColor()->setRGB(self::GREEN);
    }

    private function headerRow(Worksheet $ws, string $range): void
    {
        $style = $ws->getStyle($range);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::TEAL);
        $style->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('FFFFFF');
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    }

    private function note(Worksheet $ws, string $cell, string $text, string $color = self::MUTED): void
    {
        $this->put($ws, $cell, $text);
        $ws->getStyle($cell)->getFont()->setItalic(true)->setSize(8)->getColor()->setRGB($color);
    }

    /**
     * Escritura SEGURA de cualquier valor derivado de usuarios/fuentes: los textos
     * se escriben como cadena explícita (nunca como fórmula) y, si empiezan con
     * = + - @ tab o CR, además con prefijo de comilla (inyección de fórmulas / CSV
     * injection). Las fórmulas del libro las escribe sólo este exportador, con
     * referencias a sus propias celdas (setCellValue con texto construido aquí).
     */
    private function put(Worksheet $ws, string $cell, mixed $value): void
    {
        if ($value === null) {
            return;
        }
        if (is_int($value) || is_float($value)) {
            $ws->setCellValueExplicit($cell, $value, DataType::TYPE_NUMERIC);

            return;
        }
        if (is_bool($value)) {
            $value = $value ? 'Sí' : 'No';
        }
        $text = (string) $value;
        $ws->setCellValueExplicit($cell, $text, DataType::TYPE_STRING);
        if (self::isDangerous($text)) {
            $ws->getStyle($cell)->setQuotePrefix(true);
        }
    }

    public static function isDangerous(string $text): bool
    {
        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true);
    }

    /** Fórmula propia (cuando aplica) o el valor del dataset; ambos con formato de moneda. */
    private function formulaOrValue(Worksheet $ws, string $cell, ?string $formula, ?string $value): void
    {
        if ($formula !== null) {
            $ws->setCellValue($cell, $formula);
            $ws->getStyle($cell)->getNumberFormat()->setFormatCode(self::MONEY);

            return;
        }
        $value === null ? $this->put($ws, $cell, ErFormat::PENDING) : $this->money($ws, $cell, $value);
    }

    /** Columna CONTROL: fórmula del libro − cifra del dataset (debe dar ✓ = 0.00). */
    private function control(Worksheet $ws, int $r, ?string $expected): void
    {
        if ($expected === null) {
            return;
        }
        $ws->setCellValue("E{$r}", "=ROUND(N(C{$r})-({$this->num($expected)}),2)");
        $ws->getStyle("E{$r}")->getNumberFormat()->setFormatCode(self::CONTROL);
        $ws->getStyle("E{$r}")->getFont()->setSize(8)->getColor()->setRGB(self::GREEN);
        $ws->getStyle("E{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /** Número literal seguro para una fórmula (sólo dígitos, signo y punto). */
    private function num(?string $v): string
    {
        return number_format(round((float) $v, 2), 2, '.', '');
    }

    private function money(Worksheet $ws, string $cell, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $this->put($ws, $cell, round((float) $value, 2));
        $ws->getStyle($cell)->getNumberFormat()->setFormatCode(self::MONEY);
    }

    private function pct(Worksheet $ws, string $cell, mixed $value): void
    {
        if ($value === null || $value === '') {
            $this->put($ws, $cell, '—');
            $ws->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            return;
        }
        $this->put($ws, $cell, round((float) $value / 100, 6));
        $ws->getStyle($cell)->getNumberFormat()->setFormatCode(self::PCT);
    }

    private function indicatorValue(Worksheet $ws, string $cell, array $ind): void
    {
        if ($ind['value'] === null) {
            $this->put($ws, $cell, 'Sin información');
            $ws->getStyle($cell)->getFont()->getColor()->setRGB(self::MUTED);

            return;
        }
        match ($ind['unit']) {
            'MXN' => $this->money($ws, $cell, $ind['value']),
            '%' => $this->pct($ws, $cell, $ind['value']),
            default => $this->put($ws, $cell, is_numeric($ind['value']) ? (float) $ind['value'] : $ind['value']),
        };
    }
}
