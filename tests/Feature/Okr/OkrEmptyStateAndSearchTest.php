<?php

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Test punto 30 Q — el dashboard distingue "sistema vacío" de "filtros sin resultados". */
it('reports has_any_objectives=false when the system truly has no OKR at all', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('okr.dashboard'))->assertInertia(function ($page) {
        expect($page->toArray()['props']['has_any_objectives'])->toBeFalse();

        return $page->component('Okr/Dashboard');
    });
});

it('reports has_any_objectives=true even when the current filters return zero results', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Cordoba'), [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));

    $this->actingAs($user)->get(route('okr.dashboard', ['search' => 'texto-que-no-existe-en-ningun-titulo']))
        ->assertInertia(function ($page) {
            expect($page->toArray()['props']['has_any_objectives'])->toBeTrue();
            expect($page->toArray()['props']['objectives'])->toBe([]);

            return $page->component('Okr/Dashboard');
        });
});

/** Test punto 30 R — la búsqueda remota de colaboradores encuentra a alguien fuera del top 30. */
it('the employees-lookup search finds an employee beyond the default top-30 alphabetical slice', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Cordoba');
    $period = \App\Models\Period::query()->create(['name' => 'Semana lookup test', 'code' => 'LOOKUP-TEST-' . uniqid(), 'type' => 'weekly', 'year' => 2026, 'month' => 1, 'sequence' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-07']);

    // 35 empleados con nombres que ordenan alfabéticamente ANTES de "Zzz Ultimo" — el 36avo.
    foreach (range(1, 35) as $i) {
        $name = sprintf('Empleado %02d de prueba', $i);
        $employee = Employee::query()->create(['full_name' => $name, 'normalized_name' => mb_strtolower($name), 'is_active' => true]);
        \App\Models\EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $employee->id, 'branch_id' => $branch->id]);
    }
    $target = Employee::query()->create(['full_name' => 'Zzz Ultimo Gestor', 'normalized_name' => 'zzz ultimo gestor', 'is_active' => true]);
    \App\Models\EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $target->id, 'branch_id' => $branch->id]);

    // Sin búsqueda, el top-30 alfabético NO debería incluirlo (empieza con "Z").
    $withoutSearch = $this->actingAs($user)->getJson("/okr/employees-lookup?branch_id={$branch->id}")->json('employees');
    expect(collect($withoutSearch)->pluck('id'))->not->toContain($target->id);

    // Buscando su nombre, SÍ debe aparecer.
    $withSearch = $this->actingAs($user)->getJson("/okr/employees-lookup?branch_id={$branch->id}&search=Ultimo")->json('employees');
    expect(collect($withSearch)->pluck('id'))->toContain($target->id);
});
