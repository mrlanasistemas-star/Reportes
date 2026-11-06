<?php

use App\Services\EstadoResultados\Reconciliation\EstadoResultadosExpenseReconciler as R;
use App\Services\EstadoResultados\Reconciliation\NoiPayrollRepository;
use App\Services\EstadoResultados\Reconciliation\RefundMatcher;
use App\Services\EstadoResultados\Reconciliation\ReversalMatcher;
use App\Services\EstadoResultados\Support\Dimensions;
use App\Services\EstadoResultados\Support\FolioNormalizer;
use App\Services\EstadoResultados\Support\PersonName;
use App\Services\EstadoResultados\Support\Treatment as T;

/**
 * Conciliación endurecida: N:1, N:M, parcial, ambigüedad, reversos, reembolsos,
 * vínculo NOI por nombre y dimensiones independientes (funciones puras).
 */
function rmMov(string $fp, ?string $folio, string $cargo, string $date, array $extra = []): array
{
    return array_replace([
        'fingerprint' => $fp, 'folio_normalized' => FolioNormalizer::normalize($folio), 'cargo' => $cargo,
        'movement_date' => $date, 'branch_name' => 'CORPORATIVO', 'branch_kind' => 'corporativo',
        'uuid' => null, 'source_row' => (int) preg_replace('/\D/', '', $fp) ?: 1, 'account_slot' => 'administrativa',
    ], $extra);
}

function rmErp(string $fp, ?string $folio, string $amount, ?string $fechaPago, array $extra = []): array
{
    return array_replace([
        'fingerprint' => $fp, 'id' => crc32($fp), 'folio' => $folio, 'folio_normalized' => FolioNormalizer::normalize($folio),
        'amount' => $amount, 'fecha_pago' => $fechaPago, 'fecha_captura' => $fechaPago, 'date' => $fechaPago,
        'date_source' => 'fecha_pago', 'branch_id' => 1, 'branch_name' => 'CORPORATIVO', 'universe' => 'corporativo',
        'category' => 'Gastos Operativos', 'concept' => 'SERVICIOS', 'observations' => null, 'status_raw' => 'PAGADA', 'sucursal_raw' => 'Corporativo',
    ], $extra);
}

it('N:1 — un pago liquida varias requisiciones del mismo folio con suma exacta', function () {
    $r = (new R(3))->reconcile(
        [rmMov('b1', 'REQ-M1', '5000.00', '2026-09-10')],
        [rmErp('e1', 'REQ-M1', '3000.00', '2026-09-10'), rmErp('e2', 'REQ-M1', '2000.00', '2026-09-09')],
    );

    expect($r['results']['b1']['status'])->toBe(R::AGREGADO_ERP)
        ->and($r['results']['b1']['match_type'])->toBe('N:1')
        ->and($r['results']['b1']['applied'])->toBe('5000.00')
        ->and(collect($r['allocations'])->pluck('applied', 'erp')->sortKeys()->all())->toBe(['e1' => '3000.00', 'e2' => '2000.00'])
        ->and($r['erp_balances']['e1']['pending'])->toBe('0.00')
        ->and($r['erp_balances']['e2']['pending'])->toBe('0.00');
});

it('N:1 ambiguo (dos combinaciones de requisiciones) no elige en automático', function () {
    $r = (new R(3))->reconcile(
        [rmMov('b1', 'REQ-Z', '300.00', '2026-09-10')],
        [rmErp('e1', 'REQ-Z', '100.00', '2026-09-10'), rmErp('e2', 'REQ-Z', '200.00', '2026-09-10'), rmErp('e3', 'REQ-Z', '100.00', '2026-09-10'), rmErp('e4', 'REQ-Z', '200.00', '2026-09-10')],
    );

    expect($r['results']['b1']['status'])->toBe(R::FOLIO_AMBIGUO)
        ->and($r['allocations'])->toBe([])
        ->and($r['erp_links'])->toBe([]);
});

it('N:M — varios pagos y varias requisiciones del folio cuadran al centavo; la asignación es determinista', function () {
    $movs = [rmMov('b1', 'REQ-M2', '2500.00', '2026-09-10'), rmMov('b2', 'REQ-M2', '4500.00', '2026-09-11')];
    $erp = [rmErp('e1', 'REQ-M2', '3000.00', '2026-09-10'), rmErp('e2', 'REQ-M2', '4000.00', '2026-09-11')];
    $r = (new R(3))->reconcile($movs, $erp);
    $again = (new R(3))->reconcile(array_reverse($movs), array_reverse($erp));

    expect($r['results']['b1']['status'])->toBe(R::MULTIPLE)
        ->and($r['results']['b2']['status'])->toBe(R::MULTIPLE)
        ->and(array_map(fn ($a) => [$a['movement'], $a['erp'], $a['applied']], $r['allocations']))->toEqualCanonicalizing([
            ['b1', 'e1', '2500.00'], ['b2', 'e1', '500.00'], ['b2', 'e2', '4000.00'],
        ])
        ->and(collect($r['erp_balances'])->pluck('pending')->unique()->values()->all())->toBe(['0.00'])
        ->and($again['allocations'])->toBe($r['allocations']);
});

