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
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Auditoría 07-sep-2026 (frente 5) — estatus OPERATIVO activo/baja, alcance
 * acotado por decisión explícita del usuario: SOLO alimenta
 * sections.rotation_detail.operational_status bajo scope=employee (badge de
 * Histórico + columna del Excel de colaboradores). Rotación oficial (NOI) e
 * IMSS patronal NO se tocan — ver RadiographySnapshotBuilder::
 * buildOperationalStatus().
 *
 * Regla: activo = ingreso real (recuperación/colocación) O gasto operativo
 * automático atribuible > 0. Nunca solo por aparecer en nómina.
 */
function invokeOpStatusMethod(RadiographySnapshotBuilder $builder, string $method, array $args): array
{
    $ref = new ReflectionMethod($builder, $method);
    $ref->setAccessible(true);

    $dataIdsProp = new ReflectionProperty($builder, 'dataIds');
    $dataIdsProp->setAccessible(true);
    $dataIdsProp->setValue($builder, $args['dataIds']);

    return $ref->invoke($builder, ...$args['args']);
}

function opStatusSnapshotFixture(int $periodId, array $employeeRow): array
{
    return [
        'period' => ['id' => $periodId, 'label' => 'Periodo estatus'],
        'generated_at' => '01/06/2026 10:00',
        'summary' => [
            'recovery_total' => 17000000.0, 'placement_total' => 12000000.0, 'portfolio_total' => 38000000.0,
            'overdue_portfolio' => 14000000.0, 'mora_index' => 36.8, 'expenses_total' => 758000.0,
            'nomina_capital_humano_total' => 2489000.0, 'ebitda_final' => 2058000.0, 'margen_ebitda' => 34.5,
            'unificacion_excluida' => 0.0, 'condonacion_excluida' => 0.0,
        ],
        'branch_radiography' => ['branches' => [], 'global' => ['recuperacion_total' => 17000000.0], 'unassigned' => []],
        'sections' => [
            'employees_gestores' => [$employeeRow],
            'mora_by_gestor' => [],
            'active_loans' => [],
            'portfolio_by_branch_product' => [],
            'placement_by_branch_product' => [],
            'mora_by_branch_product' => [],
            'mora_by_branch' => [],
            'payroll_by_branch_concept' => ['data' => [], 'incidents' => []],
            'expenses_detail' => ['total' => 0, 'byBranch' => [], 'byEmployee' => [], 'byCategory' => [], 'byConcept' => [], 'bySource' => []],
            'products' => [],
            'interbranch_loans' => ['total' => 0.0],
            'corporate_funding' => ['total' => 0.0],
            'fondeo_detalle' => ['total' => 0.0],
            'rotation_detail' => ['altas' => [], 'bajas' => [], 'activos' => [], 'empleados_mes_actual' => [], 'empleados_mes_anterior' => []],
        ],
        'charts' => ['recovery_by_branch' => []],
    ];
}

function opStatusPeriodo(int $year, int $month): Period
{
    return Period::query()->create([
        'name' => "Periodo op-status {$year}-{$month}", 'code' => "M-OPSTATUS-{$year}-{$month}", 'type' => 'monthly',
        'year' => $year, 'month' => $month, 'sequence' => 1,
        'start_date' => sprintf('%04d-%02d-01', $year, $month), 'end_date' => sprintf('%04d-%02d-28', $year, $month), 'is_closed' => false,
    ]);
}

