<?php

namespace App\Services\EstadoResultados\Export;

use App\Services\EstadoResultados\Reporting\ReportStatus;
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
        $this->sheetItems($book->createSheet()->setTitle('DG'), 'DIRECCIÓN GENERAL', fn ($m) => in_array($m['treatment'], ['dg_operativo', 'dg_remuneracion'], true) || in_array($m['rule_code'], ['dg_requiere_revision', 'erp_dg'], true), 'dg');
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

        $this->put($ws, 'B6', 'CONCEPTO');
        $this->put($ws, 'C6', 'IMPORTE (MXN)');
        $this->put($ws, 'D6', '% INGRESOS');
        $this->headerRow($ws, 'B6:D6');

        $r = 7;
        foreach ($this->d['statement'] as $block) {
            if ($block['type'] === 'section') {
                $this->put($ws, "B{$r}", $block['number'].'   '.$block['title']);
                $ws->getStyle("B{$r}:D{$r}")->getFont()->setBold(true)->setSize(10)->getColor()->setRGB(self::TEAL);
                $ws->getStyle("B{$r}:D{$r}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::GREEN);
                $r++;
                if (! $block['applies']) {
                    $this->put($ws, "B{$r}", $block['note']);
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
                    $this->put($ws, "B{$r}", $label);
                    if ($line['state'] === 'sin_informacion') {
                        // Un dato desconocido nunca se escribe como $0.00 (la SUMA de validación lo ignora).
                        $this->put($ws, "C{$r}", 'Sin información');
                        $this->put($ws, "D{$r}", '—');
                    } else {
                        $this->money($ws, "C{$r}", $line['amount']);
                        $this->pct($ws, "D{$r}", $line['pct']);
                    }
                    if ($line['state'] === 'sin_informacion') {
                        $ws->getStyle("B{$r}:D{$r}")->getFont()->getColor()->setRGB('B45309');
                    }
                    $r++;
                }
                $last = $r - 1;
                $this->put($ws, "B{$r}", $block['total']['label']);
                $unknown = collect($block['lines'])->contains(fn ($l) => $l['state'] === 'sin_informacion');
                if ($unknown && (float) $block['total']['amount'] == 0.0) {
                    $this->put($ws, "C{$r}", 'Sin información');
                    $this->put($ws, "D{$r}", '—');
                } else {
                    $this->money($ws, "C{$r}", $block['total']['amount']);
                    $this->pct($ws, "D{$r}", $block['total']['pct']);
                }
                $ws->getStyle("B{$r}:D{$r}")->getFont()->setBold(true);
                $ws->getStyle("B{$r}:D{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::SOFT);
                $ws->getStyle("B{$r}:D{$r}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::TEAL);
                // Validación con fórmula PROPIA: Σ líneas − total (debe ser 0.00; N() ignora "Sin información")
                if ($last >= $first) {
                    $ws->setCellValue("E{$r}", "=ROUND(SUM(C{$first}:C{$last})-N(C{$r}),2)");
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
            $this->put($ws, "B{$r}", $label);
            $this->money($ws, "C{$r}", $block['amount']);
            $this->pct($ws, "D{$r}", $block['pct']);
            $ws->getStyle("B{$r}:D{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
            $ws->getStyle("B{$r}:D{$r}")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('FFFFFF');
            $ws->getRowDimension($r)->setRowHeight(24);
            $ws->getStyle("B{$r}:D{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $r++;
            $this->put($ws, "B{$r}", '   '.$block['formula'].($block['complete'] ? '' : ' · Falta: '.implode(', ', $block['missing'])));
            $ws->getStyle("B{$r}")->getFont()->setItalic(true)->setSize(8)->getColor()->setRGB(self::MUTED);
            $r += 2;
        }

        // Panel lateral: márgenes, conciliación con Radiografía, indicadores clave
        $p = 6;
        $this->put($ws, "F{$p}", 'MÁRGENES');
        $this->put($ws, "G{$p}", '% INGRESOS');
        $this->headerRow($ws, "F{$p}:G{$p}");
        $p++;
        $labels = ['ebitda_sucursales' => 'Margen EBITDA sucursales', 'ebitda_corporativo' => 'Margen EBITDA corporativo', 'ebitda_final' => 'Margen EBITDA final', 'ebit' => 'Margen EBIT', 'ebt' => 'Margen antes de impuestos', 'neto' => 'Margen neto'];
        foreach ($labels as $k => $label) {
            if ($this->d['margins'][$k] === null) {
                continue;
            }
            $this->put($ws, "F{$p}", $label);
            $this->pct($ws, "G{$p}", $this->d['margins'][$k]);
            $p++;
        }
        $p++;
        $rec = $this->d['radiography_reconciliation'];
        $this->put($ws, "F{$p}", 'CONCILIACIÓN CON RADIOGRAFÍA');
        $this->put($ws, "G{$p}", 'MXN');
        $this->headerRow($ws, "F{$p}:G{$p}");
        $p++;
        foreach ([['EBITDA sucursales (ER)', $rec['er_ebitda_sucursales']], ['EBITDA Radiografía', $rec['radiografia_ebitda']], ['Diferencia', $rec['difference']]] as [$l, $v]) {
            $this->put($ws, "F{$p}", $l);
            $this->money($ws, "G{$p}", $v);
            $p++;
        }
        $ws->getStyle('F'.($p - 1).':G'.($p - 1))->getFont()->setBold(true)->getColor()->setRGB($rec['ok'] ? self::GREEN : self::BAD);
        foreach ($rec['explanations'] as $e) {
            $this->put($ws, "F{$p}", '· '.$e['label']);
            $this->money($ws, "G{$p}", $e['amount']);
            $ws->getStyle("F{$p}")->getAlignment()->setWrapText(true);
            $p++;
        }
        $p++;
        $this->put($ws, "F{$p}", 'INDICADORES (INFORMATIVOS)');
        $this->headerRow($ws, "F{$p}:G{$p}");
        $p++;
        foreach ($this->d['indicators']['informative'] as $ind) {
            $this->put($ws, "F{$p}", $ind['label']);
            $this->indicatorValue($ws, "G{$p}", $ind);
            $p++;
        }
        $this->put($ws, 'F'.($p + 1), 'Cartera, mora, colocación y recuperación son estadísticos: no entran a la fórmula del Estado de Resultados.');
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
            $this->put($ws, "B{$r}", $title);
            $this->put($ws, "C{$r}", 'VALOR');
            $this->headerRow($ws, "B{$r}:C{$r}");
            $r++;
            foreach ($this->d['indicators'][$group] as $ind) {
                $this->put($ws, "B{$r}", $ind['label']);
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
        $this->put($ws, 'B6', 'RESUMEN');
        $this->put($ws, 'E6', 'CANTIDAD');
        $this->put($ws, 'F6', 'IMPORTE');
        $this->headerRow($ws, 'B6:F6');
        $r = 7;
        foreach ($summary as [$label, $count, $amount]) {
            $this->put($ws, "B{$r}", $label);
            $ws->mergeCells("B{$r}:D{$r}");
            if ($count !== null) {
                $this->put($ws, "E{$r}", $count);
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
            $this->put($ws, "B{$r}", 'CONCEPTO');
            $this->put($ws, "C{$r}", 'IMPORTE');
            $this->put($ws, "D{$r}", '% ING.');
            $this->put($ws, "E{$r}", 'FUENTE');
            $this->headerRow($ws, "B{$r}:E{$r}");
            $r++;
            foreach ($block['lines'] as $l) {
                $this->put($ws, "B{$r}", $l['label']);
                $this->money($ws, "C{$r}", $l['amount']);
                $this->pct($ws, "D{$r}", $l['pct']);
                $this->put($ws, "E{$r}", $l['source'].($l['hint'] ? ' · '.$l['hint'] : ''));
                $r++;
            }
            $this->put($ws, "B{$r}", $block['total']['label']);
            $this->money($ws, "C{$r}", $block['total']['amount']);
            $this->pct($ws, "D{$r}", $block['total']['pct']);
            $ws->getStyle("B{$r}:E{$r}")->getFont()->setBold(true);
            $ws->getStyle("B{$r}:E{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::SOFT);
            $r += 2;
        }
    }

    private function sheetItems(Worksheet $ws, string $title, callable $filter, ?string $restrictedKey = null): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 11, 'C' => 15, 'D' => 34, 'E' => 12, 'F' => 14, 'G' => 26, 'H' => 30, 'I' => 16, 'J' => 14, 'K' => 14, 'L' => 13, 'M' => 14, 'N' => 12, 'O' => 30, 'P' => 28, 'Q' => 14, 'R' => 60]);
        $this->banner($ws, $title, 'R');
        $items = array_values(array_filter($this->d['movements'], fn ($m) => $m['in_period'] && $filter($m)));
        if ($restrictedKey !== null && ! empty($this->d['meta']['redactions'][$restrictedKey])) {
            $this->restrictedNote($ws, 6, 'El detalle de Dirección General está restringido para tu rol (permiso «Consultar detalle de Dirección General»). El total sí está incluido en el EBITDA final.');

            return;
        }
        $this->put($ws, 'B6', count($items).' movimiento(s). Sólo los marcados "Suma a" afectan el resultado; los pendientes NO suman hasta clasificarse.');
        $ws->getStyle('B6')->getFont()->setItalic(true)->getColor()->setRGB(self::MUTED);
        $this->movementTable($ws, 8, $items);
    }

    private function restrictedNote(Worksheet $ws, int $row, string $text): void
    {
        $this->put($ws, "B{$row}", $text);
        $ws->getStyle("B{$row}")->getFont()->setBold(true)->getColor()->setRGB('B45309');
    }

    private function sheetPayroll(Worksheet $ws): void
    {
        $this->sheetLines($ws, 'NÓMINA Y CAPITAL HUMANO', ['nomina_sucursales', 'nomina_corporativa']);
        if (! empty($this->d['meta']['redactions']['nomina'])) {
            $this->restrictedNote($ws, $ws->getHighestRow() + 2, 'El detalle de nómina está restringido para tu rol (permiso «Consultar detalle de nómina»). Los totales sí están incluidos en el resultado.');

            return;
        }
        $items = array_values(array_filter($this->d['movements'], fn ($m) => $m['in_period'] && $m['treatment'] === 'nomina_corporativa'));
        if ($items !== []) {
            $start = $ws->getHighestRow() + 2;
            $this->put($ws, "B{$start}", 'DETALLE NÓMINA CORPORATIVA');
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
        $this->put($ws, 'B5', 'Un campo "Sin información" NO equivale a $0.00: el subtotal afectado se marca como preliminar.');
        $ws->getStyle('B5')->getFont()->setItalic(true)->getColor()->setRGB('B45309');
        $this->put($ws, 'B7', 'CONCEPTO');
        $this->put($ws, 'C7', 'ESTADO');
        $this->put($ws, 'D7', 'IMPORTE');
        $this->put($ws, 'E7', 'EFECTO');
        $this->put($ws, 'F7', 'NOTAS / CAPTURÓ');
        $this->headerRow($ws, 'B7:F7');
        $r = 8;
        $stateLabel = ['sin_informacion' => 'Sin información', 'confirmado_cero' => 'Confirmado $0', 'capturado' => 'Capturado'];
        foreach ($this->d['adjustments'] as $a) {
            $this->put($ws, "B{$r}", $a['label']);
            $this->put($ws, "C{$r}", $stateLabel[$a['state']] ?? $a['state']);
            if ($a['amount'] !== null) {
                $this->money($ws, "D{$r}", $a['amount']);
            } else {
                $this->put($ws, "D{$r}", '—');
            }
            $this->put($ws, "E{$r}", $a['effect']);
            $this->put($ws, "F{$r}", trim(($a['notes'] ?? '').($a['updated_by'] ? ' · '.$a['updated_by'].' '.$a['updated_at'] : '')));
            if ($a['state'] === 'sin_informacion') {
                $ws->getStyle("B{$r}:F{$r}")->getFont()->getColor()->setRGB('B45309');
            }
            $r++;
        }
        $r += 2;
        $this->put($ws, "B{$r}", 'IMPORTANTE: CAPEX no reduce EBITDA (flujo de inversión). El capital pagado de préstamos no es gasto financiero: sólo los intereses afectan el EBT. Los movimientos personales de socios/CEO no son OPEX.');
        $ws->mergeCells("B{$r}:F{$r}");
        $ws->getStyle("B{$r}")->getAlignment()->setWrapText(true);
        $ws->getRowDimension($r)->setRowHeight(32);
    }

    private function sheetOutside(Worksheet $ws): void
    {
        $this->setup($ws, ['A' => 2, 'B' => 11, 'C' => 15, 'D' => 34, 'E' => 12, 'F' => 14, 'G' => 26, 'H' => 30, 'I' => 16, 'J' => 14, 'K' => 14, 'L' => 13, 'M' => 14, 'N' => 12, 'O' => 30, 'P' => 28, 'Q' => 14, 'R' => 60]);
        $this->banner($ws, 'MOVIMIENTOS FUERA DEL ESTADO DE RESULTADOS', 'R');
        $this->put($ws, 'B6', 'TRATAMIENTO');
        $this->put($ws, 'E6', 'CANTIDAD');
        $this->put($ws, 'F6', 'CARGOS');
        $this->put($ws, 'G6', 'ABONOS');
        $this->put($ws, 'H6', 'POR QUÉ NO AFECTA EL RESULTADO');
        $this->headerRow($ws, 'B6:R6');
        $r = 7;
        foreach ($this->d['outside']['groups'] as $g) {
            $this->put($ws, "B{$r}", $g['label']);
            $ws->mergeCells("B{$r}:D{$r}");
            $this->put($ws, "E{$r}", $g['count']);
            $this->money($ws, "F{$r}", $g['cargos']);
            $this->money($ws, "G{$r}", $g['abonos']);
            $this->put($ws, "H{$r}", $g['explain']);
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
        $rep = $this->report();
        $rows[] = ['Versión del reporte', (string) $rep['version']];
        $rows[] = ['Estado del reporte', $rep['status_label'].($rep['preliminary'] ? ' · PRELIMINAR' : '')];
        $rows[] = ['Origen de las cifras', $rep['source'] === 'snapshot' ? 'Snapshot aprobado (inmutable)' : 'Cálculo en vivo'];
        $rows[] = ['Aprobado por / fecha', $rep['approved_at'] ? ($rep['approved_by'] ?? '—').' · '.$rep['approved_at'] : 'Sin aprobar'];
        $rows[] = ['Hash del snapshot aprobado', $rep['snapshot_hash'] ?? '—'];
        $rows[] = ['Criterio gerencial aplicado', $rep['criterion']];
        $rows[] = ['Documento emitido', $rep['issued_at']];
        foreach ($rep['preliminary_reasons'] as $reason) {
            $rows[] = ['Motivo PRELIMINAR', $reason];
        }
        if (! empty($m['redactions']['message'])) {
            $rows[] = ['Acceso restringido', $m['redactions']['message']];
        }
        $r = 6;
        foreach ($rows as [$l, $v]) {
            $this->put($ws, "B{$r}", $l);
            $this->put($ws, "C{$r}", (string) $v);
            $ws->mergeCells("C{$r}:F{$r}");
            if ($l === 'Criterio gerencial aplicado' || $l === 'Motivo PRELIMINAR') {
                $ws->getStyle("C{$r}")->getAlignment()->setWrapText(true);
                $ws->getRowDimension($r)->setRowHeight($l === 'Criterio gerencial aplicado' ? 52 : 26);
            }
            if ($l === 'Motivo PRELIMINAR' || ($l === 'Estado del reporte' && $rep['preliminary'])) {
                $ws->getStyle("B{$r}:C{$r}")->getFont()->setBold(true)->getColor()->setRGB(self::BAD);
            }
            $r++;
        }
        $r++;
        $this->put($ws, "B{$r}", 'FUENTE');
        $this->put($ws, "C{$r}", 'ESTADO');
        $this->put($ws, "D{$r}", 'DETALLE');
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
            $this->put($ws, "B{$r}", $src['label']);
            $this->put($ws, "C{$r}", mb_strtoupper(str_replace('_', ' ', $src['status'])));
            $this->put($ws, "D{$r}", $src['detail'] ?? '');
            $ws->mergeCells("D{$r}:F{$r}");
            $r++;
        }
        $r++;
        $this->put($ws, "B{$r}", 'VALIDACIÓN MATEMÁTICA');
        $this->put($ws, "C{$r}", 'ESPERADO');
        $this->put($ws, "D{$r}", 'CALCULADO');
        $this->put($ws, "E{$r}", 'DIFERENCIA');
        $this->put($ws, "F{$r}", 'OK');
        $this->headerRow($ws, "B{$r}:F{$r}");
        $r++;
        foreach ($this->d['checks'] as $c) {
            $this->put($ws, "B{$r}", $c['label']);
            $this->money($ws, "C{$r}", $c['expected']);
            $this->money($ws, "D{$r}", $c['actual']);
            $this->money($ws, "E{$r}", $c['difference']);
            $this->put($ws, "F{$r}", $c['ok'] ? '✓' : '✗');
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
            $this->put($ws, "{$col}{$r}", $h);
            $col++;
        }
        $this->headerRow($ws, "B{$r}:Q{$r}");
        $this->put($ws, "R{$r}", 'MOTIVO / EVIDENCIA');
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
                $this->put($ws, "{$c}{$r}", (string) ($v ?? ''));
            }
            $this->money($ws, "J{$r}", $m['cargo']);
            $this->money($ws, "K{$r}", $m['abono']);
            if ($erp) {
                $this->money($ws, "L{$r}", $erp['amount']);
                $this->put($ws, "M{$r}", (string) $erp['date']);
            }
            $this->put($ws, "N{$r}", $m['reconciliation_label']);
            $this->put($ws, "O{$r}", $m['treatment_label'].($m['treatment_source'] === 'manual' ? ' (manual)' : ''));
            $this->put($ws, "P{$r}", $m['impact']['included'] ? 'Suma $'.number_format((float) $m['impact']['amount'], 2) : 'No suma');
            $reason = $m['override'] ? ('Manual: '.$m['override']['notes'].' — '.$m['override']['user'].' '.$m['override']['at'].' (antes: '.$m['override']['previous'].')') : ($m['rule_reason'] ?? '');
            if ($m['reconciliation_message'] && $m['reconciliation_message'] !== $reason) {
                $reason = trim($reason.' | '.$m['reconciliation_message']);
            }
            $this->put($ws, "Q{$r}", $m['suggested_label'] ? 'Sugerido: '.$m['suggested_label'] : '');
            $this->put($ws, "R{$r}", $reason);
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
        $esc = fn (string $t) => str_replace('&', '&&', $t);
        $rep = $this->report();
        $ws->getHeaderFooter()->setOddFooter('&L&8MR LANA · Estado de Resultados · '.$esc($this->d['meta']['period']['label']).' · '.$esc($this->d['meta']['scope']['label']).' · v'.$rep['version'].' '.$esc($rep['status_label']).($rep['preliminary'] ? ' · PRELIMINAR' : '').'&R&8Página &P de &N');
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
        $m = $this->d['meta'];
        $this->put($ws, 'C2', $m['scope']['label'].'  ·  '.mb_strtoupper($m['period']['label']).'  ·  CIFRAS EN MXN');
        $ws->getStyle('C2')->getFont()->setBold(true)->setSize(10)->getColor()->setRGB(self::TEAL);
        $this->put($ws, 'C3', 'Periodo '.$m['period']['code'].' ('.$m['period']['start'].' a '.$m['period']['end'].') · Generado '.$m['generated_at'].' · Dataset '.substr($m['dataset_hash'], 0, 12));
        $ws->getStyle('C3')->getFont()->setSize(8)->getColor()->setRGB(self::MUTED);
        $rep = $this->report();
        $this->put($ws, 'C4', 'Versión '.$rep['version'].' · Estado: '.mb_strtoupper($rep['status_label'])
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
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    }

    /**
     * Escritura SEGURA de cualquier valor derivado de usuarios/fuentes: los textos
     * se escriben como cadena explícita (nunca como fórmula) y, si empiezan con
     * = + - @ tab o CR, además con prefijo de comilla (inyección de fórmulas / CSV
     * injection). Las únicas fórmulas del libro son las de validación propias,
     * escritas aparte con setCellValue().
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

    /** meta.report del resolver (o valores en vivo por defecto si el dataset viene directo del builder). */
    private function report(): array
    {
        $m = $this->d['meta'];

        return ($m['report'] ?? []) + [
            'status' => 'borrador', 'status_label' => 'Borrador', 'version' => 1, 'source' => 'en_vivo',
            'preliminary' => false, 'preliminary_reasons' => [], 'approved_by' => null, 'approved_at' => null,
            'snapshot_hash' => null, 'criterion' => $m['criterion'] ?? ReportStatus::DEFAULT_CRITERION,
            'rules_version' => $m['rules_version'] ?? '', 'generated_at' => $m['generated_at'] ?? '', 'issued_at' => now('America/Mexico_City')->format('d/m/Y H:i'),
        ];
    }

    private function money(Worksheet $ws, string $cell, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        $this->put($ws, $cell, (float) $value);
        $ws->getStyle($cell)->getNumberFormat()->setFormatCode(self::MONEY);
    }

    private function pct(Worksheet $ws, string $cell, ?string $value): void
    {
        if ($value === null) {
            $this->put($ws, $cell, '—');

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
