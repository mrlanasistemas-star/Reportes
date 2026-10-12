<?php

use App\Models\Branch;
use App\Models\Period;
use App\Models\Portfolio;
use App\Models\Recovery;
use App\Services\Efectividad\EfectividadCobranzaAprobadaReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Fase 7 — confirma que el adaptador real (fact_portfolios + fact_recoveries →
 * EfectividadCobranzaAprobadaCalculator) produce resultados reales cuando hay
 * datos suficientes, Y que reporta honestamente — sin inventar cifras — cuando
 * el Excel real de Ingresos Cobranza no trae Fecha transacción/Fecha cuota
 * (la brecha de datos confirmada en Fase 1 contra el archivo real).
 */
function makeReportServicePeriodo(): Period
{
    return Period::query()->create([
        'name' => 'Periodo ReportService', 'code' => 'M-REPSVC-2026-9', 'type' => 'monthly',
        'year' => 2026, 'month' => 9, 'sequence' => 1,
        'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'is_closed' => false,
    ]);
}

it('reports bloqueado_sin_fechas honestly when Ingresos Cobranza rows pass the whitelist but have no Fecha transaccion / Fecha cuota (the real 14-column file gap)', function () {
    $period = makeReportServicePeriodo();
    $branch = Branch::query()->create(['code' => 'COR', 'name' => 'CORDOBA', 'normalized_name' => 'cordoba', 'is_active' => true]);

    Portfolio::query()->create([
        'period_id' => $period->id, 'branch_id' => $branch->id,
        'contract' => '25COR1270278', 'promoter_name' => 'LUIS GOMEZ',
        'status' => 'VIGENTE', 'substatus' => 'VIGENTE',
        'installment_amount' => 100, 'periodicity' => 'SEMANAL', 'num_payments' => 40,
        'first_payment_date' => '2026-09-05',
    ]);

    // Simula exactamente el archivo real confirmado: sin Fecha transacción ni Fecha cuota.
    Recovery::query()->create([
        'period_id' => $period->id, 'branch_id' => $branch->id,
        'contract' => '25COR1270278', 'promoter_name' => 'LUIS GOMEZ',
        'operation' => 'PAGO A CONTRATO', 'transaction' => 'PAGO',
        'capital' => 100, 'interest' => 20, 'tax' => 5, 'total_amount' => 125,
    ]);

    $result = app(EfectividadCobranzaAprobadaReportService::class)->generate($period, $branch->id);

    expect($result['is_available'])->toBeFalse();
    expect($result['coverage']['status'])->toBe('bloqueado_sin_fechas');
    expect($result['coverage']['ingresos_rows_whitelist_pass'])->toBe(1);
    expect($result['coverage']['ingresos_rows_usables'])->toBe(0);
    expect($result['rows'])->toBe([]);
    expect($result['coverage']['message'])->toContain('Fecha transacción y Fecha cuota');
});

it('produces a real, non-empty report when both Fecha transaccion and Fecha cuota are present (future Lendus export shape)', function () {
    $period = makeReportServicePeriodo();
    $branch = Branch::query()->create(['code' => 'COR', 'name' => 'CORDOBA', 'normalized_name' => 'cordoba', 'is_active' => true]);

    Portfolio::query()->create([
        'period_id' => $period->id, 'branch_id' => $branch->id,
        'contract' => '25COR1270278', 'promoter_name' => 'LUIS GOMEZ', 'route_name' => 'CORDOBA',
        'status' => 'VIGENTE', 'substatus' => 'VIGENTE',
        'installment_amount' => 100, 'periodicity' => 'SEMANAL', 'num_payments' => 10,
        'first_payment_date' => '2026-09-05',
    ]);

    Recovery::query()->create([
        'period_id' => $period->id, 'branch_id' => $branch->id,
        'contract' => '25COR1270278', 'promoter_name' => 'LUIS GOMEZ', 'office_name_raw' => 'CORDOBA',
        'operation' => 'PAGO A CONTRATO', 'transaction' => 'PAGO',
        'capital' => 100, 'interest' => 0, 'tax' => 0, 'total_amount' => 100,
        'payment_date' => '2026-09-05', 'installment_due_date' => '2026-09-05',
    ]);

    $result = app(EfectividadCobranzaAprobadaReportService::class)->generate($period, $branch->id);

    expect($result['is_available'])->toBeTrue();
    expect($result['coverage']['status'])->toBe('ok');
    expect($result['rows'])->not->toBeEmpty();

    $granTotal = collect($result['rows'])->firstWhere('promotor', 'GRAN TOTAL');
    expect($granTotal)->not->toBeNull();
    expect((float) $granTotal['sano_cob'])->toBe(100.0);
});

it('reports sin_cartera when there is no Saldos por Cliente snapshot for this period/branch, without inventing por-cobrar numbers', function () {
    $period = makeReportServicePeriodo();
    $branch = Branch::query()->create(['code' => 'COR', 'name' => 'CORDOBA', 'normalized_name' => 'cordoba', 'is_active' => true]);

    Recovery::query()->create([
        'period_id' => $period->id, 'branch_id' => $branch->id,
        'contract' => '25COR1270278', 'promoter_name' => 'LUIS GOMEZ',
        'operation' => 'PAGO A CONTRATO', 'transaction' => 'PAGO',
        'capital' => 100, 'total_amount' => 100,
        'payment_date' => '2026-09-05', 'installment_due_date' => '2026-09-05',
    ]);

    $result = app(EfectividadCobranzaAprobadaReportService::class)->generate($period, $branch->id);

    expect($result['is_available'])->toBeFalse();
    expect($result['coverage']['status'])->toBe('sin_cartera');
    expect($result['rows'])->toBe([]);
});