function opStatusEmployee(Period $period, string $fullName, Branch $branch): Employee
{
    static $seq = 0;
    $seq++;
    $parts = explode(' ', $fullName);

    $employee = Employee::query()->create([
        'employee_code' => 'OPST' . $seq, 'full_name' => $fullName, 'normalized_name' => mb_strtolower($fullName),
        'first_name' => $parts[0] ?? $fullName, 'paternal_last_name' => $parts[1] ?? 'X',
        'is_active' => true, 'source_system' => 'noi',
    ]);

    // Presencia en el roster del periodo (fact_noi_movements) — sin esto,
    // buildEmployeesGestores() nunca produce una fila candidata para este
    // employee_id, aunque tenga gastos/recuperación reales (mismo patrón que
    // ExpenseObservationAttributionTest::makeAttribRosterEmployee()).
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

function opStatusExpense(Period $period, Employee $employee, Branch $branch): Expense
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
        'amount' => 500.0, 'paid_amount' => 500.0, 'expense_date' => now()->format('Y-m-d'),
        'branch_id' => $branch->id, 'employee_id' => $employee->id,
    ]);
}

function opStatusRow(int $employeeId, array $overrides = []): array
{
    return array_merge([
        'name' => 'EMPLEADO OP STATUS', 'branch' => 'ORIZABA', 'pagos' => 0.0, 'bonos' => 0.0, 'descuentos' => 0.0,
        'neto' => 0.0, 'gastos' => 0.0, 'colocacion' => 0.0, 'operaciones' => 0, 'recuperacion' => 0.0,
        'cartera' => 0.0, 'vencida' => 0.0, 'mora' => 0.0, 'ingreso_ebitda_base' => 0.0,
        '_employee_ids' => [$employeeId],
    ], $overrides);
}

// ── CASO A: ingreso>0, gasto=0 → activo ───────────────────────────────────
it('CASO A: income > 0 and expense = 0 => active', function () {
    $period = opStatusPeriodo(2026, 3);
    $branch = Branch::query()->create(['code' => 'ORIZ', 'name' => 'ORIZABA', 'normalized_name' => 'orizaba', 'is_active' => true]);
    $employee = opStatusEmployee($period, 'EMPLEADO CASO A', $branch);
    $row = opStatusRow($employee->id, ['name' => 'EMPLEADO CASO A', 'recuperacion' => 5000.0]);

    $builder = app(RadiographySnapshotBuilder::class);
    $snapshot = opStatusSnapshotFixture($period->id, $row);
    $result = invokeOpStatusMethod($builder, 'applyEmployeeScope', ['dataIds' => [$period->id], 'args' => [$snapshot, $employee->id, [$row], $period, []]]);

    $status = $result['sections']['rotation_detail']['operational_status'];
    expect($status['is_active'])->toBeTrue();
    expect($status['has_income'])->toBeTrue();
    expect($status['has_operational_expense'])->toBeFalse();
});

// ── CASO B: ingreso=0, gasto>0 → activo ───────────────────────────────────
it('CASO B: income = 0 and expense > 0 => active', function () {
    $period = opStatusPeriodo(2026, 3);
    $branch = Branch::query()->create(['code' => 'TULA', 'name' => 'TULA', 'normalized_name' => 'tula', 'is_active' => true]);
    $employee = opStatusEmployee($period, 'EMPLEADO CASO B', $branch);
    opStatusExpense($period, $employee, $branch);
    $row = opStatusRow($employee->id, ['name' => 'EMPLEADO CASO B']);

    $builder = app(RadiographySnapshotBuilder::class);
    $snapshot = opStatusSnapshotFixture($period->id, $row);
    $result = invokeOpStatusMethod($builder, 'applyEmployeeScope', ['dataIds' => [$period->id], 'args' => [$snapshot, $employee->id, [$row], $period, []]]);

    $status = $result['sections']['rotation_detail']['operational_status'];
    expect($status['is_active'])->toBeTrue();
    expect($status['has_income'])->toBeFalse();
    expect($status['has_operational_expense'])->toBeTrue();
});

