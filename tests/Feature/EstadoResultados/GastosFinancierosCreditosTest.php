<?php

use App\Models\EstadoResultados\EstadoResultadosCredit;
use App\Models\Period;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Classification\MovementClassifier;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Export\ErFinancingSection;
use App\Services\EstadoResultados\Export\EstadoResultadosPdfExporter;
use App\Services\EstadoResultados\FinancialExpenses\AmortizationUploadService;
use App\Services\EstadoResultados\FinancialExpenses\FinancialExpenseService;
use App\Services\EstadoResultados\Import\EstadoResultadosImportException;
use App\Services\EstadoResultados\Support\Treatment as T;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * Créditos con comisión (LEROM), aportaciones de sucursales, pagos al acreedor y prorrateo
 * gerencial (24-nov-2026). Datos 100 % SINTÉTICOS.
 */
beforeEach(function () {
    // Estas pruebas cubren también el modo «comisión en EBT» (por omisión: solo intereses).
    config(['estado_resultados.gastos_financieros.comisiones_en_resultado_financiero' => true]);
    F::branch('CORDOBA');
    F::branch('ATLIXCO');
    F::branch('CORPORATIVO');
});

/** CSV con el formato del simulador LEROM: capital + COMISIÓN + IVA de comisión (sin interés). */
function gfcLeromCsv(): string
{
    $dir = storage_path('framework/testing/er');
    is_dir($dir) || mkdir($dir, 0775, true);
    $path = $dir.'/'.uniqid('lerom_', true).'.csv';
    $lines = ['Pago,Fecha,Amortización Capital,Importe Comisión,IVA Comisión,Pago Total,Saldo Insoluto'];
    foreach (['31/08/2026', '01/09/2026', '02/09/2026', '03/09/2026', '04/09/2026', '01/10/2026'] as $i => $date) {
        $lines[] = ($i + 1).','.$date.',1000.00,100.00,16.00,1116.00,'.number_format(6696 - 1116 * ($i + 1), 2, '.', '');
    }
    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
}

function gfcImport(string $key, string $path, string $name = 'tabla.csv', ?string $kind = null): array
{
    return app(AmortizationUploadService::class)->importPath(EstadoResultadosCredit::query()->where('key', $key)->firstOrFail(), $path, $name, null, null, null, $kind);
}

/** Periodo de 4 semanas (ingresos 1,000,000 · OPEX 600,000); ajustes en $0 salvo los de LEROM. */
function gfcPeriod(string $start = '2026-09-01', string $end = '2026-09-28', array $open = ['intereses_lerom', 'comisiones_financieras']): Period
{
    $p = F::period($start, $end);
    F::fakeRadiographyByPeriod([$p->id => ['CORDOBA' => [
        'interes_recuperado' => 1000000.00,
        'gastos_operativos' => 400000.00, 'gastos_detalle' => ['Renta Oficina' => 400000.00],
        'nomina_total' => 200000.00,
    ]]]);
    foreach (array_keys(AdjustmentService::CONCEPTS) as $concept) {
        if (! in_array($concept, $open, true)) {
            app(AdjustmentService::class)->save($p, 'CONSOLIDADO', $concept, AdjustmentService::CONFIRMADO_CERO, null, 'prueba', null);
        }
    }

    return $p;
}

function gfcAdj(array $d, string $key): array
{
    return collect($d['adjustments'])->firstWhere('key', $key);
}

