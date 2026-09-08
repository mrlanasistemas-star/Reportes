<?php

use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\OkrObjective;
use App\Models\Period;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Tests punto 39 A/B/C/D — bug de diseño corregido 10-sep-2026 (sección 8/9
 * de la auditoría): el Objective de sucursal ahora admite N "OKR
 * individuales" en LA MISMA asignación (antes solo scope_type=branch OR
 * scope_type=employee, sin poder crear varios a la vez).
 */
function okrIndividualPeriod(): Period
{
    return Period::query()->create(['name' => 'Semana individual test', 'code' => 'INDIV-TEST-' . uniqid(), 'type' => 'weekly', 'year' => 2026, 'month' => 1, 'sequence' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-07']);
}

function okrEmployeeInBranch(string $name, \App\Models\Branch $branch, Period $period): Employee
{
    $employee = Employee::query()->create(['full_name' => $name, 'normalized_name' => mb_strtolower($name), 'is_active' => true]);
    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $employee->id, 'branch_id' => $branch->id]);

    return $employee;
}

it('creates the branch principal plus 4 individual children in a single transaction', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Cordoba');
    $period = okrIndividualPeriod();
    $employees = collect(range(1, 4))->map(fn ($i) => okrEmployeeInBranch("Gestor Individual {$i}", $branch, $period));
    $kpi = okrManualKpi('individual_test_kpi');

    $response = $this->actingAs($user)->post(route('okr.store'), [
        'scope_type' => 'branch', 'branch_id' => $branch->id,
        'title' => 'Objective de sucursal con cuatro individuales',
        'start_date' => now()->toDateString(), 'duration_weeks' => 8,
        'key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR de sucursal', 'baseline_value' => 10, 'target_value' => 20, 'weight' => 100]],
        'individual_objectives' => $employees->map(fn ($e) => ['employee_id' => $e->id, 'title' => "Objective individual de {$e->full_name}"])->all(),
    ]);

    $response->assertSessionHasNoErrors();
    $principal = OkrObjective::query()->where('title', 'Objective de sucursal con cuatro individuales')->firstOrFail();
    $children = OkrObjective::query()->where('parent_id', $principal->id)->get();

    expect($children)->toHaveCount(4);
    foreach ($children as $child) {
        expect($child->scope_type)->toBe(OkrObjective::SCOPE_EMPLOYEE);
        expect($child->branch_id)->toBe($principal->branch_id);
        expect($child->lifecycle_status)->toBe(OkrObjective::STATUS_DRAFT);
        // Punto 10 de la auditoría — nunca se inventan KR financieros en los children.
        expect($child->keyResults()->count())->toBe(0);
    }
});

it('rejects an individual_objectives row whose employee belongs to a DIFFERENT branch — 422', function () {
    $user = User::factory()->create();
    $branchCordoba = okrBranch('Cordoba');
    $branchOrizaba = okrBranch('Orizaba');
    $period = okrIndividualPeriod();
    $outsider = okrEmployeeInBranch('Gestor de Orizaba', $branchOrizaba, $period);
    $kpi = okrManualKpi('individual_wrong_branch_kpi');

    $response = $this->actingAs($user)->post(route('okr.store'), [
        'scope_type' => 'branch', 'branch_id' => $branchCordoba->id,
        'title' => 'Objective de sucursal con individual de otra sucursal',
        'start_date' => now()->toDateString(), 'duration_weeks' => 8,
        'key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR de sucursal', 'baseline_value' => 10, 'target_value' => 20, 'weight' => 100]],
        'individual_objectives' => [['employee_id' => $outsider->id, 'title' => 'Objective individual inválido por sucursal']],
    ]);

    $response->assertSessionHasErrors('individual_objectives.0.employee_id');
    expect(OkrObjective::query()->where('title', 'Objective de sucursal con individual de otra sucursal')->exists())->toBeFalse();
});

it('rejects a duplicated employee within the same individual_objectives assignment — 422', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Tula');
    $period = okrIndividualPeriod();
    $employee = okrEmployeeInBranch('Gestor Duplicado', $branch, $period);
    $kpi = okrManualKpi('individual_duplicate_kpi');

    $response = $this->actingAs($user)->post(route('okr.store'), [
        'scope_type' => 'branch', 'branch_id' => $branch->id,
        'title' => 'Objective de sucursal con colaborador duplicado',
        'start_date' => now()->toDateString(), 'duration_weeks' => 8,
        'key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR de sucursal', 'baseline_value' => 10, 'target_value' => 20, 'weight' => 100]],
        'individual_objectives' => [
            ['employee_id' => $employee->id, 'title' => 'Primer objective individual'],
            ['employee_id' => $employee->id, 'title' => 'Segundo objective individual duplicado'],
        ],
    ]);

    $response->assertSessionHasErrors('individual_objectives.1.employee_id');
    expect(OkrObjective::query()->where('title', 'Objective de sucursal con colaborador duplicado')->exists())->toBeFalse();
});

it('rolls back the WHOLE transaction (principal + all children) if any child insert fails', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Miacatlan');
    $period = okrIndividualPeriod();
    $employeeOk = okrEmployeeInBranch('Gestor Válido', $branch, $period);
    $kpi = okrManualKpi('individual_rollback_kpi');

    $countBefore = OkrObjective::query()->count();

    // Simula un fallo a mitad de la creación de children forzando una
    // violación de integridad (employee_id inexistente ya pasó la validación
    // porque se elimina justo antes del insert — reproduce un fallo real de
    // BD dentro de la transacción, no solo un 422 de validación previo).
    $employeeIdToDelete = $employeeOk->id;
    $deleted = false;
    DB::listen(function ($query) use ($employeeIdToDelete, &$deleted) {
        // DB::listen dispara DESPUÉS de que cada statement ya se ejecutó — se
        // borra el empleado justo tras el INSERT de `okr_key_results` (que
        // ObjectiveController::store() ejecuta ANTES del loop de children),
        // así el INSERT del child (que sí viene después) encuentra la FK rota.
        if (!$deleted && str_contains($query->sql, 'insert into') && str_contains($query->sql, 'okr_key_results')) {
            $deleted = true;
            DB::table('employees')->where('id', $employeeIdToDelete)->delete();
        }
    });

    try {
        $this->actingAs($user)->post(route('okr.store'), [
            'scope_type' => 'branch', 'branch_id' => $branch->id,
            'title' => 'Objective de sucursal con fallo forzado en child',
            'start_date' => now()->toDateString(), 'duration_weeks' => 8,
            'key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR de sucursal', 'baseline_value' => 10, 'target_value' => 20, 'weight' => 100]],
            'individual_objectives' => [['employee_id' => $employeeOk->id, 'title' => 'Objective individual que fallará']],
        ]);
    } catch (\Throwable) {
        // Se espera una excepción de integridad referencial — lo relevante es
        // que la transacción completa (principal incluido) se revierte.
    }

    expect(OkrObjective::query()->count())->toBe($countBefore);
    expect(OkrObjective::query()->where('title', 'Objective de sucursal con fallo forzado en child')->exists())->toBeFalse();
});
