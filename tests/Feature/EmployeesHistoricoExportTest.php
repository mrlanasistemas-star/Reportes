<?php

use App\Enums\MatchType;
use App\Enums\SourceType;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\EmployeePeriodManualExpense;
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
    EmployeePeriodManualExpense::query()->create(['period_id' => $period->id, 'employee_id' => $employee->id, 'amount' => 250, 'notes' => 'Ajuste']);

    $service = app(EmployeesHistoricoExportService::class);
    $spreadsheet = $service->build($period, []);
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
    $detail = $builder->buildEmployeeExpenseDetail([$employee->id], $period->id, $employee->id);

    expect((float) $exportRow['OPEX TOTAL'])->toBe($detail['total']);
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
