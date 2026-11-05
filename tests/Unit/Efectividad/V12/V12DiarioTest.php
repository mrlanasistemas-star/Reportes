<?php

use App\Services\Efectividad\V12\EfectividadV12Calculator;
use App\Services\Efectividad\V12\V12Exception;
use Tests\Support\EfectividadV12Fixtures as F;

/**
 * Modalidad DIARIO: foto base = cierre del día anterior; periodo = el día de corte.
 * Corte: miércoles 28/10/2026 (fotos 27/10 y 28/10).
 */
it('una cuota que vence HOY (fecha cuota = corte) sigue SANA: no genera Atraso por cobrar', function () {
    $r = F::calcular([F::contrato('A')], [], ['modo' => 'DIARIO', 'fecha_corte' => '2026-10-28']);

    expect(F::filaDe($r, 'ANA'))->toMatchArray([
        'sano_pc' => 1000.0, 'sano_cob' => 0.0, 'atraso_pc' => 0.0, 'vencido_pc' => 0.0, 'mora' => 1000.0,
    ])
        // El saldo pendiente al corte se clasifica SANO (no integra Atraso por cobrar).
        ->and($r['detalle_cuotas']['A|3']['pendiente'])->toBe(['bucket' => 'S', 'gestor' => 'CORDOBA~ANA', 'importe' => 1000.0])
        // La foto del cierre aún no la exige como atraso: la conciliación cuadra.
        ->and(F::conciliacionDe($r, 'A'))->toMatchArray(['exigible_neto' => 1000.0, 'atraso_cierre' => 0.0, 'estatus' => 'COINCIDE']);
});

it('el pago de una cuota que vence hoy es cobranza sana', function () {
    $a = F::contrato('A');
    $r = F::calcular([$a], [F::pago($a, 3, '2026-10-28', 1000.0)], ['modo' => 'DIARIO', 'fecha_corte' => '2026-10-28']);

    expect(F::filaDe($r, 'ANA'))->toMatchArray(['sano_pc' => 1000.0, 'sano_cob' => 1000.0, 'atraso_cob' => 0.0])
        ->and(F::filaDe($r, 'ANA')['sano_pct'])->toEqualWithDelta(1.0, 1e-12);
});

it('usa la foto del día anterior como base del periodo', function () {
    // D: cuota del lunes 26/10 impagada → la foto del 27/10 ya la muestra atrasada.
    // En DIARIO (corte 28/10) esa cuota es histórica: su cobro es Atraso y no hay meta sana.
    $d = F::contrato('D', ['primer_pago' => '2026-10-12']);
    $pago = F::pago($d, 3, '2026-10-28', 1000.0);
    $r = F::calcular([$d], [$pago], ['modo' => 'DIARIO', 'fecha_corte' => '2026-10-28']);

    expect($r['parametros'])->toMatchArray([
        'modo' => 'DIARIO', 'foto_base' => '2026-10-27', 'inicio_ingresos' => '2026-10-28', 'fin_ingresos' => '2026-10-28',
    ])
        ->and(F::filaDe($r, 'ANA'))->toMatchArray([
            'sano_pc' => 0.0, 'atraso_pc' => 1000.0, 'atraso_cob' => 1000.0, 'sano_pct' => null, 'pct_total' => null,
        ])
        ->and(F::conciliacionDe($r, 'D'))->toMatchArray(['atraso_base' => 1000.0, 'estatus' => 'COINCIDE']);

    // Misma cuota en SEMANAL (base = domingo 25/10): es la meta sana de la semana.
    $semanal = F::calcular([$d], [$pago]);
    expect(F::filaDe($semanal, 'ANA'))->toMatchArray(['sano_pc' => 1000.0, 'atraso_cob' => 1000.0]);
});

it('en DIARIO solo exige las fotos del día anterior y del corte', function () {
    $a = F::contrato('A');
    $calc = new EfectividadV12Calculator;
    $parametros = ['modo' => 'DIARIO', 'domingo_base' => F::DOMINGO, 'fecha_corte' => '2026-10-28'];

    // Fotos 2 (27/10) y 3 (28/10) bastan.
    $r = $calc->calculate($parametros, F::fotos([$a], [], [2, 3]), F::ingresos([]), F::ingresos([]));
    expect(F::filaDe($r, 'ANA')['sano_pc'])->toBe(1000.0);

    // Sin la foto del día anterior: error de fotografía faltante.
    try {
        $calc->calculate($parametros, F::fotos([$a], [], [3]), F::ingresos([]), F::ingresos([]));
        $this->fail('Se esperaba V12Exception');
    } catch (V12Exception $e) {
        expect($e->codigo)->toBe('EC-V12-204')
            ->and($e->contexto)->toBe(['foto' => 2, 'fecha' => '2026-10-27']);
    }
});
