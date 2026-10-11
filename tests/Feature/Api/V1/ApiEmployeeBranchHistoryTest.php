<?php

use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Fase 2.2/2.3 — escenario EXACTO del encargo: un gestor trabajó en Tula
 * durante junio pero hoy está en Cuernavaca. Una consulta sobre junio NUNCA
 * debe atribuirlo a Cuernavaca, y un consumidor restringido a Tula debe poder
 * ver sus datos de junio aunque HOY el colaborador ya no esté ahí — y un
 * consumidor restringido a Cuernavaca NO debe poder ver sus datos de junio
 * solo porque hoy el colaborador le pertenece.
 */
function makeTulaCuernavacaScenario(): array
{
    $tula = Branch::query()->create(['code' => 'TUL', 'name' => 'Tula', 'is_active' => true]);
    $cuernavaca = Branch::query()->create(['code' => 'CUE', 'name' => 'Cuernavaca', 'is_active' => true]);

    $employee = Employee::query()->create([
        'employee_code' => 'EMP-TRASLADO', 'full_name' => 'Gestor Trasladado', 'normalized_name' => 'gestor trasladado', 'is_active' => true,
    ]);

    $juniPeriod = Period::query()->create([
        'name' => 'Junio 2026', 'code' => 'M-2026-06', 'type' => 'monthly',
        'year' => 2026, 'month' => 6, 'sequence' => 1,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => true,
    ]);

    $octPeriod = Period::query()->create([
        'name' => 'Octubre 2026', 'code' => 'M-2026-10', 'type' => 'monthly',
        'year' => 2026, 'month' => 10, 'sequence' => 1,
        'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'is_closed' => false,
    ]);

    // Junio: el empleado estaba asignado a Tula.
    EmployeeBranchAssignment::query()->create([
        'period_id' => $juniPeriod->id, 'employee_id' => $employee->id, 'branch_id' => $tula->id,
    ]);

    // Octubre (más reciente): el empleado ya está en Cuernavaca.
    EmployeeBranchAssignment::query()->create([
        'period_id' => $octPeriod->id, 'employee_id' => $employee->id, 'branch_id' => $cuernavaca->id,
    ]);

    return compact('tula', 'cuernavaca', 'employee', 'juniPeriod', 'octPeriod');
}

function tokenFor(array $attributes, array $abilities): string
{
    $consumer = ApiConsumer::query()->create(array_merge(['name' => 'Test Consumer', 'abilities' => $abilities, 'is_active' => true], $attributes));

    return $consumer->createToken('test', $abilities)->plainTextToken;
}

it('resolves the employee branch-history-resolver to Tula for June and Cuernavaca for October — never the "latest" branch for a past period', function () {
    $scenario = makeTulaCuernavacaScenario();
    $resolver = app(App\Services\Api\EmployeeBranchHistoryResolver::class);

    $june = $resolver->resolveForPeriod($scenario['employee']->id, $scenario['juniPeriod']);
    $october = $resolver->resolveForPeriod($scenario['employee']->id, $scenario['octPeriod']);

    expect($june)->toBe(['branch_id' => $scenario['tula']->id, 'status' => 'known']);
    expect($october)->toBe(['branch_id' => $scenario['cuernavaca']->id, 'status' => 'known']);
});

