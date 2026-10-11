<?php

use App\Models\ApiConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes health without authentication and without sensitive data', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertJson(['status' => 'ok', 'service' => 'reportes-api', 'version' => 'v1']);
});

it('rejects protected endpoints without a token', function () {
    $this->getJson('/api/v1/branches')->assertUnauthorized();
});

it('rejects a token that lacks the required ability', function () {
    $consumer = ApiConsumer::query()->create([
        'name' => 'Auditoría Operativa', 'system_code' => 'auditoria', 'is_active' => true,
    ]);
    $token = $consumer->createToken('test', ['employees.read'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/branches')
        ->assertForbidden();
});

it('rejects an inactive consumer even with a valid token and ability', function () {
    $consumer = ApiConsumer::query()->create([
        'name' => 'Auditoría Operativa', 'system_code' => 'auditoria-inactiva', 'is_active' => false,
    ]);
    $token = $consumer->createToken('test', ['branches.read'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/branches')
        ->assertForbidden();
});

it('allows a consumer with the matching ability to list branches', function () {
    $consumer = ApiConsumer::query()->create([
        'name' => 'Auditoría Operativa', 'system_code' => 'auditoria-ok', 'is_active' => true,
    ]);
    $token = $consumer->createToken('test', ['branches.read'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/branches')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta']);
});

it('rejects a branch-restricted consumer requesting scope=general', function () {
    $consumer = ApiConsumer::query()->create([
        'name' => 'Auditoría Córdoba', 'system_code' => 'auditoria-cordoba', 'is_active' => true,
        'allowed_branch_ids' => [999],
    ]);
    $token = $consumer->createToken('test', ['financial.read'])->plainTextToken;

    $period = App\Models\Period::query()->create([
        'name' => 'Semana API Test', 'code' => 'W-API-' . uniqid(), 'type' => 'weekly',
        'year' => 2026, 'month' => 4, 'sequence' => 1,
        'start_date' => '2026-04-01', 'end_date' => '2026-04-05', 'is_closed' => false,
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/financial/summary?period_id={$period->id}&scope=general")
        ->assertForbidden();
});

it('rejects a branch-restricted consumer requesting a branch it is not allowed to see', function () {
    $consumer = ApiConsumer::query()->create([
        'name' => 'Auditoría Córdoba', 'system_code' => 'auditoria-cordoba-2', 'is_active' => true,
        'allowed_branch_ids' => [999],
    ]);
    $token = $consumer->createToken('test', ['financial.read'])->plainTextToken;

    $period = App\Models\Period::query()->create([
        'name' => 'Semana API Test 2', 'code' => 'W-API-' . uniqid(), 'type' => 'weekly',
        'year' => 2026, 'month' => 4, 'sequence' => 1,
        'start_date' => '2026-04-01', 'end_date' => '2026-04-05', 'is_closed' => false,
    ]);
    $branch = App\Models\Branch::query()->create(['code' => 'TST', 'name' => 'Sucursal Test', 'is_active' => true]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/financial/summary?period_id={$period->id}&scope=branch&branch_id={$branch->id}")
        ->assertForbidden();
});

it('enforces the throttle limiter is actually attached to v1 routes', function () {
    $consumer = ApiConsumer::query()->create([
        'name' => 'Auditoría Operativa', 'system_code' => 'auditoria-throttle', 'is_active' => true,
    ]);
    $token = $consumer->createToken('test', ['branches.read'])->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/branches');

    $response->assertOk();
    expect($response->headers->has('X-RateLimit-Limit'))->toBeTrue();
});
