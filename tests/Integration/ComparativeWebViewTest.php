<?php

use App\Models\Period;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\RadiografiaExportService;

uses(Tests\Integration\UsesRealDevDatabase::class);

/**
 * Parte B del cierre, 17-sep-2026 — comparativo web interactivo. Antes,
 * MonthlyReportController::viewRun() devolvía la plantilla del PDF
 * (reports.radiography-pdf-comparative) como página web (bug real). Ahora hay una
 * ruta JSON dedicada (comparativo-data) que alimenta una página Inertia/Vue nueva
 * (ComparativePreview.vue) — pero NUNCA calcula cifras propias: reutiliza
 * RadiografiaExportService::comparativeViewData(), la MISMA fuente que ya usa el
 * Excel/PDF comparativo (que esta sesión NO modifica).
 *
 * Solo lectura contra la BD de desarrollo real — nunca RefreshDatabase, nunca escribe.
 */
beforeEach(function () {
    $this->useRealDevDatabaseOrSkip();

    $this->actingUser = User::query()->first();
    if (!$this->actingUser) {
        $this->markTestSkipped('No hay ningún usuario en la BD de desarrollo para autenticar la request.');
    }

    $monthly = Period::query()->where('type', 'monthly')
        ->whereIn('id', PeriodSummary::query()->where('status', 'generated')->pluck('period_id'))
        ->orderByDesc('id')->limit(2)->get();

    if ($monthly->count() < 2) {
        $this->markTestSkipped('Se necesitan al menos 2 periodos mensuales con radiografía generada.');
    }
    $this->periodA = $monthly[0];
    $this->periodB = $monthly[1];
});

it('returns the exact same rows as comparativeViewData() for a general-scope month vs month comparison (B3 parity)', function () {
    $exportService = app(RadiografiaExportService::class);
    $expected = $exportService->comparativeViewData($this->periodA, [
        'scope' => 'general', 'report_type' => 'month_vs_month', 'compare_period_id' => $this->periodB->id,
    ]);

    $response = $this->actingAs($this->actingUser)->getJson(
        route('reportes-mensuales.comparativo-data') . '?' . http_build_query([
            'period_a' => $this->periodA->id, 'period_b' => $this->periodB->id, 'scope' => 'general',
        ])
    );
    $response->assertOk();
    $json = $response->json();

    // json_decode(json_encode()) normaliza 0.0 (float) → 0 (int) igual que la respuesta
    // HTTP real ya decodificada — compara el mismo tipo de estructura en ambos lados,
    // no un artefacto de serialización (0.0 !== 0 con toBe() estricto, pero SON el
    // mismo valor financiero).
    $expectedNormalized = json_decode(json_encode($expected['rows']), true);
    expect($json['rows'])->toBe($expectedNormalized);
    expect($json['scopeLabel'])->toBe($expected['scopeLabel']);
    expect($json['periodA']['id'])->toBe($this->periodA->id);
    expect($json['periodB']['id'])->toBe($this->periodB->id);
});

it('rejects comparing two periods of different types with 422 (B6)', function () {
    $bimonthly = Period::query()->where('type', 'bimonthly')
        ->whereIn('id', PeriodSummary::query()->where('status', 'generated')->pluck('period_id'))
        ->first();
    if (!$bimonthly) {
        $this->markTestSkipped('No hay periodos bimestrales con radiografía generada para probar el rechazo de tipos.');
    }

    $response = $this->actingAs($this->actingUser)->getJson(
        route('reportes-mensuales.comparativo-data') . '?' . http_build_query([
            'period_a' => $this->periodA->id, 'period_b' => $bimonthly->id, 'scope' => 'general',
        ])
    );
    $response->assertStatus(422);
});

it('rejects scope=branch without branch_id, and scope=employee without employee_id, with 422', function () {
    $this->actingAs($this->actingUser)->getJson(
        route('reportes-mensuales.comparativo-data') . '?' . http_build_query([
            'period_a' => $this->periodA->id, 'period_b' => $this->periodB->id, 'scope' => 'branch',
        ])
    )->assertStatus(422);

    $this->actingAs($this->actingUser)->getJson(
        route('reportes-mensuales.comparativo-data') . '?' . http_build_query([
            'period_a' => $this->periodA->id, 'period_b' => $this->periodB->id, 'scope' => 'employee',
        ])
    )->assertStatus(422);
});

it('never creates a PeriodRadiographyRun when switching periods interactively (B17)', function () {
    $countBefore = PeriodRadiographyRun::query()->count();

    $this->actingAs($this->actingUser)->getJson(
        route('reportes-mensuales.comparativo-data') . '?' . http_build_query([
            'period_a' => $this->periodA->id, 'period_b' => $this->periodB->id, 'scope' => 'general',
        ])
    )->assertOk();

    $this->actingAs($this->actingUser)->getJson(
        route('reportes-mensuales.comparativo-data') . '?' . http_build_query([
            'period_a' => $this->periodB->id, 'period_b' => $this->periodA->id, 'scope' => 'general',
        ])
    )->assertOk();

    expect(PeriodRadiographyRun::query()->count())->toBe($countBefore);
});

it('loads the Inertia comparativo page without errors and exposes the comparativoDataUrl/employeesLookupUrl props', function () {
    $response = $this->actingAs($this->actingUser)->get(
        route('reportes-mensuales.comparativo') . '?' . http_build_query([
            'period_a' => $this->periodA->id, 'period_b' => $this->periodB->id,
        ])
    );
    $response->assertOk();
});

it('the comparativo-empleados lookup returns the real roster for a period, not a raw Employee::all()', function () {
    $rosterService = app(\App\Services\PeriodEmployeeRosterService::class);
    $expectedRoster = $rosterService->rosterRowsForSelector($this->periodA)['rows'] ?? [];

    $response = $this->actingAs($this->actingUser)->getJson(
        route('reportes-mensuales.comparativo-employees-lookup') . '?period_id=' . $this->periodA->id
    );
    $response->assertOk();

    expect(count($response->json('employees')))->toBe(count($expectedRoster));
});