it('lee LEROM (comisión separada, NUNCA interés) y BBVA; rechaza PDF', function () {
    $u = gfcImport('lerom', gfcLeromCsv())['upload'];
    expect(array_keys($u->column_map['columns']))->toEqualCanonicalizing(['numero', 'fecha', 'capital', 'comision', 'iva_comision', 'pago', 'saldo'])
        ->and($u->rows_count)->toBe(6)
        ->and($u->total_interest)->toBe('0.00')
        ->and($u->total_commission)->toBe('600.00')
        ->and($u->total_commission_vat)->toBe('96.00')
        ->and($u->document_kind)->toBe('definitivo')
        ->and(collect($u->warnings)->pluck('code')->all())->not->toContain('pago_no_cuadra');

    $path = storage_path('framework/testing/er/'.uniqid('bbva_', true).'.csv');
    file_put_contents($path, "Periodo,Fecha,Pago a Capital,Interés del Periodo,IVA,Pago fijo sin IVA,Pago Mensual Total,Saldo Insoluto\n"
        ."1,02/06/2026,0,7724,1235.84,7724,8959.84,1860000\n2,02/07/2026,42916,23173,3707.68,66089,69796.68,1817084\n");
    $b = gfcImport('bbva', $path, 'bbva.csv', 'definitivo')['upload'];
    expect($b->column_map['columns'])->toHaveKeys(['numero', 'capital', 'interes', 'iva', 'pago'])
        ->and($b->column_map['columns']['pago'])->toContain('Pago Mensual Total')
        ->and($b->total_interest)->toBe('30897.00')
        ->and($b->total_principal)->toBe('42916.00')
        ->and($b->total_commission)->toBe('0.00');

    expect(fn () => gfcImport('lerom', $path, 'simulador.pdf'))->toThrow(EstadoResultadosImportException::class, 'No se aceptan tablas de amortización en PDF');
});

it('DEPOSITO LEROM de sucursal es aportación; PAGO LEROM es capital de deuda; sin sucursal se clasifica solo (Sin asignar); ambiguo queda pendiente', function () {
    $c = app(MovementClassifier::class)->useCredits(app(FinancialExpenseService::class)->classifierCredits());
    $base = ['in_period' => true, 'observations' => null, 'bank_reference' => '', 'cargo' => '0', 'abono' => '4095.00'];

    $ok = $c->classify($base + ['concept' => 'DEPÓSITO LEROM', 'branch_kind' => 'operativa', 'branch_name' => 'CORDOBA', 'sucursal_raw' => 'CORDOBA'], null);
    $noBranch = $c->classify($base + ['concept' => 'DEPOSITO LEROM', 'branch_kind' => 'vacia', 'branch_name' => null, 'sucursal_raw' => ''], null);
    $noCreditor = $c->classify($base + ['concept' => 'DEPOSITO CREDITO', 'branch_kind' => 'operativa', 'branch_name' => 'CORDOBA', 'sucursal_raw' => 'CORDOBA'], null);
    $pay = $c->classify(array_replace($base, ['cargo' => '14922.37', 'abono' => '0']) + ['concept' => 'PAGO LEROM', 'branch_kind' => 'corporativo', 'branch_name' => 'CORPORATIVO', 'sucursal_raw' => 'CORPORATIVO'], null);

    expect($ok)->toMatchArray(['treatment' => T::TRANSFERENCIA_INTERNA, 'rule' => 'aportacion_credito', 'review' => false])
        ->and($ok['reason'])->toContain('Aportación de CORDOBA para el crédito LEROM')
        ->and($noBranch)->toMatchArray(['treatment' => T::TRANSFERENCIA_INTERNA, 'suggested' => null, 'rule' => 'aportacion_credito', 'review' => false])
        ->and($noCreditor['rule'])->toBe('aportacion_credito_sugerida')
        ->and($pay)->toMatchArray(['treatment' => T::CAPITAL_DEUDA, 'rule' => 'servicio_deuda']);
});

it('reparto a centavos por residuo mayor: 30/20/50 de $20,000 y residuos que cuadran exacto', function () {
    expect(FinancialExpenseService::largestRemainder(2000000, ['A' => 30, 'B' => 20, 'C' => 50]))->toBe(['A' => 600000, 'B' => 400000, 'C' => 1000000])
        ->and(FinancialExpenseService::largestRemainder(100, ['B' => 1, 'A' => 1, 'C' => 1]))->toBe(['B' => 34, 'A' => 33, 'C' => 33]) // desempate: orden de llaves
        ->and(array_sum(FinancialExpenseService::largestRemainder(1001, ['X' => 7, 'Y' => 3, 'Z' => 11])))->toBe(1001);
});

