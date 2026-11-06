<?php

use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\EstadoResultados\EstadoResultadosReconciliationLink;
use App\Models\EstadoResultados\EstadoResultadosRun;
use App\Models\Period;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Classification\ClassificationService;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use App\Services\EstadoResultados\Support\ProcessingLockedException;
use App\Services\EstadoResultados\Support\Treatment as T;
use Illuminate\Support\Facades\Cache;
use Tests\Support\EstadoResultadosFixtures as F;

/**
 * Criterios de aceptación del endurecimiento: una sola fuente reconoce cada gasto,
 * conciliaciones 1:1/1:N/N:1/N:M/parcial con evidencia, reversos/reembolsos,
 * DG sin doble descuento, idempotencia, aislamiento entre periodos y candado.
 */
function raImport($period, string $path, string $slot = 'administrativa')
{
    return app(EstadoResultadosUploadService::class)->importPath($period, $path, basename($path), $slot, null);
}

function raProcess($period): EstadoResultadosRun
{
    return app(EstadoResultadosProcessor::class)->process($period);
}

function raBuild($period, string $scope = 'CONSOLIDADO'): array
{
    return app(EstadoResultadosDatasetBuilder::class)->build($period, $scope);
}

function raMov(array $d, string $concept, string $source = 'banco'): array
{
    return collect($d['movements'])->first(fn ($m) => $m['concept'] === $concept && $m['source'] === $source) ?? throw new RuntimeException("No hay movimiento {$concept}");
}

function raFailedChecks(array $d): array
{
    return collect($d['checks'])->where('ok', false)->map(fn ($c) => $c['key'].': '.$c['difference'])->values()->all();
}

function raClassify($period, array $m, string $treatment): void
{
    app(ClassificationService::class)->classify($period, [['fingerprint' => $m['fingerprint'], 'source' => $m['source'], 'current' => $m['treatment']]], $treatment, 'Prueba de aceptación', null);
}

beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
    F::fakeRadiography();
});

it('nómina NOI pagada por banco = un solo gasto reconocido (el NOI); el banco es evidencia', function () {
    $period = F::period();
    F::noiEmployee($period, 'JUAN PEREZ LOPEZ', 'CORDOBA', '9000.00');
    raImport($period, F::adminXlsx([['2026-09-15', 9000.00, null, 'S/R', 'NOM QUINCENA 17', 'CORPORATIVO', 'Pérez López Juan']]));
    raProcess($period);
    $d = raBuild($period);
    $m = raMov($d, 'NOM QUINCENA 17');

    expect($m['treatment'])->toBe(T::CUBIERTO_RADIOGRAFIA)
        ->and($m['rule_code'])->toBe('nomina_noi_beneficiario')
        ->and($m['dimensions']['source']['key'])->toBe('noi')
        ->and($m['dimensions']['inclusion']['key'])->toBe('excluido_justificado')
        ->and($m['impact']['included'])->toBeFalse()
        ->and(collect($m['links'])->pluck('kind')->all())->toBe(['evidencia_noi'])
        ->and($d['controls']['nomina_corporativa'])->toBe('0.00')
        ->and($d['controls']['nomina_sucursales'])->toBe('30000.00')      // sólo Radiografía (NOI)
        ->and($d['validation']['noi']['linked_payments'])->toBe(['count' => 1, 'amount' => '9000.00'])
        ->and(raFailedChecks($d))->toBe([]);
});

it('si el NOI trae colaboradores sin sucursal operativa (p. ej. Corporativo), la nómina corporativa bancaria no se suma sola', function () {
    $period = F::period();
    F::noiEmployee($period, 'MARIA CORPORATIVA DIAZ', 'CORPORATIVO', '15000.00');
    raImport($period, F::adminXlsx([['2026-09-15', 15000.00, null, 'S/R', 'NOM QUINCENA 17', 'CORPORATIVO', 'EMPLEADO SIN NOMBRE']]));
    raProcess($period);
    $d = raBuild($period);
    $m = raMov($d, 'NOM QUINCENA 17');

    expect($m['treatment'])->toBe(T::PENDIENTE)
        ->and($m['rule_code'])->toBe('nomina_corporativa_noi_sin_sucursal')
        ->and($m['suggested_treatment'])->toBe(T::NOMINA_CORPORATIVA)
        ->and($d['controls']['nomina_corporativa'])->toBe('0.00')
        ->and($d['validation']['noi']['unassigned_total'])->toBe('15000.00');
});

