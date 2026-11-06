<?php

use App\Models\EstadoResultados\EstadoResultadosClassification;
use App\Models\EstadoResultados\EstadoResultadosExport;
use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\EstadoResultados\EstadoResultadosUpload;
use App\Models\User;
use App\Services\Access\ModuleAccessService;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Classification\ClassificationService;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Export\EstadoResultadosExcelExporter;
use App\Services\EstadoResultados\Export\EstadoResultadosPdfExporter;
use App\Services\EstadoResultados\Import\EstadoResultadosImportException;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use App\Services\EstadoResultados\Support\Money;
use App\Services\EstadoResultados\Support\Treatment as T;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\EstadoResultadosFixtures as F;

/**
 * Flujo completo del Estado de Resultados sobre SQLite con base de Radiografía sintética.
 * (La equivalencia contra la Radiografía REAL vive en tests/Integration — MySQL.)
 */
function erImport($period, string $path, string $slot = 'administrativa')
{
    return app(EstadoResultadosUploadService::class)->importPath($period, $path, basename($path), $slot, null);
}

function erBuild($period, string $scope = 'CONSOLIDADO'): array
{
    return app(EstadoResultadosDatasetBuilder::class)->build($period, $scope);
}

function erMovement(array $dataset, string $concept): array
{
    return collect($dataset['movements'])->first(fn ($m) => $m['concept'] === $concept) ?? throw new RuntimeException("No hay movimiento {$concept}");
}

beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
    F::fakeRadiography();
});

it('EBITDA sucursales parte de la base canónica de Radiografía: capital recuperado no es ingreso y colocación no es gasto', function () {
    $period = F::period();
    $d = erBuild($period);

    // Ingresos = 80,000 + 12,800 + 4,200 + 1,000 + 1,500 + 300 + 200 = 100,000 (sin capital 900,000 ni colocación 750,000)
    expect($d['results']['ingresos'])->toBe('100000.00')
        ->and($d['results']['opex_sucursales'])->toBe('20000.00')
        ->and($d['results']['nomina_sucursales'])->toBe('30000.00')
        ->and($d['results']['ebitda_sucursales'])->toBe('50000.00')
        ->and($d['radiography_reconciliation']['difference'])->toBe('0.00')
        ->and($d['radiography_reconciliation']['ok'])->toBeTrue();

    $info = collect($d['indicators']['informative'])->keyBy('key');
    expect($info['capital_recuperado']['value'])->toBe('900000.00')
        ->and($info['colocacion']['value'])->toBe('750000.00');
    expect(collect($d['statement'])->where('type', 'section')->flatMap(fn ($s) => array_column($s['lines'], 'label'))->implode('|'))
        ->not->toContain('Capital')->not->toContain('Colocación');
});

it('CORPORATIVO del ERP participa en el Estado de Resultados (nivel 2) y los tres EBITDA cuadran', function () {
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-C1', '7000.00', '2026-09-05', 'Luz', 'LUZ CORPORATIVO');
    F::erp($period, 'CORPORATIVO', 'REQ-C2', '3000.00', '2026-09-06', 'Nómina y Capital Humano', 'FINIQUITO');

    $d = erBuild($period);

    expect($d['results']['opex_corporativo'])->toBe('7000.00')
        ->and($d['results']['nomina_corporativa'])->toBe('3000.00')
        ->and($d['results']['ebitda_corporativo'])->toBe('40000.00')
        ->and($d['results']['ebitda_final'])->toBe('40000.00')
        ->and(collect($d['checks'])->where('ok', false)->pluck('key')->all())->toBe([]);
});

it('un gasto presente en ERP y en el estado de cuenta sólo se cuenta una vez', function () {
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-ABC12', '5000.00', '2026-09-10', 'Servicios', 'SERVICIO INTERNET');
    erImport($period, F::adminXlsx([['2026-09-10', 5000.00, null, 'REQ-ABC12', 'REQ SERVICIOS', 'CORPORATIVO', 'PROVEEDOR SA']]));
    app(EstadoResultadosProcessor::class)->process($period);

    $d = erBuild($period);
    $bank = erMovement($d, 'REQ SERVICIOS');

    expect($bank['reconciliation_status'])->toBe('conciliado_erp_exacto')
        ->and($bank['treatment'])->toBe(T::CONCILIADO_ERP)
        ->and($bank['impact']['included'])->toBeFalse()
        ->and($d['results']['opex_corporativo'])->toBe('5000.00')   // una sola vez (desde el ERP)
        ->and($d['conciliation']['incorporado_bancos'])->toBe('0.00');
});

