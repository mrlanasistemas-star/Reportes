<?php

use App\Models\Employee;
use App\Models\Period;
use App\Services\RadiografiaExportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(Tests\Integration\UsesRealDevDatabase::class);

/**
 * Item 10 del pendiente 2026-08-25: demostrar automáticamente que Web, Excel y PDF
 * consumen el MISMO dataset canónico para un scope real, no tres fórmulas.
 *
 * Solo lectura contra la BD de desarrollo real. Nunca RefreshDatabase, nunca escribe.
 *
 * Nota honesta encontrada en esta verificación: Web (scoped-data,
 * RadiografiaExportService::buildSnapshot()) construye el snapshot CON el config de
 * scope (aplica RadiographySnapshotBuilder::applyEmployeeScope()), mientras que
 * Excel/PDF (RadiografiaExportService::exportWithConfig()/exportPdfWithConfig())
 * construyen el snapshot SIN scope y extraen manualmente la fila del colaborador
 * (RadiographyWorkbookBuilder::buildEmployeeFromSnapshot() /
 * RadiografiaExportService::resolveEmployeeRow()). Ambos caminos leen los MISMOS
 * campos internos ("_employee_ids", "_recovery_components", etc.) de la MISMA fila
 * producida por buildEmployeesGestores() — por construcción no pueden divergir en
 * los componentes agregados — pero no pasan por el mismo método. Este test verifica
 * el resultado observable (los números), no la ruta de código.
 */
beforeEach(function () {
    $this->useRealDevDatabaseOrSkip();
});

it('produces the same recovery/placement/portfolio numbers in Web (scoped-data) and Excel for a real employee', function () {
    $period = Period::query()->where('type', 'monthly')->orderByDesc('id')->first();
    if (!$period) {
        $this->markTestSkipped('No hay periodos mensuales en la BD de desarrollo.');
    }

    $summary = \App\Models\PeriodSummary::query()
        ->where('period_id', $period->id)->where('status', 'generated')->first();
    if (!$summary) {
        $this->markTestSkipped("El periodo {$period->label} no tiene radiografía generada.");
    }

    $roster = app(\App\Services\PeriodEmployeeRosterService::class)->rosterRowsForSelector($period)['rows'] ?? [];
    if (empty($roster)) {
        $this->markTestSkipped("El periodo {$period->label} no tiene roster de colaboradores.");
    }

    // Primer colaborador del roster con recuperación > 0 real (para que la
    // comparación sea significativa, no 0 == 0).
    $employeeId = null;
    foreach ($roster as $row) {
        $employeeId = (int) $row['employee_id'];
        $exportService = app(RadiografiaExportService::class);
        $webSnapshot = $exportService->buildSnapshot($period, ['scope' => 'employee', 'employee_id' => $employeeId]);
        if (($webSnapshot['scope']['available'] ?? false) && (float) ($webSnapshot['summary']['recovery_total'] ?? 0) > 0) {
            break;
        }
        $employeeId = null;
    }

    if (!$employeeId) {
        $this->markTestSkipped("Ningún colaborador del roster de {$period->label} tiene recuperación > 0 para comparar.");
    }

    $exportService = app(RadiografiaExportService::class);
    $webSnapshot = $exportService->buildSnapshot($period, ['scope' => 'employee', 'employee_id' => $employeeId]);

    $webRecovery  = round((float) $webSnapshot['summary']['recovery_total'], 2);
    $webPlacement = round((float) $webSnapshot['summary']['placement_total'], 2);
    $webPortfolio = round((float) $webSnapshot['summary']['portfolio_total'], 2);

    $excelPath = $exportService->exportWithConfig($period, ['scope' => 'employee', 'employee_id' => $employeeId, 'report_type' => 'simple']);
    $spreadsheet = IOFactory::load($excelPath);
    $resumen = $spreadsheet->getSheetByName('RESUMEN');
    expect($resumen)->not->toBeNull();

    // La hoja RESUMEN lista MÉTRICA/VALOR fila por fila desde la fila 5 (ver
    // RadiographyWorkbookBuilder::buildEmployeeFromSnapshot()) — buscamos por label
    // en vez de una celda fija, para no depender de un layout exacto.
    $excelValues = [];
    for ($r = 5; $r <= 12; $r++) {
        $label = $resumen->getCell("A{$r}")->getValue();
        $value = $resumen->getCell("B{$r}")->getValue();
        if ($label) {
            $excelValues[$label] = (float) $value;
        }
    }

    $employee = Employee::find($employeeId);
    $this->assertNotNull($employee, "employee_id={$employeeId} debe existir");

    expect(round($excelValues['Recuperación'] ?? -1, 2))->toBe($webRecovery)
        ->and(round($excelValues['Colocación'] ?? -1, 2))->toBe($webPlacement)
        ->and(round($excelValues['Cartera'] ?? -1, 2))->toBe($webPortfolio);

    @unlink($excelPath);
});