it('ERP + banco = un solo gasto (desde el ERP), con vínculo y dimensiones independientes', function () {
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-ABC12', '5000.00', '2026-09-10', 'Servicios', 'SERVICIO INTERNET');
    raImport($period, F::adminXlsx([['2026-09-10', 5000.00, null, 'REQ-ABC12', 'REQ SERVICIOS', 'CORPORATIVO', 'PROVEEDOR SA']]));
    raProcess($period);
    $d = raBuild($period);
    $bank = raMov($d, 'REQ SERVICIOS');
    $erp = raMov($d, 'SERVICIO INTERNET', 'erp');

    expect($d['controls']['opex_corporativo'])->toBe('5000.00')
        ->and($bank['dimensions']['reconciliation']['key'])->toBe('conciliado')
        ->and($bank['dimensions']['inclusion']['key'])->toBe('excluido_justificado')
        ->and($bank['dimensions']['source']['key'])->toBe('erp')
        ->and($bank['dimensions']['import']['key'])->toBe('importado')
        ->and($bank['amounts'])->toMatchArray(['gross' => '5000.00', 'conciliated' => '5000.00', 'recognized' => '0.00', 'pending' => '0.00'])
        ->and($bank['links'][0])->toMatchArray(['kind' => 'conciliacion', 'target_type' => 'erp', 'target' => $erp['fingerprint'], 'match_type' => '1:1', 'applied' => '5000.00'])
        ->and($bank['dates'])->toMatchArray(['bank' => '2026-09-10', 'erp_payment' => '2026-09-10', 'erp_capture' => '2026-09-10', 'accrual' => null])
        ->and($erp['dimensions']['inclusion']['key'])->toBe('incluido')
        ->and($erp['dimensions']['category']['key'])->toBe('opex_corporativo')
        ->and($erp['erp_payment'])->toBe(['amount' => '5000.00', 'applied' => '5000.00', 'pending' => '0.00'])
        ->and($erp['linked_bank'][0]['fingerprint'])->toBe($bank['fingerprint'])
        ->and(EstadoResultadosReconciliationLink::query()->where('period_id', $period->id)->where('link_kind', 'conciliacion')->count())->toBe(1)
        ->and(raFailedChecks($d))->toBe([]);
});

it('ERP + NOI + banco: nunca triple conteo — la requisición que nombra a un colaborador NOI queda en revisión', function () {
    $period = F::period();
    F::noiEmployee($period, 'JUAN PEREZ LOPEZ', 'CORDOBA', '9000.00');
    F::erpWith($period, 'CORPORATIVO', 'REQ-F1', '3000.00', '2026-09-10', [
        'category' => 'Nómina y Capital Humano', 'concept' => 'FINIQUITO', 'observations' => 'Finiquito de Juan Pérez López (RH)',
    ]);
    raImport($period, F::adminXlsx([['2026-09-10', 3000.00, null, 'REQ-F1', 'PAGO FINIQUITO', 'CORPORATIVO', 'JUAN PEREZ LOPEZ']]));
    raProcess($period);
    $d = raBuild($period);
    $bank = raMov($d, 'PAGO FINIQUITO');
    $erp = raMov($d, 'FINIQUITO', 'erp');

    expect($bank['treatment'])->toBe(T::CONCILIADO_ERP)                 // banco = evidencia del ERP
        ->and($erp['treatment'])->toBe(T::PENDIENTE)                     // ERP ↔ NOI: revisión explícita
        ->and($erp['rule_code'])->toBe('erp_nomina_colaborador_noi')
        ->and(collect($erp['links'])->pluck('kind')->sort()->values()->all())->toBe(['conciliacion', 'evidencia_noi'])
        ->and($d['controls']['nomina_corporativa'])->toBe('0.00')
        ->and($d['controls']['opex_corporativo'])->toBe('0.00')
        ->and($d['validation']['totals']['recognized'])->toBe(['count' => 0, 'amount' => '0.00'])
        ->and($d['validation']['totals']['pending']['amount'])->toBe('3000.00')
        ->and($d['validation']['totals']['conciliated']['amount'])->toBe('3000.00');

    // Al confirmarlo como nómina corporativa se reconoce UNA vez (desde el ERP), no tres.
    raClassify($period, $erp, T::NOMINA_CORPORATIVA);
    expect(raBuild($period)['controls']['nomina_corporativa'])->toBe('3000.00');
});

