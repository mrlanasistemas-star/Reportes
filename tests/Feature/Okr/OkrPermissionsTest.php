<?php

use App\Models\OkrKpi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Test AT#12 — corrección 08-sep-2026: antes CUALQUIER usuario autenticado
 * tenía okr.admin/okr.kpi.manage/okr.delete (Gate::define(...fn ($user) =>
 * $user !== null)). Ahora se reutiliza la columna real `users.role`
 * (default 'admin' — ver migración original de `users`, no rompe usuarios
 * existentes) para exigir role==='admin' en los permisos administrativos.
 */
it('a non-admin user cannot manage the KPI catalog', function () {
    $user = User::factory()->create(['role' => 'colaborador']);

    $this->actingAs($user)->get(route('okr.kpis.index'))->assertForbidden();
    $this->actingAs($user)->post(route('okr.kpis.store'), [
        'code' => 'blocked_kpi', 'name' => 'KPI bloqueado', 'unit' => 'currency',
        'type' => 'cumulative', 'direction' => 'increase', 'automation' => 'manual',
    ])->assertForbidden();
});

it('an admin user (default role) can manage the KPI catalog', function () {
    $admin = User::factory()->create(); // role default = 'admin'

    $this->actingAs($admin)->get(route('okr.kpis.index'))->assertOk();
});

it('a non-admin user cannot delete an objective', function () {
    $admin = User::factory()->create();
    $colaborador = User::factory()->create(['role' => 'colaborador']);
    $objective = okrDraftObjective($admin, okrBranch('Atlacomulco'), [100.0]);

    $this->actingAs($colaborador)->delete(route('okr.destroy', $objective))->assertForbidden();
    expect($objective->fresh())->not->toBeNull();
});

it('a non-admin user can still perform normal OKR operations (view/create/check-in)', function () {
    $colaborador = User::factory()->create(['role' => 'colaborador']);

    $this->actingAs($colaborador)->get(route('okr.dashboard'))->assertOk();
    $this->actingAs($colaborador)->get(route('okr.create'))->assertOk();
});
