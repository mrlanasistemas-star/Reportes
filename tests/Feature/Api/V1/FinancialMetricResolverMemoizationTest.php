<?php

use App\Models\Period;
use App\Services\Api\FinancialMetricResolver;
use App\Services\RadiografiaExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Fase 3.1 — un endpoint que resuelve N métricas para el MISMO periodo+alcance
 * debe construir el snapshot de radiografía UNA sola vez, nunca N veces.
 */
it('builds the radiography snapshot only once per request even when resolving many metrics for the same period/scope', function () {
    $period = Period::query()->create([
        'name' => 'Semana Memo Test', 'code' => 'W-MEMO-' . uniqid(), 'type' => 'weekly',
        'year' => 2026, 'month' => 4, 'sequence' => 1, 'start_date' => '2026-04-01', 'end_date' => '2026-04-05', 'is_closed' => false,
    ]);

    $fakeSnapshot = [
        'generated_at' => 'now',
        'scope' => ['available' => true],
        'summary' => ['ebitda_final' => 100.0, 'margen_ebitda' => 10.0, 'opex_total' => 50.0, 'placement_total' => 200.0, 'recovery_total' => 150.0, 'mora_index' => 5.0, 'portfolio_total' => 1000.0, 'overdue_portfolio' => 50.0],
        'sections' => [],
    ];

    $mockExportService = Mockery::mock(RadiografiaExportService::class);
    $mockExportService->shouldReceive('buildSnapshot')
        ->once() // <-- la aserción real: sin memoización, esto se llamaría 1 vez POR métrica
        ->with($period, ['scope' => 'general'])
        ->andReturn($fakeSnapshot);

    $resolver = new FinancialMetricResolver($mockExportService);

    $metrics = ['ebitda', 'ebitda_margin', 'opex', 'placement', 'recovery', 'mora', 'portfolio_total', 'portfolio_overdue', 'portfolio_current'];
    foreach ($metrics as $code) {
        $resolver->resolve($code, $period, 'general');
    }

    // Mockery verifica ->once() automáticamente al terminar el test.
    expect(true)->toBeTrue();
});

it('builds a SEPARATE snapshot per distinct scope (branch A vs branch B), never shares memoization across scopes', function () {
    $period = Period::query()->create([
        'name' => 'Semana Memo Test 2', 'code' => 'W-MEMO2-' . uniqid(), 'type' => 'weekly',
        'year' => 2026, 'month' => 4, 'sequence' => 1, 'start_date' => '2026-04-01', 'end_date' => '2026-04-05', 'is_closed' => false,
    ]);

    $snapshotFor = fn (int $branchId) => [
        'generated_at' => 'now', 'scope' => ['available' => true],
        'summary' => ['ebitda_final' => $branchId * 10.0], 'sections' => [],
    ];

    $mockExportService = Mockery::mock(RadiografiaExportService::class);
    $mockExportService->shouldReceive('buildSnapshot')->once()->with($period, ['scope' => 'branch', 'branch_id' => 5])->andReturn($snapshotFor(5));
    $mockExportService->shouldReceive('buildSnapshot')->once()->with($period, ['scope' => 'branch', 'branch_id' => 9])->andReturn($snapshotFor(9));

    $resolver = new FinancialMetricResolver($mockExportService);

    $a1 = $resolver->resolve('ebitda', $period, 'branch', 5);
    $a2 = $resolver->resolve('opex', $period, 'branch', 5); // misma sucursal, debe reusar memo
    $b1 = $resolver->resolve('ebitda', $period, 'branch', 9);

    expect($a1['value'])->toBe(50.0);
    expect($b1['value'])->toBe(90.0);
});
