<?php

use App\Models\ApiConsumer;
use App\Models\Employee;
use App\Models\Period;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeScopedConsumer(array $abilities): string
{
    $consumer = ApiConsumer::query()->create(['name' => 'Test', 'system_code' => 'dual-' . uniqid(), 'is_active' => true, 'abilities' => $abilities]);

    return $consumer->createToken('test', $abilities)->plainTextToken;
}

function makeValidationPeriod(): Period
{
    return Period::query()->create([
        'name' => 'Semana Validación', 'code' => 'W-VAL-' . uniqid(), 'type' => 'weekly',
        'year' => 2026, 'month' => 4, 'sequence' => 1, 'start_date' => '2026-04-01', 'end_date' => '2026-04-05', 'is_closed' => false,
    ]);
}

// ---- Fase 2.1: financial-summary exige AMBAS abilities ----

it('rejects /employees/{id}/financial-summary when the token only has employees.read', function () {
    $employee = Employee::query()->create(['employee_code' => 'E1', 'full_name' => 'E1', 'normalized_name' => 'e1', 'is_active' => true]);
    $period = makeValidationPeriod();
    $token = makeScopedConsumer(['employees.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/employees/{$employee->id}/financial-summary?period_id={$period->id}")
        ->assertForbidden();
});

it('rejects /employees/{id}/financial-summary when the token only has financial.read (no employees.read)', function () {
    $employee = Employee::query()->create(['employee_code' => 'E2', 'full_name' => 'E2', 'normalized_name' => 'e2', 'is_active' => true]);
    $period = makeValidationPeriod();
    $token = makeScopedConsumer(['financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/employees/{$employee->id}/financial-summary?period_id={$period->id}")
        ->assertForbidden();
});

it('allows /employees/{id}/financial-summary only when the token has BOTH abilities', function () {
    $employee = Employee::query()->create(['employee_code' => 'E3', 'full_name' => 'E3', 'normalized_name' => 'e3', 'is_active' => true]);
    $period = makeValidationPeriod();
    $token = makeScopedConsumer(['employees.read', 'financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/employees/{$employee->id}/financial-summary?period_id={$period->id}")
        ->assertOk();
});

it('plain /employees/{id} (catalog, no financial data) still works with only employees.read', function () {
    $employee = Employee::query()->create(['employee_code' => 'E4', 'full_name' => 'E4', 'normalized_name' => 'e4', 'is_active' => true]);
    $token = makeScopedConsumer(['employees.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/employees/{$employee->id}")
        ->assertOk();
});

// ---- Fase 2.7: validación estricta de parámetros ----

it('rejects a non-numeric employee_id in the path with 404 (route constraint), never silently casting to 0', function () {
    $token = makeScopedConsumer(['employees.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/employees/abc')
        ->assertNotFound();
});

it('rejects a non-numeric branch_id query param with 422, never silently casting to 0', function () {
    $token = makeScopedConsumer(['employees.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/employees?branch_id=abc')
        ->assertStatus(422);
});

it('rejects per_page=0 and per_page > 100 with 422', function () {
    $token = makeScopedConsumer(['employees.read']);

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/employees?per_page=0')->assertStatus(422);
    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/employees?per_page=101')->assertStatus(422);
    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/employees?per_page=abc')->assertStatus(422);
});

it('rejects an invalid scope value with 422', function () {
    $period = makeValidationPeriod();
    $token = makeScopedConsumer(['financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/financial/summary?period_id={$period->id}&scope=not_a_real_scope")
        ->assertStatus(422);
});

it('rejects an invalid period type filter with 422', function () {
    $token = makeScopedConsumer(['periods.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/periods?type=not_a_real_type')
        ->assertStatus(422);
});

it('rejects a negative or zero period_id with 422, never treating it as "no filter"', function () {
    $token = makeScopedConsumer(['financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/financial/summary?period_id=-5')
        ->assertStatus(422);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/financial/summary?period_id=0')
        ->assertStatus(422);
});
