<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Tests punto 30 A/B/C — bug corregido 09-sep-2026: el check-in ahora captura
 * `manual_results` para KR manuales/híbridos (antes no existía ningún camino
 * real para actualizar current_value de un KPI manual).
 */
it('a manual result in the check-in updates the key result current_value and creates its weekly snapshot', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Cordoba'), [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));
    $kr = $objective->keyResults()->first();
    expect($kr->fresh()->current_value)->toBeNull(); // manual — nunca se capturó todavía

    $response = $this->actingAs($user)->post(route('okr.check-ins.store', $objective), [
        'manual_results' => [['key_result_id' => $kr->id, 'value' => 150.0]],
    ]);

    $response->assertSessionHasNoErrors();
    $kr->refresh();
    expect((float) $kr->current_value)->toBe(150.0);
    expect($kr->last_manual_input_by)->toBe($user->id);
    expect($kr->last_manual_input_at)->not->toBeNull();

    $snapshot = $kr->snapshots()->where('week_number', $objective->fresh()->currentWeekNumber())->first();
    expect($snapshot)->not->toBeNull();
    expect((float) $snapshot->actual_value)->toBe(150.0);
    expect($snapshot->source_quality)->toBe('manual_checkin');
});

it('rejects a manual_result targeting an automatic KPI — its value can only come from Reportería', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Atlixco');
    $kpi = okrAutomaticKpiForTest();

    $objective = \App\Models\OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective con KPI automático de prueba',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(8)->toDateString(), 'duration_weeks' => 8,
        'lifecycle_status' => 'active', 'activated_at' => now(),
    ]);
    $kr = $objective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR automático', 'baseline_value' => 100, 'target_value' => 200, 'weight' => 100, 'baseline_locked_at' => now()]);

    $response = $this->actingAs($user)->post(route('okr.check-ins.store', $objective), [
        'manual_results' => [['key_result_id' => $kr->id, 'value' => 999]],
    ]);

    $response->assertSessionHasErrors();
    expect((float) $kr->fresh()->current_value)->not->toBe(999.0);
});

it('rejects a manual_result whose key_result_id belongs to a DIFFERENT Objective — 422/validation error', function () {
    $user = User::factory()->create();
    $objectiveA = okrDraftObjective($user, okrBranch('Huamantla'), [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objectiveA));
    $objectiveB = okrDraftObjective($user, okrBranch('Tula'), [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objectiveB));
    $krFromB = $objectiveB->keyResults()->first();

    $response = $this->actingAs($user)->post(route('okr.check-ins.store', $objectiveA), [
        'manual_results' => [['key_result_id' => $krFromB->id, 'value' => 50]],
    ]);

    $response->assertSessionHasErrors();
    expect($krFromB->fresh()->current_value)->toBeNull(); // sin cambios — nunca se tocó
});

function okrAutomaticKpiForTest(string $providerKey = 'reporteria.ebitda'): \App\Models\OkrKpi
{
    return \App\Models\OkrKpi::query()->firstOrCreate(['code' => 'auto_test_' . str_replace('.', '_', $providerKey)], [
        'name' => 'KPI automático de prueba', 'unit' => 'currency', 'type' => \App\Models\OkrKpi::TYPE_CUMULATIVE,
        'direction' => \App\Models\OkrKpi::DIRECTION_INCREASE, 'automation' => \App\Models\OkrKpi::AUTOMATION_AUTOMATIC,
        'provider_key' => $providerKey, 'is_active' => true,
    ]);
}