// ── CASO C: ingreso=0, gasto=0 → inactivo ─────────────────────────────────
it('CASO C: income = 0 and expense = 0 => inactive', function () {
    $period = opStatusPeriodo(2026, 3);
    $branch = Branch::query()->create(['code' => 'CUER', 'name' => 'CUERNAVACA', 'normalized_name' => 'cuernavaca', 'is_active' => true]);
    $employee = opStatusEmployee($period, 'EMPLEADO CASO C', $branch);
    $row = opStatusRow($employee->id, ['name' => 'EMPLEADO CASO C']);

    $builder = app(RadiographySnapshotBuilder::class);
    $snapshot = opStatusSnapshotFixture($period->id, $row);
    $result = invokeOpStatusMethod($builder, 'applyEmployeeScope', ['dataIds' => [$period->id], 'args' => [$snapshot, $employee->id, [$row], $period, []]]);

    $status = $result['sections']['rotation_detail']['operational_status'];
    expect($status['is_active'])->toBeFalse();
});

// ── CASO D: sin actividad PERO con cartera $300,000 → inactivo, cartera intacta ──
it('CASO D: no activity but $300,000 portfolio => operationally inactive, portfolio untouched', function () {
    $period = opStatusPeriodo(2026, 3);
    $branch = Branch::query()->create(['code' => 'ATLI', 'name' => 'ATLIXCO', 'normalized_name' => 'atlixco', 'is_active' => true]);
    $employee = opStatusEmployee($period, 'EMPLEADO CASO D', $branch);
    $row = opStatusRow($employee->id, ['name' => 'EMPLEADO CASO D', 'cartera' => 300000.0, 'vencida' => 45000.0]);

    $builder = app(RadiographySnapshotBuilder::class);
    $snapshot = opStatusSnapshotFixture($period->id, $row);
    $result = invokeOpStatusMethod($builder, 'applyEmployeeScope', ['dataIds' => [$period->id], 'args' => [$snapshot, $employee->id, [$row], $period, []]]);

    $status = $result['sections']['rotation_detail']['operational_status'];
    expect($status['is_active'])->toBeFalse();
    // La cartera NUNCA se borra/pone en cero por el estatus operativo.
    expect((float) $result['summary']['portfolio_total'])->toBe(300000.0);
    expect((float) $result['summary']['overdue_portfolio'])->toBe(45000.0);
});

