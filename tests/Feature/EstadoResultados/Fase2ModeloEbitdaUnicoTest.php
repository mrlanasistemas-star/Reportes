<?php

use App\Models\Period;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Support\Money;
use Tests\Support\EstadoResultadosFixtures as F;

/**
 * Modelo financiero aprobado (06-nov-2026): EBITDA ÚNICO en 9 pasos.
 * Datos 100 % SINTÉTICOS (ejemplo práctico del dueño), nunca en una BD real.
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
});

/** Ingresos 1,000,000 · OPEX 600,000 (400,000 gastos + 200,000 nómina de sucursal). */
function mdlPeriod(): Period
{
    $p = F::period();
    F::fakeRadiographyByPeriod([$p->id => ['CORDOBA' => [
        'interes_recuperado' => 1000000.00,
        'gastos_operativos' => 400000.00, 'gastos_detalle' => ['Renta Oficina' => 400000.00],
        'nomina_total' => 200000.00,
    ]]]);

    return $p;
}

function mdlAdjust(Period $p, array $captured, array $pending = []): void
{
    foreach (array_keys(AdjustmentService::CONCEPTS) as $concept) {
        if (in_array($concept, $pending, true)) {
            continue;
        }
        isset($captured[$concept])
            ? app(AdjustmentService::class)->save($p, 'CONSOLIDADO', $concept, AdjustmentService::CAPTURADO, $captured[$concept], 'prueba', null)
            : app(AdjustmentService::class)->save($p, 'CONSOLIDADO', $concept, AdjustmentService::CONFIRMADO_CERO, null, 'prueba', null);
    }
}

it('ejemplo de referencia: 1,000,000 − 600,000 = EBITDA 400,000 → EBIT 370,000 → EBT 340,000 → Utilidad 240,000', function () {
    $p = mdlPeriod();
    mdlAdjust($p, ['depreciacion' => '30000', 'intereses_bbva' => '30000', 'isr' => '100000']);

    $d = app(EstadoResultadosDatasetBuilder::class)->build($p);

    expect($d['results'])->toBe([
        'ingresos_operativos' => '1000000.00',
        'opex_total' => '600000.00',
        'ebitda' => '400000.00',
        'depreciacion_amortizacion' => '30000.00',
        'ebit' => '370000.00',
        'resultado_financiero_neto' => '-30000.00',   // gastos negativos
        'ebt' => '340000.00',                         // EBIT + resultado financiero neto
        'isr' => '100000.00',
        'utilidad_neta' => '240000.00',
    ])
        ->and($d['margins'])->toMatchArray(['ebitda' => '40.0000', 'ebit' => '37.0000', 'ebt' => '34.0000', 'neto' => '24.0000'])
        ->and($d['meta']['model']['version'])->toBe(EstadoResultadosDatasetBuilder::MODEL['version'])
        ->and(collect($d['checks'])->where('ok', false)->pluck('key')->all())->toBe([]);

    // Statement en 9 pasos, en orden; OPEX con categorías reales y sin duplicar.
    $steps = collect($d['statement'])->map(fn ($b) => [$b['step'], $b['key']])->all();
    expect($steps)->toBe([[1, 'ingresos'], [2, 'opex'], [3, 'ebitda'], [4, 'da'], [5, 'ebit'], [6, 'financiero'], [7, 'ebt'], [8, 'isr'], [9, 'utilidad_neta']]);
    $opex = collect($d['statement'])->firstWhere('key', 'opex');
    expect(collect($opex['categories'])->pluck('key')->all())->toBe(['opex_sucursales', 'nomina_sucursales', 'opex_corporativo', 'nomina_corporativa', 'dg'])
        ->and($opex['total']['amount'])->toBe('600000.00')
        ->and(Money::str2(Money::sum(collect($opex['categories'])->pluck('total.amount')->all())))->toBe('600000.00')
        ->and(collect($d['statement'])->where('type', 'result')->pluck('key')->all())->toBe(['ebitda', 'ebit', 'ebt', 'utilidad_neta']);

    // Resultado financiero con signo: Σ líneas = neto.
    $fin = collect($d['statement'])->firstWhere('key', 'financiero');
    expect($fin['total']['amount'])->toBe('-30000.00')
        ->and(Money::str2(Money::sum(array_column($fin['lines'], 'amount'))))->toBe('-30000.00');

    // Waterfall en el mismo orden y con EBITDA único.
    expect(collect($d['waterfall'])->where('type', 'subtotal')->pluck('key')->all())->toBe(['ebitda', 'ebit', 'ebt'])
        ->and(collect($d['waterfall'])->last()['amount'])->toBe('240000.00');

    // Las claves del modelo anterior sólo quedan como controles (no como resultados).
    expect($d['results'])->not->toHaveKeys(['ebitda_sucursales', 'ebitda_corporativo', 'ebitda_final'])
        ->and($d['controls']['ebitda_sucursales'])->toBe('400000.00')
        ->and($d['controls']['ebitda_final'])->toBe($d['results']['ebitda'])
        ->and($d['controls']['resultado_financiero_neto_gasto'])->toBe('30000.00');
});