it('folio igual con monto diferente queda en revisión y no se suma', function () {
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-ABC12', '5000.00', '2026-09-10');
    erImport($period, F::adminXlsx([['2026-09-10', 4500.00, null, 'REQ-ABC12', 'REQ SERVICIOS', 'CORPORATIVO']]));
    app(EstadoResultadosProcessor::class)->process($period);

    $bank = erMovement(erBuild($period), 'REQ SERVICIOS');

    expect($bank['reconciliation_status'])->toBe('folio_monto_diferente')
        ->and($bank['treatment'])->toBe(T::PENDIENTE)
        ->and($bank['needs_review'])->toBeTrue();
});

it('folio con varios pagos concilia 1:N y no duplica el gasto', function () {
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-N1', '5000.00', '2026-09-10', 'Renta Oficina', 'RENTA', '2026-09-05');
    erImport($period, F::adminXlsx([
        ['2026-09-08', 2000.00, null, 'REQ-N1', 'REQ RENTA', 'CORPORATIVO'],
        ['2026-09-10', 3000.00, null, 'REQ-N1', 'REQ RENTA', 'CORPORATIVO'],
    ]));
    app(EstadoResultadosProcessor::class)->process($period);
    $d = erBuild($period);
    $rows = collect($d['movements'])->where('source', 'banco');

    expect($rows->pluck('reconciliation_status')->unique()->values()->all())->toBe(['conciliado_erp_agregado'])
        ->and($rows->first()['reconciliation_message'])->toContain('$2,000.00 + $3,000.00 = $5,000.00')
        ->and($d['results']['opex_corporativo'])->toBe('5000.00');
});

it('traspasos entre cuentas no afectan ingreso ni gasto y se emparejan entre archivos', function () {
    $period = F::period();
    erImport($period, F::adminXlsx([['2026-09-02', null, 112000.00, null, 'TRASPASO ENTRE CUENTAS', null]]));
    erImport($period, F::excedentesXlsx([
        ['2026-09-01', null, 112000.00, 'RETIRO EXCEDENTE', 'ORIZABA', null],
        ['2026-09-02', 112000.00, null, 'TRASPASO ENTRE CUENTAS', null, 'EXCEDENTES A ADMINISTRATIVA', 'TRASPASO CUENTAS PROPIAS 001'],
    ]), 'excedentes');
    app(EstadoResultadosProcessor::class)->process($period);
    $d = erBuild($period);

    $transfers = collect($d['movements'])->where('treatment', T::TRANSFERENCIA_INTERNA);
    expect($transfers)->toHaveCount(3)
        ->and($transfers->pluck('transfer_pair')->filter()->unique()->count())->toBe(1)
        ->and($d['results']['ebitda_final'])->toBe('50000.00')
        ->and($d['results']['ingresos'])->toBe('100000.00');
});

it('CAPEX, capital de deuda y movimientos personales no reducen EBITDA; los intereses sí reducen EBT', function () {
    $period = F::period();
    erImport($period, F::adminXlsx([
        ['2026-09-03', 33733.93, null, 'S/R', 'PAGO 5 BYD FIRMA CAR', 'CORPORATIVO'],
        ['2026-09-04', 45045.00, null, 'S/R', 'PAGO 24 CREDITO LEROM', 'CORPORATIVO'],
        ['2026-09-05', 10339.46, null, 'S/R', 'COLEGIATURA DEBORA DG', 'CORPORATIVO'],
        ['2026-09-06', 6000.00, null, 'S/R', 'REQ VIATICOS DG', 'CORPORATIVO'],
    ]));
    app(EstadoResultadosProcessor::class)->process($period);
    $d = erBuild($period);
    $svc = app(ClassificationService::class);
    $item = fn (string $c) => ['fingerprint' => erMovement($d, $c)['fingerprint'], 'source' => 'banco', 'current' => erMovement($d, $c)['treatment']];
    $svc->classify($period, [$item('PAGO 5 BYD FIRMA CAR')], T::CAPEX, 'Arrendamiento financiero de vehículo', null);
    $svc->classify($period, [$item('COLEGIATURA DEBORA DG')], T::PERSONAL_SOCIOS, 'Gasto personal', null);
    $svc->classify($period, [$item('REQ VIATICOS DG')], T::DG_OPERATIVO, 'Viaje de negocio', null);
    app(AdjustmentService::class)->save($period, 'CONSOLIDADO', 'intereses_lerom', 'capturado', '4045.00', 'Tabla de amortización', null);

    $d = erBuild($period);

    expect(erMovement($d, 'PAGO 24 CREDITO LEROM')['treatment'])->toBe(T::CAPITAL_DEUDA)
        ->and($d['results']['ebitda_corporativo'])->toBe('50000.00')
        ->and($d['results']['dg'])->toBe('6000.00')
        ->and($d['results']['ebitda_final'])->toBe('44000.00')     // sólo el DG operativo
        ->and($d['results']['resultado_financiero_neto'])->toBe('4045.00')
        ->and($d['results']['ebt'])->toBe('39955.00')             // capital de deuda NO reduce EBT
        ->and(collect($d['outside']['groups'])->pluck('key')->all())->toContain(T::CAPEX, T::CAPITAL_DEUDA, T::PERSONAL_SOCIOS);
});

