<?php

use App\Models\Branch;
use App\Models\OkrKpi;
use App\Models\Period;
use App\Services\Okr\OkrKpiProviderRegistry;
use App\Services\Okr\OkrKpiValueResolver;
use App\Services\RadiografiaExportService;

/**
 * Módulo OKR (08-sep-2026) — PARIDAD OBLIGATORIA (secciones 39/40/66 del
 * pedido): "Si Web Reportería dice Gestor X EBITDA = 250,000, OKR debe decir
 * 250,000. MISMO periodo, MISMO scope." Contra la BD de desarrollo real
 * (UsesRealDevDatabase) — NUNCA RefreshDatabase, nunca toca fact_expenses/
 * period_summaries/ninguna tabla financiera. ÚNICA excepción a "solo lectura":
 * okrParityKpi() corre el seeder REAL (Database\Seeders\OkrKpiSeeder,
 * idempotente vía updateOrCreate por `code`) y lee la fila ya seedeada por
 * provider_key — nunca crea códigos sintéticos de prueba, nunca deja basura
 * en okr_kpis.
 */
uses(Tests\Integration\UsesRealDevDatabase::class);

beforeEach(function () {
    $this->useRealDevDatabaseOrSkip();
});

function okrParityKpi(string $providerKey): OkrKpi
{
    $existing = OkrKpi::query()->where('provider_key', $providerKey)->first();
    if ($existing) {
        return $existing;
    }

    (new \Database\Seeders\OkrKpiSeeder())->run();

    return OkrKpi::query()->where('provider_key', $providerKey)->firstOrFail();
}

it('OKR EBITDA equals Reportería EBITDA for a real employee, exact, no tolerance', function () {
    // Prefiere el periodo 21 (Junio 2026) — fixture real conocido con roster.
    $period = Period::find(21) ?? Period::query()->where('type', 'monthly')->orderByDesc('id')->first();
    if (!$period) { $this->markTestSkipped('No hay periodos mensuales en la BD de desarrollo.'); }
    $summary = \App\Models\PeriodSummary::query()->where('period_id', $period->id)->where('status', 'generated')->first();
    if (!$summary) { $this->markTestSkipped("El periodo {$period->label} no tiene radiografía generada."); }

    $roster = app(\App\Services\PeriodEmployeeRosterService::class)->rosterRowsForSelector($period)['rows'] ?? [];
    if (empty($roster)) { $this->markTestSkipped("El periodo {$period->label} no tiene roster de colaboradores."); }
    $employeeId = (int) $roster[0]['employee_id'];

    $exportService = app(RadiografiaExportService::class);
    $reporteriaSnapshot = $exportService->buildSnapshot($period, ['scope' => 'employee', 'employee_id' => $employeeId]);
    if (($reporteriaSnapshot['scope']['available'] ?? false) !== true) {
        $this->markTestSkipped('El colaborador de prueba no tiene datos de radiografía disponibles este periodo.');
    }
    $reporteriaEbitda = round((float) $reporteriaSnapshot['summary']['ebitda_final'], 2);

    $kpi = okrParityKpi('reporteria.ebitda');
    $resolver = app(OkrKpiValueResolver::class);
    $okrValue = $resolver->getValue($kpi, OkrKpiProviderRegistry::SCOPE_EMPLOYEE, null, $employeeId, $period);

    expect($okrValue)->not->toBeNull();
    expect(round($okrValue, 2))->toBe($reporteriaEbitda);
});

