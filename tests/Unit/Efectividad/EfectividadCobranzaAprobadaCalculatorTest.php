<?php

use App\Services\Efectividad\EfectividadCobranzaAprobadaCalculator;

/**
 * Cada caso aquí corresponde a una regla verificada línea por línea contra el
 * VBA real (ver docs/efectividad-cobranza-aprobada.md) — no contra los
 * antecedentes históricos previos. Donde el antecedente y el VBA real
 * coinciden, se nota; donde el VBA real es más específico o distinto, el
 * test fija ESE comportamiento.
 */
function calc(): EfectividadCobranzaAprobadaCalculator
{
    return new EfectividadCobranzaAprobadaCalculator();
}

function ingreso(array $overrides = []): array
{
    return array_merge([
        'oficina' => 'TULA', 'promotor' => 'JUAN PEREZ', 'contrato' => 'C-1',
        'acreditado' => 'A-1', 'numero_cuota' => '1',
        'operacion' => 'PAGO A CONTRATO', 'transaccion' => 'PAGO',
        'fecha_transaccion' => '2026-04-02', 'fecha_cuota' => '2026-04-02',
        'fecha_vencimiento' => null,
        'capital' => 500.0, 'interes' => 100.0, 'impuesto' => 0.0,
    ], $overrides);
}

function cartera(array $overrides = []): array
{
    return array_merge([
        'oficina' => 'TULA', 'promotor' => 'JUAN PEREZ', 'contrato' => 'C-1',
        'estatus' => 'ACTIVO', 'substatus' => '',
        'fecha_vencimiento' => '2026-12-31',
        'cuota' => 600.0, 'periodicidad' => 'SEMANAL', 'pagos' => 52,
        'fecha_primer_pago' => '2026-04-02',
    ], $overrides);
}

it('applies the exact whitelist — only PAGO A CONTRATO/PAGO and REFINANCIAMIENTO/DESCUENTO count, DESCUENTO alone never does', function () {
    $ingresos = [
        ingreso(['contrato' => 'C-1']), // incluido: PAGO A CONTRATO/PAGO
        ingreso(['contrato' => 'C-2', 'operacion' => 'REFINANCIAMIENTO', 'transaccion' => 'DESCUENTO']), // incluido
        ingreso(['contrato' => 'C-3', 'operacion' => 'CASTIGO DE CARTERA', 'transaccion' => 'CONDONACION']), // excluido
        ingreso(['contrato' => 'C-4', 'operacion' => 'SEGUROS', 'transaccion' => 'DESCUENTO']), // excluido aunque también sea DESCUENTO
        ingreso(['contrato' => 'C-5', 'operacion' => 'COMISION POR APERTURA', 'transaccion' => 'DESCUENTO']), // excluido
    ];
    $cartera = [cartera(['contrato' => 'C-1']), cartera(['contrato' => 'C-2'])];

    $filas = calc()->calculate($cartera, $ingresos, new DateTimeImmutable('2026-03-30'), new DateTimeImmutable('2026-04-05'));

    $granTotal = collect($filas)->firstWhere('promotor', 'GRAN TOTAL');
    // Solo C-1 (500+100=600) y C-2 (500+100=600) cuentan: 1200 cobrado sano.
    expect($granTotal['sano_cob'])->toBe(1200.0);
});

it('classifies a payment as Sano when fTrans <= fCuota', function () {
    $ingresos = [ingreso(['fecha_transaccion' => '2026-04-01', 'fecha_cuota' => '2026-04-02'])];
    $cartera = [cartera()];

    $filas = calc()->calculate($cartera, $ingresos, new DateTimeImmutable('2026-03-30'), new DateTimeImmutable('2026-04-05'));
    $fila = collect($filas)->firstWhere('promotor', 'JUAN PEREZ');

    expect($fila['sano_cob'])->toBe(600.0);
    expect($fila['atraso_cob'])->toBe(0.0);
    expect($fila['vencido_cob'])->toBe(0.0);
});

it('classifies a late payment as Atraso when it is late but still before the row-level fecha_vencimiento', function () {
    $ingresos = [ingreso(['fecha_transaccion' => '2026-04-10', 'fecha_cuota' => '2026-04-02', 'fecha_vencimiento' => '2026-12-31'])];
    $cartera = [cartera()];

    $filas = calc()->calculate($cartera, $ingresos, new DateTimeImmutable('2026-04-06'), new DateTimeImmutable('2026-04-12'));
    $fila = collect($filas)->firstWhere('promotor', 'JUAN PEREZ');

    expect($fila['atraso_cob'])->toBe(600.0);
});

it('classifies a late payment as Vencido when it is past the contract vencimiento', function () {
    $ingresos = [ingreso(['fecha_transaccion' => '2026-04-10', 'fecha_cuota' => '2026-04-02', 'fecha_vencimiento' => '2026-04-05'])];
    $cartera = [cartera()];

    $filas = calc()->calculate($cartera, $ingresos, new DateTimeImmutable('2026-04-06'), new DateTimeImmutable('2026-04-12'));
    $fila = collect($filas)->firstWhere('promotor', 'JUAN PEREZ');

    expect($fila['vencido_cob'])->toBe(600.0);
});