it('un campo sin información no se asume cero: el resultado se marca preliminar; confirmar $0 lo completa', function () {
    $period = F::period();
    $d = erBuild($period);
    expect($d['completeness']['ebit']['complete'])->toBeFalse()
        ->and(collect($d['statement'])->firstWhere('key', 'ebit')['complete'])->toBeFalse();

    foreach (['depreciacion', 'amortizacion'] as $c) {
        app(AdjustmentService::class)->save($period, 'CONSOLIDADO', $c, 'confirmado_cero', null, 'Sin activos', null);
    }
    $d = erBuild($period);
    expect($d['completeness']['ebit']['complete'])->toBeTrue()
        ->and(collect($d['adjustments'])->firstWhere('key', 'depreciacion')['amount'])->toBe('0.00')
        ->and(collect($d['adjustments'])->firstWhere('key', 'isr')['amount'])->toBeNull();
});

it('UUID duplicado no se cuenta dos veces', function () {
    $period = F::period();
    erImport($period, F::adminXlsx([
        ['2026-09-10', 800.00, null, 'S/R', 'REQ INSUMOS', 'CORPORATIVO', 'PROV', 'UUID-AAA-1'],
        ['2026-09-10', 800.00, null, 'S/R', 'REQ INSUMOS', 'CORPORATIVO', 'PROV', 'UUID-AAA-1'],
    ]));
    app(EstadoResultadosProcessor::class)->process($period);
    $rows = collect(erBuild($period)['movements'])->where('source', 'banco')->values();

    expect($rows->pluck('treatment')->all())->toContain(T::DUPLICADO)
        ->and($rows->where('treatment', T::DUPLICADO))->toHaveCount(1);
});

it('subir dos veces el mismo archivo no duplica y reprocesar es idempotente', function () {
    $period = F::period();
    $path = F::adminXlsx([['2026-09-10', 1000.00, null, 'S/R', 'PAGO LIMPIEZA', 'CORPORATIVO']]);
    erImport($period, $path);

    expect(fn () => erImport($period, $path))->toThrow(EstadoResultadosImportException::class, 'ya está cargado');

    app(EstadoResultadosProcessor::class)->process($period);
    $h1 = erBuild($period)['meta']['dataset_hash'];
    app(EstadoResultadosProcessor::class)->process($period);
    app(EstadoResultadosProcessor::class)->process($period);

    expect(EstadoResultadosMovement::query()->where('period_id', $period->id)->count())->toBe(1)
        ->and(erBuild($period)['meta']['dataset_hash'])->toBe($h1);
});

it('reemplazar el archivo de una cuenta no duplica movimientos y conserva la clasificación manual por huella', function () {
    $period = F::period();
    erImport($period, F::adminXlsx([['2026-09-10', 1000.00, null, 'S/R', 'PAGO LIMPIEZA', 'CORPORATIVO']]));
    app(EstadoResultadosProcessor::class)->process($period);
    $m = erMovement(erBuild($period), 'PAGO LIMPIEZA');
    app(ClassificationService::class)->classify($period, [['fingerprint' => $m['fingerprint'], 'source' => 'banco', 'current' => $m['treatment']]], T::OPEX_CORPORATIVO, 'Servicio de limpieza oficina', null);

    // Versión corregida del archivo: mismo movimiento + uno nuevo
    erImport($period, F::adminXlsx([['2026-09-10', 1000.00, null, 'S/R', 'PAGO LIMPIEZA', 'CORPORATIVO'], ['2026-09-11', 50.00, null, 'S/R', 'PAGO GARRAFONES', 'CORPORATIVO']]));
    app(EstadoResultadosProcessor::class)->process($period);
    $d = erBuild($period);

    expect(EstadoResultadosMovement::query()->where('period_id', $period->id)->count())->toBe(2)
        ->and(EstadoResultadosUpload::query()->where('period_id', $period->id)->where('status', 'reemplazado')->count())->toBe(1)
        ->and(erMovement($d, 'PAGO LIMPIEZA')['treatment'])->toBe(T::OPEX_CORPORATIVO)
        ->and($d['results']['opex_corporativo'])->toBe('1000.00');
});

