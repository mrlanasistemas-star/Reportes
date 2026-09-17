<?php

namespace App\Services\Radiography;

use App\Models\Branch;
use App\Models\Period;
use App\Services\OpexClassificationService;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * "Descargar Excel de colaboradores" (frente 6, auditoria 07-sep-2026, cerrado
 * 07-sep-2026) - TODOS los colaboradores del periodo en una fila cada uno,
 * ignorando explicitamente el filtro individual de employee (si la pantalla
 * tiene a un colaborador seleccionado, este export igual trae a todos),
 * respetando el resto de filtros (sucursal) cuando se indiquen. Verificado
 * contra Preview.vue/scoped-data: producto/mora/categoria son filtros 100%
 * cliente sobre tablas ya cargadas (nunca viajan al backend) - branch_id es el
 * UNICO filtro real ademas de employee_id, y ya se respeta aqui.
 *
 * REUTILIZA - nunca reinventa - las mismas fuentes/formulas que la vista Web de
 * un colaborador individual (RadiographySnapshotBuilder):
 *   - buildAllEmployeeGestorRows() = MISMO buildEmployeesGestores() que arma la
 *     fila de cada colaborador (recuperacion/colocacion/cartera/vencida/nomina).
 *   - OPEX = MISMA clasificacion canonica (OpexClassificationService) que usa
 *     buildEmployeeExpenseDetail() - aplicada en bloque (una sola consulta con
 *     todos los conceptos/categorias del periodo, clasificados en PHP) en vez
 *     de N consultas - evita N+1 sin cambiar el resultado ni la regla.
 *     "OPEX AUTOMÁTICO" = eligible_for_attribution (OPEX puro + Nómina-empleado
 *     combinados — finiquito/médicos/moto), MISMA semántica de automatic_total
 *     en buildEmployeeExpenseDetail() (auditoría 07-sep-2026, cierre, punto 2).
 *   - EBITDA/margen/OPEX total = MISMO computeEmployeeFinancialMetrics() que
 *     usa RadiographySnapshotBuilder::summaryFromRow() - nunca una segunda
 *     formula.
 *   - Estado activo/baja = MISMA condicion que buildOperationalStatus()
 *     (ingreso real O gasto OPEX automatico > 0 - el gasto manual NUNCA activa
 *     por si solo), sobre los MISMOS totales ya calculados en bloque.
 *   - Gasto manual = 100% EFÍMERO (cierre 17-sep-2026, ronda 2 —
 *     TemporaryOpexAdjustmentService es la fuente ÚNICA) — nunca lee
 *     employee_period_manual_expenses. Viaja como parámetro `$manualAdjustment`
 *     (`{mode, employee_id, branch_id, amount_per_employee, notes}`):
 *       mode='employee' suma solo a la fila de ESE colaborador.
 *       mode='branch_each_employee' suma amount_per_employee a CADA fila
 *         canónica de esa sucursal (multiplicado por el número de
 *         colaboradores de esa sucursal — a propósito).
 *       mode='all_each_employee' suma amount_per_employee a CADA fila del
 *         periodo completo (multiplicado por el total de colaboradores).
 *   - Desglose de gastos por concepto (07-sep-2026, ronda 3) — hoja "Detalle de
 *     Gastos" aparte: una fila por (colaborador, concepto) de TODO lo que
 *     compone su OPEX AUTOMÁTICO — la suma de sus filas ahí reconcilia EXACTO
 *     contra la columna OPEX AUTOMÁTICO de "Colaboradores" (mismos
 *     $opexByEmployee/clasificación, nunca un segundo cálculo). Nunca incluye
 *     conceptos filtrados (Nómina/IMSS/Deducciones/Fondeo/Excedentes/Pólizas).
 */
class EmployeesHistoricoExportService
{
    /** Encabezados monetarios — reciben formato de moneda. */
    private const CURRENCY_HEADERS = [
        'RECUPERACIÓN', 'COLOCACIÓN', 'VALOR CARTERA', 'CARTERA VENCIDA',
        'OPEX AUTOMÁTICO', 'GASTO MANUAL', 'OPEX TOTAL', 'NÓMINA / CAPITAL HUMANO',
        'PERCEPCIONES', 'DEDUCCIONES', 'NETO PAGADO', 'UTILIDAD BRUTA', 'EBITDA',
    ];

