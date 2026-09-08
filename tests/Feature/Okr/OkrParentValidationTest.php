<?php

use App\Models\OkrObjective;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Test punto 30 O — un Objective de colaborador no puede apuntar a un parent de OTRA sucursal. */
it('rejects a parent_id whose branch differs from the child objective branch', function () {
    $user = User::factory()->create();
    $branchCordoba  = okrBranch('Cordoba');
    $branchTlaxcala = okrBranch('Tlaxcala');

    $parent = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branchCordoba->id, 'title' => 'Objective de sucursal Córdoba padre',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(8)->toDateString(), 'duration_weeks' => 8,
        'lifecycle_status' => 'active',
    ]);

    $employee = Employee::query()->create(['full_name' => 'Gestor de Tlaxcala parent test', 'normalized_name' => 'gestor de tlaxcala parent test', 'is_active' => true]);
    $period = Period::query()->create(['name' => 'Semana parent test', 'code' => 'PARENT-TEST-' . uniqid(), 'type' => 'weekly', 'year' => 2026, 'month' => 1, 'sequence' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-07']);
    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $employee->id, 'branch_id' => $branchTlaxcala->id]);
    $kpi = okrManualKpi('parent_mismatch_kpi');

    $response = $this->actingAs($user)->post(route('okr.store'), [
        'scope_type' => 'employee', 'branch_id' => $branchTlaxcala->id, 'employee_id' => $employee->id, 'parent_id' => $parent->id,
        'title' => 'Objective de gestor de Tlaxcala con parent equivocado',
        'start_date' => now()->toDateString(), 'duration_weeks' => 8,
        'key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR de prueba', 'baseline_value' => 10, 'target_value' => 20, 'weight' => 100]],
    ]);

    $response->assertSessionHasErrors('parent_id');
    expect(OkrObjective::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
});

it('rejects a parent_id that is not a branch-scope objective', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Miacatlan');
    $employee = Employee::query()->create(['full_name' => 'Gestor parent no branch', 'normalized_name' => 'gestor parent no branch', 'is_active' => true]);
    $period = Period::query()->create(['name' => 'Semana parent test 2', 'code' => 'PARENT-TEST2-' . uniqid(), 'type' => 'weekly', 'year' => 2026, 'month' => 1, 'sequence' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-07']);
    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $employee->id, 'branch_id' => $branch->id]);

    $notBranchParent = OkrObjective::query()->create([
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id, 'title' => 'Objective de empleado usado como parent inválido',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(8)->toDateString(), 'duration_weeks' => 8,
        'lifecycle_status' => 'active',
    ]);
    $kpi = okrManualKpi('parent_not_branch_kpi');

    $employee2 = Employee::query()->create(['full_name' => 'Gestor 2 parent no branch', 'normalized_name' => 'gestor 2 parent no branch', 'is_active' => true]);
    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $employee2->id, 'branch_id' => $branch->id]);

    $response = $this->actingAs($user)->post(route('okr.store'), [
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee2->id, 'parent_id' => $notBranchParent->id,
        'title' => 'Objective con parent que no es de sucursal',
        'start_date' => now()->toDateString(), 'duration_weeks' => 8,
        'key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR de prueba', 'baseline_value' => 10, 'target_value' => 20, 'weight' => 100]],
    ]);

    $response->assertSessionHasErrors('parent_id');
});
