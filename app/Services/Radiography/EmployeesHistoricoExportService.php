<?php

namespace App\Services\Radiography;

use App\Models\Branch;
use App\Models\EmployeePeriodManualExpense;
use App\Models\Period;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * "Descargar Excel de colaboradores" (frente 6, auditoria 07-sep-2026) - TODOS
 * los colaboradores del periodo en una fila cada uno, ignorando explicitamente
 * el filtro individual de employee (si la pantalla tiene a un colaborador
 * seleccionado, este export igual trae a todos), respetando el resto de
 * filtros (sucursal) cuando se indiquen.
 *
 * REUTILIZA - nunca reinventa - las mismas fuentes/formulas que la vista Web de
 * un colaborador individual (RadiographySnapshotBuilder::applyEmployeeScope() /
 * summaryFromRow()):
 *   - buildAllEmployeeGestorRows() = MISMO buildEmployeesGestores() que arma la
 *     fila de cada colaborador (recuperacion/colocacion/cartera/vencida/nomina).
 *   - OPEX automatico/manual = MISMA formula de buildEmployeeExpenseDetail(),
 *     pero agregada en bloque (una sola consulta para TODOS los colaboradores)
 *     en vez de N consultas - evita N+1 sin cambiar el resultado.
 *   - EBITDA = MISMA resta que summaryFromRow(): ingreso_base - (gastos + neto).
 *   - Estatus activo/baja = MISMA regla que RadiographySnapshotBuilder::
 *     buildOperationalStatus() (ingreso real O gasto operativo > 0), aplicada
 *     en bloque - no cambia la definicion de "activo", solo evita repetir el
 *     calculo persona por persona.
 */
class EmployeesHistoricoExportService
{
    public function __construct(
        private readonly RadiographySnapshotBuilder $snapshotBuilder,
    ) {
    }

