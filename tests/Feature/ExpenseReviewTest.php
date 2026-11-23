<?php

use App\Enums\MatchType;
use App\Enums\SourceType;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Expense;
use App\Models\ExpenseReview\ExpenseReviewDecision;
use App\Models\ExpenseReview\ExpenseReviewLog;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\EstadoResultados\Reconciliation\ErpExpenseRepository;
use App\Services\ExpenseReview\ExpenseReviewService;
use App\Services\PeriodCloseGuardService;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Revisión de gastos (23-nov-2026) — capa OPCIONAL de decisiones manuales sobre
 * fact_expenses. Sin decisiones no cambia nada; con decisiones, persisten por huella
 * aunque los importadores borren y reinserten los gastos.
 */
function xrvPeriod(): Period
{
    return Period::query()->create([
        'name' => 'Junio 2026', 'code' => 'M-2026-06-ER', 'type' => 'monthly', 'year' => 2026, 'month' => 6,
        'sequence' => 1, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => false,
    ]);
}

function xrvBranch(string $name): Branch
{
    return Branch::query()->firstOrCreate(
        ['normalized_name' => mb_strtolower($name)],
        ['code' => mb_substr($name, 0, 4), 'name' => $name, 'is_active' => true],
    );
}

function xrvUpload(Period $period, string $code, User $user): ReportUpload
{
    $source = DataSource::query()->firstOrCreate(['code' => $code], ['name' => $code, 'description' => 'x', 'is_active' => true]);

    return ReportUpload::query()->create([
        'period_id' => $period->id, 'data_source_id' => $source->id, 'original_name' => "{$code}.xlsx",
        'stored_path' => "report_uploads/{$code}.xlsx", 'mime_type' => 'application/octet-stream', 'file_size' => 1,
        'uploaded_by' => $user->id, 'uploaded_at' => now(), 'status' => 'processed',
    ]);
}

