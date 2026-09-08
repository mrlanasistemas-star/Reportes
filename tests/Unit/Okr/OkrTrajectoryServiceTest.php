<?php

use App\Services\Okr\OkrTrajectoryService;

// Test N — trayectoria semana 4/8 correcta.
it('computes the expected value at week 4 of 8 as the linear midpoint between baseline and target', function () {
    $svc = new OkrTrajectoryService();
    // Base 300k, meta 400k, semana 4 de 8 → 50% transcurrido → 350k esperado.
    expect($svc->expectedValue(300000, 400000, 4, 8))->toBe(350000.0);
    expect($svc->expectedProgressPercentage(4, 8))->toBe(50.0);
});

it('adapts correctly to a decreasing target without a special branch (sign comes from target-baseline)', function () {
    $svc = new OkrTrajectoryService();
    // Mora base 12.5%, meta 8% — semana 4/8 → esperado 10.25%.
    expect($svc->expectedValue(12.5, 8.0, 4, 8))->toBe(10.25);
});

it('clamps elapsed fraction to [0, 1] — never negative, never beyond week 1 of the plan', function () {
    $svc = new OkrTrajectoryService();
    expect($svc->elapsedFraction(0, 8))->toBe(0.0);
    expect($svc->elapsedFraction(12, 8))->toBe(1.0); // más allá del plazo, tope 100%
    expect($svc->expectedValue(300000, 400000, 8, 8))->toBe(400000.0); // semana final = meta exacta
});

it('never divides by zero when totalWeeks is 0', function () {
    $svc = new OkrTrajectoryService();
    expect($svc->elapsedFraction(3, 0))->toBe(1.0);
});
