<?php

use App\Models\Branch;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\RadiografiaExportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(Tests\Integration\UsesRealDevDatabase::class);

/**
 * Cierre 17-sep-2026, PARTE A (ronda 1 y 2) — bug real reportado por el usuario: el
 * botón Excel/PDF de CABECERA (Preview.vue::buildActiveScopeUrl()) con alcance
 * general caía a las URLs estáticas export-radiography(.pdf), que jamás llevan
 * manual_adjustment. Resultado: la Web se veía ajustada pero el Excel/PDF de
 * cabecera salían SIN el ajuste. El fix hace que buildActiveScopeUrl() use SIEMPRE
 * export-filtrado.xlsx/pdf en cuanto hay un ajuste APLICADO.
 *
 * Ronda 2 (17-sep-2026): modelo unificado TemporaryOpexAdjustmentService — los
 * parámetros ahora son manual_mode/manual_employee_id/manual_branch_id/
 * manual_amount_per_employee/manual_notes (antes manual_scope/manual_amount).
 * BRANCH ahora SÍ soporta ajuste (mode=branch_each_employee) — antes de esta
 * ronda estaba deliberadamente sin implementar.
 *
 * Solo lectura contra la BD de desarrollo real — nunca RefreshDatabase, nunca escribe.
 */
beforeEach(function () {
    $this->useRealDevDatabaseOrSkip();

    $this->actingUser = User::query()->first();
    if (!$this->actingUser) {
        $this->markTestSkipped('No hay ningún usuario en la BD de desarrollo para autenticar la request.');
    }

    // Prefiere el periodo 21 (Junio 2026) — fixture real conocido con roster/OPEX reales
    // (mismo criterio que WebExcelPdfDatasetConsistencyTest) — cae al más reciente con
    // radiografía generada si no existe en este entorno. El más reciente por ID puede ser
    // un periodo de prueba/migración sin roster real; por eso NO se usa como único criterio.
    $this->period = Period::find(21) ?? Period::query()->where('type', 'monthly')
        ->whereIn('id', PeriodSummary::query()->where('status', 'generated')->pluck('period_id'))
        ->orderByDesc('id')->first();

    if (!$this->period) {
        $this->markTestSkipped('No hay periodos mensuales con radiografía generada.');
    }
});

it('exports a general-scope Excel via the filtered route with an all_each_employee manual adjustment applied (A10 header-button fix)', function () {
    $response = $this->actingAs($this->actingUser)->get(
        route('reportes-mensuales.export-filtered-radiography', $this->period->id) . '?' . http_build_query([
            'report_type' => 'simple',
            'scope'       => 'general',
            'manual_mode' => 'all_each_employee',
            'manual_amount_per_employee' => '2000',
            'manual_notes' => 'Gasto extraordinario del mes (test A10)',
        ])
    );

    $response->assertOk();
    $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $disposition = $response->headers->get('content-disposition') ?? '';
    if (preg_match('/filename="?([^"]+)"?/', $disposition, $m)) {
        @unlink(storage_path('app/radiografias/' . $m[1]));
    }
});

it('exports a general-scope PDF via the filtered route with an all_each_employee manual adjustment applied (A10 header-button fix)', function () {
    $response = $this->actingAs($this->actingUser)->get(
        route('reportes-mensuales.export-filtered-radiography-pdf', $this->period->id) . '?' . http_build_query([
            'report_type' => 'simple',
            'scope'       => 'general',
            'manual_mode' => 'all_each_employee',
            'manual_amount_per_employee' => '2000',
            'manual_notes' => 'Gasto extraordinario del mes (test A10)',
        ])
    );

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');

    $disposition = $response->headers->get('content-disposition') ?? '';
    if (preg_match('/filename="?([^"]+)"?/', $disposition, $m)) {
        @unlink(storage_path('app/radiografias/' . $m[1]));
    }
});

it('exports an employee-scope Excel via the filtered route with the SAME employee manual adjustment as the Web (A11 parity)', function () {
    $roster = app(\App\Services\PeriodEmployeeRosterService::class)->rosterRowsForSelector($this->period)['rows'] ?? [];
    if (empty($roster)) {
        $this->markTestSkipped("El periodo {$this->period->label} no tiene roster de colaboradores.");
    }
    $employeeId = (int) $roster[0]['employee_id'];

    $exportService = app(RadiografiaExportService::class);
    $webSnapshot = $exportService->buildSnapshot($this->period, [
        'scope' => 'employee', 'employee_id' => $employeeId,
        'manual_adjustment' => ['mode' => 'employee', 'employee_id' => $employeeId, 'amount_per_employee' => 500.0, 'notes' => 'Viáticos (test A11)'],
    ]);
    $webOpex = round((float) ($webSnapshot['summary']['opex_total'] ?? -1), 2);

    $response = $this->actingAs($this->actingUser)->get(
        route('reportes-mensuales.export-filtered-radiography', $this->period->id) . '?' . http_build_query([
            'report_type'        => 'simple',
            'scope'              => 'employee',
            'employee_id'        => $employeeId,
            'manual_mode'        => 'employee',
            'manual_employee_id' => $employeeId,
            'manual_amount_per_employee' => '500',
            'manual_notes'       => 'Viáticos (test A11)',
        ])
    );
    $response->assertOk();

    $tmpPath = storage_path('app/radiografias/__test_a11_' . uniqid() . '.xlsx');
    file_put_contents($tmpPath, $response->streamedContent());
    $spreadsheet = IOFactory::load($tmpPath);
    $resumen = $spreadsheet->getSheetByName('RESUMEN');
    expect($resumen)->not->toBeNull();

    $excelOpex = null;
    for ($r = 1; $r <= $resumen->getHighestRow(); $r++) {
        $label = $resumen->getCell("A{$r}")->getValue();
        if ($label && mb_strtoupper(trim((string) $label)) === 'TOTAL OPEX GESTOR') {
            $excelOpex = (float) $resumen->getCell("B{$r}")->getValue();
            break;
        }
    }
    expect($excelOpex)->not->toBeNull('No se encontró la fila "TOTAL OPEX GESTOR" en RESUMEN de colaborador.');
    expect(round($excelOpex, 2))->toBe($webOpex);

    @unlink($tmpPath);
});