function xrvEmployee(Period $period, string $name, Branch $branch): Employee
{
    $parts = explode(' ', $name);
    $employee = Employee::query()->create([
        'employee_code' => 'ER'.mt_rand(1000, 9999), 'full_name' => $name, 'normalized_name' => mb_strtolower($name),
        'first_name' => $parts[0], 'paternal_last_name' => $parts[1] ?? 'X', 'is_active' => true, 'source_system' => 'noi',
    ]);
    DB::table('fact_noi_movements')->insert(['period_id' => $period->id, 'employee_id' => $employee->id, 'amount' => 1000, 'quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
    EmployeeBranchAssignment::query()->create([
        'employee_id' => $employee->id, 'period_id' => $period->id, 'branch_id' => $branch->id,
        'source_type' => SourceType::Manual, 'match_type' => MatchType::Manual,
    ]);

    return $employee;
}

/** Simula un importador: inserta (o reinserta) los gastos del archivo. */
function xrvImport(Period $period, ReportUpload $upload, array $rows): array
{
    return array_map(fn ($r) => Expense::query()->create($r + [
        'period_id' => $period->id, 'report_upload_id' => $upload->id, 'paid_amount' => $r['amount'],
    ])->id, $rows);
}

function xrvAdmin(): User
{
    return User::factory()->create(['role' => 'admin', 'access_enabled_at' => now()]);
}

function xrvSetup(): array
{
    $user = xrvAdmin();
    $period = xrvPeriod();
    $cordoba = xrvBranch('CORDOBA');
    $corp = xrvBranch('CORPORATIVO');
    $emp = xrvEmployee($period, 'ANA LOPEZ PEREZ', $cordoba);
    $erp = xrvUpload($period, 'gastos_erp', $user);
    $erpRows = [
        ['category' => 'Gasolina', 'concept' => 'GASOLINA', 'amount' => 500.00, 'expense_date' => '2026-06-10', 'branch_id' => $cordoba->id, 'employee_id' => null, 'observations' => 'Carga semanal | Folio: REQ-1', 'raw_payload' => ['folio' => 'REQ-1']],
        ['category' => 'Gastos Operativos', 'concept' => 'FLYERS Y VINILES', 'amount' => 300.00, 'expense_date' => '2026-06-11', 'branch_id' => $cordoba->id, 'employee_id' => null, 'observations' => 'Diseño | Folio: REQ-2', 'raw_payload' => ['folio' => 'REQ-2']],
        ['category' => 'Gastos Operativos', 'concept' => 'CAJA CHICA', 'amount' => 80.00, 'expense_date' => '2026-06-12', 'branch_id' => null, 'employee_id' => null, 'observations' => null, 'raw_payload' => ['folio' => 'REQ-3']],
    ];
    $ids = xrvImport($period, $erp, $erpRows);

    return compact('user', 'period', 'cordoba', 'corp', 'emp', 'erp', 'erpRows', 'ids');
}

function xrvPost($test, User $user, Period $period, array $payload)
{
    return $test->actingAs($user)->postJson("/historico-general/{$period->id}/revision-gastos/decisiones", $payload);
}

it('lists the expense breakdown with group totals, filters and pagination', function () {
    ['user' => $user, 'period' => $period] = xrvSetup();

    $res = $this->actingAs($user)->getJson("/historico-general/{$period->id}/revision-gastos?filter=todos&per_page=25")
        ->assertOk()
        ->assertJsonPath('pagination.total', 3)
        ->assertJsonPath('summary.total.count', 3)
        ->assertJsonPath('summary.sin_empleado.count', 3)
        ->assertJsonPath('summary.sin_sucursal.count', 1);

    expect($res->json('rows.0.amount'))->toEqual(500)  // orden por monto
        ->and($res->json('rows.0.source_label'))->toBe('ERP')
        ->and($res->json('rows.0.classification.is_opex'))->toBeTrue()
        ->and($res->json('filtered_total'))->toEqual(880);

    $this->actingAs($user)->getJson("/historico-general/{$period->id}/revision-gastos?filter=sin_sucursal")
        ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('rows.0.concept', 'CAJA CHICA');
    $this->actingAs($user)->getJson("/historico-general/{$period->id}/revision-gastos?filter=todos&q=flyers")
        ->assertOk()->assertJsonPath('pagination.total', 1);
});

it('assigns an expense to a collaborator, logs before/after and marks the radiography for regeneration', function () {
    ['user' => $user, 'period' => $period, 'emp' => $emp, 'ids' => $ids] = xrvSetup();
    $summary = PeriodSummary::query()->create(['period_id' => $period->id, 'status' => 'generated']);

    xrvPost($this, $user, $period, ['expense_ids' => [$ids[0]], 'employee_action' => 'asignar', 'employee_id' => $emp->id])
        ->assertOk()->assertJsonPath('saved', 1);

    $row = Expense::query()->find($ids[0]);
    expect($row->employee_id)->toBe($emp->id)
        ->and($row->attribution_method)->toBe('manual')
        ->and($row->employee_review_status)->toBe('asignado')
        ->and($row->branch_id)->not->toBeNull(); // sucursal intacta: decisión independiente

    $log = ExpenseReviewLog::query()->where('fact_expense_id', $ids[0])->first();
    expect($log->user_id)->toBe($user->id)
        ->and($log->before['gasto']['employee_id'])->toBeNull()
        ->and($log->after['gasto']['employee_id'])->toBe($emp->id);

    expect($summary->fresh()->status)->toBe('database_updated');

    $this->actingAs($user)->getJson("/historico-general/{$period->id}/revision-gastos/{$ids[0]}/bitacora")
        ->assertOk()->assertJsonCount(1, 'logs');
});

it('"no pertenece a ningún colaborador" and "sin sucursal (confirmado)" stop counting as pending in the period close guard', function () {
    ['user' => $user, 'period' => $period, 'ids' => $ids] = xrvSetup();
    $guard = app(PeriodCloseGuardService::class);

    $issues = implode(' | ', $guard->canClose($period)['issues']);
    expect($issues)->toContain('Hay 3 gasto(s) sin empleado relacionado.')
        ->and($issues)->toContain('Hay 1 gasto(s) sin sucursal relacionada.');

    xrvPost($this, $user, $period, ['expense_ids' => [$ids[0], $ids[1]], 'employee_action' => 'ninguno'])->assertOk();
    xrvPost($this, $user, $period, ['expense_ids' => [$ids[2]], 'branch_action' => 'ninguna'])->assertOk();

    $issues = implode(' | ', $guard->canClose($period)['issues']);
    expect($issues)->toContain('Hay 1 gasto(s) sin empleado relacionado.')
        ->and($issues)->not->toContain('sin sucursal relacionada');

    $this->actingAs($user)->getJson("/historico-general/{$period->id}/revision-gastos?filter=todos")
        ->assertJsonPath('summary.confirmados_sin_empleado', 2)
        ->assertJsonPath('summary.confirmados_sin_sucursal', 1)
        ->assertJsonPath('summary.sin_empleado.count', 1);
});

it('bulk-applies a decision to every expense matching the current filter', function () {
    ['user' => $user, 'period' => $period] = xrvSetup();

    xrvPost($this, $user, $period, ['all_matching' => true, 'criteria' => ['filter' => 'sin_empleado', 'q' => ''], 'employee_action' => 'ninguno'])
        ->assertOk()->assertJsonPath('saved', 3);

    expect(Expense::query()->where('period_id', $period->id)->where('employee_review_status', 'sin_empleado')->count())->toBe(3);
});

it('CORPORATIVO leaves the branch radiography but stays available to the Estado de Resultados', function () {
    ['user' => $user, 'period' => $period, 'corp' => $corp, 'ids' => $ids] = xrvSetup();
    $calc = app(BranchRadiographyCalculator::class);

    $erpTotalFor = function () use ($calc, $period) {
        $maps = $calc->buildBranchMap();
        $summaries = [];
        foreach (array_unique($maps['operative']) as $suc) {
            $summaries[$suc] = $calc->emptyBranchSummary($suc);
        }
        $unassigned = [];
        $m = new ReflectionMethod($calc, 'accumulateGastos');
        $args = [[$period->id], array_keys($maps['operative']), $maps['operative'], &$summaries, $maps['corporativo'], &$unassigned];
        $m->invokeArgs($calc, $args);

        return array_sum(array_column($summaries, 'gastos_erp_total'));
    };

    expect($erpTotalFor())->toEqual(800.0);

    xrvPost($this, $user, $period, ['expense_ids' => [$ids[1]], 'branch_action' => 'corporativo'])->assertOk();

    expect(Expense::query()->find($ids[1])->branch_id)->toBe($corp->id)
        ->and($erpTotalFor())->toEqual(500.0); // los flyers ya no suman a sucursales

    $erRow = collect(app(ErpExpenseRepository::class)->forDataIds([$period->id]))->firstWhere('id', $ids[1]);
    expect($erRow['universe'])->toBe('corporativo'); // el ER la toma como gasto corporativo

    $this->actingAs($user)->getJson("/historico-general/{$period->id}/revision-gastos?filter=corporativo")
        ->assertJsonPath('pagination.total', 1)
        ->assertJsonPath('rows.0.classification.type', 'corporativo');
});

it('keeps every decision after the source is re-imported (rows deleted and inserted with new ids)', function () {
    ['user' => $user, 'period' => $period, 'emp' => $emp, 'corp' => $corp, 'erp' => $erp, 'erpRows' => $erpRows, 'ids' => $ids] = xrvSetup();

    xrvPost($this, $user, $period, ['expense_ids' => [$ids[0]], 'employee_action' => 'asignar', 'employee_id' => $emp->id])->assertOk();
    xrvPost($this, $user, $period, ['expense_ids' => [$ids[1]], 'branch_action' => 'corporativo', 'employee_action' => 'ninguno'])->assertOk();

    // Reimportación: el importador borra y reinserta (ids nuevos, valores automáticos).
    Expense::query()->where('report_upload_id', $erp->id)->delete();
    $newIds = xrvImport($period, $erp, $erpRows);
    expect(Expense::query()->find($newIds[0])->employee_id)->toBeNull();

    // Mismo punto que usa ReportAnalysisService al terminar cada importación de gastos.
    app(ExpenseReviewService::class)->applyForPeriodSafely($period->id);

    expect(Expense::query()->find($newIds[0])->employee_id)->toBe($emp->id)
        ->and(Expense::query()->find($newIds[1])->branch_id)->toBe($corp->id)
        ->and(Expense::query()->find($newIds[1])->employee_review_status)->toBe('sin_empleado')
        ->and(Expense::query()->find($newIds[2])->employee_review_status)->toBeNull();
    expect(ExpenseReviewDecision::query()->count())->toBe(2);

    // Quitar la decisión devuelve el valor automático.
    xrvPost($this, $user, $period, ['expense_ids' => [$newIds[1]], 'branch_action' => 'automatico', 'employee_action' => 'automatico'])
        ->assertOk()->assertJsonPath('removed', 1);
    $row = Expense::query()->find($newIds[1]);
    expect($row->branch_id)->toBe(xrvBranch('CORDOBA')->id)->and($row->branch_review_status)->toBeNull();
});

it('does not touch a single expense when there are no manual decisions (figures stay identical)', function () {
    ['period' => $period] = xrvSetup();
    $before = DB::table('fact_expenses')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    $stats = app(ExpenseReviewService::class)->applyForPeriod($period->id);

    expect($stats)->toBe(['employee' => 0, 'branch' => 0, 'category' => 0])
        ->and(DB::table('fact_expenses')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($before);
});

it('stores a destination category per concept, re-applies it on the next import and reverts when removed', function () {
    ['user' => $user, 'period' => $period, 'erp' => $erp, 'erpRows' => $erpRows, 'ids' => $ids] = xrvSetup();

    $concepts = $this->actingAs($user)->getJson("/historico-general/{$period->id}/revision-gastos/conceptos")->assertOk();
    $licencia = collect($concepts->json('gastos'))->firstWhere('concept', 'FLYERS Y VINILES');
    expect($licencia['needs_destination'])->toBeTrue()
        ->and($concepts->json('categories'))->toContain('Publicidad');

    $this->actingAs($user)->postJson("/historico-general/{$period->id}/revision-gastos/conceptos", [
        'source_type' => 'erp', 'concept' => 'FLYERS Y VINILES', 'category' => 'Publicidad',
    ])->assertOk();

    expect(Expense::query()->find($ids[1])->category)->toBe('Publicidad')
        ->and(Expense::query()->find($ids[1])->category_before_rule)->toBe('Gastos Operativos');

    Expense::query()->where('report_upload_id', $erp->id)->delete();
    $newIds = xrvImport($period, $erp, $erpRows);
    app(ExpenseReviewService::class)->applyForPeriodSafely($period->id);
    expect(Expense::query()->find($newIds[1])->category)->toBe('Publicidad');

    $this->actingAs($user)->postJson("/historico-general/{$period->id}/revision-gastos/conceptos", [
        'source_type' => 'erp', 'concept' => 'FLYERS Y VINILES', 'category' => null,
    ])->assertOk();
    expect(Expense::query()->find($newIds[1])->category)->toBe('Gastos Operativos')
        ->and(Expense::query()->find($newIds[1])->category_before_rule)->toBeNull();

    $this->actingAs($user)->postJson("/historico-general/{$period->id}/revision-gastos/conceptos", [
        'source_type' => 'erp', 'concept' => 'FLYERS Y VINILES', 'category' => 'Categoría inventada',
    ])->assertStatus(422);
});

it('requires carga.procesar to save decisions (reading only needs the module)', function () {
    ['period' => $period, 'ids' => $ids] = xrvSetup();
    $gerente = User::factory()->create(['role' => 'gerencial', 'access_enabled_at' => now()]);
    DB::table('role_module_access')->where('role', 'gerencial')->delete();
    DB::table('role_action_permissions')->where('role', 'gerencial')->delete();
    DB::table('role_module_access')->insert(['role' => 'gerencial', 'module' => 'carga', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('role_action_permissions')->insert(['role' => 'gerencial', 'module' => 'carga', 'action' => 'subir', 'created_at' => now(), 'updated_at' => now()]);
    cache()->flush();

    $this->actingAs($gerente)->getJson("/historico-general/{$period->id}/revision-gastos")->assertOk();
    xrvPost($this, $gerente, $period, ['expense_ids' => [$ids[0]], 'employee_action' => 'ninguno'])->assertForbidden();
    $this->actingAs($gerente)->postJson("/historico-general/{$period->id}/revision-gastos/conceptos", [
        'source_type' => 'erp', 'concept' => 'GASOLINA', 'category' => 'Gasolina',
    ])->assertForbidden();

    expect(ExpenseReviewDecision::query()->count())->toBe(0);
});

it('rejects changes on a closed period', function () {
    ['user' => $user, 'period' => $period, 'ids' => $ids] = xrvSetup();
    $period->update(['is_closed' => true]);

    xrvPost($this, $user, $period, ['expense_ids' => [$ids[0]], 'employee_action' => 'ninguno'])->assertStatus(422);
});
