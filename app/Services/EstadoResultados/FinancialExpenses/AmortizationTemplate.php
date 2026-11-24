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
    /**
     * Genera la plantilla de ejemplo (pagos mensuales o semanales según el crédito) y la guarda
     * en un archivo temporal.
     *
     * @param  EstadoResultadosCredit|null  $credit  crédito (para título y periodicidad)
     * @return string ruta del archivo temporal generado
     */
    public function build(?EstadoResultadosCredit $credit = null): string
    {
        $daily = $credit?->periodicity === EstadoResultadosCredit::DIARIA;
        $weekly = $credit?->periodicity === EstadoResultadosCredit::SEMANAL;
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet()->setTitle('Amortizacion');
        $ws->setCellValue('A1', 'Tabla de amortización'.($credit ? ' · '.$credit->name : '').' (ejemplo: sustituye los importes por los de tu crédito)');
        $ws->getStyle('A1')->getFont()->setBold(true);
        $ws->fromArray(AmortizationColumnMap::TEMPLATE_HEADERS, null, 'A3');
        $ws->getStyle('A3:I3')->getFont()->setBold(true);
        $ws->getStyle('A3:I3')->getFill()->setFillType('solid')->getStartColor()->setRGB('E2F0D9');

        // Ilustrativo: diario = capital fijo + COMISIÓN prorrateada (sin interés); semanal/mensual = interés.
        $balance = $daily ? 100000.00 : ($weekly ? 1000000.00 : 2000000.00);
        $payment = $weekly ? 45000.00 : 70000.00;
        $rate = $weekly ? 0.0045 : 0.0150;
        $date = new \DateTime('2026-09-04');
        for ($i = 1; $i <= 8; $i++) {
            if ($daily) {
                [$principal, $interest, $vat, $commission, $commissionVat] = [5000.00, 0.00, 0.00, 350.00, 56.00];
                $payment = $principal + $commission + $commissionVat;
            } else {
                $interest = round($balance * $rate, 2);
                $vat = round($interest * 0.16, 2);
                [$commission, $commissionVat] = [0.00, 0.00];
                $principal = round($payment - $interest - $vat, 2);
            }
            $balance = round($balance - $principal, 2);
            $ws->fromArray([$i, ExcelDate::PHPToExcel($date), $payment, $principal, $interest, $vat, $commission, $commissionVat, $balance], null, 'A'.(3 + $i), true); // true: los 0 se escriben (no quedan vacíos)
            $date->modify($daily ? '+1 weekday' : ($weekly ? '+7 days' : '+1 month'));
        }
        $ws->getStyle('B4:B11')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $ws->getStyle('C4:I11')->getNumberFormat()->setFormatCode('#,##0.00');
        $ws->setCellValue('A13', 'Reglas: una fila por pago. Obligatorias: Fecha de pago, Interés y/o Comisión, y Capital o Pago total. Pago total = Capital + Interés + Comisión (+ IVA de interés + IVA de comisión).');
        $ws->setCellValue('A14', 'Al Estado de Resultados (entre EBIT y EBT) sólo entran el interés y la comisión financiera; el capital y el IVA nunca. Las celdas vacías no se toman como $0 sin aviso: captura 0 cuando sea cero.');
        foreach (range('A', 'I') as $col) {
            $ws->getColumnDimension($col)->setWidth($col === 'A' ? 12 : 18);
        }

        $path = tempnam(sys_get_temp_dir(), 'amort_').'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }
}