    /** Encabezados porcentuales. */
    private const PERCENT_HEADERS = ['MORA %', 'MARGEN EBITDA %'];

    public function __construct(
        private readonly RadiographySnapshotBuilder $snapshotBuilder,
        private readonly OpexClassificationService $opexClassifier,
        private readonly \App\Services\TemporaryOpexAdjustmentService $temporaryAdjustment,
    ) {
    }

    /**
     * @param  array{branch_id?:int|null}  $filters  Filtros a respetar (ademas de periodo).
     *                                                Deliberadamente NO incluye employee_id.
     * @param  array{mode?:string,employee_id?:int|null,branch_id?:int|null,amount_per_employee?:float,notes?:string}  $manualAdjustment
     *         Ajuste manual EFÍMERO de esta descarga (cierre 17-sep-2026, ronda 2 —
     *         TemporaryOpexAdjustmentService es la fuente única) — nunca BD.
     *         mode='employee' suma solo a la fila de ese colaborador.
     *         mode='branch_each_employee' suma amount_per_employee a CADA fila
     *         canónica de esa sucursal (multiplicado por el número de colaboradores).
     *         mode='all_each_employee' suma amount_per_employee a CADA fila del
     *         periodo completo.
     */
    public function build(Period $period, array $filters = [], array $manualAdjustment = []): Spreadsheet
    {
        // Filas CANÓNICAS del periodo completo (sin filtrar por sucursal todavía) —
        // fuente ÚNICA para calcular "a quién le toca" el ajuste manual (cierre
        // 17-sep-2026, ronda 2), independiente del filtro de visualización de abajo.
        $allRows = $this->snapshotBuilder->buildAllEmployeeGestorRows($period);
        $manualByEmployeeId = $this->temporaryAdjustment->perEmployeeAmounts($allRows, $manualAdjustment);
        $manualAdjustmentNormalized = $this->temporaryAdjustment->normalize($manualAdjustment);
        $manualNotesForRow = (string) ($manualAdjustmentNormalized['notes'] ?? '');

        $rows = $allRows;
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

        // ── OPEX/Nómina-empleado clasificados — UNA sola consulta para todos los
        // colaboradores, agrupada por employee_id+categoria+concepto, clasificada
        // en PHP vía OpexClassificationService (fuente canónica única — auditoría
        // 07-sep-2026). Nunca suma NOMINA/IMSS/Deducciones/Fondeo/Excedentes/
        // Pólizas, aunque tuvieran employee_id por error de otro flujo.
        $expenseRows = DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->selectRaw("employee_id, COALESCE(category,'') as category, COALESCE(concept,'') as concept, SUM(COALESCE(NULLIF(paid_amount,0), amount)) as total")
            ->groupBy('employee_id', 'category', 'concept')
            ->get();

        // "OPEX AUTOMÁTICO" = eligible_for_attribution (OPEX puro + Nómina-empleado
        // combinados) — MISMA semántica que automatic_total en
        // buildEmployeeExpenseDetail() (auditoría 07-sep-2026, cierre, punto 2). NUNCA
        // suma NOMINA/IMSS/Deducciones/Fondeo/Excedentes/Pólizas (eligible_for_attribution
        // = false), aunque tuvieran employee_id por error de otro flujo.
        // Detalle por (employee_id, concepto) — misma clasificación, se conserva la
        // fila individual (no solo el acumulado) para poblar "Detalle de Gastos".
        $opexByEmployee     = [];
        $detailItemsByEmployee = [];
        foreach ($expenseRows as $r) {
            $classification = $this->opexClassifier->classify($r->category, $r->concept, OpexClassificationService::SOURCE_LENDUS);
            if (!$classification['eligible_for_attribution']) {
                continue;
            }
            $eid = (int) $r->employee_id;
            $amount = (float) $r->total;
            $opexByEmployee[$eid] = ($opexByEmployee[$eid] ?? 0.0) + $amount;
            $detailItemsByEmployee[$eid][] = ['category' => (string) $r->category, 'concept' => (string) $r->concept, 'amount' => round($amount, 2)];
        }

        // Gasto manual — 100% EFÍMERO (cierre 17-sep-2026, ronda 2) — nunca BD.
        // $manualByEmployeeId (calculado arriba, ANTES del filtro de sucursal de
        // visualización) ya resuelve exactamente a quién le toca cuánto, para los
        // 3 modos (employee/branch_each_employee/all_each_employee) — ver
        // TemporaryOpexAdjustmentService::perEmployeeAmounts().

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
            'PERIODO', 'SUCURSAL', 'NOMBRE COLABORADOR', 'ESTADO ACTIVO/BAJA',
            'RECUPERACIÓN', 'COLOCACIÓN', 'VALOR CARTERA', 'CARTERA VENCIDA', 'MORA %',
            'OPEX AUTOMÁTICO', 'GASTO MANUAL', 'OPEX TOTAL', 'NOTA GASTO MANUAL',
            'NÓMINA / CAPITAL HUMANO', 'PERCEPCIONES', 'DEDUCCIONES', 'NETO PAGADO',
            'UTILIDAD BRUTA', 'EBITDA', 'MARGEN EBITDA %',
        ];
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        foreach ($headers as $i => $header) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}1", $header);
        }
        $sheet->getStyle("A1:{$lastCol}1")
            ->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => RadiographyStyleHelper::BG_PRIMARY_DARK]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
        $sheet->freezePane('A2');

        $estadoColIdx = array_search('ESTADO ACTIVO/BAJA', $headers, true) + 1;
        $estadoCol    = Coordinate::stringFromColumnIndex($estadoColIdx);

        $r = 2;
        $exportedRowsCount = 0;
        $chartRows = [];
        $detailRows = [];
        $totalOpexBase   = 0.0; // suma de OPEX TOTAL de cada fila (oficial, nunca incluye el ajuste general)
        $totalEbitdaBase = 0.0; // suma de EBITDA de cada fila (oficial)
        foreach ($rows as $row) {
            $employeeIds = $row['_employee_ids'] ?? [];
            $primaryId   = $employeeIds[0] ?? null;
            if (!$primaryId) {
                continue; // fila sin identidad resuelta - no se puede exportar como colaborador
            }

            $opexAuto        = 0.0;
            $percep          = 0.0;
            $deduc           = 0.0;
            foreach ($employeeIds as $eid) {
                $opexAuto += (float) ($opexByEmployee[$eid] ?? 0.0);
                $percep   += (float) ($percepcionesByEmployee[$eid] ?? 0.0);
                $deduc    += (float) ($deduccionesByEmployee[$eid] ?? 0.0);
                // Desglose "Detalle de Gastos" — un registro por (colaborador, concepto).
                // MISMA fuente que compone $opexAuto (nunca un segundo cálculo) — su suma
                // por colaborador reconcilia exacto contra OPEX AUTOMÁTICO de arriba.
                foreach ($detailItemsByEmployee[$eid] ?? [] as $item) {
                    $detailRows[] = [
                        'name'     => $row['name'] ?? '-',
                        'branch'   => $row['branch'] ?? 'Sin asignar',
                        'category' => $item['category'],
                        'concept'  => $item['concept'],
                        'amount'   => $item['amount'],
                    ];
                }
            }

            // El ajuste manual EFÍMERO se resuelve por identidad canónica
            // ($manualByEmployeeId, calculado arriba por TemporaryOpexAdjustmentService
            // ANTES del filtro de sucursal de visualización) — nunca "de paso" a otro
            // colaborador que comparta nombre/sucursal. $primaryId es EXACTAMENTE la
            // misma clave (_employee_ids[0]) que perEmployeeAmounts() usa para armar
            // ese mapa — misma fuente, cero riesgo de IDs que no coincidan entre sí.
            $manual = (float) ($manualByEmployeeId[$primaryId] ?? 0.0);

            // Fuente ÚNICA de EBITDA/margen/OPEX total — computeEmployeeFinancialMetrics(),
            // la MISMA función que usa RadiographySnapshotBuilder::summaryFromRow() para
            // Web/Excel individual/PDF. 'total' replica exactamente lo que
            // buildEmployeeExpenseDetail() expondría: OPEX AUTOMÁTICO (ya combinado) + manual.
            $expenseDetailLike = ['total' => round($opexAuto + $manual, 2)];
            $metrics = $this->snapshotBuilder->computeEmployeeFinancialMetrics($row, $expenseDetailLike);

            // Estado activo/baja — MISMA condición que buildOperationalStatus(): ingreso
            // real (recuperación/colocación) O gasto OPEX automático > 0. El gasto manual
            // y el Nómina-empleado (finiquito/médico — normalmente señal de BAJA, no de
            // actividad) nunca activan por sí solos.
            $hasIncome  = round((float) ($row['recuperacion'] ?? 0), 2) > 0 || round((float) ($row['colocacion'] ?? 0), 2) > 0;
            $hasExpense = round($opexAuto, 2) > 0;
            $estado     = ($hasIncome || $hasExpense) ? 'ACTIVO' : 'BAJA';

            $values = [
                $period->label,
                $row['branch'] ?? 'Sin asignar',
                $row['name'] ?? '-',
                $estado,
                round((float) ($row['recuperacion'] ?? 0), 2),
                round((float) ($row['colocacion'] ?? 0), 2),
                round($metrics['cartera'], 2),
                round($metrics['vencida'], 2),
                $metrics['mora_index'],
                round($opexAuto, 2),
                round($manual, 2),
                round($metrics['opex_total'], 2),
                $manual > 0 ? ($manualNotesForRow ?: '-') : '',
                round($metrics['neto'], 2),
                round($percep, 2),
                round($deduc, 2),
                round($percep - $deduc, 2),
                round($metrics['ingreso_base'], 2),
                round($metrics['ebitda'], 2),
                $metrics['margen_ebitda'],
            ];

            foreach ($values as $i => $value) {
                $col = Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValue("{$col}{$r}", $value);
            }

            foreach ($headers as $i => $header) {
                $col = Coordinate::stringFromColumnIndex($i + 1);
                if (in_array($header, self::CURRENCY_HEADERS, true)) {
                    $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(RadiographyStyleHelper::CURRENCY);
                } elseif (in_array($header, self::PERCENT_HEADERS, true)) {
                    $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode('0.00"%"');
                }
            }

            $estadoColor = $estado === 'ACTIVO' ? RadiographyStyleHelper::BG_POSITIVE : RadiographyStyleHelper::BG_ALERT_RED;
            $sheet->getStyle("{$estadoCol}{$r}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $estadoColor]],
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $chartRows[] = [
                'name'          => $row['name'] ?? '-',
                'ebitda'        => round($metrics['ebitda'], 2),
                'opex_total'    => round($metrics['opex_total'], 2),
                'cartera'       => round($metrics['cartera'], 2),
                'vencida'       => round($metrics['vencida'], 2),
                'recuperacion'  => round((float) ($row['recuperacion'] ?? 0), 2),
                'colocacion'    => round((float) ($row['colocacion'] ?? 0), 2),
            ];

            $totalOpexBase   += round($metrics['opex_total'], 2);
            $totalEbitdaBase += round($metrics['ebitda'], 2);

            $r++;
            $exportedRowsCount++;
        }

        $lastDataRow = $r - 1;

        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Filtros nativos de Excel sobre TODO el encabezado — permite filtrar por
        // sucursal, estado activo/baja o cualquier otra columna sin reinventar UI.
        if ($lastDataRow >= 2) {
            $sheet->setAutoFilter("A1:{$lastCol}{$lastDataRow}");
        }

        if (!empty($chartRows)) {
            $this->addChartsSheet($spreadsheet, $chartRows);
        }

        // Desglose "Detalle de Gastos" (ronda 3, 07-sep-2026) — una fila por
        // (colaborador, concepto) de todo lo que compone su OPEX AUTOMÁTICO.
        if (!empty($detailRows)) {
            $this->addDetailSheet($spreadsheet, $detailRows);
        }

        // Hoja "Resumen" del ajuste manual EFÍMERO (cierre 17-sep-2026, ronda 2) —
        // se muestra para CUALQUIER modo activo (employee/branch_each_employee/
        // all_each_employee): todos aplican por fila (ya reflejado arriba en cada
        // colaborador afectado) — esta hoja solo documenta el total agregado
        // resultante para transparencia, nunca cambia ningún cálculo.
        if ($manualAdjustmentNormalized !== null) {
            $affectedCount = count(array_filter($manualByEmployeeId, fn ($v) => $v > 0));
            $this->addResumenAllSheet(
                $spreadsheet, $totalOpexBase, $totalEbitdaBase,
                $manualAdjustmentNormalized['amount_per_employee'], $affectedCount, $manualNotesForRow
            );
        }

        // Worksheet::getStyle() tiene un efecto secundario documentado en
        // PhpSpreadsheet: marca esa hoja como la ACTIVA del libro (lo necesita para
        // aplicar estilos sobre un rango). addChartsSheet()/addResumenSheet() llaman
        // getStyle() sobre sus propias hojas al final — sin este reset, el archivo se
        // abría mostrando "Gráficas"/"Resumen" en vez de "Colaboradores". Se fuerza
        // aquí, al final, para que la hoja visible al abrir SIEMPRE sea "Colaboradores".
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * Hoja "Resumen" del ajuste manual EFÍMERO (cierre 17-sep-2026, ronda 2) —
     * se aplica a CADA fila de "Colaboradores" alcanzada por el modo activo
     * (ya reflejado ahí arriba, ver $manualByEmployeeId en build()) — sea
     * mode=employee (1 colaborador), branch_each_employee (los de esa
     * sucursal) o all_each_employee (todos los del periodo). $opexConAjuste/
     * $ebitdaConAjuste YA incluyen el ajuste (vienen de sumar las filas ya
     * ajustadas) — aquí solo se resta/suma el total conocido (monto ×
     * colaboradores afectados) para mostrar el comparativo base vs
     * proyectado, sin recalcular nada.
     */
    private function addResumenAllSheet(Spreadsheet $spreadsheet, float $opexConAjuste, float $ebitdaConAjuste, float $amountPerEmployee, int $employeeCount, string $notes): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Resumen');

        $totalAdjustment = round($amountPerEmployee * $employeeCount, 2);
        $opexBase        = round($opexConAjuste - $totalAdjustment, 2);
        $ebitdaBase      = round($ebitdaConAjuste + $totalAdjustment, 2);

        $rows = [
            ['AJUSTE MANUAL POR COLABORADOR (c/u)', $amountPerEmployee],
            ['Colaboradores afectados', $employeeCount],
            ['Ajuste manual TOTAL (monto × colaboradores)', $totalAdjustment],
            ['Notas', $notes ?: '-'],
            ['', ''],
            ['OPEX general base (sin ajuste)', $opexBase],
            ['OPEX general proyectado (con ajuste)', $opexConAjuste],
            ['', ''],
            ['EBITDA base (sin ajuste)', $ebitdaBase],
            ['EBITDA proyectado (con ajuste)', $ebitdaConAjuste],
        ];

        $sheet->setCellValue('A1', 'CONCEPTO');
        $sheet->setCellValue('B1', 'VALOR');
        $sheet->getStyle('A1:B1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => RadiographyStyleHelper::BG_PRIMARY_DARK]],
        ]);

        $r = 2;
        $currencyLabels = [
            'AJUSTE MANUAL POR COLABORADOR (c/u)', 'Ajuste manual TOTAL (monto × colaboradores)',
            'OPEX general base (sin ajuste)', 'OPEX general proyectado (con ajuste)',
            'EBITDA base (sin ajuste)', 'EBITDA proyectado (con ajuste)',
        ];
        foreach ($rows as [$label, $value]) {
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $value);
            if (in_array($label, $currencyLabels, true)) {
                $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(RadiographyStyleHelper::CURRENCY);
            }
            $r++;
        }

        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);

        $sheet->setCellValue('A' . ($r + 1), 'Este ajuste es TEMPORAL — se aplicó a CADA colaborador de esta descarga. Nunca se guardó en la base de datos.');
        $sheet->mergeCells('A' . ($r + 1) . ':B' . ($r + 1));
    }

    /**
     * Hoja "Detalle de Gastos" (ronda 3, 07-sep-2026) — una fila por (colaborador,
     * concepto) de TODO lo que compone su OPEX AUTOMÁTICO en la hoja "Colaboradores"
     * — MISMA fuente ($detailItemsByEmployee en build(), poblada con la MISMA
     * clasificación que $opexByEmployee, nunca un segundo cálculo). La suma de las
     * filas de un colaborador aquí reconcilia EXACTO contra su columna OPEX
     * AUTOMÁTICO — nunca incluye conceptos filtrados (Nómina/IMSS/Deducciones/
     * Fondeo/Excedentes/Pólizas, ya excluidos antes de llegar aquí).
     */
    private function addDetailSheet(Spreadsheet $spreadsheet, array $detailRows): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Detalle de Gastos');

        $headers = ['COLABORADOR', 'SUCURSAL', 'CATEGORÍA', 'CONCEPTO', 'MONTO'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $h);
        }
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => RadiographyStyleHelper::BG_PRIMARY_DARK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->freezePane('A2');

        // Orden estable: por colaborador (para leer el desglose de cada quien
        // agrupado), y dentro de cada uno por monto descendente (el gasto más
        // grande primero — igual de legible que ordenar alfabético por concepto).
        usort($detailRows, fn ($a, $b) => ($a['name'] <=> $b['name']) ?: ($b['amount'] <=> $a['amount']));

        $r = 2;
        foreach ($detailRows as $row) {
            $sheet->setCellValue("A{$r}", $row['name']);
            $sheet->setCellValue("B{$r}", $row['branch']);
            $sheet->setCellValue("C{$r}", $row['category']);
            $sheet->setCellValue("D{$r}", $row['concept']);
            $sheet->setCellValue("E{$r}", $row['amount']);
            $sheet->getStyle("E{$r}")->getNumberFormat()->setFormatCode(RadiographyStyleHelper::CURRENCY);
            $r++;
        }
        $lastRow = $r - 1;

        if ($lastRow >= 2) {
            $sheet->setAutoFilter("A1:{$lastCol}{$lastRow}");
        }

        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * Gráficas nativas de Excel (hoja "Gráficas" aparte) — reutiliza
     * RadiographyStyleHelper::addBarChart(), YA existente en el workbook
     * principal (RadiographyWorkbookBuilder) — no se crea un mecanismo de
     * gráficas nuevo. Una barra por colaborador, TODAS las filas exportadas
     * (ninguna omitida), para EBITDA/OPEX/cartera y el resto de métricas clave.
     *
     * addBarChart() qualifica sus rangos y adjunta el chart a la MISMA hoja que
     * recibe como primer argumento — por eso los datos se replican aquí (una
     * mini-tabla local en la propia hoja "Gráficas"), en vez de referenciar la
     * hoja "Colaboradores" desde otra hoja.
     */
    private function addChartsSheet(Spreadsheet $spreadsheet, array $chartRows): void
    {
        $chartSheet = $spreadsheet->createSheet();
        $chartSheet->setTitle('Gráficas');

        // La tabla de apoyo (obligatoria: un chart de Excel SIEMPRE referencia
        // celdas reales, no existe "gráfica sin datos") se coloca lejos, a partir
        // de la columna AI — más grande que antes para dejar espacio a las
        // gráficas en grid 2×3 (ronda 3, 07-sep-2026: tamaño/estilo más moderno) —
        // así al abrir la hoja lo primero que se ve son las gráficas (A1 en
        // adelante), no una tabla de números.
        $dataStartCol = 35; // AI
        $dataHeaders = ['Colaborador', 'EBITDA', 'OPEX TOTAL', 'VALOR CARTERA', 'CARTERA VENCIDA', 'RECUPERACIÓN', 'COLOCACIÓN'];
        foreach ($dataHeaders as $i => $h) {
            $chartSheet->setCellValue(Coordinate::stringFromColumnIndex($dataStartCol + $i) . '1', $h);
        }
        [$colName, $colEbitda, $colOpex, $colCartera, $colVencida, $colRecup, $colColoc] = array_map(
            fn ($i) => Coordinate::stringFromColumnIndex($dataStartCol + $i),
            range(0, 6)
        );

        $rowCount = count($chartRows);
        $r = 2;
        foreach ($chartRows as $cr) {
            $chartSheet->setCellValue("{$colName}{$r}", $cr['name']);
            $chartSheet->setCellValue("{$colEbitda}{$r}", $cr['ebitda']);
            $chartSheet->setCellValue("{$colOpex}{$r}", $cr['opex_total']);
            $chartSheet->setCellValue("{$colCartera}{$r}", $cr['cartera']);
            $chartSheet->setCellValue("{$colVencida}{$r}", $cr['vencida']);
            $chartSheet->setCellValue("{$colRecup}{$r}", $cr['recuperacion']);
            $chartSheet->setCellValue("{$colColoc}{$r}", $cr['colocacion']);
            $r++;
        }
        $lastRow = $r - 1;
        $categoryRange = "\${$colName}\$2:\${$colName}\${$lastRow}";

        // Tabla nativa de Excel (ListObject) — el AutoFilter sobre ella permite
        // filtrar en tiempo real; los charts (plotVisibleOnly=true por defecto en
        // PhpSpreadsheet\Chart\Chart) se actualizan solos al ocultar filas, sin
        // ningún mecanismo adicional.
        $lastDataCol = Coordinate::stringFromColumnIndex($dataStartCol + 6);
        if ($lastRow >= 2) {
            $chartSheet->setAutoFilter("{$colName}1:{$lastDataCol}{$lastRow}");
        }

        $metrics = [
            $colEbitda  => ['EBITDA por colaborador', RadiographyStyleHelper::BG_ACCENT],
            $colOpex    => ['OPEX total por colaborador', RadiographyStyleHelper::BG_ALERT_RED],
            $colCartera => ['Valor de cartera por colaborador', RadiographyStyleHelper::BG_PRIMARY_DARK],
            $colVencida => ['Cartera vencida por colaborador', RadiographyStyleHelper::FG_RED],
            $colRecup   => ['Recuperación por colaborador', RadiographyStyleHelper::BG_POSITIVE],
            $colColoc   => ['Colocación por colaborador', RadiographyStyleHelper::BG_SECTION_HDR],
        ];

        // Gráficas en grid 2×3 (ronda 3, 07-sep-2026 — "mejora las gráficas", tamaño/
        // estilo más grande y moderno): dos columnas de gráficas de 16 columnas de
        // ancho cada una (antes: una sola columna apilada de 18 de ancho, más
        // angosta relativamente y mucho scroll vertical) — lectura tipo dashboard,
        // todo visible con menos desplazamiento. Cada gráfica ocupa 26 filas de alto
        // (antes 22) para verse más grande y legible.
        $leftColStart  = 'A';   // A .. P  (16 cols)
        $leftColEnd    = 'P';
        $rightColStart = 'R';   // R .. AG (16 cols, con 1 col de separación en Q)
        $rightColEnd   = 'AG';
        $chartHeightRows = 26;
        $rowGap = 2;

        $positions = [
            ['col' => $leftColStart,  'end' => $leftColEnd,  'row' => 1],
            ['col' => $rightColStart, 'end' => $rightColEnd, 'row' => 1],
            ['col' => $leftColStart,  'end' => $leftColEnd,  'row' => 1 + $chartHeightRows + $rowGap],
            ['col' => $rightColStart, 'end' => $rightColEnd, 'row' => 1 + $chartHeightRows + $rowGap],
            ['col' => $leftColStart,  'end' => $leftColEnd,  'row' => 1 + 2 * ($chartHeightRows + $rowGap)],
            ['col' => $rightColStart, 'end' => $rightColEnd, 'row' => 1 + 2 * ($chartHeightRows + $rowGap)],
        ];

        $i = 0;
        foreach ($metrics as $col => [$title, $color]) {
            $pos = $positions[$i];
            RadiographyStyleHelper::addBarChart(
                $chartSheet,
                "{$title} ({$rowCount})",
                $categoryRange,
                "\${$col}\$2:\${$col}\${$lastRow}",
                $rowCount,
                "{$pos['col']}{$pos['row']}",
                $pos['end'] . ($pos['row'] + $chartHeightRows - 1),
                $color,
            );
            $i++;
        }

        foreach ([$colName, $colEbitda, $colOpex, $colCartera, $colVencida, $colRecup, $colColoc] as $col) {
            $chartSheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
}
