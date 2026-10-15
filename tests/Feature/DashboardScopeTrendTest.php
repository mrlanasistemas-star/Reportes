<?php

use App\Models\Branch;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\DashboardTrendService;
use App\Services\RadiografiaExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Fase 7.1 (15-oct-2026) — la tendencia EBITDA/OPEX del Dashboard sigue el alcance
 * elegido: con sucursal sale del snapshot de ESA sucursal (mismo buildSnapshot()
 * scope=branch de los KPI), sin sucursal del consolidado. Caché por punto
 * (periodo + versión del resumen + alcance).
 */
function trendPeriod(string $name, int $month): Period
{
    $p = Period::query()->create(['name' => $name, 'code' => 'TS-' . uniqid(), 'type' => 'monthly', 'year' => 2026, 'month' => $month, 'sequence' => 1,
        'start_date' => sprintf('2026-%02d-01', $month), 'end_date' => sprintf('2026-%02d-28', $month)]);
    PeriodSummary::query()->create(['period_id' => $p->id, 'status' => 'generated']);

    return $p;
}

it('builds the trend from the branch snapshot when a branch is selected and from the consolidated one otherwise', function () {
    $may = trendPeriod('Mayo 2026', 5);
    $jun = trendPeriod('Junio 2026', 6);
    $configs = [];
    $this->mock(RadiografiaExportService::class, function ($m) use (&$configs, $may) {
        $m->shouldReceive('buildSnapshot')->andReturnUsing(function (Period $p, array $config) use (&$configs, $may) {
            $configs[] = [$p->id, $config];
            if (($config['scope'] ?? null) === 'branch') {
                // La sucursal no tuvo radiografía en Mayo: nunca un 0 inventado.
                if ($p->id === $may->id) {
                    return ['scope' => ['type' => 'branch', 'available' => false], 'summary' => ['ebitda_final' => null, 'opex_total' => null]];
                }

                return ['scope' => ['type' => 'branch', 'available' => true], 'summary' => ['ebitda_final' => 123.456, 'opex_total' => 50.0]];
            }

            return ['scope' => ['type' => 'general', 'available' => true], 'summary' => ['ebitda_final' => 1000.0, 'opex_total' => 400.0]];
        });
    });
    $svc = app(DashboardTrendService::class);

    $general = $svc->build();
    expect(array_column($general, 'label'))->toBe(['Mayo 2026', 'Junio 2026'])
        ->and(array_column($general, 'ebitda'))->toBe([1000.0, 1000.0]);

    $branch = $svc->build(88);
    expect($branch[0])->toMatchArray(['label' => 'Mayo 2026', 'ebitda' => null, 'opex' => null, 'available' => false])
        ->and($branch[1])->toMatchArray(['label' => 'Junio 2026', 'ebitda' => 123.46, 'opex' => 50.0, 'available' => true]);

    // El consolidado usa la corrida general ([]), la sucursal su propio alcance.
    expect($configs)->toContain([$jun->id, []])->toContain([$jun->id, ['scope' => 'branch', 'branch_id' => 88]]);

    // Caché separada por alcance: repetir no recalcula ninguno de los dos.
    $calls = count($configs);
    $svc->build();
    $svc->build(88);
    expect(count($configs))->toBe($calls);
});

it('invalidates only the regenerated period point for every scope', function () {
    $may = trendPeriod('Mayo 2026', 5);
    $jun = trendPeriod('Junio 2026', 6);
    $ebitda = 10.0;
    $calls = [];
    $this->mock(RadiografiaExportService::class, function ($m) use (&$calls, &$ebitda) {
        $m->shouldReceive('buildSnapshot')->andReturnUsing(function (Period $p, array $config) use (&$calls, &$ebitda) {
            $calls[] = $p->id;

            return ['scope' => ['available' => true], 'summary' => ['ebitda_final' => $ebitda, 'opex_total' => 1.0]];
        });
    });
    $svc = app(DashboardTrendService::class);
    $svc->build(88);
    expect($calls)->toHaveCount(2);

    $ebitda = 99.0;
    $this->travel(2)->seconds();
    PeriodSummary::query()->where('period_id', $jun->id)->first()->update(['generated_at' => now()]);

    $points = $svc->build(88);
    expect($calls)->toHaveCount(3)->and(end($calls))->toBe($jun->id)
        ->and($points[0]['ebitda'])->toBe(10.0)   // Mayo: sin cambios, desde caché
        ->and($points[1]['ebitda'])->toBe(99.0);  // Junio: regenerado, recalculado
});

it('serves the branch trend through the endpoint with the scope label', function () {
    trendPeriod('Junio 2026', 6);
    $branch = Branch::query()->create(['name' => 'ATLIXCO', 'code' => 'ATL']);
    $this->mock(RadiografiaExportService::class, function ($m) {
        $m->shouldReceive('buildSnapshot')->andReturn(['scope' => ['available' => true], 'summary' => ['ebitda_final' => 5.0, 'opex_total' => 2.0]]);
    });

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->getJson(route('dashboard.trend', ['branch_id' => $branch->id]))
        ->assertOk()
        ->assertJsonPath('scope.branch_id', $branch->id)
        ->assertJsonPath('scope.label', 'ATLIXCO')
        ->assertJsonPath('trend.0.ebitda', 5);
});