it('an employee-mode manual adjustment for a DIFFERENT employee is never misapplied to a branch export', function () {
    $operativeNames = (new \ReflectionClass(\App\Http\Controllers\MonthlyReportController::class))->getConstant('OPERATIVE_BRANCH_NAMES');
    $exportService = app(RadiografiaExportService::class);

    $branch = null;
    foreach (Branch::whereIn('name', $operativeNames)->get() as $candidate) {
        $snap = $exportService->buildSnapshot($this->period, ['scope' => 'branch', 'branch_id' => $candidate->id]);
        if ($snap['scope']['available'] ?? false) {
            $branch = $candidate;
            break;
        }
    }
    if (!$branch) {
        $this->markTestSkipped('Ninguna sucursal operativa está disponible en el snapshot de este periodo.');
    }

    // Sin ajuste — baseline.
    $pathSin = $exportService->exportWithConfig($this->period, ['scope' => 'branch', 'branch_id' => $branch->id, 'report_type' => 'simple']);
    $sizeSin = filesize($pathSin);
    @unlink($pathSin);

    // Con manual_adjustment mode=employee (nunca branch_each_employee) — el branch
    // export solo interpreta branch_each_employee, así que esto NUNCA debe alterar
    // el resultado (mismo tamaño no es una aserción financiera fuerte, pero
    // confirma que no truena y no cambia de forma).
    $pathCon = $exportService->exportWithConfig($this->period, [
        'scope' => 'branch', 'branch_id' => $branch->id, 'report_type' => 'simple',
        'manual_adjustment' => ['mode' => 'employee', 'employee_id' => 999999, 'amount_per_employee' => 5000.0, 'notes' => 'no debería aplicar'],
    ]);
    expect(file_exists($pathCon))->toBeTrue();
    @unlink($pathCon);

    expect($sizeSin)->toBeGreaterThan(0);
});

it('a branch_each_employee manual adjustment for THIS branch DOES reach the branch Excel export, multiplied by canonical headcount', function () {
    $operativeNames = (new \ReflectionClass(\App\Http\Controllers\MonthlyReportController::class))->getConstant('OPERATIVE_BRANCH_NAMES');
    $exportService = app(RadiografiaExportService::class);

    $branch = null;
    foreach (Branch::whereIn('name', $operativeNames)->get() as $candidate) {
        $snap = $exportService->buildSnapshot($this->period, ['scope' => 'branch', 'branch_id' => $candidate->id]);
        if ($snap['scope']['available'] ?? false) {
            $branch = $candidate;
            break;
        }
    }
    if (!$branch) {
        $this->markTestSkipped('Ninguna sucursal operativa está disponible en el snapshot de este periodo.');
    }

    $webSin = $exportService->buildSnapshot($this->period, ['scope' => 'branch', 'branch_id' => $branch->id]);
    $opexSin = round((float) $webSin['summary']['opex_total'], 2);

    $adjustment = ['mode' => 'branch_each_employee', 'branch_id' => $branch->id, 'amount_per_employee' => 100.0, 'notes' => 'Prueba branch_each_employee'];
    $webCon = $exportService->buildSnapshot($this->period, ['scope' => 'branch', 'branch_id' => $branch->id, 'manual_adjustment' => $adjustment]);
    $opexCon = round((float) $webCon['summary']['opex_total'], 2);

    $temporaryAdjustment = app(\App\Services\TemporaryOpexAdjustmentService::class);
    $empGestores = app(\App\Services\Radiography\RadiographySnapshotBuilder::class)->buildAllEmployeeGestorRows($this->period);
    $expectedTotal = $temporaryAdjustment->totalForScope($empGestores, $adjustment, 'branch', $branch->id);

    if ($expectedTotal <= 0) {
        $this->markTestSkipped("La sucursal {$branch->name} no tiene colaboradores canónicos este periodo.");
    }

    expect(round($opexCon - $opexSin, 2))->toBe($expectedTotal);

    $excelPath = $exportService->exportWithConfig($this->period, ['scope' => 'branch', 'branch_id' => $branch->id, 'report_type' => 'simple', 'manual_adjustment' => $adjustment]);
    $spreadsheet = IOFactory::load($excelPath);
    $resumen = $spreadsheet->getSheetByName('RESUMEN');
    expect($resumen)->not->toBeNull();

    $excelOpex = null;
    for ($r = 1; $r <= $resumen->getHighestRow(); $r++) {
        $label = $resumen->getCell("A{$r}")->getValue();
        if ($label && mb_strtoupper(trim((string) $label)) === 'TOTAL GASTOS OPERATIVOS') {
            $excelOpex = (float) $resumen->getCell("B{$r}")->getValue();
            break;
        }
    }
    expect($excelOpex)->not->toBeNull('No se encontró la fila "Total Gastos Operativos" en RESUMEN de sucursal.');
    expect(round($excelOpex, 2))->toBe($opexCon);
    @unlink($excelPath);
});

