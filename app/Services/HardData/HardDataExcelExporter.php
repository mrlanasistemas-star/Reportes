<?php

namespace App\Services\HardData;

use App\Services\Radiography\RadiographyStyleHelper as S;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel del módulo «Datos duros». Reutiliza el formato del «Excel de
 * colaboradores» del reporte mensual (EmployeesHistoricoExportService: mismos
 * encabezados en mayúsculas, verde MR LANA, moneda y ACTIVO/BAJA coloreado),
 * pero con EXACTAMENTE las filas que se ven en pantalla: mismo periodo, mismas
 * sucursales oficiales y sin quienes no trabajaron el periodo.
 *
 * No recalcula nada: recibe las filas ligeras de HardDataService::dataset().
 */
class HardDataExcelExporter
{
    private const CURRENCY_HEADERS = [
        'COLOCACIÓN', 'RECUPERACIÓN', 'VALOR CARTERA', 'CARTERA VENCIDA', 'NÓMINA / CAPITAL HUMANO',
        'OPEX', 'UTILIDAD BRUTA', 'GASTO TOTAL', 'EBITDA', 'MONTO',
    ];

    private const PERCENT_HEADERS = ['MORA %', 'MARGEN EBITDA %'];

    /**
     * @param  array  $dataset  HardDataService::dataset() (ya filtrado a las filas a exportar)
     */
    public function build(array $dataset): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('MR LANA · Reportes')->setTitle('Datos duros · '.$dataset['period']['name']);

        $this->mainSheet($book->getActiveSheet(), $dataset);
        $this->branchSheet($book->createSheet(), $dataset);
        $this->detailSheet($book->createSheet(), 'Detalle de Gastos', ['COLABORADOR', 'SUCURSAL', 'CATEGORÍA', 'CONCEPTO', 'MONTO'], $dataset['rows'],
            fn (array $r) => array_map(fn ($i) => [$r['name'], $r['branch'], $i['category'], $i['concept'], (float) $i['amount']], $r['opex_items'] ?? []));
        $this->detailSheet($book->createSheet(), 'Colocación por producto', ['COLABORADOR', 'SUCURSAL', 'PRODUCTO', 'OPERACIONES', 'MONTO'], $dataset['rows'],
            fn (array $r) => array_map(fn ($i) => [$r['name'], $r['branch'], $i['product'] ?? 'Sin producto', (int) $i['operations'], (float) $i['amount']], $r['placement_by_product'] ?? []));
        $this->detailSheet($book->createSheet(), 'Nómina por concepto', ['COLABORADOR', 'SUCURSAL', 'CONCEPTO', 'MONTO'], $dataset['rows'],
            fn (array $r) => collect($r['payroll_concepts'] ?? [])->filter(fn ($v) => (float) $v != 0.0)
                ->map(fn ($v, $k) => [$r['name'], $r['branch'], HardDataLabels::payroll($k), (float) $v])->values()->all());

        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function mainSheet(Worksheet $sheet, array $dataset): void
    {
        $sheet->setTitle('Datos duros');
        $headers = [
            'PERIODO', 'SUCURSAL', 'RUTAS', 'NOMBRE COLABORADOR', 'CATEGORÍA', 'ESTADO ACTIVO/BAJA',
            'COLOCACIÓN', 'OPERACIONES', 'RECUPERACIÓN', 'VALOR CARTERA', 'CARTERA VENCIDA', 'MORA %',
            'NÓMINA / CAPITAL HUMANO', 'OPEX', 'UTILIDAD BRUTA', 'GASTO TOTAL', 'EBITDA', 'MARGEN EBITDA %',
        ];
        $period = $dataset['period']['name'].($dataset['period']['range'] ? ' ('.$dataset['period']['range'].')' : '');

        $rows = array_map(fn (array $r) => [
            $period, $r['branch'] ?? 'Sin sucursal', implode(', ', $r['routes'] ?? []), $r['name'], $r['category'],
            mb_strtoupper((string) ($r['status'] ?? 'ACTIVO')) === 'BAJA' ? 'BAJA' : 'ACTIVO',
            (float) $r['placement'], (int) $r['operations'], (float) $r['recovery'], (float) $r['portfolio'], (float) $r['overdue'],
            $r['mora_pct'] === null ? null : (float) $r['mora_pct'],
            (float) $r['payroll'], (float) $r['opex'], (float) $r['income_base'], (float) $r['total_expenses'], (float) $r['ebitda'],
            $r['margin_pct'] === null ? null : (float) $r['margin_pct'],
        ], $dataset['rows']);

        $last = $this->table($sheet, $headers, $rows);
        if ($rows === []) {
            return;
        }

        // Fila de totales (fórmulas: siguen al usuario si filtra/edita en Excel).
        $t = $last + 1;
        $sheet->setCellValue("A{$t}", 'TOTAL');
        foreach (['G', 'H', 'I', 'J', 'K', 'M', 'N', 'O', 'P', 'Q'] as $col) {
            $sheet->setCellValue("{$col}{$t}", "=SUM({$col}2:{$col}{$last})");
        }
        $sheet->setCellValue("L{$t}", "=IF(J{$t}=0,0,K{$t}/J{$t}*100)");
        $sheet->setCellValue("R{$t}", "=IF(O{$t}=0,0,Q{$t}/O{$t}*100)");
        $this->formatRow($sheet, $headers, $t);
        $sheet->getStyle("A{$t}:R{$t}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => S::BG_LIGHT_BLUE]],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => S::BG_PRIMARY_DARK]]],
        ]);

