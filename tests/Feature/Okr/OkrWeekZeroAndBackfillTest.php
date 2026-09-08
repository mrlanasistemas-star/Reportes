<?php

use App\Models\OkrObjective;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\Okr\OkrSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Test punto 30 H — nunca se guarda un snapshot de semana 0. */
it('never creates a progress snapshot for week 0 — an objective that has not started yet', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Cordoba');
    $kpi = okrManualKpi('week_zero_kpi');

    // start_date en el FUTURO — currentWeekNumber() debe ser 0.
    $objective = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective que todavía no inicia',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => now()->addWeeks(2)->toDateString(), 'end_date' => now()->addWeeks(10)->toDateString(), 'duration_weeks' => 8,
        'lifecycle_status' => 'active', 'activated_at' => now(),
    ]);
    $kr = $objective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR futuro', 'baseline_value' => 100, 'target_value' => 200, 'weight' => 100, 'baseline_locked_at' => now()]);

    expect($objective->currentWeekNumber())->toBe(0);

    app(OkrSnapshotService::class)->evaluateKeyResult($kr->fresh());

    expect($kr->snapshots()->count())->toBe(0);
    expect($kr->snapshots()->where('week_number', 0)->exists())->toBeFalse();
});

/** Test punto 30 I — backfillMissingWeeks() rellena huecos históricos sin duplicar. */
it('backfillMissingWeeks creates the missing weekly snapshots for an automatic KPI without duplicating existing ones', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Tenango del Valle');
    $kpi = \App\Models\OkrKpi::query()->firstOrCreate(['code' => 'backfill_test_kpi'], [
        'name' => 'KPI automático backfill', 'unit' => 'currency', 'type' => \App\Models\OkrKpi::TYPE_CUMULATIVE,
        'direction' => \App\Models\OkrKpi::DIRECTION_INCREASE, 'automation' => \App\Models\OkrKpi::AUTOMATION_AUTOMATIC,
        'provider_key' => 'reporteria.ebitda', 'is_active' => true,
    ]);

    // Objective de 4 semanas, iniciado hace 3 semanas → semana actual = 4.
    $startDate = now()->subWeeks(3)->startOfDay();
    $objective = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective con huecos de backfill',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => $startDate->toDateString(), 'end_date' => $startDate->copy()->addDays(4 * 7 - 1)->toDateString(), 'duration_weeks' => 4,
        'lifecycle_status' => 'active', 'activated_at' => now(),
    ]);
    $kr = $objective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR con huecos', 'baseline_value' => 100, 'target_value' => 200, 'weight' => 100, 'baseline_locked_at' => now()]);

    // Solo existe el snapshot de la semana ACTUAL (4) — simula que el cron
    // corrió hoy pero nunca corrió las semanas 1-3.
    app(OkrSnapshotService::class)->evaluateKeyResult($kr->fresh());
    expect($kr->snapshots()->count())->toBe(1);
    expect($kr->snapshots()->pluck('week_number')->all())->toBe([4]);

    $created = app(OkrSnapshotService::class)->backfillMissingWeeks($objective->fresh());

    expect($created)->toBe(3); // semanas 1, 2 y 3
    expect($kr->snapshots()->count())->toBe(4);
    expect($kr->snapshots()->pluck('week_number')->sort()->values()->all())->toBe([1, 2, 3, 4]);

    // Nunca duplica — correr de nuevo no crea nada más.
    $createdAgain = app(OkrSnapshotService::class)->backfillMissingWeeks($objective->fresh());
    expect($createdAgain)->toBe(0);
    expect($kr->snapshots()->count())->toBe(4);
});

it('backfilled snapshots never overwrite the live cache of the current week', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Miacatlan');
    $kpi = \App\Models\OkrKpi::query()->firstOrCreate(['code' => 'backfill_cache_test'], [
        'name' => 'KPI automático backfill cache', 'unit' => 'currency', 'type' => \App\Models\OkrKpi::TYPE_CUMULATIVE,
        'direction' => \App\Models\OkrKpi::DIRECTION_INCREASE, 'automation' => \App\Models\OkrKpi::AUTOMATION_AUTOMATIC,
        'provider_key' => 'reporteria.opex', 'is_active' => true,
    ]);
    $startDate = now()->subWeeks(2)->startOfDay();
    $objective = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective backfill no pisa cache',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => $startDate->toDateString(), 'end_date' => $startDate->copy()->addDays(4 * 7 - 1)->toDateString(), 'duration_weeks' => 4,
        'lifecycle_status' => 'active', 'activated_at' => now(),
    ]);
    $kr = $objective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR', 'baseline_value' => 100, 'target_value' => 200, 'weight' => 100, 'baseline_locked_at' => now()]);
    app(OkrSnapshotService::class)->evaluateKeyResult($kr->fresh());
    $cacheBefore = $kr->fresh()->only(['current_value', 'health_status', 'last_evaluated_at']);

    app(OkrSnapshotService::class)->backfillMissingWeeks($objective->fresh());

    $cacheAfter = $kr->fresh()->only(['current_value', 'health_status']);
    expect($cacheAfter['current_value'])->toBe($cacheBefore['current_value']);
    expect($cacheAfter['health_status'])->toBe($cacheBefore['health_status']);
});
