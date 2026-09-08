<?php

use App\Models\User;
use App\Services\Okr\OkrSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Test AD — actualización de Reportería recalcula "actual" sin modificar la baseline.
it('recalculating a key result never modifies its frozen baseline, only current/expected/deviation', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Cordoba'), [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));
    $kr = $objective->keyResults()->first()->fresh();

    $baselineBefore = $kr->baseline_value;
    $lockedAtBefore = $kr->baseline_locked_at;

    $kr->update(['current_value' => 150.0]);
    app(OkrSnapshotService::class)->evaluateKeyResult($kr->fresh());

    $kr->refresh();
    expect((float) $kr->baseline_value)->toBe((float) $baselineBefore);
    expect($kr->baseline_locked_at->equalTo($lockedAtBefore))->toBeTrue();
    expect((float) $kr->current_value)->toBe(150.0);
    expect($kr->actual_progress_percentage)->not->toBeNull();
});

it('the manual "refresh" endpoint recalculates without touching baseline or writing to Reportería', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Tenango del Valle'), [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));
    $kr = $objective->keyResults()->first();
    $baselineBefore = $kr->fresh()->baseline_value;

    $this->actingAs($user)->post(route('okr.refresh', $objective))->assertSessionHasNoErrors();

    expect((float) $kr->fresh()->baseline_value)->toBe((float) $baselineBefore);
});
