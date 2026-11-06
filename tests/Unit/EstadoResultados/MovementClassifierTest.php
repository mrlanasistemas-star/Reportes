<?php

use App\Services\EstadoResultados\Classification\ErpOutsideClassifier;
use App\Services\EstadoResultados\Classification\MovementClassifier;
use App\Services\EstadoResultados\Reconciliation\EstadoResultadosExpenseReconciler as R;
use App\Services\EstadoResultados\Support\Treatment as T;

function erClassify(array $m, ?array $rec = null): array
{
    return (new MovementClassifier)->classify(array_replace([
        'in_period' => true, 'concept' => '', 'observations' => null, 'bank_reference' => '',
        'cargo' => '0', 'abono' => '0', 'branch_kind' => 'corporativo', 'branch_name' => 'CORPORATIVO', 'sucursal_raw' => 'CORPORATIVO',
    ], $m), $rec);
}

it('traspaso entre cuentas no es ingreso ni gasto (ambos sentidos)', function () {
    $out = erClassify(['concept' => 'TRASPASO ENTRE CUENTAS', 'observations' => 'EXCEDENTES A ADMINISTRATIVA', 'cargo' => '112000']);
    $in = erClassify(['concept' => 'TRASPASO ENTRE CUENTAS', 'observations' => 'ADMINISTRATIVA A EXCEDENTES', 'abono' => '75000']);

    expect($out['treatment'])->toBe(T::TRANSFERENCIA_INTERNA)
        ->and($in['treatment'])->toBe(T::TRANSFERENCIA_INTERNA)
        ->and(T::affectsStatement(T::TRANSFERENCIA_INTERNA))->toBeFalse();
});

it('un abono nunca se convierte en ingreso operativo automáticamente', function () {
    foreach (['RETIRO EXCEDENTE', 'PROVISION IMSS', 'DEPOSITO CREDITO LEROM 24', 'DESC PRESTAMO PERSONAL', 'DEVOLUCION REQ', 'GASTOS SUCURSALES', 'INDEMNIZACION'] as $concept) {
        $c = erClassify(['concept' => $concept, 'abono' => '1000', 'branch_kind' => 'operativa', 'branch_name' => 'CORDOBA']);
        expect(T::line($c['treatment']))->toBeNull("{$concept} no debe sumar en automático");
    }
});

it('el pago de un crédito bancario va a capital de deuda (fuera del ER), no a gasto financiero', function () {
    $c = erClassify(['concept' => 'PAGO 24 CREDITO LEROM', 'cargo' => '45045']);

    expect($c['treatment'])->toBe(T::CAPITAL_DEUDA)
        ->and(T::affectsStatement(T::CAPITAL_DEUDA))->toBeFalse()
        ->and($c['reason'])->toContain('intereses');
});

it('movimientos de Dirección General nunca se suman en automático: requieren revisión con sugerencia', function () {
    $personal = erClassify(['concept' => 'COLEGIATURA DEBORA DG', 'cargo' => '10339.46']);
    $operativo = erClassify(['concept' => 'REQ VIATICOS DG', 'cargo' => '5000']);

    expect($personal['treatment'])->toBe(T::PENDIENTE)->and($personal['suggested'])->toBe(T::PERSONAL_SOCIOS)
        ->and($operativo['treatment'])->toBe(T::PENDIENTE)->and($operativo['suggested'])->toBe(T::DG_OPERATIVO);
});

it('nómina de sucursal ya está en Radiografía (NOI) y la corporativa sí suma; los préstamos nunca son nómina', function () {
    $suc = erClassify(['concept' => 'PAGO NOM', 'cargo' => '2205.28', 'branch_kind' => 'operativa', 'branch_name' => 'CORDOBA']);
    $corp = erClassify(['concept' => 'NOM QUINCENA 17', 'cargo' => '214032.81']);
    $prestamo = erClassify(['concept' => 'PAGO PRESTAMOS QUINCENA 17', 'cargo' => '11800']);

    expect($suc['treatment'])->toBe(T::CUBIERTO_RADIOGRAFIA)
        ->and($corp['treatment'])->toBe(T::NOMINA_CORPORATIVA)
        ->and($prestamo['treatment'])->toBe(T::PENDIENTE)
        ->and($prestamo['suggested'])->toBe(T::RECUPERABLE);
});

