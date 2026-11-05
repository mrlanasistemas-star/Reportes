<?php

use Tests\Support\EfectividadV12Fixtures as F;

/**
 * Regla 24: el contrato T (cuota 1000 del martes 27/10) pasa de ANA a LUIS a partir de
 * la foto del jueves 29/10. ANA cobra 400 el 27/10; LUIS cobra los 600 restantes el 30/10.
 */
beforeEach(function () {
    $t = F::contrato('T', ['primer_pago' => '2026-10-13', 'promotores' => [4 => 'LUIS']]);
    $this->resultado = F::calcular([$t], [
        F::pago($t, 3, '2026-10-27', 400.0),
        F::pago($t, 3, '2026-10-30', 600.0),
    ]);
});

it('solo la meta pendiente pasa al nuevo gestor', function () {
    expect(F::filaDe($this->resultado, 'ANA')['sano_pc'])->toBe(400.0)
        ->and(F::filaDe($this->resultado, 'LUIS')['sano_pc'])->toBe(600.0)
        ->and(F::granTotal($this->resultado)['sano_pc'])->toBe(1000.0)
        ->and($this->resultado['detalle_cuotas']['T|3']['transferencias'])->toBe([
            ['fecha' => '2026-10-29', 'de' => 'CORDOBA~ANA', 'a' => 'CORDOBA~LUIS', 'meta' => 600.0],
        ])
        ->and($this->resultado['estadisticas']['transferencias_gestor'])->toBe(1)
        ->and($this->resultado['auditoria']['transferencias_gestor'])->toBe(1);
});

it('el pago hecho antes de la transferencia conserva su gestor y su bucket', function () {
    $ana = F::filaDe($this->resultado, 'ANA');
    $luis = F::filaDe($this->resultado, 'LUIS');

    expect($ana)->toMatchArray(['sano_cob' => 400.0, 'atraso_cob' => 0.0, 'cobranza_total' => 400.0])
        ->and($ana['pct_total'])->toEqualWithDelta(1.0, 1e-12)
        ->and($luis)->toMatchArray(['sano_cob' => 0.0, 'atraso_cob' => 600.0, 'atraso_pc' => 600.0, 'cobranza_total' => 600.0])
        ->and($luis['atraso_pct'])->toEqualWithDelta(1.0, 1e-12)
        ->and($this->resultado['detalle_cuotas']['T|3']['cobros'])->toBe([
            ['fecha' => '2026-10-27', 'bucket' => 'S', 'gestor' => 'CORDOBA~ANA', 'importe' => 400.0],
            ['fecha' => '2026-10-30', 'bucket' => 'A', 'gestor' => 'CORDOBA~LUIS', 'importe' => 600.0],
        ]);
});