it('EBT baja sólo por la comisión (EBITDA/EBIT igual, sin doble conteo del pago); aportaciones, pagos y prorrateo cuadran', function () {
    $p = gfcPeriod();
    G::import($p, F::adminXlsx([
        ['2026-09-01', 1116.00, null, 'S/R', 'PAGO LEROM', 'CORPORATIVO'],
        ['2026-09-02', 1116.00, null, 'S/R', 'PAGO LEROM', 'CORPORATIVO'],
        ['2026-09-04', 1116.00, null, 'S/R', 'PAGO LEROM', 'CORPORATIVO'],
        ['2026-09-05', null, 300.00, null, 'DEPOSITO LEROM', 'CORDOBA'],
        ['2026-09-05', null, 200.00, null, 'DEPOSITO LEROM', 'ATLIXCO'],
        ['2026-09-06', null, 100.00, null, 'DEPOSITO LEROM', null],
    ]));
    app(EstadoResultadosProcessor::class)->process($p);
    $before = app(EstadoResultadosDatasetBuilder::class)->build($p);

    gfcImport('lerom', gfcLeromCsv());
    $d = app(EstadoResultadosDatasetBuilder::class)->build($p);
    expect(gfcAdj($d, 'comisiones_financieras'))->toMatchArray(['state' => 'capturado', 'amount' => '400.00', 'origin' => 'tabla_amortizacion'])
        ->and(gfcAdj($d, 'intereses_lerom')['amount'])->toBe('0.00')
        ->and($d['results']['ebitda'])->toBe($before['results']['ebitda'])
        ->and($d['results']['ebit'])->toBe($before['results']['ebit'])
        ->and(bcsub($before['results']['ebt'], $d['results']['ebt'], 2))->toBe('400.00'); // ni el pago (3,348) ni el capital ni el IVA

    $panel = $this->actingAs(G::admin())->getJson("/estado-resultados/{$p->id}/gastos-financieros")->assertOk()->json();
    $lerom = collect($panel['credits'])->firstWhere('credit.key', 'lerom');
    expect($lerom['coverage']['status'])->toBe('completo') // tabla del 31-ago al 01-oct
        ->and(collect($lerom['creditor_payments']['rows'])->pluck('status')->all())->toBe(['conciliado', 'conciliado', 'no_localizado', 'conciliado'])
        ->and($lerom['status']['status'])->toBe('pago_incompleto') // aplicado $3,348 (máx. de aportado $600 y pagado $3,348) de $4,464
        ->and($lerom['application'])->toMatchArray(['applied' => '3348.00', 'shortfall' => '1116.00', 'covered_count' => 3, 'basis' => 'pagos_acreedor'])
        ->and($lerom['contributions']['identified'])->toBe('500.00')
        ->and($lerom['contributions']['pending'])->toBe('100.00')
        ->and($lerom['contributions']['items'][0]['account'])->not->toMatch('/\d{5,}/')
        ->and($panel['summary'])->toMatchArray(['commission_recognized' => '400.00', 'interest_recognized' => '0.00', 'ebt_effect' => '-400.00',
            'vat_separated' => '64.00', 'principal_informative' => '4000.00', 'observed_payment' => '3348.00', 'contributions_total' => '600.00']);

    // 300 / 200 / 100 (pendiente) sobre $400.00 ⇒ 200.00 / 133.33 / 66.67 (residuo mayor, cuadra exacto).
    $alloc = collect($panel['allocation']['credits'])->firstWhere('credit_key', 'lerom');
    expect(collect($alloc['branches'])->pluck('assigned', 'branch')->all())->toBe(['ATLIXCO' => '133.33', 'CORDOBA' => '200.00'])
        ->and($alloc['unassigned']['amount'])->toBe('66.67')
        ->and($alloc['check']['ok'])->toBeTrue()
        ->and($alloc['branches'][1]['principal_informative'])->toBe('2000.00');
    $bbva = collect($panel['allocation']['credits'])->firstWhere('credit_key', 'bbva');
    expect($bbva['unassigned']['reason'])->toContain('No hay aportaciones');

    // La captura manual gana sobre la tabla y se informa la diferencia.
    app(AdjustmentService::class)->save($p, 'CONSOLIDADO', 'comisiones_financieras', AdjustmentService::CAPTURADO, '450', 'Según contabilidad', null);
    $m = gfcAdj(app(EstadoResultadosDatasetBuilder::class)->build($p), 'comisiones_financieras');
    expect($m['origin'])->toBe('manual')->and($m['amount'])->toBe('450.00')->and($m['amortization']['difference'])->toBe('50.00');
});