it('pago parcial con excedente: sólo lo conciliado se neutraliza; el remanente queda pendiente y, al clasificarse, suma sólo el remanente', function () {
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-P1', '5000.00', '2026-09-10', 'Renta Oficina', 'RENTA');
    raImport($period, F::adminXlsx([['2026-09-10', 5500.00, null, 'REQ-P1', 'REQ RENTA', 'CORPORATIVO']]));
    raProcess($period);
    $d = raBuild($period);
    $bank = raMov($d, 'REQ RENTA');

    expect($bank['reconciliation_status'])->toBe('conciliado_erp_parcial')
        ->and($bank['treatment'])->toBe(T::PENDIENTE)
        ->and($bank['dimensions']['reconciliation']['key'])->toBe('conciliado_parcial')
        ->and($bank['amounts'])->toMatchArray(['conciliated' => '5000.00', 'pending' => '500.00', 'recognized' => '0.00'])
        ->and($d['controls']['opex_corporativo'])->toBe('5000.00')
        ->and($d['conciliation']['parciales'])->toBe(['count' => 1, 'excedente_pendiente' => '500.00']);

    raClassify($period, $bank, T::OPEX_CORPORATIVO);
    $d = raBuild($period);
    $bank = raMov($d, 'REQ RENTA');

    expect($d['controls']['opex_corporativo'])->toBe('5500.00')            // 5,000 ERP + 500 remanente (no 10,500)
        ->and($bank['impact']['amount'])->toBe('500.00')
        ->and($bank['dimensions']['inclusion']['key'])->toBe('incluido_parcial')
        ->and(raFailedChecks($d))->toBe([]);
});

it('pago parcial declarado en ERP: el gasto se reconoce una vez y el saldo ERP sin pago queda visible', function () {
    $period = F::period();
    F::erpWith($period, 'CORPORATIVO', 'REQ-P2', '5000.00', '2026-09-10', ['concept' => 'MANTENIMIENTO', 'estatus' => 'PAGO_PARCIAL']);
    raImport($period, F::adminXlsx([['2026-09-10', 2000.00, null, 'REQ-P2', 'REQ MANTENIMIENTO', 'CORPORATIVO']]));
    raProcess($period);
    $d = raBuild($period);
    $erp = raMov($d, 'MANTENIMIENTO', 'erp');

    expect(raMov($d, 'REQ MANTENIMIENTO')['treatment'])->toBe(T::CONCILIADO_ERP)
        ->and($erp['erp_payment'])->toBe(['amount' => '5000.00', 'applied' => '2000.00', 'pending' => '3000.00'])
        ->and($erp['dimensions']['reconciliation']['key'])->toBe('conciliado_parcial')
        ->and($d['controls']['opex_corporativo'])->toBe('5000.00')
        ->and($d['conciliation']['erp_saldo_pendiente_pago'])->toBe('3000.00')
        ->and(collect($d['validation']['unresolved'])->pluck('key')->all())->toContain('erp_saldo_pendiente');
});

