<?php

namespace App\Services\EstadoResultados\Export;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\ConditionalFormatting\ConditionalDataBar;
use PhpOffice\PhpSpreadsheet\Style\ConditionalFormatting\ConditionalFormatValueObject;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel del Estado de Resultados (modelo de EBITDA único) pensado para dirección: portada con
 * índice navegable, encabezado uniforme en cada hoja (logo, título, alcance, estado y versión),
 * texto mínimo y el detalle técnico concentrado en «Conciliación y trazabilidad» y los anexos.
 *
 * NO recalcula reglas: escribe el dataset resuelto (el mismo de la web y el PDF, ya redactado)
 * y las comparativas de ComparisonService. Los subtotales y resultados son FÓRMULAS sobre las
 * celdas del propio libro (EBITDA = Ingresos − OPEX, …) y cada una lleva una columna ✓ que
 * compara contra la cifra del dataset. Un dato pendiente se escribe como texto «Pendiente» /
 * «Sin información»; las fórmulas lo tratan explícitamente (ISNUMBER / N()), nunca en silencio.
 *
 * Hojas: Índice · 1 Resumen ejecutivo · 2 Estado de Resultados · 3 Comparativa mensual ·
 * 4 Evolución histórica · 5 Sucursales · 6 Conciliación y trazabilidad · anexos de detalle.
 * Los datos de las gráficas nativas viven en columnas agrupadas y contraídas de cada hoja.
 */
class EstadoResultadosExcelExporter
{
    public const SHEETS = [
        'indice' => 'Índice',
        'resumen' => 'Resumen ejecutivo',
        'er' => 'Estado de Resultados',
        'comparativa' => 'Comparativa mensual',
        'evolucion' => 'Evolución histórica',
        'sucursales' => 'Sucursales',
        'conciliacion' => 'Conciliación y trazabilidad',
    ];

    /** Anexos: clave => [título de hoja, descripción breve para el índice]. */
    public const ANNEXES = [
        'indicadores' => ['Anexo Indicadores', 'Indicadores financieros y operativos'],
        'opex_suc' => ['Anexo OPEX sucursales', 'Gastos operativos de sucursales por concepto'],
        'opex_corp' => ['Anexo OPEX corporativo', 'Movimientos de gastos corporativos'],
        'nomina' => ['Anexo Nómina', 'Nómina de sucursales y corporativa'],
        'dg' => ['Anexo DG', 'Gastos de Dirección General'],
        'movimientos' => ['Anexo Movimientos', 'Movimientos bancarios y del ERP del periodo'],
        'ajustes' => ['Anexo Ajustes', 'Depreciación, intereses e ISR'],
        'fuera' => ['Anexo Fuera del ER', 'Movimientos que no afectan el resultado'],
    ];

    /** Descripción breve de cada hoja principal (índice). */
    private const DESCRIPTIONS = [
        'resumen' => 'Resultados clave y variación contra el mes anterior',
        'er' => 'Del ingreso a la utilidad neta, renglón por renglón',
        'comparativa' => 'Mes anterior, mismo mes del año pasado y acumulado',
        'evolucion' => 'Tendencia de los últimos meses',
        'sucursales' => 'Resultado por sucursal y gastos centralizados',
        'conciliacion' => 'Fuentes, validaciones y control del documento',
    ];

    private const MONEY = '$#,##0.00;[Red]-$#,##0.00;$0.00';

    /** Variaciones: la flecha indica la dirección; el color lo da la evaluación (favorable / desfavorable). */
    private const MONEY_DELTA = '"▲ "+$#,##0.00;"▼ "-$#,##0.00;$0.00';

    private const MONEY_DELTA_SIGN = '"▲ "+$#,##0.00;[Red]"▼ "-$#,##0.00;$0.00';

    private const PCT = '0.00%;[Red]-0.00%';

    private const PCT_DELTA = '"▲ "+0.00%;"▼ "-0.00%;0.00%';

    private const PP = '"▲ "+0.00" pp";"▼ "-0.00" pp";0.00" pp"';

    private const CONTROL = '0.00;-0.00;"✓"';

    private const FONT = 'Montserrat';

    private const TEAL = '0F766E';

    private const TEAL_DARK = '0B4F4A';

    private const CYAN = '06B6D4';

    private const GREEN = '10B981';

    private const OK = '047857';

    private const INK = '0F172A';

    private const BODY = '334155';

    private const MUTED = '64748B';

    private const LINE = 'E2E8F0';

    private const SOFT = 'ECFDF5';

    private const CARD = 'F8FAFC';

    private const AMBER = 'B45309';

    private const BAD = 'B91C1C';

    private const SERIES = [
        'ingresos_operativos' => '10B981', 'opex_total' => 'F97316', 'ebitda' => '0F766E', 'ebit' => '0891B2',
        'ebt' => '7C3AED', 'utilidad_neta' => 'D97706', 'margen_ebitda' => '0F766E', 'margen_ebit' => '0891B2', 'margen_neto' => 'D97706',
    ];

    private const PALETTE = ['0F766E', '06B6D4', '10B981', '7C3AED', 'F59E0B', 'EF4444', '64748B', '0EA5E9'];

    /** Motivo breve (para dirección) de una variación no calculada; el mensaje completo vive en ComparisonService. */
    private const SHORT_REASONS = [
        'sin_datos_actual' => 'Sin datos este mes',
        'sin_datos_anterior' => 'Sin datos del mes comparado',
        'pendiente_actual' => 'Pendiente este mes',
        'pendiente_anterior' => 'Pendiente en el mes comparado',
        'base_cero' => 'Base $0: sin variación %',
        'no_comparable' => 'Periodos no comparables',
        'incompleto' => 'Cifra incompleta',
        'base_ingresos_cero' => 'Ingresos en $0',
        'base_no_positiva' => 'Base no interpretable',
        'sin_consolidado' => 'Fuera de tu alcance',
    ];

    private array $d;

    private array $x;

    /** Celda (en «Estado de Resultados») de cada resultado del modelo: key => 'C12'. */
    private array $erCells = [];

    /** Fila (en «Estado de Resultados») de cada paso, también en el modelo anterior: key => 12. */
    private array $erRows = [];

    public function __construct(private readonly EstadoResultadosExportContext $context) {}

    public function export(array $dataset, string $outputPath, ?array $comparisons = null, ?string $comparisonsError = null): string
    {
        $this->d = $dataset;
        $this->x = $this->context->make($dataset, $comparisons, $comparisonsError);
        $this->erCells = [];
        $this->erRows = [];
        $book = new Spreadsheet;
        $book->getDefaultStyle()->getFont()->setName(self::FONT)->setSize(10)->getColor()->setRGB(self::BODY);
        $book->getProperties()->setCreator('MR LANA · Reportes')->setTitle('Estado de Resultados '.$this->x['meta']['period']['label'])
            ->setSubject($this->x['meta']['scope']['label'].' · '.$this->x['meta']['period']['label']);

        $book->removeSheetByIndex(0);
        $ws = [];
        foreach (self::SHEETS as $k => $title) {
            $ws[$k] = $book->createSheet()->setTitle($title);
        }
        // El ER primero: el resumen y el índice referencian sus celdas.
        $this->sheetStatement($ws['er']);
        $this->sheetSummary($ws['resumen']);
        $this->sheetComparative($ws['comparativa']);
        $this->sheetEvolution($ws['evolucion']);
        $this->sheetBranches($ws['sucursales']);
        $this->sheetControl($ws['conciliacion']);

        // Anexos de detalle (sin números de cuenta completos; nómina/DG respetan la redacción).
        $a = fn (string $k) => $book->createSheet()->setTitle(self::ANNEXES[$k][0]);
        $this->sheetIndicators($a('indicadores'));
        $this->sheetLines($a('opex_suc'), 'Detalle · Gastos operativos de sucursales', ['opex_sucursales']);
        $this->sheetItems($a('opex_corp'), 'Detalle · Gastos corporativos', fn ($m) => $m['treatment'] === 'opex_corporativo' || ($m['treatment'] === 'pendiente' && ($m['branch_kind'] ?? null) === 'corporativo' && ! str_contains((string) $m['rule_code'], 'dg')));
        $this->sheetPayroll($a('nomina'));
        $this->sheetItems($a('dg'), 'Detalle · Dirección General', fn ($m) => in_array($m['treatment'], ['dg_operativo', 'dg_remuneracion'], true) || in_array($m['rule_code'], ['dg_requiere_revision', 'erp_dg'], true), 'dg');
        $this->sheetMovements($a('movimientos'));
        $this->sheetAdjustments($a('ajustes'));
        $this->sheetOutside($a('fuera'));

        $this->sheetIndex($ws['indice']);
        foreach ($book->getWorksheetIterator() as $sheet) {
            $sheet->setSelectedCells('A1');
            $sheet->getSheetView()->setZoomScale(100);
        }
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

    // ── Índice (portada navegable) ─────────────────────────────────────

    private function sheetIndex(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 30, 'C' => 2, 'D' => 30, 'E' => 2, 'F' => 30, 'G' => 2, 'H' => 30, 'I' => 2], false);
        $this->banner($ws, 'Estado de Resultados', 'H', false);
        $ws->freezePane('A5');
        $er = self::SHEETS['er'];
        $pair = $this->x['comparisons']['pairs']['anterior'] ?? null;
        $rowOf = fn (string $k) => $pair && $pair['available'] ? collect($pair['rows'])->firstWhere('key', $k) : null;