it('la clasificación manual no modifica el dato original y guarda historial con valor anterior', function () {
    $period = F::period();
    erImport($period, F::adminXlsx([['2026-09-10', 24300.00, null, 'S/R', 'PAGO', 'CORPORATIVO', 'IQCREATORS']]));
    app(EstadoResultadosProcessor::class)->process($period);
    $m = erMovement(erBuild($period), 'PAGO');
    $before = EstadoResultadosMovement::query()->where('fingerprint', $m['fingerprint'])->first()->only(['cargo', 'concept', 'beneficiary', 'raw_payload', 'auto_treatment']);
    $svc = app(ClassificationService::class);

    $uid = User::factory()->create()->id;
    $svc->classify($period, [['fingerprint' => $m['fingerprint'], 'source' => 'banco', 'current' => T::PENDIENTE]], T::OPEX_CORPORATIVO, 'Agencia de marketing', $uid);
    $svc->classify($period, [['fingerprint' => $m['fingerprint'], 'source' => 'banco', 'current' => T::OPEX_CORPORATIVO]], T::DG_OPERATIVO, 'Corrección: proyecto DG', $uid);

    $after = EstadoResultadosMovement::query()->where('fingerprint', $m['fingerprint'])->first()->only(['cargo', 'concept', 'beneficiary', 'raw_payload', 'auto_treatment']);
    $history = $svc->history($period->id, $m['fingerprint']);

    expect($after)->toEqual($before)
        ->and($history)->toHaveCount(2)
        ->and($history[0]['treatment'])->toBe(T::DG_OPERATIVO)
        ->and($history[0]['previous_treatment'])->toBe(T::OPEX_CORPORATIVO)
        ->and(EstadoResultadosClassification::query()->count())->toBe(2)
        ->and(erBuild($period)['results']['dg'])->toBe('24300.00');
});

