<?php

use Tests\Support\EfectividadV12Fixtures as F;

/**
 * Refinanciamiento en la semana: el contrato R (cuota 1000, cuotas 3..10 pendientes,
 * la 3 vence el 28/10) se refinancia el martes 27/10 con DESCUENTO de todas sus cuotas
 * pendientes y deja de aparecer en las fotografías a partir de ese día. A es un
 * contrato normal de referencia (meta 1000) de la misma gestora.
 */
beforeEach(function () {
    $this->a = F::contrato('A');
    $this->r = F::contrato('R', ['presente' => [0, 1]]);
});

it('una cuota futura cancelada por refinanciamiento no genera meta', function () {
    $r = F::calcular([$this->a, $this->r], F::refinanciamiento($this->r, '2026-10-27', 3));

    expect(F::filaDe($r, 'ANA'))->toMatchArray(['sano_pc' => 1000.0, 'sano_cob' => 0.0, 'cobranza_total' => 0.0])
        ->and($r['detalle_cuotas'])->not->toHaveKey('R|3')
        ->and(collect($r['amortizaciones'])->where('contrato', 'R')->pluck('numero')->all())->toBe(['1', '2']);
});

it('el diario completo del refinanciamiento concilia como cierre contabilizado, no como desaparición', function () {
    $r = F::calcular([$this->a, $this->r], F::refinanciamiento($this->r, '2026-10-27', 3));

    expect(F::conciliacionDe($r, 'R'))->toMatchArray(['estatus' => 'CIERRE REFI CONTABILIZADO', 'gestor' => 'SIN FOTO FINAL'])
        ->and($r['auditoria']['cierres_refi_comprobados'])->toBe(1)
        ->and($r['auditoria']['estado'])->toBe('CONCILIADO');
});

it('el DESCUENTO de refinanciamiento de cuotas futuras nunca cuenta como cobranza', function () {
    // 8,000 de descuento: ningún bucket los recibe.
    $r = F::calcular([$this->a, $this->r], F::refinanciamiento($this->r, '2026-10-27', 3));
    $gt = F::granTotal($r);

    expect($gt['cobranza_total'])->toBe(0.0)
        ->and($gt['sano_cob'] + $gt['atraso_cob'] + $gt['vencido_cob'])->toBe(0.0)
        ->and($r['estadisticas']['cuotas_con_cobro'])->toBe(0);
});

it('el abono ordinario real previo al refinanciamiento de una cuota futura cancelada se conserva como meta y cobranza (solo ese importe)', function () {
    $pagos = [
        F::pago($this->r, 3, '2026-10-26', 200.0),
        ...F::refinanciamiento($this->r, '2026-10-27', 3, [3 => 800.0]),
    ];
    $r = F::calcular([$this->a, $this->r], $pagos);
    $traza = collect($r['amortizaciones'])->first(fn ($t) => $t['contrato'] === 'R' && $t['numero'] === '3');

    expect(F::filaDe($r, 'ANA'))->toMatchArray([
        'sano_pc' => 1200.0,   // 1000 de A + 200 del abono real de R
        'sano_cob' => 200.0,   // los 800 de descuento no se reconocen
        'atraso_pc' => 1000.0, // solo la cuota impagada de A
    ])->and($r['detalle_cuotas']['R|3']['meta'])->toBe(200.0)
        ->and($traza)->toMatchArray(['neto' => 200.0, 'evidencia' => 'CUOTA FUTURA CANCELADA / ABONO REAL', 'cancelacion' => '2026-10-27'])
        ->and(F::conciliacionDe($r, 'R')['estatus'])->toBe('CIERRE REFI CONTABILIZADO');
});