// ── CASO E: activo mes anterior, sin actividad mes actual => UNA baja operativa ──
it('CASO E: active previous month, no activity this month => exactly one operational baja (transition)', function () {
    $prevPeriod = opStatusPeriodo(2026, 2);
    $period     = opStatusPeriodo(2026, 3);
    $branch = Branch::query()->create(['code' => 'HUAM', 'name' => 'HUAMANTLA', 'normalized_name' => 'huamantla', 'is_active' => true]);
    $employee = opStatusEmployee($period, 'EMPLEADO CASO E', $branch);
    DB::table('fact_noi_movements')->insert([
        'period_id' => $prevPeriod->id, 'employee_id' => $employee->id, 'amount' => 1000, 'quantity' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    EmployeeBranchAssignment::query()->create([
        'employee_id' => $employee->id, 'period_id' => $prevPeriod->id, 'branch_id' => $branch->id,
        'source_type' => SourceType::Manual, 'match_type' => MatchType::Manual,
    ]);

    // Activo el mes anterior (gasto operativo real atribuido).
    opStatusExpense($prevPeriod, $employee, $branch);

    // Sin actividad este mes.
    $row = opStatusRow($employee->id, ['name' => 'EMPLEADO CASO E']);

    $builder = app(RadiographySnapshotBuilder::class);
    $snapshot = opStatusSnapshotFixture($period->id, $row);
    $result = invokeOpStatusMethod($builder, 'applyEmployeeScope', ['dataIds' => [$period->id], 'args' => [$snapshot, $employee->id, [$row], $period, []]]);

    $status = $result['sections']['rotation_detail']['operational_status'];
    expect($status['is_active'])->toBeFalse();
    expect($status['was_active_previous_period'])->toBeTrue();
    expect($status['transition'])->toBe('baja_operativa');
});

// ── CASO F: sigue sin actividad el mes siguiente => NO segunda baja ──────────
it('CASO F: still no activity the following month => NOT a second baja (continues inactive)', function () {
    $prevPeriod = opStatusPeriodo(2026, 2);
    $period     = opStatusPeriodo(2026, 3);
    $branch = Branch::query()->create(['code' => 'IXTL', 'name' => 'IXTLAHUACA', 'normalized_name' => 'ixtlahuaca', 'is_active' => true]);
    $employee = opStatusEmployee($period, 'EMPLEADO CASO F', $branch);
    EmployeeBranchAssignment::query()->create([
        'employee_id' => $employee->id, 'period_id' => $prevPeriod->id, 'branch_id' => $branch->id,
        'source_type' => SourceType::Manual, 'match_type' => MatchType::Manual,
    ]);

    // Sin actividad NI el mes anterior NI este mes.
    $rowPrev = opStatusRow($employee->id, ['name' => 'EMPLEADO CASO F']);
    $row     = opStatusRow($employee->id, ['name' => 'EMPLEADO CASO F']);

    $builder = app(RadiographySnapshotBuilder::class);
    $snapshot = opStatusSnapshotFixture($period->id, $row);
    $result = invokeOpStatusMethod($builder, 'applyEmployeeScope', ['dataIds' => [$period->id], 'args' => [$snapshot, $employee->id, [$row], $period, []]]);

    $status = $result['sections']['rotation_detail']['operational_status'];
    expect($status['is_active'])->toBeFalse();
    expect($status['was_active_previous_period'])->toBeFalse();
    // NO es una baja nueva — sigue inactivo, no repite el conteo.
    expect($status['transition'])->toBe('inactivo_continua');
});

// ── Garantía: Rotación oficial (NOI) e IMSS no se tocan por este cálculo ─────
it('never changes fact_rotacion or the official NOI-based roster while computing operational status', function () {
    $period = opStatusPeriodo(2026, 3);
    $branch = Branch::query()->create(['code' => 'SLPO', 'name' => 'SAN LUIS POTOSI', 'normalized_name' => 'san luis potosi', 'is_active' => true]);
    $employee = opStatusEmployee($period, 'EMPLEADO GUARD', $branch);
    $row = opStatusRow($employee->id, ['name' => 'EMPLEADO GUARD']);

    // period_employee_rosters e is_active_for_period también son la fuente
    // oficial de Rotación/IMSS — deben quedar exactamente igual.
    DB::table('period_employee_rosters')->insert([
        'period_id' => $period->id, 'employee_id' => $employee->id, 'employee_key' => 'empleado guard',
        'nombre_normalizado' => 'empleado guard', 'nombre_original' => 'EMPLEADO GUARD',
        'branch_id' => $branch->id, 'branch_name' => 'SAN LUIS POTOSI', 'is_branch_operativa' => true,
        'source' => 'noi_nomina', 'appears_in_nomina_normal' => true, 'appears_in_nomina_fiscal' => false,
        'is_active_for_period' => true, 'movement_type' => 'activo', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $rotacionAntes = DB::table('fact_rotacion')->where('period_id', $period->id)->count();
    $rosterAntes    = DB::table('period_employee_rosters')->where('period_id', $period->id)->get()->toArray();

    $builder = app(RadiographySnapshotBuilder::class);
    $snapshot = opStatusSnapshotFixture($period->id, $row);
    invokeOpStatusMethod($builder, 'applyEmployeeScope', ['dataIds' => [$period->id], 'args' => [$snapshot, $employee->id, [$row], $period, []]]);

    // Ni una fila nueva/modificada en fact_rotacion (Rotación oficial) ni en
    // period_employee_rosters (fuente de Rotación/IMSS) — el estatus operativo
    // se calcula 100% on-demand, sin escribir nada.
    expect(DB::table('fact_rotacion')->where('period_id', $period->id)->count())->toBe($rotacionAntes);
    expect(DB::table('period_employee_rosters')->where('period_id', $period->id)->get()->toArray())->toEqual($rosterAntes);
});