it('después del último pago previsto: sin pagos programados / posible liquidación, nunca «liquidado»', function () {
    gfcImport('lerom', gfcLeromCsv());
    $p = gfcPeriod('2026-11-02', '2026-11-29');
    $panel = app(FinancialExpenseService::class)->panel($p);
    $lerom = collect($panel['credits'])->firstWhere('credit.key', 'lerom');
    expect($lerom['coverage']['status'])->toBe('fuera_de_calendario')
        ->and($lerom['coverage']['reason'])->toContain('No significa que el crédito esté liquidado')
        ->and($lerom['status']['status'])->toBe('posible_liquidacion')
        ->and($lerom['status']['remaining_payments'])->toBe(0)
        ->and(gfcAdj(app(EstadoResultadosDatasetBuilder::class)->build($p), 'comisiones_financieras')['state'])->toBe(AdjustmentService::SIN_INFORMACION);
});

/**
 * Tabla LEROM tipo P04-2026: pagos diarios hábiles de $14,922.37 (capital 13,801.68 + comisión
 * 966.12 + IVA 154.58, sin interés). Pagos 1–4 antes del periodo, 19 dentro (sin el 03/04) y 2
 * después, para que la cobertura sea completa.
 */
function gfcLeromAbrilCsv(): string
{
    $dir = storage_path('framework/testing/er');
    is_dir($dir) || mkdir($dir, 0775, true);
    $path = $dir.'/'.uniqid('lerom_abr_', true).'.csv';
    $dates = ['2026-03-24', '2026-03-25', '2026-03-26', '2026-03-27'];
    for ($d = new DateTimeImmutable('2026-03-30'); $d <= new DateTimeImmutable('2026-04-28'); $d = $d->modify('+1 day')) {
        if ((int) $d->format('N') <= 5 && $d->format('Y-m-d') !== '2026-04-03') {
            $dates[] = $d->format('Y-m-d');
        }
    }
    $lines = ['Pago,Fecha,Amortización Capital,Importe Comisión,IVA Comisión,Pago Total,Saldo Insoluto'];
    $saldo = 14922.37 * count($dates);
    foreach ($dates as $i => $date) {
        $saldo -= 14922.37;
        $lines[] = ($i + 1).','.date('d/m/Y', strtotime($date)).',13801.68,966.12,154.58,14922.37,'.number_format($saldo, 2, '.', '');
    }
    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
}

