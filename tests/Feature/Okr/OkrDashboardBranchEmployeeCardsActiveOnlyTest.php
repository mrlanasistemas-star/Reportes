<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * C5 del cierre, 17-sep-2026 (ronda 2) — bug real: "Sucursales con OKR" y
 * "Colaboradores con OKR individual" contaban `$openObjectives` (draft+active),
 * así que un draft recién creado (que ni siquiera empezó a trackearse) ya hacía
 * creer que existía un OKR operativo en esa sucursal/colaborador. Ahora cuentan
 * SOLO `$activeOnly` — mismo criterio que riesgo/cumplimiento promedio.
 */
it('a DRAFT objective (never activated) does NOT count toward "Sucursales con OKR"', function () {
    $admin = User::factory()->create();
    $branch = okrBranch('Miacatlan');
    okrDraftObjective($admin, $branch, [100.0]); // se queda en draft — nunca se activa

    $this->actingAs($admin)
        ->get(route('okr.dashboard', ['branch_id' => $branch->id]))
        ->assertOk()
        ->assertInertia(function ($page) {
            expect($page->toArray()['props']['cards']['branches_with_okr'])->toBe(0);

            return $page->component('Okr/Dashboard');
        });
});

it('an ACTIVE objective DOES count toward "Sucursales con OKR" and "Colaboradores con OKR individual"', function () {
    $admin = User::factory()->create();
    $branch = okrBranch('Ixtlahuaca');

    $branchObjective = okrDraftObjective($admin, $branch, [100.0]);
    $this->actingAs($admin)->post(route('okr.activate', $branchObjective));

    $employee = \App\Models\Employee::query()->create([
        'employee_code' => 'C5-TEST', 'full_name' => 'Colaborador C5', 'normalized_name' => 'colaborador c5',
        'is_active' => true, 'source_system' => 'noi',
    ]);
    $individualObjective = \App\Models\OkrObjective::query()->create([
        'scope_type' => \App\Models\OkrObjective::SCOPE_EMPLOYEE, 'branch_id' => $branch->id, 'employee_id' => $employee->id,
        'title' => 'Objective individual de prueba C5', 'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(8)->toDateString(), 'duration_weeks' => 8,
        'lifecycle_status' => \App\Models\OkrObjective::STATUS_DRAFT,
    ]);
    $kpi = okrManualKpi('c5_test_kpi');
    $individualObjective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR de prueba', 'baseline_value' => 100.0, 'target_value' => 200.0, 'weight' => 100.0]);
    $this->actingAs($admin)->post(route('okr.activate', $individualObjective));

    $this->actingAs($admin)
        ->get(route('okr.dashboard', ['branch_id' => $branch->id]))
        ->assertOk()
        ->assertInertia(function ($page) {
            $cards = $page->toArray()['props']['cards'];
            expect($cards['branches_with_okr'])->toBe(1);
            expect($cards['employees_with_okr'])->toBe(1);

            return $page->component('Okr/Dashboard');
        });
});
