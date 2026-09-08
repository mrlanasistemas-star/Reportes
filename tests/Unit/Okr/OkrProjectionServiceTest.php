<?php

use App\Models\OkrKpi;
use App\Services\Okr\OkrProgressCalculator;
use App\Services\Okr\OkrProjectionService;

// Test Q — proyección no falla con semana 0/1, y reproduce el ejemplo conceptual
// del pedido (meta 1,000,000, 8 semanas, semana 5, actual 510,000 → 816,000).
it('projects cumulative KPIs using observed rate, matching the conceptual example', function () {
    $svc = new OkrProjectionService();
    expect($svc->projectCumulative(510000, 5, 8))->toBe(816000.0);
});

it('cumulative projection never fails at week 0 (no rate observed yet)', function () {
    $svc = new OkrProjectionService();
    expect($svc->projectCumulative(0, 0, 8))->toBe(0.0);
});

it('cumulative projection works at week 1', function () {
    $svc = new OkrProjectionService();
    // Ritmo 100k/semana en semana 1, 8 semanas totales → proyección 800k.
    expect($svc->projectCumulative(100000, 1, 8))->toBe(800000.0);
});

// Test K/balance — tendencia con snapshots insuficientes hace fallback conservador.
it('trend projection falls back conservatively to the current value with fewer than 2 historical points', function () {
    $svc = new OkrProjectionService();
    expect($svc->projectTrend([], 5000000.0, 4))->toBe(5000000.0);
    expect($svc->projectTrend([5000000.0], 5000000.0, 4))->toBe(5000000.0);
});

it('trend projection extrapolates the average delta between consecutive snapshots', function () {
    $svc = new OkrProjectionService();
    // Cartera creciendo ~100k/semana en 3 puntos, 2 semanas restantes → +200k.
    expect($svc->projectTrend([5000000.0, 5100000.0, 5200000.0], 5200000.0, 2))->toBe(5400000.0);
});

it('projected compliance percentage reuses the ONE canonical progress formula, never a second one', function () {
    $svc  = new OkrProjectionService();
    $calc = new OkrProgressCalculator();
    // Proyección 816,000 contra base 0 / meta 1,000,000 → 81.6% (ejemplo conceptual).
    expect($svc->projectedCompliancePercentage(816000, 0.0, 1000000.0, OkrKpi::DIRECTION_INCREASE, $calc))->toBe(81.6);
});
