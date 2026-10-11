<?php

use App\Models\ApiConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeConsumer(array $attributes, array $abilities): array
{
    $consumer = ApiConsumer::query()->create(array_merge([
        'abilities' => $abilities,
        'is_active' => true,
    ], $attributes));

    $token = $consumer->createToken('test', $abilities)->plainTextToken;

    return [$consumer, $token];
}

it('exposes health without authentication and without sensitive data', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertJson(['status' => 'ok', 'service' => 'reportes-api', 'version' => 'v1']);
});

it('rejects protected endpoints without a token', function () {
    $this->getJson('/api/v1/branches')->assertUnauthorized();
});

it('rejects a token that lacks the required ability', function () {
    [, $token] = makeConsumer(['name' => 'Auditoría Operativa', 'system_code' => 'auditoria'], ['employees.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/branches')
        ->assertForbidden();
});

it('rejects an inactive consumer even with a valid token and ability', function () {
    [, $token] = makeConsumer(['name' => 'Auditoría Operativa', 'system_code' => 'auditoria-inactiva', 'is_active' => false], ['branches.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/branches')
        ->assertForbidden();
});

it('allows a consumer with the matching ability to list branches', function () {
    [, $token] = makeConsumer(['name' => 'Auditoría Operativa', 'system_code' => 'auditoria-ok'], ['branches.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/branches')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta']);
});

it('rejects a token with an ability the consumer itself was never granted (Fase 2.9 — the consumer is a real ceiling, not just the token)', function () {
    [$consumer] = makeConsumer(['name' => 'Auditoría Operativa', 'system_code' => 'auditoria-ceiling'], ['branches.read']);
    // El token dice tener 'financial.read', pero el consumidor NUNCA lo tuvo en su propia lista.
    $token = $consumer->createToken('rogue', ['financial.read'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/financial/metrics')
        ->assertForbidden();
});

it('rejects a branch-restricted consumer requesting scope=general', function () {
    [, $token] = makeConsumer([
        'name' => 'Auditoría Córdoba', 'system_code' => 'auditoria-cordoba',
        'allowed_branch_ids' => [999], 'branch_access_mode' => ApiConsumer::BRANCH_ACCESS_RESTRICTED,
    ], ['financial.read']);

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
    [, $token] = makeConsumer([
        'name' => 'Auditoría Córdoba', 'system_code' => 'auditoria-cordoba-2',
        'allowed_branch_ids' => [999], 'branch_access_mode' => ApiConsumer::BRANCH_ACCESS_RESTRICTED,
    ], ['financial.read']);

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
    [, $token] = makeConsumer(['name' => 'Auditoría Operativa', 'system_code' => 'auditoria-throttle'], ['branches.read']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/branches');

    $response->assertOk();
    expect($response->headers->has('X-RateLimit-Limit'))->toBeTrue();
});