it('N:M no concilia si las sumas difieren por un centavo', function () {
    $r = (new R(3))->reconcile(
        [rmMov('b1', 'REQ-M3', '2500.00', '2026-09-10'), rmMov('b2', 'REQ-M3', '4500.01', '2026-09-11')],
        [rmErp('e1', 'REQ-M3', '3000.00', '2026-09-10'), rmErp('e2', 'REQ-M3', '4000.00', '2026-09-11')],
    );

    expect(collect($r['results'])->pluck('status')->unique()->values()->all())->toBe([R::FOLIO_MONTO_DIFERENTE])
        ->and($r['allocations'])->toBe([]);
});

it('parcial por excedente: sólo se aplica el importe del ERP y el resto queda pendiente', function () {
    $r = (new R(3))->reconcile([rmMov('b1', 'REQ-P1', '5500.00', '2026-09-10')], [rmErp('e1', 'REQ-P1', '5000.00', '2026-09-10')]);

    expect($r['results']['b1']['status'])->toBe(R::PARCIAL)
        ->and($r['results']['b1']['applied'])->toBe('5000.00')
        ->and($r['results']['b1']['pending'])->toBe('500.00')
        ->and($r['erp_balances']['e1'])->toBe(['amount' => '5000.00', 'applied' => '5000.00', 'pending' => '0.00']);
});

it('parcial por pago menor sólo con estatus ERP de pago parcial; con PAGADA queda en revisión', function () {
    $erp = fn (string $status) => [rmErp('e1', 'REQ-P2', '5000.00', '2026-09-10', ['status_raw' => $status])];
    $partial = (new R(3, 8, ['PAGO_PARCIAL']))->reconcile([rmMov('b1', 'REQ-P2', '2000.00', '2026-09-10')], $erp('PAGO_PARCIAL'));
    $paid = (new R(3, 8, ['PAGO_PARCIAL']))->reconcile([rmMov('b1', 'REQ-P2', '2000.00', '2026-09-10')], $erp('PAGADA'));

    expect($partial['results']['b1']['status'])->toBe(R::PARCIAL)
        ->and($partial['results']['b1']['pending'])->toBe('0.00')
        ->and($partial['erp_balances']['e1']['pending'])->toBe('3000.00')
        ->and($paid['results']['b1']['status'])->toBe(R::FOLIO_MONTO_DIFERENTE)
        ->and($paid['allocations'])->toBe([]);
});

it('reverso: cargo y abono de la misma cuenta, mismo importe y evidencia se anulan; sin evidencia no', function () {
    $mk = fn ($fp, $cargo, $abono, $date, $concept, $folio = null, $slot = 'administrativa') => [
        'fingerprint' => $fp, 'account_slot' => $slot, 'cargo' => $cargo, 'abono' => $abono, 'movement_date' => $date,
        'source_row' => (int) substr($fp, 1), 'folio_normalized' => $folio, 'concept' => $concept, 'bank_reference' => null,
    ];
    $pairs = (new ReversalMatcher(3, 'REVERS|CANCELACION'))->match([
        $mk('m1', '1200.00', '0', '2026-09-10', 'PAGO PROVEEDOR'),
        $mk('m2', '0', '1200.00', '2026-09-11', 'REVERSO SPEI'),
        $mk('m3', '800.00', '0', '2026-09-10', 'PAGO X'),
        $mk('m4', '0', '800.00', '2026-09-10', 'DEPOSITO CLIENTE'),         // sin evidencia
        $mk('m5', '300.00', '0', '2026-09-10', 'PAGO Y', 'ABC'),
        $mk('m6', '0', '300.00', '2026-09-12', 'ABONO Y', 'ABC', 'excedentes'), // otra cuenta
    ]);

    expect(array_keys($pairs))->toEqualCanonicalizing(['m1', 'm2'])
        ->and($pairs['m1']['key'])->toBe($pairs['m2']['key'])
        ->and($pairs['m1']['counterpart'])->toBe('m2');
});