it('OKR EBITDA equals Reportería EBITDA for a real branch, exact, no tolerance', function () {
    $period = Period::query()->where('type', 'monthly')->orderByDesc('id')->first();
    if (!$period) { $this->markTestSkipped('No hay periodos mensuales en la BD de desarrollo.'); }

    $operativeNames = (new ReflectionClass(\App\Http\Controllers\MonthlyReportController::class))->getConstant('OPERATIVE_BRANCH_NAMES');
    $exportService = app(RadiografiaExportService::class);

    $branchId = null;
    $reporteriaEbitda = null;
    foreach (Branch::whereIn('name', $operativeNames)->get() as $branch) {
        try {
            $snap = $exportService->buildSnapshot($period, ['scope' => 'branch', 'branch_id' => $branch->id]);
        } catch (\Throwable) {
            continue;
        }
        if (($snap['scope']['available'] ?? false) === true) {
            $branchId = $branch->id;
            $reporteriaEbitda = round((float) $snap['summary']['ebitda_final'], 2);
            break;
        }
    }
    if (!$branchId) { $this->markTestSkipped('Ninguna sucursal operativa tiene radiografía disponible para comparar.'); }

    $kpi = okrParityKpi('reporteria.ebitda');
    $resolver = app(OkrKpiValueResolver::class);
    $okrValue = $resolver->getValue($kpi, OkrKpiProviderRegistry::SCOPE_BRANCH, $branchId, null, $period);

    expect($okrValue)->not->toBeNull();
    expect(round($okrValue, 2))->toBe($reporteriaEbitda);
});

it('OKR OPEX equals Reportería OPEX for the same real period and scope', function () {
    $period = Period::query()->where('type', 'monthly')->orderByDesc('id')->first();
    if (!$period) { $this->markTestSkipped('No hay periodos mensuales en la BD de desarrollo.'); }
    $summary = \App\Models\PeriodSummary::query()->where('period_id', $period->id)->where('status', 'generated')->first();
    if (!$summary) { $this->markTestSkipped("El periodo {$period->label} no tiene radiografía generada."); }

    $exportService = app(RadiografiaExportService::class);
    $reporteriaSnapshot = $exportService->buildSnapshot($period, ['scope' => 'general']);
    $reporteriaOpex = round((float) $reporteriaSnapshot['summary']['opex_total'], 2);

    $kpi = okrParityKpi('reporteria.opex');
    $resolver = app(OkrKpiValueResolver::class);
    $okrValue = $resolver->getValue($kpi, OkrKpiProviderRegistry::SCOPE_GENERAL, null, null, $period);

    expect($okrValue)->not->toBeNull();
    expect(round($okrValue, 2))->toBe($reporteriaOpex);
});

it('OKR placement/recovery/mora/portfolio equal Reportería for the same real branch, where data exists', function () {
    $period = Period::query()->where('type', 'monthly')->orderByDesc('id')->first();
    if (!$period) { $this->markTestSkipped('No hay periodos mensuales en la BD de desarrollo.'); }

    $operativeNames = (new ReflectionClass(\App\Http\Controllers\MonthlyReportController::class))->getConstant('OPERATIVE_BRANCH_NAMES');
    $exportService = app(RadiografiaExportService::class);

    $branchId = null;
    $reporteria = null;
    foreach (Branch::whereIn('name', $operativeNames)->get() as $branch) {
        try {
            $snap = $exportService->buildSnapshot($period, ['scope' => 'branch', 'branch_id' => $branch->id]);
        } catch (\Throwable) {
            continue;
        }
        if (($snap['scope']['available'] ?? false) === true) {
            $branchId   = $branch->id;
            $reporteria = $snap['summary'];
            break;
        }
    }
    if (!$branchId) { $this->markTestSkipped('Ninguna sucursal operativa tiene radiografía disponible para comparar.'); }

    $resolver = app(OkrKpiValueResolver::class);
    $pairs = [
        ['reporteria.placement', 'placement_total'],
        ['reporteria.recovery', 'recovery_total'],
        ['reporteria.mora', 'mora_index'],
        ['reporteria.portfolio', 'portfolio_total'],
        ['reporteria.overdue_portfolio', 'overdue_portfolio'],
    ];
    foreach ($pairs as [$providerKey, $summaryKey]) {
        $kpi = okrParityKpi($providerKey);
        $okrValue = $resolver->getValue($kpi, OkrKpiProviderRegistry::SCOPE_BRANCH, $branchId, null, $period);
        expect($okrValue)->not->toBeNull("provider {$providerKey} devolvió null");
        expect(round($okrValue, 2))->toBe(round((float) $reporteria[$summaryKey], 2));
    }
});
