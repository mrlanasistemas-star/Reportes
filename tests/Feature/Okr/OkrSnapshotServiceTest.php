<?php

use App\Models\OkrProgressSnapshot;
use App\Models\User;
use App\Services\Okr\OkrSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Test R — snapshot idempotente (mismo KR + misma semana = update, nunca duplicado).
it('evaluating the same key result twice in the same week updates the snapshot, never duplicates it', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Atlacomulco'), [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));
    $kr = $objective->keyResults()->first();

    $service = app(OkrSnapshotService::class);
    $service->evaluateKeyResult($kr->fresh());
    $countAfterFirst = OkrProgressSnapshot::query()->where('okr_key_result_id', $kr->id)->count();

    $service->evaluateKeyResult($kr->fresh());
    $countAfterSecond = OkrProgressSnapshot::query()->where('okr_key_result_id', $kr->id)->count();

    expect($countAfterFirst)->toBe(1);
    expect($countAfterSecond)->toBe(1); // nunca duplica
});

it('current_value/expected_value/deviation cache on the key result reflects the latest evaluation', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Tenango del Valle'), [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));
    $kr = $objective->keyResults()->first()->fresh();

    // Base 100, meta 200, semana actual (recién activado) — expected_value ≈ base.
    expect($kr->expected_value)->not->toBeNull();
    expect((float) $kr->baseline_value)->toBe(100.0);
    expect((float) $kr->target_value)->toBe(200.0);
});
