<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Test S — check-in único por KR/semana/responsable (aquí: por objective/semana/usuario).
it('a user cannot submit two check-ins for the same objective in the same week', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('San Luis Potosi'), [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));

    $this->actingAs($user)->post(route('okr.check-ins.store', $objective), [
        'main_blocker' => 'Falta de personal', 'corrective_action' => 'Contratar refuerzo',
    ])->assertSessionHasNoErrors();

    expect($objective->checkIns()->count())->toBe(1);

    $this->actingAs($user)->post(route('okr.check-ins.store', $objective), [
        'main_blocker' => 'Segundo intento',
    ])->assertSessionHasErrors('check_in');

    expect($objective->checkIns()->count())->toBe(1); // nunca duplica
});

it('a corrective action with responsible and due date creates a trackable corrective action', function () {
    $user = User::factory()->create();
    $responsible = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Ixtlahuaca'), [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));

    $this->actingAs($user)->post(route('okr.check-ins.store', $objective), [
        'corrective_action' => 'Reforzar cobranza en la sucursal',
        'action_responsible_user_id' => $responsible->id,
        'action_due_date' => now()->addWeek()->toDateString(),
    ])->assertSessionHasNoErrors();

    expect($objective->correctiveActions()->count())->toBe(1);
    $action = $objective->correctiveActions()->first();
    expect($action->responsible_user_id)->toBe($responsible->id);
    expect($action->status)->toBe(\App\Models\OkrCorrectiveAction::STATUS_PENDING);
});

it('an unauthenticated request is redirected, never allowed to check-in', function () {
    $objective = okrDraftObjective(User::factory()->create(), okrBranch('Puebla'), [100.0]);
    $this->post(route('okr.check-ins.store', $objective))->assertRedirect(route('login'));
});
