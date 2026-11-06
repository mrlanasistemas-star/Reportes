<?php

use App\Services\EstadoResultados\Reconciliation\EstadoResultadosExpenseReconciler as R;
use App\Services\EstadoResultados\Support\FolioNormalizer;
use App\Services\EstadoResultados\Support\Money;

/**
 * Conciliación banco ↔ ERP — función pura y determinista.
 * Casos del documento de requerimientos (REQ-ABC12).
 */
function erMov(string $fp, ?string $folio, string $cargo, string $date, array $extra = []): array
{
    return array_replace([
        'fingerprint' => $fp, 'folio_normalized' => FolioNormalizer::normalize($folio), 'cargo' => $cargo,
        'movement_date' => $date, 'branch_name' => 'CORPORATIVO', 'branch_kind' => 'corporativo',
        'uuid' => null, 'source_row' => (int) preg_replace('/\D/', '', $fp) ?: 1, 'account_slot' => 'administrativa',
    ], $extra);
}

function erErp(string $fp, ?string $folio, string $amount, ?string $fechaPago, array $extra = []): array
{
    return array_replace([
        'fingerprint' => $fp, 'id' => crc32($fp), 'folio' => $folio, 'folio_normalized' => FolioNormalizer::normalize($folio),
        'amount' => $amount, 'fecha_pago' => $fechaPago, 'fecha_captura' => $fechaPago, 'date' => $fechaPago,
        'date_source' => 'fecha_pago', 'branch_id' => 1, 'branch_name' => 'CORPORATIVO', 'universe' => 'corporativo',
        'category' => 'Gastos Operativos', 'concept' => 'SERVICIOS', 'observations' => null, 'status_raw' => 'PAGADA', 'sucursal_raw' => 'Corporativo',
    ], $extra);
}

it('normaliza folios REQ en todas sus variantes y trata S/R como sin folio', function () {
    expect(FolioNormalizer::normalize('REQ-ABC12'))->toBe('ABC12')
        ->and(FolioNormalizer::normalize(' req abc12 '))->toBe('ABC12')
        ->and(FolioNormalizer::normalize('REQ_ABC-12'))->toBe('ABC12')
        ->and(FolioNormalizer::normalize('abc12'))->toBe('ABC12')
        ->and(FolioNormalizer::normalize('S/R'))->toBeNull()
        ->and(FolioNormalizer::normalize(''))->toBeNull();
});

it('concilia exacto: mismo folio, monto y fecha ⇒ se cuenta una sola vez', function () {
    $r = (new R(3))->reconcile(
        [erMov('b1', 'REQ-ABC12', '5000.00', '2026-09-10')],
        [erErp('e1', 'abc12', '5000.00', '2026-09-10')],
    );

    expect($r['results']['b1']['status'])->toBe(R::EXACTO)
        ->and($r['results']['b1']['erp'][0]['fingerprint'])->toBe('e1')
        ->and($r['erp_links'])->toBe(['e1' => ['b1']]);
});

it('folio igual + monto diferente NO se descarta: queda en revisión', function () {
    $r = (new R(3))->reconcile(
        [erMov('b1', 'REQ-ABC12', '4500.00', '2026-09-10')],
        [erErp('e1', 'REQ-ABC12', '5000.00', '2026-09-10')],
    );

    expect($r['results']['b1']['status'])->toBe(R::FOLIO_MONTO_DIFERENTE)
        ->and($r['results']['b1']['message'])->toContain('monto diferente')
        ->and($r['erp_links'])->toBe([]);
});

it('folio repetido con varios pagos soporta conciliación 1:N con suma exacta', function () {
    $r = (new R(3))->reconcile(
        [erMov('b1', 'REQ-ABC12', '2000.00', '2026-09-08'), erMov('b2', 'REQ-ABC12', '3000.00', '2026-09-10')],
        [erErp('e1', 'REQ-ABC12', '5000.00', '2026-09-10', ['fecha_captura' => '2026-09-05'])],
    );

    expect($r['results']['b1']['status'])->toBe(R::AGREGADO)
        ->and($r['results']['b2']['status'])->toBe(R::AGREGADO)
        ->and($r['results']['b1']['message'])->toContain('$2,000.00 + $3,000.00 = $5,000.00')
        ->and($r['erp_links']['e1'])->toBe(['b1', 'b2']);
});

it('no marca como duplicadas todas las filas de un mismo REQ: sólo la que concilia', function () {
    $r = (new R(3))->reconcile(
        [erMov('b1', 'REQ-ABC12', '5000.00', '2026-09-10'), erMov('b2', 'REQ-ABC12', '1200.00', '2026-09-12')],
        [erErp('e1', 'REQ-ABC12', '5000.00', '2026-09-10')],
    );

    expect($r['results']['b1']['status'])->toBe(R::EXACTO)
        ->and($r['results']['b2']['status'])->toBe(R::FOLIO_MONTO_DIFERENTE);
});

it('un segundo pago idéntico del mismo folio no consume el ERP otra vez (posible pago duplicado)', function () {
    $r = (new R(3))->reconcile(
        [erMov('b1', 'REQ-ABC12', '5000.00', '2026-09-10'), erMov('b2', 'REQ-ABC12', '5000.00', '2026-09-11')],
        [erErp('e1', 'REQ-ABC12', '5000.00', '2026-09-10')],
    );

    expect($r['results']['b1']['status'])->toBe(R::EXACTO)
        ->and($r['results']['b2']['status'])->toBe(R::FOLIO_YA_CONCILIADO);
});

