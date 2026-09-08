<?php

use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Tests punto 39 F/G — bug real corregido 10-sep-2026 (sección 30/31 de la
 * auditoría): `actual_value_snapshot` se armaba ANTES de aplicar
 * `manual_results`, así que el check-in quedaba con el valor ANTERIOR aunque
 * el KR ya estuviera actualizado. Además usaba `kpi_id` como llave — dos KR
 * con el MISMO KPI colisionaban. Ahora se arma DESPUÉS (valores finales) y la
 * llave es `key_result_id` (único por definición).
 */
it('a manual check-in from 70 to 85 leaves KR, weekly snapshot AND the check-in snapshot all showing 85 — never the stale 70', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Cordoba'), [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));
    $kr = $objective->keyResults()->first();
    $kr->update(['current_value' => 70.0]); // simula un resultado previo ya capturado

    $response = $this->actingAs($user)->post(route('okr.check-ins.store', $objective), [
        'manual_results' => [['key_result_id' => $kr->id, 'value' => 85.0]],
    ]);
    $response->assertSessionHasNoErrors();

    // 1. El KR queda en 85.
    $kr->refresh();
    expect((float) $kr->current_value)->toBe(85.0);

    // 2. El snapshot semanal del KR queda en 85 (nunca el 70 anterior).
    $weekSnapshot = $kr->snapshots()->where('week_number', $objective->fresh()->currentWeekNumber())->first();
    expect((float) $weekSnapshot->actual_value)->toBe(85.0);

    // 3. El actual_value_snapshot GUARDADO DENTRO DEL CHECK-IN también queda
    // en 85 — este era exactamente el bug: antes se armaba con el 70 viejo.
    $checkIn = $objective->checkIns()->latest()->first();
    $krKey = "kr_{$kr->id}";
    expect($checkIn->actual_value_snapshot)->toHaveKey($krKey);
    expect((float) $checkIn->actual_value_snapshot[$krKey]['value'])->toBe(85.0);
});

it('two Key Results sharing the SAME KPI never collide in actual_value_snapshot — both are preserved', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Orizaba');
    $kpi = OkrKpi::query()->firstOrCreate(['code' => 'shared_kpi_snapshot_test'], [
        'name' => 'KPI compartido de prueba', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE,
        'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_MANUAL, 'is_active' => true,
    ]);

    $objective = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective con dos KR del mismo KPI',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(8)->toDateString(), 'duration_weeks' => 8,
        'lifecycle_status' => 'active', 'activated_at' => now(),
    ]);
    $krA = $objective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR A (mismo KPI)', 'baseline_value' => 10, 'target_value' => 20, 'weight' => 50, 'baseline_locked_at' => now()]);
    $krB = $objective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR B (mismo KPI)', 'baseline_value' => 30, 'target_value' => 40, 'weight' => 50, 'baseline_locked_at' => now()]);

    $this->actingAs($user)->post(route('okr.check-ins.store', $objective), [
        'manual_results' => [
            ['key_result_id' => $krA->id, 'value' => 15.0],
            ['key_result_id' => $krB->id, 'value' => 35.0],
        ],
    ])->assertSessionHasNoErrors();

    $checkIn = $objective->checkIns()->latest()->first();
    expect($checkIn->actual_value_snapshot)->toHaveKey("kr_{$krA->id}");
    expect($checkIn->actual_value_snapshot)->toHaveKey("kr_{$krB->id}");
    expect((float) $checkIn->actual_value_snapshot["kr_{$krA->id}"]['value'])->toBe(15.0);
    expect((float) $checkIn->actual_value_snapshot["kr_{$krB->id}"]['value'])->toBe(35.0);
    expect((float) $krA->fresh()->current_value)->toBe(15.0);
    expect((float) $krB->fresh()->current_value)->toBe(35.0);
});
