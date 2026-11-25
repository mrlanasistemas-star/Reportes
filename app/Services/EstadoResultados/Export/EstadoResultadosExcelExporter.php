<?php

namespace App\Services\EstadoResultados\Export;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
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
    /** Hojas principales: clave ⇒ título de la pestaña. */
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
        'movimientos' => ['Anexo Movimientos', 'Movimientos bancarios y del ERP del periodo'],
        'ajustes' => ['Anexo Ajustes', 'Depreciación, intereses e ISR'],
        'fuera' => ['Anexo Fuera del ER', 'Movimientos que no afectan el resultado'],
    ];

    /** Hoja de financiamientos (sólo consolidado con créditos registrados). */
    public const FINANCING_SHEET = 'Financiamientos';

    /**
     * Hoja de retiros de Dirección General y socios (sólo consolidado). Reemplaza al antiguo
     * «Anexo DG»: DG y socios ya no son OPEX y se restan después de la Utilidad neta.
     */
    public const WITHDRAWALS_SHEET = ErWithdrawalsSection::TITLE;

    /** Tratamientos y reglas de DG y socios (respaldo para datasets sin el bloque `withdrawals`). */
    private const DG_TREATMENTS = ['dg_operativo', 'dg_remuneracion', 'personal_socios'];

    private const DG_RULES = ['dg_requiere_revision', 'erp_dg', 'retiro_dg', 'retiro_socios', 'retiro_dg_devolucion'];

    /** Descripción breve de cada hoja principal (índice). */
    private const DESCRIPTIONS = [
        'resumen' => 'Resultados clave y variación contra el mes anterior',
        'er' => 'Del ingreso a la utilidad neta, renglón por renglón',
        'comparativa' => 'Mes anterior, mismo mes del año pasado y acumulado',
        'evolucion' => 'Tendencia de los últimos meses',
        'sucursales' => 'Resultado por sucursal y gastos centralizados',
        'conciliacion' => 'Fuentes, validaciones y control del documento',
    ];

    /** Formatos numéricos de Excel (moneda, variaciones, porcentajes, puntos y control ✓). */
    private const MONEY = '$#,##0.00;[Red]-$#,##0.00;$0.00';

    /** Variaciones: la flecha indica la dirección; el color lo da la evaluación (favorable / desfavorable). */
    private const MONEY_DELTA = '"▲ "+$#,##0.00;"▼ "-$#,##0.00;$0.00';

    private const MONEY_DELTA_SIGN = '"▲ "+$#,##0.00;[Red]"▼ "-$#,##0.00;$0.00';

    private const PCT = '0.00%;[Red]-0.00%';

    private const PCT_DELTA = '"▲ "+0.00%;"▼ "-0.00%;0.00%';

    private const PP = '"▲ "+0.00" pp";"▼ "-0.00" pp";0.00" pp"';

    private const CONTROL = '0.00;-0.00;"✓"';

    /** Tipografía y paleta de la marca (RGB sin #). */
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

    /** Color de cada serie en las gráficas. */
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

    /** Dataset resuelto de la exportación en curso. */
    private array $d;

    /** Contexto de presentación (EstadoResultadosExportContext::make) de la exportación en curso. */
    private array $x;

    /** Celda (en «Estado de Resultados») de cada resultado del modelo: key => 'C12'. */
    private array $erCells = [];

    /** Fila (en «Estado de Resultados») de cada paso, también en el modelo anterior: key => 12. */
    private array $erRows = [];

    /** Sección de financiamientos (ErFinancingSection::build) de la exportación en curso, o null. */
    private ?array $fin = null;

    /** ¿El libro lleva la hoja «Retiros DG y socios»? (sólo consolidado). */
    private bool $withdrawalsSheet = false;

    /**
     * @param  EstadoResultadosExportContext  $context  contexto de presentación compartido con el PDF
     * @param  ErFinancingSection  $financing  sección de financiamientos (FinancialExpenseService)
     */
    public function __construct(
        private readonly EstadoResultadosExportContext $context,
        private readonly ErFinancingSection $financing,
    ) {}

    /**
     * Genera el libro completo (hojas principales, anexos e índice) y lo guarda en disco.
     * El ER se escribe primero porque el resumen y el índice referencian sus celdas. Crea el
     * directorio de salida si no existe y usa precision = -1 al guardar (lo restaura después).
     *
     * @param  array  $dataset  dataset resuelto (ya redactado)
     * @param  string  $outputPath  ruta del .xlsx a generar
     * @param  array|null  $comparisons  payload de ComparisonService (o null)
     * @param  string|null  $comparisonsError  motivo si no hay comparativas
     * @return string ruta del archivo generado
     */
    public function export(array $dataset, string $outputPath, ?array $comparisons = null, ?string $comparisonsError = null): string
    {
        $this->d = $dataset;
        $this->x = $this->context->make($dataset, $comparisons, $comparisonsError);
        $this->erCells = [];
        $this->erRows = [];
        $this->withdrawalsSheet = (bool) $this->x['meta']['consolidated'];
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
        $this->sheetMovements($a('movimientos'));
        $this->sheetAdjustments($a('ajustes'));
        $this->sheetOutside($a('fuera'));
        $this->fin = $this->financing->build($dataset);
        if ($this->fin !== null) {
            $this->sheetFinancing($book->createSheet()->setTitle(self::FINANCING_SHEET));
        }
        if ($this->withdrawalsSheet) {
            $this->sheetWithdrawals($book->createSheet()->setTitle(self::WITHDRAWALS_SHEET));
        }

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

    /**
     * Hoja «Índice»: portada con resultados clave y ligas a cada hoja y anexo.
     *
     * @param  Worksheet  $ws  hoja
     */
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
        if ($this->fin !== null) {
            $this->tocRow($ws, $row++, '·', self::FINANCING_SHEET, 'Créditos, pagos al acreedor, aportaciones y distribución gerencial', false);
        }
        if ($this->withdrawalsSheet) {
            $this->tocRow($ws, $row++, '·', self::WITHDRAWALS_SHEET, 'Lo que tomaron Dirección General y los socios (después de la Utilidad neta)', false);
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

    /**
     * Renglón del índice con número, título enlazado a la hoja y descripción.
     *
     * @param  Worksheet  $ws  hoja índice
     * @param  int  $r  fila
     * @param  string  $num  número/prefijo
     * @param  string  $title  título visible
     * @param  string  $desc  descripción breve
     * @param  bool  $main  hoja principal (estilo destacado) o anexo
     * @param  string|null  $sheet  hoja destino (por defecto el título)
     */
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

    /**
     * Hoja «Estado de Resultados»: renglón por renglón del ingreso a la utilidad neta, con
     * subtotales como FÓRMULAS sobre el libro y columna ✓ contra la cifra del dataset. Registra
     * en $erCells/$erRows la celda/fila de cada paso para que otras hojas la referencien.
     *
     * @param  Worksheet  $ws  hoja
     */
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

        // Sección/categoría ⇒ hoja de detalle. DG (modelo anterior) y retiros ⇒ «Retiros DG y socios».
        $annexOf = array_map(fn ($k) => self::ANNEXES[$k][0], [
            'opex_sucursales' => 'opex_suc', 'nomina_sucursales' => 'nomina', 'nomina_corporativa' => 'nomina', 'opex_corporativo' => 'opex_corp',
            'da' => 'ajustes', 'financiero' => 'ajustes', 'isr' => 'ajustes',
        ]);
        if ($this->withdrawalsSheet) {
            $annexOf['dg'] = $annexOf['retiros'] = self::WITHDRAWALS_SHEET;
        }
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
                    $this->detailLink($ws, "F{$r}", $annexOf[$block['key']]);
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
                            $this->detailLink($ws, "F{$r}", $annexOf[$cat['key']], $centralized ? 'Centralizado · Ver detalle  ›' : 'Ver detalle  ›');
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
                $stepKey = ['ingresos' => 'ingresos_operativos', 'opex' => 'opex_total', 'da' => 'depreciacion_amortizacion', 'financiero' => 'resultado_financiero_neto', 'isr' => 'isr', 'retiros' => 'retiros_dg_socios'][$block['key'] ?? ''] ?? null;
                if (($block['key'] ?? '') === 'retiros') {
                    // Retiros DG y socios: después de la Utilidad neta, en rojo (no son OPEX).
                    $ws->getStyle("B{$r}:E{$r}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF2F2']]]);
                    $this->edge($ws, "B{$r}:E{$r}", 'top', Border::BORDER_THIN, self::BAD);
                }
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
            $formula = $this->x['legacy'] ? null : $this->resultFormula($key, $block);
            if (($block['amount'] ?? null) === null && $formula === null) {
                $this->put($ws, "C{$r}", ErFormat::PENDING);
            } else {
                $this->formulaOrValue($ws, "C{$r}", $formula, $block['amount'] ?? null);
                if (($block['amount'] ?? null) !== null) {
                    $this->control($ws, $r, $block['amount']);
                }
            }
            if (! $this->x['legacy'] && in_array($key, ['ebitda', 'ebit', 'ebt', 'utilidad_neta', 'resultado_despues_retiros'], true)) {
                $this->erCells[$key] = "C{$r}";
            }
            $this->erRows[$key === 'ebitda_final' ? 'ebitda' : $key] = $r;
            $pctRows[] = $r;
            $fill = match (true) {
                $key === 'resultado_despues_retiros' => ($block['negative'] ?? false) ? '991B1B' : '334155',
                $key === 'utilidad_neta' => '065F46',
                in_array($key, ['ebitda', 'ebitda_final'], true) => self::TEAL,
                $key === 'ebt' => self::TEAL_DARK,
                default => '0E7490',
            };
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

    /**
     * Escribe las líneas de una sección (etiqueta con sangría, importe o «Sin información»,
     * fuente) y acumula las filas que llevan % sobre ingresos.
     *
     * @param  Worksheet  $ws  hoja
     * @param  int  $r  fila inicial
     * @param  array  $lines  líneas de la sección
     * @param  string  $indent  sangría de la etiqueta
     * @param  array  $pctRows  filas con porcentaje (por referencia)
     * @return array{int,int,int} [primera fila, última fila, siguiente fila]
     */
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
    private function resultFormula(string $key, array $block = []): ?string
    {
        $c = $this->erCells;
        // Resultado después de retiros = Utilidad neta (o EBT con ISR pendiente) − Retiros DG y socios.
        $base = ($block['base'] ?? 'utilidad_neta') === 'ebt' ? 'ebt' : 'utilidad_neta';

        return match ($key) {
            'resultado_despues_retiros' => isset($c[$base], $c['retiros_dg_socios']) ? "=IF(ISNUMBER({$c[$base]}),{$c[$base]}-N({$c['retiros_dg_socios']}),\"Pendiente\")" : null,
            'ebitda' => isset($c['ingresos_operativos'], $c['opex_total']) ? "={$c['ingresos_operativos']}-{$c['opex_total']}" : null,
            // Un ajuste «Sin información» se toma como 0 de forma EXPLÍCITA (N) y el renglón se rotula INCOMPLETO, igual que la web.
            'ebit' => isset($c['ebitda'], $c['depreciacion_amortizacion']) ? "={$c['ebitda']}-N({$c['depreciacion_amortizacion']})" : null,
            'ebt' => isset($c['ebit'], $c['resultado_financiero_neto']) ? "={$c['ebit']}+N({$c['resultado_financiero_neto']})" : null,
            'utilidad_neta' => isset($c['ebt'], $c['isr']) ? "=IF(ISNUMBER({$c['isr']}),{$c['ebt']}-{$c['isr']},\"Pendiente\")" : null,
            default => null,
        };
    }

    // ── 1 · Resumen ejecutivo ──────────────────────────────────────────

    /**
     * Hoja «Resumen ejecutivo»: resultados clave (referenciando el ER), márgenes, variación
     * contra el periodo anterior y gráficas nativas.
     *
     * @param  Worksheet  $ws  hoja
     */
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
        // Retiros DG y socios (sólo consolidado): después de la Utilidad neta, sin tocar el EBITDA.
        if ($wd = $this->x['withdrawals'] ?? null) {
            $nSteps = count($this->x['steps']);
            foreach ([['retiros_dg_socios', ($nSteps + 1).'. Retiros DG y socios', $wd['total'], false], ['resultado_despues_retiros', ($nSteps + 2).'. '.$wd['result_title'], $wd['result'], true]] as [$k, $label, $amount, $isResult]) {
                $this->put($ws, "B{$r}", $label);
                if (isset($this->erRows[$k])) {
                    $this->linkTo($ws, "B{$r}", self::SHEETS['er'], 'B'.$this->erRows[$k], 'Ver el detalle en el Estado de Resultados');
                    $ws->getStyle("B{$r}")->getFont()->getColor()->setRGB(self::INK);
                }
                if (isset($this->erCells[$k])) {
                    $ws->setCellValue("C{$r}", "='".self::SHEETS['er']."'!".$this->erCells[$k]);
                    $ws->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::MONEY);
                } else {
                    $amount === null ? $this->put($ws, "C{$r}", ErFormat::PENDING) : $this->money($ws, "C{$r}", $amount);
                }
                $ws->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $this->flag($ws, "H{$r}", $isResult ? ($wd['negative'] ? '⚠ En rojo: tomaron dinero del flujo siguiente' : $wd['formula']) : 'No es OPEX: no cambia el EBITDA', $isResult && $wd['negative'] ? self::BAD : self::MUTED);
                $ws->getRowDimension($r)->setRowHeight($isResult ? 22 : 18);
                $this->rowLine($ws, "B{$r}:H{$r}");
                if ($isResult) {
                    $ws->getStyle("B{$r}:F{$r}")->getFont()->setBold(true);
                    $ws->getStyle("B{$r}:F{$r}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $wd['negative'] ? 'FEF2F2' : self::CARD]]]);
                }
                $r++;
            }
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

    /**
     * Celdas D–G de comparación de un concepto: anterior, variación ($ o pp), variación % y
     * lectura (favorable/desfavorable) o el motivo breve si no se calcula.
     *
     * @param  Worksheet  $ws  hoja
     * @param  int  $r  fila
     * @param  array|null  $row  fila de comparación de ComparisonService
     * @param  array|null  $pair  modo de comparación (null = no disponible)
     * @param  bool  $isMargin  el concepto es un margen (pp en lugar de $)
     */
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

    /**
     * Motivo breve para dirección (SHORT_REASONS) o el mensaje completo si no hay versión corta.
     *
     * @param  array  $reason  {code, message}
     */
    private function shortReason(array $reason): string
    {
        return self::SHORT_REASONS[$reason['code'] ?? ''] ?? (string) ($reason['message'] ?? '');
    }

    // ── 3 · Comparativa mensual ────────────────────────────────────────

    /**
     * Hoja «Comparativa mensual»: mes anterior, mismo mes del año pasado, personalizado y
     * acumulado, con comparabilidad y categorías OPEX. Si no hay comparativas, sólo el aviso.
     *
     * @param  Worksheet  $ws  hoja
     */
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

    /**
     * Hoja «Evolución histórica»: tabla por mes (meses sin datos como «Sin datos», nunca $0) y
     * gráficas de tendencia.
     *
     * @param  Worksheet  $ws  hoja
     */
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

    /**
     * Hoja «Sucursales»: ingresos, OPEX directo, EBITDA y margen por sucursal, participación,
     * variación contra el mes anterior, gastos centralizados y conciliación con el consolidado.
     *
     * @param  Worksheet  $ws  hoja
     */
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

    /**
     * Hoja «Conciliación y trazabilidad»: datos de control del documento (versión, hash,
     * criterio), estado de fuentes, validaciones y conciliación con Radiografía.
     *
     * @param  Worksheet  $ws  hoja
     */
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

    /**
     * Símbolo del estado de una fuente (✓ procesado, ○ pendiente, ⚠ incidencias, ● otro).
     *
     * @param  string  $status  estado
     */
    private function sourceGlyph(string $status): string
    {
        return match ($status) {
            'procesado' => '✓', 'pendiente' => '○', 'con_incidencias' => '⚠', default => '●',
        };
    }

    /**
     * Color RGB del estado de una fuente.
     *
     * @param  string  $status  estado
     */
    private function sourceColor(string $status): string
    {
        return match ($status) {
            'procesado' => self::OK, 'pendiente' => self::MUTED, 'con_incidencias' => self::BAD, default => self::AMBER,
        };
    }

    // ── Anexos ─────────────────────────────────────────────────────────

    /**
     * Anexo «Indicadores»: financieros e informativos (no afectan el resultado).
     *
     * @param  Worksheet  $ws  hoja
     */
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

    /**
     * Anexo de líneas por concepto de las secciones/categorías indicadas (importe, % de
     * ingresos y fuente).
     *
     * @param  Worksheet  $ws  hoja
     * @param  string  $title  título
     * @param  list<string>  $keys  claves de sección/categoría
     */
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

    /**
     * Anexo con la tabla de movimientos que cumplen $filter; si el detalle está redactado para
     * el usuario ($restrictedKey) sólo muestra el aviso.
     *
     * @param  Worksheet  $ws  hoja
     * @param  string  $title  título
     * @param  callable  $filter  movimiento ⇒ bool
     * @param  string|null  $restrictedKey  clave de meta.redactions (p. ej. 'dg')
     */
    private function sheetItems(Worksheet $ws, string $title, callable $filter, ?string $restrictedKey = null): void
    {
        $this->setup($ws, $this->movementWidths());
        $this->banner($ws, $title, 'R');
        if ($restrictedKey !== null && ! empty($this->d['meta']['redactions'][$restrictedKey])) {
            $this->restrictedNote($ws, 6, 'El detalle de Dirección General está restringido para tu rol (permiso «Consultar detalle de Dirección General»). El total sí está incluido en el resultado.');
            $ws->freezePane('A5');

            return;
        }
        $items = array_values(array_filter((array) ($this->d['movements'] ?? []), fn ($m) => ($m['in_period'] ?? false) && $filter($m)));
        $this->put($ws, 'B6', count($items).' movimiento(s). Sólo los marcados «Suma» afectan el resultado; los pendientes NO suman hasta clasificarse.');
        $ws->getStyle('B6')->applyFromArray(['font' => ['italic' => true, 'size' => 8, 'color' => ['rgb' => self::MUTED]]]);
        $this->movementTable($ws, 8, $items);
    }

    /**
     * Aviso destacado de detalle restringido por permisos.
     *
     * @param  Worksheet  $ws  hoja
     * @param  int  $row  fila
     * @param  string  $text  texto
     */
    private function restrictedNote(Worksheet $ws, int $row, string $text): void
    {
        $this->put($ws, "B{$row}", '⚠  '.$text);
        $ws->getStyle("B{$row}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => self::AMBER]]]);
    }

    /**
     * Anexo «Nómina»: líneas de nómina de sucursales y corporativa y, si el usuario tiene
     * permiso, el detalle de movimientos de nómina.
     *
     * @param  Worksheet  $ws  hoja
     */
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

    /**
     * Anexo «Movimientos»: resumen de la conciliación bancaria y tabla completa de movimientos.
     *
     * @param  Worksheet  $ws  hoja
     */
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

    /**
     * Anexo «Ajustes»: cada ajuste contable con su estado, importe, efecto y notas («Sin
     * información» nunca equivale a $0.00).
     *
     * @param  Worksheet  $ws  hoja
     */
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

    /**
     * Anexo «Fuera del ER»: grupos por tratamiento con cantidad, cargos, abonos y explicación.
     *
     * @param  Worksheet  $ws  hoja
     */
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

    // ── Retiros DG y socios ───────────────────────────────────────────────

    /**
     * Hoja «Retiros DG y socios» (sólo consolidado): resumen (total, base usada, resultado después
     * de retiros y la regla), agrupado por concepto y la lista COMPLETA de movimientos. Las cifras
     * vienen del bloque `withdrawals` del dataset (vía ErWithdrawalsSection). Sin permiso `ver_dg`
     * sólo los totales. Un dataset anterior a la regla (sin `withdrawals`) lista los movimientos de
     * Dirección General y socios del propio dataset (reemplaza al antiguo «Anexo DG»).
     *
     * @param  Worksheet  $ws  hoja
     */
    private function sheetWithdrawals(Worksheet $ws): void
    {
        $wd = $this->x['withdrawals'] ?? null;
        $restrictedDg = ! empty($this->d['meta']['redactions']['dg']);
        if ($wd === null) {
            $this->setup($ws, $this->movementWidths());
            $this->banner($ws, 'Retiros de Dirección General y socios', 'R');
            if ($restrictedDg) {
                $this->restrictedNote($ws, 6, ErWithdrawalsSection::RESTRICTED);
                $ws->freezePane('A5');

                return;
            }
            $items = array_values(array_filter((array) ($this->d['movements'] ?? []), fn ($m) => ($m['in_period'] ?? false)
                && (in_array($m['treatment'] ?? null, self::DG_TREATMENTS, true) || in_array($m['rule_code'] ?? null, self::DG_RULES, true))));
            $this->note($ws, 'B5', 'Versión del reporte anterior a la regla de retiros: se listan los movimientos de Dirección General y socios tal como se calcularon.', self::AMBER);
            $this->put($ws, 'B6', count($items).' movimiento(s). Sólo los marcados «Suma» afectan el resultado.');
            $ws->getStyle('B6')->applyFromArray(['font' => ['italic' => true, 'size' => 8, 'color' => ['rgb' => self::MUTED]]]);
            $this->movementTable($ws, 8, $items);

            return;
        }

        $this->setup($ws, ['A' => 2, 'B' => 13, 'C' => 46, 'D' => 24, 'E' => 30, 'F' => 30, 'G' => 22, 'H' => 18, 'I' => 22, 'J' => 50]);
        $this->banner($ws, 'Retiros de Dirección General y socios', 'J');
        $this->note($ws, 'B5', $wd['rule']);

        // Resumen (rótulo / importe / nota).
        $this->label($ws, 'B6', 'RESUMEN');
        $summary = [
            ['Total de retiros', $wd['total'], $wd['count'].' movimiento(s) · no son OPEX: no cambian el EBITDA', false],
            ['Base usada · '.$wd['base_label'], $wd['base_amount'], $wd['base'] === 'ebt' ? 'ISR pendiente: el resultado se calcula sobre el EBT' : 'Resultado del periodo', false],
            [$wd['result_title'], $wd['result'], $wd['formula'], true],
        ];
        $r = 7;
        foreach ($summary as [$label, $amount, $hint, $isResult]) {
            $this->put($ws, "B{$r}", $label);
            $ws->mergeCells("B{$r}:C{$r}");
            $amount === null ? $this->put($ws, "D{$r}", ErFormat::PENDING) : $this->money($ws, "D{$r}", $amount);
            $ws->getStyle("D{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $this->put($ws, "E{$r}", $hint);
            $ws->mergeCells("E{$r}:J{$r}");
            $ws->getStyle("E{$r}")->applyFromArray(['font' => ['size' => 8, 'color' => ['rgb' => self::MUTED]]]);
            $ws->getStyle("B{$r}:D{$r}")->getFont()->setBold($isResult);
            if ($isResult) {
                $neg = $wd['negative'];
                $ws->getStyle("B{$r}:D{$r}")->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $neg ? 'FEF2F2' : self::SOFT]],
                    'font' => ['color' => ['rgb' => $neg ? self::BAD : self::INK]]]);
                $this->edge($ws, "B{$r}:D{$r}", 'top', Border::BORDER_THIN, $neg ? self::BAD : self::TEAL);
            }
            $this->rowLine($ws, "B{$r}:J{$r}");
            $ws->getRowDimension($r)->setRowHeight($isResult ? 22 : 18);
            $r++;
        }
        if ($wd['explain']) {
            $wd['negative'] ? $this->flag($ws, "B{$r}", '⚠ '.$wd['explain'], self::BAD) : $this->note($ws, "B{$r}", $wd['explain']);
            $r++;
        }
        $r++;

        if ($wd['restricted']) {
            $this->restrictedNote($ws, $r, $wd['restricted_note']);
            $ws->freezePane('A5');

            return;
        }

        // Agrupado por concepto.
        $this->label($ws, "B{$r}", 'AGRUPADO POR CONCEPTO');
        $r++;
        foreach (['B' => 'CONCEPTO', 'E' => 'MOVIMIENTOS', 'F' => 'IMPORTE', 'G' => '% DEL TOTAL'] as $col => $t) {
            $this->put($ws, "{$col}{$r}", $t);
        }
        $this->headerRow($ws, "B{$r}:G{$r}");
        $ws->getStyle("E{$r}:G{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $r++;
        $gFirst = $r;
        foreach ($wd['by_concept'] as $g) {
            $this->put($ws, "B{$r}", ErFormat::mask((string) $g['label']));
            $ws->mergeCells("B{$r}:D{$r}");
            $this->put($ws, "E{$r}", (int) $g['count']);
            $this->money($ws, "F{$r}", $g['amount']);
            $this->pct($ws, "G{$r}", $g['pct'] ?? null);
            $this->rowLine($ws, "B{$r}:G{$r}");
            $r++;
        }
        if ($wd['by_concept'] === []) {
            $this->note($ws, "B{$r}", 'Sin retiros de Dirección General ni de socios en el periodo.');
            $r++;
        }
        $this->put($ws, "B{$r}", 'Total retiros DG y socios');
        $ws->mergeCells("B{$r}:D{$r}");
        $this->put($ws, "E{$r}", (int) $wd['count']);
        if ($wd['by_concept'] !== []) {
            $this->formulaOrValue($ws, "F{$r}", '=SUM(F'.$gFirst.':F'.($r - 1).')', $wd['total']);
        } else {
            $this->money($ws, "F{$r}", $wd['total']);
        }
        $ws->getStyle("B{$r}:G{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => self::INK]], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SOFT]]]);
        $this->edge($ws, "B{$r}:G{$r}", 'top', Border::BORDER_THIN, self::TEAL);
        $r += 3;

        // Lista completa de movimientos.
        $this->label($ws, "B{$r}", 'MOVIMIENTOS · LISTA COMPLETA ('.count($wd['items']).')');
        $r++;
        $hdr = $r;
        foreach (['B' => 'FECHA', 'C' => 'CONCEPTO', 'D' => 'CUENTA', 'E' => 'QUIÉN', 'F' => 'BENEFICIARIO', 'G' => 'ORIGEN', 'H' => 'IMPORTE', 'I' => 'TRATAMIENTO', 'J' => 'MOTIVO'] as $col => $t) {
            $this->put($ws, "{$col}{$r}", $t);
        }
        $this->headerRow($ws, "B{$r}:J{$r}", true);
        $ws->getStyle("H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->freezePane('C'.($r + 1));
        $ws->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($r, $r);
        $r++;
        foreach ($wd['items'] as $i) {
            if (! empty($i['date'])) {
                $this->put($ws, "B{$r}", (float) ExcelDate::PHPToExcel(Carbon::parse($i['date'])->startOfDay()));
                $ws->getStyle("B{$r}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            }
            $this->put($ws, "C{$r}", ErFormat::mask((string) ($i['concept'] ?? '')));
            $this->put($ws, "D{$r}", (string) ErFormat::mask((string) ($i['account_label'] ?? $i['account_slot'] ?? '')));
            $this->put($ws, "E{$r}", (string) ($i['who'] ?? ''));
            $this->put($ws, "F{$r}", (string) ErFormat::mask((string) ($i['beneficiary'] ?? '')));
            $this->put($ws, "G{$r}", $i['source_label']);
            $this->money($ws, "H{$r}", $i['amount'] ?? null);
            $this->put($ws, "I{$r}", (string) ($i['treatment_label'] ?? '').(! empty($i['manual']) ? ' (manual)' : ''));
            $this->put($ws, "J{$r}", (string) ErFormat::mask((string) ($i['reason'] ?? '')));
            $this->rowLine($ws, "B{$r}:J{$r}");
            $r++;
        }
        if ($wd['items'] === []) {
            $this->note($ws, "B{$r}", 'Sin movimientos de retiro en el periodo.');
            $r++;
        } else {
            $ws->setAutoFilter("B{$hdr}:J".($r - 1));
            $this->put($ws, "G{$r}", 'Total');
            $ws->setCellValue("H{$r}", '=SUM(H'.($hdr + 1).':H'.($r - 1).')');
            $ws->getStyle("H{$r}")->getNumberFormat()->setFormatCode(self::MONEY);
            $ws->getStyle("G{$r}:H{$r}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => self::INK]], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SOFT]]]);
            $this->edge($ws, "G{$r}:H{$r}", 'top', Border::BORDER_THIN, self::TEAL);
        }
        $ws->getPageSetup()->setPrintArea("A1:J{$r}");
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Anchos de columna de las tablas de movimientos.
     *
     * @return array<string, int>
     */
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
            // «Ya considerado» parcial: esa parte no suma (el impacto ya viene sin ella).
            $considered = (float) ($m['amounts']['considered'] ?? 0) > 0 ? ' · ya considerado '.ErFormat::money($m['amounts']['considered']) : '';
            $this->put($ws, "O{$r}", ($m['treatment_label'] ?? '').(($m['treatment_source'] ?? null) === 'manual' ? ' (manual)' : '').$considered);
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

    // ── Financiamientos (gastos financieros) ───────────────────────────

    /**
     * Hoja «Financiamientos» (sólo consolidado): resumen por crédito, calendario previsto del
     * periodo, pagos bancarios detectados, aportaciones de sucursales, distribución gerencial y
     * diferencias. TODAS las cifras vienen de FinancialExpenseService (vía ErFinancingSection);
     * aquí sólo se escriben. Gráfica nativa de barras con la distribución por sucursal.
     *
     * @param  Worksheet  $ws  hoja
     */
    private function sheetFinancing(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 24, 'C' => 20, 'D' => 16, 'E' => 16, 'F' => 16, 'G' => 16, 'H' => 16, 'I' => 16, 'J' => 16, 'K' => 16, 'L' => 15, 'M' => 16, 'N' => 20, 'O' => 60]);
        $this->banner($ws, 'Financiamientos · Gastos financieros', 'O');
        $fin = $this->fin;
        if (isset($fin['error'])) {
            $this->flag($ws, 'B6', $fin['error']);
            $ws->freezePane('A5');

            return;
        }
        $s = $fin['summary'];
        $this->note($ws, 'B5', $fin['notice'] ?? ErFinancingSection::NOTE, $fin['notice'] ? self::AMBER : self::MUTED);

        // Tarjetas del resumen (fila 6 rótulo, fila 7 importe).
        $tiles = [
            'B' => ['PAGO OBSERVADO', $s['observed_payment']], 'C' => ['CAPITAL (INFORMATIVO)', $s['principal_informative']],
            'D' => ['INTERESES', $s['interest_recognized']], 'E' => ['COMISIONES *', $s['commission_recognized']],
            'F' => ['IVA SEPARADO', $s['vat_separated']], 'G' => ['EFECTO EN EBT', $s['ebt_effect']],
        ];
        foreach ($tiles as $col => [$label, $value]) {
            $this->put($ws, "{$col}6", $label);
            $this->money($ws, "{$col}7", $value);
        }
        $ws->getStyle('B6:G6')->applyFromArray(['font' => ['bold' => true, 'size' => 8, 'color' => ['rgb' => self::MUTED]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::CARD]]]);
        $ws->getStyle('B7:G7')->applyFromArray(['font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => self::INK]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::CARD]]]);
        $ws->getStyle('G6:G7')->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SOFT]]]);
        $ws->getRowDimension(7)->setRowHeight(24);
        $this->note($ws, 'H7', '* Comisión como gasto financiero: criterio por confirmar por Finanzas.'.($fin['commissions_in_result'] ? '' : ' Hoy no reduce el EBT.'));

        // 1 · Resumen por crédito (encabezados congelados + autofiltro).
        $hdr = 9;
        $headers = ['B' => 'CRÉDITO', 'C' => 'ACREEDOR', 'D' => 'COBERTURA TABLA', 'E' => 'PAGOS PREVISTOS', 'F' => 'PAGOS CUBIERTOS', 'G' => 'DEBÍA PAGAR',
            'H' => 'APLICADO', 'I' => 'CAPITAL (INFO.)', 'J' => 'INTERÉS', 'K' => 'COMISIÓN', 'L' => 'IVA', 'M' => 'FALTANTE / EXCEDENTE', 'N' => 'ESTADO', 'O' => 'MOTIVO'];
        foreach ($headers as $c => $t) {
            $this->put($ws, "{$c}{$hdr}", $t);
        }
        $this->headerRow($ws, "B{$hdr}:O{$hdr}", true);
        $ws->freezePane('C'.($hdr + 1));
        $ws->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($hdr, $hdr);
        $r = $hdr + 1;
        foreach ($fin['credits'] as $c) {
            $pt = $c['payments']['totals'];
            $this->put($ws, "B{$r}", $c['name']);
            $this->put($ws, "C{$r}", (string) $c['creditor']);
            $this->put($ws, "D{$r}", $c['coverage_label']);
            $this->put($ws, "E{$r}", (int) $pt['expected_count']);
            $this->put($ws, "F{$r}", (int) $c['application']['covered_count']);
            $this->money($ws, "G{$r}", $c['application']['expected']);
            $this->money($ws, "H{$r}", $c['application']['applied']);
            $c['covered'] ? $this->money($ws, "I{$r}", $c['totals']['principal']) : $this->put($ws, "I{$r}", '—');
            $this->money($ws, "J{$r}", $c['recognized']['interest']);
            $this->money($ws, "K{$r}", $c['recognized']['commission']);
            $this->money($ws, "L{$r}", $c['vat']);
            $this->money($ws, "M{$r}", $c['application']['difference']);
            $this->put($ws, "N{$r}", $c['status_label']);
            $this->pill($ws, "N{$r}", match ($c['status_tone']) {
                'ok' => 'ECFDF5', 'bad' => 'FEF2F2', 'mute' => 'F1F5F9', default => 'FFFBEB'
            }, match ($c['status_tone']) {
                'ok' => '065F46', 'bad' => '991B1B', 'mute' => '475569', default => '92400E'
            });
            $this->put($ws, "O{$r}", $c['status_reason']);
            $this->rowLine($ws, "B{$r}:O{$r}");
            $r++;
        }
        $ws->setAutoFilter("B{$hdr}:O".($r - 1));
        $this->put($ws, "B{$r}", 'Total');
        foreach (['G' => $s['expected_payment'], 'H' => $s['applied_payment'], 'I' => $s['principal_informative'], 'J' => $s['interest_recognized'], 'K' => $s['commission_recognized'], 'L' => $s['vat_separated']] as $col => $v) {
            $this->money($ws, "{$col}{$r}", $v);
        }
        $ws->getStyle("B{$r}:O{$r}")->applyFromArray(['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SOFT]]]);
        $this->edge($ws, "B{$r}:O{$r}", 'top', Border::BORDER_THIN, self::TEAL);
        $this->note($ws, 'B'.($r + 1), ErFinancingSection::APPLIED_NOTE);
        $r += 3;

        // 2 · Calendario previsto del periodo (tabla de amortización vs. banco).
        $r = $this->finTable($ws, $r, 'CALENDARIO PREVISTO DEL PERIODO', ['CRÉDITO', 'NÚM.', 'FECHA PREVISTA', 'PAGO PREVISTO', 'APLICADO', 'FALTANTE', 'COBERTURA', 'CAPITAL', 'INTERÉS', 'COMISIÓN', 'IVA', 'FECHA BANCO', 'CARGO BANCO', 'ESTADO BANCO'],
            collect($fin['credits'])->flatMap(fn ($c) => array_map(fn ($p) => [
                $c['name'], (int) $p['number'], ['date', $p['scheduled_date']], ['money', $p['expected_payment']], ['money', $p['applied']], ['money', $p['shortfall']],
                ErFinancingSection::coverageLabel($p['coverage'], $c['application']['basis']), ['money', $p['principal']], ['money', $p['interest']],
                ['money', $p['commission']], ['money', $p['vat']], ['date', $p['bank_date']], ['money', $p['detected_payment']],
                ErFinancingSection::PAYMENT_STATUS[$p['status']] ?? $p['status'],
            ], $c['payments']['rows']))->all(), 'Sin pagos previstos en el periodo.');

        // 3 · Pagos bancarios detectados (cargos al acreedor; cuentas y conceptos enmascarados).
        $bankRow = fn (string $credit, array $m, ?int $number, string $status) => [
            $credit, ['date', $m['date']], ['money', $m['amount']], ErFormat::mask((string) ($m['account'] ?? '')), ErFormat::mask((string) ($m['concept'] ?? '')), $number, $status,
        ];
        $r = $this->finTable($ws, $r, 'PAGOS BANCARIOS DETECTADOS', ['CRÉDITO', 'FECHA', 'IMPORTE', 'CUENTA', 'CONCEPTO', 'PAGO NÚM.', 'ESTADO'],
            collect($fin['credits'])->flatMap(fn ($c) => array_merge(
                collect($c['payments']['rows'])->flatMap(fn ($p) => array_map(fn ($m) => $bankRow($c['name'], $m, (int) $p['number'], ErFinancingSection::PAYMENT_STATUS[$p['status']] ?? $p['status']), $p['movements']))->all(),
                array_map(fn ($m) => $bankRow($c['name'], $m, null, ErFinancingSection::PAYMENT_STATUS['no_previsto']), $c['payments']['unscheduled']),
            ))->all(), 'No se detectaron cargos al acreedor en los estados de cuenta del periodo.');

        // 4 · Aportaciones de sucursales (abonos a los créditos).
        $contribRow = fn (string $credit, array $e) => [
            $credit, ['date', $e['date']], (string) ($e['branch'] ?? 'Sin sucursal'), ErFormat::mask((string) ($e['account'] ?? '')), ErFormat::mask((string) ($e['concept'] ?? '')),
            ['money', $e['amount']], $e['status'] === 'identificada' ? 'Identificada' : 'Pendiente', (string) ($e['reason'] ?? ''),
        ];
        $r = $this->finTable($ws, $r, 'APORTACIONES DE SUCURSALES', ['CRÉDITO', 'FECHA', 'SUCURSAL', 'CUENTA', 'CONCEPTO', 'IMPORTE', 'ESTADO', 'MOTIVO'],
            array_merge(
                collect($fin['credits'])->flatMap(fn ($c) => array_map(fn ($e) => $contribRow($c['name'], $e), $c['contributions']['items']))->all(),
                array_map(fn ($e) => $contribRow('Sin crédito identificado', $e), $fin['unassigned_contributions']),
            ), 'Sin aportaciones de sucursales a créditos en el periodo.');

        // 5 · Distribución gerencial por sucursal (no duplica el gasto consolidado).
        $r = $this->finTable($ws, $r, 'DISTRIBUCIÓN POR SUCURSAL (SERVICIO DE DEUDA)', ['CRÉDITO', 'SUCURSAL', 'PARTICIPACIÓN', 'LE TOCA', 'APORTÓ', 'DIFERENCIA', 'SITUACIÓN', 'INTERÉS', 'COMISIÓN', 'CAPITAL (INFO.)', 'MOTIVO'],
            collect($fin['allocation']['credits'] ?? [])->flatMap(fn ($a) => array_merge(
                array_map(fn ($b) => [$a['credit_name'], $b['branch'], ['share', $b['share']], ['money', $b['due']], ['money', $b['contributed']], ['money', $b['difference']],
                    ErFinancingSection::standingLabel($b['standing']), ['money', $b['interest']], ['money', $b['commission']], ['money', $b['principal_informative']], ''], $a['branches']),
                (float) $a['unassigned']['due'] > 0 || (float) $a['unassigned']['contributed'] > 0 || (float) $a['unassigned']['amount'] > 0
                    ? [[$a['credit_name'], 'Sin asignar', null, ['money', $a['unassigned']['due']], ['money', $a['unassigned']['contributed']], ['money', $a['unassigned']['difference']],
                        'Sin sucursal', ['money', $a['unassigned']['interest']], ['money', $a['unassigned']['commission']], ['money', $a['unassigned']['principal_informative']], (string) ($a['unassigned']['reason'] ?? '')]]
                    : [],
            ))->all(), 'Sin pagos ni aportaciones que distribuir en el periodo.');
        $this->note($ws, 'B'.($r - 1), trim(ErFinancingSection::BRANCH_NOTE.' '.ErFinancingSection::NOTE));
        $r++;

        // 6 · Diferencias (pagos vs. tabla, captura del ER vs. tabla y cuadre del reparto).
        $allocBy = collect($fin['allocation']['credits'] ?? [])->keyBy('credit_key');
        $r = $this->finTable($ws, $r, 'DIFERENCIAS', ['CRÉDITO', 'DEBÍA PAGAR', 'APORTADO', 'PAGADO AL ACREEDOR', 'APLICADO', 'FALTANTE / EXCEDENTE', 'SIN CUBRIR (#)', 'ER − TABLA (INTERÉS)', 'ER − TABLA (COMISIÓN)', 'REPARTO CUADRA'],
            array_map(fn ($c) => [
                $c['name'], ['money', $c['application']['expected']], ['money', $c['application']['contributed']], ['money', $c['application']['paid_to_creditor']],
                ['money', $c['application']['applied']], ['money', $c['application']['difference']], (int) $c['payments']['totals']['unreconciled_count'],
                ['money', $c['adjustment']['difference'] ?? null], ['money', $c['commission_adjustment']['difference'] ?? null],
                isset($allocBy[$c['key']]) ? (($allocBy[$c['key']]['check']['ok'] ?? false) ? '✓ Sí' : '✗ No') : '—',
            ], $fin['credits']), 'Sin créditos.');

        // Gráfica nativa: le toca vs. aportó por sucursal + «Sin asignar» (datos en Q:S agrupadas y ocultas).
        $this->put($ws, 'Q9', 'Sucursal');
        $this->put($ws, 'R9', 'Le toca');
        $this->put($ws, 'S9', 'Aportó');
        $this->headerRow($ws, 'Q9:S9');
        foreach (array_values($fin['bars']) as $i => $bar) {
            $this->put($ws, 'Q'.(10 + $i), $bar['label']);
            $this->money($ws, 'R'.(10 + $i), $bar['due']);
            $this->money($ws, 'S'.(10 + $i), $bar['contributed']);
        }
        $dLast = max(10, 9 + count($fin['bars']));
        $this->label($ws, "B{$r}", 'GRÁFICA · LE TOCA VS. APORTÓ POR SUCURSAL');
        $top = $r + 1;
        $bottom = $top + max(14, count($fin['bars']) + 6);
        ErExcelCharts::add($ws, DataSeries::TYPE_BARCHART, 'Servicio de deuda por sucursal: le toca vs. aportó', 'Q', 9, 10, $dLast,
            [['col' => 'R', 'color' => 'F59E0B'], ['col' => 'S', 'color' => '059669']],
            "B{$top}", "H{$bottom}", '"$"#,##0', DataSeries::GROUPING_CLUSTERED, true);
        ErExcelCharts::hideColumns($ws, 'P', 'S');
        $ws->getPageSetup()->setPrintArea("A1:O{$bottom}");
    }

    /**
     * Bloque de tabla de la hoja «Financiamientos»: rótulo, encabezados y renglones. Cada celda
     * es texto, entero, null o [tipo, valor] con tipo money | date (Y-m-d → fecha de Excel) |
     * share (fracción 0–1 → porcentaje). Devuelve la fila libre siguiente (deja una de aire).
     *
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    private function finTable(Worksheet $ws, int $r, string $title, array $headers, array $rows, string $empty): int
    {
        $this->label($ws, "B{$r}", $title);
        $ws->getRowDimension($r)->setRowHeight(22);
        $ws->getStyle("B{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_BOTTOM);
        $r++;
        $last = Coordinate::stringFromColumnIndex(1 + count($headers));
        foreach ($headers as $i => $h) {
            $this->put($ws, Coordinate::stringFromColumnIndex(2 + $i).$r, $h);
        }
        $this->headerRow($ws, "B{$r}:{$last}{$r}", true);
        $r++;
        if ($rows === []) {
            $this->note($ws, "B{$r}", $empty);

            return $r + 2;
        }
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $v) {
                $cell = Coordinate::stringFromColumnIndex(2 + $i).$r;
                if (! is_array($v)) {
                    $this->put($ws, $cell, $v);

                    continue;
                }
                [$type, $val] = $v;
                if ($val === null || $val === '') {
                    continue;
                }
                if ($type === 'money') {
                    $this->money($ws, $cell, $val);
                } elseif ($type === 'share') {
                    $this->put($ws, $cell, round((float) $val, 6));
                    $ws->getStyle($cell)->getNumberFormat()->setFormatCode(self::PCT);
                } elseif ($type === 'date') {
                    $this->put($ws, $cell, (float) ExcelDate::PHPToExcel(Carbon::parse($val)->startOfDay()));
                    $ws->getStyle($cell)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                }
            }
            $this->rowLine($ws, "B{$r}:{$last}{$r}");
            $r++;
        }

        return $r + 1;
    }

    /**
     * Configuración común de la hoja: sin cuadrícula, anchos de columna y página de impresión.
     *
     * @param  Worksheet  $ws  hoja
     * @param  array<string, int|float>  $widths  columna ⇒ ancho
     * @param  bool  $landscape  orientación horizontal
     */
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

    /**
     * Estilo de fila de encabezado de tabla (fondo de marca, texto blanco en negrita).
     *
     * @param  Worksheet  $ws  hoja
     * @param  string  $range  rango (p. ej. «B7:F7»)
     * @param  bool  $wrap  ajustar texto
     */
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

    /**
     * Nota en cursiva pequeña.
     *
     * @param  Worksheet  $ws  hoja
     * @param  string  $cell  celda
     * @param  string  $text  texto
     * @param  string  $color  color RGB
     */
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

    /**
     * Liga «Ver detalle» a otra hoja del libro.
     *
     * @param  Worksheet  $ws  hoja
     * @param  string  $cell  celda
     * @param  string  $sheet  hoja destino
     * @param  string  $text  texto de la liga
     */
    private function detailLink(Worksheet $ws, string $cell, string $sheet, string $text = 'Ver detalle  ›'): void
    {
        $this->put($ws, $cell, $text);
        $this->linkTo($ws, $cell, $sheet, 'A1', 'Abrir «'.$sheet.'»');
        $ws->getStyle($cell)->applyFromArray(['font' => ['bold' => true, 'italic' => false, 'size' => 8, 'color' => ['rgb' => self::TEAL]]]);
    }

    /**
     * Formato condicional de barra de datos (de 0 al máximo del rango).
     *
     * @param  Worksheet  $ws  hoja
     * @param  string  $range  rango
     * @param  string  $color  color RGB
     */
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

    /**
     * Porcentaje como texto con sufijo («40.00% de ingresos»); «—» si no hay dato.
     *
     * @param  string|null  $pct  porcentaje (0–100)
     * @param  string  $suffix  sufijo
     */
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

    /**
     * ¿El texto podría interpretarse como fórmula en Excel (inyección CSV/fórmula)? Empieza con
     * =, +, -, @, tabulador o retorno de carro.
     *
     * @param  string  $text  texto
     */
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

    /**
     * Escribe un importe con formato de moneda (redondeado a 2 decimales); vacío ⇒ no escribe.
     *
     * @param  Worksheet  $ws  hoja
     * @param  string  $cell  celda
     * @param  mixed  $value  importe
     */
    private function money(Worksheet $ws, string $cell, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $this->put($ws, $cell, round((float) $value, 2));
        $ws->getStyle($cell)->getNumberFormat()->setFormatCode(self::MONEY);
    }

    /**
     * Escribe un porcentaje (el dato viene en 0–100 y se guarda como fracción /100 con formato
     * %); vacío ⇒ «—».
     *
     * @param  Worksheet  $ws  hoja
     * @param  string  $cell  celda
     * @param  mixed  $value  porcentaje 0–100
     */
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

    /**
     * Valor de un indicador según su unidad; sin valor ⇒ «Sin información».
     *
     * @param  Worksheet  $ws  hoja
     * @param  string  $cell  celda
     * @param  array  $ind  indicador {value, unit, …}
     */
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
