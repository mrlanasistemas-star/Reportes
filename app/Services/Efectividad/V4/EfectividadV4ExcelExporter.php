<?php

namespace App\Services\Efectividad\V4;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * XLSX con el formato de la hoja EFECTIVIDAD de la macro V4 (`GenerarReporte`,
 * `AplicarSemaforoEfectividad`, `AplicarColorMora`, `EscribirSubtotal`,
 * `EscribirGranTotal`, `MR_AutoconfigurarCorte`) y la bitácora
 * `_AUDITORIA_EFECTIVIDAD` (`GenerarBitacora`). Sin macros: solo valores y
 * formato. No incluye datos de clientes (nombres, direcciones); la bitácora
 * identifica movimientos por número de contrato, igual que la macro.
 */
class EfectividadV4ExcelExporter
{
    private const MONEDA = '$#,##0.00;-$#,##0.00';

    private const PCT = '0.00%';

    // Colores de la macro (RGB → hex).
    private const MR_VERDE = 'A0E04E';

    private const NEGRO = '000000';

    private const BLANCO = 'FFFFFF';

    private const AZUL_OSCURO = '123656';

    private const COB_VERDE = '46A88F';

    private const COB_AMARILLO = 'FFE598';

    private const COB_ROJO = 'C00000';

    private const COB_AZUL = '185D7B';

    /**
     * @param  list<array<string,mixed>>  $filas  `filas` de EfectividadV4Calculator::calculate()
     * @param  array<string,string>  $parametros  `parametros` del cálculo (modo, domingo_base, fecha_corte, inicio_ingresos, fin_ingresos)
     * @param  list<array<string,mixed>>  $incidencias
     * @param  array<string,mixed>  $fuentes  resumen de fotografías/ingresos usados (hoja FUENTES)
     */
    public function export(array $filas, array $parametros, array $incidencias, string $outputPath, array $fuentes = [], string $engineVersion = EfectividadV4Calculator::ENGINE_VERSION): string
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('Reportes MR LANA')->setTitle('Efectividad de Cobranza')->setDescription('Motor '.$engineVersion);
        $book->getDefaultStyle()->getFont()->setName('Montserrat');

        $ws = $book->getActiveSheet();
        $ws->setTitle('EFECTIVIDAD');
        $this->hojaEfectividad($ws, $filas, $parametros, $engineVersion);

        $this->hojaAuditoria($book->createSheet(), $incidencias, $parametros);
        $this->hojaFuentes($book->createSheet(), $fuentes, $parametros, $engineVersion);

        $book->setActiveSheetIndex(0);
        // El writer convierte cada Double a texto con `precision` (14 dígitos por omisión):
        // 0.9166666666666666 se guardaría como 0.91666666666667. Con -1 se escribe la
        // representación exacta más corta, la misma que conserva el XLSM de la macro.
        $precision = ini_get('precision');
        ini_set('precision', '-1');
        try {
            (new Xlsx($book))->save($outputPath);
        } finally {
            ini_set('precision', (string) $precision);
        }

