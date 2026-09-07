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
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Auditoría 07-sep-2026 — reports:audit-expense-attribution debe reportar las
 * secciones completas pedidas (resumen, atribución, montos, por colaborador,
 * por concepto/categoría, no encontrados con razón, ambiguos con candidatos).
 * No prueba la lógica de matching (eso vive en ExpenseObservationAttributionTest)
 * — solo que el comando expone los números reales correctamente.
 */
function makeCmdPeriodo(): Period
{
    static $seq = 0;
    $seq++;
    $month = (($seq - 1) % 12) + 1;

    return Period::query()->create([
        'name' => "Periodo audit-cmd {$seq}", 'code' => "M-AUDITCMD-2026-{$month}-{$seq}", 'type' => 'monthly',
        'year' => 2026, 'month' => $month, 'sequence' => 1,
        'start_date' => sprintf('2026-%02d-01', $month), 'end_date' => sprintf('2026-%02d-28', $month), 'is_closed' => false,
    ]);
}

function makeCmdBranch(string $name): Branch
{
    return Branch::query()->firstOrCreate(
        ['normalized_name' => mb_strtolower($name)],
        ['code' => mb_substr(strtoupper($name), 0, 4), 'name' => strtoupper($name), 'is_active' => true],
    );
}

function makeCmdRosterEmployee(Period $period, string $fullName, Branch $branch): Employee
{
    static $seq = 0;
    $seq++;
    $parts = explode(' ', $fullName);

    $employee = Employee::query()->create([
        'employee_code' => 'CMD' . $seq, 'full_name' => $fullName, 'normalized_name' => mb_strtolower($fullName),
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

function makeCmdUpload(Period $period): ReportUpload
{
    $source = DataSource::query()->firstOrCreate(
        ['code' => 'gastos_lendus_excel'],
        ['name' => 'gastos_lendus_excel', 'description' => 'gastos_lendus_excel', 'is_active' => true],
    );

    return ReportUpload::query()->create([
        'period_id' => $period->id, 'data_source_id' => $source->id,
        'original_name' => 'Gastos.xlsx', 'stored_path' => 'report_uploads/gastos.xlsx',
        'mime_type' => 'application/vnd.ms-excel', 'file_size' => 10, 'uploaded_at' => now(),
        'status' => \App\Enums\ReportUploadStatus::Processed, 'notes' => null,
    ]);
}

function makeCmdExpense(Period $period, ReportUpload $upload, array $overrides = []): Expense
{
    return Expense::query()->create(array_merge([
        'period_id' => $period->id, 'report_upload_id' => $upload->id,
        'category' => 'Recargas Telefónicas', 'concept' => 'RECARGAS TELEFONICAS',
        'amount' => 200.0, 'paid_amount' => 200.0, 'expense_date' => now()->format('Y-m-d'),
        'branch_id' => null, 'employee_id' => null,
    ], $overrides));
}

it('reports resumen, atribución, montos, por colaborador/concepto/categoría, no encontrados y ambiguos with real numbers', function () {
    $period = makeCmdPeriodo();
    $upload = makeCmdUpload($period);
    $branch = makeCmdBranch('Huamantla');

    $employee = makeCmdRosterEmployee($period, 'CARLOS ALBERTO MENDOZA RUIZ', $branch);
    makeCmdRosterEmployee($period, 'JOSE ANGEL PEREZ LOPEZ', $branch);
    makeCmdRosterEmployee($period, 'JOSE RICARDO PEREZ LOPEZ', $branch);

    // Encontrado (nombre contenido en texto con ruido).
    makeCmdExpense($period, $upload, ['observations' => 'RECARGA TELEFONICA PARA CARLOS ALBERTO MENDOZA RUIZ DEL MES']);
    // No encontrado.
    makeCmdExpense($period, $upload, ['observations' => 'RECARGA DE EXTINTOR']);
    // Ambiguo (combinación compartida por 2 personas del roster).
    makeCmdExpense($period, $upload, ['observations' => 'RECARGA PARA JOSE PEREZ LOPEZ']);
    // Excluido de evaluación (Nómina, ya cubierto por NOI) — no debe aparecer en "evaluables".
    makeCmdExpense($period, $upload, ['category' => 'Nómina y Capital Humano', 'concept' => 'NOMINA', 'observations' => 'CARLOS ALBERTO MENDOZA RUIZ']);

    // expectsOutputToContain() en cadena no es confiable con salida multilínea con
    // colores ANSI en este entorno — se captura la salida real (igual que la vería
    // el usuario en consola) y se afirma sobre el texto completo.
    $exitCode = \Illuminate\Support\Facades\Artisan::call('reports:audit-expense-attribution', ['period' => $period->id]);
    $output = \Illuminate\Support\Facades\Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain("PERIODO: {$period->label}");
    expect($output)->toContain('ARCHIVO: Gastos.xlsx');
    expect($output)->toContain('Filas fact_expenses del upload más reciente: 4');
    expect($output)->toContain('Filas OPEX evaluables por este servicio');
    expect($output)->toContain('POR COLABORADOR');
    expect($output)->toContain((string) $employee->id);
    expect($output)->toContain('POR CONCEPTO');
    expect($output)->toContain('POR CATEGORÍA');
    expect($output)->toContain('NO ENCONTRADOS');
    expect($output)->toContain('AMBIGUOS');
    expect($output)->toContain('Candidatos considerados');
    expect($output)->toContain('Total evaluados: 3'); // 4 filas - 1 excluida (Nómina) = 3 evaluables
    expect($output)->toContain('Con colaborador: 1');
    expect($output)->toContain('Sin colaborador: 1');
    expect($output)->toContain('Ambiguos: 1');
});

it('never mutates fact_expenses (audit command is dry-run only)', function () {
    $period = makeCmdPeriodo();
    $upload = makeCmdUpload($period);
    $branch = makeCmdBranch('Orizaba');
    $employee = makeCmdRosterEmployee($period, 'MARIA FERNANDA LUNA CASTRO', $branch);

    $expense = makeCmdExpense($period, $upload, ['observations' => 'MARIA FERNANDA LUNA CASTRO']);

    $this->artisan('reports:audit-expense-attribution', ['period' => $period->id])->assertExitCode(0);

    // El comando es SOLO diagnóstico — nunca debe escribir employee_id/branch_id.
    expect($expense->fresh()->employee_id)->toBeNull();
});
