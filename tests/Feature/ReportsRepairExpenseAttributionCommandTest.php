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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Auditoría 07-sep-2026 (cierre, sección 7) — reports:repair-expense-attribution
 * con guardas duras: COUNT/SUM(amount)/SUM(paid_amount)/total OPEX no deben
 * cambiar tras --apply (test obligatorio L), y --export vuelca el detalle
 * propuesto a CSV para revisión humana antes de aplicar nada.
 */
function repairPeriodo(): Period
{
    static $seq = 0;
    $seq++;
    $month = (($seq - 1) % 12) + 1;

    return Period::query()->create([
        'name' => "Periodo repair {$seq}", 'code' => "M-REPAIRCMD-2026-{$month}-{$seq}", 'type' => 'monthly',
        'year' => 2026, 'month' => $month, 'sequence' => 1,
        'start_date' => sprintf('2026-%02d-01', $month), 'end_date' => sprintf('2026-%02d-28', $month), 'is_closed' => false,
    ]);
}

function repairBranch(string $name): Branch
{
    return Branch::query()->firstOrCreate(
        ['normalized_name' => mb_strtolower($name)],
        ['code' => mb_substr(strtoupper($name), 0, 4), 'name' => strtoupper($name), 'is_active' => true],
    );
}

function repairRosterEmployee(Period $period, string $fullName, Branch $branch): Employee
{
    static $seq = 0;
    $seq++;
    $parts = explode(' ', $fullName);

    $employee = Employee::query()->create([
        'employee_code' => 'RPR' . $seq, 'full_name' => $fullName, 'normalized_name' => mb_strtolower($fullName),
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

function repairUpload(Period $period): ReportUpload
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

function repairExpense(Period $period, ReportUpload $upload, array $overrides = []): Expense
{
    return Expense::query()->create(array_merge([
        'period_id' => $period->id, 'report_upload_id' => $upload->id,
        'category' => 'Recargas Telefónicas', 'concept' => 'RECARGAS TELEFONICAS',
        'amount' => 200.0, 'paid_amount' => 200.0, 'expense_date' => now()->format('Y-m-d'),
        'branch_id' => null, 'employee_id' => null,
    ], $overrides));
}

it('--dry-run never writes and reports the invariant as not-yet-verified (nothing to verify)', function () {
    $period = repairPeriodo();
    $upload = repairUpload($period);
    $branch = repairBranch('Tula');
    $employee = repairRosterEmployee($period, 'EMPLEADO REPAIR DRYRUN', $branch);
    $expense = repairExpense($period, $upload, ['observations' => 'EMPLEADO REPAIR DRYRUN']);

    Artisan::call('reports:repair-expense-attribution', ['period' => $period->id, '--dry-run' => true]);

    expect($expense->fresh()->employee_id)->toBeNull();
    expect(Artisan::output())->toContain('DRY-RUN — no se verificó invariante post-escritura');
});

it('--apply writes the attribution and confirms count/sum(amount)/sum(paid_amount)/opex total are unchanged (invariant L)', function () {
    $period = repairPeriodo();
    $upload = repairUpload($period);
    $branch = repairBranch('Cuernavaca');
    $employee = repairRosterEmployee($period, 'EMPLEADO REPAIR APPLY', $branch);
    $expense = repairExpense($period, $upload, ['observations' => 'EMPLEADO REPAIR APPLY']);

    $countBefore = DB::table('fact_expenses')->where('period_id', $period->id)->count();
    $sumAmountBefore = (float) DB::table('fact_expenses')->where('period_id', $period->id)->sum('amount');
    $sumPaidBefore = (float) DB::table('fact_expenses')->where('period_id', $period->id)->sum('paid_amount');

    $exitCode = Artisan::call('reports:repair-expense-attribution', ['period' => $period->id, '--apply' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Invariante OK');
    expect($expense->fresh()->employee_id)->toBe($employee->id);

    expect(DB::table('fact_expenses')->where('period_id', $period->id)->count())->toBe($countBefore);
    expect((float) DB::table('fact_expenses')->where('period_id', $period->id)->sum('amount'))->toBe($sumAmountBefore);
    expect((float) DB::table('fact_expenses')->where('period_id', $period->id)->sum('paid_amount'))->toBe($sumPaidBefore);
});

it('--export writes a CSV with the proposed changes (fact_expense_id, employee anterior/nuevo, branch anterior/nuevo, texto, metodo, confianza)', function () {
    $period = repairPeriodo();
    $upload = repairUpload($period);
    $branch = repairBranch('Orizaba');
    $employee = repairRosterEmployee($period, 'EMPLEADO REPAIR EXPORT', $branch);
    $expense = repairExpense($period, $upload, ['observations' => 'EMPLEADO REPAIR EXPORT']);

    $csvPath = storage_path('app/audits/propuesta_test_' . $period->id . '.csv');
    @unlink($csvPath);

    Artisan::call('reports:repair-expense-attribution', ['period' => $period->id, '--dry-run' => true, '--export' => $csvPath]);

    expect(file_exists($csvPath))->toBeTrue();
    $rows = array_map('str_getcsv', file($csvPath));
    expect($rows[0])->toBe(['fact_expense_id', 'concept', 'category', 'amount', 'employee_anterior', 'employee_nuevo', 'branch_anterior', 'branch_nuevo', 'texto', 'metodo', 'confianza']);
    expect($rows[1][0])->toBe((string) $expense->id);
    expect((int) $rows[1][5])->toBe($employee->id); // employee_nuevo

    // --export es solo diagnóstico — con --dry-run implícito nunca escribe en fact_expenses.
    expect($expense->fresh()->employee_id)->toBeNull();
    @unlink($csvPath);
});
