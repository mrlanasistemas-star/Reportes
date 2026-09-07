<?php

use App\Models\Employee;
use App\Models\EmployeeAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/**
 * Auditoría 07-sep-2026 (cierre, sección 14) — reports:audit-employee-aliases
 * es SOLO diagnóstico: detecta alias duplicados entre personas distintas y
 * alias demasiado cortos, sin borrar/modificar nada.
 */
function aliasCmdEmployee(string $name): Employee
{
    static $seq = 0;
    $seq++;
    return Employee::query()->create([
        'employee_code' => 'ALIASCMD' . $seq, 'full_name' => $name, 'normalized_name' => mb_strtolower($name),
        'first_name' => explode(' ', $name)[0], 'paternal_last_name' => explode(' ', $name)[1] ?? 'X',
        'is_active' => true, 'source_system' => 'noi',
    ]);
}

it('flags a normalized_alias shared by two different employees, and never modifies employee_aliases', function () {
    $juan  = aliasCmdEmployee('JUAN PRIMERO PEREZ');
    $juan2 = aliasCmdEmployee('JUAN SEGUNDO PEREZ');

    EmployeeAlias::query()->create(['employee_id' => $juan->id, 'alias_name' => 'JUAN PEREZ', 'normalized_alias' => 'juan perez', 'source' => 'manual', 'confidence' => 1.0]);
    EmployeeAlias::query()->create(['employee_id' => $juan2->id, 'alias_name' => 'JUAN PEREZ', 'normalized_alias' => 'juan perez', 'source' => 'manual', 'confidence' => 1.0]);

    $countBefore = EmployeeAlias::query()->count();

    $exitCode = Artisan::call('reports:audit-employee-aliases');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('ALIAS DUPLICADOS');
    expect($output)->toContain('"juan perez"');
    expect($output)->toContain((string) $juan->id);
    expect($output)->toContain((string) $juan2->id);

    expect(EmployeeAlias::query()->count())->toBe($countBefore); // nunca borra/modifica
});

it('flags one-word and very short aliases separately', function () {
    $employee = aliasCmdEmployee('ANA MARIA LOPEZ');
    EmployeeAlias::query()->create(['employee_id' => $employee->id, 'alias_name' => 'ANA', 'normalized_alias' => 'ana', 'source' => 'manual', 'confidence' => 1.0]);

    Artisan::call('reports:audit-employee-aliases');
    $out = Artisan::output();

    expect($out)->toContain('ALIAS DE UNA SOLA PALABRA');
    expect($out)->toContain('"ana"');
    expect($out)->toContain('ALIAS MUY CORTOS');
});