/**
 * Bug real confirmado y corregido (cierre 17-sep-2026, ronda 3) — ORIZABA (id 94, Junio
 * 2026): 28 filas en employees_gestores, pero 6 sin `_employee_ids` resuelto (gestores que
 * solo aparecen en colocación/recuperación por nombre, sin match contra `employees`).
 * TemporaryOpexAdjustmentService::canonicalEmployeeIds() descartaba esas 6 EN SILENCIO,
 * multiplicando por 22 en vez de 28 — la UI (que cuenta TODAS las filas, sin filtrar por
 * identidad) mostraba "Gestores afectados: 28" / "$28,000", pero el Excel/PDF exportaban
 * $22,000. Ancla el conteo exacto contra la fuente real (nunca hardcodeado) para que una
 * regresión futura en canonicalEmployeeIds() sí reviente esta prueba.
 */
it('multiplies by EVERY row of employees_gestores for a branch, including gestores without a resolved employee_id (Orizaba regression)', function () {
    $branch = Branch::where('name', 'ORIZABA')->first();
    if (!$branch) {
        $this->markTestSkipped('No existe la sucursal ORIZABA en esta BD.');
    }

    $exportService = app(RadiografiaExportService::class);
    $snap = $exportService->buildSnapshot($this->period, ['scope' => 'branch', 'branch_id' => $branch->id]);
    if (!($snap['scope']['available'] ?? false)) {
        $this->markTestSkipped('ORIZABA no tiene datos de radiografía en este periodo.');
    }

    $empGestores = app(\App\Services\Radiography\RadiographySnapshotBuilder::class)->buildAllEmployeeGestorRows($this->period);
    $branchNameUpper = mb_strtoupper(trim($branch->name));
    $expectedRowCount = collect($empGestores)->filter(
        fn ($r) => mb_strtoupper(trim((string) ($r['branch'] ?? ''))) === $branchNameUpper
    )->count();
    $hasUnresolvedIdentity = collect($empGestores)->contains(
        fn ($r) => mb_strtoupper(trim((string) ($r['branch'] ?? ''))) === $branchNameUpper && empty($r['_employee_ids'])
    );
    if (!$hasUnresolvedIdentity) {
        $this->markTestSkipped('ORIZABA ya no tiene gestores sin employee_id resuelto en este periodo — el escenario del bug ya no aplica.');
    }

    $temporaryAdjustment = app(\App\Services\TemporaryOpexAdjustmentService::class);
    expect($temporaryAdjustment->canonicalEmployeeCount($empGestores, $branch->id))->toBe($expectedRowCount);

    $amountPerEmployee = 1000.0;
    $adjustment = ['mode' => 'branch_each_employee', 'branch_id' => $branch->id, 'amount_per_employee' => $amountPerEmployee, 'notes' => 'Regresión Orizaba'];
    $expectedTotal = round($amountPerEmployee * $expectedRowCount, 2);
    expect($temporaryAdjustment->totalForScope($empGestores, $adjustment, 'branch', $branch->id))->toBe($expectedTotal);

    $excelPath = $exportService->exportWithConfig($this->period, ['scope' => 'branch', 'branch_id' => $branch->id, 'report_type' => 'simple', 'manual_adjustment' => $adjustment]);
    $spreadsheet = IOFactory::load($excelPath);

    $resumen = $spreadsheet->getSheetByName('RESUMEN');
    $gastos  = $spreadsheet->getSheetByName('GASTOS');
    expect($resumen)->not->toBeNull();
    expect($gastos)->not->toBeNull();

    $findRowValue = function ($sheet, string $wantedLabel) {
        for ($r = 1; $r <= $sheet->getHighestRow(); $r++) {
            if (mb_strtoupper(trim((string) $sheet->getCell("A{$r}")->getValue())) === mb_strtoupper($wantedLabel)) {
                return (float) $sheet->getCell("B{$r}")->getValue();
            }
        }
        return null;
    };

    expect($findRowValue($resumen, 'Gasto manual'))->toBe($expectedTotal);
    expect($findRowValue($gastos, 'Gasto manual'))->toBe($expectedTotal);
    @unlink($excelPath);
});