it('un archivo con estructura inválida da un error entendible y no carga datos parciales', function () {
    $period = F::period();

    expect(fn () => erImport($period, F::invalidXlsx()))->toThrow(EstadoResultadosImportException::class, 'Estructura no reconocida');

    // Fila con importe pero fecha inválida: se rechaza TODO el archivo
    $book = IOFactory::load(F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO LIMPIEZA', 'CORPORATIVO'], ['2026-09-11', 200.00, null, 'S/R', 'PAGO X', 'CORPORATIVO']]));
    $book->getActiveSheet()->setCellValue('B4', 'no-es-fecha');
    $bad = storage_path('framework/testing/er/bad_'.uniqid().'.xlsx');
    (new Xlsx($book))->save($bad);

    try {
        erImport($period, $bad);
        $this->fail('Debió rechazarse');
    } catch (EstadoResultadosImportException $e) {
        expect($e->details()[0])->toContain('Fila 4')->toContain('FECHA');
    }

    expect(EstadoResultadosUpload::query()->count())->toBe(0)
        ->and(EstadoResultadosMovement::query()->count())->toBe(0);
});

it('rechaza un archivo de la otra cuenta y uno cuyas fechas no pertenecen al periodo', function () {
    $period = F::period();
    expect(fn () => erImport($period, F::excedentesXlsx([['2026-09-01', null, 100.00, 'RETIRO EXCEDENTE', 'ORIZABA', null]]), 'administrativa'))
        ->toThrow(EstadoResultadosImportException::class, 'estructura');
    expect(fn () => erImport($period, F::adminXlsx([['2026-12-01', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO']])))
        ->toThrow(EstadoResultadosImportException::class, 'Ninguna fecha');
});

it('movimientos fuera de las fechas del periodo se conservan pero no suman', function () {
    $period = F::period('2026-09-01', '2026-09-20');
    erImport($period, F::adminXlsx([
        ['2026-09-10', 100.00, null, 'S/R', 'NOM QUINCENA 17', 'CORPORATIVO'],
        ['2026-09-25', 900.00, null, 'S/R', 'NOM QUINCENA 18', 'CORPORATIVO'],
    ]));
    app(EstadoResultadosProcessor::class)->process($period);
    $d = erBuild($period);

    expect(erMovement($d, 'NOM QUINCENA 18')['treatment'])->toBe(T::FUERA_PERIODO)
        ->and($d['results']['nomina_corporativa'])->toBe('100.00')
        ->and($d['conciliation']['fuera_periodo'])->toBe(1);
});

it('valida el total de control del archivo contra la suma leída', function () {
    $period = F::period();
    $upload = erImport($period, F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO'], ['2026-09-11', null, 50.00, null, 'DEVOLUCION REQ', 'CORPORATIVO']]));

    expect($upload->control_ok)->toBeTrue()
        ->and(Money::str2($upload->total_cargos))->toBe('100.00')
        ->and(Money::str2($upload->total_abonos))->toBe('50.00');
});

it('Web, Excel y PDF usan exactamente el mismo dataset y exportar dos veces no cambia cifras', function () {
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-C1', '7000.55', '2026-09-05', 'Luz', 'LUZ');
    erImport($period, F::adminXlsx([['2026-09-10', 214032.81, null, 'S/R', 'NOM QUINCENA 17', 'CORPORATIVO']]));
    app(EstadoResultadosProcessor::class)->process($period);
    $admin = User::factory()->create(['role' => 'admin', 'access_enabled_at' => now()]);

    $web = $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/dataset")->assertOk()->json('dataset');

    $x1 = storage_path('framework/testing/er/x1.xlsx');
    $x2 = storage_path('framework/testing/er/x2.xlsx');
    app(EstadoResultadosExcelExporter::class)->export(erBuild($period), $x1);
    app(EstadoResultadosExcelExporter::class)->export(erBuild($period), $x2);
    $html = app(EstadoResultadosPdfExporter::class)->html(erBuild($period));
    $pdfMoney = fn ($v) => ((float) $v < 0 ? '-$' : '$').number_format(abs((float) $v), 2);

    $readResults = function (string $path): array {
        $ws = IOFactory::load($path)->getSheetByName('ESTADO RESULTADOS');
        $out = [];
        foreach ($ws->getRowIterator() as $row) {
            $r = $row->getRowIndex();
            $label = (string) $ws->getCell("B{$r}")->getValue();
            foreach (['TOTAL INGRESOS OPERATIVOS' => 'ingresos', 'EBITDA SUCURSALES' => 'ebitda_sucursales', 'EBITDA CORPORATIVO' => 'ebitda_corporativo', 'EBITDA FINAL' => 'ebitda_final', 'EBIT' => 'ebit', 'UTILIDAD NETA' => 'utilidad_neta', 'TOTAL OPEX CORPORATIVO' => 'opex_corporativo', 'TOTAL NÓMINA CORPORATIVA' => 'nomina_corporativa'] as $prefix => $key) {
                if ($label === $prefix || str_starts_with($label, $prefix.' ') || str_starts_with($label, $prefix.'  ')) {
                    $out[$key] = number_format((float) $ws->getCell("C{$r}")->getValue(), 2, '.', '');
                }
            }
        }
        $control = IOFactory::load($path)->getSheetByName('FUENTES Y CONTROL')->getCell('C6')->getValue();

        return [$out, $control];
    };
    [$excel1, $hash1] = $readResults($x1);
    [$excel2, $hash2] = $readResults($x2);

    foreach ($excel1 as $key => $value) {
        expect($value)->toBe($web['results'][$key], "Excel {$key}");
    }
    expect(count($excel1))->toBeGreaterThanOrEqual(7)
        ->and($excel1)->toBe($excel2)
        ->and($hash1)->toBe($web['meta']['dataset_hash'])
        ->and($hash2)->toBe($web['meta']['dataset_hash'])
        ->and($html)->toContain($web['meta']['dataset_hash'])
        ->and($html)->toContain($pdfMoney($web['results']['ebitda_final']))
        ->and($html)->toContain($pdfMoney($web['results']['ebitda_corporativo']))
        ->and($html)->toContain($pdfMoney($web['results']['ebitda_sucursales']));
});

it('todas las validaciones matemáticas terminan en $0.00 con datos mixtos', function () {
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-1', '1234.56', '2026-09-05', 'Luz', 'LUZ');
    F::erp($period, 'CORPORATIVO', 'REQ-2', '0.07', '2026-09-05', 'Agua', 'AGUA');
    erImport($period, F::adminXlsx([
        ['2026-09-05', 1234.56, null, 'REQ-1', 'REQ LUZ', 'CORPORATIVO'],
        ['2026-09-06', 333.33, null, 'S/R', 'NOM QUINCENA 17', 'CORPORATIVO'],
        ['2026-09-07', null, 0.01, null, 'DEVOLUCION REQ', 'CORPORATIVO'],
    ]));
    app(EstadoResultadosProcessor::class)->process($period);
    app(AdjustmentService::class)->save($period, 'CONSOLIDADO', 'depreciacion', 'capturado', '100.10', null, null);
    app(AdjustmentService::class)->save($period, 'CONSOLIDADO', 'ingresos_financieros', 'capturado', '0.05', null, null);
    app(AdjustmentService::class)->save($period, 'CONSOLIDADO', 'isr', 'capturado', '999.99', null, null);
    $d = erBuild($period);

    $failed = collect($d['checks'])->where('ok', false)->map(fn ($c) => $c['key'].': '.$c['difference'])->values()->all();
    expect($failed)->toBe([])
        ->and($d['results']['ebit'])->toBe(Money::str2(Money::of($d['results']['ebitda_final'])->minus('100.10')))
        ->and($d['results']['utilidad_neta'])->toBe(Money::str2(Money::of($d['results']['ebt'])->minus('999.99')));
});

it('alcance sucursal: sólo nivel 1 conciliado contra la sucursal y sin Corporativo/DG', function () {
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-C1', '7000.00', '2026-09-05', 'Luz', 'LUZ');
    $d = erBuild($period, 'CORDOBA');

    expect($d['results']['ebitda_sucursales'])->toBe('50000.00')
        ->and($d['results']['opex_corporativo'])->toBe('0.00')
        ->and($d['radiography_reconciliation']['ok'])->toBeTrue()
        ->and(collect($d['statement'])->firstWhere('key', 'opex_corporativo')['applies'])->toBeFalse();
});

it('respeta permisos: sin el módulo no entra; con módulo pero sin acción no puede cargar ni clasificar', function () {
    $period = F::period();
    $this->actingAs(User::factory()->create(['role' => 'colaborador', 'access_enabled_at' => now()]))
        ->get('/estado-resultados')->assertForbidden();

    app(ModuleAccessService::class)->save(['gerencial' => ['dashboard', 'estado_resultados']]);
    $viewer = User::factory()->create(['role' => 'gerencial', 'access_enabled_at' => now(), 'branch_scope' => 'all']);
    $this->withoutVite();
    $this->actingAs($viewer)->get('/estado-resultados')->assertOk();
    $this->actingAs($viewer)->getJson("/estado-resultados/{$period->id}/dataset")->assertOk();
    $this->actingAs($viewer)->postJson("/estado-resultados/{$period->id}/clasificar", ['fingerprints' => ['x'], 'treatment' => 'capex', 'notes' => 'prueba'])->assertForbidden();
    $this->actingAs($viewer)->postJson("/estado-resultados/{$period->id}/procesar")->assertForbidden();
    $this->actingAs($viewer)->get("/estado-resultados/{$period->id}/exportar/excel")->assertForbidden();
});

it('el endpoint de carga devuelve errores entendibles (422) y la exportación registra el hash', function () {
    $period = F::period();
    $admin = User::factory()->create(['role' => 'admin', 'access_enabled_at' => now()]);
    $file = new UploadedFile(F::invalidXlsx(), 'malo.xlsx', null, null, true);

    $this->actingAs($admin)->post("/estado-resultados/{$period->id}/cargas", ['slot' => 'administrativa', 'file' => $file], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Estructura no reconocida'));

    $hash = erBuild($period)['meta']['dataset_hash'];
    $this->actingAs($admin)->get("/estado-resultados/{$period->id}/exportar/excel")->assertOk()->assertHeader('X-Dataset-Hash', $hash);
    $this->actingAs($admin)->get("/estado-resultados/{$period->id}/exportar/excel")->assertOk()->assertHeader('X-Dataset-Hash', $hash);

    expect(EstadoResultadosExport::query()->pluck('dataset_hash')->unique()->values()->all())->toBe([$hash]);
});
