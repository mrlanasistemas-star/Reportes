<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Tests punto 30 D/E — bug de diseño corregido 09-sep-2026: antes solo se
 * podía cambiar UN Key Result a la vez (updateGoal()), lo que hacía
 * IMPOSIBLE mover peso de un KR a otro (60/40 → 70/30) en un flujo de
 * usuario real. PUT /okr/{objective}/weights recibe TODOS los pesos juntos.
 */
it('redistributes weights from 60/40 to 70/30 in a single batch call', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('San Luis Potosi'), [60.0, 40.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));
    [$krA, $krB] = $objective->keyResults()->orderBy('id')->get();

    $response = $this->actingAs($user)->put(route('okr.weights.update', $objective), [
        'weights' => [
            ['key_result_id' => $krA->id, 'weight' => 70.0],
            ['key_result_id' => $krB->id, 'weight' => 30.0],
        ],
        'reason' => 'Reasignación de prioridad hacia el primer KR.',
    ]);

    $response->assertSessionHasNoErrors();
    expect((float) $krA->fresh()->weight)->toBe(70.0);
    expect((float) $krB->fresh()->weight)->toBe(30.0);

    $log = \App\Models\OkrAuditLog::query()->where('auditable_type', 'key_result')->where('auditable_id', $krA->id)->where('field', 'weight')->first();
    expect($log)->not->toBeNull();
    expect($log->reason)->toBe('Reasignación de prioridad hacia el primer KR.');
});

it('rolls back the entire batch when the total is not exactly 100% — nothing gets saved', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Orizaba'), [60.0, 40.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));
    [$krA, $krB] = $objective->keyResults()->orderBy('id')->get();

    $response = $this->actingAs($user)->put(route('okr.weights.update', $objective), [
        'weights' => [
            ['key_result_id' => $krA->id, 'weight' => 70.0],
            ['key_result_id' => $krB->id, 'weight' => 40.0], // 110 total
        ],
        'reason' => 'Intento inválido.',
    ]);

    $response->assertSessionHasErrors('weights');
    expect((float) $krA->fresh()->weight)->toBe(60.0);
    expect((float) $krB->fresh()->weight)->toBe(40.0);
});

it('rejects a batch that omits a Key Result of the Objective — must cover all of them', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Ixtlahuaca'), [60.0, 40.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));
    $krA = $objective->keyResults()->first();

    $response = $this->actingAs($user)->put(route('okr.weights.update', $objective), [
        'weights' => [['key_result_id' => $krA->id, 'weight' => 100.0]],
        'reason' => 'Omite el segundo KR.',
    ]);

    $response->assertSessionHasErrors('weights');
});
