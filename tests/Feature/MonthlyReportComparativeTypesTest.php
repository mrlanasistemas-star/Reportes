<?php

use App\Models\Period;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Comparativos coherentes (23-nov-2026): un comparativo heredado «mes vs bimestre» no
 * se lista, y la pantalla de comparativo nunca se queda con un Periodo B de otro tipo.
 */
function ctPeriod(string $type, int $month, int $seq, string $start, string $end): Period
{
    $p = Period::query()->create([
        'name' => "{$type} {$month}", 'code' => sprintf('%s-2026-%02d-%d', strtoupper(substr($type, 0, 2)), $month, $seq), 'type' => $type,
        'year' => 2026, 'month' => $month, 'sequence' => $seq, 'start_date' => $start, 'end_date' => $end, 'is_closed' => false, 'component_period_ids' => [],
    ]);
    PeriodSummary::query()->create(['period_id' => $p->id, 'status' => 'generated', 'generated_at' => now(), 'global_metrics' => []]);

    return $p;
}

it('hides legacy comparatives between periods of different types from the list', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $oct = ctPeriod('monthly', 10, 1, '2026-09-14', '2026-10-11');
    $sep = ctPeriod('monthly', 9, 1, '2026-08-17', '2026-09-13');
    $bim = ctPeriod('bimonthly', 9, 5, '2026-08-17', '2026-10-11');

    foreach ([[$oct, $sep], [$bim, $oct]] as [$a, $b]) {
        PeriodRadiographyRun::query()->create([
            'period_id' => $a->id, 'comparison_period_id' => $b->id, 'report_type' => 'month_vs_month', 'scope' => 'general',
            'status' => 'success', 'finished_at' => now(), 'period_summary_id' => PeriodSummary::query()->where('period_id', $a->id)->value('id'),
        ]);
    }

    $reports = $this->actingAs($user)->get('/reportes-mensuales')->assertOk()->viewData('page')['props']['generatedReports'];
    $comparisons = collect($reports)->whereNotNull('comparison_period');

    expect($comparisons)->toHaveCount(1)
        ->and($comparisons->first()['period_id'])->toBe($oct->id);
});

it('replaces an invalid Periodo B with the previous period of the same type', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $b4 = ctPeriod('bimonthly', 7, 4, '2026-06-22', '2026-08-16');
    $b5 = ctPeriod('bimonthly', 9, 5, '2026-08-17', '2026-10-11');
    $oct = ctPeriod('monthly', 10, 1, '2026-09-14', '2026-10-11');

    $props = $this->actingAs($user)
        ->get("/reportes-mensuales/comparativo?period_a={$b5->id}&period_b={$oct->id}")
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['initialPeriodAId'])->toBe($b5->id)
        ->and($props['initialPeriodBId'])->toBe($b4->id);
});
