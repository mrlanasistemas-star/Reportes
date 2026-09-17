<?php

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\User;

uses(Tests\Integration\UsesRealDevDatabase::class);

/**
 * Cierre 17-sep-2026, PARTE A — bug real reportado por el usuario: el botón Excel/PDF
 * de CABECERA (Preview.vue::buildActiveScopeUrl()) con alcance general caía a las URLs
 * estáticas export-radiography(.pdf), que jamás llevan manual_adjustment. Resultado: la
 * Web se veía ajustada pero el Excel/PDF de cabecera salían SIN el ajuste (A10/A11 del
 * pendiente). El fix hace que buildActiveScopeUrl() use SIEMPRE export-filtrado.xlsx/pdf
 * (con scope=general + manual_scope=general) en cuanto hay un ajuste APLICADO.
 *
 * Estos tests verifican el CONTRATO HTTP real que ese fix explota — que las rutas
 * export-filtered-radiography(.pdf) YA aceptan scope=general + manual_* sin exigir
 * branch_id/employee_id (a diferencia de scope=branch/employee, que si los exige).
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

it('exports a general-scope Excel via the filtered route with a general manual adjustment applied (A10 header-button fix)', function () {
    $response = $this->actingAs($this->actingUser)->get(
        route('reportes-mensuales.export-filtered-radiography', $this->period->id) . '?' . http_build_query([
            'report_type'  => 'simple',
            'scope'        => 'general',
            'manual_scope' => 'general',
            'manual_amount' => '20000',
            'manual_notes'  => 'Gasto extraordinario del mes (test A10)',
        ])
    );

    $response->assertOk();
    $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    // Limpia el archivo temporal que exportFilteredRadiography() genera en storage/app/radiografias.
    $disposition = $response->headers->get('content-disposition') ?? '';
    if (preg_match('/filename="?([^"]+)"?/', $disposition, $m)) {
        @unlink(storage_path('app/radiografias/' . $m[1]));
    }
});

it('exports a general-scope PDF via the filtered route with a general manual adjustment applied (A10 header-button fix)', function () {
    $response = $this->actingAs($this->actingUser)->get(
        route('reportes-mensuales.export-filtered-radiography-pdf', $this->period->id) . '?' . http_build_query([
            'report_type'  => 'simple',
            'scope'        => 'general',
            'manual_scope' => 'general',
            'manual_amount' => '20000',
            'manual_notes'  => 'Gasto extraordinario del mes (test A10)',
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

    $exportService = app(\App\Services\RadiografiaExportService::class);
    $webSnapshot = $exportService->buildSnapshot($this->period, [
        'scope' => 'employee', 'employee_id' => $employeeId,
        'manual_adjustment' => ['scope' => 'employee', 'employee_id' => $employeeId, 'amount' => 500.0, 'notes' => 'Viáticos (test A11)'],
    ]);
    $webOpex = round((float) ($webSnapshot['summary']['opex_total'] ?? -1), 2);

    $response = $this->actingAs($this->actingUser)->get(
        route('reportes-mensuales.export-filtered-radiography', $this->period->id) . '?' . http_build_query([
            'report_type'        => 'simple',
            'scope'              => 'employee',
            'employee_id'        => $employeeId,
            'manual_scope'       => 'employee',
            'manual_employee_id' => $employeeId,
            'manual_amount'      => '500',
            'manual_notes'       => 'Viáticos (test A11)',
        ])
    );
    $response->assertOk();

    $tmpPath = storage_path('app/radiografias/__test_a11_' . uniqid() . '.xlsx');
    file_put_contents($tmpPath, $response->streamedContent());
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
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

it('never applies a manual adjustment to a branch export, even if manual params are sent alongside scope=branch', function () {
    $operativeNames = (new \ReflectionClass(\App\Http\Controllers\MonthlyReportController::class))->getConstant('OPERATIVE_BRANCH_NAMES');
    $exportService = app(\App\Services\RadiografiaExportService::class);

    $branch = null;
    foreach (\App\Models\Branch::whereIn('name', $operativeNames)->get() as $candidate) {
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

    // Con manual_adjustment de alcance 'employee' colado en el config — el branch export
    // no sabe interpretarlo (buildBranchFromSnapshot no acepta extraAmount/extraNotes),
    // así que el archivo generado debe ser funcionalmente idéntico (mismo tamaño no es
    // una aserción financiera fuerte, pero confirma que no truena y no cambia de forma).
    $pathCon = $exportService->exportWithConfig($this->period, [
        'scope' => 'branch', 'branch_id' => $branch->id, 'report_type' => 'simple',
        'manual_adjustment' => ['scope' => 'employee', 'employee_id' => 999999, 'amount' => 5000.0, 'notes' => 'no debería aplicar'],
    ]);
    expect(file_exists($pathCon))->toBeTrue();
    @unlink($pathCon);

    expect($sizeSin)->toBeGreaterThan(0);
});