it('reembolso vinculado reduce la misma categoría; sin vínculo queda pendiente y nunca es ingreso; un reverso neto $0 queda fuera', function () {
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-R1', '5000.00', '2026-09-05', 'Viáticos', 'VIATICOS VENTAS');
    raImport($period, F::adminXlsx([
        ['2026-09-12', null, 500.00, 'REQ-R1', 'DEVOLUCION REQ', 'CORPORATIVO'],
        ['2026-09-13', null, 80.00, null, 'DEVOLUCION VIATICO', 'CORPORATIVO'],
        ['2026-09-14', 1200.00, null, 'S/R', 'PAGO PROVEEDOR', 'CORPORATIVO'],
        ['2026-09-15', null, 1200.00, null, 'REVERSO SPEI', 'CORPORATIVO'],
    ]));
    raProcess($period);
    $d = raBuild($period);

    $refund = raMov($d, 'DEVOLUCION REQ');
    expect($refund['treatment'])->toBe(T::OPEX_CORPORATIVO)
        ->and($refund['treatment_source'])->toBe('reembolso')
        ->and($refund['impact']['amount'])->toBe('-500.00')
        ->and($refund['links'][0])->toMatchArray(['kind' => 'reembolso', 'target_type' => 'erp'])
        ->and($d['controls']['opex_corporativo'])->toBe('4500.00')
        ->and(raMov($d, 'DEVOLUCION VIATICO')['treatment'])->toBe(T::PENDIENTE)
        ->and(raMov($d, 'PAGO PROVEEDOR')['treatment'])->toBe(T::REVERSO)
        ->and(raMov($d, 'REVERSO SPEI')['treatment'])->toBe(T::REVERSO)
        ->and(raMov($d, 'PAGO PROVEEDOR')['reversal_pair'])->toBe(raMov($d, 'REVERSO SPEI')['reversal_pair'])
        ->and($d['validation']['eliminations']['reversals'])->toBe(['pairs' => 1, 'count' => 2, 'amount' => '2400.00'])
        ->and($d['controls']['ingresos'])->toBe('100000.00')
        ->and($d['controls']['ingresos_financieros'])->toBe('0.00')
        ->and($d['controls']['ebitda_final'])->toBe('45500.00')
        ->and(raFailedChecks($d))->toBe([]);
});

it('gasto DG registrado en ERP de Corporativo se reclasifica (no se descuenta dos veces)', function () {
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-D1', '6000.00', '2026-09-06', 'Viáticos', 'VIATICOS DG');
    raImport($period, F::adminXlsx([['2026-09-06', 6000.00, null, 'REQ-D1', 'REQ VIATICOS DG', 'CORPORATIVO']]));
    raProcess($period);
    $d = raBuild($period);
    expect(raMov($d, 'VIATICOS DG', 'erp')['treatment'])->toBe(T::PENDIENTE)
        ->and(raMov($d, 'REQ VIATICOS DG')['treatment'])->toBe(T::CONCILIADO_ERP);

    raClassify($period, raMov($d, 'VIATICOS DG', 'erp'), T::DG_OPERATIVO);
    // Un usuario además reclasifica el pago bancario como DG: no se suma otra vez y queda señalado.
    raClassify($period, raMov($d, 'REQ VIATICOS DG'), T::DG_OPERATIVO);
    $d = raBuild($period);

    expect($d['controls']['dg'])->toBe('6000.00')
        ->and($d['controls']['opex_corporativo'])->toBe('0.00')
        ->and($d['controls']['ebitda_final'])->toBe('44000.00')
        ->and(raMov($d, 'VIATICOS DG', 'erp')['dimensions']['category']['key'])->toBe('opex_dg')
        ->and(raMov($d, 'REQ VIATICOS DG')['impact']['included'])->toBeFalse()
        ->and(raFailedChecks($d))->toBe(['conciliados_reclasificados: 6000.00']);
});

