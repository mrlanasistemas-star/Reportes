<?php

use App\Enums\MatchType;
use App\Enums\SourceType;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Expense;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\Radiography\EmployeesHistoricoExportService;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

/**
 * Auditoría 07-sep-2026 (frente 6) — "Descargar Excel de colaboradores": TODOS
 * los colaboradores del periodo, ignorando el filtro individual de employee_id,
 * reutilizando las mismas fuentes/fórmulas que la vista Web de un colaborador
 * (nunca una segunda fórmula de EBITDA). Ver EmployeesHistoricoExportService.
 */
function exportPeriodo(): Period
{
    static $seq = 0;
    $seq++;
    $month = (($seq - 1) % 12) + 1;

    return Period::query()->create([
        'name' => "Periodo export {$seq}", 'code' => "M-EXPORT-2026-{$month}-{$seq}", 'type' => 'monthly',
        'year' => 2026, 'month' => $month, 'sequence' => 1,
        'start_date' => sprintf('2026-%02d-01', $month), 'end_date' => sprintf('2026-%02d-28', $month), 'is_closed' => false,
    ]);
}

function exportBranch(string $name): Branch
{
    return Branch::query()->firstOrCreate(
        ['normalized_name' => mb_strtolower($name)],
        ['code' => mb_substr(strtoupper($name), 0, 4), 'name' => strtoupper($name), 'is_active' => true],
    );
}

