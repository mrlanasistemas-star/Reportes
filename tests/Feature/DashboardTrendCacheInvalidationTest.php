<?php

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\DashboardTrendService;
use App\Services\RadiografiaExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Prioridad 12: la segunda apertura reutiliza la caché, pero si el periodo se
 * regenera (cambia su resumen) la tendencia se recalcula al instante.
 */
it('reutiliza la tendencia en caché y la invalida en cuanto el resumen del periodo cambia', function () {
    $p = Period::query()->create(['name' => 'Agosto 2026', 'code' => 'TR-'.uniqid(), 'type' => 'monthly', 'year' => 2026, 'month' => 8, 'sequence' => 1,
        'start_date' => '2026-07-27', 'end_date' => '2026-08-30']);
    $summary = PeriodSummary::query()->create(['period_id' => $p->id, 'status' => 'generated']);

    $calls = 0;
    $ebitda = 100.0;
    $this->mock(RadiografiaExportService::class, function ($m) use (&$calls, &$ebitda) {
        $m->shouldReceive('buildSnapshot')->andReturnUsing(function () use (&$calls, &$ebitda) {
            $calls++;

            return ['summary' => ['ebitda_final' => $ebitda, 'opex_total' => 10.0]];
        });
    });
    $svc = app(DashboardTrendService::class);

    expect($svc->build()[0]['ebitda'])->toBe(100.0);
    expect($svc->build()[0]['ebitda'])->toBe(100.0)->and($calls)->toBe(1); // segunda apertura: caché

    $ebitda = 250.0; // el periodo se regenera con otras cifras
    $this->travel(2)->seconds();
    $summary->update(['generated_at' => now()]);

    expect($svc->build()[0]['ebitda'])->toBe(250.0)->and($calls)->toBe(2);
});
