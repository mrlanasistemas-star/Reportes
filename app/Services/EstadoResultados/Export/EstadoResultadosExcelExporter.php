<?php

namespace App\Services\EstadoResultados\Export;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel del Estado de Resultados. NO calcula nada: escribe el dataset canónico
 * (EstadoResultadosDatasetBuilder) tal cual. Las únicas fórmulas son columnas de
 * VALIDACIÓN (=SUMA(líneas) − total) que deben dar 0.00; las cifras mostradas
 * son las mismas que la web y el PDF (mismo hash de dataset en FUENTES Y CONTROL).
 */
class EstadoResultadosExcelExporter
{
    private const MONEY = '$#,##0.00;[Red]-$#,##0.00';

    private const PCT = '0.00%';

    private const FONT = 'Montserrat';

    private const TEAL = '0F766E';

    private const TEAL_DARK = '0B4F4A';

    private const GREEN = '10B981';

    private const CYAN = '06B6D4';

    private const INK = '0F172A';

    private const MUTED = '64748B';

    private const SOFT = 'ECFDF5';

    private const SOFT_CYAN = 'ECFEFF';

    private const LINE = 'E2E8F0';

    private const BAD = 'B91C1C';

    private array $d;

    public function export(array $dataset, string $outputPath): string
    {
        $this->d = $dataset;
        $book = new Spreadsheet;
        $book->getDefaultStyle()->getFont()->setName(self::FONT)->setSize(10);
        $book->getProperties()->setCreator('MR LANA · Reportes')->setTitle('Estado de Resultados '.$dataset['meta']['period']['label']);

        $book->removeSheetByIndex(0);
        $this->sheetStatement($book->createSheet()->setTitle('ESTADO RESULTADOS'));
        $this->sheetIndicators($book->createSheet()->setTitle('INDICADORES'));
        $this->sheetConciliation($book->createSheet()->setTitle('CONCILIACION'));
        $this->sheetLines($book->createSheet()->setTitle('OPEX SUCURSALES'), 'OPEX SUCURSALES', ['opex_sucursales']);
        $this->sheetItems($book->createSheet()->setTitle('OPEX CORPORATIVO'), 'OPEX CORPORATIVO', fn ($m) => $m['treatment'] === 'opex_corporativo' || ($m['treatment'] === 'pendiente' && $m['branch_kind'] === 'corporativo' && ! str_contains((string) $m['rule_code'], 'dg')));
        $this->sheetPayroll($book->createSheet()->setTitle('NOMINA'));
        $this->sheetItems($book->createSheet()->setTitle('DG'), 'DIRECCIÓN GENERAL', fn ($m) => in_array($m['treatment'], ['dg_operativo', 'dg_remuneracion'], true) || in_array($m['rule_code'], ['dg_requiere_revision', 'erp_dg'], true));
        $this->sheetAdjustments($book->createSheet()->setTitle('AJUSTES Y FLUJO'));
        $this->sheetOutside($book->createSheet()->setTitle('MOVIMIENTOS FUERA ER'));
        $this->sheetControl($book->createSheet()->setTitle('FUENTES Y CONTROL'));
        $book->setActiveSheetIndex(0);

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $prev = ini_get('precision');
        ini_set('precision', '-1');
        try {
            (new Xlsx($book))->save($outputPath);
        } finally {
            ini_set('precision', (string) $prev);
        }

        return $outputPath;
    }

    // ── Hoja principal ───────────────────────────────────────────────────