it('el EBITDA único es igual al "EBITDA final" anterior: Corporativo y DG legítimos son OPEX', function () {
    $p = mdlPeriod();
    F::erp($p, 'CORPORATIVO', 'REQ-C1', '7000.00', '2026-09-05', 'Luz', 'LUZ CORPORATIVO');
    mdlAdjust($p, []);

    $d = app(EstadoResultadosDatasetBuilder::class)->build($p);
    expect($d['controls']['opex_corporativo'])->toBe('7000.00')
        ->and($d['results']['opex_total'])->toBe('607000.00')
        ->and($d['results']['ebitda'])->toBe('393000.00')
        ->and($d['controls']['ebitda_final'])->toBe('393000.00')      // mismo importe que el modelo anterior
        ->and($d['controls']['ebitda_sucursales'])->toBe('400000.00') // control contra Radiografía
        ->and(collect($d['checks'])->firstWhere('key', 'ebitda_sucursales_radiografia')['ok'])->toBeTrue();
});

it('ISR sin información queda PENDIENTE (null), nunca $0; la utilidad neta también queda pendiente', function () {
    $p = mdlPeriod();
    mdlAdjust($p, ['depreciacion' => '30000'], ['isr']);

    $d = app(EstadoResultadosDatasetBuilder::class)->build($p);
    $isr = collect($d['statement'])->firstWhere('key', 'isr');
    expect($d['results']['isr'])->toBeNull()
        ->and($d['results']['utilidad_neta'])->toBeNull()
        ->and($d['margins']['neto'])->toBeNull()
        ->and($isr['state'])->toBe('pendiente')
        ->and($isr['total']['amount'])->toBeNull()
        ->and(collect($d['statement'])->firstWhere('key', 'utilidad_neta')['complete'])->toBeFalse()
        ->and(collect($d['waterfall'])->firstWhere('key', 'utilidad_neta')['pending'])->toBeTrue()
        ->and($d['results']['ebt'])->toBe('370000.00')
        ->and(collect($d['checks'])->where('ok', false)->pluck('key')->all())->toBe([]);
});

it('márgenes en null cuando los ingresos operativos son 0', function () {
    $p = F::period();
    F::fakeRadiographyByPeriod([$p->id => ['CORDOBA' => ['gastos_operativos' => 100.0, 'gastos_detalle' => ['Renta' => 100.0]]]]);
    $d = app(EstadoResultadosDatasetBuilder::class)->build($p);

    expect($d['results']['ingresos_operativos'])->toBe('0.00')
        ->and($d['margins']['ebitda'])->toBeNull()
        ->and($d['margins']['ebit'])->toBeNull()
        ->and($d['margins']['neto'])->toBeNull();
});

it('sucursal: EBITDA = ingresos − OPEX directo; Corporativo y DG nunca se asignan', function () {
    $p = mdlPeriod();
    F::erp($p, 'CORPORATIVO', 'REQ-C1', '7000.00', '2026-09-05', 'Luz', 'LUZ CORPORATIVO');

    $d = app(EstadoResultadosDatasetBuilder::class)->build($p, 'CORDOBA');
    $opex = collect($d['statement'])->firstWhere('key', 'opex');
    expect($d['results']['ebitda'])->toBe('400000.00')
        ->and($d['results']['opex_total'])->toBe('600000.00')
        ->and(collect($opex['categories'])->pluck('key')->all())->toBe(['opex_sucursales', 'nomina_sucursales'])
        ->and(collect($d['waterfall'])->pluck('key')->all())->not->toContain('opex_corporativo', 'dg');
});