it('conciliaciones N:1 y N:M desde los archivos reales: el ERP se reconoce una sola vez', function () {
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-M1', '3000.00', '2026-09-10', 'Luz', 'LUZ A');
    F::erp($period, 'CORPORATIVO', 'REQ-M1', '2000.00', '2026-09-10', 'Luz', 'LUZ B');
    F::erp($period, 'CORPORATIVO', 'REQ-M2', '3000.00', '2026-09-12', 'Agua', 'AGUA A');
    F::erp($period, 'CORPORATIVO', 'REQ-M2', '4000.00', '2026-09-13', 'Agua', 'AGUA B');
    raImport($period, F::adminXlsx([
        ['2026-09-10', 5000.00, null, 'REQ-M1', 'PAGO LUZ', 'CORPORATIVO'],
        ['2026-09-12', 2500.00, null, 'REQ-M2', 'PAGO AGUA 1', 'CORPORATIVO'],
        ['2026-09-13', 4500.00, null, 'REQ-M2', 'PAGO AGUA 2', 'CORPORATIVO'],
    ]));
    raProcess($period);
    $d = raBuild($period);

    expect(raMov($d, 'PAGO LUZ')['match_type'])->toBe('N:1')
        ->and(raMov($d, 'PAGO AGUA 1')['match_type'])->toBe('N:M')
        ->and(raMov($d, 'PAGO AGUA 2')['match_type'])->toBe('N:M')
        ->and(collect(raMov($d, 'PAGO AGUA 2')['links'])->pluck('applied')->all())->toEqualCanonicalizing(['500.00', '4000.00'])
        ->and($d['controls']['opex_corporativo'])->toBe('12000.00')
        ->and($d['conciliation']['incorporado_bancos'])->toBe('0.00')
        ->and(collect($d['conciliation']['por_tipo_emparejamiento'])->pluck('count', 'key')->all())->toBe(['N:1' => 1, 'N:M' => 2])
        ->and(EstadoResultadosReconciliationLink::query()->where('period_id', $period->id)->where('link_kind', 'conciliacion')->count())->toBe(5) // N:1 = 2 vínculos, N:M = 3
        ->and(raFailedChecks($d))->toBe([]);
});

it('coincidencia ambigua queda pendiente: ni se incluye ni se excluye en automático', function () {
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-Z', '100.00', '2026-09-10', 'Papelería', 'PAPEL A');
    F::erp($period, 'CORPORATIVO', 'REQ-Z', '200.00', '2026-09-10', 'Papelería', 'PAPEL B');
    F::erp($period, 'CORPORATIVO', 'REQ-Z', '100.00', '2026-09-11', 'Papelería', 'PAPEL C');
    F::erp($period, 'CORPORATIVO', 'REQ-Z', '200.00', '2026-09-11', 'Papelería', 'PAPEL D');
    raImport($period, F::adminXlsx([['2026-09-10', 300.00, null, 'REQ-Z', 'PAGO PAPEL', 'CORPORATIVO']]));
    raProcess($period);
    $d = raBuild($period);
    $m = raMov($d, 'PAGO PAPEL');

    expect($m['reconciliation_status'])->toBe('folio_ambiguo')
        ->and($m['treatment'])->toBe(T::PENDIENTE)
        ->and($m['dimensions']['reconciliation']['key'])->toBe('ambiguo')
        ->and($m['dimensions']['inclusion']['key'])->toBe('pendiente')
        ->and($m['amounts'])->toMatchArray(['pending' => '300.00', 'conciliated' => '0.00', 'excluded' => '0.00', 'recognized' => '0.00'])
        ->and($m['links'])->toBe([])
        ->and($d['validation']['differences']['by_folio']['count'])->toBe(1);
});

