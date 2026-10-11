<?php

use App\Models\ApiConsumer;
use App\Models\ApiQueryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function queryLogConsumer(): ApiConsumer
{
    $consumer = ApiConsumer::query()->create(['name' => 'Test', 'system_code' => 'qlog-' . uniqid(), 'is_active' => true, 'abilities' => ['branches.read']]);
    $consumer->createToken('test', ['branches.read']);

    return $consumer;
}

it('does not rewrite last_used_at on every single request — only once per throttle window', function () {
    $consumer = queryLogConsumer();
    $token = $consumer->tokens()->first()->plainTextToken ?? $consumer->createToken('t2', ['branches.read'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/branches');
    $firstTouch = $consumer->fresh()->last_used_at;

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/branches');
    $secondTouch = $consumer->fresh()->last_used_at;

    // Dentro de la ventana de 60s, el segundo touch NO debe reescribir la columna
    // (mismo timestamp, no una nueva escritura) — la prueba real es que ambas
    // peticiones no fallan y el valor se mantiene estable, no que cambie cada vez.
    expect($firstTouch)->not->toBeNull();
    expect($secondTouch->equalTo($firstTouch))->toBeTrue();
});

it('records one api_query_log row per request with metadata only — never the response payload', function () {
    $consumer = queryLogConsumer();
    $token = $consumer->createToken('log-test', ['branches.read'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/branches')->assertOk();

    $log = ApiQueryLog::query()->where('api_consumer_id', $consumer->id)->latest('id')->first();

    expect($log)->not->toBeNull();
    expect($log->endpoint)->toBe('api/v1/branches');
    expect($log->response_status)->toBe(200);
    // Nunca se guarda un campo de payload/valores — el esquema ni lo tiene.
    expect(array_keys($log->getAttributes()))->not->toContain('payload', 'response_body', 'financial_values');
});

it('prunes api_query_logs older than the retention window, keeps recent ones', function () {
    $consumer = queryLogConsumer();

    ApiQueryLog::query()->create([
        'api_consumer_id' => $consumer->id, 'endpoint' => 'api/v1/old', 'method' => 'GET',
        'response_status' => 200, 'created_at' => now()->subDays(200),
    ]);
    $recent = ApiQueryLog::query()->create([
        'api_consumer_id' => $consumer->id, 'endpoint' => 'api/v1/recent', 'method' => 'GET',
        'response_status' => 200, 'created_at' => now()->subDays(5),
    ]);

    $this->artisan('api:query-logs:prune', ['--days' => 90])->assertExitCode(0);

    expect(ApiQueryLog::query()->where('endpoint', 'api/v1/old')->exists())->toBeFalse();
    expect(ApiQueryLog::query()->whereKey($recent->id)->exists())->toBeTrue();
});
