<?php

use App\Services\Efectividad\V12\EfectividadV12Calculator;

/**
 * construirFilas(): filtros y agregación reconstruyen promotores, subtotales y
 * GRAN TOTAL desde importes base; los porcentajes se recalculan, nunca se suman.
 */
function v12Promotor(string $oficina, string $promotor, array $v = [], ?string $sucursal = null): array
{
    $p = ['oficina' => $oficina, 'promotor' => $promotor] + $v + [
        'sano_pc' => 0.0, 'sano_cob' => 0.0, 'atraso_pc' => 0.0, 'atraso_cob' => 0.0, 'vencido_pc' => 0.0, 'vencido_cob' => 0.0,
    ];
    if ($sucursal !== null) {
        $p['sucursal'] = $sucursal;
    }

    return $p;
}

it('agrega dos semanas sumando importes base y recalcula los porcentajes (nunca suma porcentajes)', function () {
    $semana1 = v12Promotor('CORDOBA', 'ANA', ['sano_pc' => 1000.0, 'sano_cob' => 900.0, 'atraso_pc' => 200.0, 'atraso_cob' => 100.0]);
    $semana2 = v12Promotor('CORDOBA', 'ANA', ['sano_pc' => 100.0, 'sano_cob' => 0.0, 'vencido_pc' => 50.0, 'vencido_cob' => 50.0]);
    $sumados = [];
    foreach (['sano_pc', 'sano_cob', 'atraso_pc', 'atraso_cob', 'vencido_pc', 'vencido_cob'] as $campo) {
        $sumados[$campo] = $semana1[$campo] + $semana2[$campo];
    }
    $mes = v12Promotor('CORDOBA', 'ANA', $sumados);

    $calc = new EfectividadV12Calculator;
    $s1 = $calc->construirFilas([$semana1])[0];
    $s2 = $calc->construirFilas([$semana2])[0];
    $filas = $calc->construirFilas([$mes]);
    $ana = $filas[0];

    expect($ana)->toMatchArray(['sano_pc' => 1100.0, 'sano_cob' => 900.0, 'atraso_cob' => 100.0, 'vencido_cob' => 50.0, 'cobranza_total' => 1050.0])
        ->and($ana['sano_pct'])->toEqualWithDelta(900 / 1100, 1e-12)
        ->and($ana['atraso_pct'])->toEqualWithDelta(100 / 1100, 1e-12)
        ->and($ana['vencido_pct'])->toEqualWithDelta(50 / 1100, 1e-12)
        ->and($ana['pct_total'])->toEqualWithDelta(1050 / 1100, 1e-12)
        // Ni suma ni promedio de los porcentajes semanales.
        ->and($ana['sano_pct'])->not->toEqualWithDelta($s1['sano_pct'] + $s2['sano_pct'], 1e-6)
        ->and($ana['sano_pct'])->not->toEqualWithDelta(($s1['sano_pct'] + $s2['sano_pct']) / 2, 1e-6)
        ->and($ana['pct_total'])->not->toEqualWithDelta($s1['pct_total'] + $s2['pct_total'], 1e-6)
        ->and(array_column($filas, 'tipo'))->toBe(['promotor', 'subtotal', 'gran_total']);
});

it('sobre un subconjunto filtrado reconstruye subtotal y GRAN TOTAL desde importes y conserva la fila de meta cero con recuperación', function () {
    $todos = [
        v12Promotor('CORDOBA', 'ANA', ['sano_pc' => 1000.0, 'sano_cob' => 800.0, 'atraso_pc' => 300.0, 'atraso_cob' => 100.0]),
        v12Promotor('CORDOBA', 'LUIS', ['atraso_pc' => 500.0, 'atraso_cob' => 500.0]),       // meta cero con recuperación
        v12Promotor('TULA', 'PEDRO', ['sano_pc' => 2000.0, 'sano_cob' => 2000.0]),
    ];
    $calc = new EfectividadV12Calculator;
    $filtrado = array_values(array_filter($todos, fn ($p) => $p['oficina'] === 'CORDOBA'));
    $filas = $calc->construirFilas($filtrado);
    $porEtiqueta = array_column($filas, null, 'promotor');

    expect(array_column($filas, 'promotor'))->toBe(['ANA', 'LUIS', 'TOTAL CORDOBA', 'GRAN TOTAL'])
        ->and($porEtiqueta['LUIS'])->toMatchArray(['sano_pc' => 0.0, 'cobranza_total' => 500.0, 'pct_total' => null, 'mora' => -500.0])
        ->and($porEtiqueta['TOTAL CORDOBA'])->toMatchArray(['tipo' => 'subtotal', 'sano_pc' => 1000.0, 'cobranza_total' => 1400.0, 'atraso_pc' => 800.0])
        ->and($porEtiqueta['TOTAL CORDOBA']['pct_total'])->toEqualWithDelta(1.4, 1e-12)
        ->and($porEtiqueta['GRAN TOTAL'])->toMatchArray(['tipo' => 'gran_total', 'sano_pc' => 1000.0, 'sano_cob' => 800.0, 'cobranza_total' => 1400.0, 'mora' => -400.0])
        ->and($porEtiqueta['GRAN TOTAL']['atraso_pct'])->toEqualWithDelta(0.6, 1e-12)
        ->and($porEtiqueta['GRAN TOTAL']['pct_total'])->toEqualWithDelta(1.4, 1e-12);

    // Sin filtro: un subtotal por ruta y el GRAN TOTAL de ambas.
    $completo = array_column($calc->construirFilas($todos), null, 'promotor');
    expect(array_keys($completo))->toBe(['ANA', 'LUIS', 'TOTAL CORDOBA', 'PEDRO', 'TOTAL TULA', 'GRAN TOTAL'])
        ->and($completo['GRAN TOTAL']['sano_pc'])->toBe(3000.0)
        ->and($completo['GRAN TOTAL']['pct_total'])->toEqualWithDelta(3400 / 3000, 1e-12);
});

it('con más de una sucursal agrega subtotales por sucursal y deja al final la sucursal indicada', function () {
    $filas = (new EfectividadV12Calculator)->construirFilas([
        v12Promotor('RUTA 1', 'ANA', ['sano_pc' => 100.0, 'sano_cob' => 50.0], 'Tulancingo'),
        v12Promotor('RUTA 9', 'LUIS', ['sano_pc' => 300.0, 'sano_cob' => 300.0], 'Cordoba'),
    ], 'Cordoba');

    expect(array_column($filas, 'promotor'))->toBe([
        'ANA', 'TOTAL RUTA 1', 'TOTAL SUCURSAL TULANCINGO', 'LUIS', 'TOTAL RUTA 9', 'TOTAL SUCURSAL CORDOBA', 'GRAN TOTAL',
    ])
        ->and($filas[2])->toMatchArray(['tipo' => 'subtotal_sucursal', 'sano_pc' => 100.0, 'sucursal' => 'Tulancingo'])
        ->and(end($filas)['pct_total'])->toEqualWithDelta(350 / 400, 1e-12);
});

it('sin promotores no genera filas', function () {
    expect((new EfectividadV12Calculator)->construirFilas([]))->toBe([]);
});
