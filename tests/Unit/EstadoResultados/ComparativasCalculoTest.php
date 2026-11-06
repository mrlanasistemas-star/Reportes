<?php

use App\Services\EstadoResultados\Comparatives\ComparisonConcepts as C;
use App\Services\EstadoResultados\Comparatives\Figures;
use App\Services\EstadoResultados\Comparatives\PeriodComparability;
use App\Services\EstadoResultados\Comparatives\PeriodSummaryExtractor;

/**
 * Aritmética y reglas de las comparativas con valores calculados a mano
 * (independientes del builder): variaciones, base cero, negativos, centavos,
 * dirección favorable por tipo, comparabilidad y lectura del dataset (nuevo y compatibilidad).
 */
it('variación % con base positiva, negativa (|base|), centavos y base cero', function () {
    expect(Figures::variationPct('1100.00', '1000.00'))->toBe('10.00')
        ->and(Figures::variationPct('-50.00', '-100.00'))->toBe('50.00')     // mejora de un negativo = +50 %
        ->and(Figures::variationPct('-150.00', '-100.00'))->toBe('-50.00')
        ->and(Figures::variationPct('0.03', '0.01'))->toBe('200.00')
        ->and(Figures::variationPct('100.00', '0.00'))->toBeNull()
        ->and(Figures::variationPct(null, '10.00'))->toBeNull()
        ->and(Figures::sub('0.30', '0.10'))->toBe('0.20')
        ->and(Figures::sum(['0.10', '0.20']))->toBe('0.30')
        ->and(Figures::sum(['1.00', null]))->toBeNull()
        ->and(Figures::pct('1.00', '3.00'))->toBe('33.33')
        ->and(Figures::pct('1.00', '0.00'))->toBeNull()
        ->and(Figures::points('40.00', '37.50'))->toBe('2.50');
});

it('dirección favorable según el tipo de concepto', function () {
    expect(Figures::assessment('10.00', C::INCOME))->toBe('favorable')
        ->and(Figures::assessment('10.00', C::EXPENSE))->toBe('desfavorable')
        ->and(Figures::assessment('-10.00', C::EXPENSE))->toBe('favorable')
        ->and(Figures::assessment('10.00', C::RESULT))->toBe('favorable')
        ->and(Figures::assessment('5.00', C::SIGNED_RESULT))->toBe('favorable')
        ->and(Figures::assessment('-0.50', C::MARGIN))->toBe('desfavorable')
        ->and(Figures::assessment('0.00', C::INCOME))->toBe('neutral')
        ->and(Figures::assessment('0.004', C::EXPENSE))->toBe('neutral')
        ->and(Figures::assessment(null, C::INCOME))->toBeNull()
        ->and(C::meta('isr')['kind'])->toBe(C::EXPENSE)
        ->and(C::meta('resultado_financiero_neto')['kind'])->toBe(C::SIGNED_RESULT);
});

function ucSummary(array $over = []): array
{
    return array_replace_recursive([
        'period' => ['label' => 'P', 'days' => 28],
        'status' => 'preliminar', 'status_label' => 'Preliminar',
        'availability' => ['erp' => true, 'noi' => true, 'bancos' => ['cargadas' => 2, 'total' => 2]],
        'report' => ['rules_version' => 'r1'],
        'complete' => ['depreciacion_amortizacion' => true, 'resultado_financiero_neto' => true, 'isr' => true],
    ], $over);
}

it('comparabilidad: cobertura parcial, duración distinta y sin datos bloquean; aprobado vs preliminar sólo advierte', function () {
    $c = new PeriodComparability;

    $ok = $c->evaluate(ucSummary(), ucSummary(['status' => 'aprobado', 'status_label' => 'Aprobado']));
    expect($ok['comparable'])->toBeTrue()->and(array_column($ok['warnings'], 'code'))->toContain('preliminar', 'estado_distinto');

    $partial = $c->evaluate(ucSummary(), ucSummary(['availability' => ['erp' => false]]));
    expect($partial['comparable'])->toBeFalse()->and($partial['reasons'][0]['code'])->toBe('cobertura_distinta')
        ->and($partial['reasons'][0]['message'])->toContain('ERP de gastos');

    $banks = $c->evaluate(ucSummary(), ucSummary(['availability' => ['bancos' => ['cargadas' => 1]]]));
    expect($banks['comparable'])->toBeFalse();

    $days = $c->evaluate(ucSummary(), ucSummary(['period' => ['days' => 35]]));
    expect($days['comparable'])->toBeFalse()->and($days['reasons'][0]['message'])->toContain('28 vs 35');

    $empty = $c->evaluate(ucSummary(), ucSummary(['status' => 'sin_datos']));
    expect($empty['comparable'])->toBeFalse()->and($empty['reasons'][0]['code'])->toBe('sin_datos_comparacion');

    $adj = $c->evaluate(ucSummary(), ucSummary(['complete' => ['isr' => false]]));
    expect($adj['comparable'])->toBeTrue()->and(array_column($adj['warnings'], 'code'))->toContain('ajustes_distintos');
});

