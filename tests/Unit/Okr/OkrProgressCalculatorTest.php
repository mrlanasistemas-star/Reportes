<?php

use App\Models\OkrKpi;
use App\Services\Okr\OkrProgressCalculator;

// Test A — increase correcto.
it('computes correct raw progress for direction=increase', function () {
    $calc = new OkrProgressCalculator();
    // Base 300k, meta 400k, actual 350k → 50%.
    expect($calc->rawProgress(300000, 400000, 350000, OkrKpi::DIRECTION_INCREASE))->toBe(50.0);
    // Sub-cumplimiento.
    expect($calc->rawProgress(300000, 400000, 300000, OkrKpi::DIRECTION_INCREASE))->toBe(0.0);
    // Sobre-cumplimiento — raw permite >100%.
    expect($calc->rawProgress(300000, 400000, 450000, OkrKpi::DIRECTION_INCREASE))->toBe(150.0);
});

// Test B — decrease correcto (mora: base 12.5%, meta 8%).
it('computes correct raw progress for direction=decrease', function () {
    $calc = new OkrProgressCalculator();
    expect($calc->rawProgress(12.5, 8.0, 10.25, OkrKpi::DIRECTION_DECREASE))->toBe(50.0);
    expect($calc->rawProgress(12.5, 8.0, 12.5, OkrKpi::DIRECTION_DECREASE))->toBe(0.0);
    expect($calc->rawProgress(12.5, 8.0, 8.0, OkrKpi::DIRECTION_DECREASE))->toBe(100.0);
    // Mejor que la meta — sobre-cumplimiento: (12.5-6.0)/(12.5-8.0) = 144.44%.
    expect($calc->rawProgress(12.5, 8.0, 6.0, OkrKpi::DIRECTION_DECREASE))->toBe(144.44);
});

// Test C — target == baseline nunca divide entre cero.
it('never divides by zero when target equals baseline', function () {
    $calc = new OkrProgressCalculator();
    expect($calc->rawProgress(100.0, 100.0, 100.0, OkrKpi::DIRECTION_INCREASE))->toBe(100.0);
    expect($calc->rawProgress(100.0, 100.0, 50.0, OkrKpi::DIRECTION_INCREASE))->toBe(0.0);
    expect($calc->rawProgress(10.0, 10.0, 10.0, OkrKpi::DIRECTION_DECREASE))->toBe(100.0);
    expect($calc->rawProgress(10.0, 10.0, 15.0, OkrKpi::DIRECTION_DECREASE))->toBe(0.0);
});

it('returns null when baseline/target/current are missing (nulls handled)', function () {
    $calc = new OkrProgressCalculator();
    expect($calc->rawProgress(null, 100.0, 50.0, OkrKpi::DIRECTION_INCREASE))->toBeNull();
    expect($calc->rawProgress(0.0, null, 50.0, OkrKpi::DIRECTION_INCREASE))->toBeNull();
    expect($calc->rawProgress(0.0, 100.0, null, OkrKpi::DIRECTION_INCREASE))->toBeNull();
});

it('display progress never goes negative but allows over-compliance', function () {
    $calc = new OkrProgressCalculator();
    expect($calc->displayProgress(-30.0))->toBe(0.0);
    expect($calc->displayProgress(134.0))->toBe(134.0);
    expect($calc->displayProgress(null))->toBeNull();
});

it('weighted contribution caps over-compliance so one KR cannot compensate the rest (documented and tested)', function () {
    $calc = new OkrProgressCalculator();
    // 300% de cumplimiento con peso 50% — sin tope daría 150 puntos (imposible,
    // "tapa" el resto). Con el tope por defecto (150%), se limita a 75 puntos.
    expect($calc->weightedContribution(300.0, 50.0))->toBe(75.0);
    // Dentro del tope, contribuye proporcional normal.
    expect($calc->weightedContribution(80.0, 50.0))->toBe(40.0);
    // Tope configurable explícito.
    expect($calc->weightedContribution(300.0, 50.0, 200.0))->toBe(100.0);
});

it('objective compliance is the sum of weighted KR contributions — the ONE canonical formula', function () {
    $calc = new OkrProgressCalculator();
    $keyResults = [
        ['raw_progress' => 80.0, 'weight' => 50.0],
        ['raw_progress' => 60.0, 'weight' => 30.0],
        ['raw_progress' => 40.0, 'weight' => 20.0],
    ];
    // 80*0.5 + 60*0.3 + 40*0.2 = 40 + 18 + 8 = 66.
    expect($calc->objectiveCompliance($keyResults))->toBe(66.0);
});

it('deviation in percentage points is actual minus expected', function () {
    $calc = new OkrProgressCalculator();
    expect($calc->deviationPp(68.0, 75.0))->toBe(-7.0);
    expect($calc->deviationPp(80.0, 75.0))->toBe(5.0);
    expect($calc->deviationPp(null, 75.0))->toBeNull();
});
