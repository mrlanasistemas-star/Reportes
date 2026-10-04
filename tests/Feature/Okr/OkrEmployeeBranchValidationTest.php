<?php

use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\OkrKpi;
use App\Models\Period;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function okrPeriodForAssignment(): Period
{
    return Period::query()->create([
        'name' => 'Semana de prueba OKR', 'code' => 'OKR-EBA-TEST-' . uniqid(),
        'type' => 'weekly', 'year' => 2026, 'month' => 1, 'sequence' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-07',
    ]);
}

/** Test AT#11 — un colaborador de OTRA sucursal se rechaza con 422 al crear el OKR. */
it('rejects creating an employee-scoped objective when the employee does not belong to the given branch', function () {
    $user = User::factory()->create();
    $branchCordoba  = okrBranch('Cordoba');
    $branchTlaxcala = okrBranch('Tlaxcala');
    $employee = Employee::query()->create(['full_name' => 'Gestor de Tlaxcala de prueba', 'normalized_name' => 'gestor de tlaxcala de prueba', 'is_active' => true]);
    EmployeeBranchAssignment::query()->create(['period_id' => okrPeriodForAssignment()->id, 'employee_id' => $employee->id, 'branch_id' => $branchTlaxcala->id]);
    $kpi = okrManualKpi('eba_test_kpi');

    $response = $this->actingAs($user)->post(route('okr.store'), [
        'scope_type' => 'employee', 'branch_id' => $branchCordoba->id, 'employee_id' => $employee->id,
        'title' => 'Aumentar la colocación del gestor de prueba',
        'start_date' => now()->toDateString(), 'duration_weeks' => 8,
        'key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR de prueba', 'baseline_value' => 10, 'target_value' => 20, 'weight' => 100]],
    ]);

    $response->assertStatus(302); // Inertia: back() con errores de sesión
    $response->assertSessionHasErrors('employee_id');
    expect(\App\Models\OkrObjective::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
});

it('accepts creating an employee-scoped objective when the employee really belongs to the given branch', function () {
    $user = User::factory()->create();
    $branch = okrBranch('San Luis Potosi');
    $employee = Employee::query()->create(['full_name' => 'Gestor de SLP de prueba', 'normalized_name' => 'gestor de slp de prueba', 'is_active' => true]);
    EmployeeBranchAssignment::query()->create(['period_id' => okrPeriodForAssignment()->id, 'employee_id' => $employee->id, 'branch_id' => $branch->id]);
    $kpi = okrManualKpi('eba_test_kpi_ok');

    $response = $this->actingAs($user)->post(route('okr.store'), [
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id,
        'title' => 'Aumentar la colocación del gestor de prueba',
        'start_date' => now()->toDateString(), 'duration_weeks' => 8,
        'key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR de prueba', 'baseline_value' => 10, 'target_value' => 20, 'weight' => 100]],
    ]);

    $response->assertSessionHasNoErrors();
    expect(\App\Models\OkrObjective::query()->where('employee_id', $employee->id)->exists())->toBeTrue();
});

/**
 * TEST 5 del cierre (04-oct-2026, Parte 1.1): un colaborador en baja no es
 * seleccionable para un NUEVO OKR — backend real (StoreObjectiveRequest),
 * nunca solo el filtro del buscador del wizard (employeesLookup ya lo oculta,
 * pero esto cubre una petición directa con un employee_id que la UI nunca
 * hubiera ofrecido).
 */
it('rejects creating an employee-scoped objective for an INACTIVE employee (baja), even if they still belong to the branch', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Puebla');
    $employee = Employee::query()->create(['full_name' => 'Gestor en baja de prueba', 'normalized_name' => 'gestor en baja de prueba', 'is_active' => false]);
    EmployeeBranchAssignment::query()->create(['period_id' => okrPeriodForAssignment()->id, 'employee_id' => $employee->id, 'branch_id' => $branch->id]);
    $kpi = okrManualKpi('eba_test_kpi_inactive');

    $response = $this->actingAs($user)->post(route('okr.store'), [
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id,
        'title' => 'Objective para un colaborador en baja',
        'start_date' => now()->toDateString(), 'duration_weeks' => 8,
        'key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR de prueba', 'baseline_value' => 10, 'target_value' => 20, 'weight' => 100]],
    ]);

    $response->assertSessionHasErrors('employee_id');
    expect(\App\Models\OkrObjective::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
});

it('rejects an INACTIVE employee inside "individual_objectives" (branch individualization), even when active colleagues in the same row set succeed', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Veracruz');
    $period = okrPeriodForAssignment();
    $activeEmployee = Employee::query()->create(['full_name' => 'Gestor activo de prueba', 'normalized_name' => 'gestor activo de prueba', 'is_active' => true]);
    $inactiveEmployee = Employee::query()->create(['full_name' => 'Gestor baja de prueba', 'normalized_name' => 'gestor baja de prueba', 'is_active' => false]);
    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $activeEmployee->id, 'branch_id' => $branch->id]);
    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $inactiveEmployee->id, 'branch_id' => $branch->id]);
    $kpi = okrManualKpi('eba_test_kpi_individual_inactive');

    $response = $this->actingAs($user)->post(route('okr.store'), [
        'scope_type' => 'branch', 'branch_id' => $branch->id,
        'title' => 'Objective de sucursal con un gestor en baja en la lista',
        'start_date' => now()->toDateString(), 'duration_weeks' => 8,
        'key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR de prueba', 'baseline_value' => 10, 'target_value' => 20, 'weight' => 100]],
        'individual_objectives' => [
            ['employee_id' => $activeEmployee->id, 'title' => 'Objective individual del gestor activo'],
            ['employee_id' => $inactiveEmployee->id, 'title' => 'Objective individual del gestor en baja'],
        ],
    ]);

    $response->assertSessionHasErrors('individual_objectives.1.employee_id');
    expect(\App\Models\OkrObjective::query()->where('title', 'Objective de sucursal con un gestor en baja en la lista')->exists())->toBeFalse();
});

/**
 * TEST 5 (segunda mitad) — histórico preservado: un empleado que YA tenía un
 * OKR individual sigue existiendo íntegro aunque después cause baja. La
 * exclusión de Parte 1.1 es solo para CREAR un OKR nuevo, nunca para borrar
 * o esconder lo que ya se produjo.
 */
it('never deletes or hides a historical objective after the employee is later marked inactive (baja)', function () {
    $admin = User::factory()->create();
    $branch = okrBranch('Toluca');
    $employee = Employee::query()->create(['full_name' => 'Gestor historico de prueba', 'normalized_name' => 'gestor historico de prueba', 'is_active' => true]);
    EmployeeBranchAssignment::query()->create(['period_id' => okrPeriodForAssignment()->id, 'employee_id' => $employee->id, 'branch_id' => $branch->id]);
    $kpi = okrManualKpi('eba_test_kpi_historic');

    $this->actingAs($admin)->post(route('okr.store'), [
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id,
        'title' => 'Objective historico antes de la baja',
        'start_date' => now()->toDateString(), 'duration_weeks' => 8,
        'key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR de prueba', 'baseline_value' => 10, 'target_value' => 20, 'weight' => 100]],
    ])->assertSessionHasNoErrors();

    $objective = \App\Models\OkrObjective::query()->where('employee_id', $employee->id)->firstOrFail();

    $employee->update(['is_active' => false]);

    expect($objective->fresh())->not->toBeNull();
    $this->actingAs($admin)->get(route('okr.show', $objective))->assertOk();
});
