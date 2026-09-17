<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * D12 del cierre, 17-sep-2026 — bug real: la card "Colaboradores con OKR"
 * mostraba SIEMPRE el total GLOBAL de colaboradores como denominador (ej.
 * "X / 78"), incluso cuando el usuario ya filtró por una sucursal específica
 * — debía mostrar el total canónico de ESA sucursal, no el del sistema
 * completo.
 */
it('uses the GLOBAL canonical employee count as denominator when no branch filter is applied', function () {
    $admin = User::factory()->create();
    Employee::query()->create(['employee_code' => 'D12-1', 'full_name' => 'Persona Uno', 'normalized_name' => 'persona uno', 'is_active' => true, 'source_system' => 'noi']);
    Employee::query()->create(['employee_code' => 'D12-2', 'full_name' => 'Persona Dos', 'normalized_name' => 'persona dos', 'is_active' => true, 'source_system' => 'noi']);
    // Duplicado histórico de "Persona Uno" — NO debe contarse dos veces.
    Employee::query()->create(['employee_code' => 'D12-1-OLD', 'full_name' => 'Persona Uno', 'normalized_name' => 'persona uno', 'is_active' => true, 'source_system' => 'noi']);

    $this->actingAs($admin)
        ->get(route('okr.dashboard'))
        ->assertOk()
        ->assertInertia(function ($page) {
            expect($page->toArray()['props']['cards']['total_employees'])->toBe(2);

            return $page->component('Okr/Dashboard');
        });
});

it('uses the BRANCH-SCOPED canonical employee count as denominator when a branch filter is applied, never the global total', function () {
    $admin = User::factory()->create();
    $cordoba = Branch::query()->create(['code' => 'CORD', 'name' => 'CORDOBA', 'normalized_name' => 'cordoba', 'is_active' => true]);
    $atlixco = Branch::query()->create(['code' => 'ATLI', 'name' => 'ATLIXCO', 'normalized_name' => 'atlixco', 'is_active' => true]);
    $period = Period::query()->create([
        'name' => 'Test D12', 'code' => 'D12-TEST-' . uniqid(), 'type' => 'weekly',
        'year' => 2026, 'month' => 8, 'sequence' => 1, 'start_date' => '2026-08-01', 'end_date' => '2026-08-07',
    ]);

    $cordobaEmp1 = Employee::query()->create(['employee_code' => 'D12-C1', 'full_name' => 'Cordoba Uno', 'normalized_name' => 'cordoba uno', 'is_active' => true, 'source_system' => 'noi']);
    $cordobaEmp2 = Employee::query()->create(['employee_code' => 'D12-C2', 'full_name' => 'Cordoba Dos', 'normalized_name' => 'cordoba dos', 'is_active' => true, 'source_system' => 'noi']);
    $atlixcoEmp  = Employee::query()->create(['employee_code' => 'D12-A1', 'full_name' => 'Atlixco Uno', 'normalized_name' => 'atlixco uno', 'is_active' => true, 'source_system' => 'noi']);

    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $cordobaEmp1->id, 'branch_id' => $cordoba->id]);
    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $cordobaEmp2->id, 'branch_id' => $cordoba->id]);
    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $atlixcoEmp->id, 'branch_id' => $atlixco->id]);

    // Denominador global sería 3 — filtrado por Córdoba debe dar 2, NUNCA 3.
    $this->actingAs($admin)
        ->get(route('okr.dashboard', ['branch_id' => $cordoba->id]))
        ->assertOk()
        ->assertInertia(function ($page) {
            expect($page->toArray()['props']['cards']['total_employees'])->toBe(2);

            return $page->component('Okr/Dashboard');
        });
});
