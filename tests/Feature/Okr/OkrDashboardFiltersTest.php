<?php

use App\Models\OkrObjective;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Tests AT#7/AT#8/AT#9/AT#10 — filtros reales del dashboard, y cards que respetan el mismo alcance filtrado. */
it('filters the dashboard by kpi_id', function () {
    $user = User::factory()->create();
    $kpiA = okrManualKpi('kpi_filter_a');
    $kpiB = okrManualKpi('kpi_filter_b');

    $objA = okrDraftObjective($user, okrBranch('Orizaba'), [100.0]);
    $objA->keyResults()->update(['kpi_id' => $kpiA->id]);
    $this->actingAs($user)->post(route('okr.activate', $objA));

    $objB = okrDraftObjective($user, okrBranch('Cordoba'), [100.0]);
    $objB->keyResults()->update(['kpi_id' => $kpiB->id]);
    $this->actingAs($user)->post(route('okr.activate', $objB));

    $this->actingAs($user)->get(route('okr.dashboard', ['kpi_id' => $kpiA->id]))
        ->assertInertia(function ($page) use ($objA, $objB) {
            $ids = collect($page->toArray()['props']['objectives'])->pluck('id')->all();
            expect($ids)->toContain($objA->id);
            expect($ids)->not->toContain($objB->id);

            return $page->component('Okr/Dashboard');
        });
});

it('filters the dashboard by responsible_user_id', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $objA = okrDraftObjective($userA, okrBranch('Huamantla'), [100.0]);
    $this->actingAs($userA)->post(route('okr.activate', $objA));
    $objB = okrDraftObjective($userB, okrBranch('Tula'), [100.0]);
    $this->actingAs($userB)->post(route('okr.activate', $objB));

    $this->actingAs($userA)->get(route('okr.dashboard', ['responsible_user_id' => $userA->id]))
        ->assertInertia(function ($page) use ($objA, $objB) {
            $ids = collect($page->toArray()['props']['objectives'])->pluck('id')->all();
            expect($ids)->toContain($objA->id);
            expect($ids)->not->toContain($objB->id);

            return $page->component('Okr/Dashboard');
        });
});

it('filters the dashboard by start_date/end_date range', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Miacatlan');

    $early = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective de rango temprano de prueba',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-02-01', 'duration_weeks' => 4,
        'lifecycle_status' => 'draft',
    ]);
    $late = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective de rango tardío de prueba',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => '2026-06-01', 'end_date' => '2026-07-01', 'duration_weeks' => 4,
        'lifecycle_status' => 'draft',
    ]);

    $this->actingAs($user)->get(route('okr.dashboard', ['start_date' => '2026-05-01', 'end_date' => '2026-08-01']))
        ->assertInertia(function ($page) use ($early, $late) {
            $ids = collect($page->toArray()['props']['objectives'])->pluck('id')->all();
            expect($ids)->toContain($late->id);
            expect($ids)->not->toContain($early->id);

            return $page->component('Okr/Dashboard');
        });
});

it('cards reflect the same filtered scope as the table, not the whole system', function () {
    $user = User::factory()->create();
    $branchA = okrBranch('Cordoba');
    $branchB = okrBranch('Orizaba');

    // 2 activos en A (uno en riesgo simulado), 3 activos en B — filtrar por A debe dar cards de solo 2, no 5.
    $objA1 = okrDraftObjective($user, $branchA, [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objA1));
    $objA2 = okrDraftObjective($user, $branchA, [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objA2));
    $objA2->update(['health_status' => OkrObjective::HEALTH_RISK]);

    for ($i = 0; $i < 3; $i++) {
        $obj = okrDraftObjective($user, $branchB, [100.0]);
        $this->actingAs($user)->post(route('okr.activate', $obj));
    }

    $this->actingAs($user)->get(route('okr.dashboard', ['branch_id' => $branchA->id]))
        ->assertInertia(function ($page) {
            $cards = $page->toArray()['props']['cards'];
            expect($cards['active'])->toBe(2);
            expect($cards['risk'])->toBe(1);
            expect($cards['branches_with_okr'])->toBe(1);

            return $page->component('Okr/Dashboard');
        });
});
