<?php

use App\Models\OkrObjective;
use App\Models\OkrProgressSnapshot;
use App\Models\User;
use App\Services\Okr\OkrClosingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Test X/Y — cierre automático conserva histórico; OKR cerrado no pierde snapshots.
it('closeDue() closes an overdue objective, classifies the final result, and never deletes snapshots', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Orizaba'), [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));
    $kr = $objective->keyResults()->first();

    // Simula cumplimiento total (current = target) para que clasifique "completed".
    $kr->update(['current_value' => 200.0]);
    // Forzar vencimiento.
    $objective->update(['end_date' => now()->subDay()->toDateString()]);

    $service = app(OkrClosingService::class);
    $closedCount = $service->closeDue();

    expect($closedCount)->toBe(1);
    $objective->refresh();
    expect($objective->lifecycle_status)->toBe(OkrObjective::STATUS_CLOSED);
    expect($objective->final_status)->toBe(OkrObjective::FINAL_COMPLETED);
    expect($objective->closed_at)->not->toBeNull();

    // Snapshots conservados — nunca borrados al cerrar.
    expect(OkrProgressSnapshot::query()->where('okr_key_result_id', $kr->id)->count())->toBeGreaterThan(0);
});

it('close() is idempotent — closing an already-closed objective does not re-classify or error', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Cuernavaca'), [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));

    $service = app(OkrClosingService::class);
    $first = $service->close($objective);
    $finalStatusAfterFirst = $first->final_status;

    $second = $service->close($objective->fresh());
    expect($second->final_status)->toBe($finalStatusAfterFirst);
    expect($second->lifecycle_status)->toBe(OkrObjective::STATUS_CLOSED);
});

it('classifies not_completed when compliance is low', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Ixtlahuaca'), [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));
    $kr = $objective->keyResults()->first();
    $kr->update(['current_value' => 100.0]); // sin avance — 0% de cumplimiento

    $objective = app(OkrClosingService::class)->close($objective);
    expect($objective->final_status)->toBe(OkrObjective::FINAL_NOT_COMPLETED);
});
