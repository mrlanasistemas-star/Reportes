<?php

use App\Services\Efectividad\V12\V12Buckets;
use App\Services\Efectividad\V12\V12Vba;
use Tests\Support\EfectividadV12Fixtures as F;

/**
 * Migración entre buckets: la deuda existe UNA sola vez. Atraso y Vencido por cobrar
 * son vistas de control y no se suman al Sano por cobrar.
 */
it('la migración Sano → Atraso no duplica la meta: la cuota impagada cuenta una vez en Sano y su saldo aparece en Atraso solo como control', function () {
    $a = F::contrato('A');
    $r = F::calcular([$a], [F::pago($a, 3, '2026-10-28', 250.0)]);
    $ana = F::filaDe($r, 'ANA');

    expect($ana)->toMatchArray([
        'sano_pc' => 1000.0, 'sano_cob' => 250.0,
        'atraso_pc' => 750.0, 'atraso_cob' => 0.0,
        'vencido_pc' => 0.0, 'mora' => 750.0,
    ])->and($r['detalle_cuotas']['A|3']['pendiente'])->toBe(['bucket' => 'A', 'gestor' => 'CORDOBA~ANA', 'importe' => 750.0])
        ->and(F::granTotal($r)['sano_pc'])->toBe(1000.0)
        ->and($r['auditoria']['sano_pc'])->toBe(1000.0);
});

it('la migración Atraso → Vencido (contrato vencido antes del corte) no duplica la deuda', function () {
    // V: 3 cuotas de 500 (14/10, 21/10, 28/10); vence el 28/10. La cuota del 21/10 está
    // atrasada en la foto base (500); se abonan 200 el 27/10 (aún no vencía el contrato).
    $v = F::contrato('V', ['pagos' => 3, 'cuota' => 500.0, 'pagado_previo' => [2 => 0.0]]);
    $r = F::calcular([$v], [F::pago($v, 2, '2026-10-27', 200.0)]);
    $ana = F::filaDe($r, 'ANA');

    expect($ana)->toMatchArray([
        'sano_pc' => 500.0, 'sano_cob' => 0.0,
        'atraso_pc' => 200.0, 'atraso_cob' => 200.0,   // el cobro conserva el bucket del día en que ocurrió
        'vencido_pc' => 800.0, 'vencido_cob' => 0.0,   // 300 histórico + 500 de la cuota del 28/10
    ])
        // Deuda inicial (500 atraso + 500 cuota) aparece una sola vez entre Atraso y Vencido.
        ->and($ana['atraso_pc'] + $ana['vencido_pc'])->toBe(1000.0)
        ->and($r['detalle_cuotas']['V|3']['pendiente']['bucket'])->toBe('V')
        // El saldo vencido al corte coincide con la foto de corte (sin diferencia).
        ->and(F::conciliacionDe($r, 'V'))->toMatchArray(['atraso_cierre' => 800.0, 'diferencia' => 0.0, 'estatus' => 'COINCIDE']);
});

it('clasifica el bucket por fecha: mismo día = Sano, después de la cuota = Atraso, después del vencimiento = Vencido', function () {
    $cuota = V12Vba::dia(2026, 10, 28);
    $vence = V12Vba::dia(2026, 10, 30);

    expect(V12Buckets::tipoBucket($cuota - 1, $cuota, $vence))->toBe('S')
        ->and(V12Buckets::tipoBucket($cuota, $cuota, $vence))->toBe('S')
        ->and(V12Buckets::tipoBucket($cuota + 1, $cuota, $vence))->toBe('A')
        ->and(V12Buckets::tipoBucket($vence, $cuota, $vence))->toBe('A')
        ->and(V12Buckets::tipoBucket($vence + 1, $cuota, $vence))->toBe('V');
});
