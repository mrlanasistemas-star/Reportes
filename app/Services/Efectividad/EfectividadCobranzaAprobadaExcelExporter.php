<?php

namespace App\Services\Efectividad;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Fase 2.8 — exportador fiel al layout REAL de la hoja EFECTIVIDAD del VBA
 * aprobado (columnas A-M, filas 1-5 de encabezado, semáforo en %Cobrado
 * Total) — ver docs/efectividad-cobranza-aprobada.md sección 12-13.
 *
 * Prioriza fidelidad al formato aprobado, no un rediseño estético (regla
 * explícita del encargo). No usa macros: XLSX simple, formato/color vía
 * PhpSpreadsheet, ningún VBA incluido.
 */
class EfectividadCobranzaAprobadaExcelExporter
{
    /** @param array<int, array<string,mixed>> $filas salida de EfectividadCobranzaAprobadaCalculator::calculate() */
    public function export(array $filas, string $periodoLabel, string $outputPath): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('EFECTIVIDAD');

        $this->escribirEncabezado($sheet, $periodoLabel);
        $this->escribirFilas($sheet, $filas);

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($outputPath);

        return $outputPath;
    }

    private function escribirEncabezado(Worksheet $sheet, string $periodoLabel): void
    {
        $sheet->setCellValue('A1', 'EFECTIVIDAD DE COBRANZA — MR LANA');
        $sheet->mergeCells('A1:M1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', 'Periodo: ' . $periodoLabel);
        $sheet->mergeCells('A2:M2');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A3', 'Pagos anticipados reconocidos en Fecha Cuota | Moratorios y movimientos administrativos excluidos');
        $sheet->mergeCells('A3:M3');
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9);
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('B4:D4');
        $sheet->setCellValue('B4', 'SANO');
        $sheet->mergeCells('E4:G4');
        $sheet->setCellValue('E4', 'VIGENTES CON ATRASO');
        $sheet->mergeCells('H4:J4');
        $sheet->setCellValue('H4', 'VENCIDOS CON ATRASO');
        $sheet->mergeCells('K4:L4');
        $sheet->setCellValue('K4', 'COBRANZA TOTAL');
        $sheet->setCellValue('M4', 'MORA');
        $sheet->getStyle('A4:M4')->getFont()->setBold(true);
        $sheet->getStyle('A4:M4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A5', 'Promotor');
        foreach (['B5' => 'Por cobrar', 'C5' => 'Cobrado', 'D5' => '% Cobrado', 'E5' => 'Por cobrar', 'F5' => 'Cobrado', 'G5' => '% Cobrado', 'H5' => 'Por cobrar', 'I5' => 'Cobrado', 'J5' => '% Cobrado', 'K5' => 'Cobrado', 'L5' => '% Cobrado'] as $cell => $label) {
            $sheet->setCellValue($cell, $label);
        }
        $sheet->getStyle('A5:M5')->getFont()->setBold(true);
    }

    private function escribirFilas(Worksheet $sheet, array $filas): void
    {
        $row = 6;
        $oficinaActual = null;

        foreach ($filas as $fila) {
            $esTotal = $fila['es_total'] ?? false;
            $oficina = $fila['oficina'] ?? null;

            if (!$esTotal && $oficina !== null && $oficina !== $oficinaActual) {
                $sheet->setCellValue("A{$row}", mb_strtoupper($oficina));
                $sheet->mergeCells("A{$row}:M{$row}");
                $sheet->getStyle("A{$row}")->getFont()->setBold(true);
                $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9D9D9');
                $row++;
                $oficinaActual = $oficina;
            }

            $sheet->setCellValue("A{$row}", $fila['promotor']);
            $sheet->setCellValue("B{$row}", $fila['sano_pc']);
            $sheet->setCellValue("C{$row}", $fila['sano_cob']);
            $sheet->setCellValue("D{$row}", $fila['sano_pct']);
            $sheet->setCellValue("E{$row}", $fila['atraso_pc']);
            $sheet->setCellValue("F{$row}", $fila['atraso_cob']);
            $sheet->setCellValue("G{$row}", $fila['atraso_pct']);
            $sheet->setCellValue("H{$row}", $fila['vencido_pc']);
            $sheet->setCellValue("I{$row}", $fila['vencido_cob']);
            $sheet->setCellValue("J{$row}", $fila['vencido_pct']);
            $sheet->setCellValue("K{$row}", $fila['cobranza_total']);
            $sheet->setCellValue("L{$row}", $fila['pct_total']);
            $sheet->setCellValue("M{$row}", $fila['mora']);

            foreach (['B', 'C', 'E', 'F', 'H', 'I', 'K', 'M'] as $col) {
                $sheet->getStyle("{$col}{$row}")->getNumberFormat()->setFormatCode('$#,##0.00');
            }
            foreach (['D', 'G', 'J', 'L'] as $col) {
                $sheet->getStyle("{$col}{$row}")->getNumberFormat()->setFormatCode('0.0%');
            }

            $this->aplicarSemaforo($sheet, "L{$row}", (float) $fila['pct_total']);

            if ($esTotal) {
                $sheet->getStyle("A{$row}:M{$row}")->getFont()->setBold(true);
            }

            $row++;
        }
    }

    /** Fase 2.8 — mismos umbrales del VBA real (AplicarSemaforoEfectividad): >=95% verde, >=90% amarillo, <90% rojo. */
    private function aplicarSemaforo(Worksheet $sheet, string $cell, float $pct): void
    {
        $normalizado = $pct > 1.5 ? $pct / 100 : $pct;

        $color = match (true) {
            $normalizado >= 0.95 => 'C6EFCE',
            $normalizado >= 0.90 => 'FFEB9C',
            default => 'FFC7CE',
        };
        $textColor = match (true) {
            $normalizado >= 0.95 => '006100',
            $normalizado >= 0.90 => '9C6500',
            default => '9C0006',
        };

        $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
        $sheet->getStyle($cell)->getFont()->getColor()->setRGB($textColor);
    }
}
