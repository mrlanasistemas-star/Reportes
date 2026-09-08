<?php

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\Okr\OkrTrackingPeriodResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Tests AT#1/AT#2 — el periodo de seguimiento se resuelve SEGÚN LA FECHA
 * evaluada, nunca "siempre el último mensual generado" sin relación con la
 * semana real que se está evaluando (bug crítico corregido 08-sep-2026).
 */
function okrGeneratedMonthlyPeriod(int $year, int $month, string $start, string $end): Period
{
    $period = Period::query()->create([
        'name' => "Mes {$month}/{$year}", 'code' => "TEST-{$year}-{$month}-" . uniqid(),
        'type' => 'monthly', 'year' => $year, 'month' => $month, 'sequence' => 1,
        'start_date' => $start, 'end_date' => $end,
    ]);

    PeriodSummary::query()->create(['period_id' => $period->id, 'status' => 'generated']);

    return $period;
}

it('resolves the period whose date range actually contains the given date, not just the latest one', function () {
    $august    = okrGeneratedMonthlyPeriod(2026, 8, '2026-08-01', '2026-08-31');
    $september = okrGeneratedMonthlyPeriod(2026, 9, '2026-09-01', '2026-09-30');

    $resolver = app(OkrTrackingPeriodResolver::class);

    // Una fecha de agosto debe resolver AGOSTO, aunque septiembre (más reciente) ya exista generado.
    expect($resolver->getPeriodForDate('2026-08-15')->id)->toBe($august->id);
    // Una fecha de septiembre debe resolver SEPTIEMBRE.
    expect($resolver->getPeriodForDate('2026-09-15')->id)->toBe($september->id);
});

it('resolves a different period per objective week when weeks span different months — never always the latest', function () {
    $august    = okrGeneratedMonthlyPeriod(2026, 8, '2026-08-01', '2026-08-31');
    $september = okrGeneratedMonthlyPeriod(2026, 9, '2026-09-01', '2026-09-30');

    $resolver = app(OkrTrackingPeriodResolver::class);
    $objectiveStart = '2026-08-15'; // semana 1 empieza aquí

    // Semana 1 (15-21 ago) cae en agosto.
    $periodWeek1 = $resolver->getPeriodForObjectiveWeek($objectiveStart, 1);
    // Semana 4 (5-11 sep) cae en septiembre.
    $periodWeek4 = $resolver->getPeriodForObjectiveWeek($objectiveStart, 4);

    expect($periodWeek1->id)->toBe($august->id);
    expect($periodWeek4->id)->toBe($september->id);
    expect($periodWeek1->id)->not->toBe($periodWeek4->id); // nunca el mismo periodo "porque es el más reciente"
});

it('falls back to the latest generated period at or before the date when the exact month has no radiography yet', function () {
    $august = okrGeneratedMonthlyPeriod(2026, 8, '2026-08-01', '2026-08-31');
    // Septiembre existe como Period pero SIN PeriodSummary generado (mes en curso, radiografía no cerrada todavía).
    Period::query()->create([
        'name' => 'Mes 9/2026', 'code' => 'TEST-2026-9-nogen', 'type' => 'monthly',
        'year' => 2026, 'month' => 9, 'sequence' => 1, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
    ]);

    $resolver = app(OkrTrackingPeriodResolver::class);

    expect($resolver->getPeriodForDate('2026-09-15')->id)->toBe($august->id);
});

it('never returns a future period for a past date', function () {
    okrGeneratedMonthlyPeriod(2026, 10, '2026-10-01', '2026-10-31');

    $resolver = app(OkrTrackingPeriodResolver::class);

    expect($resolver->getPeriodForDate('2026-08-01'))->toBeNull();
});
