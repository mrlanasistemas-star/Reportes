<?php

use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * §16 — /people agrupa los registros `employees` de la misma persona (clave NOI +
 * altas de Lendus sin clave + variantes de captura) con su sucursal oficial y sus
 * rutas; /sucursales agrupa las rutas bajo su sucursal. Aditivo: /employees no cambia.
 */
function dirToken(array $abilities): string
{
    $consumer = ApiConsumer::query()->create(['name' => 'PEOPLE', 'system_code' => 'people-dir-'.uniqid(), 'abilities' => $abilities, 'is_active' => true]);

    return $consumer->createToken('test', $abilities)->plainTextToken;
}

it('/people junta a la misma persona de varias rutas y marca lo que no es persona', function () {
    $jiutepec = Branch::query()->create(['code' => 'JIUTEPEC', 'name' => 'JIUTEPEC', 'is_active' => true]);
    $cuerna = Branch::query()->create(['code' => 'CUERNAVACA', 'name' => 'CUERNAVACA', 'is_active' => true]);
    $mayo = Period::query()->create(['name' => 'Mayo 2026', 'code' => 'M-2026-05', 'type' => 'monthly', 'year' => 2026, 'month' => 5, 'sequence' => 1, 'start_date' => '2026-05-01', 'end_date' => '2026-05-31', 'is_closed' => true]);
    $junio = Period::query()->create(['name' => 'Junio 2026', 'code' => 'M-2026-06', 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => true]);

    $noi = Employee::query()->create(['full_name' => 'Alejandra Rosas Juárez', 'normalized_name' => 'alejandra rosas juarez', 'employee_code' => '22', 'source_system' => 'noi', 'is_active' => true]);
    $lendus = Employee::query()->create(['full_name' => 'ALEJANDRA ROSAS JUAREZ', 'normalized_name' => 'alejandra rosas juarez', 'source_system' => 'lendus_cobranza', 'is_active' => true]);
    $typo = Employee::query()->create(['full_name' => 'EVA CRISITNA LOPEZ RAMIREZ', 'normalized_name' => 'eva crisitna lopez ramirez', 'source_system' => 'lendus_cobranza', 'is_active' => true]);
    $eva = Employee::query()->create(['full_name' => 'EVA CRISTINA LOPEZ RAMIREZ', 'normalized_name' => 'eva cristina lopez ramirez', 'employee_code' => '40', 'source_system' => 'noi', 'is_active' => true]);
    Employee::query()->create(['full_name' => 'TEMIXCO VACANTE', 'normalized_name' => 'temixco vacante', 'source_system' => 'lendus_cobranza', 'is_active' => true]);

    EmployeeBranchAssignment::query()->create(['period_id' => $mayo->id, 'employee_id' => $lendus->id, 'branch_id' => $jiutepec->id]);
    EmployeeBranchAssignment::query()->create(['period_id' => $junio->id, 'employee_id' => $noi->id, 'branch_id' => $cuerna->id]);

    $token = dirToken(['employees.read', 'branches.read']);
    $data = collect($this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/people')->assertOk()->json('data'));

    expect($data)->toHaveCount(3);

    $alejandra = $data->firstWhere('id', $noi->id);
    expect($alejandra['employee_ids'])->toBe([$noi->id, $lendus->id])
        ->and($alejandra['employee_codes'])->toBe(['22'])
        ->and($alejandra['sucursal']['name'])->toBe('CUERNAVACA')
        // Ruta más reciente primero; JIUTEPEC es ruta de CUERNAVACA en el catálogo oficial.
        ->and(array_column($alejandra['routes'], 'name'))->toBe(['CUERNAVACA', 'JIUTEPEC'])
        ->and(array_column($alejandra['routes'], 'sucursal_code'))->toBe(['CUERNAVACA', 'CUERNAVACA']);

    // La variante con typo cae en la persona de NOI.
    expect($data->firstWhere('id', $eva->id)['employee_ids'])->toBe([$typo->id, $eva->id]);

    $vacante = $data->firstWhere('full_name', 'TEMIXCO VACANTE');
    expect($vacante['kind'])->toBe('placeholder')->and($vacante['placeholder_reason'])->toBe('vacante');

    // /sucursales: las dos rutas bajo CUERNAVACA.
    $sucursales = collect($this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/sucursales')->assertOk()->json('data'));
    expect(array_column($sucursales->firstWhere('name', 'CUERNAVACA')['routes'], 'name'))->toEqualCanonicalizing(['CUERNAVACA', 'JIUTEPEC']);
});

it('/people exige employees.read', function () {
    $token = dirToken(['branches.read']);

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/people')->assertForbidden();
});
