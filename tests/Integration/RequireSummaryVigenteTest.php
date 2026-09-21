<?php

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\RadiografiaExportService;

uses(Tests\Integration\UsesRealDevDatabase::class);

/**
 * Retoma 21-sep-2026, punto 7/12 — RadiografiaExportService::requireSummary() (privado)
 * no traía whereNull('invalidated_at') ni latest('id'), a diferencia de TODAS las
 * consultas equivalentes en MonthlyReportController (exportRadiography,
 * exportRadiographyPdf, exportFilteredRadiography(Pdf), previewPage). Un `->first()`
 * sin ese criterio podía devolver un PeriodSummary viejo/invalidado cuando un periodo
 * llegó a tener más de uno con status=generated (reprocesos/reimportaciones) —
 * candidato más probable al "error de BD" reportado en el comparativo, ya que
 * comparativoData() → comparativeViewData() → requireSummary() es el único camino que
 * puede tocar un summary distinto al que ya validó el controlador.
 *
 * Este test verifica, contra la BD de desarrollo real (solo lectura, nunca escribe),
 * que requireSummary() elige EXACTAMENTE el mismo summary que la consulta canónica
 * (status=generated, invalidated_at NULL, más reciente por id) — el mismo criterio que
 * ya usan exportRadiography()/exportRadiographyPdf()/previewPage().
 */
beforeEach(function () {
    $this->useRealDevDatabaseOrSkip();

    $this->period = Period::query()
        ->whereIn('id', PeriodSummary::query()->where('status', 'generated')->whereNull('invalidated_at')->pluck('period_id'))
        ->orderByDesc('id')->first();

    if (!$this->period) {
        $this->markTestSkipped('No hay ningún periodo con una radiografía vigente en la BD de desarrollo.');
    }
});

it('requireSummary() picks the same summary as the canonical generated+vigente+latest query', function () {
    $canonical = PeriodSummary::query()
        ->where('period_id', $this->period->id)
        ->where('status', 'generated')
        ->whereNull('invalidated_at')
        ->latest('id')
        ->first();

    expect($canonical)->not->toBeNull();

    $service = app(RadiografiaExportService::class);
    $method  = new ReflectionMethod($service, 'requireSummary');
    $method->setAccessible(true);

    /** @var PeriodSummary $picked */
    $picked = $method->invoke($service, $this->period);

    expect($picked->id)->toBe($canonical->id);
    expect($picked->invalidated_at)->toBeNull();
});

it('comparativeViewData() (used by the web comparativo) resolves its base summary through the same vigente criteria', function () {
    $other = Period::query()
        ->where('id', '!=', $this->period->id)
        ->where('type', $this->period->type)
        ->whereIn('id', PeriodSummary::query()->where('status', 'generated')->whereNull('invalidated_at')->pluck('period_id'))
        ->first();

    if (!$other) {
        $this->markTestSkipped('Se necesita un segundo periodo del mismo tipo con radiografía vigente para comparar.');
    }

    $service = app(RadiografiaExportService::class);

    // No debe lanzar — si requireSummary() picked an invalidated/stale summary, esto
    // podría fallar aguas abajo en el snapshot builder con un error de BD (el bug
    // reportado). Aquí solo se afirma que corre limpio contra datos reales vigentes.
    $data = $service->comparativeViewData($this->period, [
        'scope' => 'general', 'report_type' => 'month_vs_month', 'compare_period_id' => $other->id,
    ]);

    expect($data['rows'])->not->toBeEmpty();
});
