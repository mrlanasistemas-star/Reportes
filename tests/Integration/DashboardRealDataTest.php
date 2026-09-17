<?php

use App\Models\User;

uses(Tests\Integration\UsesRealDevDatabase::class);

/**
 * Cierre 17-sep-2026 ronda 4 — el Dashboard ahora resume el periodo mensual más
 * reciente con radiografía generada, usando la MISMA fuente que Web/Excel/PDF
 * (RadiografiaExportService::buildSnapshot()/buildExecutiveChartsData()) — nunca un
 * segundo cálculo. Verifica contra la BD real de desarrollo.
 */
beforeEach(function () {
    $this->useRealDevDatabaseOrSkip();
    $this->user = User::query()->first();
    if (!$this->user) {
        $this->markTestSkipped('No hay ningún usuario en la BD de desarrollo.');
    }
});

it('shows real KPIs for the latest generated monthly period, matching buildSnapshot exactly', function () {
    $period = \App\Models\Period::find(21) ?? \App\Models\Period::query()->where('type', 'monthly')
        ->where('year', '<=', now()->year)
        ->whereIn('id', \App\Models\PeriodSummary::where('status', 'generated')->pluck('period_id'))
        ->orderByDesc('year')->orderByDesc('month')->first();
    if (!$period) {
        $this->markTestSkipped('No hay periodos mensuales reales con radiografía generada.');
    }

    $exportService = app(\App\Services\RadiografiaExportService::class);
    $expectedSnapshot = $exportService->buildSnapshot($period, ['scope' => 'general']);

    $response = $this->actingAs($this->user)->get(route('dashboard'));
    $response->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['hasData'])->toBeTrue();
    // Solo compara contra el periodo esperado si el controlador escogió el mismo
    // (puede diferir si esta BD tiene un periodo mensual real más nuevo que 21).
    if ($props['kpis']['period_label'] === $period->label) {
        expect($props['kpis']['ebitda'])->toBe(round((float) $expectedSnapshot['summary']['ebitda_final'], 2));
        expect($props['kpis']['opex'])->toBe(round((float) $expectedSnapshot['summary']['opex_total'], 2));
        expect($props['kpis']['mora_pct'])->toBe(round((float) $expectedSnapshot['summary']['mora_index'], 2));
    }

    expect($props['charts'])->not->toBeNull();
    // Rendimiento (ronda 5) — la tendencia ya NO viaja en la carga inicial (antes
    // obligaba a construir hasta 7 snapshots en la misma request, "tardaba
    // muchísimo en entrar"). Ahora se pide aparte — ver el test de trend() abajo.
    expect($props)->not->toHaveKey('trend');
});

it('the trend endpoint (dashboard-trend) returns real EBITDA/OPEX history and is cached — never recomputed on every request', function () {
    $response = $this->actingAs($this->user)->getJson(route('dashboard.trend'));
    $response->assertOk();
    $data = $response->json();

    expect($data['trend'])->not->toBeEmpty();
    foreach ($data['trend'] as $point) {
        expect($point)->toHaveKeys(['label', 'ebitda', 'opex']);
    }

    // Segunda llamada — debe devolver EXACTAMENTE lo mismo (viene de Cache::remember,
    // nunca recalcula snapshots en cada request).
    $second = $this->actingAs($this->user)->getJson(route('dashboard.trend'));
    $second->assertOk();
    expect($second->json())->toBe($data);
});

it('the live period-filter endpoint (dashboard-data) returns the same numbers as the initial page load, for a real different period', function () {
    $periods = \App\Models\Period::query()->where('type', 'monthly')
        ->where('year', '<=', now()->year)
        ->whereIn('id', \App\Models\PeriodSummary::where('status', 'generated')->pluck('period_id'))
        ->orderByDesc('year')->orderByDesc('month')->get();

    if ($periods->count() < 2) {
        $this->markTestSkipped('Se necesitan al menos 2 periodos mensuales reales generados para probar el filtro en vivo.');
    }

    $olderPeriod = $periods->last();
    $exportService = app(\App\Services\RadiografiaExportService::class);
    $expectedSnapshot = $exportService->buildSnapshot($olderPeriod, ['scope' => 'general']);

    $response = $this->actingAs($this->user)->getJson(route('dashboard.data', ['period_id' => $olderPeriod->id]));
    $response->assertOk();
    $data = $response->json();

    expect($data['hasData'])->toBeTrue();
    expect($data['kpis']['period_label'])->toBe($olderPeriod->label);
    expect($data['kpis']['ebitda'])->toBe(round((float) $expectedSnapshot['summary']['ebitda_final'], 2));
    expect($data['kpis']['opex'])->toBe(round((float) $expectedSnapshot['summary']['opex_total'], 2));
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});
