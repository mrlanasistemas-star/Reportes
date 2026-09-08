<?php

use App\Models\OkrKpi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Test AC — KPI inactivo no puede asignarse a un KR nuevo.
it('an inactive KPI cannot be assigned to a new key result', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Atlacomulco');
    $inactiveKpi = OkrKpi::query()->create([
        'code' => 'inactive_kpi', 'name' => 'KPI Inactivo', 'unit' => 'currency',
        'type' => OkrKpi::TYPE_CUMULATIVE, 'direction' => OkrKpi::DIRECTION_INCREASE,
        'automation' => OkrKpi::AUTOMATION_MANUAL, 'is_active' => false,
    ]);

    $objective = \App\Models\OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective de prueba con KPI inactivo',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(), 'duration_weeks' => 4,
        'lifecycle_status' => 'draft',
    ]);

    $this->actingAs($user)->post(route('okr.key-results.store', $objective), [
        'kpi_id' => $inactiveKpi->id, 'description' => 'KR con KPI inactivo', 'target_value' => 100, 'weight' => 100,
    ])->assertSessionHasErrors('kpi_id');
});

it('provider_key on a KPI must be one of the registered providers — never free text', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('okr.kpis.store'), [
        'code' => 'fake_kpi', 'name' => 'KPI Falso', 'unit' => 'currency', 'type' => 'cumulative',
        'direction' => 'increase', 'automation' => 'automatic', 'provider_key' => 'App\\Malicious\\EvilClass::hack',
    ])->assertSessionHasErrors('provider_key');
});