it('un cargo conciliado con ERP no se vuelve a sumar y uno con folio y monto distinto queda en revisión', function () {
    $ok = erClassify(['concept' => 'REQ SERVICIOS', 'cargo' => '5000'], ['status' => R::EXACTO, 'message' => 'ok']);
    $diff = erClassify(['concept' => 'REQ SERVICIOS', 'cargo' => '4500'], ['status' => R::FOLIO_MONTO_DIFERENTE, 'message' => 'Mismo folio, monto diferente']);

    expect($ok['treatment'])->toBe(T::CONCILIADO_ERP)
        ->and(T::affectsStatement($ok['treatment']))->toBeFalse()
        ->and($diff['treatment'])->toBe(T::PENDIENTE)
        ->and($diff['review'])->toBeTrue();
});

it('un cargo de sucursal sin ERP no se suma solo (protege el EBITDA de sucursales)', function () {
    $c = erClassify(['concept' => 'REQ GASOLINA', 'cargo' => '1600', 'branch_kind' => 'operativa', 'branch_name' => 'ATLACOMULCO'], ['status' => R::FOLIO_SIN_ERP, 'message' => 'x']);

    expect($c['treatment'])->toBe(T::PENDIENTE)->and($c['suggested'])->toBe(T::OPEX_SUCURSAL);
});

it('posible CAPEX y pagos genéricos quedan en revisión, nunca como OPEX', function () {
    expect(erClassify(['concept' => 'PAGO 5 BYD FIRMA CAR', 'cargo' => '33733.93'])['suggested'])->toBe(T::CAPEX)
        ->and(erClassify(['concept' => 'PAGO', 'cargo' => '24300'])['treatment'])->toBe(T::PENDIENTE)
        ->and(erClassify(['concept' => 'PAGO', 'cargo' => '24300'])['suggested'])->toBeNull();
});

it('retenciones y pólizas por cuenta de terceros quedan fuera del resultado', function () {
    expect(erClassify(['concept' => 'SAT RETENCIONES ISR JULIO 2026', 'cargo' => '13605'])['treatment'])->toBe(T::BALANCE)
        ->and(erClassify(['concept' => 'POLIZAS CRECE', 'cargo' => '320', 'branch_kind' => 'operativa'])['treatment'])->toBe(T::PUENTE_TERCEROS);
});

it('movimiento fuera del periodo no se clasifica en este periodo', function () {
    expect(erClassify(['in_period' => false, 'concept' => 'REQ RENTA', 'cargo' => '1000'])['treatment'])->toBe(T::FUERA_PERIODO);
});

it('ERP de Corporativo: requisición normal es OPEX corporativo; mobiliario, pólizas y DG van a revisión', function () {
    $base = ['universe' => 'corporativo', 'observations' => null, 'sucursal_raw' => 'Corporativo'];
    $cls = new ErpOutsideClassifier;

    expect($cls->classify($base + ['category' => 'Luz', 'concept' => 'LUZ OFICINA'])['treatment'])->toBe(T::OPEX_CORPORATIVO)
        ->and($cls->classify($base + ['category' => 'Nómina y Capital Humano', 'concept' => 'FINIQUITO'])['treatment'])->toBe(T::NOMINA_CORPORATIVA)
        ->and($cls->classify($base + ['category' => 'Mobiliario y Equipo', 'concept' => 'EQUIPO DE COMPUTO'])['suggested'])->toBe(T::CAPEX)
        ->and($cls->classify($base + ['category' => 'Pólizas', 'concept' => 'SEGURO'])['treatment'])->toBe(T::PENDIENTE)
        ->and($cls->classify($base + ['category' => 'Viáticos', 'concept' => 'VIATICOS DG'])['treatment'])->toBe(T::PENDIENTE)
        ->and($cls->classify(['universe' => 'sin_sucursal', 'category' => 'Luz', 'concept' => 'X', 'observations' => null, 'sucursal_raw' => null])['treatment'])->toBe(T::PENDIENTE);
});