it('falls back to the CARTERA-level vencimiento when the INGRESOS row has none', function () {
    // La fila de ingreso NO trae fecha_vencimiento — debe usar la de CARTERA (2026-04-05), clasificando Vencido.
    $ingresos = [ingreso(['fecha_transaccion' => '2026-04-10', 'fecha_cuota' => '2026-04-02', 'fecha_vencimiento' => null])];
    $cartera = [cartera(['fecha_vencimiento' => '2026-04-05'])];

    $filas = calc()->calculate($cartera, $ingresos, new DateTimeImmutable('2026-04-06'), new DateTimeImmutable('2026-04-12'));
    $fila = collect($filas)->firstWhere('promotor', 'JUAN PEREZ');

    expect($fila['vencido_cob'])->toBe(600.0);
});

it('divides every percentage by sanoPC, never by its own bucket por-cobrar', function () {
    $cartera = [cartera(['cuota' => 1000.0, 'pagos' => 1, 'fecha_primer_pago' => '2026-04-01'])];
    $ingresos = [
        ingreso(['fecha_transaccion' => '2026-04-10', 'fecha_cuota' => '2026-04-01', 'fecha_vencimiento' => '2026-12-31', 'capital' => 200.0, 'interes' => 0, 'impuesto' => 0]),
    ];

    $filas = calc()->calculate($cartera, $ingresos, new DateTimeImmutable('2026-03-30'), new DateTimeImmutable('2026-04-12'));
    $fila = collect($filas)->firstWhere('promotor', 'JUAN PEREZ');

    expect($fila['sano_pc'])->toBe(1000.0);
    expect($fila['atraso_cob'])->toBe(200.0);
    // 200 / 1000 (sanoPC), NO 200/200 (que daría 100%).
    expect($fila['atraso_pct'])->toBe(0.2);
});

it('a VIGENTE-with-atraso contract contributes to Atraso-por-cobrar AND still gets Sano-projected (not mutually exclusive)', function () {
    $cartera = [cartera([
        'substatus' => 'VIGENTE', 'total_atrasado' => 150.0,
        'cuota' => 600.0, 'pagos' => 1, 'fecha_primer_pago' => '2026-04-02',
    ])];

    $filas = calc()->calculate($cartera, [], new DateTimeImmutable('2026-03-30'), new DateTimeImmutable('2026-04-05'));
    $fila = collect($filas)->firstWhere('promotor', 'JUAN PEREZ');

    expect($fila['atraso_pc'])->toBe(150.0);
    expect($fila['sano_pc'])->toBe(600.0); // la proyección de Sano NO se cancela por tener atraso
});

it('a VENCIDO contract NEVER gets Sano-projected, even if it has future cuotas theoretically', function () {
    $cartera = [cartera([
        'substatus' => 'VENCIDO', 'total_pendiente' => 500.0,
        'cuota' => 600.0, 'pagos' => 1, 'fecha_primer_pago' => '2026-04-02',
    ])];

    $filas = calc()->calculate($cartera, [], new DateTimeImmutable('2026-03-30'), new DateTimeImmutable('2026-04-05'));
    $fila = collect($filas)->firstWhere('promotor', 'JUAN PEREZ');

    expect($fila['sano_pc'])->toBe(0.0);
    expect($fila['vencido_pc'])->toBe(500.0);
});

it('suppresses Vencido-por-cobrar entirely when Total pendiente <= 0.01 (the "Eudy $0.80" rule)', function () {
    $cartera = [cartera(['substatus' => 'VENCIDO', 'total_pendiente' => 0.005, 'saldo_capital' => 50.0])];

    $filas = calc()->calculate($cartera, [], new DateTimeImmutable('2026-03-30'), new DateTimeImmutable('2026-04-05'));

    expect(collect($filas)->firstWhere('promotor', 'JUAN PEREZ'))->toBeNull(); // grupo sin datos, omitido
});

it('suppresses Atraso-por-cobrar when Pagos atrasados=0, even if Total atrasado > 0', function () {
    $cartera = [cartera(['substatus' => 'VIGENTE', 'total_atrasado' => 300.0, 'pagos_atrasados' => 0, 'cuota' => 0])];

    $filas = calc()->calculate($cartera, [], new DateTimeImmutable('2026-03-30'), new DateTimeImmutable('2026-04-05'));

    expect(collect($filas)->firstWhere('promotor', 'JUAN PEREZ'))->toBeNull();
});

it('counts Atraso-por-cobrar normally when Pagos atrasados column does not exist at all', function () {
    $cartera = [cartera(['substatus' => 'VIGENTE', 'total_atrasado' => 300.0, 'cuota' => 0])];
    unset($cartera[0]['pagos_atrasados']);

    $filas = calc()->calculate($cartera, [], new DateTimeImmutable('2026-03-30'), new DateTimeImmutable('2026-04-05'));
    $fila = collect($filas)->firstWhere('promotor', 'JUAN PEREZ');

    expect($fila['atraso_pc'])->toBe(300.0);
});

