<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\OkrAlert;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Cierre real OKR (05-oct-2026) — identidad persistente User↔Employee +
 * scoping completo. Juan y Pedro son colaboradores REALES, cada uno dueño de
 * SU Objective individual (employee_id), ambos con el MISMO responsable
 * administrativo (Gerente) — el escenario exacto que exponía el bug: antes
 * de este cierre, Juan NO podía ver su propio Objective porque
 * responsible_user_id apuntaba al gerente, no a él.
 */
function scopingBranch(): Branch
{
    return Branch::query()->create(['code' => 'SCOPE', 'name' => 'SUCURSAL SCOPING ' . uniqid(), 'normalized_name' => 'sucursal scoping ' . uniqid(), 'is_active' => true]);
}

function scopingKpi(): OkrKpi
{
    return OkrKpi::query()->create([
        'name' => 'Colocación', 'code' => 'scoping_kpi_' . uniqid(), 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE,
        'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_MANUAL, 'is_active' => true,
    ]);
}

/**
 * @return array{0: Employee, 1: Employee, 2: User, 3: User, 4: User, 5: OkrObjective, 6: OkrObjective}
 */
function scopingScenario(): array
{
    $branch = scopingBranch();
    $kpi = scopingKpi();

    $juanEmployee = Employee::query()->create(['full_name' => 'JUAN SCOPING GESTOR', 'normalized_name' => 'juan scoping gestor ' . uniqid(), 'is_active' => true]);
    $pedroEmployee = Employee::query()->create(['full_name' => 'PEDRO SCOPING GESTOR', 'normalized_name' => 'pedro scoping gestor ' . uniqid(), 'is_active' => true]);

    $gerente = User::factory()->create(['role' => 'gerencial', 'access_enabled_at' => now()]);
    $juanUser = User::factory()->create(['role' => 'colaborador', 'employee_id' => $juanEmployee->id, 'access_enabled_at' => now()]);
    $pedroUser = User::factory()->create(['role' => 'colaborador', 'employee_id' => $pedroEmployee->id, 'access_enabled_at' => now()]);

    $juanObjective = OkrObjective::query()->create([
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $juanEmployee->id,
        'title' => 'Objective individual de Juan', 'responsible_user_id' => $gerente->id, 'created_by' => $gerente->id,
        'start_date' => now()->subWeek(), 'end_date' => now()->addWeeks(3), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $juanObjective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR Juan', 'baseline_value' => 0, 'target_value' => 500_000, 'weight' => 100, 'baseline_locked_at' => now()]);

    $pedroObjective = OkrObjective::query()->create([
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $pedroEmployee->id,
        'title' => 'Objective individual de Pedro', 'responsible_user_id' => $gerente->id, 'created_by' => $gerente->id,
        'start_date' => now()->subWeek(), 'end_date' => now()->addWeeks(3), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $pedroObjective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR Pedro', 'baseline_value' => 0, 'target_value' => 600_000, 'weight' => 100, 'baseline_locked_at' => now()]);

    return [$juanEmployee, $pedroEmployee, $gerente, $juanUser, $pedroUser, $juanObjective, $pedroObjective];
}

// ── 14 — identidad real: dueño por employee_id, nunca por responsible_user_id a secas ──
it('14 — Juan can open HIS OWN individual Objective even though responsible_user_id is the manager, and gets 403 on Pedro\'s', function () {
    [, , , $juanUser, $pedroUser, $juanObjective, $pedroObjective] = scopingScenario();

    $this->actingAs($juanUser)->get(route('okr.show', $juanObjective))->assertOk();
    $this->actingAs($juanUser)->get(route('okr.show', $pedroObjective))->assertForbidden();

    $this->actingAs($pedroUser)->get(route('okr.show', $pedroObjective))->assertOk();
    $this->actingAs($pedroUser)->get(route('okr.show', $juanObjective))->assertForbidden();
});

it('14 — the manager (gerencial) can open BOTH objectives', function () {
    [, , $gerente, , , $juanObjective, $pedroObjective] = scopingScenario();

    $this->actingAs($gerente)->get(route('okr.show', $juanObjective))->assertOk();
    $this->actingAs($gerente)->get(route('okr.show', $pedroObjective))->assertOk();
});

// ── 15 — dashboard ──
it('15 — Juan\'s dashboard only contains his own Objective, cards computed only from it', function () {
    [, , , $juanUser, , $juanObjective, $pedroObjective] = scopingScenario();

    $page = $this->actingAs($juanUser)->get(route('okr.dashboard'))->assertOk()->viewData('page')['props'];

    $titles = collect($page['objectives'])->pluck('title');
    expect($titles)->toContain('Objective individual de Juan')
        ->and($titles)->not->toContain('Objective individual de Pedro');

    expect($page['cards']['active'])->toBe(1); // solo el de Juan, nunca el de Pedro también
});

// ── 16 — lookup ──
it('16 — Juan\'s objectives-lookup only returns his own, searching Pedro\'s exact title returns nothing', function () {
    [, , , $juanUser, , $juanObjective, $pedroObjective] = scopingScenario();

    $resAll = $this->actingAs($juanUser)->getJson(route('okr.objectives.lookup'))->assertOk()->json('objectives');
    expect(collect($resAll)->pluck('title'))->toContain('Objective individual de Juan')
        ->not->toContain('Objective individual de Pedro');

    $resSearch = $this->actingAs($juanUser)
        ->getJson(route('okr.objectives.lookup', ['search' => 'Objective individual de Pedro']))
        ->assertOk()->json('objectives');
    expect($resSearch)->toBeEmpty();
});

// ── 17 — histórico ──
it('17 — closed objectives: Juan only sees his own in history, Pedro only his, the manager sees both', function () {
    [, , $gerente, $juanUser, $pedroUser, $juanObjective, $pedroObjective] = scopingScenario();

    $juanObjective->update(['lifecycle_status' => OkrObjective::STATUS_CLOSED, 'closed_at' => now(), 'final_status' => OkrObjective::FINAL_COMPLETED]);
    $pedroObjective->update(['lifecycle_status' => OkrObjective::STATUS_CLOSED, 'closed_at' => now(), 'final_status' => OkrObjective::FINAL_COMPLETED]);

    $juanHistory = $this->actingAs($juanUser)->get(route('okr.history'))->assertOk()->viewData('page')['props']['objectives']['data'];
    expect(collect($juanHistory)->pluck('title'))->toContain('Objective individual de Juan')->not->toContain('Objective individual de Pedro');

    $pedroHistory = $this->actingAs($pedroUser)->get(route('okr.history'))->assertOk()->viewData('page')['props']['objectives']['data'];
    expect(collect($pedroHistory)->pluck('title'))->toContain('Objective individual de Pedro')->not->toContain('Objective individual de Juan');

    $managerHistory = $this->actingAs($gerente)->get(route('okr.history'))->assertOk()->viewData('page')['props']['objectives']['data'];
    expect(collect($managerHistory)->pluck('title'))->toContain('Objective individual de Juan')->toContain('Objective individual de Pedro');
});

// ── 18 — alertas ──
it('18 — Juan only sees his own alerts, and gets 403 marking Pedro\'s alert as read (which stays unread)', function () {
    [, , , $juanUser, , $juanObjective, $pedroObjective] = scopingScenario();

    $juanAlert = OkrAlert::query()->create(['okr_objective_id' => $juanObjective->id, 'type' => OkrAlert::TYPE_RISK, 'message' => 'Alerta de Juan', 'dedupe_key' => 'scoping-juan-' . uniqid()]);
    $pedroAlert = OkrAlert::query()->create(['okr_objective_id' => $pedroObjective->id, 'type' => OkrAlert::TYPE_RISK, 'message' => 'Alerta de Pedro', 'dedupe_key' => 'scoping-pedro-' . uniqid()]);

    $alerts = $this->actingAs($juanUser)->getJson(route('okr.alerts.index'))->assertOk()->json('alerts');
    expect(collect($alerts)->pluck('id'))->toContain($juanAlert->id)->not->toContain($pedroAlert->id);

    $this->actingAs($juanUser)->post(route('okr.alerts.read', $pedroAlert))->assertForbidden();
    expect($pedroAlert->fresh()->read_at)->toBeNull();

    $this->actingAs($juanUser)->post(route('okr.alerts.read', $juanAlert))->assertRedirect();
    expect($juanAlert->fresh()->read_at)->not->toBeNull();
});

// ── 19 — check-in / evidencia del dueño ──
it('19 — Juan can check-in on HIS OWN Objective even though responsible_user_id is the manager, but not on Pedro\'s', function () {
    [, , , $juanUser, , $juanObjective, $pedroObjective] = scopingScenario();

    $this->actingAs($juanUser)->post(route('okr.check-ins.store', $juanObjective), [
        'main_blocker' => null, 'corrective_action' => null,
    ])->assertRedirect(); // sin error de autorización (422/403) — el dueño SÍ puede

    expect($juanObjective->checkIns()->where('user_id', $juanUser->id)->exists())->toBeTrue();

    $this->actingAs($juanUser)->post(route('okr.check-ins.store', $pedroObjective), [
        'main_blocker' => null, 'corrective_action' => null,
    ])->assertForbidden();
});

it('19 — Juan can upload evidence on his own Objective, but not on Pedro\'s', function () {
    [, , , $juanUser, , $juanObjective, $pedroObjective] = scopingScenario();

    expect($juanUser->can('uploadEvidence', $juanObjective))->toBeTrue();
    expect($juanUser->can('uploadEvidence', $pedroObjective))->toBeFalse();
});

// ── ownership NUNCA amplía privilegios administrativos ──
it('owning via employee_id does NOT grant update/assign/delete — only admin/responsible/gerencial keep those', function () {
    [, , , $juanUser, , $juanObjective] = scopingScenario();

    expect($juanUser->can('update', $juanObjective))->toBeFalse()
        ->and($juanUser->can('assign', $juanObjective))->toBeFalse()
        ->and($juanUser->can('delete', $juanObjective))->toBeFalse();
});
