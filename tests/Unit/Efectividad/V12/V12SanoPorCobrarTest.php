<?php

use Tests\Support\EfectividadV12Fixtures as F;

/**
 * SANO por cobrar = saldo NETO de la cuota del periodo pendiente al INICIO de la
 * semana. Los anticipos previos lo reducen; los cobros de la semana no.
 * Contrato A: cuota semanal 1000, cuota 3 vence el miércoles 28/10.
 */
it('sin anticipos la meta sana es la cuota ordinaria completa', function () {
    $r = F::calcular([F::contrato('A')]);

    expect(F::filaDe($r, 'ANA'))->toMatchArray(['sano_pc' => 1000.0, 'sano_cob' => 0.0, 'cobranza_total' => 0.0]);
});

it('un anticipo previo al periodo reduce la meta (1000 - 300 = 700)', function () {
    $a = F::contrato('A');
    $r = F::calcular([$a], [F::pago($a, 3, '2026-10-20', 300.0)]);

    expect(F::filaDe($r, 'ANA'))->toMatchArray(['sano_pc' => 700.0, 'sano_cob' => 0.0, 'atraso_pc' => 700.0])
        ->and($r['detalle_cuotas']['A|3']['meta'])->toBe(700.0)
        ->and($r['detalle_cuotas']['A|3']['previo_final'])->toBe(300.0);
});

it('un pago dentro de la semana no reduce retroactivamente el Sano por cobrar', function () {
    $a = F::contrato('A');
    $r = F::calcular([$a], [
        F::pago($a, 3, '2026-10-20', 300.0),
        F::pago($a, 3, '2026-10-28', 500.0),
    ]);

    expect(F::filaDe($r, 'ANA'))->toMatchArray([
        'sano_pc' => 700.0, 'sano_cob' => 500.0, 'atraso_pc' => 200.0, 'atraso_cob' => 0.0, 'mora' => 200.0,
    ])->and(F::filaDe($r, 'ANA')['sano_pct'])->toEqualWithDelta(500 / 700, 1e-12);
});

it('una cuota del periodo ya cubierta antes de la semana (EN avanzado) no genera meta', function () {
    $a = F::contrato('A', ['pagado_previo' => [3 => 1000.0]]);
    $r = F::calcular([$a]);

    expect($r['filas'])->toBe([])
        ->and($r['sin_movimientos'])->toBeTrue()
        ->and($r['detalle_cuotas']['A|3']['meta'])->toBe(0.0)
        ->and(collect($r['amortizaciones'])->firstWhere('numero', '3')['previo_reconstruido'])->toBe(1000.0);
});