function ucDataset(array $results, array $statement = [], array $adjustments = [], array $sources = []): array
{
    return [
        'meta' => ['period' => ['id' => 1, 'code' => 'M', 'label' => 'Junio', 'start' => '2026-05-25', 'end' => '2026-06-21'],
            'scope' => ['key' => 'CONSOLIDADO', 'label' => 'Consolidado', 'consolidated' => true],
            'report' => ['status' => 'borrador', 'source' => 'en_vivo', 'preliminary' => true, 'rules_version' => 'r1'], 'dataset_hash' => 'h'],
        'results' => $results,
        'statement' => $statement,
        'adjustments' => $adjustments,
        'sources' => $sources + ['erp' => ['count' => 3], 'nomina' => ['count' => 5], 'cuentas' => [['status' => 'procesado'], ['status' => 'procesado']], 'radiografia' => ['status' => 'procesado']],
        'completeness' => [],
    ];
}

it('lee el modelo nuevo (Fase 2) sin reinterpretar el resultado financiero con signo', function () {
    $d = ucDataset([
        'ingresos_operativos' => '1000000.00', 'opex_total' => '600000.00', 'ebitda' => '400000.00',
        'depreciacion_amortizacion' => '30000.00', 'ebit' => '370000.00', 'resultado_financiero_neto' => '-30000.00',
        'ebt' => '340000.00', 'isr' => '100000.00', 'utilidad_neta' => '240000.00',
        // claves internas de compatibilidad: NO se usan
        'ebitda_sucursales' => '999.00', 'ebitda_final' => '111.00',
    ], [['type' => 'section', 'key' => 'opex', 'categories' => [
        ['key' => 'opex_sucursales', 'label' => 'OPEX sucursales', 'total' => ['amount' => '400000.00'], 'lines' => [['key' => 'a', 'label' => 'Renta', 'amount' => '400000.00']]],
        ['key' => 'nomina_sucursales', 'label' => 'Nómina', 'total' => ['amount' => '200000.00'], 'lines' => []],
    ]]]);
    $s = (new PeriodSummaryExtractor)->extract($d);

    expect($s['model'])->toBe('ebitda_unico')
        ->and($s['sign_corrected'])->toBeFalse()
        ->and($s['values']['ebitda'])->toBe('400000.00')
        ->and($s['values']['resultado_financiero_neto'])->toBe('-30000.00')
        ->and($s['values']['utilidad_neta'])->toBe('240000.00')
        ->and(array_column($s['opex_categories'], 'amount'))->toBe(['400000.00', '200000.00'])
        ->and($s['opex_categories'][1]['sensitive'])->toBe('nomina')
        ->and($s['warnings'])->toBe([])
        ->and($s['status'])->toBe('preliminar');
});

it('compatibilidad: OPEX = Σ secciones, RFN expresado como gasto pasa a signo, ISR sin información = pendiente', function () {
    $sec = fn ($k, $amt, $applies = true) => ['type' => 'section', 'key' => $k, 'title' => strtoupper($k), 'applies' => $applies, 'total' => ['amount' => $amt], 'lines' => []];
    $d = ucDataset(
        ['ingresos' => '1000000.00', 'ebitda_sucursales' => '407000.00', 'ebitda_final' => '400000.00', 'depreciacion_amortizacion' => '30000.00',
            'ebit' => '370000.00', 'resultado_financiero_neto' => '30000.00', 'ebt' => '340000.00', 'isr' => '0.00', 'utilidad_neta' => '340000.00'],
        [$sec('opex_sucursales', '400000.00'), $sec('nomina_sucursales', '193000.00'), $sec('opex_corporativo', '7000.00'), $sec('nomina_corporativa', '0.00'), $sec('dg', '0.00')],
        [['key' => 'depreciacion', 'state' => 'capturado'], ['key' => 'isr', 'state' => 'sin_informacion']],
    );
    $s = (new PeriodSummaryExtractor)->extract($d);

    expect($s['model'])->toBe('compatibilidad')
        ->and($s['values']['opex_total'])->toBe('600000.00')
        ->and($s['values']['ebitda'])->toBe('400000.00')
        ->and($s['values']['resultado_financiero_neto'])->toBe('-30000.00')
        ->and($s['sign_corrected'])->toBeTrue()
        ->and($s['values']['isr'])->toBeNull()
        ->and($s['values']['utilidad_neta'])->toBeNull()
        ->and($s['complete']['isr'])->toBeFalse()
        ->and(collect($s['opex_categories'])->where('centralized', true)->pluck('key')->all())->toBe(['opex_corporativo', 'nomina_corporativa', 'dg']);
});

it('sin ninguna fuente ni cifra: «sin datos» con importes null (nunca $0.00)', function () {
    $d = ucDataset(['ingresos' => '0.00', 'ebitda_final' => '0.00', 'ebit' => '0.00', 'ebt' => '0.00', 'resultado_financiero_neto' => '0.00', 'isr' => '0.00', 'utilidad_neta' => '0.00'],
        [], [], ['erp' => ['count' => 0], 'nomina' => ['count' => 0], 'cuentas' => [['status' => 'pendiente'], ['status' => 'pendiente']]]);
    $s = (new PeriodSummaryExtractor)->extract($d);

    expect($s['status'])->toBe('sin_datos')
        ->and(array_unique(array_values($s['values'])))->toBe([null])
        ->and($s['opex_categories'])->toBe([]);
});
