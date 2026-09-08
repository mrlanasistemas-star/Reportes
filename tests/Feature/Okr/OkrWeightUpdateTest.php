<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Tests AT#5/AT#6 — bug corregido 08-sep-2026: updateGoal() ahora revalida la
 * suma de pesos ANTES de guardar (antes solo se validaba al crear/activar).
 */
it('rejects a weight change on an active objective that would push the total away from 100%', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Ixtlahuaca'), [60.0, 40.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));
    $kr = $objective->keyResults()->first(); // peso 60 → subir a 80 dejaría 80+40=120

    $response = $this->actingAs($user)->put(route('okr.goal.update', $objective), [
        'key_result_id' => $kr->id, 'weight' => 80.0, 'reason' => 'Prueba de rechazo por sobre-ponderación.',
    ]);

    $response->assertSessionHasErrors('weight');
    expect((float) $kr->fresh()->weight)->toBe(60.0); // nada se guarda — ni siquiera parcialmente
});

it('accepts a coordinated weight change that keeps the total exactly at 100%', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('San Luis Potosi'), [60.0, 40.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));
    [$krA, $krB] = $objective->keyResults()->orderBy('id')->get();

    // Reasignación coordinada: KR B ya bajó de 40 a 30 (ej. corrección previa) —
    // ahora KR A sube de 60 a 70 para cerrar en 100% exacto (70 + 30 = 100).
    $krB->update(['weight' => 30.0]);

    $response = $this->actingAs($user)->put(route('okr.goal.update', $objective), [
        'key_result_id' => $krA->id, 'weight' => 70.0, 'reason' => 'Reasignación de ponderación coordinada entre KR.',
    ]);

    $response->assertSessionHasNoErrors();
    expect((float) $krA->fresh()->weight)->toBe(70.0);
    expect(app(\App\Services\Okr\OkrWeightValidator::class)->totalWeight($objective->fresh()))->toBe(100.0);
});
