<?php

use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function okrTestKpi(): OkrKpi
{
    return OkrKpi::query()->firstOrCreate(['code' => 'api_test_kpi'], [
        'name' => 'KPI de prueba API', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE,
        'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_MANUAL, 'is_active' => true,
    ]);
}

function apiTokenFor(array $attributes, array $abilities): string
{
    $consumer = ApiConsumer::query()->create(array_merge(['name' => 'Test Consumer', 'abilities' => $abilities, 'is_active' => true], $attributes));

    return $consumer->createToken('test', $abilities)->plainTextToken;
}

// ---- Fase 2.6: OKR — filtrado por objetivo, nunca por "sucursal actual" del colaborador ----

it('only returns the objectives whose OWN branch (or parent branch) the consumer is authorized for — filters per objective', function () {
    $tula = Branch::query()->create(['code' => 'TUL', 'name' => 'Tula', 'is_active' => true]);
    $cuernavaca = Branch::query()->create(['code' => 'CUE', 'name' => 'Cuernavaca', 'is_active' => true]);
    $employee = Employee::query()->create(['employee_code' => 'EMP-OKR', 'full_name' => 'Gestor OKR', 'normalized_name' => 'gestor okr', 'is_active' => true]);
    $user = User::factory()->create();
    $kpi = okrTestKpi();

    // Objective individual bajo la sucursal Tula (vía parent branch Objective).
    $tulaParent = OkrObjective::query()->create([
        'scope_type' => OkrObjective::SCOPE_BRANCH, 'branch_id' => $tula->id, 'title' => 'OKR sucursal Tula',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => '2026-06-01', 'end_date' => '2026-07-26', 'duration_weeks' => 8,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE,
    ]);
    $tulaIndividual = OkrObjective::query()->create([
        'scope_type' => OkrObjective::SCOPE_EMPLOYEE, 'parent_id' => $tulaParent->id, 'employee_id' => $employee->id,
        'title' => 'Objetivo individual en Tula', 'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => '2026-06-01', 'end_date' => '2026-07-26', 'duration_weeks' => 8,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE,
    ]);
    $tulaIndividual->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR', 'baseline_value' => 10, 'target_value' => 20, 'weight' => 100, 'actual_progress_percentage' => 50]);

    // El MISMO colaborador, ahora con un objetivo individual bajo Cuernavaca.
    $cuernavacaParent = OkrObjective::query()->create([
        'scope_type' => OkrObjective::SCOPE_BRANCH, 'branch_id' => $cuernavaca->id, 'title' => 'OKR sucursal Cuernavaca',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => '2026-10-01', 'end_date' => '2026-11-26', 'duration_weeks' => 8,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE,
    ]);
    $cuernavacaIndividual = OkrObjective::query()->create([
        'scope_type' => OkrObjective::SCOPE_EMPLOYEE, 'parent_id' => $cuernavacaParent->id, 'employee_id' => $employee->id,
        'title' => 'Objetivo individual en Cuernavaca', 'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => '2026-10-01', 'end_date' => '2026-11-26', 'duration_weeks' => 8,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE,
    ]);
    $cuernavacaIndividual->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR', 'baseline_value' => 10, 'target_value' => 20, 'weight' => 100, 'actual_progress_percentage' => 70]);

    $token = apiTokenFor([
        'system_code' => 'auditoria-okr-tula', 'allowed_branch_ids' => [$tula->id],
        'branch_access_mode' => ApiConsumer::BRANCH_ACCESS_RESTRICTED,
    ], ['okr.read']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/okr/employees/{$employee->id}")
        ->assertOk();

    $ids = collect($response->json('objectives'))->pluck('id');
    expect($ids)->toContain($tulaIndividual->id);
    expect($ids)->not->toContain($cuernavacaIndividual->id);
});

it('returns 403 when the employee has objectives but NONE are visible to this restricted consumer', function () {
    $tula = Branch::query()->create(['code' => 'TUL', 'name' => 'Tula', 'is_active' => true]);
    $other = Branch::query()->create(['code' => 'OTR', 'name' => 'Otra', 'is_active' => true]);
    $employee = Employee::query()->create(['employee_code' => 'EMP-OKR2', 'full_name' => 'Gestor OKR 2', 'normalized_name' => 'gestor okr 2', 'is_active' => true]);
    $user = User::factory()->create();

    $parent = OkrObjective::query()->create([
        'scope_type' => OkrObjective::SCOPE_BRANCH, 'branch_id' => $tula->id, 'title' => 'OKR Tula',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => '2026-06-01', 'end_date' => '2026-07-26', 'duration_weeks' => 8,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE,
    ]);
    OkrObjective::query()->create([
        'scope_type' => OkrObjective::SCOPE_EMPLOYEE, 'parent_id' => $parent->id, 'employee_id' => $employee->id,
        'title' => 'Objetivo en Tula', 'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => '2026-06-01', 'end_date' => '2026-07-26', 'duration_weeks' => 8,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE,
    ]);

    $token = apiTokenFor([
        'system_code' => 'auditoria-okr-other', 'allowed_branch_ids' => [$other->id],
        'branch_access_mode' => ApiConsumer::BRANCH_ACCESS_RESTRICTED,
    ], ['okr.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/okr/employees/{$employee->id}")
        ->assertForbidden();
});

it('returns 200 with an empty list when the employee genuinely has no objectives (absence of data, not a permission issue)', function () {
    $other = Branch::query()->create(['code' => 'OTR', 'name' => 'Otra', 'is_active' => true]);
    $employee = Employee::query()->create(['employee_code' => 'EMP-NOOKR', 'full_name' => 'Sin OKR', 'normalized_name' => 'sin okr', 'is_active' => true]);

    $token = apiTokenFor([
        'system_code' => 'auditoria-okr-empty', 'allowed_branch_ids' => [$other->id],
        'branch_access_mode' => ApiConsumer::BRANCH_ACCESS_RESTRICTED,
    ], ['okr.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/okr/employees/{$employee->id}")
        ->assertOk()
        ->assertJsonPath('objectives', []);
});

// ---- Fase 2.4: catálogo de sucursales respeta allowed_branch_ids ----

it('a branch-restricted consumer only sees its allowed branches in the catalog', function () {
    $allowed = Branch::query()->create(['code' => 'ALO', 'name' => 'Allowed', 'is_active' => true]);
    Branch::query()->create(['code' => 'HID', 'name' => 'Hidden', 'is_active' => true]);

    $token = apiTokenFor([
        'system_code' => 'auditoria-branch-catalog', 'allowed_branch_ids' => [$allowed->id],
        'branch_access_mode' => ApiConsumer::BRANCH_ACCESS_RESTRICTED,
    ], ['branches.read']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/branches')->assertOk();

    $names = collect($response->json('data'))->pluck('name');
    expect($names)->toContain('Allowed');
    expect($names)->not->toContain('Hidden');
});

it('a deny-all (none) consumer sees an empty branch catalog, never the full list', function () {
    Branch::query()->create(['code' => 'ANY', 'name' => 'Any Branch', 'is_active' => true]);

    $token = apiTokenFor([
        'system_code' => 'auditoria-branch-none', 'branch_access_mode' => ApiConsumer::BRANCH_ACCESS_NONE,
    ], ['branches.read']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/branches')->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('a restricted consumer WITH the branches.read.global override ability sees the full catalog', function () {
    $allowed = Branch::query()->create(['code' => 'ALO2', 'name' => 'Allowed Two', 'is_active' => true]);
    Branch::query()->create(['code' => 'HID2', 'name' => 'Hidden Two', 'is_active' => true]);

    $token = apiTokenFor([
        'system_code' => 'auditoria-branch-global-override', 'allowed_branch_ids' => [$allowed->id],
        'branch_access_mode' => ApiConsumer::BRANCH_ACCESS_RESTRICTED,
    ], ['branches.read', 'branches.read.global']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/branches')->assertOk();

    $names = collect($response->json('data'))->pluck('name');
    expect($names)->toContain('Allowed Two', 'Hidden Two');
});
