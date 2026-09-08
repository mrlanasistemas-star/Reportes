<?php

use App\Models\OkrObjective;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Test punto 30 S (integración) — el flujo real de creación usa el end_date inclusivo correcto. */
it('creating an objective via the real store() flow computes the inclusive end_date, not off-by-one', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Cordoba');
    $kpi = okrManualKpi('end_date_creation_kpi');

    $this->actingAs($user)->post(route('okr.store'), [
        'scope_type' => 'branch', 'branch_id' => $branch->id,
        'title' => 'Objective de prueba de fecha de término',
        'start_date' => '2026-09-01', 'duration_weeks' => 8,
        'key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR de prueba', 'baseline_value' => 10, 'target_value' => 20, 'weight' => 100]],
    ])->assertSessionHasNoErrors();

    $objective = OkrObjective::query()->where('title', 'Objective de prueba de fecha de término')->firstOrFail();
    expect($objective->end_date->toDateString())->toBe('2026-10-26');
});
