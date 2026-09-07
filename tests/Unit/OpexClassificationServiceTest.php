<?php

use App\Services\OpexClassificationService;

/**
 * Auditoría 07-sep-2026 — OpexClassificationService es la fuente ÚNICA de
 * clasificación financiera (antes duplicada entre BranchRadiographyCalculator::
 * accumulateGastos() y ExpenseObservationAttributionService::
 * isEligibleForAttribution()). Estas pruebas fijan el contrato: para cada
 * combinación categoría/concepto conocida, is_opex/type/eligible_for_attribution
 * deben coincidir EXACTAMENTE con la regla que ya vivía en accumulateGastos()
 * (confirmado en producción — ver verificación real contra periodo 21 en el
 * plan de esta sesión: los totales de gastos_operativos por sucursal son
 * IDÉNTICOS antes/después de la extracción).
 */
it('classifies NOMINA/PAGO DE IMSS/DEDUCCIONES/PAGO PRESTAMO Z/ANTICIPO DE NOMINA as covered-by-NOI: never OPEX, never eligible', function () {
    $service = new OpexClassificationService();
    foreach (['NOMINA', 'PAGO DE IMSS', 'DEDUCCIONES', 'DEDUCCIONES GENERALES', 'PAGO PRESTAMO Z', 'ANTICIPO DE NOMINA'] as $concept) {
        $r = $service->classify('Nómina y Capital Humano', $concept, OpexClassificationService::SOURCE_LENDUS);
        expect($r['is_opex'])->toBeFalse("Concepto {$concept} no debe ser OPEX");
        expect($r['type'])->toBe(OpexClassificationService::TYPE_NOMINA_COVERED_BY_NOI);
        expect($r['eligible_for_attribution'])->toBeFalse("Concepto {$concept} no debe ser elegible para atribución");
    }
});

it('classifies PAGO FINIQUITO and GASTOS MEDICOS as nomina_empleado: not OPEX, but eligible for person attribution', function () {
    $service = new OpexClassificationService();
    foreach (['PAGO FINIQUITO', 'GASTOS MEDICOS', 'GASTOS MÉDICOS'] as $concept) {
        $r = $service->classify('Nómina y Capital Humano', $concept, OpexClassificationService::SOURCE_LENDUS);
        expect($r['is_opex'])->toBeFalse();
        expect($r['type'])->toBe(OpexClassificationService::TYPE_NOMINA_EMPLEADO);
        expect($r['eligible_for_attribution'])->toBeTrue();
    }
});

// Caso real MARLEN RAZO SALDAÑA (auditoría 07-sep-2026, cierre) — el concepto
// COMPLETO "PAGO FINANCIAMIENTO MOTO"/"COMPRA DE CASCOS" bajo la categoría
// "Nómina y Capital Humano" (como llega vía gastos_lendus_excel) debe recibir
// el MISMO trato que la etiqueta genérica truncada "PAGO"/"COMPRA DE" bajo
// "Gastos Operativos" (ver test de abajo): gasto real de empleado, atribuible,
// NUNCA OPEX genérico — nunca is_opex, siempre eligible_for_attribution. Antes
// de este fix caía en TYPE_NOMINA_COVERED_BY_NOI (eligible=false), lo que
// hacía desaparecer el dinero del costo total del colaborador.
it('classifies PAGO FINANCIAMIENTO MOTO and COMPRA DE CASCOS (full label) as nomina_empleado: not OPEX, but eligible for person attribution', function () {
    $service = new OpexClassificationService();
    foreach (['PAGO FINANCIAMIENTO MOTO', 'COMPRA DE CASCOS'] as $concept) {
        $r = $service->classify('Nómina y Capital Humano', $concept, OpexClassificationService::SOURCE_LENDUS);
        expect($r['is_opex'])->toBeFalse();
        expect($r['type'])->toBe(OpexClassificationService::TYPE_NOMINA_EMPLEADO);
        expect($r['eligible_for_attribution'])->toBeTrue();
    }
});

it('classifies Excedentes, Fondeo/Intersucursal and Pólizas as excluded, never eligible', function () {
    $service = new OpexClassificationService();

    $exc = $service->classify('Envío de utilidad a corporativo', 'CUALQUIERA', OpexClassificationService::SOURCE_LENDUS);
    expect($exc['is_opex'])->toBeFalse();
    expect($exc['type'])->toBe(OpexClassificationService::TYPE_EXCEDENTE);
    expect($exc['eligible_for_attribution'])->toBeFalse();

    $fondeo = $service->classify('Préstamos Intersucursales', 'CUALQUIERA', OpexClassificationService::SOURCE_LENDUS);
    expect($fondeo['is_opex'])->toBeFalse();
    expect($fondeo['type'])->toBe(OpexClassificationService::TYPE_FONDEO);

    $polizas = $service->classify('Pólizas', 'SEGURO AUTOMOVIL', OpexClassificationService::SOURCE_LENDUS);
    expect($polizas['is_opex'])->toBeFalse();
    expect($polizas['type'])->toBe(OpexClassificationService::TYPE_POLIZAS);
});

it('classifies a real OPEX concept (RECARGAS TELEFONICAS) as opex and eligible', function () {
    $service = new OpexClassificationService();
    $r = $service->classify('Recargas Telefónicas', 'RECARGAS TELEFONICAS', OpexClassificationService::SOURCE_LENDUS);
    expect($r['is_opex'])->toBeTrue();
    expect($r['type'])->toBe(OpexClassificationService::TYPE_OPEX);
    expect($r['eligible_for_attribution'])->toBeTrue();
});

it('classifies the generic PDF labels (Gastos Operativos + PAGO/COMPRA DE/ENGANCHE DE) as nomina_empleado, and ANTICIPO DE as not eligible', function () {
    $service = new OpexClassificationService();
    foreach (['PAGO', 'COMPRA DE', 'ENGANCHE DE'] as $concept) {
        $r = $service->classify('Gastos Operativos', $concept, OpexClassificationService::SOURCE_LENDUS);
        expect($r['is_opex'])->toBeFalse();
        expect($r['type'])->toBe(OpexClassificationService::TYPE_NOMINA_EMPLEADO);
        expect($r['eligible_for_attribution'])->toBeTrue();
    }
    $anticipo = $service->classify('Gastos Operativos', 'ANTICIPO DE', OpexClassificationService::SOURCE_LENDUS);
    expect($anticipo['eligible_for_attribution'])->toBeFalse();
});

it('ERP source only excludes Pólizas — everything else counts fully as OPEX', function () {
    $service = new OpexClassificationService();

    $polizas = $service->classify('Pólizas', 'SEGURO AUTOMOVIL', OpexClassificationService::SOURCE_ERP);
    expect($polizas['is_opex'])->toBeFalse();

    // Categorías que SÍ serían excluidas del lado Lendus (Nómina, Excedentes) deben
    // seguir contando COMPLETO como OPEX del lado ERP — la regla vigente (2026-07)
    // es "ERP se suma íntegro a OPEX, sin reclasificar", solo excluyendo Pólizas.
    foreach (['Nómina y Capital Humano', 'Envío de utilidad a corporativo', 'Gasolina'] as $cat) {
        $r = $service->classify($cat, 'CUALQUIERA', OpexClassificationService::SOURCE_ERP);
        expect($r['is_opex'])->toBeTrue("Categoría ERP {$cat} debe seguir siendo OPEX completo");
    }
});
