<?php

use App\Models\ApiConsumer;
use App\Models\Period;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeApiToken(string $systemCode, array $abilities): string
{
    $consumer = ApiConsumer::query()->create([
        'name' => 'Test Consumer', 'system_code' => $systemCode, 'is_active' => true, 'abilities' => $abilities,
    ]);

    return $consumer->createToken('test', $abilities)->plainTextToken;
}

function makeApiPeriod(): Period
{
    return Period::query()->create([
        'name' => 'Semana Financial Test', 'code' => 'W-FIN-' . uniqid(), 'type' => 'weekly',
        'year' => 2026, 'month' => 4, 'sequence' => 1,
        'start_date' => '2026-04-01', 'end_date' => '2026-04-05', 'is_closed' => false,
    ]);
}

it('returns the metrics catalog with stable codes and definitions', function () {
    $token = makeApiToken('catalog-consumer', ['financial.read']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/financial/metrics');

    $response->assertOk();
    $codes = collect($response->json('data'))->pluck('code');
    expect($codes)->toContain('ebitda', 'mora', 'portfolio_total', 'collections_effectiveness_legacy');
});

it('never returns zero for a metric that has no radiography generated yet — it returns null with an explicit status', function () {
    $token = makeApiToken('no-data-consumer', ['financial.read']);
    $period = makeApiPeriod();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/financial/summary?period_id={$period->id}&scope=general");

    $response->assertOk();
    $metrics = collect($response->json('metrics'))->keyBy('metric');

    expect($metrics['ebitda']['value'])->toBeNull();
    expect($metrics['ebitda']['status'])->toBe('not_available');
    expect($metrics['mora']['value'])->toBeNull();
});

it('requires period_id on financial/summary', function () {
    $token = makeApiToken('missing-period-consumer', ['financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/financial/summary')
        ->assertStatus(422);
});

it('returns 404 for a period that does not exist', function () {
    $token = makeApiToken('bad-period-consumer', ['financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/financial/summary?period_id=999999')
        ->assertNotFound();
});

it('rejects unknown metric codes on metric-values', function () {
    $token = makeApiToken('metric-values-consumer', ['financial.read']);
    $period = makeApiPeriod();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/financial/metric-values?period_id={$period->id}&metrics=ebitda,not_a_real_metric")
        ->assertStatus(422);
});

it('marks the approved collections effectiveness as blocked (real, per-period) when there is no CARTERA/Ingresos data for this period, and distinguishes it from the legacy version', function () {
    $token = makeApiToken('collections-consumer', ['collections.read']);
    $period = makeApiPeriod();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/collections/effectiveness?period_id={$period->id}");

    $response->assertOk();
    // Este periodo no tiene ninguna fila de fact_portfolios/fact_recoveries — bloqueado
    // por falta de datos REAL para este period_id específico, nunca una bandera global.
    expect($response->json('versions.approved.available'))->toBeFalse();
    expect($response->json('versions.approved.status'))->toBe('blocked');
    expect($response->json('versions.approved.coverage.status'))->toBe('sin_datos');
    expect($response->json('versions.legacy.version'))->toBe('legacy_vigente_atrasado_vencido');
});

it('marks the approved collections effectiveness as available and returns real numbers when this period/scope has usable CARTERA + Ingresos data', function () {
    $token = makeApiToken('collections-consumer-real', ['collections.read']);
    $period = makeApiPeriod();
    $branch = \App\Models\Branch::query()->create(['code' => 'COR', 'name' => 'CORDOBA', 'normalized_name' => 'cordoba', 'is_active' => true]);

    \App\Models\Portfolio::query()->create([
        'period_id' => $period->id, 'branch_id' => $branch->id,
        'contract' => '25COR1270278', 'promoter_name' => 'LUIS GOMEZ', 'route_name' => 'CORDOBA',
        'status' => 'VIGENTE', 'substatus' => 'VIGENTE',
        'installment_amount' => 100, 'periodicity' => 'SEMANAL', 'num_payments' => 10,
        'first_payment_date' => '2026-04-01',
    ]);
    \App\Models\Recovery::query()->create([
        'period_id' => $period->id, 'branch_id' => $branch->id,
        'contract' => '25COR1270278', 'promoter_name' => 'LUIS GOMEZ', 'office_name_raw' => 'CORDOBA',
        'operation' => 'PAGO A CONTRATO', 'transaction' => 'PAGO',
        'capital' => 100, 'interest' => 0, 'tax' => 0, 'total_amount' => 100,
        'payment_date' => '2026-04-01', 'installment_due_date' => '2026-04-01',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/collections/effectiveness?period_id={$period->id}&scope=branch&branch_id={$branch->id}");

    $response->assertOk();
    expect($response->json('versions.approved.available'))->toBeTrue();
    expect($response->json('versions.approved.status'))->toBe('ok');
    expect((float) $response->json('versions.approved.summary.sano_cob'))->toBe(100.0);
});

it('exposes capabilities scoped to the authenticated consumer', function () {
    $token = makeApiToken('capabilities-consumer', ['financial.read']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/capabilities');

    $response->assertOk()
        ->assertJsonPath('consumer.system_code', 'capabilities-consumer')
        // Fase 11 — "approved" quedó conectado a datos reales: implemented=true es
        // verdad-del-sistema (el recurso existe y funciona), independiente de si ESTE
        // periodo en particular tiene datos suficientes (eso vive en available, por request).
        ->assertJsonPath('resources.collections_effectiveness_approved.implemented', true)
        ->assertJsonPath('resources.collections_effectiveness_approved.permitted', false)
        ->assertJsonPath('resources.collections_effectiveness_legacy.implemented', true)
        // Este consumidor solo tiene 'financial.read' — financial_summary sí está permitido,
        // pero collections/branches NO (implementado ≠ permitido para ESTE consumidor).
        ->assertJsonPath('resources.financial_summary.permitted', true)
        ->assertJsonPath('resources.collections_effectiveness_legacy.permitted', false)
        ->assertJsonPath('resources.branches.permitted', false);
});