        $this->label($ws, 'B6', 'RESULTADOS DEL PERIODO');
        $ws->getRowDimension(6)->setRowHeight(24);
        $ws->getStyle('B6')->getAlignment()->setVertical(Alignment::VERTICAL_BOTTOM);
        $r = $this->x['results'];
        $mg = $this->x['margins'];
        $cards = [
            'B' => ['ingresos_operativos', 'INGRESOS OPERATIVOS', 'Base del 100 %'],
            'D' => ['opex_total', 'OPEX · GASTOS OPERATIVOS', $this->pctText(collect($this->x['steps'])->firstWhere('key', 'opex_total')['pct'] ?? null, 'de los ingresos')],
            'F' => ['ebitda', 'EBITDA', 'Margen '.ErFormat::pct($mg['ebitda'] ?? null)],
            'H' => ['utilidad_neta', 'UTILIDAD NETA', 'Margen '.ErFormat::pct($mg['neto'] ?? null)],
        ];
        foreach ($cards as $col => [$key, $label, $sub]) {
            $this->card($ws, $col, 7, $label, $key, $sub, $rowOf($key), $pair['comparison_label'] ?? null, $key === 'ebitda');
        }

        // Aviso de estado del documento (una línea, sin detalle técnico).
        $rep = $this->x['report'];
        $ws->getRowDimension(12)->setRowHeight(10);
        $ws->mergeCells('B13:H13');
        if ($rep['preliminary']) {
            $this->put($ws, 'B13', '⚠  Documento preliminar: faltan datos o hay partidas por conciliar. Las cifras pueden cambiar.');
            $this->pill($ws, 'B13', 'FEF2F2', '991B1B');
        } elseif ($rep['approved']) {
            $this->put($ws, 'B13', '✓  Aprobado'.($rep['approved_by'] ? ' por '.$rep['approved_by'] : '').($rep['approved_at'] ? ' el '.$rep['approved_at'] : '').'.');
            $this->pill($ws, 'B13', 'ECFDF5', '065F46');
        } else {
            $this->put($ws, 'B13', '●  Estado: '.$rep['status_label'].'.');
            $this->pill($ws, 'B13', 'FFFBEB', '92400E');
        }
        $ws->getStyle('B13')->getAlignment()->setIndent(1);
        $ws->getRowDimension(13)->setRowHeight(24);