it('reembolso: vincula por folio con un único gasto; varios candidatos = ambiguo; sin folio = sin vínculo', function () {
    $matcher = new RefundMatcher;
    $targets = [
        ['fingerprint' => 'erp:1', 'folio_normalized' => 'R1', 'amount' => '5000.00', 'source' => 'erp', 'universe' => 'corporativo'],
        ['fingerprint' => 'erp:2', 'folio_normalized' => 'R2', 'amount' => '100.00', 'source' => 'erp', 'universe' => 'corporativo'],
        ['fingerprint' => 'b9', 'folio_normalized' => 'R2', 'amount' => '200.00', 'source' => 'banco', 'universe' => null],
        ['fingerprint' => 'erp:3', 'folio_normalized' => 'R3', 'amount' => '900.00', 'source' => 'erp', 'universe' => 'radiografia'],
    ];

    expect($matcher->match(['fingerprint' => 'a', 'folio_normalized' => 'R1', 'abono' => '500.00'], $targets)['status'])->toBe('vinculado')
        ->and($matcher->match(['fingerprint' => 'a', 'folio_normalized' => 'R1', 'abono' => '6000.00'], $targets)['status'])->toBe('sin_vinculo')
        ->and($matcher->match(['fingerprint' => 'a', 'folio_normalized' => 'R2', 'abono' => '50.00'], $targets)['status'])->toBe('ambiguo')
        ->and($matcher->match(['fingerprint' => 'a', 'folio_normalized' => 'R3', 'abono' => '50.00'], $targets)['status'])->toBe('radiografia')
        ->and($matcher->match(['fingerprint' => 'a', 'folio_normalized' => null, 'abono' => '50.00'], $targets)['status'])->toBe('sin_vinculo')
        ->and($matcher->isRefund(['abono' => '10', 'concept' => 'DEVOLUCIÓN REQ']))->toBeTrue()
        ->and($matcher->isRefund(['abono' => '0', 'cargo' => '10', 'concept' => 'DEVOLUCION']))->toBeFalse();
});

it('vínculo NOI exige el mismo conjunto de palabras del nombre; homónimos quedan ambiguos', function () {
    $noi = [
        'employees' => [
            PersonName::key('JUAN PEREZ LOPEZ') => ['employee_id' => 1, 'name' => 'JUAN PEREZ LOPEZ', 'branch' => 'CORDOBA', 'operative' => true, 'total' => '9000.00'],
            PersonName::key('ANA RUIZ') => ['employee_id' => 2, 'name' => 'ANA RUIZ', 'branch' => null, 'operative' => false, 'total' => '5000.00'],
        ],
        'ambiguous_keys' => [PersonName::key('ANA RUIZ')],
    ];

    expect(NoiPayrollRepository::match($noi, 'Pérez López, Juan')['status'])->toBe('unico')
        ->and(NoiPayrollRepository::match($noi, 'JUAN PEREZ')['status'])->toBe('ninguno')
        ->and(NoiPayrollRepository::match($noi, 'RUIZ ANA')['status'])->toBe('ambiguo')
        ->and(NoiPayrollRepository::match($noi, 'Finiquito de Juan Pérez López (RH)', true)['employee']['employee_id'])->toBe(1)
        ->and(NoiPayrollRepository::match($noi, 'Pago a Juan López', true)['status'])->toBe('ninguno');
});

it('las dimensiones son independientes: conciliación ≠ clasificación ≠ inclusión', function () {
    $d = fn (array $c) => Dimensions::derive($c + ['source' => 'banco', 'in_period' => true, 'applied' => '0.00', 'has_override' => false, 'recognizing_hint' => null, 'import' => 'importado']);

    $conciliado = $d(['treatment' => T::CONCILIADO_ERP, 'reconciliation_status' => R::EXACTO, 'applied' => '100.00', 'classifiable' => '0.00', 'recognizing_hint' => 'erp']);
    $excedente = $d(['treatment' => T::PENDIENTE, 'reconciliation_status' => R::PARCIAL, 'applied' => '100.00', 'classifiable' => '20.00', 'recognizing_hint' => 'erp']);
    $excedenteClasificado = $d(['treatment' => T::OPEX_CORPORATIVO, 'reconciliation_status' => R::PARCIAL, 'applied' => '100.00', 'classifiable' => '20.00', 'has_override' => true]);
    $nuevo = $d(['treatment' => T::NOMINA_CORPORATIVA, 'reconciliation_status' => R::SIN_FOLIO, 'classifiable' => '50.00']);
    $fuera = $d(['treatment' => T::FUERA_PERIODO, 'reconciliation_status' => 'fuera_periodo', 'in_period' => false]);

    expect([$conciliado['reconciliation'], $conciliado['inclusion'], $conciliado['source'], $conciliado['category']])->toBe(['conciliado', 'excluido_justificado', 'erp', null])
        ->and([$excedente['reconciliation'], $excedente['inclusion'], $excedente['review']])->toBe(['conciliado_parcial', 'pendiente', 'pendiente'])
        ->and([$excedenteClasificado['inclusion'], $excedenteClasificado['category'], $excedenteClasificado['review']])->toBe(['incluido_parcial', 'opex_corporativo', 'revisado'])
        ->and([$nuevo['reconciliation'], $nuevo['inclusion'], $nuevo['category'], $nuevo['accounting'], $nuevo['source']])->toBe(['sin_coincidencia', 'incluido', 'nomina_corporativa', 'gasto_operativo', 'banco'])
        ->and([$fuera['inclusion'], $fuera['category'], $fuera['accounting']])->toBe(['fuera_periodo', null, 'otro_periodo'])
        ->and(T::category(T::DG_REMUNERACION))->toBe('opex_dg')
        ->and(collect(Dimensions::catalog()['category'])->pluck('key')->all())->toBe(['opex_sucursales', 'nomina_sucursales', 'opex_corporativo', 'nomina_corporativa', 'opex_dg', 'da', 'financiero', 'isr']);
});
