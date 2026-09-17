<?php

use App\Models\OkrKpi;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\Okr\OkrKpiValueResolver;
use App\Services\Okr\OkrTrackingPeriodResolver;
use App\Services\RadiografiaExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * D13 del cierre, 17-sep-2026 — auditoría pedida explícitamente ("NO CAMBIES A
 * CIEGAS. AUDITA con datos reales."): demostrar si el "seguimiento semanal" de
 * un KPI automático es real o si semana 1 y semana 2 del MISMO mes terminan
 * leyendo el mismo total mensual.
 *
 * HALLAZGO (confirmado leyendo OkrTrackingPeriodResolver y
 * OkrKpiValueResolver): este sistema SOLO genera radiografía a nivel MENSUAL
 * (Period::canReceiveUploads() únicamente para type=monthly) — dos semanas
 * cualquiera dentro del MISMO mes calendario resuelven al MISMO Period
 * (OkrTrackingPeriodResolver::getPeriodForObjectiveWeek()), y
 * OkrKpiValueResolver::getValue() es una función determinista de
 * (kpi, scope, Period) — mismo Period, garantiza el MISMO valor.
 *
 * CONCLUSIÓN: el seguimiento semanal de un KPI automático dentro del mismo mes
 * NO tiene granularidad real — es el mismo total mensual reutilizado. Esto NO
 * es un bug nuevo de esta sesión: YA estaba correctamente etiquetado en el
 * backend (OkrSnapshotService::resolveSourceMetadata(), source_granularity=
 * 'monthly', source_quality='monthly_proxy'/'last_available' — ver auditoría
 * 09-sep-2026, punto 4). Lo que SÍ faltaba (corregido en esta sesión) era que
 * la UI (Okr/Show.vue) recibía ese campo pero nunca lo mostraba — el usuario
 * no tenía forma de saber que "Actual" no cambia semana a semana dentro del
 * mismo mes. Se agregó un tooltip visible junto al valor "Actual" quue lo
 * explica, sin inventar una granularidad semanal que no existe.
 */
it('proves that week 1 and week 2 of the SAME month resolve to the identical Period (no real weekly granularity exists)', function () {
    $period = Period::query()->create([
        'name' => 'Agosto 2026', 'code' => 'D13-AUDIT-' . uniqid(), 'type' => 'monthly',
        'year' => 2026, 'month' => 8, 'sequence' => 1, 'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
    ]);
    PeriodSummary::query()->create(['period_id' => $period->id, 'status' => 'generated']);

    $resolver = app(OkrTrackingPeriodResolver::class);
    $objectiveStart = '2026-08-01'; // semana 1: 1-7 ago, semana 2: 8-14 ago — AMBAS dentro de agosto

    $periodWeek1 = $resolver->getPeriodForObjectiveWeek($objectiveStart, 1);
    $periodWeek2 = $resolver->getPeriodForObjectiveWeek($objectiveStart, 2);

    expect($periodWeek1)->not->toBeNull();
    expect($periodWeek2)->not->toBeNull();
    expect($periodWeek1->id)->toBe($period->id);
    expect($periodWeek2->id)->toBe($period->id);
    // El hallazgo central: NO hay dos periodos distintos para dos semanas
    // distintas del mismo mes — es literalmente el mismo registro.
    expect($periodWeek1->id)->toBe($periodWeek2->id);
});

it('proves OkrKpiValueResolver::getValue() returns the EXACT SAME reading for week 1 and week 2 of the same month', function () {
    $period = Period::query()->create([
        'name' => 'Agosto 2026', 'code' => 'D13-AUDIT-VALUE-' . uniqid(), 'type' => 'monthly',
        'year' => 2026, 'month' => 8, 'sequence' => 1, 'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
    ]);
    PeriodSummary::query()->create(['period_id' => $period->id, 'status' => 'generated']);

    $resolver = app(OkrTrackingPeriodResolver::class);
    $periodWeek1 = $resolver->getPeriodForObjectiveWeek('2026-08-01', 1);
    $periodWeek2 = $resolver->getPeriodForObjectiveWeek('2026-08-01', 2);

    // Frontera legítima del test: se sustituye el servicio financiero real
    // (pipeline MySQL pesado, no disponible en la suite SQLite) por un doble que
    // devuelve un valor FIJO — el punto no es "cuánto vale la recuperación", es
    // que getValue() nunca sabe distinguir semana 1 de semana 2 dentro del mismo
    // mes porque solo recibe un $period, y aquí es el MISMO objeto para ambas.
    $exportService = Mockery::mock(RadiografiaExportService::class);
    $exportService->shouldReceive('buildSnapshot')
        ->with(Mockery::on(fn ($p) => $p->id === $period->id), ['scope' => 'general'])
        ->andReturn(['scope' => ['available' => true], 'summary' => ['recovery_total' => 987654.32]]);
    app()->instance(RadiografiaExportService::class, $exportService);

    $kpi = OkrKpi::query()->create([
        'name' => 'Recuperación (auditoría D13)', 'code' => 'd13_audit_recovery', 'unit' => 'currency',
        'type' => OkrKpi::TYPE_CUMULATIVE, 'direction' => OkrKpi::DIRECTION_INCREASE,
        'automation' => OkrKpi::AUTOMATION_AUTOMATIC, 'provider_key' => 'reporteria.recovery', 'is_active' => true,
    ]);

    $valueResolver = app(OkrKpiValueResolver::class);
    $valueWeek1 = $valueResolver->getValue($kpi, 'general', null, null, $periodWeek1);
    $valueWeek2 = $valueResolver->getValue($kpi, 'general', null, null, $periodWeek2);

    expect($valueWeek1)->toBe(987654.32);
    expect($valueWeek2)->toBe(987654.32);
    expect($valueWeek1)->toBe($valueWeek2); // EL TRACKING SEMANAL, DENTRO DEL MISMO MES, ES EL MISMO DATO.
});

it('the backend already labels this honestly as source_quality=monthly_proxy — never claims real weekly data', function () {
    $period = Period::query()->create([
        'name' => 'Agosto 2026', 'code' => 'D13-AUDIT-LABEL-' . uniqid(), 'type' => 'monthly',
        'year' => 2026, 'month' => 8, 'sequence' => 1, 'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
    ]);

    $resolveSourceMetadata = new ReflectionMethod(\App\Services\Okr\OkrSnapshotService::class, 'resolveSourceMetadata');
    $resolveSourceMetadata->setAccessible(true);
    $service = app(\App\Services\Okr\OkrSnapshotService::class);

    $kpi = OkrKpi::query()->create([
        'name' => 'KPI automático (auditoría D13)', 'code' => 'd13_audit_kpi_label', 'unit' => 'currency',
        'type' => OkrKpi::TYPE_CUMULATIVE, 'direction' => OkrKpi::DIRECTION_INCREASE,
        'automation' => OkrKpi::AUTOMATION_AUTOMATIC, 'provider_key' => 'reporteria.recovery', 'is_active' => true,
    ]);

    $weekEndDate = \Illuminate\Support\Carbon::parse('2026-08-07');
    $meta = $resolveSourceMetadata->invoke($service, $kpi, $period, $weekEndDate, null);

    expect($meta['source_granularity'])->toBe('monthly');
    expect($meta['source_quality'])->toBe(\App\Models\OkrProgressSnapshot::QUALITY_MONTHLY_PROXY);
});
