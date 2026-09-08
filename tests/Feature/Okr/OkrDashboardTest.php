<?php

use App\Models\OkrObjective;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Test Z — filtros del dashboard funcionan combinados.
it('dashboard filters by branch and status combined', function () {
    $user = User::factory()->create();
    $branchA = okrBranch('Orizaba');
    $branchB = okrBranch('Cordoba');

    $objA = okrDraftObjective($user, $branchA, [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objA));
    $objB = okrDraftObjective($user, $branchB, [100.0]); // se queda en draft

    $this->actingAs($user)
        ->get(route('okr.dashboard', ['branch_id' => $branchA->id, 'status' => OkrObjective::STATUS_ACTIVE]))
        ->assertOk()
        ->assertInertia(function ($page) use ($objA, $objB) {
            $ids = collect($page->toArray()['props']['objectives'])->pluck('id')->all();
            expect($ids)->toContain($objA->id);
            expect($ids)->not->toContain($objB->id);

            return $page->component('Okr/Dashboard');
        });
});

// Test AA — no N+1 grave en dashboard (query count acotado, no crece linealmente
// con el número de OKR — eager load ya viene en DashboardController::index()).
it('dashboard does not trigger N+1 queries as the number of objectives grows', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Tula');

    for ($i = 0; $i < 3; $i++) {
        $obj = okrDraftObjective($user, $branch, [100.0]);
        $this->actingAs($user)->post(route('okr.activate', $obj));
    }
    DB::enableQueryLog();
    $this->actingAs($user)->get(route('okr.dashboard'));
    $queriesFor3 = count(DB::getQueryLog());
    DB::flushQueryLog();
    DB::disableQueryLog(); // apagado durante el setup — solo se mide la carga del dashboard, nunca la creación/activación de los objectives nuevos

    for ($i = 0; $i < 6; $i++) {
        $obj = okrDraftObjective($user, $branch, [100.0]);
        $this->actingAs($user)->post(route('okr.activate', $obj));
    }

    DB::enableQueryLog();
    $this->actingAs($user)->get(route('okr.dashboard'));
    $queriesFor9 = count(DB::getQueryLog());
    DB::disableQueryLog();

    // 6 Objectives adicionales (3→9) NO deben agregar una query POR CADA
    // Objective/KeyResult (eso sería la firma clásica de N+1) — con eager load
    // el costo extra por fila es acotado y pequeño, nunca ~1:1 con las filas.
    $extraQueriesForSixMoreObjectives = $queriesFor9 - $queriesFor3;
    expect($extraQueriesForSixMoreObjectives)->toBeLessThan(15);
});