it('aplicado = máx(aportaciones, pagos al acreedor) en FIFO: abril cubre 12 pagos, 1,111.56 del 13.º y faltan 103,345.03; el ER no cambia', function () {
    config(['estado_resultados.gastos_financieros.comisiones_en_resultado_financiero' => false]);
    $branches = ['CORDOBA', 'ATLIXCO', 'ATLACOMULCO', 'CUERNAVACA', 'HUAMANTLA', 'IXTLAHUACA', 'MIACATLAN', 'ORIZABA', 'SAN LUIS POTOSI', 'TLAXCALA', 'TULA'];
    foreach ($branches as $b) {
        F::branch($b);
    }
    $p = gfcPeriod('2026-03-30', '2026-04-26');
    $weekly = F::period('2026-04-13', '2026-04-19'); // periodo semanal que también contiene el 15/04
    $rows = [['2026-04-06', 45045.00, null, 'S/R', 'PAGO CREDITO LEROM', 'CORPORATIVO']];
    foreach (['2026-04-03', '2026-04-10', '2026-04-17', '2026-04-24'] as $date) {
        foreach ($branches as $b) {
            $rows[] = [$date, null, 4095.00, null, 'DEPOSITO LEROM', $b];
        }
    }
    G::import($p, F::adminXlsx($rows));
    app(EstadoResultadosProcessor::class)->process($p);
    gfcImport('lerom', gfcLeromAbrilCsv());

    $panel = app(FinancialExpenseService::class)->panel($p);
    $lerom = collect($panel['credits'])->firstWhere('credit.key', 'lerom');
    expect($lerom['totals']['payment'])->toBe('283525.03')
        ->and($lerom['application'])->toMatchArray([
            'rule' => 'max_aportaciones_pagos', 'basis' => 'aportaciones',
            'expected' => '283525.03', 'contributed' => '180180.00', 'paid_to_creditor' => '45045.00',
            'applied' => '180180.00', 'shortfall' => '103345.03', 'excess' => '0.00', 'difference' => '-103345.03',
            'expected_count' => 19, 'covered_count' => 12, 'partial_count' => 1, 'uncovered_count' => 6,
        ]);
    $pays = $lerom['creditor_payments'];
    expect($pays['rows'][0]['number'])->toBe(5)
        ->and(collect($pays['rows'])->pluck('coverage')->countBy()->all())->toBe(['cubierto' => 12, 'parcial' => 1, 'sin_cubrir' => 6])
        ->and($pays['rows'][12])->toMatchArray(['number' => 17, 'applied' => '1111.56', 'shortfall' => '13810.81', 'coverage' => 'parcial'])
        ->and($pays['unscheduled'])->toHaveCount(1) // el cargo de $45,045 se sigue listando aparte
        ->and($pays['unscheduled'][0]['amount'])->toBe('45045.00')
        ->and($pays['totals'])->toMatchArray(['covered_count' => 12, 'unreconciled_count' => 7, 'unreconciled_amount' => '103345.03'])
        ->and($lerom['status']['status'])->toBe('pago_incompleto')
        ->and($lerom['status']['reason'])->toContain('$180,180.00')
        ->and($panel['summary'])->toMatchArray(['applied_payment' => '180180.00', 'expected_payment' => '283525.03', 'shortfall' => '103345.03',
            'financial_cost' => '0.00', 'ebt_effect' => '0.00', 'unreconciled' => ['count' => 7, 'amount' => '103345.03']]);

    // Reparto automático del SERVICIO DE DEUDA: 11 × 1/11 de $283,525.03 ⇒ $25,775.00 (los 3
    // centavos sobrantes a las 3 primeras por orden); aportó $16,380 ⇒ debe ≈ $9,395. Cuadra exacto.
    $alloc = collect($panel['allocation']['credits'])->firstWhere('credit_key', 'lerom');
    $by = collect($alloc['branches'])->keyBy('branch');
    expect($alloc['debt_service'])->toMatchArray(['expected' => '283525.03', 'contributed' => '180180.00', 'difference' => '-103345.03', 'ok' => true])
        ->and($alloc['recognized']['total'])->toBe('0.00') // sin interés: el costo financiero es $0, pero el reparto sí aplica
        ->and(collect($alloc['branches'])->reduce(fn ($s, $b) => bcadd($s, $b['due'], 2), '0'))->toBe('283525.03')
        ->and($by['ATLIXCO'])->toMatchArray(['due' => '25775.01', 'contributed' => '16380.00', 'difference' => '-9395.01', 'standing' => 'debe', 'interest' => '0.00'])
        ->and($by['ORIZABA'])->toMatchArray(['due' => '25775.00', 'contributed' => '16380.00', 'difference' => '-9395.00', 'standing' => 'debe'])
        ->and($alloc['unassigned'])->toMatchArray(['due' => '0.00', 'contributed' => '0.00'])
        ->and(collect($panel['allocation']['by_branch'])->firstWhere('branch', 'ORIZABA'))->toMatchArray(['due' => '25775.00', 'difference' => '-9395.00']);

    // Sin aportaciones, el aplicado son los pagos al acreedor (no se suman ambos).
    [, $onlyPaid] = FinancialExpenseService::applyFunds($pays, ['total' => '0.00']);
    expect($onlyPaid)->toMatchArray(['basis' => 'pagos_acreedor', 'applied' => '45045.00', 'covered_count' => 3, 'partial_count' => 1]);

    // Exportación: mismas cifras.
    $fin = app(ErFinancingSection::class)->build(['meta' => ['scope' => ['consolidated' => true], 'period' => ['id' => $p->id]]]);
    $c = collect($fin['credits'])->firstWhere('key', 'lerom');
    expect($c['application']['applied'])->toBe('180180.00')->and($c['status_label'])->toBe('Faltan pagos por cubrir')
        ->and($c['allocation']['debt_service']['expected'])->toBe('283525.03')
        ->and($fin['allocation_ok'])->toBeTrue()
        ->and(collect($fin['bars'])->firstWhere('label', 'ORIZABA'))->toMatchArray(['due' => '25775.00', 'contributed' => '16380.00', 'value' => '-9395.00'])
        ->and(collect($fin['bars'])->pluck('label'))->not->toContain('Sin asignar');

    // Comando por fecha: elige el periodo de 4 semanas sobre el semanal y lista candidatos.
    $this->artisan('estado-resultados:cuadre-creditos', ['periodo' => '2026-04-15', '--credito' => 'lerom'])
        ->expectsOutputToContain('Periodo '.$p->id)
        ->expectsOutputToContain('cuadra al centavo')
        ->expectsOutputToContain('Varios periodos contienen el 15/04/2026')
        ->expectsOutputToContain('$180,180.00')
        ->expectsOutputToContain('$103,345.03')
        ->expectsOutputToContain('Cubierto con aportaciones')
        ->expectsOutputToContain('Le toca')
        ->expectsOutputToContain('$25,775.00')
        ->expectsOutputToContain('-$9,395.01') // línea de ATLACOMULCO (la de $25,775.00 ya la consume la expectativa anterior)
        ->assertSuccessful();
    $this->artisan('estado-resultados:cuadre-creditos', ['periodo' => (string) $weekly->id])->assertSuccessful();
});