it('reprocesar y reemplazar el archivo no duplica movimientos, vínculos ni gastos (mismo hash)', function () {
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-N1', '5000.00', '2026-09-10', 'Renta Oficina', 'RENTA', '2026-09-05');
    $rows = [['2026-09-08', 2000.00, null, 'REQ-N1', 'REQ RENTA', 'CORPORATIVO'], ['2026-09-10', 3000.00, null, 'REQ-N1', 'REQ RENTA', 'CORPORATIVO']];
    raImport($period, F::adminXlsx($rows));
    raProcess($period);
    $h1 = raBuild($period)['meta']['dataset_hash'];
    raProcess($period);
    raProcess($period);
    $links = EstadoResultadosReconciliationLink::query()->where('period_id', $period->id)->count();

    // Versión corregida del mismo archivo (mismos movimientos): reemplaza sin duplicar.
    raImport($period, F::adminXlsx($rows, false));
    raProcess($period);
    $d = raBuild($period);

    expect($links)->toBe(2)
        ->and(EstadoResultadosReconciliationLink::query()->where('period_id', $period->id)->count())->toBe(2)
        ->and(EstadoResultadosMovement::query()->where('period_id', $period->id)->count())->toBe(2)
        ->and($d['controls']['opex_corporativo'])->toBe('5000.00')
        ->and(EstadoResultadosRun::query()->where('period_id', $period->id)->latest('id')->first()->input_signature)
        ->toBe(EstadoResultadosRun::query()->where('period_id', $period->id)->orderBy('id')->skip(1)->first()->input_signature);
    // El hash cambia sólo por el estado del archivo de control (sin total de control); cifras idénticas.
    expect($d['results'])->toBe(raBuild($period)['results']);
    raProcess($period);
    expect(raBuild($period)['meta']['dataset_hash'])->toBe($d['meta']['dataset_hash'])
        ->and($h1)->toBeString();
});

it('cambio de periodo no contamina: un folio ERP de un periodo anterior es ajuste explícito y el periodo previo no cambia', function () {
    $sep = F::period('2026-09-01', '2026-09-30');
    $oct = Period::query()->create(['name' => 'ER prueba octubre', 'code' => 'M-ER-OCT', 'type' => 'monthly', 'year' => 2026, 'month' => 10, 'sequence' => 1, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'is_closed' => false]);
    F::erp($sep, 'CORPORATIVO', 'REQ-OLD1', '700.00', '2026-09-28', 'Luz', 'LUZ SEPTIEMBRE');
    raImport($sep, F::adminXlsx([['2026-09-28', 700.00, null, 'REQ-OLD1', 'PAGO LUZ SEP', 'CORPORATIVO'], ['2026-10-02', 150.00, null, 'S/R', 'PAGO OCTUBRE EN ARCHIVO SEP', 'CORPORATIVO']]));
    raProcess($sep);
    $sepBefore = raBuild($sep);

    raImport($oct, F::adminXlsx([['2026-10-03', 700.00, null, 'REQ-OLD1', 'PAGO LUZ ATRASADO', 'CORPORATIVO']]));
    raProcess($oct);
    $o = raBuild($oct);
    $late = raMov($o, 'PAGO LUZ ATRASADO');
    $sepAfter = raBuild($sep);
    $outOfPeriod = raMov($sepAfter, 'PAGO OCTUBRE EN ARCHIVO SEP');

    expect($late['reconciliation_status'])->toBe('folio_otro_periodo')
        ->and($late['treatment'])->toBe(T::PENDIENTE)
        ->and($late['dimensions']['recognition']['out_of_period'])->toBe('ajuste_periodo_anterior')
        ->and($late['rule_reason'])->toContain('Ajuste de periodo anterior')
        ->and($o['controls']['opex_corporativo'])->toBe('0.00')
        ->and($sepAfter['meta']['dataset_hash'])->toBe($sepBefore['meta']['dataset_hash'])
        ->and($sepAfter['controls']['opex_corporativo'])->toBe('700.00')
        ->and($outOfPeriod['dimensions']['inclusion']['key'])->toBe('fuera_periodo')
        ->and($outOfPeriod['dimensions']['recognition']['out_of_period'])->toBe('fecha_posterior_al_periodo')
        ->and($outOfPeriod['dimensions']['recognition']['period_id'])->toBe($oct->id)
        ->and($outOfPeriod['amounts']['out_of_period'])->toBe('150.00');
});