function exportEmployee(Period $period, string $fullName, Branch $branch): Employee
{
    static $seq = 0;
    $seq++;
    $parts = explode(' ', $fullName);

    $employee = Employee::query()->create([
        'employee_code' => 'EXP' . $seq, 'full_name' => $fullName, 'normalized_name' => mb_strtolower($fullName),
        'first_name' => $parts[0] ?? $fullName, 'paternal_last_name' => $parts[1] ?? 'X',
        'is_active' => true, 'source_system' => 'noi',
    ]);

    DB::table('fact_noi_movements')->insert([
        'period_id' => $period->id, 'employee_id' => $employee->id, 'amount' => 1000, 'quantity' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    EmployeeBranchAssignment::query()->create([
        'employee_id' => $employee->id, 'period_id' => $period->id, 'branch_id' => $branch->id,
        'source_type' => SourceType::Manual, 'match_type' => MatchType::Manual,
    ]);

    return $employee;
}

function exportExpense(Period $period, Employee $employee, Branch $branch, float $amount): Expense
{
    $source = DataSource::query()->firstOrCreate(
        ['code' => 'gastos_lendus_excel'],
        ['name' => 'gastos_lendus_excel', 'description' => 'x', 'is_active' => true],
    );
    $upload = ReportUpload::query()->create([
        'period_id' => $period->id, 'data_source_id' => $source->id,
        'original_name' => 'Gastos.xlsx', 'stored_path' => 'x', 'mime_type' => 'x', 'file_size' => 10,
        'uploaded_at' => now(), 'status' => \App\Enums\ReportUploadStatus::Processed, 'notes' => null,
    ]);

    return Expense::query()->create([
        'period_id' => $period->id, 'report_upload_id' => $upload->id,
        'category' => 'Recargas Telefónicas', 'concept' => 'RECARGAS TELEFONICAS',
        'amount' => $amount, 'paid_amount' => $amount, 'expense_date' => now()->format('Y-m-d'),
        'branch_id' => $branch->id, 'employee_id' => $employee->id,
    ]);
}

function readExportedRows(string $path): array
{
    $spreadsheet = IOFactory::load($path);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = [];
    $header = null;
    // formatData=false — valores crudos (numéricos), no la cadena con formato de
    // moneda ("$750.00") que (float) convertiría incorrectamente a 0.0.
    foreach ($sheet->toArray(null, true, false, false) as $i => $line) {
        if ($i === 0) {
            $header = $line;
            continue;
        }
        if ($line[0] === null) {
            continue;
        }
        $rows[] = array_combine($header, $line);
    }
    return $rows;
}

it('includes ALL employees of the period, never limited to a single selected employee', function () {
    $period = exportPeriodo();
    $branch = exportBranch('Tula');
    $e1 = exportEmployee($period, 'COLABORADOR EXPORT UNO', $branch);
    $e2 = exportEmployee($period, 'COLABORADOR EXPORT DOS', $branch);
    $e3 = exportEmployee($period, 'COLABORADOR EXPORT TRES', $branch);
    exportExpense($period, $e1, $branch, 500);
    exportExpense($period, $e2, $branch, 300);

    $service = app(EmployeesHistoricoExportService::class);
    $spreadsheet = $service->build($period, []); // sin filtro de employee_id — nunca existe ese parámetro aquí

    $tmp = tempnam(sys_get_temp_dir(), 'export') . '.xlsx';
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($tmp);
    $rows = readExportedRows($tmp);
    @unlink($tmp);

    $names = array_column($rows, 'NOMBRE COLABORADOR');
    expect($names)->toContain('COLABORADOR EXPORT UNO');
    expect($names)->toContain('COLABORADOR EXPORT DOS');
    expect($names)->toContain('COLABORADOR EXPORT TRES');
    expect(count($rows))->toBeGreaterThanOrEqual(3);
});

it('respects the branch filter but never the individual employee filter (there is no such parameter)', function () {
    $period = exportPeriodo();
    $branchA = exportBranch('Cordoba');
    $branchB = exportBranch('Miacatlan');
    $eA = exportEmployee($period, 'COLABORADOR SUCURSAL A', $branchA);
    $eB = exportEmployee($period, 'COLABORADOR SUCURSAL B', $branchB);

    $service = app(EmployeesHistoricoExportService::class);
    $spreadsheet = $service->build($period, ['branch_id' => $branchA->id]);

    $tmp = tempnam(sys_get_temp_dir(), 'export') . '.xlsx';
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($tmp);
    $rows = readExportedRows($tmp);
    @unlink($tmp);

    $names = array_column($rows, 'NOMBRE COLABORADOR');
    expect($names)->toContain('COLABORADOR SUCURSAL A');
    expect($names)->not->toContain('COLABORADOR SUCURSAL B');
});

it('OPEX total and EBITDA in the Excel match exactly what buildEmployeeExpenseDetail/applyEmployeeScope compute for that same employee', function () {
    $period = exportPeriodo();
    $branch = exportBranch('Orizaba');
    $employee = exportEmployee($period, 'COLABORADOR PARIDAD', $branch);
    exportExpense($period, $employee, $branch, 750);

    // Ajuste manual EFÍMERO (reversión 07-sep-2026, cierre) — nunca BD, viaja
    // como parámetro directo a build().
    $manualAdjustment = ['scope' => 'employee', 'employee_id' => $employee->id, 'amount' => 250.0, 'notes' => 'Ajuste'];

    $service = app(EmployeesHistoricoExportService::class);
    $spreadsheet = $service->build($period, [], $manualAdjustment);
    $tmp = tempnam(sys_get_temp_dir(), 'export') . '.xlsx';
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($tmp);
    $rows = readExportedRows($tmp);
    @unlink($tmp);

    $exportRow = collect($rows)->firstWhere('NOMBRE COLABORADOR', 'COLABORADOR PARIDAD');
    expect($exportRow)->not->toBeNull();
    expect((float) $exportRow['OPEX AUTOMÁTICO'])->toBe(750.0);
    expect((float) $exportRow['GASTO MANUAL'])->toBe(250.0);
    expect((float) $exportRow['OPEX TOTAL'])->toBe(1000.0);

    // MISMA fuente que consume la vista Web (findEmployeeGestorRowByEmployeeId +
    // buildEmployeeExpenseDetail) — sin recalcular con una fórmula distinta.
    $builder = app(RadiographySnapshotBuilder::class);
    $builder->findEmployeeGestorRowByEmployeeId($period, $employee->id);
    $detail = $builder->buildEmployeeExpenseDetail([$employee->id], $employee->id, 250.0, 'Ajuste');

    expect((float) $exportRow['OPEX TOTAL'])->toBe($detail['total']);
});

// ── Ajuste manual EFÍMERO: nunca se guarda, nunca se reparte (punto 7/14) ────
it('a manual adjustment passed to build() is never persisted and, without it, the export returns exactly the base data', function () {
    $period = exportPeriodo();
    $branch = exportBranch('Cordoba');
    $employee = exportEmployee($period, 'COLABORADOR SIN AJUSTE', $branch);
    exportExpense($period, $employee, $branch, 400);

    $countAntes = DB::table('employee_period_manual_expenses')->count();

    $service = app(EmployeesHistoricoExportService::class);
    $service->build($period, [], ['scope' => 'employee', 'employee_id' => $employee->id, 'amount' => 999.0, 'notes' => 'x']);

    expect(DB::table('employee_period_manual_expenses')->count())->toBe($countAntes);

    // Nueva descarga SIN manual_adjustment — debe volver exactamente a la base.
    $spreadsheet = $service->build($period, []);
    $tmp = tempnam(sys_get_temp_dir(), 'export') . '.xlsx';
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($tmp);
    $rows = readExportedRows($tmp);
    @unlink($tmp);

    $exportRow = collect($rows)->firstWhere('NOMBRE COLABORADOR', 'COLABORADOR SIN AJUSTE');
    expect((float) $exportRow['GASTO MANUAL'])->toBe(0.0);
    expect((float) $exportRow['OPEX AUTOMÁTICO'])->toBe(400.0);
    expect((float) $exportRow['OPEX TOTAL'])->toBe(400.0);
});

// ── Ajuste GENERAL: nunca se reparte entre colaboradores, solo aparece en "Resumen" ──
it('a general-scope manual adjustment never gets distributed across employee rows and appears once in the Resumen sheet', function () {
    $period = exportPeriodo();
    $branch = exportBranch('Puebla');
    $e1 = exportEmployee($period, 'COLABORADOR GENERAL UNO', $branch);
    $e2 = exportEmployee($period, 'COLABORADOR GENERAL DOS', $branch);
    exportExpense($period, $e1, $branch, 100);
    exportExpense($period, $e2, $branch, 200);

    $service = app(EmployeesHistoricoExportService::class);
    $spreadsheet = $service->build($period, [], ['scope' => 'general', 'amount' => 10000.0, 'notes' => 'Ajuste general de prueba']);

    $sheet = $spreadsheet->getSheetByName('Colaboradores');
    $tmp = tempnam(sys_get_temp_dir(), 'export') . '.xlsx';
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($tmp);
    $rows = readExportedRows($tmp);
    @unlink($tmp);

    // Ninguna fila individual recibió el ajuste general — cada quien conserva su
    // OPEX AUTOMÁTICO/GASTO MANUAL/OPEX TOTAL oficiales.
    $row1 = collect($rows)->firstWhere('NOMBRE COLABORADOR', 'COLABORADOR GENERAL UNO');
    $row2 = collect($rows)->firstWhere('NOMBRE COLABORADOR', 'COLABORADOR GENERAL DOS');
    expect((float) $row1['GASTO MANUAL'])->toBe(0.0);
    expect((float) $row1['OPEX TOTAL'])->toBe(100.0);
    expect((float) $row2['GASTO MANUAL'])->toBe(0.0);
    expect((float) $row2['OPEX TOTAL'])->toBe(200.0);

    // El ajuste general aparece UNA vez, en su propia hoja.
    $resumen = $spreadsheet->getSheetByName('Resumen');
    expect($resumen)->not->toBeNull();
    expect((float) $resumen->getCell('B2')->getValue())->toBe(10000.0); // AJUSTE MANUAL GENERAL TEMPORAL
});

it('marks a collaborator with real activity as ACTIVO and one with none as BAJA, without touching their portfolio', function () {
    $period = exportPeriodo();
    $branch = exportBranch('Tlaxcala');
    $activo = exportEmployee($period, 'COLABORADOR ACTIVO EXPORT', $branch);
    exportExpense($period, $activo, $branch, 100);
    $baja = exportEmployee($period, 'COLABORADOR BAJA EXPORT', $branch);

    $service = app(EmployeesHistoricoExportService::class);
    $spreadsheet = $service->build($period, []);
    $tmp = tempnam(sys_get_temp_dir(), 'export') . '.xlsx';
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($tmp);
    $rows = readExportedRows($tmp);
    @unlink($tmp);

    $rowActivo = collect($rows)->firstWhere('NOMBRE COLABORADOR', 'COLABORADOR ACTIVO EXPORT');
    $rowBaja   = collect($rows)->firstWhere('NOMBRE COLABORADOR', 'COLABORADOR BAJA EXPORT');

    expect($rowActivo['ESTADO ACTIVO/BAJA'])->toBe('ACTIVO');
    expect($rowBaja['ESTADO ACTIVO/BAJA'])->toBe('BAJA');
});

it('the HTTP download route is reachable and returns a valid xlsx file', function () {
    $user = User::factory()->create();
    $period = exportPeriodo();
    $branch = exportBranch('San Luis Potosi');
    exportEmployee($period, 'COLABORADOR HTTP EXPORT', $branch);

    $response = $this->actingAs($user)->get(route('reportes-mensuales.export-employees-historico', $period->id));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('spreadsheetml');
});

// ── Bug real 07-sep-2026: el .xlsx descargado por HTTP debe contener gráficas
// REALES, no solo la mini-tabla de apoyo (writer sin setIncludeCharts(true) las
// omitía en el archivo final aunque el código las hubiera construido). ─────────
it('the .xlsx downloaded via HTTP actually contains rendered chart objects, not just numbers', function () {
    $user = User::factory()->create();
    $period = exportPeriodo();
    $branch = exportBranch('Tula');
    $e1 = exportEmployee($period, 'COLABORADOR CHART HTTP UNO', $branch);
    exportExpense($period, $e1, $branch, 500);

    $response = $this->actingAs($user)->get(route('reportes-mensuales.export-employees-historico', $period->id));
    $response->assertOk();

    $tmp = tempnam(sys_get_temp_dir(), 'export') . '.xlsx';
    file_put_contents($tmp, $response->streamedContent());

    // El reader de PhpSpreadsheet TAMBIÉN necesita includeCharts=true para volver
    // a parsear los objetos de gráfica al leer — sin esto, IOFactory::load()
    // simplemente no los expone (aunque sí estén en el .xlsx real). No es el bug:
    // es la simetría esperada de la librería (setIncludeCharts en reader Y writer).
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
    $reader->setIncludeCharts(true);
    $spreadsheet = $reader->load($tmp);
    $chartSheet = $spreadsheet->getSheetByName('Gráficas');
    expect($chartSheet)->not->toBeNull();
    expect(count($chartSheet->getChartCollection()))->toBeGreaterThan(0);
    @unlink($tmp);
});

// ── Ajustes de UX pedidos (mid-turn, 07-sep-2026) ─────────────────────────────
it('renames INGRESO BASE EBITDA to UTILIDAD BRUTA, removes ID COLABORADOR, adds AutoFilter and a Gráficas sheet with a bar per collaborator', function () {
    $period = exportPeriodo();
    $branch = exportBranch('Cuernavaca');
    $e1 = exportEmployee($period, 'COLABORADOR UX UNO', $branch);
    $e2 = exportEmployee($period, 'COLABORADOR UX DOS', $branch);
    exportExpense($period, $e1, $branch, 200);
    exportExpense($period, $e2, $branch, 300);

    $service = app(\App\Services\Radiography\EmployeesHistoricoExportService::class);
    $spreadsheet = $service->build($period, []);

    $dataSheet = $spreadsheet->getSheetByName('Colaboradores');
    $header = $dataSheet->rangeToArray('A1:S1')[0];

    expect($header)->toContain('UTILIDAD BRUTA');
    expect($header)->not->toContain('INGRESO BASE EBITDA');
    expect($header)->not->toContain('ID COLABORADOR');

    // AutoFilter cubre todo el encabezado — filtros nativos de Excel por sucursal,
    // estado activo/baja, o cualquier otra columna.
    expect($dataSheet->getAutoFilter()->getRange())->not->toBe('');

    // Hoja de gráficas nativas, con una fila (barra) por colaborador — ninguno omitido.
    $chartSheet = $spreadsheet->getSheetByName('Gráficas');
    expect($chartSheet)->not->toBeNull();
    expect(count($chartSheet->getChartCollection()))->toBeGreaterThan(0);
    // La tabla de apoyo vive lejos (columna AB en adelante) para que las gráficas
    // (A1 en adelante) sean lo primero visible al abrir la hoja.
    $chartRows = $chartSheet->rangeToArray('AB2:AB3');
    expect(collect($chartRows)->flatten()->filter()->count())->toBe(2); // ambos colaboradores, ninguno faltante
    expect($chartSheet->getAutoFilter()->getRange())->not->toBe(''); // filtro nativo sobre la tabla de apoyo
});
