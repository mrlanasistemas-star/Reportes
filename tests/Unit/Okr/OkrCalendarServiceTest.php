<?php

use App\Services\Okr\OkrCalendarService;

/**
 * Test punto 30 S — bug corregido 09-sep-2026: `end_date` usaba
 * `start->addWeeks($n)`, un día MÁS que el fin real de la semana N (inclusive).
 * 8 semanas desde 01/09 deben terminar 26/10, nunca 27/10.
 */
it('computes the inclusive end date exactly one day before the start of week N+1', function () {
    $calendar = new OkrCalendarService();

    $end = $calendar->endDate('2026-09-01', 8);

    expect($end->toDateString())->toBe('2026-10-26');
    expect($end->toDateString())->not->toBe('2026-10-27');
});

it('computes weekStart/weekEnd inclusively and consecutively, no gaps or overlaps', function () {
    $calendar = new OkrCalendarService();
    $start = '2026-01-01';

    expect($calendar->weekStart($start, 1)->toDateString())->toBe('2026-01-01');
    expect($calendar->weekEnd($start, 1)->toDateString())->toBe('2026-01-07');
    expect($calendar->weekStart($start, 2)->toDateString())->toBe('2026-01-08'); // día siguiente al fin de semana 1
    expect($calendar->weekEnd($start, 2)->toDateString())->toBe('2026-01-14');
});

it('currentWeekNumber returns 0 before the start date and never exceeds duration_weeks', function () {
    $calendar = new OkrCalendarService();

    expect($calendar->currentWeekNumber('2099-01-01', 8, '2026-01-01'))->toBe(0);
    expect($calendar->currentWeekNumber('2026-01-01', 8, '2026-01-01'))->toBe(1);
    expect($calendar->currentWeekNumber('2026-01-01', 8, '2026-01-07'))->toBe(1);
    expect($calendar->currentWeekNumber('2026-01-01', 8, '2026-01-08'))->toBe(2);
    expect($calendar->currentWeekNumber('2026-01-01', 8, '2027-01-01'))->toBe(8); // muy adelante — se limita a duration_weeks
});

it('remainingWeeks never goes negative', function () {
    $calendar = new OkrCalendarService();

    expect($calendar->remainingWeeks(3, 8))->toBe(5);
    expect($calendar->remainingWeeks(8, 8))->toBe(0);
    expect($calendar->remainingWeeks(10, 8))->toBe(0);
});