/** Tabla BBVA sintética: primer pago 02/06/2026 (interés 7,724), monto 1,860,000 (saldo de la fila 1). */
function gfcBbvaCsv(): string
{
    $dir = storage_path('framework/testing/er');
    is_dir($dir) || mkdir($dir, 0775, true);
    $path = $dir.'/'.uniqid('bbva_', true).'.csv';
    file_put_contents($path, "Periodo,Fecha,Pago a Capital,Interés del Periodo,IVA,Pago fijo sin IVA,Pago Mensual Total,Saldo Insoluto\n"
        ."1,02/06/2026,0,7724,1235.84,7724,8959.84,1860000\n2,02/07/2026,42916,23173,3707.68,66089,69796.68,1817084\n");

    return $path;
}

it('BBVA antes del primer pago: «Aún no inicia», primer pago y monto del crédito según la tabla', function () {
    config(['estado_resultados.gastos_financieros.comisiones_en_resultado_financiero' => false]);
    $p = gfcPeriod('2026-03-30', '2026-04-26', ['intereses_lerom', 'intereses_bbva', 'comisiones_financieras']);
    gfcImport('bbva', gfcBbvaCsv(), 'bbva.csv', 'definitivo');

    $panel = app(FinancialExpenseService::class)->panel($p);
    $bbva = collect($panel['credits'])->firstWhere('credit.key', 'bbva');
    expect($bbva['status'])->toMatchArray(['status' => 'por_iniciar', 'document_balance' => '1860000.00',
        'first_payment' => ['number' => 1, 'date' => '2026-06-02', 'payment' => '8959.84']])
        ->and($bbva['status']['reason'])->toContain('Aún no inicia')->toContain('02/06/2026')->toContain('$8,959.84')
        ->and($bbva['coverage']['reason'])->toContain('Aún no inicia')
        ->and($bbva['recognized']['interest'])->toBe('0.00');

    $fin = app(ErFinancingSection::class)->build(['meta' => ['scope' => ['consolidated' => true], 'period' => ['id' => $p->id]]]);
    expect(collect($fin['credits'])->firstWhere('key', 'bbva'))->toMatchArray(['status_label' => 'Aún no inicia', 'coverage_label' => 'Aún no inicia', 'status_tone' => 'mute']);

    $this->artisan('estado-resultados:cuadre-creditos', ['periodo' => (string) $p->id, '--credito' => 'bbva'])
        ->expectsOutputToContain('Aún no inicia · primer pago 02/06/2026 · $8,959.84 · monto del crédito según la tabla $1,860,000.00')
        ->assertSuccessful();
});