    /**
     * @param  array{branch_id?:int|null}  $filters  Filtros a respetar (ademas de periodo).
     *                                                Deliberadamente NO incluye employee_id.
     */
    public function build(Period $period, array $filters = []): Spreadsheet
    {
        $rows = $this->snapshotBuilder->buildAllEmployeeGestorRows($period);
        $dataIds = $this->snapshotBuilder->resolveDataIdsPublic($period);

        if (!empty($filters['branch_id'])) {
            // buildEmployeesGestores() solo trae el NOMBRE de sucursal por fila (nunca
            // un branch_id numerico) - se resuelve el nombre una sola vez y se compara
            // insensible a mayusculas/espacios, igual que el resto del pipeline
            // (RadiographySnapshotBuilder::eqName()).
            $branchName = Branch::query()->find((int) $filters['branch_id'])?->name;
            $branchNameUpper = $branchName ? mb_strtoupper(trim($branchName)) : null;
            if ($branchNameUpper) {
                $rows = array_values(array_filter(
                    $rows,
                    fn ($row) => mb_strtoupper(trim((string) ($row['branch'] ?? ''))) === $branchNameUpper
                ));
            }
        }

        // OPEX automatico - UNA sola consulta para todos los colaboradores.
        $automaticByEmployee = DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->selectRaw('employee_id, SUM(COALESCE(NULLIF(paid_amount,0), amount)) as total')
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id');

        // Gasto manual persistido - UNA sola consulta.
        $manualByEmployee = EmployeePeriodManualExpense::query()
            ->where('period_id', $period->id)
            ->pluck('amount', 'employee_id');

        // Percepciones/Deducciones NOI - UNA sola consulta por tipo.
        $percepcionesByEmployee = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->where('concept_type', 'percepcion')
            ->selectRaw('employee_id, SUM(amount) as total')
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id');

        $deduccionesByEmployee = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->where('concept_type', 'deduccion')
            ->selectRaw('employee_id, SUM(amount) as total')
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Colaboradores');

        $headers = [
            'PERIODO', 'SUCURSAL', 'ID COLABORADOR', 'NOMBRE COLABORADOR', 'ESTADO ACTIVO/BAJA',
            'RECUPERACIÓN', 'COLOCACIÓN', 'VALOR CARTERA', 'CARTERA VENCIDA', 'MORA %',
            'OPEX AUTOMÁTICO', 'GASTO MANUAL', 'OPEX TOTAL',
            'NÓMINA / CAPITAL HUMANO', 'PERCEPCIONES', 'DEDUCCIONES', 'NETO PAGADO',
            'INGRESO BASE EBITDA', 'EBITDA', 'MARGEN EBITDA %',
        ];
        foreach ($headers as $i => $header) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}1", $header);
        }
        $sheet->getStyle('A1:' . Coordinate::stringFromColumnIndex(count($headers)) . '1')
            ->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => RadiographyStyleHelper::BG_PRIMARY_DARK]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
        $sheet->freezePane('A2');

        $r = 2;
        foreach ($rows as $row) {
            $employeeIds = $row['_employee_ids'] ?? [];
            $primaryId   = $employeeIds[0] ?? null;
            if (!$primaryId) {
                continue; // fila sin identidad resuelta - no se puede exportar como colaborador
            }

            $automatic = 0.0;
            $manual    = (float) ($manualByEmployee[$primaryId] ?? 0.0);
            $percep    = 0.0;
            $deduc     = 0.0;
            foreach ($employeeIds as $eid) {
                $automatic += (float) ($automaticByEmployee[$eid] ?? 0.0);
                $percep    += (float) ($percepcionesByEmployee[$eid] ?? 0.0);
                $deduc     += (float) ($deduccionesByEmployee[$eid] ?? 0.0);
            }
            $opexTotal = round($automatic + $manual, 2);

            $pagos      = (float) ($row['pagos'] ?? 0);
            $bonos      = (float) ($row['bonos'] ?? 0);
            $descuentos = (float) ($row['descuentos'] ?? 0);
            $neto       = (float) ($row['neto'] ?? ($pagos + $bonos - $descuentos));
            $ingresoBase = (float) ($row['ingreso_ebitda_base'] ?? 0);
            $cartera    = (float) ($row['cartera'] ?? 0);
            $vencida    = (float) ($row['vencida'] ?? 0);
            $mora       = $cartera > 0 ? round($vencida / $cartera * 100, 2) : 0.0;
            $ebitda     = $ingresoBase - ($opexTotal + $neto);
            $margen     = $ingresoBase > 0 ? round($ebitda / $ingresoBase * 100, 2) : 0.0;

            $hasIncome  = round((float) ($row['recuperacion'] ?? 0), 2) > 0 || round((float) ($row['colocacion'] ?? 0), 2) > 0;
            $hasExpense = round($automatic, 2) > 0;
            $estado     = ($hasIncome || $hasExpense) ? 'ACTIVO' : 'BAJA';

            $values = [
                $period->label,
                $row['branch'] ?? 'Sin asignar',
                $primaryId,
                $row['name'] ?? '-',
                $estado,
                round((float) ($row['recuperacion'] ?? 0), 2),
                round((float) ($row['colocacion'] ?? 0), 2),
                round($cartera, 2),
                round($vencida, 2),
                $mora,
                round($automatic, 2),
                round($manual, 2),
                $opexTotal,
                round($neto, 2),
                round($percep, 2),
                round($deduc, 2),
                round($percep - $deduc, 2),
                round($ingresoBase, 2),
                round($ebitda, 2),
                $margen,
            ];

            foreach ($values as $i => $value) {
                $col = Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValue("{$col}{$r}", $value);
            }

            // Formato moneda para las columnas numericas monetarias.
            foreach ([6, 7, 8, 9, 11, 12, 13, 14, 15, 16, 17, 18, 19] as $colIdx) {
                $col = Coordinate::stringFromColumnIndex($colIdx);
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(RadiographyStyleHelper::CURRENCY);
            }
            $sheet->getStyle('J' . $r)->getNumberFormat()->setFormatCode('0.00"%"');
            $sheet->getStyle('T' . $r)->getNumberFormat()->setFormatCode('0.00"%"');

            $estadoColor = $estado === 'ACTIVO' ? RadiographyStyleHelper::BG_POSITIVE : RadiographyStyleHelper::BG_ALERT_RED;
            $sheet->getStyle('E' . $r)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $estadoColor]],
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $r++;
        }

        foreach (range('A', Coordinate::stringFromColumnIndex(count($headers))) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return $spreadsheet;
    }
}
