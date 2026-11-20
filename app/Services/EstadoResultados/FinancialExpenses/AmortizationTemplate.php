<?php

namespace App\Services\EstadoResultados\FinancialExpenses;

use App\Models\EstadoResultados\EstadoResultadosCredit;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Plantilla .xlsx de ejemplo para la tabla de amortización (columnas de
 * AmortizationColumnMap::TEMPLATE_HEADERS). Los importes son ILUSTRATIVOS.
 */
class AmortizationTemplate
{
    /** @return string ruta del archivo temporal generado */
    public function build(?EstadoResultadosCredit $credit = null): string
    {
        $weekly = $credit?->periodicity === EstadoResultadosCredit::SEMANAL;
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet()->setTitle('Amortizacion');
        $ws->setCellValue('A1', 'Tabla de amortización'.($credit ? ' · '.$credit->name : '').' (ejemplo: sustituye los importes por los de tu crédito)');
        $ws->getStyle('A1')->getFont()->setBold(true);
        $ws->fromArray(AmortizationColumnMap::TEMPLATE_HEADERS, null, 'A3');
        $ws->getStyle('A3:G3')->getFont()->setBold(true);
        $ws->getStyle('A3:G3')->getFill()->setFillType('solid')->getStartColor()->setRGB('E2F0D9');

        $balance = $weekly ? 1000000.00 : 2000000.00;
        $payment = $weekly ? 45000.00 : 70000.00;
        $rate = $weekly ? 0.0045 : 0.0150;
        $date = new \DateTime('2026-09-04');
        for ($i = 1; $i <= 8; $i++) {
            $interest = round($balance * $rate, 2);
            $vat = round($interest * 0.16, 2);
            $principal = round($payment - $interest - $vat, 2);
            $balance = round($balance - $principal, 2);
            $ws->fromArray([$i, ExcelDate::PHPToExcel($date), $payment, $principal, $interest, $vat, $balance], null, 'A'.(3 + $i));
            $date->modify($weekly ? '+7 days' : '+1 month');
        }
        $ws->getStyle('B4:B11')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $ws->getStyle('C4:G11')->getNumberFormat()->setFormatCode('#,##0.00');
        $ws->setCellValue('A13', 'Reglas: una fila por pago. Obligatorias: Fecha de pago e Interés. Pago total = Capital + Interés (+ IVA de interés). Sólo el interés entra al Estado de Resultados (entre EBIT y EBT); el capital no.');
        foreach (range('A', 'G') as $col) {
            $ws->getColumnDimension($col)->setWidth($col === 'A' ? 12 : 18);
        }

        $path = tempnam(sys_get_temp_dir(), 'amort_').'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }
}