it('never lets a negative saldo contribute to Vencido-por-cobrar (Positivo clamps to 0)', function () {
    $cartera = [cartera(['substatus' => 'VENCIDO', 'saldo_capital' => -500.0, 'saldo_interes' => 100.0])];

    $filas = calc()->calculate($cartera, [], new DateTimeImmutable('2026-03-30'), new DateTimeImmutable('2026-04-05'));
    $fila = collect($filas)->firstWhere('promotor', 'JUAN PEREZ');

    // Solo el saldo_interes positivo (100) cuenta, el capital negativo se trunca a 0 (nunca -500).
    expect($fila['vencido_pc'])->toBe(100.0);
});

it('CERRADO contracts contribute nothing to por-cobrar but their vencimiento still classifies historical payments', function () {
    $cartera = [cartera(['estatus' => 'CERRADO', 'fecha_vencimiento' => '2026-04-05'])];
    $ingresos = [ingreso(['fecha_transaccion' => '2026-04-10', 'fecha_cuota' => '2026-04-02', 'fecha_vencimiento' => null])];

    $filas = calc()->calculate($cartera, $ingresos, new DateTimeImmutable('2026-04-06'), new DateTimeImmutable('2026-04-12'));
    $fila = collect($filas)->firstWhere('promotor', 'JUAN PEREZ');

    // El contrato cerrado no aporta por-cobrar, pero SÍ se clasifica el cobro usando su vencimiento (Vencido).
    expect($fila['sano_pc'])->toBe(0.0);
    expect($fila['vencido_cob'])->toBe(600.0);
});

it('reassigns a route to whoever has the most movements this period, overriding the stale promoter name on old CARTERA rows', function () {
    $cartera = [cartera(['promotor' => 'PROMOTOR VIEJO', 'cuota' => 0])]; // residual, sin proyección sano
    $ingresos = [
        ingreso(['promotor' => 'PROMOTOR NUEVO', 'contrato' => 'C-9']),
        ingreso(['promotor' => 'PROMOTOR NUEVO', 'contrato' => 'C-10']),
        ingreso(['promotor' => 'PROMOTOR VIEJO', 'contrato' => 'C-11']),
    ];

    $filas = calc()->calculate($cartera, $ingresos, new DateTimeImmutable('2026-03-30'), new DateTimeImmutable('2026-04-05'));

    // PROMOTOR NUEVO tiene 2 movimientos vs 1 de PROMOTOR VIEJO -> gana la ruta de TULA.
    expect(collect($filas)->pluck('promotor'))->toContain('PROMOTOR NUEVO');
    expect(collect($filas)->pluck('promotor'))->not->toContain('PROMOTOR VIEJO');
});

it('computes mora as sanoPC minus total collected across all three buckets', function () {
    $cartera = [cartera(['cuota' => 1000.0, 'pagos' => 1, 'fecha_primer_pago' => '2026-04-02'])];
    $ingresos = [ingreso(['fecha_transaccion' => '2026-04-02', 'fecha_cuota' => '2026-04-02', 'capital' => 300.0, 'interes' => 0, 'impuesto' => 0])];

    $filas = calc()->calculate($cartera, $ingresos, new DateTimeImmutable('2026-03-30'), new DateTimeImmutable('2026-04-05'));
    $fila = collect($filas)->firstWhere('promotor', 'JUAN PEREZ');

    expect($fila['mora'])->toBe(700.0); // 1000 - 300
});

it('omits a group entirely when none of its six buckets has real data (never a fully-zero row)', function () {
    $cartera = [cartera(['cuota' => 0, 'substatus' => '', 'fecha_primer_pago' => null])];

    $filas = calc()->calculate($cartera, [], new DateTimeImmutable('2026-03-30'), new DateTimeImmutable('2026-04-05'));

    expect(collect($filas)->firstWhere('promotor', 'JUAN PEREZ'))->toBeNull();
    expect(collect($filas)->firstWhere('promotor', 'GRAN TOTAL'))->not->toBeNull();
});

it('reconstructs Sano-por-cobrar for a payment whose contract already left CARTERA (never inflates effectiveness by missing denominator)', function () {
    // Sin CARTERA en absoluto para C-77, pero el ingreso tiene fecha_cuota en el periodo.
    $ingresos = [ingreso(['contrato' => 'C-77', 'fecha_transaccion' => '2026-04-02', 'fecha_cuota' => '2026-04-02', 'capital' => 400.0, 'interes' => 0, 'impuesto' => 0])];

    $filas = calc()->calculate([], $ingresos, new DateTimeImmutable('2026-03-30'), new DateTimeImmutable('2026-04-05'));
    $fila = collect($filas)->firstWhere('promotor', 'JUAN PEREZ');

    expect($fila['sano_pc'])->toBe(400.0); // reconstruido desde el propio pago, no quedó en 0
    expect($fila['sano_cob'])->toBe(400.0);
    expect($fila['sano_pct'])->toBe(1.0);
});
