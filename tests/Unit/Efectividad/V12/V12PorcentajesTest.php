<?php

use Tests\Support\EfectividadV12Fixtures as F;

/**
 * Porcentajes V12.9.4: cada bucket se divide entre el Sano por cobrar (nunca entre su
 * propio Por cobrar), la efectividad es la suma de los tres aportes, sin tope, y la
 * mora puede ser negativa.
 *
 * Escenario (gestora ANA):
 *  - A: cuota semanal 1000 del miércoles 28/10; paga 600 ese día (sano) y 300 el viernes
 *    30/10 (atraso). Quedan 100 pendientes al corte (atraso por cobrar de control).
 *  - C: contrato vencido el 14/10 con 400 de saldo en la foto base; paga 300 el 27/10
 *    (vencido). Quedan 100 en vencido.
 * Sano por cobrar 1000; cobrado 600 + 300 + 300 = 1200.
 */
beforeEach(function () {
    $a = F::contrato('A');
    $c = F::contrato('C', ['primer_pago' => '2026-09-23', 'pagos' => 4, 'cuota' => 400.0, 'pagado_previo' => [4 => 0.0]]);
    $pagos = [
        F::pago($a, 3, '2026-10-28', 600.0),
        F::pago($a, 3, '2026-10-30', 300.0),
        F::pago($c, 4, '2026-10-27', 300.0),
    ];
    $this->resultado = F::calcular([$a, $c], $pagos);
    $this->ana = F::filaDe($this->resultado, 'ANA');
});

it('arma los importes base de cada bucket', function () {
    expect($this->ana)->toMatchArray([
        'sano_pc' => 1000.0, 'sano_cob' => 600.0,
        'atraso_pc' => 400.0, 'atraso_cob' => 300.0,
        'vencido_pc' => 400.0, 'vencido_cob' => 300.0,
        'cobranza_total' => 1200.0,
    ]);
});

it('calcula % Sano como cobrado sano entre Sano por cobrar', function () {
    expect($this->ana['sano_pct'])->toEqualWithDelta(600 / 1000, 1e-12);
});

it('calcula % Atraso contra el Sano por cobrar y no contra el Atraso por cobrar', function () {
    expect($this->ana['atraso_pct'])->toEqualWithDelta(300 / 1000, 1e-12)
        ->and($this->ana['atraso_pct'])->not->toEqualWithDelta(300 / 400, 1e-6);
});

it('calcula % Vencido contra el Sano por cobrar y no contra el Vencido por cobrar', function () {
    expect($this->ana['vencido_pct'])->toEqualWithDelta(300 / 1000, 1e-12)
        ->and($this->ana['vencido_pct'])->not->toEqualWithDelta(300 / 400, 1e-6);
});

it('obtiene la efectividad total como suma de los tres aportes = cobranza total / Sano por cobrar', function () {
    $suma = $this->ana['sano_pct'] + $this->ana['atraso_pct'] + $this->ana['vencido_pct'];

    expect($this->ana['pct_total'])->toEqualWithDelta($suma, 1e-12)
        ->and($this->ana['pct_total'])->toEqualWithDelta($this->ana['cobranza_total'] / $this->ana['sano_pc'], 1e-12);
});

it('permite una efectividad total mayor a 100 % sin tope', function () {
    expect($this->ana['pct_total'])->toEqualWithDelta(1.2, 1e-12)
        ->and(F::granTotal($this->resultado)['pct_total'])->toEqualWithDelta(1.2, 1e-12)
        ->and($this->resultado['auditoria']['efectividad'])->toEqualWithDelta(1.2, 1e-12);
});

it('admite mora negativa: Sano por cobrar menos cobranza total, sin max(0)', function () {
    expect($this->ana['mora'])->toBe(-200.0)
        ->and(F::granTotal($this->resultado)['mora'])->toBe(-200.0)
        ->and($this->resultado['auditoria']['mora'])->toBe(-200.0);
});

it('concilia ambos contratos contra la foto de corte (estado CONCILIADO)', function () {
    expect(F::conciliacionDe($this->resultado, 'A')['estatus'])->toBe('COINCIDE')
        ->and(F::conciliacionDe($this->resultado, 'C')['estatus'])->toBe('COINCIDE')
        ->and($this->resultado['auditoria']['estado'])->toBe('CONCILIADO');
});
