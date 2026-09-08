<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Tests punto 30 M/N — bug corregido 09-sep-2026: antes CUALQUIER autenticado
 * podía modificar/asignar CUALQUIER OKR (Gate global sin contexto). Ahora
 * OkrObjectivePolicy exige admin o ser el responsable del propio Objective.
 */
it('a non-admin, non-responsible user cannot update the goal/weight of someone else\'s objective', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create(['role' => 'colaborador']);
    $objective = okrDraftObjective($owner, okrBranch('Cordoba'), [100.0]);
    $this->actingAs($owner)->post(route('okr.activate', $objective));
    $kr = $objective->keyResults()->first();

    $response = $this->actingAs($stranger)->put(route('okr.goal.update', $objective), [
        'key_result_id' => $kr->id, 'target_value' => 999, 'reason' => 'Intento no autorizado.',
    ]);

    $response->assertForbidden();
    expect((float) $kr->fresh()->target_value)->not->toBe(999.0);
});

it('a non-admin, non-responsible user cannot activate someone else\'s objective', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create(['role' => 'colaborador']);
    $objective = okrDraftObjective($owner, okrBranch('Orizaba'), [100.0]);

    $this->actingAs($stranger)->post(route('okr.activate', $objective))->assertForbidden();
    expect($objective->fresh()->lifecycle_status)->toBe(\App\Models\OkrObjective::STATUS_DRAFT);
});

it('the responsible of an objective CAN check-in on it even without the admin role', function () {
    $admin = User::factory()->create();
    $responsible = User::factory()->create(['role' => 'colaborador']);
    $objective = okrDraftObjective($admin, okrBranch('Tula'), [100.0]);
    $objective->update(['responsible_user_id' => $responsible->id]);
    $this->actingAs($admin)->post(route('okr.activate', $objective));

    $this->actingAs($responsible)->post(route('okr.check-ins.store', $objective), [
        'main_blocker' => 'Prueba de responsable.',
    ])->assertSessionHasNoErrors();

    expect($objective->checkIns()->where('user_id', $responsible->id)->exists())->toBeTrue();
});

it('the responsible of an objective can update its own goal, but cannot activate it (assignment stays admin-only)', function () {
    $admin = User::factory()->create();
    $responsible = User::factory()->create(['role' => 'colaborador']);
    $objective = okrDraftObjective($admin, okrBranch('San Luis Potosi'), [100.0]);
    $objective->update(['responsible_user_id' => $responsible->id]);

    // Activar sigue siendo del admin, ni siquiera el responsable puede.
    $this->actingAs($responsible)->post(route('okr.activate', $objective))->assertForbidden();

    $this->actingAs($admin)->post(route('okr.activate', $objective));
    $kr = $objective->keyResults()->first();

    $this->actingAs($responsible)->put(route('okr.goal.update', $objective), [
        'key_result_id' => $kr->id, 'target_value' => 250, 'reason' => 'Ajuste del propio responsable.',
    ])->assertSessionHasNoErrors();
    expect((float) $kr->fresh()->target_value)->toBe(250.0);
});
