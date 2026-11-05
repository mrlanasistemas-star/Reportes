<?php

use Tests\Support\EfectividadV12Fixtures as F;

/**
 * Regla 32: un gestor con meta cero y recuperación participa en el consolidado.
 * LUIS solo tiene el contrato mensual B (cuotas 15/09, 15/10, 15/11): la cuota del
 * 15/10 está atrasada en la foto base y la cobra el 27/10; no tiene cuota en la semana.
 * ANA tiene el contrato A con meta 1000 y cobra 1000 a tiempo.
 */
beforeEach(function () {
    $a = F::contrato('A');
    $b = F::contrato('B', ['promotor' => 'LUIS', 'periodicidad' => 'MENSUAL', 'primer_pago' => '2026-09-15', 'pagos' => 3,
        'cuota' => 500.0, 'pagado_previo' => [2 => 0.0]]);
    $this->resultado = F::calcular([$a, $b], [
        F::pago($a, 3, '2026-10-28', 1000.0),
        F::pago($b, 2, '2026-10-27', 500.0),
    ]);
});

it('un gestor con meta cero y recuperación de atraso aparece en filas, en el subtotal y en el GRAN TOTAL', function () {
    $filas = collect($this->resultado['filas']);
    $subtotal = $filas->firstWhere('tipo', 'subtotal');
    $gt = F::granTotal($this->resultado);

    expect($filas->pluck('promotor')->all())->toBe(['ANA', 'LUIS', 'TOTAL CORDOBA', 'GRAN TOTAL'])
        ->and(F::filaDe($this->resultado, 'LUIS'))->toMatchArray([
            'sano_pc' => 0.0, 'atraso_pc' => 500.0, 'atraso_cob' => 500.0, 'cobranza_total' => 500.0, 'mora' => -500.0,
        ])
        ->and($subtotal)->toMatchArray(['sano_pc' => 1000.0, 'sano_cob' => 1000.0, 'atraso_cob' => 500.0, 'cobranza_total' => 1500.0])
        ->and($gt)->toMatchArray(['sano_pc' => 1000.0, 'cobranza_total' => 1500.0, 'mora' => -500.0])
        ->and($gt['pct_total'])->toEqualWithDelta(1.5, 1e-12)
        ->and($gt['atraso_pct'])->toEqualWithDelta(0.5, 1e-12);
});

it('los porcentajes del gestor con meta cero son nulos', function () {
    expect(F::filaDe($this->resultado, 'LUIS'))->toMatchArray([
        'sano_pct' => null, 'atraso_pct' => null, 'vencido_pct' => null, 'pct_total' => null,
    ]);
});

it('no muestra gestores sin meta ni cobranza', function () {
    $sinNada = F::contrato('Z', ['promotor' => 'PEDRO', 'pagado_previo' => [3 => 1000.0]]);
    $r = F::calcular([F::contrato('A'), $sinNada]);

    expect(F::filaDe($r, 'PEDRO'))->toBeNull()
        ->and(F::filaDe($r, 'ANA'))->not->toBeNull();
});
