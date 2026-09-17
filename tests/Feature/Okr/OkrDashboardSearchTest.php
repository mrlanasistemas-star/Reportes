<?php

use App\Models\OkrKpi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * D9 del cierre, 17-sep-2026 — bug real: el placeholder del buscador dice
 * "Objetivo, colaborador o KPI" pero DashboardController::applyDashboardFilters()
 * solo buscaba en `title`. Ahora también busca por colaborador, sucursal,
 * responsable, descripción de KR y nombre/código de KPI.
 */
function searchDashboard(User $user, string $term): array
{
    $response = test()->actingAs($user)->get(route('okr.dashboard', ['search' => $term]));
    $response->assertOk();

    $ids = [];
    $response->assertInertia(function ($page) use (&$ids) {
        $ids = collect($page->toArray()['props']['objectives'])->pluck('id')->all();

        return $page->component('Okr/Dashboard');
    });

    return $ids;
}

it('finds an objective by the RESPONSIBLE USER name, not just the title', function () {
    $admin = User::factory()->create();
    $responsible = User::factory()->create(['name' => 'Beatriz Solorzano Ramirez']);
    $branch = okrBranch('Cuernavaca');
    $objective = okrDraftObjective($admin, $branch, [100.0]);
    $objective->update(['responsible_user_id' => $responsible->id]);

    $ids = searchDashboard($admin, 'Beatriz Solorzano');
    expect($ids)->toContain($objective->id);
});

it('finds an objective by BRANCH name, not just the title', function () {
    $admin = User::factory()->create();
    $branch = okrBranch('Tlaxcala');
    $objective = okrDraftObjective($admin, $branch, [100.0]);

    $ids = searchDashboard($admin, 'TLAXCALA');
    expect($ids)->toContain($objective->id);
});

it('finds an objective by KEY RESULT description, not just the title', function () {
    $admin = User::factory()->create();
    $branch = okrBranch('Tula');
    $objective = okrDraftObjective($admin, $branch, [100.0]);
    $objective->keyResults()->first()->update(['description' => 'Reducir la mora en cartera vencida a menos del 5%']);

    $ids = searchDashboard($admin, 'mora en cartera vencida');
    expect($ids)->toContain($objective->id);
});

it('finds an objective by KPI name or code, not just the title', function () {
    $admin = User::factory()->create();
    $branch = okrBranch('Orizaba');
    $kpi = OkrKpi::query()->create([
        'name' => 'Recuperación de cartera semanal', 'code' => 'recup_cartera_semanal', 'unit' => 'currency',
        'type' => OkrKpi::TYPE_CUMULATIVE, 'direction' => OkrKpi::DIRECTION_INCREASE,
        'automation' => OkrKpi::AUTOMATION_MANUAL, 'is_active' => true,
    ]);
    $objective = okrDraftObjective($admin, $branch, [100.0]);
    $objective->keyResults()->first()->update(['kpi_id' => $kpi->id]);

    $ids = searchDashboard($admin, 'Recuperación de cartera');
    expect($ids)->toContain($objective->id);

    $idsByCode = searchDashboard($admin, 'recup_cartera_semanal');
    expect($idsByCode)->toContain($objective->id);
});

it('a search term matching nothing anywhere returns an empty table, not an error', function () {
    $admin = User::factory()->create();
    okrDraftObjective($admin, okrBranch('San Juan del Rio'), [100.0]);

    $ids = searchDashboard($admin, 'texto-que-no-existe-en-absolutamente-nada');
    expect($ids)->toBe([]);
});
