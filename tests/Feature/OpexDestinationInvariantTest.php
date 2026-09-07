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
use App\Services\ExpenseObservationAttributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Auditoría 07-sep-2026 (cierre, sección 5) — invariante "ningún OPEX sin
 * destino": para toda fila is_opex=true, employee_id IS NOT NULL OR branch_id
 * IS NOT NULL OR attribution_needs_review=true. Verificado sobre el RESULTADO
 * proyectado de ExpenseObservationAttributionService::attributeForPeriod()
 * (universo B), que es exactamente lo que reports:audit-expense-attribution
 * reporta en su sección "OPEX SIN DESTINO".
 */
function invPeriodo(): Period
{
    static $seq = 0;
    $seq++;
    $month = (($seq - 1) % 12) + 1;

    return Period::query()->create([
        'name' => "Periodo invariante {$seq}", 'code' => "M-INVDEST-2026-{$month}-{$seq}", 'type' => 'monthly',
        'year' => 2026, 'month' => $month, 'sequence' => 1,
        'start_date' => sprintf('2026-%02d-01', $month), 'end_date' => sprintf('2026-%02d-28', $month), 'is_closed' => false,
    ]);
}

function invBranch(string $name): Branch
{
    return Branch::query()->firstOrCreate(
        ['normalized_name' => mb_strtolower($name)],
        ['code' => mb_substr(strtoupper($name), 0, 4), 'name' => strtoupper($name), 'is_active' => true],
    );
}

function invRosterEmployee(Period $period, string $fullName, Branch $branch): Employee
{
    static $seq = 0;
    $seq++;
    $parts = explode(' ', $fullName);

    $employee = Employee::query()->create([
        'employee_code' => 'INV' . $seq, 'full_name' => $fullName, 'normalized_name' => mb_strtolower($fullName),
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

function invUpload(Period $period): ReportUpload
{
    $source = DataSource::query()->firstOrCreate(
        ['code' => 'gastos_lendus_excel'],
        ['name' => 'gastos_lendus_excel', 'description' => 'x', 'is_active' => true],
    );

    return ReportUpload::query()->create([
        'period_id' => $period->id, 'data_source_id' => $source->id,
        'original_name' => 'Gastos.xlsx', 'stored_path' => 'x', 'mime_type' => 'x', 'file_size' => 10,
        'uploaded_at' => now(), 'status' => \App\Enums\ReportUploadStatus::Processed, 'notes' => null,
    ]);
}

function invExpense(Period $period, ReportUpload $upload, array $overrides = []): Expense
{
    return Expense::query()->create(array_merge([
        'period_id' => $period->id, 'report_upload_id' => $upload->id,
        'category' => 'Recargas Telefónicas', 'concept' => 'RECARGAS TELEFONICAS',
        'amount' => 200.0, 'paid_amount' => 200.0, 'expense_date' => now()->format('Y-m-d'),
        'branch_id' => null, 'employee_id' => null,
    ], $overrides));
}

it('the invariant HOLDS (count=0) when every OPEX row has either a person or a resolved branch', function () {
    $period = invPeriodo();
    $upload = invUpload($period);
    $branch = invBranch('Tula');
    $employee = invRosterEmployee($period, 'EMPLEADO INVARIANTE OK', $branch);

    invExpense($period, $upload, ['observations' => 'EMPLEADO INVARIANTE OK']); // resuelve a persona
    invExpense($period, $upload, ['observations' => null, 'branch_id' => $branch->id]); // branch_general

    $service = app(ExpenseObservationAttributionService::class);
    $results = $service->attributeForPeriod($period, [$period->id], dryRun: true);

    $sinDestino = array_filter($results, fn ($r) => $r['estado'] === 'no_atribuible');
    expect($sinDestino)->toBeEmpty();

    Artisan::call('reports:audit-expense-attribution', ['period' => $period->id]);
    expect(Artisan::output())->toContain('count = 0 | amount = $0.00');
});

it('forces a real violation (fixture sintético) and confirms both the service and the audit command flag it', function () {
    $period = invPeriodo();
    $upload = invUpload($period);
    $branch = invBranch('Cordoba');
    invRosterEmployee($period, 'EMPLEADO IRRELEVANTE INV', $branch);

    // OPEX real, sin persona identificable Y sin branch_id resuelto — el caso
    // que la invariante debe señalar (en la práctica, GastosExcelBranchResolverService
    // garantiza branch_id siempre resuelto antes de este servicio — este fixture
    // fuerza deliberadamente el caso límite para probar que el comando lo detecta).
    $violating = invExpense($period, $upload, ['observations' => 'GASTO SIN NINGUNA REFERENCIA', 'branch_id' => null]);

    $service = app(ExpenseObservationAttributionService::class);
    $results = $service->attributeForPeriod($period, [$period->id], dryRun: true);

    $sinDestino = array_values(array_filter($results, fn ($r) => $r['estado'] === 'no_atribuible'));
    expect($sinDestino)->toHaveCount(1);
    expect($sinDestino[0]['fact_expense_id'])->toBe($violating->id);
    expect($sinDestino[0]['employee_id'])->toBeNull();
    expect($sinDestino[0]['branch_id'])->toBeNull();

    Artisan::call('reports:audit-expense-attribution', ['period' => $period->id]);
    $output = Artisan::output();
    expect($output)->toContain('count = 1 | amount = $200.00');
    expect($output)->toContain("fact_expenses.id={$violating->id}");
});
