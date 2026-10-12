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
        'start_date' => '2026-03-30', 'end_date' => '2026-04-05', 'is_closed' => false,
    ]);
}

/** Identidad de una ejecución del motor V4 vigente (semanal, corte = domingo del periodo). */
function v4RunAttrs(Period $period): array
{
    return [
        'engine_version' => \App\Models\EfectividadCobranzaRun::currentEngineVersion(), 'mode' => 'SEMANAL',
        'base_date' => $period->start_date->copy()->subDay()->format('Y-m-d'), 'cutoff_date' => $period->end_date->format('Y-m-d'),
    ];
}

function v4Fingerprint(Period $period, ?int $branchId): ?string
{
    return app(\App\Services\Efectividad\V4\EfectividadV4FingerprintService::class)->compute($period, $branchId, 'SEMANAL', null);
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

it('marks the approved collections effectiveness as not_available (never recalculated live) when no EfectividadCobranzaRun has been generated yet for this period/scope', function () {
    $token = makeApiToken('collections-consumer', ['collections.read']);
    $period = makeApiPeriod();

    // Auditoría 2026-10-12 (Fase 2) — aunque hubiera datos reales en fact_portfolios/
    // fact_recoveries, la API YA NO los recalcula en vivo: solo lee un
    // EfectividadCobranzaRun ya generado. Sin uno, siempre not_available.
    \App\Models\Portfolio::query()->create([
        'period_id' => $period->id, 'contract' => '25COR1270278', 'promoter_name' => 'LUIS GOMEZ',
        'status' => 'VIGENTE', 'substatus' => 'VIGENTE', 'installment_amount' => 100,
        'periodicity' => 'SEMANAL', 'num_payments' => 10, 'first_payment_date' => '2026-04-01',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/collections/effectiveness?period_id={$period->id}");

    $response->assertOk();
    expect($response->json('versions.approved.available'))->toBeFalse();
    expect($response->json('versions.approved.status'))->toBe('not_available');
    expect($response->json('versions.legacy.version'))->toBe('legacy_vigente_atrasado_vencido');
});

it('marks the approved collections effectiveness as available with real numbers when a successful EfectividadCobranzaRun already exists for this period/scope', function () {
    $token = makeApiToken('collections-consumer-real', ['collections.read']);
    $period = makeApiPeriod();
    $branch = \App\Models\Branch::query()->create(['code' => 'COR', 'name' => 'CORDOBA', 'normalized_name' => 'cordoba', 'is_active' => true]);

    \App\Models\EfectividadCobranzaRun::query()->create(v4RunAttrs($period) + [
        'period_id' => $period->id, 'branch_id' => $branch->id, 'status' => 'success',
        'finished_at' => now(), 'coverage' => ['status' => 'ok'],
        'result_rows' => [
            ['promotor' => 'LUIS GOMEZ', 'sano_pc' => 1000, 'sano_cob' => 100, 'es_total' => false],
            ['promotor' => 'GRAN TOTAL', 'sano_pc' => 1000, 'sano_cob' => 100, 'es_total' => true],
        ],
        'source_fingerprint' => 'stub-fingerprint-not-stale-check-only',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/collections/effectiveness?period_id={$period->id}&scope=branch&branch_id={$branch->id}");

    $response->assertOk();
    expect($response->json('versions.approved.available'))->toBeTrue();
    // El fingerprint guardado ("stub-...") nunca coincide con el real recalculado →
    // status=stale es el comportamiento CORRECTO aquí (no hay datos reales detrás de
    // este run de prueba). Ver el siguiente test para status=ok con datos reales.
    expect($response->json('versions.approved.status'))->toBe('stale');
    expect($response->json('versions.approved.is_stale'))->toBeTrue();
    expect((float) $response->json('versions.approved.summary.sano_cob'))->toBe(100.0);
});

it('marks the approved collections effectiveness as status=ok (not stale) when the stored fingerprint still matches the current state of the sources', function () {
    $token = makeApiToken('collections-consumer-fresh', ['collections.read']);
    $period = makeApiPeriod();
    $branch = \App\Models\Branch::query()->create(['code' => 'COR', 'name' => 'CORDOBA', 'normalized_name' => 'cordoba', 'is_active' => true]);

    \App\Models\Portfolio::query()->create([
        'period_id' => $period->id, 'branch_id' => $branch->id,
        'contract' => '25COR1270278', 'promoter_name' => 'LUIS GOMEZ', 'route_name' => 'CORDOBA',
        'status' => 'VIGENTE', 'substatus' => 'VIGENTE',
        'installment_amount' => 100, 'periodicity' => 'SEMANAL', 'num_payments' => 10,
        'first_payment_date' => '2026-04-01',
    ]);

    $fingerprint = v4Fingerprint($period, $branch->id);

    \App\Models\EfectividadCobranzaRun::query()->create(v4RunAttrs($period) + [
        'period_id' => $period->id, 'branch_id' => $branch->id, 'status' => 'success',
        'finished_at' => now(), 'coverage' => ['status' => 'ok'],
        'result_rows' => [['promotor' => 'GRAN TOTAL', 'sano_cob' => 100, 'es_total' => true]],
        'source_fingerprint' => $fingerprint,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/collections/effectiveness?period_id={$period->id}&scope=branch&branch_id={$branch->id}");

    $response->assertOk();
    expect($response->json('versions.approved.status'))->toBe('ok');
    expect($response->json('versions.approved.is_stale'))->toBeFalse();
});

it('Fase 1 (auditoría 2026-10-12): never attributes an employee-scoped result by name match alone — requires a verified branch assignment for that exact period', function () {
    $period = makeApiPeriod();
    $branchA = \App\Models\Branch::query()->create(['code' => 'ATC', 'name' => 'ATLACOMULCO', 'normalized_name' => 'atlacomulco', 'is_active' => true]);
    $branchB = \App\Models\Branch::query()->create(['code' => 'COR', 'name' => 'CORDOBA', 'normalized_name' => 'cordoba', 'is_active' => true]);

    // DOS colaboradores con nombre EXACTAMENTE IGUAL, en sucursales distintas.
    $employeeA = \App\Models\Employee::query()->create(['full_name' => 'JUAN PEREZ LOPEZ', 'normalized_name' => 'juan perez lopez', 'is_active' => true, 'source_system' => 'lendus_cobranza']);
    $employeeB = \App\Models\Employee::query()->create(['full_name' => 'JUAN PEREZ LOPEZ', 'normalized_name' => 'juan perez lopez', 'is_active' => true, 'source_system' => 'lendus_cobranza']);

    \App\Models\EmployeeBranchAssignment::query()->create([
        'period_id' => $period->id, 'employee_id' => $employeeA->id, 'branch_id' => $branchA->id,
        'source_type' => 'lendus', 'match_type' => 'normalized', 'confidence' => '0.90', 'was_manual_reviewed' => false,
    ]);
    \App\Models\EmployeeBranchAssignment::query()->create([
        'period_id' => $period->id, 'employee_id' => $employeeB->id, 'branch_id' => $branchB->id,
        'source_type' => 'lendus', 'match_type' => 'normalized', 'confidence' => '0.90', 'was_manual_reviewed' => false,
    ]);

    // Solo existe un snapshot generado para la sucursal A (Atlacomulco), con la fila
    // del promotor homónimo.
    \App\Models\EfectividadCobranzaRun::query()->create(v4RunAttrs($period) + [
        'period_id' => $period->id, 'branch_id' => $branchA->id, 'status' => 'success',
        'finished_at' => now(), 'coverage' => ['status' => 'ok'],
        'result_rows' => [['promotor' => 'JUAN PEREZ LOPEZ', 'sano_cob' => 555, 'es_total' => false]],
        'source_fingerprint' => v4Fingerprint($period, $branchA->id),
    ]);

    $token = makeApiToken('homonym-consumer', ['collections.read']);

    // Empleado A (sucursal A verificada) → SÍ debe atribuirse al snapshot de A.
    $responseA = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/collections/effectiveness?period_id={$period->id}&scope=employee&employee_id={$employeeA->id}");
    $responseA->assertOk();
    expect($responseA->json('versions.approved.available'))->toBeTrue();
    expect((float) $responseA->json('versions.approved.row.sano_cob'))->toBe(555.0);
    expect($responseA->json('versions.approved.branch_id'))->toBe($branchA->id);

    // Empleado B (sucursal B verificada, MISMO nombre) → NUNCA debe recibir el
    // resultado de A solo porque el nombre coincide. No hay snapshot para B.
    $responseB = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/collections/effectiveness?period_id={$period->id}&scope=employee&employee_id={$employeeB->id}");
    $responseB->assertOk();
    expect($responseB->json('versions.approved.available'))->toBeFalse();
    expect($responseB->json('versions.approved.status'))->toBe('not_available');
});

it('Fase 1 (auditoría 2026-10-12): an employee with no verified branch assignment for this exact period is not_attributable, never resolved by "most recent assignment"', function () {
    $period = makeApiPeriod();
    $otherPeriod = \App\Models\Period::query()->create([
        'name' => 'Periodo anterior', 'code' => 'W-FIN-OTHER-' . uniqid(), 'type' => 'weekly',
        'year' => 2026, 'month' => 1, 'sequence' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-05', 'is_closed' => true,
    ]);
    $branch = \App\Models\Branch::query()->create(['code' => 'TUL', 'name' => 'TULA', 'normalized_name' => 'tula', 'is_active' => true]);
    $employee = \App\Models\Employee::query()->create(['full_name' => 'MARIA LOPEZ', 'normalized_name' => 'maria lopez', 'is_active' => true, 'source_system' => 'lendus_cobranza']);

    // Asignación conocida SOLO para un periodo distinto (anterior) — nunca debe usarse
    // como sustituto de "la asignación más reciente" para el periodo consultado.
    \App\Models\EmployeeBranchAssignment::query()->create([
        'period_id' => $otherPeriod->id, 'employee_id' => $employee->id, 'branch_id' => $branch->id,
        'source_type' => 'lendus', 'match_type' => 'normalized', 'confidence' => '0.90', 'was_manual_reviewed' => false,
    ]);

    $token = makeApiToken('no-assignment-consumer', ['collections.read']);
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/collections/effectiveness?period_id={$period->id}&scope=employee&employee_id={$employee->id}");

    $response->assertOk();
    expect($response->json('versions.approved.available'))->toBeFalse();
    expect($response->json('versions.approved.status'))->toBe('not_attributable');
    expect($response->json('versions.approved.reason'))->toContain('asignación de sucursal verificada');
});

it('Fase 1 (auditoría 2026-10-12): a route takeover (gestor sustituido) never leaks the predecessor result to the new assignee just because the row text still says the old name', function () {
    $period = makeApiPeriod();
    $branch = \App\Models\Branch::query()->create(['code' => 'TUL', 'name' => 'TULA', 'normalized_name' => 'tula', 'is_active' => true]);

    // El gestor NUEVO (sustituto) ya tiene asignación verificada a esta sucursal/periodo...
    $nuevoGestor = \App\Models\Employee::query()->create(['full_name' => 'PEDRO RAMIREZ', 'normalized_name' => 'pedro ramirez', 'is_active' => true, 'source_system' => 'lendus_cobranza']);
    \App\Models\EmployeeBranchAssignment::query()->create([
        'period_id' => $period->id, 'employee_id' => $nuevoGestor->id, 'branch_id' => $branch->id,
        'source_type' => 'lendus', 'match_type' => 'normalized', 'confidence' => '0.90', 'was_manual_reviewed' => false,
    ]);

    // ...pero el snapshot ya generado (el calculador aprobado ya resuelve la reasignación
    // de ruta internamente) solo tiene la fila a nombre del gestor nuevo — el residual del
    // gestor anterior nunca debe aparecer como una fila separada atribuible a otra persona.
    \App\Models\EfectividadCobranzaRun::query()->create(v4RunAttrs($period) + [
        'period_id' => $period->id, 'branch_id' => $branch->id, 'status' => 'success',
        'finished_at' => now(), 'coverage' => ['status' => 'ok'],
        'result_rows' => [['promotor' => 'PEDRO RAMIREZ', 'sano_cob' => 321, 'es_total' => false]],
        'source_fingerprint' => v4Fingerprint($period, $branch->id),
    ]);

    $token = makeApiToken('route-takeover-consumer', ['collections.read']);
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/collections/effectiveness?period_id={$period->id}&scope=employee&employee_id={$nuevoGestor->id}");

    $response->assertOk();
    expect($response->json('versions.approved.available'))->toBeTrue();
    expect((float) $response->json('versions.approved.row.sano_cob'))->toBe(321.0);
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

it('V4: la API nunca entrega como vigente un resultado de la calculadora anterior y declara la versión del motor', function () {
    $token = makeApiToken('collections-legacy-engine', ['collections.read']);
    $period = makeApiPeriod();
    $branch = \App\Models\Branch::query()->create(['code' => 'COR', 'name' => 'CORDOBA', 'normalized_name' => 'cordoba', 'is_active' => true]);

    $legacy = \App\Models\EfectividadCobranzaRun::query()->create([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'status' => 'success', 'engine_version' => 'aprobada-2026-10',
        'finished_at' => now(), 'result_rows' => [['promotor' => 'GRAN TOTAL', 'sano_cob' => 999, 'es_total' => true]],
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/collections/effectiveness?period_id={$period->id}&scope=branch&branch_id={$branch->id}");
    $response->assertOk();
    expect($response->json('versions.approved.available'))->toBeFalse()
        ->and($response->json('versions.approved.status'))->toBe('not_available')
        ->and($response->json('versions.approved.reason'))->toContain("run {$legacy->id}")
        ->and($response->json('versions.approved.engine_version'))->toBe('mrlana-v4-2026-10')
        ->and($response->json('versions.approved.version'))->toBe('approved_snapshot_v4')
        ->and($response->json('versions.approved.summary'))->toBeNull();
});

it('V4: un periodo mensual no tiene resultado V4 (no se agregan semanas) — not_supported_period', function () {
    $token = makeApiToken('collections-monthly', ['collections.read']);
    $month = Period::query()->create([
        'name' => 'Abril', 'code' => 'M-FIN-'.uniqid(), 'type' => 'monthly', 'year' => 2026, 'month' => 4, 'sequence' => 1,
        'start_date' => '2026-03-30', 'end_date' => '2026-04-26', 'is_closed' => false,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/collections/effectiveness?period_id={$month->id}");
    $response->assertOk();
    expect($response->json('versions.approved.status'))->toBe('not_supported_period')
        ->and($response->json('versions.legacy.version'))->toBe('legacy_vigente_atrasado_vencido');
});