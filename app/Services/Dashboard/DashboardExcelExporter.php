<?php

namespace App\Services\Dashboard;

use App\Services\Radiography\RadiographyStyleHelper as S;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel del Dashboard (Fase 7.3, 15-oct-2026) — presenta EXACTAMENTE los datos que
 * DashboardController ya armó para la pantalla y el PDF (payloadForPeriod() +
 * DashboardTrendService::build($branchId)). Solo PRESENTA: ningún cálculo
 * financiero nuevo ni fórmulas de Excel; las cifras se escriben tal cual llegan
 * (valores numéricos con formato moneda MXN, nunca texto).
 *
 * Hojas:
 *   1. "Resumen ejecutivo" — alcance, 8 KPI y tendencia EBITDA vs OPEX (con gráfica).
 *   2. "Desglose" — cartera vencida por antigüedad, categoría EBITDA y detalle por
 *      sucursal (colocación, recuperación, EBITDA, categoría), con gráficas nativas.
 */
class DashboardExcelExporter
{
    /**
     * @param array{kpis: ?array, charts: ?array, trend: array, periodLabel: string, branchName: ?string, generatedAt: Carbon, generatedBy: ?string} $data
     */
    public function export(array $data, string $path): string
    {
        $book = $this->build($data);

        File::ensureDirectoryExists(dirname($path));
        $writer = IOFactory::createWriter($book, 'Xlsx');
        $writer->setPreCalculateFormulas(false);
        $writer->setIncludeCharts(true);
        $writer->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    public function build(array $data): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('MR LANA · Reportes')->setTitle('Dashboard ' . $data['periodLabel']);
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        $summary = $book->getActiveSheet();
        $summary->setTitle('Resumen ejecutivo');
        $this->summarySheet($summary, $data);

        $detail = $book->createSheet();
        $detail->setTitle('Desglose');
        $this->detailSheet($detail, $data);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function scopeLabel(array $data): string
    {
        return $data['branchName'] ? 'Sucursal ' . $data['branchName'] : 'Todas las sucursales (consolidado)';
    }

    private function header(Worksheet $sheet, array $data, string $title, string $lastCol): int
    {
        S::applyTitleStyle($sheet, "A1:{$lastCol}1", 'MR LANA · ' . $title);
        S::applySubtitleStyle($sheet, "A2:{$lastCol}2", 'Dashboard — ' . $data['periodLabel'] . ' — ' . $this->scopeLabel($data));
        $meta = [
            ['Periodo', $data['periodLabel']],
            ['Alcance', $this->scopeLabel($data)],
            ['Fuente', 'Radiografía generada del periodo (misma fuente que el Dashboard, el PDF y la Reportería)'],
            ['Generado', $data['generatedAt']->format('d/m/Y H:i') . ($data['generatedBy'] ? ' · ' . $data['generatedBy'] : '')],
        ];
        $row = 3;
        foreach ($meta as [$k, $v]) {
            $sheet->setCellValue("A{$row}", $k);
            S::setCellValueSafe($sheet, "B{$row}", $v);
            S::mergeCellsSafe($sheet, "B{$row}:{$lastCol}{$row}");
            S::applyMetaStyle($sheet, "A{$row}:{$lastCol}{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
        }

        return $row + 1;
    }

    private function summarySheet(Worksheet $sheet, array $data): void
    {
        S::setColWidths($sheet, ['A' => 30, 'B' => 22, 'C' => 22, 'D' => 4, 'E' => 14, 'F' => 14, 'G' => 14, 'H' => 14, 'I' => 14, 'J' => 14]);
        $row = $this->header($sheet, $data, 'Resumen ejecutivo', 'C');
        $kpis = $data['kpis'];

        if (!$kpis) {
            S::setCellValueSafe($sheet, "A{$row}", 'Sin datos de radiografía para ' . $this->scopeLabel($data) . ' en ' . $data['periodLabel'] . '. Elige otra sucursal o verifica que la radiografía del periodo ya esté generada.');
            S::mergeCellsSafe($sheet, "A{$row}:C{$row}");
            S::applyWarningStyle($sheet, "A{$row}:C{$row}");

            return;
        }

        // ── Indicadores ──────────────────────────────────────────────────────────
        S::applySectionHeaderStyle($sheet, "A{$row}:C{$row}", 'Indicadores clave');
        $row++;
        S::applyTableHeaderStyle($sheet, $row, ['A' => 'Indicador', 'B' => 'Valor', 'C' => 'Unidad']);
        $row++;
        $cards = [
            ['EBITDA', $kpis['ebitda'], 'money'],
            ['Margen EBITDA', $kpis['margen_ebitda'], 'pct'],
            ['OPEX', $kpis['opex'], 'money'],
            ['Colocación', $kpis['colocacion'], 'money'],
            ['Recuperación', $kpis['recuperacion'], 'money'],
            ['Cartera', $kpis['cartera'], 'money'],
            ['Mora', $kpis['mora_pct'], 'pct'],
            ['Colaboradores activos', $kpis['colaboradores'], 'int'],
        ];
        foreach ($cards as $i => [$label, $value, $fmt]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $fmt === 'int' ? (int) $value : (float) $value);
            $sheet->setCellValue("C{$row}", match ($fmt) { 'money' => 'MXN', 'pct' => '%', default => 'personas' });
            S::applyDataRowStyle($sheet, "A{$row}:C{$row}", $i % 2 === 0);
            match ($fmt) {
                'money' => S::applyCurrencyFormat($sheet, "B{$row}"),
                'pct' => S::applyPercentFormat($sheet, "B{$row}"),
                default => S::applyIntegerFormat($sheet, "B{$row}"),
            };
            if ($label === 'EBITDA' || $label === 'Margen EBITDA') {
                S::applyPositiveNegativeStyle($sheet, "B{$row}", (float) $value);
            }
            $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        // ── Tendencia ────────────────────────────────────────────────────────────
        $row++;
        $trend = $data['trend'] ?? [];
        S::applySectionHeaderStyle($sheet, "A{$row}:C{$row}", 'Tendencia EBITDA vs OPEX — ' . $this->scopeLabel($data));
        $row++;
        if ($trend === []) {
            S::setCellValueSafe($sheet, "A{$row}", 'Sin periodos suficientes para mostrar la tendencia.');
            S::mergeCellsSafe($sheet, "A{$row}:C{$row}");

            return;
        }
        S::applyTableHeaderStyle($sheet, $row, ['A' => 'Periodo', 'B' => 'EBITDA', 'C' => 'OPEX']);
        $row++;
        $first = $row;
        $missing = false;
        foreach ($trend as $i => $p) {
            $available = $p['available'] ?? true;
            $missing = $missing || !$available;
            $sheet->setCellValue("A{$row}", $p['label']);
            if ($available) {
                $sheet->setCellValue("B{$row}", (float) $p['ebitda']);
                $sheet->setCellValue("C{$row}", (float) $p['opex']);
            } else {
                $sheet->setCellValue("B{$row}", 'Sin radiografía');
                $sheet->setCellValue("C{$row}", 'Sin radiografía');
            }
            S::applyDataRowStyle($sheet, "A{$row}:C{$row}", $i % 2 === 0);
            S::applyCurrencyFormat($sheet, "B{$row}:C{$row}");
            $row++;
        }
        $last = $row - 1;
        if ($missing) {
            S::setCellValueSafe($sheet, "A{$row}", 'Los periodos marcados "Sin radiografía" no tienen datos para este alcance; no se grafican como cero.');
            S::mergeCellsSafe($sheet, "A{$row}:C{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(8.5);
        }

        $points = array_values(array_filter($trend, fn ($p) => $p['available'] ?? true));
        if ($points !== [] && !$missing) {
            S::addMultiSeriesChart(
                $sheet,
                'EBITDA vs OPEX',
                'line',
                "\$A\${$first}:\$A\${$last}",
                array_column($trend, 'label'),
                [
                    ['label' => 'EBITDA', 'range' => "\$B\${$first}:\$B\${$last}", 'color' => S::BG_PRIMARY_DARK, 'values' => array_column($trend, 'ebitda')],
                    ['label' => 'OPEX', 'range' => "\$C\${$first}:\$C\${$last}", 'color' => 'FFEF4444', 'values' => array_column($trend, 'opex')],
                ],
                'E7',
                'L24',
            );
        }
        $sheet->freezePane('A7');
    }

    private function detailSheet(Worksheet $sheet, array $data): void
    {
        S::setColWidths($sheet, ['A' => 26, 'B' => 20, 'C' => 20, 'D' => 20, 'E' => 16, 'F' => 4]);
        $row = $this->header($sheet, $data, 'Desglose del Dashboard', 'E');
        $charts = $data['charts'];

        if (!$data['kpis'] || !$charts) {
            S::setCellValueSafe($sheet, "A{$row}", $data['kpis']
                ? 'Este alcance no tiene desglose por sucursal en la radiografía del periodo.'
                : 'Sin datos de radiografía para ' . $this->scopeLabel($data) . ' en ' . $data['periodLabel'] . '.');
            S::mergeCellsSafe($sheet, "A{$row}:E{$row}");
            S::applyWarningStyle($sheet, "A{$row}:E{$row}");

            return;
        }

        // ── Cartera vencida por antigüedad ───────────────────────────────────────
        S::applySectionHeaderStyle($sheet, "A{$row}:E{$row}", 'Cartera vencida por antigüedad');
        $row++;
        S::applyTableHeaderStyle($sheet, $row, ['A' => 'Antigüedad', 'B' => 'Monto vencido']);
        $row++;
        $moraFirst = $row;
        foreach ($charts['moraBuckets'] ?? [] as $i => $b) {
            $sheet->setCellValue("A{$row}", $b['label']);
            $sheet->setCellValue("B{$row}", (float) $b['valor']);
            S::applyDataRowStyle($sheet, "A{$row}:B{$row}", $i % 2 === 0);
            S::applyCurrencyFormat($sheet, "B{$row}");
            $row++;
        }
        $moraLast = $row - 1;
        if ($moraLast >= $moraFirst) {
            S::addBarChart($sheet, 'Cartera vencida por antigüedad', "\$A\${$moraFirst}:\$A\${$moraLast}", "\$B\${$moraFirst}:\$B\${$moraLast}",
                $moraLast - $moraFirst + 1, 'G' . ($moraFirst - 2), 'N' . ($moraFirst + 13), 'FFEF4444',
                array_column($charts['moraBuckets'], 'label'), array_map('floatval', array_column($charts['moraBuckets'], 'valor')));
        }

        // ── Categoría EBITDA ─────────────────────────────────────────────────────
        $row = max($row + 1, $moraFirst + 15);
        $isBranch = count($charts['sucursalRows'] ?? []) === 1;
        S::applySectionHeaderStyle($sheet, "A{$row}:E{$row}", $isBranch ? 'Categoría EBITDA de la sucursal' : 'Sucursales por categoría EBITDA');
        $row++;
        S::applyTableHeaderStyle($sheet, $row, ['A' => 'Categoría', 'B' => 'Sucursales']);
        $row++;
        $catFirst = $row;
        foreach ($charts['categoriaLabels'] ?? [] as $i => $label) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", (int) ($charts['categoriaCounts'][$i] ?? 0));
            S::applyDataRowStyle($sheet, "A{$row}:B{$row}", $i % 2 === 0);
            S::applyIntegerFormat($sheet, "B{$row}");
            $row++;
        }
        $catLast = $row - 1;
        if (!$isBranch && $catLast >= $catFirst) {
            S::addDonutChart($sheet, 'Sucursales por categoría EBITDA', "\$A\${$catFirst}:\$A\${$catLast}", "\$B\${$catFirst}:\$B\${$catLast}",
                $catLast - $catFirst + 1, 'G' . ($catFirst - 2), 'N' . ($catFirst + 13), array_map(fn ($c) => 'FF' . ltrim($c, '#'), $charts['categoriaColors'] ?? []));
        }

        // ── Detalle por sucursal ─────────────────────────────────────────────────
        $row = $isBranch ? $row + 1 : max($row + 1, $catFirst + 15);
        S::applySectionHeaderStyle($sheet, "A{$row}:E{$row}", 'Colocación, recuperación y EBITDA por sucursal');
        $row++;
        S::applyTableHeaderStyle($sheet, $row, ['A' => 'Sucursal', 'B' => 'Colocación', 'C' => 'Recuperación', 'D' => 'EBITDA', 'E' => 'Categoría']);
        $sheet->setAutoFilter("A{$row}:E{$row}");
        $row++;
        $catBy = collect($charts['categorias'] ?? [])->keyBy('nombre');
        $rows = $charts['sucursalRows'] ?? [];
        $first = $row;
        foreach ($rows as $i => $r) {
            $cat = $catBy[$r['sucursal']] ?? null;
            $sheet->setCellValue("A{$row}", $r['sucursal']);
            $sheet->setCellValue("B{$row}", (float) $r['colocacion']);
            $sheet->setCellValue("C{$row}", (float) $r['recuperacion']);
            if ($cat) {
                $sheet->setCellValue("D{$row}", (float) $cat['ebitda']);
                S::applyPositiveNegativeStyle($sheet, "D{$row}", (float) $cat['ebitda']);
            }
            S::setCellValueSafe($sheet, "E{$row}", $cat['categoria'] ?? '—');
            S::applyDataRowStyle($sheet, "A{$row}:E{$row}", $i % 2 === 0);
            S::applyCurrencyFormat($sheet, "B{$row}:D{$row}");
            $sheet->getStyle("E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }
        $last = $row - 1;
        if (count($rows) > 1) {
            // Suma de lo mostrado (igual que la tabla del PDF) — valor, no fórmula.
            $sheet->setCellValue("A{$row}", 'Suma de sucursales');
            $sheet->setCellValue("B{$row}", array_sum(array_column($rows, 'colocacion')));
            $sheet->setCellValue("C{$row}", array_sum(array_column($rows, 'recuperacion')));
            $sheet->setCellValue("D{$row}", array_sum(array_column($charts['categorias'] ?? [], 'ebitda')));
            S::applyTotalRowStyle($sheet, "A{$row}:E{$row}");
            S::applyCurrencyFormat($sheet, "B{$row}:D{$row}");

            S::addMultiSeriesChart(
                $sheet,
                'Colocación vs. recuperación por sucursal',
                'bar',
                "\$A\${$first}:\$A\${$last}",
                array_column($rows, 'sucursal'),
                [
                    ['label' => 'Colocación', 'range' => "\$B\${$first}:\$B\${$last}", 'color' => S::BG_PRIMARY_DARK, 'values' => array_column($rows, 'colocacion')],
                    ['label' => 'Recuperación', 'range' => "\$C\${$first}:\$C\${$last}", 'color' => S::BG_ACCENT, 'values' => array_column($rows, 'recuperacion')],
                ],
                'A' . ($row + 3),
                'I' . ($row + 25),
            );
        }
    }
}
