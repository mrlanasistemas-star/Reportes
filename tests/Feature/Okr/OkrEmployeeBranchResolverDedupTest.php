<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Services\Okr\OkrEmployeeBranchResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Parte D1/D2/D3 del cierre, 17-sep-2026 — bug real en producción: el dropdown de
 * colaborador del wizard OKR mostraba el MISMO nombre repetido muchas veces.
 * Causa: OkrEmployeeBranchResolver::employeesForBranch() devolvía una fila por
 * cada registro `Employee` activo, sin canonicalizar — la misma persona real
 * puede tener varios `Employee.id` históricos con el MISMO `normalized_name`
 * (fusión de fuentes/periodos, ver PersonIdentityResolverService y
 * RadiographySnapshotBuilder::buildEmployeesGestores()::_employee_ids).
 *
 * Fixture explícito del pendiente: Employee ID 10 y un Employee histórico ID 55,
 * ambos "ADRIAN DAVID MUÑIZ VAZQUEZ" → UNA sola opción. Un segundo caso con DOS
 * personas reales de identidad distinta → siguen siendo DOS opciones.
 */
function makeEmployeeFixture(string $fullName, string $normalizedName, string $employeeCode): Employee
{
    return Employee::query()->create([
        'employee_code'   => $employeeCode,
        'full_name'       => $fullName,
        'normalized_name' => $normalizedName,
        'is_active'       => true,
        'source_system'   => 'noi',
    ]);
}

beforeEach(function () {
    $this->branch = Branch::query()->create([
        'code' => 'CORD', 'name' => 'CORDOBA', 'normalized_name' => 'cordoba', 'is_active' => true,
    ]);
    $this->period = Period::query()->create([
        'name' => 'Test Period', 'code' => 'W-TEST-01', 'type' => 'weekly',
        'year' => 2026, 'month' => 8, 'sequence' => 1,
        'start_date' => '2026-08-01', 'end_date' => '2026-08-07', 'is_closed' => false,
    ]);
});

it('collapses two historical Employee IDs for the same real person (same normalized_name) into ONE option', function () {
    $adrianOld = makeEmployeeFixture('ADRIAN DAVID MUÑIZ VAZQUEZ', 'adrian david muniz vazquez', 'EMP-010');
    $adrianNew = makeEmployeeFixture('ADRIAN DAVID MUÑIZ VAZQUEZ', 'adrian david muniz vazquez', 'EMP-055');

    EmployeeBranchAssignment::query()->create(['period_id' => $this->period->id, 'employee_id' => $adrianOld->id, 'branch_id' => $this->branch->id]);
    // Simula el ID "histórico" con su propia fila de asignación (mismo periodo no permitido
    // por el unique constraint, así que se reutiliza el mismo period_id vía un segundo periodo).
    $period2 = Period::query()->create([
        'name' => 'Test Period 2', 'code' => 'W-TEST-02', 'type' => 'weekly',
        'year' => 2026, 'month' => 8, 'sequence' => 2,
        'start_date' => '2026-08-08', 'end_date' => '2026-08-14', 'is_closed' => false,
    ]);
    EmployeeBranchAssignment::query()->create(['period_id' => $period2->id, 'employee_id' => $adrianNew->id, 'branch_id' => $this->branch->id]);

    $resolver = app(OkrEmployeeBranchResolver::class);
    $result = $resolver->employeesForBranch($this->branch->id);

    expect($result)->toHaveCount(1);
    $row = $result->first();
    expect($row->full_name)->toBe('ADRIAN DAVID MUÑIZ VAZQUEZ');
    expect($row->id)->toBe(min($adrianOld->id, $adrianNew->id));
    expect($row->employee_ids)->toEqualCanonicalizing([$adrianOld->id, $adrianNew->id]);

    expect($resolver->countForBranch($this->branch->id))->toBe(1);
});

it('keeps two real people with distinct canonical identities as TWO separate options, even sharing a display name', function () {
    // Dos personas reales — mismo NOMBRE VISIBLE ("JUAN PEREZ") pero identidad
    // canónica distinta (normalized_name distinto, ej. un catálogo con variante de
    // segundo nombre no visible en full_name) — nunca deben fusionarse a ciegas.
    $juanA = makeEmployeeFixture('JUAN PEREZ', 'juan perez a', 'EMP-100');
    $juanB = makeEmployeeFixture('JUAN PEREZ', 'juan perez b', 'EMP-200');

    EmployeeBranchAssignment::query()->create(['period_id' => $this->period->id, 'employee_id' => $juanA->id, 'branch_id' => $this->branch->id]);
    $period2 = Period::query()->create([
        'name' => 'Test Period 3', 'code' => 'W-TEST-03', 'type' => 'weekly',
        'year' => 2026, 'month' => 8, 'sequence' => 3,
        'start_date' => '2026-08-15', 'end_date' => '2026-08-21', 'is_closed' => false,
    ]);
    EmployeeBranchAssignment::query()->create(['period_id' => $period2->id, 'employee_id' => $juanB->id, 'branch_id' => $this->branch->id]);

    $resolver = app(OkrEmployeeBranchResolver::class);
    $result = $resolver->employeesForBranch($this->branch->id);

    expect($result)->toHaveCount(2);
    expect($result->pluck('id')->sort()->values()->all())->toEqualCanonicalizing([$juanA->id, $juanB->id]);
    expect($resolver->countForBranch($this->branch->id))->toBe(2);
});

it('never lets a branch filter leak a duplicate from a DIFFERENT branch (regression guard)', function () {
    $otherBranch = Branch::query()->create(['code' => 'ATLI', 'name' => 'ATLIXCO', 'normalized_name' => 'atlixco', 'is_active' => true]);

    $cordobaEmployee = makeEmployeeFixture('MARIA LOPEZ', 'maria lopez', 'EMP-300');
    $atlixcoEmployee = makeEmployeeFixture('PEDRO GOMEZ', 'pedro gomez', 'EMP-400');

    EmployeeBranchAssignment::query()->create(['period_id' => $this->period->id, 'employee_id' => $cordobaEmployee->id, 'branch_id' => $this->branch->id]);
    EmployeeBranchAssignment::query()->create(['period_id' => $this->period->id, 'employee_id' => $atlixcoEmployee->id, 'branch_id' => $otherBranch->id]);

    $resolver = app(OkrEmployeeBranchResolver::class);
    $result = $resolver->employeesForBranch($this->branch->id);

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($cordobaEmployee->id);
});
