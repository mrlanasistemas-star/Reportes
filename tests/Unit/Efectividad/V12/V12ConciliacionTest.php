<?php

use Tests\Support\EfectividadV12Fixtures as F;

/**
 * Conciliación del neto y control contra la foto de corte: la evidencia faltante se
 * reporta (incidencia / PRELIMINAR), nunca se convierte en cobro ni se reparte a ciegas.
 */
it('un contrato que desaparece de las fotos posteriores no se trata como pagado ni liquidado', function () {
    // X: cuota 1000 del martes 27/10, sin pagos; aparece hasta la foto del 27/10.
    $x = F::contrato('X', ['primer_pago' => '2026-10-13', 'presente' => [0, 1, 2]]);
    $r = F::calcular([$x]);

    expect(F::filaDe($r, 'ANA'))->toMatchArray([
        'sano_pc' => 1000.0, 'sano_cob' => 0.0, 'atraso_pc' => 1000.0, 'cobranza_total' => 0.0, 'mora' => 1000.0,
    ])
        ->and(F::conciliacionDe($r, 'X'))->toMatchArray([
            'gestor' => 'SIN FOTO FINAL', 'exigible_neto' => 1000.0, 'cobranza_elegible' => 0.0,
            'atraso_cierre' => 0.0, 'diferencia' => 1000.0, 'estatus' => 'DESAPARICION SIN SOPORTE',
        ])
        ->and(F::incidenciasDe($r, 'X'))->toContain('DESAPARICION-SIN-SOPORTE')
        ->and($r['auditoria'])->toMatchArray([
            'estado' => 'PRELIMINAR', 'desapariciones_sin_soporte' => 1, 'saldo_sin_soporte' => 1000.0, 'cobranza_total' => 0.0,
        ]);
});

it('una diferencia global de DX:DZ no se reparte entre varias cuotas parciales (MULTI-ANTICIPO)', function () {
    // M: crédito diario de 100 (lunes a viernes). Antes de la semana se anticiparon 50 a
    // la cuota 6 (26/10) y 50 a la 7 (27/10) SIN folio en el historial: la foto base solo
    // lo refleja en el saldo global (DX:DZ = 1,400 en lugar de 1,500).
    $m = F::contrato('M', ['periodicidad' => 'DIARIA', 'primer_pago' => '2026-10-19', 'pagos' => 20, 'cuota' => 100.0,
        'pagado_previo' => [6 => 50.0, 7 => 50.0]]);
    $r = F::calcular([$m], [
        F::pago($m, 6, '2026-10-26', 50.0),
        F::pago($m, 7, '2026-10-27', 50.0),
        F::pago($m, 8, '2026-10-28', 100.0),
        F::pago($m, 9, '2026-10-29', 100.0),
        F::pago($m, 10, '2026-10-30', 100.0),
    ]);

    expect(F::filaDe($r, 'ANA'))->toMatchArray(['sano_pc' => 500.0, 'sano_cob' => 400.0, 'atraso_pc' => 100.0])
        ->and(F::incidenciasDe($r, 'M'))->toContain('MULTI-ANTICIPO')
        ->and($r['estadisticas']['cuotas_inferidas'])->toBe(0)
        ->and($r['detalle_cuotas']['M|6']['meta'])->toBe(100.0)
        ->and($r['detalle_cuotas']['M|7']['meta'])->toBe(100.0)
        // La diferencia queda visible en la conciliación en vez de repartirse.
        ->and(F::conciliacionDe($r, 'M'))->toMatchArray(['diferencia' => 100.0, 'estatus' => 'POR CONCILIAR'])
        ->and($r['auditoria']['estado'])->toBe('PRELIMINAR');
});

it('con UNA sola cuota parcial candidata el anticipo se descuenta y se marca como inferido', function () {
    $m = F::contrato('M', ['periodicidad' => 'DIARIA', 'primer_pago' => '2026-10-19', 'pagos' => 20, 'cuota' => 100.0,
        'pagado_previo' => [6 => 50.0]]);
    $r = F::calcular([$m], [
        F::pago($m, 6, '2026-10-26', 50.0),
        F::pago($m, 7, '2026-10-27', 100.0),
        F::pago($m, 8, '2026-10-28', 100.0),
        F::pago($m, 9, '2026-10-29', 100.0),
        F::pago($m, 10, '2026-10-30', 100.0),
    ]);
    $traza = collect($r['amortizaciones'])->first(fn ($t) => $t['contrato'] === 'M' && $t['numero'] === '6');

    expect(F::filaDe($r, 'ANA'))->toMatchArray(['sano_pc' => 450.0, 'sano_cob' => 450.0, 'atraso_pc' => 0.0])
        ->and($traza)->toMatchArray(['estimado' => 50.0, 'calidad' => 'INFERIDO', 'evidencia' => 'SALDO GLOBAL / CUOTA ESTIMADA'])
        ->and(F::incidenciasDe($r, 'M'))->toContain('META-INFERIDA')
        ->and(F::incidenciasDe($r, 'M'))->not->toContain('MULTI-ANTICIPO')
        ->and(F::conciliacionDe($r, 'M')['estatus'])->toBe('COINCIDE')
        // Inferencia = reporte preliminar aunque la cartera cuadre.
        ->and($r['auditoria'])->toMatchArray(['estado' => 'PRELIMINAR', 'cuotas_inferidas' => 1, 'importe_inferido' => 50.0]);
});

it('los ajustes de conciliación nunca usan la cobranza de la semana: sin evidencia de anticipo la meta no cambia', function () {
    $a = F::contrato('A');
    $r = F::calcular([$a], [F::pago($a, 3, '2026-10-28', 1000.0)]);

    expect(F::filaDe($r, 'ANA'))->toMatchArray(['sano_pc' => 1000.0, 'sano_cob' => 1000.0, 'mora' => 0.0])
        ->and($r['estadisticas'])->toMatchArray(['cuotas_inferidas' => 0, 'contratos_conciliados' => 0])
        ->and($r['auditoria']['estado'])->toBe('CONCILIADO');
});
