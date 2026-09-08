<?php

use App\Models\OkrObjective;
use App\Services\Okr\OkrHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Test O/P — semáforo según thresholds (por defecto).
it('classifies health status according to default thresholds', function () {
    $svc = new OkrHealthService();
    expect($svc->classify(10.0))->toBe(OkrObjective::HEALTH_AHEAD);   // >= +5
    expect($svc->classify(5.0))->toBe(OkrObjective::HEALTH_AHEAD);
    expect($svc->classify(0.0))->toBe(OkrObjective::HEALTH_ON_TRACK); // entre -5 y +5
    expect($svc->classify(-5.0))->toBe(OkrObjective::HEALTH_ON_TRACK);
    expect($svc->classify(-7.0))->toBe(OkrObjective::HEALTH_RISK);    // entre -15 y -5
    expect($svc->classify(-15.0))->toBe(OkrObjective::HEALTH_RISK);
    expect($svc->classify(-20.0))->toBe(OkrObjective::HEALTH_OFF_TRACK); // < -15
});

it('never throws or errors with null deviation — defaults to neutral on_track', function () {
    $svc = new OkrHealthService();
    expect($svc->classify(null))->toBe(OkrObjective::HEALTH_ON_TRACK);
});

it('worstOf() returns the worst health among key results, not an averaged-away optimistic one', function () {
    $svc = new OkrHealthService();
    expect($svc->worstOf([OkrObjective::HEALTH_AHEAD, OkrObjective::HEALTH_OFF_TRACK, OkrObjective::HEALTH_ON_TRACK]))
        ->toBe(OkrObjective::HEALTH_OFF_TRACK);
    expect($svc->worstOf([OkrObjective::HEALTH_AHEAD, OkrObjective::HEALTH_ON_TRACK]))
        ->toBe(OkrObjective::HEALTH_ON_TRACK);
    expect($svc->worstOf([]))->toBe(OkrObjective::HEALTH_AHEAD);
});