it('BBVA usa la misma lógica automática que LEROM: cargo y aportaciones se aplican solos y el interés de la tabla entra al EBT', function () {
    config(['estado_resultados.gastos_financieros.comisiones_en_resultado_financiero' => false]);
    $p = gfcPeriod('2026-05-25', '2026-06-21', ['intereses_lerom', 'intereses_bbva', 'comisiones_financieras']);
    G::import($p, F::adminXlsx([
        ['2026-06-02', 8959.84, null, 'S/R', 'PAGO CREDITO BBVA', 'CORPORATIVO'],
        ['2026-05-29', null, 3000.00, null, 'DEPOSITO CREDITO BBVA', 'CORDOBA'],
        ['2026-05-29', null, 2000.00, null, 'DEPOSITO CREDITO BBVA', 'ATLIXCO'],
    ]));
    app(EstadoResultadosProcessor::class)->process($p);
    $before = app(EstadoResultadosDatasetBuilder::class)->build($p);
    gfcImport('bbva', gfcBbvaCsv(), 'bbva.csv', 'definitivo');

    // Interés de la tabla ⇒ ajuste del ER sin captura manual; sólo baja el EBT (no EBITDA ni EBIT).
    $d = app(EstadoResultadosDatasetBuilder::class)->build($p);
    expect(gfcAdj($d, 'intereses_bbva'))->toMatchArray(['state' => 'capturado', 'amount' => '7724.00', 'origin' => 'tabla_amortizacion'])
        ->and($d['results']['ebitda'])->toBe($before['results']['ebitda'])
        ->and($d['results']['ebit'])->toBe($before['results']['ebit'])
        ->and(bcsub($before['results']['ebt'], $d['results']['ebt'], 2))->toBe('7724.00');

    $panel = app(FinancialExpenseService::class)->panel($p);
    $bbva = collect($panel['credits'])->firstWhere('credit.key', 'bbva');
    expect($bbva['coverage']['status'])->toBe('parcial') // la tabla inicia dentro del periodo
        ->and($bbva['creditor_payments']['rows'][0])->toMatchArray(['number' => 1, 'status' => 'conciliado', 'coverage' => 'cubierto', 'applied' => '8959.84'])
        ->and($bbva['contributions'])->toMatchArray(['identified' => '5000.00', 'pending' => '0.00'])
        ->and($bbva['application'])->toMatchArray(['expected' => '8959.84', 'contributed' => '5000.00', 'paid_to_creditor' => '8959.84',
            'applied' => '8959.84', 'basis' => 'pagos_acreedor', 'covered_count' => 1, 'shortfall' => '0.00'])
        ->and($bbva['status']['status'])->toBe('vigente')
        ->and($bbva['recognized']['interest'])->toBe('7724.00')
        ->and($panel['summary'])->toMatchArray(['interest_recognized' => '7724.00', 'financial_cost' => '7724.00', 'ebt_effect' => '-7724.00']);

    // Mismo reparto por sucursal que LEROM (60 / 40): le toca, aportó, diferencia e interés.
    $alloc = collect($panel['allocation']['credits'])->firstWhere('credit_key', 'bbva');
    expect(collect($alloc['branches'])->keyBy('branch')->map(fn ($b) => [$b['due'], $b['contributed'], $b['difference'], $b['standing'], $b['interest']])->all())
        ->toBe(['ATLIXCO' => ['3583.94', '2000.00', '-1583.94', 'debe', '3089.60'], 'CORDOBA' => ['5375.90', '3000.00', '-2375.90', 'debe', '4634.40']])
        ->and($alloc['debt_service']['ok'])->toBeTrue()
        ->and($alloc['check']['ok'])->toBeTrue();

    // PDF: misma tabla por sucursal (le toca / aportó / diferencia / interés).
    $html = app(EstadoResultadosPdfExporter::class)->html(app(EstadoResultadosDatasetBuilder::class)->build($p));
    expect($html)->toContain('BBVA por sucursal')->toContain('Le toca')->toContain('5,375.90')->toContain('4,634.40');

    // Solo con aportaciones (sin cargo) el pago queda parcial; sin nada, sin cubrir.
    $noCargo = $bbva['creditor_payments'];
    $noCargo['totals']['detected'] = '0.00';
    [$partial] = FinancialExpenseService::applyFunds($noCargo, ['total' => '5000.00']);
    [$none] = FinancialExpenseService::applyFunds($noCargo, ['total' => '0.00']);
    expect($partial['rows'][0])->toMatchArray(['coverage' => 'parcial', 'applied' => '5000.00', 'shortfall' => '3959.84'])
        ->and($none['rows'][0]['coverage'])->toBe('sin_cubrir');
});