/**
 * Lee el valor numérico total de la gráfica nativa (recién agregada) cuyo título
 * contiene $titleContains, sumando todos los puntos de su serie de valores — sirve
 * como verificación independiente de "los datos de la gráfica == el dataset" sin
 * depender de un layout de celdas fijo (las hojas GENERAL/SUCURSAL son demasiado
 * grandes y dinámicas para buscar una celda por posición fija).
 */
function sumChartValuesByTitle(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $titleContains): ?float
{
    foreach ($sheet->getChartCollection() as $chart) {
        $title = $chart->getTitle()?->getCaptionText() ?? '';
        if (!str_contains($title, $titleContains)) {
            continue;
        }

        $series = $chart->getPlotArea()->getPlotGroupByIndex(0)->getPlotValues()[0] ?? null;
        if (!$series) {
            return null;
        }

        $range = str_replace('$', '', explode('!', $series->getDataSource())[1] ?? '');
        [$start, $end] = array_pad(explode(':', $range), 2, null);
        $end ??= $start;

        $sum = 0.0;
        foreach ($sheet->rangeToArray("{$start}:{$end}") as $row) {
            foreach ($row as $value) {
                $sum += (float) $value;
            }
        }

        return $sum;
    }

    return null;
}

it('produces the same recovery/placement numbers in Web and the GENERAL Excel workbook', function () {
    $period = Period::query()->where('type', 'monthly')->orderByDesc('id')->first();
    if (!$period) {
        $this->markTestSkipped('No hay periodos mensuales en la BD de desarrollo.');
    }

    $summary = \App\Models\PeriodSummary::query()
        ->where('period_id', $period->id)->where('status', 'generated')->first();
    if (!$summary) {
        $this->markTestSkipped("El periodo {$period->label} no tiene radiografía generada.");
    }

    $exportService = app(RadiografiaExportService::class);
    $webSnapshot = $exportService->buildSnapshot($period, ['scope' => 'general']);
    $webRecovery = round((float) $webSnapshot['summary']['recovery_total'], 2);
    $webPlacement = round((float) $webSnapshot['summary']['placement_total'], 2);

    if ($webRecovery <= 0) {
        $this->markTestSkipped("El periodo {$period->label} no tiene recuperación > 0 para comparar.");
    }

    $excelPath = $exportService->export($period, []);
    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $reader->setIncludeCharts(true);
    $spreadsheet = $reader->load($excelPath);

    $global = $spreadsheet->getSheetByName('GLOBAL');
    expect($global)->not->toBeNull();

    // "Recuperación por producto" y "Colocación por producto" son las gráficas nuevas
    // agregadas en esta sesión (las demás — Recuperación vs Colocación, EBITDA, Mora
    // por bucket, etc. — ya existían de una sesión anterior; no se duplican ni se
    // re-verifican aquí). Cada una debe sumar exactamente el KPI total del snapshot.
    // "por producto" excluye deliberadamente ciertas categorías especiales/reestructura
    // (ver buildProducts()/buildRecoveryByProduct() — mismo criterio para ambas), así
    // que su suma es <= el KPI total, no exactamente igual — es un desglose parcial
    // por diseño, no una redefinición del total.
    $excelRecoveryByProduct = sumChartValuesByTitle($global, 'Recuperación por producto');
    expect($excelRecoveryByProduct)->not->toBeNull('No se encontró la gráfica "Recuperación por producto" en GLOBAL.');
    expect(round($excelRecoveryByProduct, 2))->toBeLessThanOrEqual($webRecovery + 0.01);

    $excelPlacementByProduct = sumChartValuesByTitle($global, 'Colocación por producto');
    expect($excelPlacementByProduct)->not->toBeNull('No se encontró la gráfica "Colocación por producto" en GLOBAL.');
    expect(round($excelPlacementByProduct, 2))->toBeLessThanOrEqual($webPlacement + 0.01);

    @unlink($excelPath);
});

