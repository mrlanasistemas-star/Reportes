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