    private function sheetStatement(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 52, 'C' => 20, 'D' => 12, 'E' => 3, 'F' => 38, 'G' => 20]);
        $this->banner($ws, 'ESTADO DE RESULTADOS', 'G');

        $ws->setCellValue('B6', 'CONCEPTO');
        $ws->setCellValue('C6', 'IMPORTE (MXN)');
        $ws->setCellValue('D6', '% INGRESOS');
        $this->headerRow($ws, 'B6:D6');

        $r = 7;
        foreach ($this->d['statement'] as $block) {
            if ($block['type'] === 'section') {
                $ws->setCellValue("B{$r}", $block['number'].'   '.$block['title']);
                $ws->getStyle("B{$r}:D{$r}")->getFont()->setBold(true)->setSize(10)->getColor()->setRGB(self::TEAL);
                $ws->getStyle("B{$r}:D{$r}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::GREEN);
                $r++;
                if (! $block['applies']) {
                    $ws->setCellValue("B{$r}", $block['note']);
                    $ws->getStyle("B{$r}")->getFont()->setItalic(true)->getColor()->setRGB(self::MUTED);
                    $r += 2;

                    continue;
                }
                $first = $r;
                foreach ($block['lines'] as $line) {
                    $label = '   '.$line['label'];
                    if ($line['state'] === 'sin_informacion') {
                        $label .= '  · SIN INFORMACIÓN';
                    }
                    $ws->setCellValue("B{$r}", $label);
                    $this->money($ws, "C{$r}", $line['amount']);
                    $this->pct($ws, "D{$r}", $line['pct']);
                    if ($line['state'] === 'sin_informacion') {
                        $ws->getStyle("B{$r}:D{$r}")->getFont()->getColor()->setRGB('B45309');
                    }
                    $r++;
                }
                $last = $r - 1;
                $ws->setCellValue("B{$r}", $block['total']['label']);
                $this->money($ws, "C{$r}", $block['total']['amount']);
                $this->pct($ws, "D{$r}", $block['total']['pct']);
                $ws->getStyle("B{$r}:D{$r}")->getFont()->setBold(true);
                $ws->getStyle("B{$r}:D{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::SOFT);
                $ws->getStyle("B{$r}:D{$r}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::TEAL);
                // Validación con fórmula: Σ líneas − total (debe ser 0.00)
                if ($last >= $first) {
                    $ws->setCellValue("E{$r}", "=ROUND(SUM(C{$first}:C{$last})-C{$r},2)");
                    $ws->getStyle("E{$r}")->getNumberFormat()->setFormatCode('0.00;-0.00;"✓"');
                    $ws->getStyle("E{$r}")->getFont()->setSize(8)->getColor()->setRGB(self::GREEN);
                }
                $r += 2;

                continue;
            }

            // Bloque de resultado (EBITDA / EBIT / EBT / Utilidad)
            $fill = match ($block['level']) {
                1 => self::TEAL,
                2 => '0E7490',
                3 => self::TEAL_DARK,
                6 => '065F46',
                default => '334155',
            };
            $label = $block['label'].($block['complete'] ? '' : '  (PRELIMINAR)');
            $ws->setCellValue("B{$r}", $label);
            $this->money($ws, "C{$r}", $block['amount']);
            $this->pct($ws, "D{$r}", $block['pct']);
            $ws->getStyle("B{$r}:D{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
            $ws->getStyle("B{$r}:D{$r}")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('FFFFFF');
            $ws->getRowDimension($r)->setRowHeight(24);
            $ws->getStyle("B{$r}:D{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $r++;
            $ws->setCellValue("B{$r}", '   '.$block['formula'].($block['complete'] ? '' : ' · Falta: '.implode(', ', $block['missing'])));
            $ws->getStyle("B{$r}")->getFont()->setItalic(true)->setSize(8)->getColor()->setRGB(self::MUTED);
            $r += 2;
        }

        // Panel lateral: márgenes, conciliación con Radiografía, indicadores clave
        $p = 6;
        $ws->setCellValue("F{$p}", 'MÁRGENES');
        $ws->setCellValue("G{$p}", '% INGRESOS');
        $this->headerRow($ws, "F{$p}:G{$p}");
        $p++;
        $labels = ['ebitda_sucursales' => 'Margen EBITDA sucursales', 'ebitda_corporativo' => 'Margen EBITDA corporativo', 'ebitda_final' => 'Margen EBITDA final', 'ebit' => 'Margen EBIT', 'ebt' => 'Margen antes de impuestos', 'neto' => 'Margen neto'];
        foreach ($labels as $k => $label) {
            if ($this->d['margins'][$k] === null) {
                continue;
            }
            $ws->setCellValue("F{$p}", $label);
            $this->pct($ws, "G{$p}", $this->d['margins'][$k]);
            $p++;
        }
        $p++;
        $rec = $this->d['radiography_reconciliation'];
        $ws->setCellValue("F{$p}", 'CONCILIACIÓN CON RADIOGRAFÍA');
        $ws->setCellValue("G{$p}", 'MXN');
        $this->headerRow($ws, "F{$p}:G{$p}");
        $p++;
        foreach ([['EBITDA sucursales (ER)', $rec['er_ebitda_sucursales']], ['EBITDA Radiografía', $rec['radiografia_ebitda']], ['Diferencia', $rec['difference']]] as [$l, $v]) {
            $ws->setCellValue("F{$p}", $l);
            $this->money($ws, "G{$p}", $v);
            $p++;
        }
        $ws->getStyle('F'.($p - 1).':G'.($p - 1))->getFont()->setBold(true)->getColor()->setRGB($rec['ok'] ? self::GREEN : self::BAD);
        foreach ($rec['explanations'] as $e) {
            $ws->setCellValue("F{$p}", '· '.$e['label']);
            $this->money($ws, "G{$p}", $e['amount']);
            $ws->getStyle("F{$p}")->getAlignment()->setWrapText(true);
            $p++;
        }
        $p++;
        $ws->setCellValue("F{$p}", 'INDICADORES (INFORMATIVOS)');
        $this->headerRow($ws, "F{$p}:G{$p}");
        $p++;
        foreach ($this->d['indicators']['informative'] as $ind) {
            $ws->setCellValue("F{$p}", $ind['label']);
            $this->indicatorValue($ws, "G{$p}", $ind);
            $p++;
        }
        $ws->setCellValue('F'.($p + 1), 'Cartera, mora, colocación y recuperación son estadísticos: no entran a la fórmula del Estado de Resultados.');
        $ws->getStyle('F'.($p + 1))->getFont()->setItalic(true)->setSize(8)->getColor()->setRGB(self::MUTED);
        $ws->getStyle('F'.($p + 1))->getAlignment()->setWrapText(true);

        $ws->freezePane('A7');
        $ws->getPageSetup()->setPrintArea('A1:G'.max($r, $p + 2));
    }

    private function sheetIndicators(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 44, 'C' => 22, 'D' => 50]);
        $this->banner($ws, 'INDICADORES', 'D');
        $r = 6;
        foreach (['financial' => 'INDICADORES FINANCIEROS', 'informative' => 'INDICADORES OPERATIVOS (INFORMATIVOS, NO AFECTAN EL RESULTADO)'] as $group => $title) {
            $ws->setCellValue("B{$r}", $title);
            $ws->setCellValue("C{$r}", 'VALOR');
            $this->headerRow($ws, "B{$r}:C{$r}");
            $r++;
            foreach ($this->d['indicators'][$group] as $ind) {
                $ws->setCellValue("B{$r}", $ind['label']);
                $this->indicatorValue($ws, "C{$r}", $ind);
                $r++;
            }
            $r++;
        }
    }

    private function sheetConciliation(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 11, 'C' => 15, 'D' => 34, 'E' => 12, 'F' => 14, 'G' => 26, 'H' => 30, 'I' => 16, 'J' => 14, 'K' => 14, 'L' => 13, 'M' => 14, 'N' => 12, 'O' => 30, 'P' => 28, 'Q' => 14, 'R' => 60]);
        $this->banner($ws, 'CONCILIACIÓN DE MOVIMIENTOS', 'R');
        $c = $this->d['conciliation'];
        $summary = [
            ['Movimientos leídos', $c['movimientos_leidos'], null],
            ['Movimientos en el periodo', $c['movimientos_en_periodo'], null],
            ['Total cargos (periodo)', null, $c['total_cargos']],
            ['Total abonos (periodo)', null, $c['total_abonos']],
            ['Cargos candidatos a conciliar', $c['candidatos'], null],
            ['Conciliados con ERP', $c['conciliados_erp']['count'], $c['conciliados_erp']['amount']],
            ['Nuevos (sin ERP)', $c['nuevos']['count'], $c['nuevos']['amount']],
            ['Transferencias internas', $c['transferencias']['count'], $c['transferencias']['amount']],
            ['Fuera del ER (otros)', $c['fuera_er']['count'], $c['fuera_er']['amount']],
            ['Pendientes de revisión (no suman)', $c['pendientes']['count'], $c['pendientes']['amount']],
            ['Importe incorporado al ER desde bancos', null, $c['incorporado_bancos']],
            ['Importe incorporado desde ERP fuera de Radiografía', null, $c['incorporado_erp_fuera_radiografia']],
        ];
        $ws->setCellValue('B6', 'RESUMEN');
        $ws->setCellValue('E6', 'CANTIDAD');
        $ws->setCellValue('F6', 'IMPORTE');
        $this->headerRow($ws, 'B6:F6');
        $r = 7;
        foreach ($summary as [$label, $count, $amount]) {
            $ws->setCellValue("B{$r}", $label);
            $ws->mergeCells("B{$r}:D{$r}");
            if ($count !== null) {
                $ws->setCellValue("E{$r}", $count);
            }
            if ($amount !== null) {
                $this->money($ws, "F{$r}", $amount);
            }
            $r++;
        }
        $r++;
        $this->movementTable($ws, $r, $this->d['movements']);
    }

    private function sheetLines(Worksheet $ws, string $title, array $sectionKeys): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 56, 'C' => 20, 'D' => 12, 'E' => 40]);
        $this->banner($ws, $title, 'E');
        $r = 6;
        foreach ($this->d['statement'] as $block) {
            if ($block['type'] !== 'section' || ! in_array($block['key'], $sectionKeys, true)) {
                continue;
            }
            $ws->setCellValue("B{$r}", 'CONCEPTO');
            $ws->setCellValue("C{$r}", 'IMPORTE');
            $ws->setCellValue("D{$r}", '% ING.');
            $ws->setCellValue("E{$r}", 'FUENTE');
            $this->headerRow($ws, "B{$r}:E{$r}");
            $r++;
            foreach ($block['lines'] as $l) {
                $ws->setCellValue("B{$r}", $l['label']);
                $this->money($ws, "C{$r}", $l['amount']);
                $this->pct($ws, "D{$r}", $l['pct']);
                $ws->setCellValue("E{$r}", $l['source'].($l['hint'] ? ' · '.$l['hint'] : ''));
                $r++;
            }
            $ws->setCellValue("B{$r}", $block['total']['label']);
            $this->money($ws, "C{$r}", $block['total']['amount']);
            $this->pct($ws, "D{$r}", $block['total']['pct']);
            $ws->getStyle("B{$r}:E{$r}")->getFont()->setBold(true);
            $ws->getStyle("B{$r}:E{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::SOFT);
            $r += 2;
        }
    }

    private function sheetItems(Worksheet $ws, string $title, callable $filter): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 11, 'C' => 15, 'D' => 34, 'E' => 12, 'F' => 14, 'G' => 26, 'H' => 30, 'I' => 16, 'J' => 14, 'K' => 14, 'L' => 13, 'M' => 14, 'N' => 12, 'O' => 30, 'P' => 28, 'Q' => 14, 'R' => 60]);
        $this->banner($ws, $title, 'R');
        $items = array_values(array_filter($this->d['movements'], fn ($m) => $m['in_period'] && $filter($m)));
        $ws->setCellValue('B6', count($items).' movimiento(s). Sólo los marcados "Suma a" afectan el resultado; los pendientes NO suman hasta clasificarse.');
        $ws->getStyle('B6')->getFont()->setItalic(true)->getColor()->setRGB(self::MUTED);
        $this->movementTable($ws, 8, $items);
    }

    private function sheetPayroll(Worksheet $ws): void
    {
        $this->sheetLines($ws, 'NÓMINA Y CAPITAL HUMANO', ['nomina_sucursales', 'nomina_corporativa']);
        $items = array_values(array_filter($this->d['movements'], fn ($m) => $m['in_period'] && $m['treatment'] === 'nomina_corporativa'));
        if ($items !== []) {
            $start = $ws->getHighestRow() + 2;
            $ws->setCellValue("B{$start}", 'DETALLE NÓMINA CORPORATIVA');
            $ws->getStyle("B{$start}")->getFont()->setBold(true)->getColor()->setRGB(self::TEAL);
            foreach (['F' => 14, 'G' => 26, 'H' => 30, 'I' => 16, 'J' => 14, 'K' => 14, 'L' => 13, 'M' => 14, 'N' => 12, 'O' => 30, 'P' => 28, 'Q' => 14, 'R' => 60] as $col => $w) {
                $ws->getColumnDimension($col)->setWidth($w);
            }
            $this->movementTable($ws, $start + 1, $items);
        }
    }

    private function sheetAdjustments(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 46, 'C' => 18, 'D' => 20, 'E' => 40, 'F' => 40]);
        $this->banner($ws, 'AJUSTES CONTABLES Y FLUJO', 'F');
        $ws->setCellValue('B5', 'Un campo "Sin información" NO equivale a $0.00: el subtotal afectado se marca como preliminar.');
        $ws->getStyle('B5')->getFont()->setItalic(true)->getColor()->setRGB('B45309');
        $ws->setCellValue('B7', 'CONCEPTO');
        $ws->setCellValue('C7', 'ESTADO');
        $ws->setCellValue('D7', 'IMPORTE');
        $ws->setCellValue('E7', 'EFECTO');
        $ws->setCellValue('F7', 'NOTAS / CAPTURÓ');
        $this->headerRow($ws, 'B7:F7');
        $r = 8;
        $stateLabel = ['sin_informacion' => 'Sin información', 'confirmado_cero' => 'Confirmado $0', 'capturado' => 'Capturado'];
        foreach ($this->d['adjustments'] as $a) {
            $ws->setCellValue("B{$r}", $a['label']);
            $ws->setCellValue("C{$r}", $stateLabel[$a['state']] ?? $a['state']);
            if ($a['amount'] !== null) {
                $this->money($ws, "D{$r}", $a['amount']);
            } else {
                $ws->setCellValue("D{$r}", '—');
            }
            $ws->setCellValue("E{$r}", $a['effect']);
            $ws->setCellValue("F{$r}", trim(($a['notes'] ?? '').($a['updated_by'] ? ' · '.$a['updated_by'].' '.$a['updated_at'] : '')));
            if ($a['state'] === 'sin_informacion') {
                $ws->getStyle("B{$r}:F{$r}")->getFont()->getColor()->setRGB('B45309');
            }
            $r++;
        }
        $r += 2;
        $ws->setCellValue("B{$r}", 'IMPORTANTE: CAPEX no reduce EBITDA (flujo de inversión). El capital pagado de préstamos no es gasto financiero: sólo los intereses afectan el EBT. Los movimientos personales de socios/CEO no son OPEX.');
        $ws->mergeCells("B{$r}:F{$r}");
        $ws->getStyle("B{$r}")->getAlignment()->setWrapText(true);
        $ws->getRowDimension($r)->setRowHeight(32);
    }

    private function sheetOutside(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 11, 'C' => 15, 'D' => 34, 'E' => 12, 'F' => 14, 'G' => 26, 'H' => 30, 'I' => 16, 'J' => 14, 'K' => 14, 'L' => 13, 'M' => 14, 'N' => 12, 'O' => 30, 'P' => 28, 'Q' => 14, 'R' => 60]);
        $this->banner($ws, 'MOVIMIENTOS FUERA DEL ESTADO DE RESULTADOS', 'R');
        $ws->setCellValue('B6', 'TRATAMIENTO');
        $ws->setCellValue('E6', 'CANTIDAD');
        $ws->setCellValue('F6', 'CARGOS');
        $ws->setCellValue('G6', 'ABONOS');
        $ws->setCellValue('H6', 'POR QUÉ NO AFECTA EL RESULTADO');
        $this->headerRow($ws, 'B6:R6');
        $r = 7;
        foreach ($this->d['outside']['groups'] as $g) {
            $ws->setCellValue("B{$r}", $g['label']);
            $ws->mergeCells("B{$r}:D{$r}");
            $ws->setCellValue("E{$r}", $g['count']);
            $this->money($ws, "F{$r}", $g['cargos']);
            $this->money($ws, "G{$r}", $g['abonos']);
            $ws->setCellValue("H{$r}", $g['explain']);
            $ws->mergeCells("H{$r}:R{$r}");
            $r++;
        }
        $r += 1;
        $items = array_values(array_filter($this->d['movements'], fn ($m) => ! $m['impact']['included']));
        $this->movementTable($ws, $r, $items);
    }

    private function sheetControl(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 60, 'C' => 20, 'D' => 20, 'E' => 16, 'F' => 10]);
        $this->banner($ws, 'FUENTES Y CONTROL', 'F');
        $m = $this->d['meta'];
        $rows = [
            ['Hash del dataset (igual en web, Excel y PDF)', $m['dataset_hash']],
            ['Versión de reglas de clasificación', $m['rules_version']],
            ['Tolerancia de fechas en conciliación (días)', (string) $m['tolerance_days']],
            ['Generado', $m['generated_at']],
        ];
        $r = 6;
        foreach ($rows as [$l, $v]) {
            $ws->setCellValue("B{$r}", $l);
            $ws->setCellValueExplicit("C{$r}", $v, DataType::TYPE_STRING);
            $ws->mergeCells("C{$r}:F{$r}");
            $r++;
        }
        $r++;
        $ws->setCellValue("B{$r}", 'FUENTE');
        $ws->setCellValue("C{$r}", 'ESTADO');
        $ws->setCellValue("D{$r}", 'DETALLE');
        $this->headerRow($ws, "B{$r}:F{$r}");
        $r++;
        $s = $this->d['sources'];
        $list = [$s['radiografia'], $s['erp'], $s['nomina']];
        foreach ($s['cuentas'] as $cta) {
            $list[] = ['label' => $cta['label'], 'status' => $cta['status'], 'detail' => $cta['upload'] ? ($cta['upload']['name'].' · '.$cta['upload']['movements'].' movimientos · sha256 '.substr($cta['upload']['hash'], 0, 16).'… · control '.($cta['upload']['control_ok'] === null ? 'sin totales' : ($cta['upload']['control_ok'] ? 'OK' : 'NO CUADRA'))) : 'Sin archivo cargado'];
        }
        $list[] = $s['ajustes'];
        $list[] = ['label' => $s['procesamiento']['label'], 'status' => $s['procesamiento']['status'], 'detail' => $s['procesamiento']['last_run'] ? ('Último: '.$s['procesamiento']['last_run']['at'].' por '.($s['procesamiento']['last_run']['user'] ?? '—')) : 'Sin procesar'];
        foreach ($list as $src) {
            $ws->setCellValue("B{$r}", $src['label']);
            $ws->setCellValue("C{$r}", mb_strtoupper(str_replace('_', ' ', $src['status'])));
            $ws->setCellValue("D{$r}", $src['detail'] ?? '');
            $ws->mergeCells("D{$r}:F{$r}");
            $r++;
        }
        $r++;
        $ws->setCellValue("B{$r}", 'VALIDACIÓN MATEMÁTICA');
        $ws->setCellValue("C{$r}", 'ESPERADO');
        $ws->setCellValue("D{$r}", 'CALCULADO');
        $ws->setCellValue("E{$r}", 'DIFERENCIA');
        $ws->setCellValue("F{$r}", 'OK');
        $this->headerRow($ws, "B{$r}:F{$r}");
        $r++;
        foreach ($this->d['checks'] as $c) {
            $ws->setCellValue("B{$r}", $c['label']);
            $this->money($ws, "C{$r}", $c['expected']);
            $this->money($ws, "D{$r}", $c['actual']);
            $this->money($ws, "E{$r}", $c['difference']);
            $ws->setCellValue("F{$r}", $c['ok'] ? '✓' : '✗');
            $ws->getStyle("F{$r}")->getFont()->setBold(true)->getColor()->setRGB($c['ok'] ? self::GREEN : self::BAD);
            $r++;
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function movementTable(Worksheet $ws, int $r, array $items): void
    {
        $headers = ['FECHA', 'ORIGEN', 'REFERENCIA', 'FOLIO/REQ', 'UUID', 'BENEFICIARIO', 'CONCEPTO', 'SUCURSAL', 'CARGO', 'ABONO', 'ERP MONTO', 'ERP FECHA', 'ESTADO CONCILIACIÓN', 'CLASIFICACIÓN', 'IMPACTO', 'SUGERENCIA'];
        $col = 'B';
        foreach ($headers as $h) {
            $ws->setCellValue("{$col}{$r}", $h);
            $col++;
        }
        $this->headerRow($ws, "B{$r}:Q{$r}");
        $ws->setCellValue("R{$r}", 'MOTIVO / EVIDENCIA');
        $this->headerRow($ws, "R{$r}");
        $ws->setAutoFilter("B{$r}:R{$r}");
        $r++;
        foreach ($items as $m) {
            $erp = $m['erp'][0] ?? null;
            $origin = $m['source'] === 'erp' ? 'ERP' : (($m['account_label'] ?? $m['account_slot']).' f.'.$m['source_row']);
            $values = [
                'B' => $m['date'], 'C' => $origin, 'D' => $m['reference'], 'E' => $m['folio'], 'F' => $m['uuid'],
                'G' => $m['beneficiary'], 'H' => $m['concept'], 'I' => $m['branch_name'] ?? $m['sucursal_raw'],
            ];
            foreach ($values as $c => $v) {
                $ws->setCellValueExplicit("{$c}{$r}", (string) ($v ?? ''), DataType::TYPE_STRING);
            }
            $this->money($ws, "J{$r}", $m['cargo']);
            $this->money($ws, "K{$r}", $m['abono']);
            if ($erp) {
                $this->money($ws, "L{$r}", $erp['amount']);
                $ws->setCellValue("M{$r}", (string) $erp['date']);
            }
            $ws->setCellValue("N{$r}", $m['reconciliation_label']);
            $ws->setCellValue("O{$r}", $m['treatment_label'].($m['treatment_source'] === 'manual' ? ' (manual)' : ''));
            $ws->setCellValue("P{$r}", $m['impact']['included'] ? 'Suma $'.number_format((float) $m['impact']['amount'], 2) : 'No suma');
            $reason = $m['override'] ? ('Manual: '.$m['override']['notes'].' — '.$m['override']['user'].' '.$m['override']['at'].' (antes: '.$m['override']['previous'].')') : ($m['rule_reason'] ?? '');
            if ($m['reconciliation_message'] && $m['reconciliation_message'] !== $reason) {
                $reason = trim($reason.' | '.$m['reconciliation_message']);
            }
            $ws->setCellValue("Q{$r}", $m['suggested_label'] ? 'Sugerido: '.$m['suggested_label'] : '');
            $ws->setCellValue("R{$r}", $reason);
            $color = $m['impact']['included'] ? self::GREEN : ($m['needs_review'] ? 'B45309' : self::MUTED);
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
        $ws->getHeaderFooter()->setOddFooter('&L&8MR LANA · Estado de Resultados · '.$this->d['meta']['period']['label'].' · '.$this->d['meta']['scope']['label'].'&R&8Página &P de &N');
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
        $ws->setCellValue('C1', $title);
        $ws->getStyle('C1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::INK);
        $m = $this->d['meta'];
        $ws->setCellValue('C2', $m['scope']['label'].'  ·  '.mb_strtoupper($m['period']['label']).'  ·  CIFRAS EN MXN');
        $ws->getStyle('C2')->getFont()->setBold(true)->setSize(10)->getColor()->setRGB(self::TEAL);
        $ws->setCellValue('C3', 'Periodo '.$m['period']['code'].' ('.$m['period']['start'].' a '.$m['period']['end'].') · Generado '.$m['generated_at'].' · Dataset '.substr($m['dataset_hash'], 0, 12));
        $ws->getStyle('C3')->getFont()->setSize(8)->getColor()->setRGB(self::MUTED);
        $ws->getStyle("B4:{$lastCol}4")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THICK)->getColor()->setRGB(self::GREEN);
    }

    private function headerRow(Worksheet $ws, string $range): void
    {
        $style = $ws->getStyle($range);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::TEAL);
        $style->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('FFFFFF');
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    }

    private function money(Worksheet $ws, string $cell, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        $ws->setCellValue($cell, (float) $value);
        $ws->getStyle($cell)->getNumberFormat()->setFormatCode(self::MONEY);
    }

    private function pct(Worksheet $ws, string $cell, ?string $value): void
    {
        if ($value === null) {
            $ws->setCellValue($cell, '—');

            return;
        }
        $ws->setCellValue($cell, round((float) $value / 100, 6));
        $ws->getStyle($cell)->getNumberFormat()->setFormatCode(self::PCT);
    }

    private function indicatorValue(Worksheet $ws, string $cell, array $ind): void
    {
        if ($ind['value'] === null) {
            $ws->setCellValue($cell, 'Sin información');
            $ws->getStyle($cell)->getFont()->getColor()->setRGB(self::MUTED);

            return;
        }
        match ($ind['unit']) {
            'MXN' => $this->money($ws, $cell, $ind['value']),
            '%' => $this->pct($ws, $cell, $ind['value']),
            default => $ws->setCellValue($cell, $ind['value']),
        };
    }
}