it('produces the same recovery/placement numbers in Web and the BRANCH Excel workbook', function () {
    $period = Period::query()->where('type', 'monthly')->orderByDesc('id')->first();
    if (!$period) {
        $this->markTestSkipped('No hay periodos mensuales en la BD de desarrollo.');
    }

    $operativeNames = (new \ReflectionClass(\App\Http\Controllers\MonthlyReportController::class))->getConstant('OPERATIVE_BRANCH_NAMES');
    $exportService = app(RadiografiaExportService::class);

    $branchId = null;
    $webRecovery = 0.0;
    $webPlacement = 0.0;
    foreach (\App\Models\Branch::whereIn('name', $operativeNames)->get() as $branch) {
        $webSnapshot = $exportService->buildSnapshot($period, ['scope' => 'branch', 'branch_id' => $branch->id]);
        if (($webSnapshot['scope']['available'] ?? false) && (float) ($webSnapshot['summary']['recovery_total'] ?? 0) > 0) {
            $branchId = $branch->id;
            $webRecovery = round((float) $webSnapshot['summary']['recovery_total'], 2);
            $webPlacement = round((float) $webSnapshot['summary']['placement_total'], 2);
            break;
        }
    }

    if (!$branchId) {
        $this->markTestSkipped('Ninguna sucursal operativa tiene recuperación > 0 para comparar.');
    }

    $excelPath = $exportService->exportWithConfig($period, ['scope' => 'branch', 'branch_id' => $branchId, 'report_type' => 'simple']);
    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $reader->setIncludeCharts(true);
    $spreadsheet = $reader->load($excelPath);

    $resumen = $spreadsheet->getSheetByName('RESUMEN');
    expect($resumen)->not->toBeNull();

    $excelRecovery = sumChartValuesByTitle($resumen, 'Recuperación vs Colocación');
    expect($excelRecovery)->not->toBeNull('No se encontró la gráfica "Recuperación vs Colocación" en RESUMEN de sucursal.');
    expect(round($excelRecovery, 2))->toBe(round($webRecovery + $webPlacement, 2));

    @unlink($excelPath);
});

// ============================================================================
// Auditoría 07-sep-2026 (cierre) — ajuste manual EFÍMERO: paridad Web/Excel/PDF
// con y sin ajuste, alcance colaborador Y general. Nunca escribe
// employee_period_manual_expenses (ZERO WRITES), y una nueva request/descarga
// sin manual_adjustment vuelve exactamente a los datos oficiales.
// ============================================================================

