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
 *   - Gasto manual = 100% EFÍMERO (reversión 07-sep-2026, cierre) — nunca lee
 *     employee_period_manual_expenses. Viaja como parámetro `$manualAdjustment`
 *     (`{scope, employee_id, amount, notes}`) — scope='employee' solo suma a
 *     la fila de ESE colaborador; scope='general' NUNCA se reparte entre
 *     filas, se refleja únicamente en la hoja "Resumen" (ver addResumenSheet()).
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
    ) {
    }

    /**
     * @param  array{branch_id?:int|null}  $filters  Filtros a respetar (ademas de periodo).
     *                                                Deliberadamente NO incluye employee_id.
     * @param  array{scope?:string,employee_id?:int|null,amount?:float,notes?:string}  $manualAdjustment
     *         Ajuste manual EFÍMERO de esta descarga (reversión 07-sep-2026, cierre) —
     *         nunca BD. scope='employee' suma solo a la fila de ese colaborador.
     *         scope='general' NUNCA se reparte — solo aparece en la hoja "Resumen".
     */
    public function build(Period $period, array $filters = [], array $manualAdjustment = []): Spreadsheet
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
        $opexByEmployee = [];
        foreach ($expenseRows as $r) {
            $classification = $this->opexClassifier->classify($r->category, $r->concept, OpexClassificationService::SOURCE_LENDUS);
            if (!$classification['eligible_for_attribution']) {
                continue;
            }
            $eid = (int) $r->employee_id;
            $opexByEmployee[$eid] = ($opexByEmployee[$eid] ?? 0.0) + (float) $r->total;
        }

        // Gasto manual — 100% EFÍMERO (reversión 07-sep-2026, cierre) — nunca BD. Solo
        // aplica a UNA fila (scope='employee') o queda fuera de las filas por completo
        // (scope='general' — ver addResumenSheet()).
        $manualEmployeeId = null;
        $manualAmountForRow = 0.0;
        $manualNotesForRow  = '';
        if (($manualAdjustment['scope'] ?? null) === 'employee') {
            $manualEmployeeId   = (int) ($manualAdjustment['employee_id'] ?? 0) ?: null;
            $manualAmountForRow = round(max(0.0, (float) ($manualAdjustment['amount'] ?? 0)), 2);
            $manualNotesForRow  = $manualAmountForRow > 0 ? trim((string) ($manualAdjustment['notes'] ?? '')) : '';
        }

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
            'OPEX AUTOMÁTICO', 'GASTO MANUAL', 'OPEX TOTAL',
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
            }

            // El ajuste manual EFÍMERO solo aplica a la fila cuyo grupo de identidad
            // (_employee_ids) contiene EXACTAMENTE el employee_id del ajuste — nunca
            // "de paso" a otro colaborador que comparta el mismo nombre/branch.
            $manual = ($manualEmployeeId !== null && in_array($manualEmployeeId, $employeeIds, true))
                ? $manualAmountForRow : 0.0;

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

        // Ajuste manual GENERAL (reversión 07-sep-2026, cierre, punto 14) — NUNCA se
        // reparte entre las filas de arriba (cada colaborador conserva su dato
        // oficial individual). Se refleja UNA sola vez en una hoja "Resumen" aparte.
        if (($manualAdjustment['scope'] ?? null) === 'general') {
            $generalAmount = round(max(0.0, (float) ($manualAdjustment['amount'] ?? 0)), 2);
            if ($generalAmount > 0) {
                $this->addResumenSheet($spreadsheet, $totalOpexBase, $totalEbitdaBase, $generalAmount, (string) ($manualAdjustment['notes'] ?? ''));
            }
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
     * Hoja "Resumen" — ajuste manual GENERAL temporal (auditoría 07-sep-2026,
     * cierre, punto 14). Se agrega SOLO cuando hay un ajuste general activo (>0) —
     * nunca modifica ninguna fila de la hoja "Colaboradores", solo informa el
     * efecto agregado de esta descarga puntual.
     */
    private function addResumenSheet(Spreadsheet $spreadsheet, float $opexBase, float $ebitdaBase, float $manualAmount, string $notes): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Resumen');

        $opexProyectado   = round($opexBase + $manualAmount, 2);
        $ebitdaProyectado = round($ebitdaBase - $manualAmount, 2);

        $rows = [
            ['AJUSTE MANUAL GENERAL TEMPORAL', $manualAmount],
            ['Notas', $notes ?: '-'],
            ['', ''],
            ['OPEX general base', $opexBase],
            ['Ajuste manual', $manualAmount],
            ['OPEX general proyectado', $opexProyectado],
            ['', ''],
            ['EBITDA base', $ebitdaBase],
            ['EBITDA proyectado', $ebitdaProyectado],
        ];

        $sheet->setCellValue('A1', 'CONCEPTO');
        $sheet->setCellValue('B1', 'VALOR');
        $sheet->getStyle('A1:B1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => RadiographyStyleHelper::BG_PRIMARY_DARK]],
        ]);

        $r = 2;
        $currencyLabels = ['AJUSTE MANUAL GENERAL TEMPORAL', 'OPEX general base', 'Ajuste manual', 'OPEX general proyectado', 'EBITDA base', 'EBITDA proyectado'];
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

        // Nota explícita: este ajuste solo afecta esta descarga puntual — nunca se
        // guardó en ninguna tabla, y una nueva descarga sin manual_amount vuelve a
        // los valores base de arriba.
        $sheet->setCellValue('A' . ($r + 1), 'Este ajuste es TEMPORAL — solo afecta esta descarga. No se guardó en la base de datos.');
        $sheet->mergeCells('A' . ($r + 1) . ':B' . ($r + 1));
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
        // de la columna AB — así al abrir la hoja lo primero que se ve son las
        // gráficas (A1 en adelante), no una tabla de números.
        $dataStartCol = 28; // AB
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

        // Gráficas grandes, apiladas verticalmente empezando en A1 — lo primero
        // que se ve al abrir la hoja. Tamaño generoso (columna A a S, 22 filas)
        // para una lectura clara tipo dashboard moderno.
        $topRow = 1;
        foreach ($metrics as $col => [$title, $color]) {
            RadiographyStyleHelper::addBarChart(
                $chartSheet,
                "{$title} ({$rowCount})",
                $categoryRange,
                "\${$col}\$2:\${$col}\${$lastRow}",
                $rowCount,
                "A{$topRow}",
                'S' . ($topRow + 21),
                $color,
            );
            $topRow += 23;
        }

        foreach ([$colName, $colEbitda, $colOpex, $colCartera, $colVencida, $colRecup, $colColoc] as $col) {
            $chartSheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
}