        // Contenido.
        $row = 15;
        $this->label($ws, "B{$row}", 'CONTENIDO');
        $ws->getRowDimension($row)->setRowHeight(24);
        $ws->getStyle("B{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_BOTTOM);
        $row++;
        $n = 0;
        foreach (self::SHEETS as $k => $title) {
            if ($k === 'indice') {
                continue;
            }
            $n++;
            $this->tocRow($ws, $row++, sprintf('%02d', $n), $title, self::DESCRIPTIONS[$k], true);
        }
        $row++;
        $this->label($ws, "B{$row}", 'ANEXOS DE DETALLE');
        $ws->getRowDimension($row)->setRowHeight(22);
        $ws->getStyle("B{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_BOTTOM);
        $row++;
        foreach (self::ANNEXES as [$title, $desc]) {
            $this->tocRow($ws, $row++, '·', preg_replace('/^Anexo /', '', $title), $desc, false, $title);
        }
        $row++;
        $this->put($ws, "B{$row}", 'Cifras en MXN. «Pendiente» nunca equivale a $0.00. Generado '.$this->x['meta']['generated_at'].'.');
        $ws->getStyle("B{$row}")->applyFromArray(['font' => ['size' => 8, 'color' => ['rgb' => self::MUTED]]]);
        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setPrintArea("A1:I{$row}");
    }

    /** Tarjeta KPI de 4 filas (etiqueta, importe, contexto, variación) en la columna $col. */
    private function card(Worksheet $ws, string $col, int $top, string $label, string $key, string $sub, ?array $cmpRow, ?string $vsLabel, bool $main): void
    {
        $range = "{$col}{$top}:{$col}".($top + 3);
        $bg = $main ? self::TEAL : self::CARD;
        $ws->getStyle($range)->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]]]);
        $ws->getStyle($range)->applyFromArray(['alignment' => ['indent' => 1, 'vertical' => Alignment::VERTICAL_CENTER]]);
        $line = ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $main ? self::TEAL : self::LINE]];
        $ws->getStyle($range)->applyFromArray(['borders' => ['left' => $line, 'right' => $line]], false);
        $this->edge($ws, "{$col}".($top + 3), 'bottom', Border::BORDER_THIN, $main ? self::TEAL : self::LINE);
        $this->edge($ws, "{$col}{$top}", 'top', Border::BORDER_THICK, $main ? self::CYAN : self::GREEN);

        $this->put($ws, "{$col}{$top}", $label);
        $ws->getStyle("{$col}{$top}")->applyFromArray(['font' => ['bold' => true, 'size' => 8, 'color' => ['rgb' => $main ? 'CCFBF1' : self::MUTED]]]);
        $ws->getStyle("{$col}{$top}")->getAlignment()->setVertical(Alignment::VERTICAL_BOTTOM);

        $v = "{$col}".($top + 1);
        $amount = $this->x['results'][$key] ?? null;
        if (isset($this->erCells[$key])) {
            $ws->setCellValue($v, "='".self::SHEETS['er']."'!".$this->erCells[$key]);
            $ws->getStyle($v)->getNumberFormat()->setFormatCode(self::MONEY);
        } else {
            $amount === null ? $this->put($ws, $v, ErFormat::PENDING) : $this->money($ws, $v, $amount);
        }
        $ws->getStyle($v)->applyFromArray(['font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => $main ? 'FFFFFF' : ($amount === null ? self::AMBER : self::INK)]]]);
        $ws->getStyle($v)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        if (isset($this->erRows[$key])) {
            $this->linkTo($ws, $v, self::SHEETS['er'], 'B'.$this->erRows[$key], 'Ver en el Estado de Resultados');
        }

        $this->put($ws, "{$col}".($top + 2), $sub);
        $ws->getStyle("{$col}".($top + 2))->applyFromArray(['font' => ['size' => 8, 'color' => ['rgb' => $main ? 'CCFBF1' : self::MUTED]]]);

        $vr = "{$col}".($top + 3);
        if ($cmpRow && $cmpRow['variation'] !== null && $vsLabel) {
            $up = (float) $cmpRow['variation'] >= 0;
            $this->put($ws, $vr, ($up ? '▲ ' : '▼ ').ErFormat::delta($cmpRow['variation']).($cmpRow['variation_pct'] !== null ? ' ('.ErFormat::pct($cmpRow['variation_pct'], '—', true).')' : '').' vs '.$vsLabel);
            $color = match ($cmpRow['assessment'] ?? null) {
                'favorable' => $main ? 'A7F3D0' : self::OK, 'desfavorable' => $main ? 'FECACA' : self::BAD, default => $main ? 'CCFBF1' : self::MUTED,
            };
            $ws->getStyle($vr)->applyFromArray(['font' => ['bold' => true, 'size' => 8, 'color' => ['rgb' => $color]]]);
        } else {
            $this->put($ws, $vr, $vsLabel ? 'Sin comparación disponible' : ' ');
            $ws->getStyle($vr)->applyFromArray(['font' => ['size' => 8, 'color' => ['rgb' => $main ? 'CCFBF1' : self::MUTED]]]);
        }
        $ws->getStyle($vr)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        foreach ([22, 30, 16, 22] as $i => $h) {
            $ws->getRowDimension($top + $i)->setRowHeight($h);
        }
    }

    private function tocRow(Worksheet $ws, int $r, string $num, string $title, string $desc, bool $main, ?string $sheet = null): void
    {
        $sheet ??= $title;
        $this->put($ws, "B{$r}", $num.'    '.$title);
        $ws->mergeCells("B{$r}:C{$r}");
        $this->linkTo($ws, "B{$r}", $sheet, 'A1', 'Ir a «'.$sheet.'»');
        $ws->getStyle("B{$r}")->applyFromArray(['font' => ['bold' => $main, 'size' => $main ? 11 : 9, 'color' => ['rgb' => $main ? self::TEAL : self::TEAL_DARK]]]);
        $this->put($ws, "D{$r}", $desc);
        $ws->mergeCells("D{$r}:G{$r}");
        $ws->getStyle("D{$r}")->applyFromArray(['font' => ['size' => $main ? 9 : 8, 'color' => ['rgb' => self::MUTED]]]);
        $this->put($ws, "H{$r}", 'Abrir  ›');
        $this->linkTo($ws, "H{$r}", $sheet, 'A1', 'Ir a «'.$sheet.'»');
        $ws->getStyle("H{$r}")->applyFromArray(['font' => ['bold' => true, 'size' => 8, 'color' => ['rgb' => self::TEAL]]]);
        $ws->getStyle("H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getStyle("B{$r}:H{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $this->edge($ws, "B{$r}:H{$r}", 'bottom', Border::BORDER_THIN, self::LINE);
        $ws->getRowDimension($r)->setRowHeight($main ? 26 : 19);
    }

    // ── 2 · Estado de Resultados ────────────────────────────────────────

    private function sheetStatement(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 60, 'C' => 20, 'D' => 13, 'E' => 6, 'F' => 32, 'G' => 2]);
        $this->banner($ws, 'Estado de Resultados', 'F');
        if ($this->x['legacy']) {
            $this->note($ws, 'B5', $this->x['legacy_notice'], self::AMBER);
        }
        foreach (['B6' => 'CONCEPTO', 'C6' => 'IMPORTE', 'D6' => '% INGRESOS', 'E6' => '✓', 'F6' => 'FUENTE'] as $c => $t) {
            $this->put($ws, $c, $t);
        }
        $this->headerRow($ws, 'B6:F6');
        $ws->getStyle('C6:E6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getStyle('E6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->freezePane('A7');
        $ws->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(6, 6);

        $annexOf = [
            'opex_sucursales' => 'opex_suc', 'nomina_sucursales' => 'nomina', 'nomina_corporativa' => 'nomina', 'opex_corporativo' => 'opex_corp', 'dg' => 'dg',
            'da' => 'ajustes', 'financiero' => 'ajustes', 'isr' => 'ajustes',
        ];
        $r = 7;
        $ing = null;
        $pctRows = [];
        foreach ($this->x['statement'] as $block) {
            if (($block['type'] ?? '') === 'section') {
                $ws->getRowDimension($r)->setRowHeight(20);
                $this->put($ws, "B{$r}", trim(($block['number'] ?? '').'   '.mb_strtoupper((string) ($block['title'] ?? ''))));
                $ws->getStyle("B{$r}:F{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => self::TEAL]]]);
                $ws->getStyle("B{$r}:F{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_BOTTOM);
                $this->edge($ws, "B{$r}:F{$r}", 'bottom', Border::BORDER_MEDIUM, self::GREEN);
                if (isset($annexOf[$block['key'] ?? '']) && empty($block['categories'])) {
                    $this->detailLink($ws, "F{$r}", self::ANNEXES[$annexOf[$block['key']]][0]);
                }
                $r++;
                if (! ($block['applies'] ?? true)) {
                    $this->note($ws, "B{$r}", (string) ($block['note'] ?? ''));
                    $r += 2;

                    continue;
                }
                $partRows = [];
                if (! empty($block['categories']) && ! $this->x['legacy']) {
                    foreach ($block['categories'] as $cat) {
                        $this->put($ws, "B{$r}", '   '.$cat['label']);
                        $ws->getStyle("B{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => '0E7490']]]);
                        $centralized = in_array($cat['key'], ['opex_corporativo', 'nomina_corporativa', 'dg'], true);
                        if (isset($annexOf[$cat['key']])) {
                            $this->detailLink($ws, "F{$r}", self::ANNEXES[$annexOf[$cat['key']]][0], $centralized ? 'Centralizado · Ver detalle  ›' : 'Ver detalle  ›');
                        }
                        $r++;
                        [$first, $last, $r] = $this->writeLines($ws, $r, $cat['lines'], '      ', $pctRows);
                        $this->put($ws, "B{$r}", '   '.$cat['total']['label']);
                        $this->formulaOrValue($ws, "C{$r}", $last >= $first ? "=SUM(C{$first}:C{$last})" : null, $cat['total']['amount']);
                        $this->control($ws, $r, $cat['total']['amount']);
                        $ws->getStyle("B{$r}:D{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => self::INK]]]);
                        $this->edge($ws, "B{$r}:D{$r}", 'top', Border::BORDER_THIN, 'CBD5E1');
                        $pctRows[] = $r;
                        $partRows[] = $r;
                        $r += 2;
                    }
                    $r--;
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
                    $this->flag($ws, "F{$r}", '⚠ Sin información');
                } elseif ($unknown && (float) $total == 0.0) {
                    // Un dato desconocido nunca se escribe como $0.00.
                    $this->put($ws, "C{$r}", 'Sin información');
                    $this->flag($ws, "F{$r}", '⚠ Sin capturar');
                } else {
                    $this->formulaOrValue($ws, "C{$r}", $this->x['legacy'] ? null : $totalFormula, $total);
                    $this->control($ws, $r, $total);
                    if ($unknown) {
                        $this->flag($ws, "F{$r}", '⚠ Incompleto');
                    }
                }
                $pctRows[] = $r;
                $ws->getRowDimension($r)->setRowHeight(18);
                $ws->getStyle("B{$r}:D{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => self::INK]]]);
                $ws->getStyle("B{$r}:E{$r}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SOFT]]]);
                $this->edge($ws, "B{$r}:E{$r}", 'top', Border::BORDER_THIN, self::TEAL);
                $ws->getStyle("B{$r}:E{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                $stepKey = ['ingresos' => 'ingresos_operativos', 'opex' => 'opex_total', 'da' => 'depreciacion_amortizacion', 'financiero' => 'resultado_financiero_neto', 'isr' => 'isr'][$block['key'] ?? ''] ?? null;
                if ($stepKey && ! $this->x['legacy']) {
                    $this->erCells[$stepKey] = "C{$r}";
                }
                if ($stepKey) {
                    $this->erRows[$stepKey] = $r;
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
            $this->erRows[$key === 'ebitda_final' ? 'ebitda' : $key] = $r;
            $pctRows[] = $r;
            $fill = $key === 'utilidad_neta' ? '065F46' : (in_array($key, ['ebitda', 'ebitda_final'], true) ? self::TEAL : ($key === 'ebt' ? self::TEAL_DARK : '0E7490'));
            $ws->getStyle("B{$r}:E{$r}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fill]]]);
            $ws->getStyle("B{$r}:E{$r}")->applyFromArray(['font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']]]);
            $ws->getStyle("B{$r}")->getAlignment()->setIndent(1);
            $ws->getRowDimension($r)->setRowHeight(24);
            $ws->getStyle("B{$r}:E{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $r++;
            $nMissing = count((array) ($block['missing'] ?? []));
            $missing = ($block['complete'] ?? true) ? '' : '  ·  ⚠ Incompleto: '.($nMissing === 1 ? 'falta 1 dato' : 'faltan '.$nMissing.' datos').' (ver Anexo Ajustes)';
            $this->put($ws, "B{$r}", '   '.($block['formula'] ?? '').$missing);
            $ws->getStyle("B{$r}")->applyFromArray(['font' => ['italic' => true, 'size' => 8, 'color' => ['rgb' => $missing !== '' ? self::AMBER : self::MUTED]]]);
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
        $ws->getStyle('C7:C'.$r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $this->note($ws, 'B'.($r + 1), '✓ = la fórmula cuadra con el cálculo central.  «Pendiente» y «Sin información» nunca equivalen a $0.00.');
        $ws->getPageSetup()->setPrintArea('A1:G'.($r + 1));
    }

    /** @return array{int,int,int} [primera fila, última fila, siguiente fila] */
    private function writeLines(Worksheet $ws, int $r, array $lines, string $indent, array &$pctRows): array
    {
        $first = $r;
        foreach ($lines as $line) {
            $state = $line['state'] ?? null;
            $this->put($ws, "B{$r}", $indent.$line['label']);
            if ($state === 'sin_informacion') {
                $this->put($ws, "C{$r}", 'Sin información');
                $ws->getStyle("B{$r}:D{$r}")->getFont()->getColor()->setRGB(self::AMBER);
                $ws->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $this->flag($ws, "F{$r}", '⚠ Sin capturar');
            } else {
                $this->money($ws, "C{$r}", $line['amount']);
                $this->put($ws, "F{$r}", $this->shortSource($line['source'] ?? ''));
                $ws->getStyle("F{$r}")->applyFromArray(['font' => ['size' => 8, 'color' => ['rgb' => self::MUTED]]]);
            }
            if ($state === 'restringido') {
                $ws->getStyle("B{$r}")->applyFromArray(['font' => ['italic' => true, 'color' => ['rgb' => self::MUTED]]]);
            }
            $this->rowLine($ws, "B{$r}:F{$r}", false);
            $pctRows[] = $r;
            $r++;
        }

        return [$first, $r - 1, $r];
    }

    /** «Radiografía · Recuperación» → «Radiografía»; «ERP de gastos (sucursal X)» → «ERP de gastos». */
    private function shortSource(string $source): string
    {
        $s = trim((string) preg_replace('/\s*\(.*\)$/u', '', explode(' · ', $source)[0]));

        return $s;
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
        $this->setup($ws, ['A' => 2, 'B' => 42, 'C' => 19, 'D' => 19, 'E' => 21, 'F' => 15, 'G' => 16, 'H' => 30, 'I' => 3, 'J' => 22, 'K' => 16, 'L' => 16, 'M' => 16, 'N' => 16, 'O' => 16]);
        $this->banner($ws, 'Resumen ejecutivo', 'H');
        $pair = $this->x['comparisons']['pairs']['anterior'] ?? null;
        $prevLabel = $pair ? mb_strtoupper($pair['comparison_label']) : 'PERIODO ANTERIOR';
        if ($this->x['legacy']) {
            $this->note($ws, 'B5', $this->x['legacy_notice'], self::AMBER);
        } elseif ($n = $this->x['comparisons']['notice'] ?? null) {
            $this->note($ws, 'B5', $n, self::AMBER);
        }
        $current = mb_strtoupper((string) $this->x['meta']['period']['label']) ?: 'ACTUAL';
        foreach (['B6' => 'INDICADOR', 'C6' => $current, 'D6' => $prevLabel, 'E6' => 'VARIACIÓN $', 'F6' => 'VARIACIÓN %', 'G6' => 'EVALUACIÓN', 'H6' => 'OBSERVACIÓN'] as $c => $t) {
            $this->put($ws, $c, $t);
        }
        $this->headerRow($ws, 'B6:H6');
        $ws->getStyle('C6:F6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getStyle('G6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->freezePane('A7');

        $rowOf = fn (string $k) => $pair ? collect($pair['rows'])->firstWhere('key', $k) : null;
        $r = 7;
        $cell = [];
        foreach ($this->x['steps'] as $s) {
            $this->put($ws, "B{$r}", $s['step'].'. '.$s['label']);
            if (isset($this->erRows[$s['key']])) {
                $this->linkTo($ws, "B{$r}", self::SHEETS['er'], 'B'.$this->erRows[$s['key']], 'Ver el detalle en el Estado de Resultados');
                $ws->getStyle("B{$r}")->getFont()->getColor()->setRGB(self::INK);
            }
            if (isset($this->erCells[$s['key']])) {
                $ws->setCellValue("C{$r}", "='".self::SHEETS['er']."'!".$this->erCells[$s['key']]);
                $ws->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::MONEY);
            } else {
                $s['amount'] === null ? $this->put($ws, "C{$r}", ErFormat::PENDING) : $this->money($ws, "C{$r}", $s['amount']);
            }
            $ws->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $cell[$s['key']] = "C{$r}";
            $row = $rowOf($s['key']);
            $this->comparisonCells($ws, $r, $row, $pair, false);
            $notes = [];
            if ($s['pending']) {
                $notes[] = '⚠ Pendiente';
            } elseif (! $s['complete']) {
                $notes[] = '⚠ Incompleto';
            }
            if ($row && $row['reason'] && ! in_array($row['reason']['code'], ['margen_en_puntos', 'incompleto', 'pendiente_actual'], true)) {
                $notes[] = $this->shortReason($row['reason']);
            }
            $this->flag($ws, "H{$r}", implode(' · ', $notes), $notes && str_starts_with($notes[0], '⚠') ? self::AMBER : self::MUTED);
            $ws->getRowDimension($r)->setRowHeight($s['is_result'] ? 22 : 18);
            $this->rowLine($ws, "B{$r}:H{$r}");
            if ($s['is_result']) {
                $ws->getStyle("B{$r}:F{$r}")->getFont()->setBold(true);
                $ws->getStyle("B{$r}:F{$r}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SOFT]]]);
                $this->edge($ws, "B{$r}:F{$r}", 'bottom', Border::BORDER_THIN, 'A7F3D0');
            }
            $r++;
        }
        $r++;
        $this->put($ws, "B{$r}", 'MÁRGENES · % SOBRE INGRESOS OPERATIVOS');
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
            $ws->getRowDimension($r)->setRowHeight(18);
            $this->rowLine($ws, "B{$r}:H{$r}");
            $r++;
        }
        $r++;
        $this->note($ws, "B{$r}", 'Clic en un indicador para ver su detalle.  La variación % no se calcula con base $0, datos pendientes o periodos no comparables; los márgenes se comparan en puntos (pp).');
        $chartTop = $r + 2;
        $this->label($ws, 'B'.($chartTop - 1), 'GRÁFICAS');

        // Datos de gráficas (mismas cifras; celdas vacías = pendiente). Columnas agrupadas y ocultas.
        $this->put($ws, 'J6', 'DATOS DE GRÁFICAS');
        $this->headerRow($ws, 'J6:O6');
        $this->put($ws, 'J7', 'Concepto');
        $this->put($ws, 'K7', 'Importe');
        $rr = 8;
        foreach (['ingresos_operativos', 'opex_total', 'ebitda', 'ebit', 'ebt', 'utilidad_neta'] as $k) {
            $this->put($ws, "J{$rr}", ['ingresos_operativos' => 'Ingresos', 'opex_total' => 'OPEX', 'ebitda' => 'EBITDA', 'ebit' => 'EBIT', 'ebt' => 'EBT', 'utilidad_neta' => 'Utilidad neta'][$k]);
            $this->money($ws, "K{$rr}", $this->x['results'][$k]);
            $rr++;
        }
        ErExcelCharts::add($ws, DataSeries::TYPE_BARCHART, 'Resultados del periodo · '.$this->x['meta']['period']['label'], 'J', 7, 8, $rr - 1,
            [['col' => 'K', 'color' => self::TEAL]], "B{$chartTop}", 'D'.($chartTop + 18));

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
        ErExcelCharts::add($ws, DataSeries::TYPE_BARCHART, 'Cascada: del ingreso a la utilidad', 'J', $wr, $first, $row - 1, [
            ['col' => 'K', 'color' => 'FFFFFF', 'transparent' => true],
            ['col' => 'L', 'color' => self::TEAL],
            ['col' => 'M', 'color' => self::GREEN],
            ['col' => 'N', 'color' => 'EF4444'],
            ['col' => 'O', 'color' => 'F87171'],
        ], "E{$chartTop}", 'H'.($chartTop + 18), '"$"#,##0', DataSeries::GROUPING_STACKED);
        $this->note($ws, 'J'.($row + 1), 'La serie «Base» es transparente (cascada con columnas apiladas). Un paso pendiente queda vacío.');
        ErExcelCharts::hideColumns($ws, 'I', 'O');
        $ws->getPageSetup()->setPrintArea('A1:H'.($chartTop + 18));
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
        foreach (['D', 'E', 'F'] as $c) {
            $ws->getStyle("{$c}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $ws->getStyle("G{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        if (! $pair || ! $row) {
            $this->put($ws, "D{$r}", $pair && ! $pair['available'] ? ErFormat::NO_DATA : '—');
            $this->put($ws, "E{$r}", '—');
            $this->put($ws, "F{$r}", '—');
            $this->put($ws, "G{$r}", '—');
            $ws->getStyle("D{$r}:G{$r}")->getFont()->getColor()->setRGB(self::MUTED);

            return;
        }
        $color = match ($row['assessment'] ?? null) {
            'favorable' => self::OK, 'desfavorable' => self::BAD, default => self::BODY,
        };
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
        $ws->getStyle("E{$r}:F{$r}")->getFont()->getColor()->setRGB($color);
        $this->put($ws, "G{$r}", $row['assessment_label'] ?? '—');
        match ($row['assessment'] ?? null) {
            'favorable' => $this->pill($ws, "G{$r}", 'D1FAE5', '065F46'),
            'desfavorable' => $this->pill($ws, "G{$r}", 'FEE2E2', '991B1B'),
            default => $ws->getStyle("G{$r}")->getFont()->getColor()->setRGB(self::MUTED),
        };
    }

    private function shortReason(array $reason): string
    {
        return self::SHORT_REASONS[$reason['code'] ?? ''] ?? (string) ($reason['message'] ?? '');
    }

    // ── 3 · Comparativa mensual ────────────────────────────────────────

    private function sheetComparative(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 42, 'C' => 19, 'D' => 19, 'E' => 21, 'F' => 15, 'G' => 16, 'H' => 30, 'I' => 3, 'J' => 18, 'K' => 16, 'L' => 16]);
        $this->banner($ws, 'Comparativa mensual', 'H');
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
            $this->put($ws, "B{$r}", '▸  '.$p['title'].($p['partial'] ? ' · parcial' : ''));
            $ws->getStyle("B{$r}")->applyFromArray(['font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => self::TEAL]]]);
            $this->put($ws, "H{$r}", $p['current_label'].'  vs  '.$p['comparison_label']);
            $ws->getStyle("H{$r}")->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => self::MUTED]]]);
            $ws->getStyle("H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $this->edge($ws, "B{$r}:H{$r}", 'bottom', Border::BORDER_MEDIUM, self::GREEN);
            $ws->getRowDimension($r)->setRowHeight(24);
            $ws->getStyle("B{$r}:H{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_BOTTOM);
            $r++;
            if ($p['partial']) {
                $this->note($ws, "B{$r}", 'Acumulado parcial: sólo meses con datos'.($p['missing_months'] ? '; faltan '.implode(', ', $p['missing_months']) : '').'.', self::AMBER);
                $r++;
            }
            if (! $p['available']) {
                $this->note($ws, "B{$r}", $p['unavailable'] ?? 'Sin datos de comparación.', self::AMBER);
                $r += 3;

                continue;
            }
            if (! $p['comparable'] && $p['reasons']) {
                $this->note($ws, "B{$r}", 'No comparable: '.implode(' ', $p['reasons']), self::AMBER);
                $r++;
            }
            $r++;
            foreach (['B' => 'CONCEPTO', 'C' => mb_strtoupper(mb_substr($p['current_label'], 0, 24)), 'D' => mb_strtoupper(mb_substr($p['comparison_label'], 0, 24)), 'E' => 'VARIACIÓN $ / PP', 'F' => 'VARIACIÓN %', 'G' => 'EVALUACIÓN', 'H' => 'OBSERVACIÓN'] as $c => $t) {
                $this->put($ws, "{$c}{$r}", $t);
            }
            $this->headerRow($ws, "B{$r}:H{$r}");
            $ws->getStyle("C{$r}:F{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $ws->getStyle("G{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $r++;
            $start = $r;
            foreach ($p['rows'] as $row) {
                $isPct = $row['unit'] === '%';
                $this->put($ws, "B{$r}", $row['label']);
                if ($row['current'] === null) {
                    $this->put($ws, "C{$r}", ErFormat::PENDING);
                    $ws->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                } else {
                    $isPct ? $this->pct($ws, "C{$r}", $row['current']) : $this->money($ws, "C{$r}", $row['current']);
                }
                $this->comparisonCells($ws, $r, $row, $p, $isPct);
                $this->flag($ws, "H{$r}", $row['reason'] && $row['reason']['code'] !== 'margen_en_puntos' ? $this->shortReason($row['reason']) : '', self::MUTED);
                $ws->getRowDimension($r)->setRowHeight(18);
                $this->rowLine($ws, "B{$r}:H{$r}");
                if (in_array($row['key'], ['ebitda', 'ebit', 'ebt', 'utilidad_neta'], true)) {
                    $ws->getStyle("B{$r}:F{$r}")->getFont()->setBold(true);
                    $ws->getStyle("B{$r}:F{$r}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SOFT]]]);
                }
                $r++;
            }
            if ($k === 'anterior') {
                $chartBlock = [$p, $start];
            }
            $r += 2;
        }

        // Gráfica nativa: actual vs anterior (importes clave). Datos en columnas agrupadas y ocultas.
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
            $this->label($ws, "B{$r}", 'GRÁFICA');
            ErExcelCharts::add($ws, DataSeries::TYPE_BARCHART, $p['current_label'].' vs '.$p['comparison_label'], 'J', 6, 7, $rr - 1,
                [['col' => 'K', 'color' => self::TEAL], ['col' => 'L', 'color' => '94A3B8']], 'B'.($r + 1), 'F'.($r + 20));
            ErExcelCharts::hideColumns($ws, 'I', 'L');
            $r += 21;
        }
        $ws->getPageSetup()->setPrintArea('A1:H'.max(6, $r));
    }

    // ── 4 · Evolución histórica ────────────────────────────────────────

    private function sheetEvolution(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 18, 'C' => 26, 'D' => 17, 'E' => 17, 'F' => 17, 'G' => 17, 'H' => 17, 'I' => 17, 'J' => 13, 'K' => 13, 'L' => 13, 'M' => 3, 'N' => 16, 'O' => 15, 'P' => 15, 'Q' => 15, 'R' => 15, 'S' => 15, 'T' => 15, 'U' => 12, 'V' => 12, 'W' => 12]);
        $this->banner($ws, 'Evolución histórica', 'L');
        $evo = $this->x['comparisons']['evolution'] ?? null;
        if (! $evo) {
            $this->note($ws, 'B6', (string) ($this->x['comparisons']['error'] ?? 'Sin evolución disponible.'), self::AMBER);
            $ws->freezePane('A6');

            return;
        }
        $this->note($ws, 'B5', $evo['label'].' · '.$evo['range']['from'].' a '.$evo['range']['to'].'. Meses sin datos: «Sin datos», nunca $0.00.');
        $amountKeys = ['ingresos_operativos' => 'INGRESOS', 'opex_total' => 'OPEX', 'ebitda' => 'EBITDA', 'ebit' => 'EBIT', 'ebt' => 'EBT', 'utilidad_neta' => 'UTILIDAD NETA'];
        $marginKeys = ['margen_ebitda' => 'MARGEN EBITDA', 'margen_ebit' => 'MARGEN EBIT', 'margen_neto' => 'MARGEN NETO'];
        $headers = ['B' => 'PERIODO', 'C' => 'ESTADO'];
        $col = 'D';
        foreach ($amountKeys + $marginKeys as $t) {
            $headers[$col] = $t;
            $col++;
        }
        foreach ($headers as $c => $t) {
            $this->put($ws, "{$c}6", $t);
        }
        $this->headerRow($ws, 'B6:L6');
        $ws->getStyle('D6:L6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->freezePane('C7');
        $ws->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(6, 6);
        $r = 7;
        foreach ($evo['periods'] as $p) {
            $this->put($ws, "B{$r}", $p['period']['label']);
            $this->put($ws, "C{$r}", $p['status_label']);
            $ws->getStyle("C{$r}")->applyFromArray(['font' => ['size' => 8, 'color' => ['rgb' => self::MUTED]]]);
            $col = 'D';
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
                $ws->getStyle("B{$r}:L{$r}")->getFont()->getColor()->setRGB('94A3B8');
            }
            $ws->getRowDimension($r)->setRowHeight(18);
            $this->rowLine($ws, "B{$r}:L{$r}");
            $r++;
        }
        $last = $r - 1;
        if ($last >= 7) {
            // El mes del reporte resaltado.
            $ws->getStyle("F{$last}")->getFont()->setBold(true);
            $ws->setAutoFilter("B6:L{$last}");
            $ws->getStyle("D7:L{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }

        // Datos de gráficas (celdas vacías = sin datos / pendiente → hueco en la gráfica). Columnas agrupadas y ocultas.
        $dataHeaders = ['N' => 'Periodo', 'O' => 'Ingresos operativos', 'P' => 'OPEX total', 'Q' => 'EBITDA', 'R' => 'EBIT', 'S' => 'EBT', 'T' => 'Utilidad neta', 'U' => 'Margen EBITDA', 'V' => 'Margen EBIT', 'W' => 'Margen neto'];
        foreach ($dataHeaders as $c => $t) {
            $this->put($ws, "{$c}6", $t);
        }
        $this->headerRow($ws, 'N6:W6');
        $series = $evo['series'];
        $map = ['O' => 'ingresos_operativos', 'P' => 'opex_total', 'Q' => 'ebitda', 'R' => 'ebit', 'S' => 'ebt', 'T' => 'utilidad_neta', 'U' => 'margen_ebitda', 'V' => 'margen_ebit', 'W' => 'margen_neto'];
        foreach ($evo['labels'] as $i => $label) {
            $rr = 7 + $i;
            $this->put($ws, "N{$rr}", $label);
            foreach ($map as $c => $k) {
                $v = $series[$k][$i] ?? null;
                if ($v === null) {
                    continue;
                }
                str_starts_with($k, 'margen_') ? $this->pct($ws, "{$c}{$rr}", $v) : $this->money($ws, "{$c}{$rr}", $v);
            }
        }
        $dLast = 6 + count($evo['labels']);
        $top = max($last, 7) + 3;
        $this->label($ws, 'B'.($top - 1), 'GRÁFICAS');
        ErExcelCharts::add($ws, DataSeries::TYPE_LINECHART, 'EBITDA, EBIT, EBT y utilidad neta', 'N', 6, 7, $dLast, [
            ['col' => 'Q', 'color' => self::SERIES['ebitda']], ['col' => 'R', 'color' => self::SERIES['ebit']],
            ['col' => 'S', 'color' => self::SERIES['ebt']], ['col' => 'T', 'color' => self::SERIES['utilidad_neta']],
        ], "B{$top}", 'F'.($top + 20));
        ErExcelCharts::add($ws, DataSeries::TYPE_BARCHART, 'Ingresos operativos vs OPEX', 'N', 6, 7, $dLast, [
            ['col' => 'O', 'color' => self::SERIES['ingresos_operativos']], ['col' => 'P', 'color' => self::SERIES['opex_total']],
        ], "G{$top}", 'L'.($top + 20));
        ErExcelCharts::add($ws, DataSeries::TYPE_LINECHART, 'Márgenes EBITDA, EBIT y neto', 'N', 6, 7, $dLast, [
            ['col' => 'U', 'color' => self::SERIES['margen_ebitda']], ['col' => 'V', 'color' => self::SERIES['margen_ebit']], ['col' => 'W', 'color' => self::SERIES['margen_neto']],
        ], 'B'.($top + 22), 'F'.($top + 42), '0%');

        // Composición del OPEX por periodo (categorías reales, columnas apiladas).
        $lastData = 'W';
        $cats = $evo['opex_categories'] ?? [];
        if ($cats) {
            $base = $dLast + 3;
            $this->put($ws, "N{$base}", 'Periodo');
            $c = 'O';
            $cols = [];
            foreach ($cats as $i => $cat) {
                $this->put($ws, "{$c}{$base}", $cat['label']);
                $cols[] = ['col' => $c, 'color' => self::PALETTE[$i % count(self::PALETTE)]];
                foreach ($evo['labels'] as $j => $label) {
                    $this->put($ws, 'N'.($base + 1 + $j), $label);
                    if (($v = $cat['data'][$j] ?? null) !== null) {
                        $this->money($ws, $c.($base + 1 + $j), $v);
                    }
                }
                if (Coordinate::columnIndexFromString($c) > Coordinate::columnIndexFromString($lastData)) {
                    $lastData = $c;
                }
                $c++;
            }
            $this->headerRow($ws, "N{$base}:".chr(ord('N') + count($cats))."{$base}");
            ErExcelCharts::add($ws, DataSeries::TYPE_BARCHART, 'Composición del OPEX', 'N', $base, $base + 1, $base + count($evo['labels']), $cols,
                'G'.($top + 22), 'L'.($top + 42), '"$"#,##0', DataSeries::GROUPING_STACKED);
        }
        ErExcelCharts::hideColumns($ws, 'M', $lastData);
        $ws->getPageSetup()->setPrintArea('A1:L'.($top + 42));
    }

    // ── 5 · Sucursales ─────────────────────────────────────────────────

    private function sheetBranches(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 30, 'C' => 24, 'D' => 18, 'E' => 18, 'F' => 18, 'G' => 13, 'H' => 14, 'I' => 18, 'J' => 19, 'K' => 15, 'L' => 3, 'M' => 22, 'N' => 16, 'O' => 16, 'P' => 16]);
        $this->banner($ws, 'Sucursales', 'K');
        $br = $this->x['comparisons']['branches'] ?? null;
        if (! $br || ! $br['rows']) {
            $this->note($ws, 'B6', $this->x['comparisons']['available'] ? 'Sin sucursales con datos para este alcance.' : (string) $this->x['comparisons']['error'], self::AMBER);
            $ws->freezePane('A6');

            return;
        }
        $this->note($ws, 'B5', 'EBITDA de sucursal = ingresos − OPEX directo (incluye su nómina). Corporativo y Dirección General se muestran aparte.');
        $h = ['B' => 'SUCURSAL', 'C' => 'ESTADO', 'D' => 'INGRESOS OPERATIVOS', 'E' => 'OPEX DIRECTO', 'F' => 'EBITDA SUCURSAL', 'G' => 'MARGEN', 'H' => 'PART. INGRESOS', 'I' => 'EBITDA ANTERIOR', 'J' => 'VARIACIÓN EBITDA', 'K' => 'EVALUACIÓN'];
        foreach ($h as $c => $t) {
            $this->put($ws, "{$c}6", $t);
        }
        $this->headerRow($ws, 'B6:K6');
        $ws->getStyle('D6:J6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getStyle('K6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->freezePane('C7');
        $ws->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(6, 6);
        $r = 7;
        foreach ($br['rows'] as $row) {
            $this->put($ws, "B{$r}", $row['label']);
            $ws->getStyle("B{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => self::INK]]]);
            $this->put($ws, "C{$r}", $row['status_label']);
            $ws->getStyle("C{$r}")->applyFromArray(['font' => ['size' => 8, 'color' => ['rgb' => self::MUTED]]]);
            foreach (['D' => 'ingresos_operativos', 'E' => 'opex_total', 'F' => 'ebitda'] as $c => $k) {
                $row[$k] === null ? $this->put($ws, "{$c}{$r}", ErFormat::NO_DATA) : $this->money($ws, "{$c}{$r}", $row[$k]);
            }
            $ws->getStyle("F{$r}")->getFont()->setBold(true);
            $ws->setCellValue("G{$r}", "=IF(AND(ISNUMBER(F{$r}),N(D{$r})<>0),F{$r}/D{$r},\"—\")");
            $ws->getStyle("G{$r}")->getNumberFormat()->setFormatCode(self::PCT);
            $this->pct($ws, "H{$r}", $row['participacion_ingresos']);
            $prev = $row['variation']['ebitda']['previous'] ?? null;
            $prev === null ? $this->put($ws, "I{$r}", ErFormat::NO_DATA) : $this->money($ws, "I{$r}", $prev);
            $ws->setCellValue("J{$r}", "=IF(AND(ISNUMBER(F{$r}),ISNUMBER(I{$r})),ROUND(F{$r}-I{$r},2),\"—\")");
            $ws->getStyle("J{$r}")->getNumberFormat()->setFormatCode(self::MONEY_DELTA_SIGN);
            $assessment = $row['variation']['ebitda']['assessment'] ?? null;
            $this->put($ws, "K{$r}", $row['variation']['ebitda']['assessment_label'] ?? '—');
            match ($assessment) {
                'favorable' => $this->pill($ws, "K{$r}", 'D1FAE5', '065F46'),
                'desfavorable' => $this->pill($ws, "K{$r}", 'FEE2E2', '991B1B'),
                default => $ws->getStyle("K{$r}")->getFont()->getColor()->setRGB(self::MUTED),
            };
            $ws->getStyle("K{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("D{$r}:J{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $ws->getRowDimension($r)->setRowHeight(18);
            $this->rowLine($ws, "B{$r}:K{$r}");
            $r++;
        }
        $last = $r - 1;
        $ws->setAutoFilter("B6:K{$last}");
        $this->dataBar($ws, "H7:H{$last}", self::GREEN);
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
            $ws->getStyle("B{$r}:K{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => self::INK]]]);
            $ws->getStyle("B{$r}:K{$r}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SOFT]]]);
            $this->edge($ws, "B{$r}:K{$r}", 'top', Border::BORDER_THIN, self::TEAL);
            $ws->getRowDimension($r)->setRowHeight(20);
            $ws->getStyle("B{$r}:K{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $ws->setCellValue("K{$r}", '=ROUND(F'.$r.'-('.$this->num($t['ebitda']).'),2)');
            $ws->getStyle("K{$r}")->getNumberFormat()->setFormatCode(self::CONTROL);
            $ws->getStyle("K{$r}")->applyFromArray(['font' => ['size' => 9, 'color' => ['rgb' => self::GREEN]]]);
            $ws->getStyle("K{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $r++;
        }
        $r += 2;
        $centralRow = null;
        if ($c = $br['totals']['centralizados'] ?? null) {
            $this->put($ws, "B{$r}", 'GASTOS CENTRALIZADOS · CORPORATIVO Y DIRECCIÓN GENERAL');
            $this->headerRow($ws, "B{$r}:F{$r}");
            $r++;
            $f = $r;
            foreach ($c['categories'] as $cat) {
                $this->put($ws, "B{$r}", $cat['label'].($cat['restricted'] ? ' (detalle restringido)' : ''));
                $this->money($ws, "F{$r}", $cat['amount']);
                $this->rowLine($ws, "B{$r}:F{$r}", false);
                $r++;
            }
            $centralRow = $r;
            $this->put($ws, "B{$r}", 'Total centralizados');
            $ws->setCellValue("F{$r}", $r > $f ? "=SUM(F{$f}:F".($r - 1).')' : '=0');
            $ws->getStyle("F{$r}")->getNumberFormat()->setFormatCode(self::MONEY);
            $ws->getStyle("B{$r}:F{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => self::INK]]]);
            $ws->getStyle("B{$r}:F{$r}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SOFT]]]);
            $this->detailLink($ws, "G{$r}", self::ANNEXES['opex_corp'][0]);
            $r += 3;
        }
        if (($k = $br['totals']['conciliacion'] ?? null) && $totalRow && $centralRow) {
            $this->put($ws, "B{$r}", 'DE LAS SUCURSALES AL EBITDA CONSOLIDADO');
            $this->headerRow($ws, "B{$r}:F{$r}");
            $r++;
            $a = $r;
            $this->put($ws, "B{$r}", 'Σ  EBITDA de sucursales');
            $ws->setCellValue("F{$r}", "=F{$totalRow}");
            $r++;
            $this->put($ws, "B{$r}", '−  Gastos centralizados');
            $ws->setCellValue("F{$r}", "=-F{$centralRow}");
            $r++;
            $this->put($ws, "B{$r}", '±  Ingresos / gastos no asignados a sucursal');
            $this->money($ws, "F{$r}", $k['no_asignado_a_sucursal']);
            $r++;
            $this->put($ws, "B{$r}", '=  EBITDA consolidado');
            $ws->setCellValue("F{$r}", "=SUM(F{$a}:F".($r - 1).')');
            $ws->setCellValue("G{$r}", '=ROUND(F'.$r.'-('.$this->num($k['ebitda_consolidado']).'),2)');
            $ws->getStyle("G{$r}")->getNumberFormat()->setFormatCode(self::CONTROL);
            $ws->getStyle("G{$r}")->applyFromArray(['font' => ['size' => 9, 'color' => ['rgb' => self::GREEN]]]);
            $ws->getStyle("G{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("B{$r}:F{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']]]);
            $ws->getStyle("B{$r}:F{$r}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::TEAL]]]);
            $ws->getRowDimension($r)->setRowHeight(20);
            $ws->getStyle("B{$r}:F{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $ws->getStyle("F{$a}:F{$r}")->getNumberFormat()->setFormatCode(self::MONEY);
            $r += 2;
        }

        // Datos y gráficas por sucursal (columnas agrupadas y ocultas).
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
        $this->label($ws, 'B'.($top - 1), 'GRÁFICAS');
        $bottom = $top + max(16, count($rows) + 6);
        ErExcelCharts::add($ws, DataSeries::TYPE_BARCHART, 'EBITDA por sucursal', 'M', 6, 7, $dLast, [['col' => 'N', 'color' => self::TEAL]],
            "B{$top}", "E{$bottom}", '"$"#,##0', DataSeries::GROUPING_CLUSTERED, true);
        ErExcelCharts::add($ws, DataSeries::TYPE_BARCHART, 'Ingresos vs OPEX por sucursal', 'M', 6, 7, $dLast, [
            ['col' => 'O', 'color' => self::SERIES['ingresos_operativos']], ['col' => 'P', 'color' => self::SERIES['opex_total']],
        ], "F{$top}", "K{$bottom}");
        ErExcelCharts::hideColumns($ws, 'L', 'P');
        $ws->getPageSetup()->setPrintArea("A1:K{$bottom}");
    }

    // ── 6 · Conciliación y trazabilidad ────────────────────────────────

    private function sheetControl(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 50, 'C' => 24, 'D' => 22, 'E' => 18, 'F' => 60]);
        $this->banner($ws, 'Conciliación y trazabilidad', 'F');
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
        $this->put($ws, 'B5', 'CONTROL DEL DOCUMENTO');
        $ws->getStyle('B5')->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => self::TEAL]]]);
        $r = 6;
        foreach ($rows as [$l, $v]) {
            $this->put($ws, "B{$r}", $l);
            $ws->getStyle("B{$r}")->getFont()->getColor()->setRGB(self::MUTED);
            $this->put($ws, "C{$r}", (string) $v);
            $ws->mergeCells("C{$r}:F{$r}");
            $this->rowLine($ws, "B{$r}:F{$r}", false);
            if (in_array($l, ['Criterio gerencial aplicado', 'Motivo PRELIMINAR'], true)) {
                $ws->getStyle("C{$r}")->getAlignment()->setWrapText(true);
                $ws->getRowDimension($r)->setRowHeight($l === 'Criterio gerencial aplicado' ? 52 : 26);
            }
            if ($l === 'Motivo PRELIMINAR' || ($l === 'Estado del reporte' && $rep['preliminary'])) {
                $ws->getStyle("B{$r}:C{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => self::BAD]]]);
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
            $this->put($ws, "C{$r}", $this->sourceGlyph($s['status']).' '.$s['status_label']);
            $this->put($ws, "D{$r}", $s['period']);
            $ws->mergeCells("D{$r}:E{$r}");
            $this->put($ws, "F{$r}", $s['detail']);
            $ws->getStyle("C{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => $this->sourceColor($s['status'])]]]);
            $this->rowLine($ws, "B{$r}:F{$r}", false);
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
            $ws->getStyle("B{$r}:E{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => $rad['ok'] ? self::OK : self::BAD]]]);
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
            $ws->getStyle("F{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => $c['ok'] ? self::GREEN : self::BAD]]]);
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
                $this->put($ws, "D{$r}", $p['source_label'] ?? ($p['unavailable_reason']['message'] ?? '—'));
                $this->put($ws, "E{$r}", isset($p['report']['version']) ? 'v'.$p['report']['version'] : '—');
                $this->put($ws, "F{$r}", isset($p['report']['dataset_hash']) && $p['report']['dataset_hash'] !== '' ? substr((string) $p['report']['dataset_hash'], 0, 16).'…' : '—');
                $r++;
            }
        }

        // Accesos al detalle.
        $r++;
        $this->label($ws, "B{$r}", 'DETALLE DE SOPORTE');
        $r++;
        foreach (['movimientos', 'ajustes', 'fuera'] as $k) {
            [$title, $desc] = self::ANNEXES[$k];
            $this->put($ws, "B{$r}", $title.'  ›');
            $this->linkTo($ws, "B{$r}", $title, 'A1', $desc);
            $ws->getStyle("B{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => self::TEAL]]]);
            $this->put($ws, "C{$r}", $desc);
            $ws->getStyle("C{$r}")->applyFromArray(['font' => ['size' => 8, 'color' => ['rgb' => self::MUTED]]]);
            $r++;
        }
        $ws->freezePane('A5');
    }

    private function sourceGlyph(string $status): string
    {
        return match ($status) {
            'procesado' => '✓', 'pendiente' => '○', 'con_incidencias' => '⚠', default => '●',
        };
    }

    private function sourceColor(string $status): string
    {
        return match ($status) {
            'procesado' => self::OK, 'pendiente' => self::MUTED, 'con_incidencias' => self::BAD, default => self::AMBER,
        };
    }

    // ── Anexos ─────────────────────────────────────────────────────────

    private function sheetIndicators(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 52, 'C' => 22, 'D' => 30]);
        $this->banner($ws, 'Indicadores', 'D');
        $r = 6;
        foreach (['financial' => 'INDICADORES FINANCIEROS', 'informative' => 'INDICADORES OPERATIVOS (INFORMATIVOS, NO AFECTAN EL RESULTADO)'] as $group => $title) {
            $this->put($ws, "B{$r}", $title);
            $this->put($ws, "C{$r}", 'VALOR');
            $this->headerRow($ws, "B{$r}:C{$r}");
            $ws->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $r++;
            foreach ((array) ($this->d['indicators'][$group] ?? []) as $ind) {
                $this->put($ws, "B{$r}", $ind['label']);
                $this->indicatorValue($ws, "C{$r}", $ind);
                $ws->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $this->rowLine($ws, "B{$r}:C{$r}", false);
                $r++;
            }
            $r += 2;
        }
        $ws->freezePane('A5');
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
                    $out[] = ['lines' => $c['lines'], 'total' => $c['total'], 'label' => $c['label']];
                }
            }
            if (in_array($b['key'] ?? '', $keys, true) && ($b['applies'] ?? true)) {
                $out[] = ['lines' => $b['lines'] ?? [], 'total' => $b['total'], 'label' => $b['title'] ?? ''];
            }
        }

        return $out;
    }

    private function sheetLines(Worksheet $ws, string $title, array $keys): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 56, 'C' => 20, 'D' => 14, 'E' => 40]);
        $this->banner($ws, $title, 'E');
        $r = 6;
        foreach ($this->sections($keys) as $sec) {
            foreach (['B' => mb_strtoupper((string) $sec['label']) ?: 'CONCEPTO', 'C' => 'IMPORTE', 'D' => '% INGRESOS', 'E' => 'FUENTE'] as $c => $t) {
                $this->put($ws, "{$c}{$r}", $t);
            }
            $this->headerRow($ws, "B{$r}:E{$r}");
            $ws->getStyle("C{$r}:D{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $hdr = $r;
            $r++;
            foreach ($sec['lines'] as $l) {
                $this->put($ws, "B{$r}", $l['label']);
                ($l['state'] ?? null) === 'sin_informacion' ? $this->put($ws, "C{$r}", 'Sin información') : $this->money($ws, "C{$r}", $l['amount']);
                $this->pct($ws, "D{$r}", $l['pct'] ?? null);
                $this->put($ws, "E{$r}", $this->shortSource($l['source'] ?? ''));
                $ws->getStyle("E{$r}")->applyFromArray(['font' => ['size' => 8, 'color' => ['rgb' => self::MUTED]]]);
                $this->rowLine($ws, "B{$r}:E{$r}", false);
                $r++;
            }
            if ($r - 1 > $hdr) {
                $ws->setAutoFilter("B{$hdr}:E".($r - 1));
                $this->dataBar($ws, 'D'.($hdr + 1).':D'.($r - 1), self::CYAN);
            }
            $this->put($ws, "B{$r}", $sec['total']['label']);
            $this->money($ws, "C{$r}", $sec['total']['amount']);
            $this->pct($ws, "D{$r}", $sec['total']['pct'] ?? null);
            $ws->getStyle("B{$r}:E{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => self::INK]]]);
            $ws->getStyle("B{$r}:E{$r}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SOFT]]]);
            $this->edge($ws, "B{$r}:E{$r}", 'top', Border::BORDER_THIN, self::TEAL);
            $r += 3;
        }
        $ws->freezePane('A7');
    }

    private function sheetItems(Worksheet $ws, string $title, callable $filter, ?string $restrictedKey = null): void
    {
        $this->setup($ws, $this->movementWidths());
        $this->banner($ws, $title, 'R');
        if ($restrictedKey !== null && ! empty($this->d['meta']['redactions'][$restrictedKey])) {
            $this->restrictedNote($ws, 6, 'El detalle de Dirección General está restringido para tu rol (permiso «Consultar detalle de Dirección General»). El total sí está incluido en el OPEX y en el EBITDA.');
            $ws->freezePane('A5');

            return;
        }
        $items = array_values(array_filter((array) ($this->d['movements'] ?? []), fn ($m) => ($m['in_period'] ?? false) && $filter($m)));
        $this->put($ws, 'B6', count($items).' movimiento(s). Sólo los marcados «Suma» afectan el resultado; los pendientes NO suman hasta clasificarse.');
        $ws->getStyle('B6')->applyFromArray(['font' => ['italic' => true, 'size' => 8, 'color' => ['rgb' => self::MUTED]]]);
        $this->movementTable($ws, 8, $items);
    }

    private function restrictedNote(Worksheet $ws, int $row, string $text): void
    {
        $this->put($ws, "B{$row}", '⚠  '.$text);
        $ws->getStyle("B{$row}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => self::AMBER]]]);
    }

    private function sheetPayroll(Worksheet $ws): void
    {
        $this->sheetLines($ws, 'Detalle · Nómina y capital humano', ['nomina_sucursales', 'nomina_corporativa']);
        if (! empty($this->d['meta']['redactions']['nomina'])) {
            $this->restrictedNote($ws, $ws->getHighestRow() + 2, 'El detalle de nómina está restringido para tu rol (permiso «Consultar detalle de nómina»). Los totales sí están incluidos en el resultado.');

            return;
        }
        $items = array_values(array_filter((array) ($this->d['movements'] ?? []), fn ($m) => ($m['in_period'] ?? false) && $m['treatment'] === 'nomina_corporativa'));
        if ($items !== []) {
            $start = $ws->getHighestRow() + 2;
            $this->put($ws, "B{$start}", 'DETALLE NÓMINA CORPORATIVA');
            $ws->getStyle("B{$start}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => self::TEAL]]]);
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
        $this->banner($ws, 'Conciliación de movimientos', 'R');
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
            $this->rowLine($ws, "B{$r}:F{$r}", false);
            $r++;
        }
        $this->movementTable($ws, $r + 1, (array) ($this->d['movements'] ?? []));
    }

    private function sheetAdjustments(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 46, 'C' => 18, 'D' => 20, 'E' => 40, 'F' => 40]);
        $this->banner($ws, 'Ajustes contables y flujo', 'F');
        $this->note($ws, 'B5', '«Sin información» NO equivale a $0.00: el resultado afectado se marca incompleto (y un ISR pendiente deja pendiente la utilidad neta).', self::AMBER);
        foreach (['B7' => 'CONCEPTO', 'C7' => 'ESTADO', 'D7' => 'IMPORTE', 'E7' => 'EFECTO', 'F7' => 'NOTAS / CAPTURÓ'] as $c => $t) {
            $this->put($ws, $c, $t);
        }
        $this->headerRow($ws, 'B7:F7');
        $r = 8;
        $stateLabel = ['sin_informacion' => '⚠ Sin información', 'confirmado_cero' => '✓ Confirmado $0', 'capturado' => '✓ Capturado'];
        foreach ((array) ($this->d['adjustments'] ?? []) as $a) {
            $this->put($ws, "B{$r}", $a['label']);
            $this->put($ws, "C{$r}", $stateLabel[$a['state']] ?? $a['state']);
            $a['amount'] !== null ? $this->money($ws, "D{$r}", $a['amount']) : $this->put($ws, "D{$r}", '—');
            $this->put($ws, "E{$r}", $a['effect'] ?? '');
            $this->put($ws, "F{$r}", trim(($a['notes'] ?? '').(! empty($a['updated_by']) ? ' · '.$a['updated_by'].' '.($a['updated_at'] ?? '') : '')));
            $ws->getStyle("C{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => $a['state'] === 'sin_informacion' ? self::AMBER : self::OK]]]);
            if ($a['state'] === 'sin_informacion') {
                $ws->getStyle("B{$r}")->getFont()->getColor()->setRGB(self::AMBER);
            }
            $this->rowLine($ws, "B{$r}:F{$r}", false);
            $r++;
        }
        $r += 2;
        $this->put($ws, "B{$r}", 'CAPEX no reduce el EBITDA (flujo de inversión). El capital pagado de préstamos no es gasto financiero: sólo los intereses afectan el EBT. Los movimientos personales de socios/CEO no son OPEX.');
        $ws->mergeCells("B{$r}:F{$r}");
        $ws->getStyle("B{$r}")->getAlignment()->setWrapText(true);
        $ws->getStyle("B{$r}")->applyFromArray(['font' => ['size' => 8, 'color' => ['rgb' => self::MUTED]]]);
        $ws->getRowDimension($r)->setRowHeight(32);
        $ws->freezePane('A8');
    }

    private function sheetOutside(Worksheet $ws): void
    {
        $this->setup($ws, $this->movementWidths());
        $this->banner($ws, 'Movimientos fuera del Estado de Resultados', 'R');
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
            $this->rowLine($ws, "B{$r}:R{$r}", false);
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
        $this->headerRow($ws, "B{$r}:R{$r}", true);
        $ws->setAutoFilter("B{$r}:R".max($r, $r + count($items)));
        if ($freeze) {
            $ws->freezePane('C'.($r + 1));
            $ws->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($r, $r);
        }
        $r++;
        foreach ($items as $m) {
            $erp = $m['erp'][0] ?? null;
            $origin = match ($m['source'] ?? '') {
                'erp' => 'ERP', 'noi_corp' => 'NOI corporativo', default => ErFormat::mask((string) ($m['account_label'] ?? $m['account_slot'] ?? '')).' f.'.($m['source_row'] ?? '')
            };
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
            $this->put($ws, "P{$r}", $included ? '✓ Suma '.ErFormat::money($m['impact']['amount'] ?? 0) : 'No suma');
            $reason = ! empty($m['override']) ? ('Manual: '.$m['override']['notes'].' — '.$m['override']['user'].' '.$m['override']['at'].' (antes: '.$m['override']['previous'].')') : ($m['rule_reason'] ?? '');
            if (! empty($m['reconciliation_message']) && $m['reconciliation_message'] !== $reason) {
                $reason = trim($reason.' | '.$m['reconciliation_message']);
            }
            $this->put($ws, "Q{$r}", ! empty($m['suggested_label']) ? 'Sugerido: '.$m['suggested_label'] : '');
            $this->put($ws, "R{$r}", ErFormat::mask($reason));
            $color = $included ? self::OK : (! empty($m['needs_review']) ? self::AMBER : self::MUTED);
            $ws->getStyle("P{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => $color]]]);
            $r++;
        }
    }

    private function setup(Worksheet $ws, array $widths, bool $landscape = true): void
    {
        $ws->setShowGridlines(false);
        foreach ($widths as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }
        $ws->getPageSetup()->setOrientation($landscape ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT)
            ->setPaperSize(PageSetup::PAPERSIZE_LETTER)->setFitToWidth(1)->setFitToHeight(0)->setHorizontalCentered(true);
        $ws->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.45)->setBottom(0.6)->setFooter(0.3);
        $esc = fn (string $t) => str_replace('&', '&&', $t);
        $rep = $this->x['report'];
        $ws->getHeaderFooter()->setOddFooter('&L&8&K64748BMR LANA · Estado de Resultados · '.$esc($this->x['meta']['period']['label']).' · '.$esc($this->x['meta']['scope']['label'])
            .' · Versión '.$rep['version'].' '.$esc($rep['display_status']).'&R&8&K64748BPágina &P de &N');
        $ws->getHeaderFooter()->setEvenFooter($ws->getHeaderFooter()->getOddFooter());
    }

    /**
     * Encabezado uniforme (filas 1-4): logo en su propia franja (proporción original, alineado
     * al margen de contenido), título, alcance · periodo · moneda, estado y versión, liga al
     * índice y una barra de acento. El contenido de cada hoja empieza en la fila 5.
     */
    private function banner(Worksheet $ws, string $title, string $lastCol, bool $back = true): void
    {
        $ws->getRowDimension(1)->setRowHeight(42);
        $ws->getRowDimension(2)->setRowHeight(27);
        $ws->getRowDimension(3)->setRowHeight(18);
        $ws->getRowDimension(4)->setRowHeight(5);
        $logo = public_path('logo-mr-lana.png');
        if (is_file($logo)) {
            $drawing = new Drawing;
            $drawing->setName('MR LANA')->setDescription('Logo MR LANA')->setPath($logo);
            $drawing->setResizeProportional(true);
            $drawing->setHeight(36); // 508×192 → 95×36 px: misma proporción, sin deformar.
            $drawing->setCoordinates('B1')->setOffsetX(1)->setOffsetY(14);
            $drawing->setWorksheet($ws);
        } else {
            $this->put($ws, 'B1', 'MR LANA');
            $ws->getStyle('B1')->applyFromArray(['font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => self::TEAL]]]);
        }
        $ws->getStyle('B1')->getAlignment()->setVertical(Alignment::VERTICAL_BOTTOM);

        $this->put($ws, 'B2', $title);
        $ws->getStyle('B2')->applyFromArray(['font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => self::INK]]]);
        $ws->getStyle('B2')->getAlignment()->setVertical(Alignment::VERTICAL_BOTTOM);
        $m = $this->x['meta'];
        $fmt = fn ($d) => $d ? Carbon::parse($d)->format('d/m/Y') : null;
        $range = $fmt($m['period']['start']) && $fmt($m['period']['end']) ? '  ('.$fmt($m['period']['start']).' – '.$fmt($m['period']['end']).')' : '';
        $this->put($ws, 'B3', $m['scope']['label'].'   ·   '.$m['period']['label'].$range.'   ·   Cifras en MXN');
        $ws->getStyle('B3')->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => self::TEAL]]]);
        $ws->getStyle('B3')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        // Derecha: liga al índice (fila 1), estado + versión (fila 2), aviso breve (fila 3).
        if ($back) {
            $this->put($ws, "{$lastCol}1", '←  Índice');
            $this->linkTo($ws, "{$lastCol}1", self::SHEETS['indice'], 'A1', 'Volver al índice');
            $ws->getStyle("{$lastCol}1")->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => self::TEAL]]]);
        } else {
            $this->put($ws, "{$lastCol}1", 'Emitido '.$this->x['report']['issued_at']);
            $ws->getStyle("{$lastCol}1")->applyFromArray(['font' => ['size' => 8, 'color' => ['rgb' => self::MUTED]]]);
        }
        $ws->getStyle("{$lastCol}1")->applyFromArray(['alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER]]);
        $rep = $this->x['report'];
        [$glyph, $color] = match (true) {
            $rep['preliminary'] => ['●', self::BAD],
            $rep['approved'] => ['✓', self::OK],
            default => ['●', self::AMBER],
        };
        $this->put($ws, "{$lastCol}2", $glyph.'  '.$rep['display_status'].'  ·  VERSIÓN '.$rep['version']);
        $ws->getStyle("{$lastCol}2")->applyFromArray(['font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => $color]]]);
        $ws->getStyle("{$lastCol}2")->applyFromArray(['alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_BOTTOM]]);
        if ($rep['preliminary']) {
            $this->put($ws, "{$lastCol}3", 'Las cifras pueden cambiar');
            $ws->getStyle("{$lastCol}3")->applyFromArray(['font' => ['size' => 8, 'color' => ['rgb' => self::BAD]]]);
            $ws->getStyle("{$lastCol}3")->applyFromArray(['alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER]]);
        }

        // Barra de acento (degradado verde → cian, como el PDF).
        $ws->mergeCells("B4:{$lastCol}4");
        $fill = $ws->getStyle('B4')->getFill();
        $fill->setFillType(Fill::FILL_GRADIENT_LINEAR)->setRotation(0);
        $fill->getStartColor()->setRGB(self::GREEN);
        $fill->getEndColor()->setRGB(self::CYAN);
    }

    private function headerRow(Worksheet $ws, string $range, bool $wrap = false): void
    {
        $style = $ws->getStyle($range);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::TEAL);
        $style->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('FFFFFF');
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText($wrap);
        preg_match('/\d+/', $range, $m);
        $ws->getRowDimension((int) $m[0])->setRowHeight($wrap ? 30 : 22);
        $first = explode(':', $range)[0];
        $ws->getStyle($first)->getAlignment()->setIndent(1);
    }

    /** Renglón de tabla: línea inferior tenue (+ centrado vertical) en una sola operación de estilo. */
    private function rowLine(Worksheet $ws, string $range, bool $center = true): void
    {
        $style = ['borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => self::LINE]]]];
        if ($center) {
            $style['alignment'] = ['vertical' => Alignment::VERTICAL_CENTER];
        }
        $ws->getStyle($range)->applyFromArray($style, false);
    }

    /**
     * Borde de un lado en cada celda del rango, en UNA operación de estilo (modo simple de
     * PhpSpreadsheet). El encadenado getBorders()->getBottom()->… usa el modo avanzado por
     * regiones y es varias veces más lento en libros con muchos estilos.
     */
    private function edge(Worksheet $ws, string $range, string $side, string $style, string $color): void
    {
        $ws->getStyle($range)->applyFromArray(['borders' => [$side => ['borderStyle' => $style, 'color' => ['rgb' => $color]]]], false);
    }

    /** Rótulo de sección: versalitas pequeñas en verde azulado. */
    private function label(Worksheet $ws, string $cell, string $text): void
    {
        $this->put($ws, $cell, $text);
        $ws->getStyle($cell)->applyFromArray(['font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => self::TEAL]]]);
    }

    private function note(Worksheet $ws, string $cell, string $text, string $color = self::MUTED): void
    {
        $this->put($ws, $cell, $text);
        $ws->getStyle($cell)->applyFromArray(['font' => ['italic' => true, 'size' => 8, 'color' => ['rgb' => $color]]]);
    }

    /** Marca breve (⚠ Sin capturar, Incompleto…) en una celda de observaciones. */
    private function flag(Worksheet $ws, string $cell, string $text, string $color = self::AMBER): void
    {
        if ($text === '') {
            return;
        }
        $this->put($ws, $cell, $text);
        $ws->getStyle($cell)->applyFromArray(['font' => ['bold' => true, 'size' => 8, 'color' => ['rgb' => $color]]]);
    }

    /** Celda con fondo suave y texto de color (píldora de estado / evaluación). */
    private function pill(Worksheet $ws, string $cell, string $bg, string $fg): void
    {
        $ws->getStyle($cell)->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]]]);
        $ws->getStyle($cell)->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => $fg]]]);
    }

    /** Hipervínculo interno a otra hoja del libro. */
    private function linkTo(Worksheet $ws, string $cell, string $sheet, string $target = 'A1', ?string $tooltip = null): void
    {
        $link = $ws->getCell($cell)->getHyperlink();
        $link->setUrl("sheet://'".str_replace("'", "''", $sheet)."'!".$target);
        if ($tooltip !== null) {
            $link->setTooltip($tooltip);
        }
    }

    private function detailLink(Worksheet $ws, string $cell, string $sheet, string $text = 'Ver detalle  ›'): void
    {
        $this->put($ws, $cell, $text);
        $this->linkTo($ws, $cell, $sheet, 'A1', 'Abrir «'.$sheet.'»');
        $ws->getStyle($cell)->applyFromArray(['font' => ['bold' => true, 'italic' => false, 'size' => 8, 'color' => ['rgb' => self::TEAL]]]);
    }

    private function dataBar(Worksheet $ws, string $range, string $color): void
    {
        $bar = new ConditionalDataBar;
        $bar->setMinimumConditionalFormatValueObject(new ConditionalFormatValueObject('num', '0'))
            ->setMaximumConditionalFormatValueObject(new ConditionalFormatValueObject('max'))
            ->setColor('FF'.$color);
        $cond = new Conditional;
        $cond->setConditionType(Conditional::CONDITION_DATABAR)->setDataBar($bar);
        $ws->getStyle($range)->setConditionalStyles([$cond]);
    }

    private function pctText(?string $pct, string $suffix): string
    {
        return $pct === null ? '—' : ErFormat::pct($pct).' '.$suffix;
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

    /** Columna ✓: fórmula del libro − cifra del dataset (debe dar ✓ = 0.00). */
    private function control(Worksheet $ws, int $r, ?string $expected): void
    {
        if ($expected === null) {
            return;
        }
        $ws->setCellValue("E{$r}", "=ROUND(N(C{$r})-({$this->num($expected)}),2)");
        $ws->getStyle("E{$r}")->getNumberFormat()->setFormatCode(self::CONTROL);
        $ws->getStyle("E{$r}")->applyFromArray(['font' => ['size' => 9, 'color' => ['rgb' => self::GREEN]]]);
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
