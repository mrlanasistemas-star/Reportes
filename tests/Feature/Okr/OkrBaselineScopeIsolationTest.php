<?php

use App\Models\Branch;
use App\Models\OkrKpi;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\Okr\OkrKpiValueResolver;
use App\Services\Radiography\RadiographySnapshotBuilder;
use App\Services\RadiografiaExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Parte 4 del cierre (04-oct-2026) — "AUDITAR ESTO PRIMERO": auditoría confirmó
 * que el camino normal (scope_type=employee -> OkrKpiValueResolver ->
 * RadiografiaExportService::buildSnapshot -> RadiographySnapshotBuilder::
 * applyEmployeeScope) SÍ usa el dato individual del gestor, nunca el total de
 * sucursal (ver tests\Feature\RadiographyScopeTest.php y
 * tests\Integration\OkrKpiParityTest.php, ya existentes). El riesgo real
 * encontrado era más sutil: si branch_id/employee_id llegaran en 0/null (p.ej.
 * un Objective mal formado), RadiographySnapshotBuilder::applyScope()
 * devolvía el snapshot SIN la clave 'scope', y OkrKpiValueResolver::getValue()
 * interpretaba esa ausencia como `?? true` (disponible) — cayendo así, de
 * forma silenciosa, en el summary GENERAL (ni siquiera el de sucursal: el de
 * TODA la empresa). Estos tests fijan esa corrección para que no regrese.
 */
function okrScopeIsolationKpi(string $providerKey = 'reporteria.placement'): OkrKpi
{
    return OkrKpi::query()->create([
        'name' => 'KPI de prueba (aislamiento de scope)', 'code' => 'scope_isolation_' . str_replace('.', '_', $providerKey) . '_' . uniqid(),
        'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE, 'direction' => OkrKpi::DIRECTION_INCREASE,
        'automation' => OkrKpi::AUTOMATION_AUTOMATIC, 'provider_key' => $providerKey, 'is_active' => true,
    ]);
}

function okrScopeIsolationPeriod(): Period
{
    $period = Period::query()->create([
        'name' => 'Periodo aislamiento de scope', 'code' => 'SCOPE-ISOLATION-' . uniqid(), 'type' => 'monthly',
        'year' => 2026, 'month' => 9, 'sequence' => 1, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
    ]);
    PeriodSummary::query()->create(['period_id' => $period->id, 'status' => 'generated']);

    return $period;
}

it('never falls back to the GENERAL summary when scope=employee and the snapshot has no scope key (employee_id=0 edge case)', function () {
    $period = okrScopeIsolationPeriod();

    // Simula EXACTAMENTE el bug: snapshot sin clave 'scope' (como devolvía
    // applyScope() antes de la corrección cuando employee_id resolvía a 0) y
    // con un total GENERAL de toda la empresa, muy distinto al de un gestor.
    $exportService = Mockery::mock(RadiografiaExportService::class);
    $exportService->shouldReceive('buildSnapshot')
        ->andReturn(['summary' => ['placement_total' => 12000000.0]]); // total de TODA la empresa
    app()->instance(RadiografiaExportService::class, $exportService);

    $kpi = okrScopeIsolationKpi();
    $resolver = app(OkrKpiValueResolver::class);

    // employee_id=0 — nunca debe leer el total general como si fuera del gestor.
    $value = $resolver->getValue($kpi, 'employee', null, 0, $period);

    expect($value)->toBeNull();
});

it('never falls back to the GENERAL summary when scope=branch and the snapshot has no scope key (branch_id=0 edge case)', function () {
    $period = okrScopeIsolationPeriod();

    $exportService = Mockery::mock(RadiografiaExportService::class);
    $exportService->shouldReceive('buildSnapshot')
        ->andReturn(['summary' => ['placement_total' => 12000000.0]]);
    app()->instance(RadiografiaExportService::class, $exportService);

    $kpi = okrScopeIsolationKpi();
    $resolver = app(OkrKpiValueResolver::class);

    $value = $resolver->getValue($kpi, 'branch', 0, null, $period);

    expect($value)->toBeNull();
});

it('still reads the summary normally for scope=general even without a scope key', function () {
    $period = okrScopeIsolationPeriod();

    $exportService = Mockery::mock(RadiografiaExportService::class);
    $exportService->shouldReceive('buildSnapshot')
        ->andReturn(['summary' => ['placement_total' => 12000000.0]]);
    app()->instance(RadiografiaExportService::class, $exportService);

    $kpi = okrScopeIsolationKpi();
    $resolver = app(OkrKpiValueResolver::class);

    $value = $resolver->getValue($kpi, 'general', null, null, $period);

    expect($value)->toBe(12000000.0);
});

it('explicitly marks scope unavailable (never general) at the builder level when branch_id/employee_id resolve to zero', function () {
    $builder = app(RadiographySnapshotBuilder::class);
    $ref = new ReflectionMethod($builder, 'applyScope');
    $ref->setAccessible(true);
    $period = okrScopeIsolationPeriod();

    $snapshot = ['summary' => ['placement_total' => 12000000.0]];

    $resultBranch = $ref->invoke($builder, $snapshot, ['scope' => 'branch', 'branch_id' => 0], $period, [], []);
    expect($resultBranch['scope']['available'])->toBeFalse();
    expect($resultBranch['scope']['type'])->toBe('branch');

    $resultEmployee = $ref->invoke($builder, $snapshot, ['scope' => 'employee', 'employee_id' => 0], $period, [], []);
    expect($resultEmployee['scope']['available'])->toBeFalse();
    expect($resultEmployee['scope']['type'])->toBe('employee');
});

/**
 * TEST 1 / TEST 2 del cierre funcional (Parte 19): mismo periodo, mismo branch
 * real — el baseline de un Objective scope=employee debe ser la colocación
 * INDIVIDUAL del gestor, nunca el total de la sucursal, y viceversa.
 */
it('TEST 1/2 — employee baseline uses the individual placement, branch baseline uses the branch total, never swapped', function () {
    $period = okrScopeIsolationPeriod();
    $branch = Branch::query()->create(['code' => 'CVA', 'name' => 'CUERNAVACA', 'normalized_name' => 'cuernavaca', 'is_active' => true]);

    $exportService = Mockery::mock(RadiografiaExportService::class);
    $exportService->shouldReceive('buildSnapshot')
        ->with(Mockery::any(), Mockery::on(fn ($c) => ($c['scope'] ?? null) === 'branch'))
        ->andReturn(['scope' => ['type' => 'branch', 'available' => true], 'summary' => ['placement_total' => 3000000.0]]);
    $exportService->shouldReceive('buildSnapshot')
        ->with(Mockery::any(), Mockery::on(fn ($c) => ($c['scope'] ?? null) === 'employee'))
        ->andReturn(['scope' => ['type' => 'employee', 'available' => true], 'summary' => ['placement_total' => 700000.0]]);
    app()->instance(RadiografiaExportService::class, $exportService);

    $kpi = okrScopeIsolationKpi();
    $resolver = app(OkrKpiValueResolver::class);

    $branchValue = $resolver->getValue($kpi, 'branch', $branch->id, null, $period);
    $employeeValue = $resolver->getValue($kpi, 'employee', null, 701, $period);

    expect($branchValue)->toBe(3000000.0);
    expect($employeeValue)->toBe(700000.0);
    expect($employeeValue)->not->toBe($branchValue);
});