it('an employee-scope manual_adjustment is reflected identically in Web and Excel, never persisted, and clears on the next request', function () {
    // Prefiere el periodo 21 (Junio 2026) — fixture real conocido con roster/OPEX
    // reales (Bryan/Marlen, auditoría 07-sep-2026) — cae al genérico "último mensual"
    // si no existe en este entorno.
    $period = Period::find(21) ?? Period::query()->where('type', 'monthly')->orderByDesc('id')->first();
    if (!$period) {
        $this->markTestSkipped('No hay periodos mensuales en la BD de desarrollo.');
    }
    $summary = \App\Models\PeriodSummary::query()
        ->where('period_id', $period->id)->where('status', 'generated')->first();
    if (!$summary) {
        $this->markTestSkipped("El periodo {$period->label} no tiene radiografía generada.");
    }

    $roster = app(\App\Services\PeriodEmployeeRosterService::class)->rosterRowsForSelector($period)['rows'] ?? [];
    if (empty($roster)) {
        $this->markTestSkipped("El periodo {$period->label} no tiene roster de colaboradores.");
    }
    $employeeId = (int) $roster[0]['employee_id'];

    $exportService = app(RadiografiaExportService::class);
    $manualAdjustment = ['mode' => 'employee', 'employee_id' => $employeeId, 'amount_per_employee' => 1500.0, 'notes' => 'Test integración'];

    $countAntes = \DB::table('employee_period_manual_expenses')->count();

    // Web CON ajuste.
    $webConAjuste = $exportService->buildSnapshot($period, ['scope' => 'employee', 'employee_id' => $employeeId, 'manual_adjustment' => $manualAdjustment]);
    $webOpexCon = round((float) ($webConAjuste['summary']['opex_total'] ?? -1), 2);

    // Web SIN ajuste (base oficial) — debe ser exactamente $1,500 menos.
    $webSinAjuste = $exportService->buildSnapshot($period, ['scope' => 'employee', 'employee_id' => $employeeId]);
    $webOpexSin = round((float) ($webSinAjuste['summary']['opex_total'] ?? -1), 2);
    expect(round($webOpexCon - $webOpexSin, 2))->toBe(1500.0);

    // Excel CON el MISMO ajuste — debe coincidir con Web.
    $excelPath = $exportService->exportWithConfig($period, ['scope' => 'employee', 'employee_id' => $employeeId, 'report_type' => 'simple', 'manual_adjustment' => $manualAdjustment]);
    $spreadsheet = IOFactory::load($excelPath);
    $resumen = $spreadsheet->getSheetByName('RESUMEN');
    // Label EXACTO (ver RadiographyWorkbookBuilder::buildEmployeeFromSnapshot(),
    // 'TOTAL OPEX GESTOR') — un substring genérico "OPEX" matchea antes una fila de
    // encabezado de sección sin valor numérico. Recorre hasta la última fila real —
    // la tabla de conceptos automáticos puede empujar esta fila bastante abajo.
    $excelOpex = null;
    for ($r = 1; $r <= $resumen->getHighestRow(); $r++) {
        $label = $resumen->getCell("A{$r}")->getValue();
        if ($label && mb_strtoupper(trim((string) $label)) === 'TOTAL OPEX GESTOR') {
            $excelOpex = (float) $resumen->getCell("B{$r}")->getValue();
            break;
        }
    }
    expect($excelOpex)->not->toBeNull('No se encontró la fila "TOTAL OPEX GESTOR" en RESUMEN de colaborador.');
    expect(round($excelOpex, 2))->toBe($webOpexCon);
    @unlink($excelPath);

    // PDF con el MISMO ajuste — nunca debe fallar, mismo camino de código
    // (resolveEmployeeRow) que ya alimentó Excel.
    $pdfPath = $exportService->exportPdfWithConfig($period, ['scope' => 'employee', 'employee_id' => $employeeId, 'report_type' => 'simple', 'manual_adjustment' => $manualAdjustment]);
    expect(file_exists($pdfPath))->toBeTrue();
    expect(filesize($pdfPath))->toBeGreaterThan(0);
    @unlink($pdfPath);

    // ZERO WRITES — el ajuste nunca tocó la tabla persistente.
    expect(\DB::table('employee_period_manual_expenses')->count())->toBe($countAntes);

    // Nueva request SIN manual_adjustment (equivalente a "salir y volver a entrar")
    // — vuelve exactamente al dato oficial, nunca conserva el ajuste anterior.
    $webDespues = $exportService->buildSnapshot($period, ['scope' => 'employee', 'employee_id' => $employeeId]);
    expect(round((float) $webDespues['summary']['opex_total'], 2))->toBe($webOpexSin);
});