it('fecha dentro de la tolerancia queda registrada como tipo propio; fuera de ella pasa a revisión', function () {
    $tol = (new R(3))->reconcile([erMov('b1', 'REQ-X1', '100.00', '2026-09-12')], [erErp('e1', 'REQ-X1', '100.00', '2026-09-10')]);
    expect($tol['results']['b1']['status'])->toBe(R::TOLERANCIA)->and($tol['results']['b1']['delta_days'])->toBe(2);

    $far = (new R(3))->reconcile([erMov('b1', 'REQ-X1', '100.00', '2026-09-20')], [erErp('e1', 'REQ-X1', '100.00', '2026-09-10')]);
    expect($far['results']['b1']['status'])->toBe(R::FOLIO_FECHA_DIFERENTE)->and($far['results']['b1']['delta_days'])->toBe(10);

    $strict = (new R(0))->reconcile([erMov('b1', 'REQ-X1', '100.00', '2026-09-11')], [erErp('e1', 'REQ-X1', '100.00', '2026-09-10')]);
    expect($strict['results']['b1']['status'])->toBe(R::FOLIO_FECHA_DIFERENTE);
});

it('usa fecha_captura cuando el ERP no tiene fecha de pago', function () {
    $r = (new R(0))->reconcile(
        [erMov('b1', 'REQ-Y2', '250.00', '2026-09-03')],
        [erErp('e1', 'REQ-Y2', '250.00', null, ['fecha_captura' => '2026-09-03', 'date' => '2026-09-03', 'date_source' => 'fecha_captura'])],
    );

    expect($r['results']['b1']['status'])->toBe(R::EXACTO)
        ->and($r['results']['b1']['erp'][0]['date_source'])->toBe('fecha_captura');
});

it('ambigüedad 1:N (dos combinaciones posibles) no elige en automático', function () {
    $r = (new R(3))->reconcile(
        [erMov('b1', 'REQ-Z', '100.00', '2026-09-10'), erMov('b2', 'REQ-Z', '200.00', '2026-09-10'), erMov('b3', 'REQ-Z', '100.00', '2026-09-10'), erMov('b4', 'REQ-Z', '200.00', '2026-09-10')],
        [erErp('e1', 'REQ-Z', '300.00', '2026-09-10')],
    );

    expect(collect($r['results'])->pluck('status')->unique()->values()->all())->toBe([R::FOLIO_AMBIGUO])
        ->and($r['erp_links'])->toBe([]);
});

it('UUID duplicado (mismo CFDI, importe y fecha) no se cuenta dos veces', function () {
    $r = (new R(3))->reconcile(
        [erMov('b1', null, '800.00', '2026-09-10', ['uuid' => 'AAA-1']), erMov('b2', null, '800.00', '2026-09-10', ['uuid' => 'AAA-1'])],
        [],
    );

    expect($r['results']['b1']['status'])->toBe(R::SIN_FOLIO)
        ->and($r['results']['b2']['status'])->toBe(R::DUPLICADO_UUID)
        ->and($r['results']['b2']['duplicate_of'])->toBe('b1');
});

it('sin folio con coincidencia de huella NUNCA se descarta: pasa a revisión', function () {
    $r = (new R(3))->reconcile(
        [erMov('b1', 'S/R', '1500.00', '2026-09-10')],
        [erErp('e1', 'REQ-Q9', '1500.00', '2026-09-11')],
    );

    expect($r['results']['b1']['status'])->toBe(R::POSIBLE_DUPLICADO_ERP)
        ->and($r['results']['b1']['erp'][0]['fingerprint'])->toBe('e1');
});

it('folio inexistente en el periodo pero presente en otro periodo queda en revisión', function () {
    $r = (new R(3))->reconcile(
        [erMov('b1', 'REQ-OLD1', '700.00', '2026-09-10')],
        [],
        ['OLD1' => [['period_code' => 'M-2026-08', 'amount' => '700.00', 'date' => '2026-08-30']]],
    );

    expect($r['results']['b1']['status'])->toBe(R::FOLIO_OTRO_PERIODO);
});

it('es determinista: el orden de entrada no cambia el resultado', function () {
    $movs = [erMov('b1', 'REQ-A', '2000.00', '2026-09-08'), erMov('b2', 'REQ-A', '3000.00', '2026-09-10'), erMov('b3', null, '50.00', '2026-09-10')];
    $erp = [erErp('e1', 'REQ-A', '5000.00', '2026-09-10'), erErp('e2', 'REQ-B', '50.00', '2026-09-10')];

    $a = (new R(3))->reconcile($movs, $erp);
    $b = (new R(3))->reconcile(array_reverse($movs), array_reverse($erp));
    ksort($a['results']);
    ksort($b['results']);

    expect($a)->toEqual($b);
});

it('Money opera en decimal exacto y redondea sólo al presentar', function () {
    $sum = Money::sum(['0.10', '0.20', 0.1 + 0.2 - 0.3, '1157377.59']);

    expect(Money::str2($sum))->toBe('1157377.89')
        ->and(Money::cents('3323.9688'))->toBe(332397)
        ->and(Money::equalsCents('5000', 5000.004))->toBeTrue()
        ->and(Money::isZeroCents(Money::of('0.004')))->toBeTrue()
        ->and(Money::isZeroCents(Money::of('0.006')))->toBeFalse()
        ->and(Money::pct('25', '100'))->toBe('25.0000')
        ->and(Money::pct('1', '0'))->toBeNull();
});