it('allows a Tula-restricted consumer to read the employee financial-summary for June (the period they actually belonged to Tula)', function () {
    $scenario = makeTulaCuernavacaScenario();
    $token = tokenFor([
        'system_code' => 'auditoria-tula', 'allowed_branch_ids' => [$scenario['tula']->id],
        'branch_access_mode' => ApiConsumer::BRANCH_ACCESS_RESTRICTED,
    ], ['employees.read', 'financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/employees/{$scenario['employee']->id}/financial-summary?period_id={$scenario['juniPeriod']->id}")
        ->assertOk()
        ->assertJsonPath('branch_id', $scenario['tula']->id)
        ->assertJsonPath('branch_status', 'known');
});

it('rejects a Cuernavaca-restricted consumer reading June data — the employee belonged to Tula THAT period, never Cuernavaca', function () {
    $scenario = makeTulaCuernavacaScenario();
    $token = tokenFor([
        'system_code' => 'auditoria-cuernavaca', 'allowed_branch_ids' => [$scenario['cuernavaca']->id],
        'branch_access_mode' => ApiConsumer::BRANCH_ACCESS_RESTRICTED,
    ], ['employees.read', 'financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/employees/{$scenario['employee']->id}/financial-summary?period_id={$scenario['juniPeriod']->id}")
        ->assertForbidden();
});

it('allows the SAME Cuernavaca-restricted consumer to read October data for the same employee (now legitimately theirs)', function () {
    $scenario = makeTulaCuernavacaScenario();
    $token = tokenFor([
        'system_code' => 'auditoria-cuernavaca-2', 'allowed_branch_ids' => [$scenario['cuernavaca']->id],
        'branch_access_mode' => ApiConsumer::BRANCH_ACCESS_RESTRICTED,
    ], ['employees.read', 'financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/employees/{$scenario['employee']->id}/financial-summary?period_id={$scenario['octPeriod']->id}")
        ->assertOk()
        ->assertJsonPath('branch_id', $scenario['cuernavaca']->id);
});

it('rejects a Tula-restricted consumer requesting October data (employee no longer theirs that period)', function () {
    $scenario = makeTulaCuernavacaScenario();
    $token = tokenFor([
        'system_code' => 'auditoria-tula-2', 'allowed_branch_ids' => [$scenario['tula']->id],
        'branch_access_mode' => ApiConsumer::BRANCH_ACCESS_RESTRICTED,
    ], ['employees.read', 'financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/employees/{$scenario['employee']->id}/financial-summary?period_id={$scenario['octPeriod']->id}")
        ->assertForbidden();
});

it('IDOR: a restricted consumer cannot escalate by switching employee_id via scope=employee on /financial/summary', function () {
    $scenario = makeTulaCuernavacaScenario();
    $token = tokenFor([
        'system_code' => 'auditoria-idor', 'allowed_branch_ids' => [$scenario['cuernavaca']->id],
        'branch_access_mode' => ApiConsumer::BRANCH_ACCESS_RESTRICTED,
    ], ['financial.read']);

    // Mismo empleado, mismo periodo de junio (Tula) — el consumidor solo tiene Cuernavaca.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/financial/summary?period_id={$scenario['juniPeriod']->id}&scope=employee&employee_id={$scenario['employee']->id}")
        ->assertForbidden();
});

it('denies access by default when the branch for that period is genuinely unknown (no assignment at all) for a restricted consumer', function () {
    $scenario = makeTulaCuernavacaScenario();
    $otherEmployee = Employee::query()->create(['employee_code' => 'EMP-SIN-ASIGNAR', 'full_name' => 'Sin Asignación', 'normalized_name' => 'sin asignacion', 'is_active' => true]);
    // Nunca se le creó ninguna EmployeeBranchAssignment.

    $token = tokenFor([
        'system_code' => 'auditoria-unknown', 'allowed_branch_ids' => [$scenario['tula']->id],
        'branch_access_mode' => ApiConsumer::BRANCH_ACCESS_RESTRICTED,
    ], ['employees.read', 'financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/employees/{$otherEmployee->id}/financial-summary?period_id={$scenario['juniPeriod']->id}")
        ->assertForbidden();
});

it('a global (unrestricted) consumer can read both June and October regardless of branch history', function () {
    $scenario = makeTulaCuernavacaScenario();
    $token = tokenFor(['system_code' => 'auditoria-global'], ['employees.read', 'financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/employees/{$scenario['employee']->id}/financial-summary?period_id={$scenario['juniPeriod']->id}")
        ->assertOk()->assertJsonPath('branch_id', $scenario['tula']->id);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/employees/{$scenario['employee']->id}/financial-summary?period_id={$scenario['octPeriod']->id}")
        ->assertOk()->assertJsonPath('branch_id', $scenario['cuernavaca']->id);
});
