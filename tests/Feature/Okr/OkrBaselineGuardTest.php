<?php

use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\Okr\OkrKpiValueResolver;
use App\Services\Okr\OkrTrackingPeriodResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Tests punto 30 F/G — línea base automática protegida + preview read-only. */
it('rejects a manual baseline_value on an automatic KPI when creating an objective', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Cordoba');
    $kpi = okrAutomaticKpiForTest('reporteria.opex');

    $response = $this->actingAs($user)->post(route('okr.store'), [
        'scope_type' => 'branch', 'branch_id' => $branch->id,
        'title' => 'Objective con baseline manipulada de prueba',
        'start_date' => now()->toDateString(), 'duration_weeks' => 8,
        'key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR de prueba', 'baseline_value' => 999999, 'target_value' => 1, 'weight' => 100]],
    ]);

    $response->assertSessionHasErrors();
    expect(OkrObjective::query()->where('title', 'Objective con baseline manipulada de prueba')->exists())->toBeFalse();
});

it('rejects a manual baseline_value on an automatic KPI when adding a Key Result while draft', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Atlacomulco');
    $kpi = okrAutomaticKpiForTest('reporteria.mora');
    $objective = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective draft de prueba baseline',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(), 'duration_weeks' => 4,
        'lifecycle_status' => 'draft',
    ]);

    $response = $this->actingAs($user)->post(route('okr.key-results.store', $objective), [
        'kpi_id' => $kpi->id, 'description' => 'KR automático manipulado', 'baseline_value' => 12.5, 'target_value' => 8, 'weight' => 100,
    ]);

    $response->assertSessionHasErrors('baseline_value');
});

it('baseline preview returns exactly the same value OkrKpiValueResolver would resolve for the same scope/period', function () {
    $user = User::factory()->create();
    $branch = okrBranch('San Luis Potosi');
    $kpi = okrAutomaticKpiForTest('reporteria.recovery');

    $period = Period::query()->create([
        'name' => 'Mes de prueba preview', 'code' => 'PREVIEW-TEST-' . uniqid(),
        'type' => 'monthly', 'year' => 2026, 'month' => 3, 'sequence' => 1,
        'start_date' => '2026-03-01', 'end_date' => '2026-03-31',
    ]);
    PeriodSummary::query()->create(['period_id' => $period->id, 'status' => 'generated']);

    $directValue = app(OkrKpiValueResolver::class)->getValue($kpi, 'branch', $branch->id, null, $period);

    $response = $this->actingAs($user)->getJson('/okr/baseline-preview?' . http_build_query([
        'kpi_id' => $kpi->id, 'scope_type' => 'branch', 'branch_id' => $branch->id, 'start_date' => '2026-03-15',
    ]));

    $response->assertOk();
    $json = $response->json();
    expect($json['value'])->toBe($directValue);
    expect($json['available'])->toBe($directValue !== null);
});

it('baseline preview never writes anything to the database — read only', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Tula');
    $kpi = okrAutomaticKpiForTest('reporteria.portfolio');

    $countBefore = OkrObjective::query()->count();
    $this->actingAs($user)->getJson('/okr/baseline-preview?' . http_build_query([
        'kpi_id' => $kpi->id, 'scope_type' => 'branch', 'branch_id' => $branch->id, 'start_date' => now()->toDateString(),
    ]))->assertOk();

    expect(OkrObjective::query()->count())->toBe($countBefore);
});