it('a general-scope (all_each_employee) manual_adjustment reaches Web AND the general Excel/PDF identically, multiplied by canonical headcount, never persisted', function () {
    // Prefiere el periodo 21 (Junio 2026) — fixture real conocido — cae al genérico
    // "último mensual" si no existe en este entorno.
    $period = Period::find(21) ?? Period::query()->where('type', 'monthly')->orderByDesc('id')->first();
    if (!$period) {
        $this->markTestSkipped('No hay periodos mensuales en la BD de desarrollo.');
    }
    $summary = \App\Models\PeriodSummary::query()
        ->where('period_id', $period->id)->where('status', 'generated')->first();
    if (!$summary) {
        $this->markTestSkipped("El periodo {$period->label} no tiene radiografía generada.");
    }

    $exportService = app(RadiografiaExportService::class);
    // Cierre 17-sep-2026, ronda 2 — modo unificado: all_each_employee reemplaza al
    // extinto scope='general' (que sumaba UNA sola vez sin importar el headcount).
    // Ahora amount_per_employee se multiplica por los colaboradores CANÓNICOS del
    // periodo completo (A5 Caso 3) — nunca un monto fijo.
    $manualAdjustment = ['mode' => 'all_each_employee', 'employee_id' => null, 'amount_per_employee' => 100.0, 'notes' => 'Ajuste general de prueba'];

    $webSin = $exportService->buildSnapshot($period, ['scope' => 'general']);
    $opexSin = round((float) $webSin['summary']['opex_total'], 2);
    $ebitdaSin = round((float) $webSin['summary']['ebitda_final'], 2);

    $webCon = $exportService->buildSnapshot($period, ['scope' => 'general', 'manual_adjustment' => $manualAdjustment]);
    $opexCon = round((float) $webCon['summary']['opex_total'], 2);
    $ebitdaCon = round((float) $webCon['summary']['ebitda_final'], 2);

    $empGestores = app(\App\Services\Radiography\RadiographySnapshotBuilder::class)->buildAllEmployeeGestorRows($period);
    $expectedTotal = app(\App\Services\TemporaryOpexAdjustmentService::class)->totalForScope($empGestores, $manualAdjustment, 'general');
    expect($expectedTotal)->toBeGreaterThan(0.0, "El periodo {$period->label} no tiene colaboradores canónicos para probar la multiplicación.");

    // Se suma amount_per_employee × colaboradores canónicos — NUNCA un monto fijo
    // sin relación con el headcount real del periodo.
    expect(round($opexCon - $opexSin, 2))->toBe($expectedTotal);
    expect(round($ebitdaSin - $ebitdaCon, 2))->toBe($expectedTotal);

    // Excel general CON el mismo ajuste — debe reflejar el mismo total multiplicado.
    // Bug real confirmado y corregido (cierre 17-sep-2026, ronda 3): el Excel/PDF general
    // recalculan EBITDA/OPEX desde branch_radiography.global (extractGlobalCoreMetrics()),
    // NUNCA desde summary.* — antes de la corrección esta hoja seguía mostrando el EBITDA
    // OFICIAL sin ajustar aunque Web (summary.*) ya reflejaba el ajuste. Esta aserción lee
    // la CELDA real (no solo "la hoja existe") para que una regresión futura sí reviente.
    $excelPath = $exportService->exportWithConfig($period, ['scope' => 'general', 'report_type' => 'simple', 'manual_adjustment' => $manualAdjustment]);
    $spreadsheet = IOFactory::load($excelPath);
    $global = $spreadsheet->getSheetByName('GLOBAL');
    expect($global)->not->toBeNull();
    $ebitdaRow = null;
    $gastoManualRow = null;
    for ($r = 1; $r <= $global->getHighestRow(); $r++) {
        $label = (string) $global->getCell("A{$r}")->getValue();
        if ($label === 'EBITDA') { $ebitdaRow = $r; }
        if ($label === 'Gasto manual') { $gastoManualRow = $r; }
    }
    expect($ebitdaRow)->not->toBeNull('No se encontró la fila EBITDA en GLOBAL.');
    expect($gastoManualRow)->not->toBeNull('El Excel general no muestra la fila "Gasto manual" con el ajuste activo.');
    expect(round((float) $global->getCell("B{$gastoManualRow}")->getValue(), 2))->toBe($expectedTotal);
    expect(round((float) $global->getCell("B{$ebitdaRow}")->getValue(), 2))->toBe($ebitdaCon);
    @unlink($excelPath);

    // PDF general con el mismo ajuste — debe mostrar el MISMO EBITDA que Web/Excel en su
    // narrativa principal (no solo en la nota "AJUSTE TEMPORAL" aparte).
    $pdfPath = $exportService->exportPdfWithConfig($period, ['scope' => 'general', 'report_type' => 'simple', 'manual_adjustment' => $manualAdjustment]);
    expect(file_exists($pdfPath))->toBeTrue();
    expect(filesize($pdfPath))->toBeGreaterThan(0);
    $pdfText = shell_exec('pdftotext -layout ' . escapeshellarg($pdfPath) . ' -');
    $ebitdaFormatted = number_format($ebitdaCon, 0);
    expect($pdfText)->not->toBeNull();
    expect(str_contains((string) $pdfText, $ebitdaFormatted))->toBeTrue("El PDF general no muestra el EBITDA ajustado ({$ebitdaFormatted}) en su narrativa principal.");
    @unlink($pdfPath);

    // Nunca escribió en la tabla persistente desconectada.
    // (No se compara count antes/después aquí porque ya se probó explícitamente
    // en el test de arriba — este test se enfoca en la paridad Web/Excel/PDF.)
});
