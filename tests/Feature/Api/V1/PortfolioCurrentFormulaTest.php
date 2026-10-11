<?php

use App\Models\Period;
use App\Services\Api\FinancialMetricResolver;
use App\Services\RadiografiaExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Fase 4.1 — portfolio_current = portfolio_total - portfolio_overdue. Nunca
 * presentar un "vigente" negativo como si fuera un valor válido.
 */
it('computes portfolio_current as the straightforward complement when data is consistent', function () {
    $period = Period::query()->create([
        'name' => 'Semana Portfolio', 'code' => 'W-PORT-' . uniqid(), 'type' => 'weekly',
        'year' => 2026, 'month' => 4, 'sequence' => 1, 'start_date' => '2026-04-01', 'end_date' => '2026-04-05', 'is_closed' => false,
    ]);

    $mock = Mockery::mock(RadiografiaExportService::class);
    $mock->shouldReceive('buildSnapshot')->once()->andReturn([
        'generated_at' => 'now', 'scope' => ['available' => true],
        'summary' => ['portfolio_total' => 1000.0, 'overdue_portfolio' => 150.0],
        'sections' => [],
    ]);

    $resolver = new FinancialMetricResolver($mock);
    $result = $resolver->resolve('portfolio_current', $period, 'general');

    expect($result['value'])->toBe(850.0);
    expect($result['status'])->toBe('ok');
});

it('returns null (never a negative value) when overdue_portfolio inconsistently exceeds portfolio_total', function () {
    $period = Period::query()->create([
        'name' => 'Semana Portfolio Inconsistente', 'code' => 'W-PORT2-' . uniqid(), 'type' => 'weekly',
        'year' => 2026, 'month' => 4, 'sequence' => 1, 'start_date' => '2026-04-01', 'end_date' => '2026-04-05', 'is_closed' => false,
    ]);

    $mock = Mockery::mock(RadiografiaExportService::class);
    $mock->shouldReceive('buildSnapshot')->once()->andReturn([
        'generated_at' => 'now', 'scope' => ['available' => true],
        'summary' => ['portfolio_total' => 100.0, 'overdue_portfolio' => 150.0], // inconsistente
        'sections' => [],
    ]);

    $resolver = new FinancialMetricResolver($mock);
    $result = $resolver->resolve('portfolio_current', $period, 'general');

    expect($result['value'])->toBeNull();
    expect($result['status'])->toBe('not_available');
});