it('dos procesamientos simultáneos del mismo periodo: el segundo se rechaza por candado y no duplica nada', function () {
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-ABC12', '5000.00', '2026-09-10');
    raImport($period, F::adminXlsx([['2026-09-10', 5000.00, null, 'REQ-ABC12', 'REQ SERVICIOS', 'CORPORATIVO']]));

    $held = Cache::lock(EstadoResultadosProcessor::lockKey($period->id), 60);
    expect($held->get())->toBeTrue();
    expect(fn () => raProcess($period))->toThrow(ProcessingLockedException::class);
    expect(EstadoResultadosRun::query()->where('period_id', $period->id)->count())->toBe(0)
        ->and(EstadoResultadosMovement::query()->where('period_id', $period->id)->whereNotNull('processed_at')->count())->toBe(0);
    $held->release();

    $first = raProcess($period);
    $hash = raBuild($period)['meta']['dataset_hash'];
    $second = raProcess($period);

    expect($first->status)->toBe('ok')->and($second->status)->toBe('ok')
        ->and(EstadoResultadosReconciliationLink::query()->where('period_id', $period->id)->count())->toBe(1)
        ->and(raBuild($period)['meta']['dataset_hash'])->toBe($hash)
        ->and($first->recognition_version)->toBe(config('estado_resultados.reconocimiento.version'))
        ->and($first->recognition_criterion['criterio'])->toBe('flujo_bancario_con_devengo_erp')
        // El candado se libera aunque el procesamiento termine: otro proceso puede tomarlo.
        ->and(Cache::lock(EstadoResultadosProcessor::lockKey($period->id), 60)->get())->toBeTrue();
});

it('sección de validación: partición completa, totales por destino y diferencias visibles (nunca compensadas)', function () {
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-1', '1234.56', '2026-09-05', 'Luz', 'LUZ');
    F::erp($period, 'CORPORATIVO', 'REQ-2', '999.00', '2026-09-05', 'Agua', 'AGUA');
    raImport($period, F::adminXlsx([
        ['2026-09-05', 1234.56, null, 'REQ-1', 'REQ LUZ', 'CORPORATIVO'],
        ['2026-09-05', 900.00, null, 'REQ-2', 'REQ AGUA', 'CORPORATIVO'],         // monto diferente
        ['2026-09-06', 333.33, null, 'S/R', 'NOM QUINCENA 17', 'CORPORATIVO'],
        ['2026-09-07', 10.00, null, 'S/R', 'TRASPASO ENTRE CUENTAS', null],         // sin contraparte
    ]));
    raProcess($period);
    $d = raBuild($period);
    $v = $d['validation'];

    expect(array_keys($v))->toBe(['status', 'scope_note', 'criterion', 'totals', 'partition', 'differences', 'radiography', 'integrity', 'eliminations', 'noi', 'unresolved', 'failed_checks'])
        ->and($v['partition']['all']['ok'])->toBeTrue()
        ->and($v['partition']['bank']['total'])->toBe('2477.89')
        ->and($v['totals']['source']['total'])->toBe($v['totals']['imported']['total'])
        ->and($v['totals']['conciliated']['amount'])->toBe('1234.56')
        ->and($v['totals']['recognized']['amount'])->toBe('2566.89')             // ERP 1234.56 + 999.00 + nómina 333.33
        ->and($v['totals']['pending']['amount'])->toBe('900.00')
        ->and($v['totals']['excluded_justified']['amount'])->toBe('10.00')
        ->and($v['differences']['by_amount']['items'][0])->toMatchArray(['bank_amount' => '900.00', 'erp_amounts' => ['999.00'], 'difference' => '-99.00'])
        ->and($v['eliminations']['unpaired_transfers']['count'])->toBe(1)
        ->and($v['integrity']['status'])->toBe('ok')
        ->and($v['radiography']['difference'])->toBe('0.00')
        ->and($v['status'])->toBe('con_pendientes')
        ->and(collect($v['unresolved'])->pluck('key')->all())->toContain('pendientes', 'diferencia_importe', 'traspasos_sin_contraparte')
        ->and($d['meta']['recognition']['version'])->toBe(config('estado_resultados.reconocimiento.version'))
        ->and(collect($d['dimensions']['inclusion'])->pluck('key')->all())->toBe(['incluido', 'incluido_parcial', 'excluido_justificado', 'pendiente', 'fuera_periodo'])
        ->and(raFailedChecks($d))->toBe([]);
});