        // ACTIVO/BAJA coloreado como en el Excel de colaboradores.
        for ($i = 2; $i <= $last; $i++) {
            $isActive = $sheet->getCell("F{$i}")->getValue() === 'ACTIVO';
            $sheet->getStyle("F{$i}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $isActive ? S::BG_POSITIVE : S::BG_ALERT_RED]],
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            if ((float) $sheet->getCell("Q{$i}")->getValue() < 0) {
                $sheet->getStyle("Q{$i}")->getFont()->getColor()->setARGB(S::FG_RED);
            }
        }
        $sheet->setAutoFilter("A1:R{$last}");
    }

    private function branchSheet(Worksheet $sheet, array $dataset): void
    {
        $sheet->setTitle('Por sucursal');
        $headers = ['SUCURSAL', 'COLABORADORES', 'COLOCACIÓN', 'RECUPERACIÓN', 'VALOR CARTERA', 'CARTERA VENCIDA', 'MORA %', 'NÓMINA / CAPITAL HUMANO', 'OPEX', 'EBITDA', 'MARGEN EBITDA %'];
        $rows = collect($dataset['rows'])->groupBy(fn ($r) => $r['branch'] ?? 'Sin sucursal')->map(function ($g, $branch) {
            $portfolio = $g->sum('portfolio');
            $income = $g->sum('income_base');

            return [
                $branch, $g->count(), $g->sum('placement'), $g->sum('recovery'), $portfolio, $g->sum('overdue'),
                $portfolio > 0 ? round($g->sum('overdue') / $portfolio * 100, 2) : 0.0,
                $g->sum('payroll'), $g->sum('opex'), $g->sum('ebitda'),
                $income > 0 ? round($g->sum('ebitda') / $income * 100, 2) : 0.0,
            ];
        })->sortByDesc(fn ($r) => $r[9])->values()->all();

        $this->table($sheet, $headers, $rows);
    }

    /**
     * @param  callable(array): list<list<mixed>>  $map
     */
    private function detailSheet(Worksheet $sheet, string $title, array $headers, array $source, callable $map): void
    {
        $sheet->setTitle($title);
        $rows = [];
        foreach ($source as $r) {
            array_push($rows, ...$map($r));
        }
        $this->table($sheet, $headers, $rows);
    }

    /** Encabezado verde + filas con formato; devuelve la última fila con datos. */
    private function table(Worksheet $sheet, array $headers, array $rows): int
    {
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => S::FG_WHITE]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => S::BG_PRIMARY_DARK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->freezePane('A2');

        if ($rows === []) {
            $sheet->setCellValue('A2', 'Sin registros para esta selección.');

            return 2;
        }

        $sheet->fromArray($rows, null, 'A2', true);
        $last = count($rows) + 1;
        foreach (range(2, $last) as $i) {
            $this->formatRow($sheet, $headers, $i);
            if ($i % 2 === 1) {
                $sheet->getStyle("A{$i}:{$lastCol}{$i}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(S::BG_GRAY);
            }
        }
        $sheet->getStyle("A1:{$lastCol}{$last}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(S::BORDER_LT);

        foreach (range(1, count($headers)) as $c) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        return $last;
    }

    private function formatRow(Worksheet $sheet, array $headers, int $row): void
    {
        foreach ($headers as $i => $header) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $format = match (true) {
                in_array($header, self::CURRENCY_HEADERS, true) => S::CURRENCY,
                in_array($header, self::PERCENT_HEADERS, true) => S::PERCENT,
                in_array($header, ['OPERACIONES', 'COLABORADORES'], true) => S::INTEGER,
                default => null,
            };
            if ($format) {
                $sheet->getStyle("{$col}{$row}")->getNumberFormat()->setFormatCode($format);
            }
        }
    }
}
