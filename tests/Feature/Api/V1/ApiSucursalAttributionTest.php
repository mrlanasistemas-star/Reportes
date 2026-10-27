<?php

use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Models\Sucursal;
use Database\Seeders\SucursalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Exposición ADITIVA de la atribución branch → sucursal (una de las 11 de OKR)
 * en /branches, /employees, /employees/{id} y financial-summary. Nunca quita ni
 * renombra claves existentes; la sucursal sale de branches.sucursal_id (relación
 * real), jamás del nombre del colaborador.
 */
function sucTokenFor(array $attributes, array $abilities): string
{
    $consumer = ApiConsumer::query()->create(array_merge(
        ['name' => 'Attribution Consumer', 'abilities' => $abilities, 'is_active' => true],
        $attributes,
    ));

    return $consumer->createToken('test', $abilities)->plainTextToken;
}

/** Crea los branches del escenario y materializa branches.sucursal_id vía el catálogo oficial. */
function seedSucursalFor(array $branchSpecs): array
{
    $branches = [];
    foreach ($branchSpecs as $key => [$code, $name, $active]) {
        $branches[$key] = Branch::query()->create(['code' => $code, 'name' => $name, 'is_active' => $active]);
    }
    (new SucursalSeeder)->run();

    return $branches;
}

function sucIdByCodigo(string $codigo): int
{
    return Sucursal::query()->where('codigo', $codigo)->value('id');
}

// ---------------------------------------------------------------------------
// /branches
// ---------------------------------------------------------------------------

it('/branches exposes sucursal {codigo,nombre} + region + active for a branch mapped to one of the 11', function () {
    seedSucursalFor(['tula' => ['TUL', 'TULA', true]]);

    $token = sucTokenFor(['system_code' => 'attr-branches-mapped'], ['branches.read']);
    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/branches')->assertOk();

    $item = collect($response->json('data'))->firstWhere('name', 'TULA');
    expect($item['sucursal']['codigo'])->toBe('TULA');
    expect($item['sucursal']['nombre'])->toBe('TULA');
    expect($item['region'])->toBe('Q3');
    expect($item['active'])->toBeTrue();
    // Retro-compatibilidad: claves originales intactas.
    expect($item)->toHaveKeys(['id', 'code', 'name']);
    expect($item['code'])->toBe('TUL');
});

it('/branches returns sucursal null / region null for a branch NOT among the 11 (honest, never fabricated)', function () {
    seedSucursalFor(['corp' => ['COR', 'Corporativo', true]]);

    $token = sucTokenFor(['system_code' => 'attr-branches-unmapped'], ['branches.read']);
    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/branches')->assertOk();

    $item = collect($response->json('data'))->firstWhere('name', 'Corporativo');
    expect($item['sucursal'])->toBeNull();
    expect($item['region'])->toBeNull();
    expect($item['active'])->toBeTrue();
});

it('/branches reports active:false for a mapped branch whose route is inactive in the official catalog', function () {
    // ZACATEPEC es una ruta de MIACATLAN marcada inactiva; is_active del branch es true.
    seedSucursalFor(['zaca' => ['ZAC', 'ZACATEPEC', true]]);

    $token = sucTokenFor(['system_code' => 'attr-branches-route-inactive'], ['branches.read']);
    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/branches')->assertOk();

    $item = collect($response->json('data'))->firstWhere('name', 'ZACATEPEC');
    expect($item['active'])->toBeFalse();
    expect($item['sucursal']['codigo'])->toBe('MIACATLAN');
    expect($item['region'])->toBe('Q1');
});

