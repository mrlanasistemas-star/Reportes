<?php

use App\Models\OkrObjective;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * D8 del cierre, 17-sep-2026 — bug real en DashboardController::index():
 *
 *  1. SIN filtro de status, la tabla operativa excluía SOLO 'closed' — un
 *     Objective 'cancelled' se seguía mostrando mezclado con draft/active.
 *  2. CON un filtro de status EXPLÍCITO (ej. status=cancelled), esa misma
 *     exclusión de 'closed' se aplicaba de nuevo DESPUÉS del filtro y volvía a
 *     quitar los resultados que el usuario pidió — la tabla salía vacía.
 *  3. Riesgo/cumplimiento promedio se calculaban sobre draft+active+cancelled
 *     ("openObjectives"), así que un Objective cancelado con un
 *     `health_status` congelado de cuando SÍ estaba activo podía inflar el
 *     card de riesgo aunque ya no estuviera vigente.
 *
 * NOTA: ObjectiveController::destroy() cancela Y hace soft-delete en el MISMO
 * paso ("conserva histórico" — ver su docblock), así que un 'cancelled' real
 * llegado por esa vía nunca vuelve a aparecer en baseQuery() (whereNull
 * deleted_at) sin importar el filtro — eso es una decisión de diseño previa,
 * no el bug de esta sesión. Estos tests fuerzan lifecycle_status=cancelled
 * directamente (sin soft-delete) para probar la lógica de
 * DashboardController::index() en aislamiento de esa decisión.
 */
it('excludes cancelled AND closed from the operational table when no status filter is applied, but keeps draft', function () {
    $user = User::factory()->create();
    $branch = okrBranch('San Luis Potosi');

    $draft = okrDraftObjective($user, $branch, [100.0]); // se queda en draft

    $active = okrDraftObjective($user, $branch, [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $active));

    $cancelled = okrDraftObjective($user, $branch, [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $cancelled));
    // Simula un Objective que SÍ llegó a estar en riesgo antes de cancelarse —
    // el health_status queda congelado en la fila.
    $cancelled->forceFill(['health_status' => OkrObjective::HEALTH_OFF_TRACK])->save();
    $cancelled->forceFill(['lifecycle_status' => OkrObjective::STATUS_CANCELLED])->save(); // → cancelled, SIN soft-delete

    $this->actingAs($user)
        ->get(route('okr.dashboard', ['branch_id' => $branch->id]))
        ->assertOk()
        ->assertInertia(function ($page) use ($draft, $active, $cancelled) {
            $ids = collect($page->toArray()['props']['objectives'])->pluck('id')->all();
            expect($ids)->toContain($draft->id);
            expect($ids)->toContain($active->id);
            expect($ids)->not->toContain($cancelled->id);

            return $page->component('Okr/Dashboard');
        });
});

it('an explicit status=cancelled filter shows EXACTLY the cancelled objectives, not an empty table', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Tenango del Valle');

    $active = okrDraftObjective($user, $branch, [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $active));

    $cancelled = okrDraftObjective($user, $branch, [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $cancelled));
    $cancelled->forceFill(['lifecycle_status' => OkrObjective::STATUS_CANCELLED])->save();

    $this->actingAs($user)
        ->get(route('okr.dashboard', ['branch_id' => $branch->id, 'status' => OkrObjective::STATUS_CANCELLED]))
        ->assertOk()
        ->assertInertia(function ($page) use ($cancelled) {
            $ids = collect($page->toArray()['props']['objectives'])->pluck('id')->all();
            expect($ids)->toBe([$cancelled->id]);

            return $page->component('Okr/Dashboard');
        });
});

it('a cancelled objective with a stale RISK health_status never inflates the risk card or the average compliance', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Atlixco');

    $active = okrDraftObjective($user, $branch, [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $active));
    $active->forceFill(['health_status' => OkrObjective::HEALTH_ON_TRACK])->save();

    $cancelled = okrDraftObjective($user, $branch, [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $cancelled));
    $cancelled->forceFill(['health_status' => OkrObjective::HEALTH_OFF_TRACK])->save();
    $cancelled->forceFill(['lifecycle_status' => OkrObjective::STATUS_CANCELLED])->save();

    $this->actingAs($user)
        ->get(route('okr.dashboard', ['branch_id' => $branch->id]))
        ->assertOk()
        ->assertInertia(function ($page) {
            $cards = $page->toArray()['props']['cards'];
            // Solo 1 Objective activo real (el cancelado no cuenta) → riesgo debe
            // ser 0, no 1 (que sería el resultado si el cancelado se colara).
            expect($cards['active'])->toBe(1);
            expect($cards['risk'])->toBe(0);

            return $page->component('Okr/Dashboard');
        });
});