        return $outputPath;
    }

    private function hojaEfectividad(Worksheet $ws, array $filas, array $p, string $engine): void
    {
        $ws->getTabColor()->setRGB(self::MR_VERDE);

        $this->banda($ws, 'A1:M1', 'MR LANA | EFECTIVIDAD DE COBRANZA', self::NEGRO, self::BLANCO, 18, true);
        $ws->getRowDimension(1)->setRowHeight(34);
        $this->banda($ws, 'A2:M2', 'PERIODO: '.$this->dmy($p['inicio_ingresos']).' AL '.$this->dmy($p['fin_ingresos']), self::MR_VERDE, self::NEGRO, 11, true);
        $ws->getRowDimension(2)->setRowHeight(24);
        $this->banda($ws, 'A3:M3', EfectividadV4Calculator::LEYENDA, self::BLANCO, '505050', 9, false, true);
        $ws->getRowDimension(3)->setRowHeight(22);

        // Fila 4: grupos.
        $this->celda($ws, 'A4', 'Oficina', self::AZUL_OSCURO, self::BLANCO, Alignment::HORIZONTAL_LEFT);
        foreach ([['B4:D4', 'SANO', self::COB_VERDE, self::BLANCO], ['E4:G4', 'VIGENTES CON ATRASO', self::COB_AMARILLO, self::NEGRO],
            ['H4:J4', 'VENCIDOS CON ATRASO', self::COB_ROJO, self::BLANCO], ['K4:L4', 'COBRANZA TOTAL', self::COB_AZUL, self::BLANCO]] as [$rng, $txt, $bg, $fg]) {
            $ws->mergeCells($rng);
            $this->celda($ws, $rng, $txt, $bg, $fg, Alignment::HORIZONTAL_CENTER, explode(':', $rng)[0]);
        }
        $this->celda($ws, 'M4', 'MORA', self::AZUL_OSCURO, self::BLANCO, Alignment::HORIZONTAL_CENTER);

        // Fila 5: subencabezados.
        $sub = ['Promotor', 'Por cobrar', 'Cobrado', '% Cobrado', 'Por cobrar', 'Cobrado', '% Cobrado', 'Por cobrar', 'Cobrado', '% Cobrado', 'Cobrado', '% Cobrado', ''];
        foreach ($sub as $i => $txt) {
            $ws->setCellValue($this->col($i + 1).'5', $txt);
        }
        $st = $ws->getStyle('A5:M5');
        $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::AZUL_OSCURO);
        $st->getFont()->setBold(true)->setSize(9)->getColor()->setRGB(self::BLANCO);
        $st->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $ws->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $ws->getStyle('B5:M5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getRowDimension(4)->setRowHeight(26);
        $ws->getRowDimension(5)->setRowHeight(28);

        // Datos.
        $fila = 6;
        $oficinaAnterior = null;
        $combinadas = [];
        if (! $filas) {
            $ws->setCellValue('A6', 'SIN MOVIMIENTOS PARA EL PERIODO');
        }
        foreach ($filas as $f) {
            if ($f['tipo'] === 'promotor' && ($oficinaAnterior === null || $f['oficina_titulo'] !== $oficinaAnterior)) {
                $ws->mergeCells("A{$fila}:M{$fila}");
                $combinadas[$fila] = true;
                // Texto explícito: una oficina que empiece con «=» nunca se interpreta como fórmula.
                $ws->setCellValueExplicit("A{$fila}", (string) $f['oficina_titulo'], DataType::TYPE_STRING);
                $s = $ws->getStyle("A{$fila}:M{$fila}");
                $s->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::BLANCO);
                $s->getFont()->setBold(true)->setSize(9)->getColor()->setRGB(self::NEGRO);
                $s->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
                $ws->getRowDimension($fila)->setRowHeight(20);
                $oficinaAnterior = $f['oficina_titulo'];
                $fila++;
            }

            $ws->setCellValueExplicit("A{$fila}", (string) $f['promotor'], DataType::TYPE_STRING);
            foreach (['sano_pc', 'sano_cob', 'sano_pct', 'atraso_pc', 'atraso_cob', 'atraso_pct', 'vencido_pc', 'vencido_cob', 'vencido_pct', 'cobranza_total', 'pct_total', 'mora'] as $i => $campo) {
                $ws->setCellValue($this->col($i + 2).$fila, (float) $f[$campo]);
            }
            $s = $ws->getStyle("A{$fila}:M{$fila}");
            $s->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::BLANCO);
            $s->getFont()->setBold(true)->setSize($f['tipo'] === 'gran_total' ? 11 : 9)->getColor()->setRGB(self::NEGRO);
            $s->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $ws->getStyle("A{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $ws->getStyle("B{$fila}:M{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            if ($f['tipo'] === 'subtotal') {
                $s->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B4B4B4');
                $ws->getRowDimension($fila)->setRowHeight(20);
            } elseif ($f['tipo'] === 'subtotal_sucursal') {
                // Solo en exportaciones multi-sucursal de la calculadora (la macro V4 nunca lo emite):
                // franja azul tenue para distinguir el total de sucursal, como en el PDF/imagen.
                foreach ([$s->getBorders()->getTop(), $s->getBorders()->getBottom()] as $b) {
                    $b->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COB_AZUL);
                }
                $s->getFill()->getStartColor()->setRGB('EAF2F8');
                $ws->getRowDimension($fila)->setRowHeight(20);
            } elseif ($f['tipo'] === 'gran_total') {
                foreach ([$s->getBorders()->getTop(), $s->getBorders()->getBottom()] as $b) {
                    $b->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(self::AZUL_OSCURO);
                }
                $a = $ws->getStyle("A{$fila}");
                $a->getFill()->getStartColor()->setRGB(self::AZUL_OSCURO);
                $a->getFont()->getColor()->setRGB(self::BLANCO);
                $ws->getRowDimension($fila)->setRowHeight(23);
            }
            $fila++;
        }
        $ultima = max(6, $fila - 1);

        foreach (['B', 'C', 'E', 'F', 'H', 'I', 'K', 'M'] as $c) {
            $ws->getStyle("{$c}6:{$c}{$ultima}")->getNumberFormat()->setFormatCode(self::MONEDA);
        }
        foreach (['D', 'G', 'J', 'L'] as $c) {
            $ws->getStyle("{$c}6:{$c}{$ultima}")->getNumberFormat()->setFormatCode(self::PCT);
        }

        // Semáforo de efectividad (columna L) y color de mora (columna M), filas no combinadas.
        for ($r = 6; $r <= $ultima; $r++) {
            if (isset($combinadas[$r])) {
                continue;
            }
            $l = $ws->getCell("L{$r}")->getValue();
            $b = $ws->getCell("B{$r}")->getValue();
            if (is_numeric($l) && is_numeric($b) && (float) $b > 0) {
                [$bg, $fg] = (float) $l >= 0.95 ? ['C6EFCE', '006100'] : ((float) $l >= 0.9 ? ['FFEB9C', '9C6500'] : ['FFC7CE', '9C0006']);
                $st = $ws->getStyle("L{$r}");
                $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bg);
                $st->getFont()->setBold(true)->getColor()->setRGB($fg);
            }
            $m = $ws->getCell("M{$r}")->getValue();
            if (is_numeric($m)) {
                $ws->getStyle("M{$r}")->getFont()->setBold(true)->getColor()->setRGB((float) $m < 0 ? '008000' : ((float) $m > 0 ? 'C00000' : self::NEGRO));
            }
        }

        $ws->getStyle("A4:M{$ultima}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D2D2D2');

        foreach (['A' => 42, 'B' => 14, 'C' => 14, 'D' => 13, 'E' => 14, 'F' => 14, 'G' => 13, 'H' => 14, 'I' => 14, 'J' => 13, 'K' => 14, 'L' => 13, 'M' => 14] as $c => $w) {
            $ws->getColumnDimension($c)->setWidth($w);
        }

        // Control de corte (P-Q), como MR_AutoconfigurarCorte.
        $ws->mergeCells('P1:Q1');
        $ws->setCellValue('P1', 'CONTROL DE CORTE AUTOMATICO | MR LANA');
        $ws->getStyle('P1:Q1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::NEGRO);
        $ws->getStyle('P1:Q1')->getFont()->getColor()->setRGB(self::MR_VERDE);
        $control = [
            2 => ['MODO', $p['modo']],
            3 => ['DOMINGO BASE', $p['domingo_base']],
            4 => ['FECHA DE CORTE', $p['fecha_corte']],
            5 => ['INICIO DE INGRESOS', $p['inicio_ingresos']],
            6 => ['FIN DE INGRESOS', $p['fin_ingresos']],
            7 => ['VERSIÓN DEL MOTOR', $engine],
        ];
        foreach ($control as $r => [$etq, $valor]) {
            $ws->setCellValue("P{$r}", $etq);
            if ($r >= 3 && $r <= 6) {
                $ws->setCellValue("Q{$r}", ExcelDate::PHPToExcel(new \DateTimeImmutable($valor)));
                $ws->getStyle("Q{$r}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            } else {
                $ws->setCellValue("Q{$r}", $valor);
            }
        }
        $ws->getStyle('P1:P7')->getFont()->setBold(true);
        $ws->getColumnDimension('P')->setWidth(35);
        $ws->getColumnDimension('Q')->setWidth(26);

        $ws->setShowGridlines(false);
        $ws->freezePane('A6');
        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0)->setHorizontalCentered(true)->setRowsToRepeatAtTopByStartAndEnd(1, 5);
        $ws->setSelectedCell('A1');
    }

    private function hojaAuditoria(Worksheet $ws, array $incidencias, array $p): void
    {
        $ws->setTitle('_AUDITORIA_EFECTIVIDAD');
        $ws->setCellValue('A1', 'INCIDENCIAS DE CALCULO | MR LANA');
        $ws->setCellValue('A2', 'DOMINGO BASE');
        $ws->setCellValue('B2', ExcelDate::PHPToExcel(new \DateTimeImmutable($p['domingo_base'])));
        $ws->setCellValue('A3', 'CORTE');
        $ws->setCellValue('B3', ExcelDate::PHPToExcel(new \DateTimeImmutable($p['fecha_corte'])));
        $ws->getStyle('B2:B3')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $ws->getStyle('B2:B3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        foreach (['A5' => 'ID', 'B5' => 'DETALLE / MOTIVO DE EXCLUSION', 'C5' => 'CODIGO', 'D5' => 'CONTRATO', 'E5' => 'FECHA RECONOCIMIENTO', 'F5' => 'IMPORTE', 'G5' => 'TRATAMIENTO'] as $c => $t) {
            $ws->setCellValue($c, $t);
        }
        $r = 6;
        foreach ($incidencias as $i) {
            $ws->setCellValueExplicit("A{$r}", (string) $i['id'], DataType::TYPE_STRING);
            $ws->setCellValueExplicit("B{$r}", (string) $i['descripcion'], DataType::TYPE_STRING);
            $ws->setCellValue("C{$r}", (string) $i['codigo']);
            $ws->setCellValueExplicit("D{$r}", (string) ($i['contrato'] ?? ''), DataType::TYPE_STRING);
            if (! empty($i['fecha'])) {
                $ws->setCellValue("E{$r}", ExcelDate::PHPToExcel(new \DateTimeImmutable($i['fecha'])));
            }
            if (isset($i['importe'])) {
                $ws->setCellValue("F{$r}", (float) $i['importe']);
            }
            $ws->setCellValue("G{$r}", EfectividadV4Incidents::tratamiento($i['codigo']));
            $r++;
        }
        if ($r > 6) {
            $ws->getStyle('E6:E'.($r - 1))->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            $ws->getStyle('F6:F'.($r - 1))->getNumberFormat()->setFormatCode(self::MONEDA);
        } else {
            $ws->setCellValue('A6', 'SIN INCIDENCIAS');
        }
        foreach (['A' => 38, 'B' => 90, 'C' => 14, 'D' => 18, 'E' => 20, 'F' => 14, 'G' => 34] as $c => $w) {
            $ws->getColumnDimension($c)->setWidth($w);
        }
        $ws->getStyle('A1:G5')->getFont()->setBold(true);
        $ws->freezePane('A6');
    }

    private function hojaFuentes(Worksheet $ws, array $fuentes, array $p, string $engine): void
    {
        $ws->setTitle('FUENTES');
        $ws->setCellValue('A1', 'FUENTES UTILIZADAS | MR LANA');
        $ws->getStyle('A1')->getFont()->setBold(true);
        $ws->fromArray([['Motor', $engine], ['Modo', $p['modo']], ['Domingo base', $this->dmy($p['domingo_base'])], ['Fecha de corte', $this->dmy($p['fecha_corte'])]], null, 'A3');
        $ws->fromArray([['Fotografía', 'Fecha', 'Contratos leídos']], null, 'A8');
        $r = 9;
        foreach ($fuentes['fotos'] ?? [] as $f) {
            $ws->fromArray([[$f['nombre'], $this->dmy($f['fecha']), (int) $f['filas']]], null, "A{$r}");
            $r++;
        }
        $r++;
        $ws->fromArray([
            ['Ingresos del corte (movimientos)', (int) ($fuentes['ingresos_corte'] ?? 0)],
            ['Movimientos del historial evaluados', (int) ($fuentes['ingresos_historial'] ?? 0)],
            ['Fechas con cargas de Ingresos solapadas (se usó la más reciente)', count($fuentes['fechas_con_cargas_solapadas'] ?? [])],
        ], null, "A{$r}");
        $ws->getStyle('A8:C8')->getFont()->setBold(true);
        $ws->getColumnDimension('A')->setWidth(60);
        $ws->getColumnDimension('B')->setWidth(16);
        $ws->getColumnDimension('C')->setWidth(18);
    }

    private function banda(Worksheet $ws, string $rango, string $texto, string $bg, string $fg, int $size, bool $bold, bool $italic = false): void
    {
        $ws->mergeCells($rango);
        $ws->setCellValue(explode(':', $rango)[0], $texto);
        $s = $ws->getStyle($rango);
        $s->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bg);
        $s->getFont()->setSize($size)->setBold($bold)->setItalic($italic)->getColor()->setRGB($fg);
        $s->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    }

    private function celda(Worksheet $ws, string $rango, string $texto, string $bg, string $fg, string $h, ?string $ancla = null): void
    {
        $ws->setCellValue($ancla ?? $rango, $texto);
        $s = $ws->getStyle($rango);
        $s->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bg);
        $s->getFont()->setBold(true)->setSize(9)->getColor()->setRGB($fg);
        $s->getAlignment()->setHorizontal($h)->setVertical(Alignment::VERTICAL_CENTER);
    }

    private function col(int $n): string
    {
        return Coordinate::stringFromColumnIndex($n);
    }

    private function dmy(string $ymd): string
    {
        return date('d/m/Y', strtotime($ymd));
    }
}