it('/branches keeps meta/pagination unchanged (per_page default 50, max 100)', function () {
    seedSucursalFor(['tula' => ['TUL', 'TULA', true]]);
    $token = sucTokenFor(['system_code' => 'attr-branches-pag'], ['branches.read']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/branches')->assertOk();
    expect($response->json('meta'))->toHaveKeys(['current_page', 'per_page', 'total', 'last_page']);
    expect($response->json('meta.per_page'))->toBe(50);

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/branches?per_page=101')->assertStatus(422);
});

it('/branches still enforces the branches.read ability (403 without it)', function () {
    seedSucursalFor(['tula' => ['TUL', 'TULA', true]]);
    $noAbility = sucTokenFor(['system_code' => 'attr-branches-noability'], ['employees.read']);
    $this->withHeader('Authorization', "Bearer {$noAbility}")->getJson('/api/v1/branches')->assertForbidden();
});

it('/branches still enforces the branch restriction (a restricted consumer only sees its allowed branches)', function () {
    $branches = seedSucursalFor([
        'tula' => ['TUL', 'TULA', true],
        'cuer' => ['CUE', 'CUERNAVACA', true],
    ]);

    $restricted = sucTokenFor([
        'system_code' => 'attr-branches-restricted',
        'allowed_branch_ids' => [$branches['tula']->id],
        'branch_access_mode' => ApiConsumer::BRANCH_ACCESS_RESTRICTED,
    ], ['branches.read']);

    $response = $this->withHeader('Authorization', "Bearer {$restricted}")->getJson('/api/v1/branches')->assertOk();
    $names = collect($response->json('data'))->pluck('name');
    expect($names)->toContain('TULA');
    expect($names)->not->toContain('CUERNAVACA');
});

// ---------------------------------------------------------------------------
// /employees and /employees/{id}
// ---------------------------------------------------------------------------

it('/employees and /employees/{id} expose branch + sucursal (from the branch relation) and is_active', function () {
    $branches = seedSucursalFor(['tula' => ['TUL', 'TULA', true]]);
    $employee = Employee::query()->create([
        'employee_code' => 'EMP-ATTR', 'full_name' => 'Gestor Atribuido', 'position' => 'Gestor',
        'normalized_name' => 'gestor atribuido', 'is_active' => true,
    ]);
    EmployeeBranchAssignment::query()->create([
        'period_id' => Period::query()->create([
            'name' => 'Sem', 'code' => 'W-ATTR', 'type' => 'weekly', 'year' => 2026, 'month' => 6, 'sequence' => 1,
            'start_date' => '2026-06-01', 'end_date' => '2026-06-07', 'is_closed' => false,
        ])->id,
        'employee_id' => $employee->id, 'branch_id' => $branches['tula']->id,
    ]);

    $token = sucTokenFor(['system_code' => 'attr-emp'], ['employees.read']);

    // index
    $index = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/employees')->assertOk();
    $row = collect($index->json('data'))->firstWhere('id', $employee->id);
    expect($row)->toHaveKeys(['id', 'employee_code', 'full_name', 'position', 'branch_id', 'is_active', 'branch', 'sucursal', 'region']);
    expect($row['is_active'])->toBeTrue();
    expect($row['branch_id'])->toBe($branches['tula']->id);
    expect($row['branch'])->toBe(['id' => $branches['tula']->id, 'code' => 'TUL', 'name' => 'TULA']);
    expect($row['sucursal']['codigo'])->toBe('TULA');
    expect($row['region'])->toBe('Q3');

    // show
    $show = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/employees/{$employee->id}")->assertOk();
    expect($show->json('is_active'))->toBeTrue();
    expect($show->json('branch_id'))->toBe($branches['tula']->id);
    expect($show->json('branch.name'))->toBe('TULA');
    expect($show->json('sucursal.codigo'))->toBe('TULA');
    expect($show->json('region'))->toBe('Q3');
});

it('/employees returns sucursal null for an employee whose branch is not among the 11, and branch null when unassigned', function () {
    $branches = seedSucursalFor(['corp' => ['COR', 'Corporativo', true]]);
    $attributed = Employee::query()->create(['employee_code' => 'E-CORP', 'full_name' => 'Sin Sucursal', 'normalized_name' => 'sin sucursal', 'is_active' => true]);
    $orphan = Employee::query()->create(['employee_code' => 'E-ORPH', 'full_name' => 'Sin Asignacion', 'normalized_name' => 'sin asignacion', 'is_active' => true]);
    EmployeeBranchAssignment::query()->create([
        'period_id' => Period::query()->create([
            'name' => 'Sem', 'code' => 'W-CORP', 'type' => 'weekly', 'year' => 2026, 'month' => 6, 'sequence' => 1,
            'start_date' => '2026-06-01', 'end_date' => '2026-06-07', 'is_closed' => false,
        ])->id,
        'employee_id' => $attributed->id, 'branch_id' => $branches['corp']->id,
    ]);

    $token = sucTokenFor(['system_code' => 'attr-emp-null'], ['employees.read']);
    $index = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/employees')->assertOk();

    $attrRow = collect($index->json('data'))->firstWhere('id', $attributed->id);
    expect($attrRow['branch']['name'])->toBe('Corporativo');
    expect($attrRow['sucursal'])->toBeNull();
    expect($attrRow['region'])->toBeNull();

    $orphanRow = collect($index->json('data'))->firstWhere('id', $orphan->id);
    expect($orphanRow['branch_id'])->toBeNull();
    expect($orphanRow['branch'])->toBeNull();
    expect($orphanRow['sucursal'])->toBeNull();
});

// ---------------------------------------------------------------------------
// financial-summary
// ---------------------------------------------------------------------------

it('financial-summary returns sucursal_id + sucursal_status known for a period whose branch maps, historically', function () {
    $branches = seedSucursalFor([
        'tula' => ['TUL', 'TULA', true],
        'cuer' => ['CUE', 'CUERNAVACA', true],
    ]);
    $employee = Employee::query()->create(['employee_code' => 'E-HIST', 'full_name' => 'Gestor Hist', 'normalized_name' => 'gestor hist', 'is_active' => true]);

    $june = Period::query()->create([
        'name' => 'Junio', 'code' => 'M-2026-06', 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => true,
    ]);
    $oct = Period::query()->create([
        'name' => 'Octubre', 'code' => 'M-2026-10', 'type' => 'monthly', 'year' => 2026, 'month' => 10, 'sequence' => 1,
        'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'is_closed' => false,
    ]);
    EmployeeBranchAssignment::query()->create(['period_id' => $june->id, 'employee_id' => $employee->id, 'branch_id' => $branches['tula']->id]);
    EmployeeBranchAssignment::query()->create(['period_id' => $oct->id, 'employee_id' => $employee->id, 'branch_id' => $branches['cuer']->id]);

    $token = sucTokenFor(['system_code' => 'attr-fin-hist'], ['employees.read', 'financial.read']);

    // Junio → sucursal TULA (histórico), NO la más reciente (Cuernavaca).
    $juneRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/employees/{$employee->id}/financial-summary?period_id={$june->id}")->assertOk();
    expect($juneRes->json('branch_id'))->toBe($branches['tula']->id);
    expect($juneRes->json('branch_status'))->toBe('known');
    expect($juneRes->json('sucursal_id'))->toBe(sucIdByCodigo('TULA'));
    expect($juneRes->json('sucursal_status'))->toBe('known');
    // Retro-compatibilidad: claves originales intactas.
    expect($juneRes->json())->toHaveKeys(['employee_id', 'period_id', 'branch_id', 'branch_status', 'metrics']);

    // Octubre → sucursal CUERNAVACA.
    $octRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/employees/{$employee->id}/financial-summary?period_id={$oct->id}")->assertOk();
    expect($octRes->json('sucursal_id'))->toBe(sucIdByCodigo('CUERNAVACA'));
    expect($octRes->json('sucursal_status'))->toBe('known');
});

it('financial-summary returns sucursal_status unknown when the period branch is null (no assignment)', function () {
    seedSucursalFor(['tula' => ['TUL', 'TULA', true]]);
    $employee = Employee::query()->create(['employee_code' => 'E-NONE', 'full_name' => 'Sin Asignacion', 'normalized_name' => 'sin asignacion', 'is_active' => true]);
    $period = Period::query()->create([
        'name' => 'Junio', 'code' => 'M-2026-06', 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => true,
    ]);

    $token = sucTokenFor(['system_code' => 'attr-fin-none'], ['employees.read', 'financial.read']);
    $res = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/employees/{$employee->id}/financial-summary?period_id={$period->id}")->assertOk();

    expect($res->json('branch_id'))->toBeNull();
    expect($res->json('branch_status'))->toBe('unknown');
    expect($res->json('sucursal_id'))->toBeNull();
    expect($res->json('sucursal_status'))->toBe('unknown');
});

it('financial-summary returns sucursal_status unknown when the period branch exists but maps to none of the 11', function () {
    $branches = seedSucursalFor(['corp' => ['COR', 'Corporativo', true]]);
    $employee = Employee::query()->create(['employee_code' => 'E-UNM', 'full_name' => 'Gestor Corp', 'normalized_name' => 'gestor corp', 'is_active' => true]);
    $period = Period::query()->create([
        'name' => 'Junio', 'code' => 'M-2026-06', 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => true,
    ]);
    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $employee->id, 'branch_id' => $branches['corp']->id]);

    $token = sucTokenFor(['system_code' => 'attr-fin-unmapped'], ['employees.read', 'financial.read']);
    $res = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/employees/{$employee->id}/financial-summary?period_id={$period->id}")->assertOk();

    // El branch del periodo SÍ se conoce; su sucursal no.
    expect($res->json('branch_id'))->toBe($branches['corp']->id);
    expect($res->json('branch_status'))->toBe('known');
    expect($res->json('sucursal_id'))->toBeNull();
    expect($res->json('sucursal_status'))->toBe('unknown');
});

// ---------------------------------------------------------------------------
// capabilities
// ---------------------------------------------------------------------------

it('/capabilities advertises the route_branch_mapping feature without moving api_version', function () {
    $token = sucTokenFor(['system_code' => 'attr-caps'], ['branches.read']);
    $res = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/capabilities')->assertOk();

    expect($res->json('api_version'))->toBe('v1');
    expect($res->json('features.route_branch_mapping'))->toBeTrue();
    expect($res->json('features.sucursal_attribution'))->toBeTrue();
    expect($res->json('resources.branches.sucursal_attribution'))->toBeTrue();
});
