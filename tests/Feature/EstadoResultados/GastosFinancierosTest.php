<?php

use App\Models\EstadoResultados\EstadoResultadosAmortizationRow;
use App\Models\EstadoResultados\EstadoResultadosAmortizationUpload;
use App\Models\EstadoResultados\EstadoResultadosCredit;
use App\Models\Period;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\FinancialExpenses\AmortizationUploadService;
use App\Services\EstadoResultados\FinancialExpenses\FinancialExpenseService;
use App\Services\EstadoResultados\Import\EstadoResultadosImportException;
use App\Services\EstadoResultados\Reporting\ApprovalInputSignature;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * Gastos financieros (20-nov-2026): SÓLO el interés de los créditos LEROM/BBVA va entre
 * EBIT y EBT, tomado de la tabla de amortización. Datos 100 % SINTÉTICOS.
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
});

/** Tabla de amortización con encabezados NO estándar (título arriba, «Núm.», «Vencimiento», «Cuota»…). */
function gfXlsx(array $rows, array $headers = ['Núm.', 'Vencimiento', 'Cuota', 'Abono a capital', 'Intereses', 'IVA interés', 'Saldo insoluto'], bool $title = true): string
{
    $book = new Spreadsheet;
    $ws = $book->getActiveSheet();
    $start = 1;
    if ($title) {
        $ws->setCellValue('A1', 'LEROM SA DE CV — Tabla de amortización crédito simple');
        $ws->setCellValue('A2', 'Acreditado: MR LANA');
        $start = 4;
    }
    $ws->fromArray($headers, null, 'A'.$start);
    $r = $start + 1;
    foreach ($rows as $row) {
        if (isset($row[1]) && is_string($row[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $row[1])) {
            $row[1] = Date::PHPToExcel(new DateTime($row[1]));
        }
        $ws->fromArray($row, null, 'A'.$r++);
    }
    $ws->fromArray(['TOTAL', null, 999999, 999999, 999999], null, 'A'.($r + 1));
    $dir = storage_path('framework/testing/er');
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $path = $dir.'/'.uniqid('amort_', true).'.xlsx';
    (new Xlsx($book))->save($path);

    return $path;
}

/** LEROM semanal: 6 pagos de $45,000; 4 caen en el periodo 01-sep..28-sep. */
function gfLeromRows(): array
{
    return [
        [1, '2026-08-28', 45000.00, 39780.00, 4500.00, 720.00, 960220.00],
        [2, '2026-09-04', 45000.00, 39896.00, 4400.00, 704.00, 920324.00],
        [3, '2026-09-11', 45000.00, 40012.00, 4300.00, 688.00, 880312.00],
        [4, '2026-09-18', 45000.00, 40128.00, 4200.00, 672.00, 840184.00],
        [5, '2026-09-25', 45000.00, 40244.00, 4100.00, 656.00, 799940.00],
        [6, '2026-10-02', 45000.00, 40360.00, 4000.00, 640.00, 759580.00],
    ];
}

function gfCredit(string $key): EstadoResultadosCredit
{
    return EstadoResultadosCredit::query()->where('key', $key)->firstOrFail();
}

function gfImport(string $key, string $path, string $name = 'tabla.xlsx'): array
{
    return app(AmortizationUploadService::class)->importPath(gfCredit($key), $path, $name, null);
}

/** Periodo de 4 semanas con ingresos 1,000,000 y OPEX 600,000; ajustes confirmados en $0 salvo $pending. */
function gfPeriod(array $pending = ['intereses_lerom', 'intereses_bbva']): Period
{
    $p = F::period('2026-09-01', '2026-09-28');
    F::fakeRadiographyByPeriod([$p->id => ['CORDOBA' => [
        'interes_recuperado' => 1000000.00,
        'gastos_operativos' => 400000.00, 'gastos_detalle' => ['Renta Oficina' => 400000.00],
        'nomina_total' => 200000.00,
    ]]]);
    foreach (array_keys(AdjustmentService::CONCEPTS) as $concept) {
        if (! in_array($concept, $pending, true)) {
            app(AdjustmentService::class)->save($p, 'CONSOLIDADO', $concept, AdjustmentService::CONFIRMADO_CERO, null, 'prueba', null);
        }
    }

    return $p;
}

function gfAdj(array $d, string $key): array
{
    return collect($d['adjustments'])->firstWhere('key', $key);
}

it('importa una tabla con encabezados distintos (título, Núm., Vencimiento, Cuota, Abono a capital, Intereses) e ignora el renglón TOTAL', function () {
    $res = gfImport('lerom', gfXlsx(gfLeromRows()));
    $u = $res['upload'];

    expect($res['idempotent'])->toBeFalse()
        ->and($u->status)->toBe(EstadoResultadosAmortizationUpload::VIGENTE)
        ->and($u->rows_count)->toBe(6)
        ->and($u->first_payment_date->format('Y-m-d'))->toBe('2026-08-28')
        ->and($u->last_payment_date->format('Y-m-d'))->toBe('2026-10-02')
        ->and($u->total_interest)->toBe('25500.00')
        ->and(array_keys($u->column_map['columns']))->toEqualCanonicalizing(['numero', 'fecha', 'pago', 'capital', 'interes', 'iva', 'saldo'])
        ->and($u->column_map['header_row'])->toBe(4)
        ->and($u->stored_path)->not->toBeNull()
        ->and($u->warnings)->toBe([]);

    $row = EstadoResultadosAmortizationRow::query()->where('upload_id', $u->id)->orderBy('payment_date')->first();
    expect($row->payment_number)->toBe(1)
        ->and($row->interest)->toBe('4500.00')
        ->and($row->principal)->toBe('39780.00')
        ->and($row->interest_vat)->toBe('720.00')
        ->and($row->origin)->toBe('archivo');
});

it('mismo archivo dos veces no duplica; un archivo nuevo SUSTITUYE la tabla vigente', function () {
    $path = gfXlsx(gfLeromRows());
    $first = gfImport('lerom', $path)['upload'];
    $dup = gfImport('lerom', $path);
    expect($dup['idempotent'])->toBeTrue()->and($dup['upload']->id)->toBe($first->id);

    $rows = gfLeromRows();
    $rows[1][4] = 4450.00;
    $rows[1][3] = 39846.00;
    $second = gfImport('lerom', gfXlsx($rows), 'tabla_v2.xlsx');
    expect($second['replaced']?->id)->toBe($first->id)
        ->and($first->fresh()->status)->toBe(EstadoResultadosAmortizationUpload::SUSTITUIDA)
        ->and($first->fresh()->superseded_by_upload_id)->toBe($second['upload']->id)
        ->and(EstadoResultadosAmortizationUpload::query()->where('status', 'vigente')->count())->toBe(1);
});

it('interés del periodo = Σ interés de los 4 pagos semanales de LEROM; el capital queda excluido; EBT = EBIT − intereses', function () {
    $p = gfPeriod(['intereses_lerom']);
    gfImport('lerom', gfXlsx(gfLeromRows()));

    $d = app(EstadoResultadosDatasetBuilder::class)->build($p);
    $lerom = gfAdj($d, 'intereses_lerom');

    // 04, 11, 18 y 25 de septiembre: 4,400 + 4,300 + 4,200 + 4,100.
    expect($lerom['state'])->toBe(AdjustmentService::CAPTURADO)
        ->and($lerom['origin'])->toBe(FinancialExpenseService::ORIGIN_TABLE)
        ->and($lerom['amount'])->toBe('17000.00')
        ->and($lerom['amortization']['payments'])->toBe(4)
        ->and($lerom['amortization']['principal_excluded'])->toBe('160280.00')
        ->and($lerom['amortization']['payment'])->toBe('180000.00')
        ->and($lerom['notes'])->toContain('4 pago(s) del 04/09/2026 al 25/09/2026');

    expect($d['results']['ebit'])->toBe('400000.00')
        ->and($d['results']['resultado_financiero_neto'])->toBe('-17000.00')
        ->and($d['results']['ebt'])->toBe('383000.00') // EBIT − SÓLO el interés (nunca el pago de 180,000)
        ->and($d['completeness']['ebt']['complete'])->toBeTrue();

    $fin = collect($d['statement'])->firstWhere('key', 'financiero');
    $line = collect($fin['lines'])->firstWhere('key', 'adj_intereses_lerom');
    expect($line['label'])->toBe('Intereses LEROM')
        ->and($line['amount'])->toBe('-17000.00')
        ->and($line['source'])->toBe('Tabla de amortización')
        ->and($line['hint'])->toContain('capital excluido');

    // Sólo afecta el consolidado: la sucursal no recibe intereses.
    $branch = app(EstadoResultadosDatasetBuilder::class)->build($p, 'CORDOBA');
    expect(gfAdj($branch, 'intereses_lerom')['state'])->toBe(AdjustmentService::SIN_INFORMACION)
        ->and(gfAdj($branch, 'intereses_lerom')['origin'])->toBeNull();
});

it('la captura manual GANA sobre la tabla y se muestra la diferencia', function () {
    $p = gfPeriod(['intereses_lerom']);
    gfImport('lerom', gfXlsx(gfLeromRows()));
    app(AdjustmentService::class)->save($p, 'CONSOLIDADO', 'intereses_lerom', AdjustmentService::CAPTURADO, '18000', 'Según contabilidad', null);

    $d = app(EstadoResultadosDatasetBuilder::class)->build($p);
    $lerom = gfAdj($d, 'intereses_lerom');
    expect($lerom['amount'])->toBe('18000.00')
        ->and($lerom['origin'])->toBe(FinancialExpenseService::ORIGIN_MANUAL)
        ->and($lerom['amortization']['interest'])->toBe('17000.00')
        ->and($lerom['amortization']['difference'])->toBe('1000.00')
        ->and($lerom['amortization']['applied'])->toBeFalse()
        ->and($d['results']['ebt'])->toBe('382000.00');

    $line = collect(collect($d['statement'])->firstWhere('key', 'financiero')['lines'])->firstWhere('key', 'adj_intereses_lerom');
    expect($line['source'])->toBe('Ajuste contable')
        ->and($line['hint'])->toContain('Difiere de la tabla de amortización en $1,000.00');

    $panel = app(FinancialExpenseService::class)->panel($p);
    $c = collect($panel['credits'])->firstWhere('credit.key', 'lerom');
    expect($c['adjustment']['origin'])->toBe('manual')->and($c['adjustment']['difference'])->toBe('1000.00');
});

it('sin tabla ni captura los intereses siguen «sin información» (nunca $0); una tabla que no cubre el periodo tampoco inventa $0', function () {
    $p = gfPeriod();
    $d = app(EstadoResultadosDatasetBuilder::class)->build($p);
    expect(gfAdj($d, 'intereses_bbva')['state'])->toBe(AdjustmentService::SIN_INFORMACION)
        ->and(gfAdj($d, 'intereses_bbva')['amount'])->toBeNull()
        ->and(gfAdj($d, 'intereses_lerom')['state'])->toBe(AdjustmentService::SIN_INFORMACION)
        ->and($d['completeness']['ebt']['complete'])->toBeFalse()
        ->and($d['completeness']['ebt']['missing'])->toContain('Intereses bancarios BBVA', 'Intereses LEROM');

    // Tabla de BBVA que termina antes del periodo: no cubre ⇒ sigue sin información.
    gfImport('bbva', gfXlsx([[1, '2026-06-15', 70000, 50000, 20000, null, 100000], [2, '2026-07-15', 70000, 51000, 19000, null, 49000]], ['No.', 'Fecha de pago', 'Mensualidad', 'Capital', 'Interés', 'IVA', 'Saldo'], false));
    $d = app(EstadoResultadosDatasetBuilder::class)->build($p);
    expect(gfAdj($d, 'intereses_bbva')['state'])->toBe(AdjustmentService::SIN_INFORMACION)
        ->and(gfAdj($d, 'intereses_bbva')['amortization']['covered'])->toBeFalse();
});

it('BBVA mensual: un periodo cubierto por la tabla sin pago en sus fechas queda en $0.00 explicado', function () {
    $p = gfPeriod(['intereses_bbva']);
    gfImport('bbva', gfXlsx([[1, '2026-08-30', 70000, 50000, 20000, null, 100000], [2, '2026-09-29', 70000, 51000, 19000, null, 49000]], ['No.', 'Fecha de pago', 'Mensualidad', 'Capital', 'Interés', 'IVA', 'Saldo'], false));
    $bbva = gfAdj(app(EstadoResultadosDatasetBuilder::class)->build($p), 'intereses_bbva');
    expect($bbva['state'])->toBe(AdjustmentService::CAPTURADO)
        ->and($bbva['amount'])->toBe('0.00')
        ->and($bbva['notes'])->toContain('sin pagos con fecha dentro del periodo');
});

it('rechaza con errores por fila legibles si falta la fecha o el interés (y no guarda nada)', function () {
    $rows = gfLeromRows();
    $rows[2][1] = null;       // sin fecha
    $rows[3][4] = null;       // sin interés
    $rows[4][4] = 'abc';      // interés no numérico
    try {
        gfImport('lerom', gfXlsx($rows));
        $this->fail('Debió rechazarse');
    } catch (EstadoResultadosImportException $e) {
        $errors = $e->errors();
        expect($e->getMessage())->toContain('3 error(es)')
            ->and(collect($errors)->pluck('row')->all())->toBe([7, 8, 9])
            ->and($errors[0]['message'])->toContain('falta la fecha de pago')
            ->and($errors[0]['column'])->toBe('B')
            ->and($errors[1]['message'])->toContain('falta el importe de interés')
            ->and($errors[2]['message'])->toContain('no es un importe válido');
    }
    expect(EstadoResultadosAmortizationUpload::query()->count())->toBe(0)
        ->and(EstadoResultadosAmortizationRow::query()->count())->toBe(0);

    // Sin columna de interés ⇒ no se reconoce el encabezado.
    expect(fn () => gfImport('lerom', gfXlsx([[1, '2026-09-04', 45000, 40000]], ['No.', 'Fecha', 'Pago', 'Capital'], false)))
        ->toThrow(EstadoResultadosImportException::class, 'No se encontró la fila de encabezados');
});

it('avisa (sin rechazar) cuando interés + capital (+ IVA) no suma el pago', function () {
    $rows = gfLeromRows();
    $rows[1][2] = 46000.00; // pago no cuadra por $1,000
    $u = gfImport('lerom', gfXlsx($rows))['upload'];
    expect(collect($u->warnings)->pluck('code')->all())->toContain('pago_no_cuadra')
        ->and(collect($u->warnings)->firstWhere('code', 'pago_no_cuadra')['message'])->toContain('fila 6');
});

it('acepta CSV y deduce el capital cuando sólo trae pago e interés', function () {
    $dir = storage_path('framework/testing/er');
    is_dir($dir) || mkdir($dir, 0775, true);
    $path = $dir.'/'.uniqid('amort_', true).'.csv';
    file_put_contents($path, "Pago #;Fecha;Pago total;Interés ordinario\n1;04/09/2026;45000.00;4400.00\n2;11/09/2026;45000.00;4300.00\n");
    $u = gfImport('lerom', $path, 'tabla.csv')['upload'];
    expect($u->rows_count)->toBe(2)
        ->and($u->total_principal)->toBe('81300.00')
        ->and(collect($u->warnings)->pluck('code')->all())->toContain('capital_deducido');
});

it('cruza contra el banco: pagos esperados según la tabla vs. pagos «CREDITO LEROM» encontrados (sin meterlos como gasto)', function () {
    $p = gfPeriod(['intereses_lerom']);
    G::import($p, F::adminXlsx([
        ['2026-09-05', 45000.00, null, 'S/R', 'PAGO CREDITO LEROM', 'CORPORATIVO', 'LEROM'],
        ['2026-09-12', 45000.00, null, 'S/R', 'PAGO CREDITO LEROM', 'CORPORATIVO', 'LEROM'],
        ['2026-09-20', 30000.00, null, 'S/R', 'PAGO CREDITO LEROM', 'CORPORATIVO', 'LEROM'],
    ]));
    gfImport('lerom', gfXlsx(gfLeromRows()));

    $admin = G::admin();
    $panel = $this->actingAs($admin)->getJson("/estado-resultados/{$p->id}/gastos-financieros")->assertOk()->json();
    $c = collect($panel['credits'])->firstWhere('credit.key', 'lerom');
    expect($c['totals'])->toMatchArray(['count' => 4, 'payment' => '180000.00', 'principal' => '160280.00', 'interest' => '17000.00'])
        ->and($c['bank']['totals'])->toMatchArray(['expected_count' => 4, 'found_count' => 3, 'matched_count' => 2, 'expected' => '180000.00', 'found' => '120000.00', 'difference' => '-60000.00'])
        ->and(collect($c['bank']['expected'])->pluck('status')->all())->toBe(['encontrado', 'encontrado', 'no_encontrado', 'no_encontrado'])
        ->and($c['bank']['expected'][0]['match']['days'])->toBe(1)
        ->and(count($c['bank']['unmatched_bank']))->toBe(1)
        ->and($panel['totals']['interest'])->toBe('17000.00');

    // El pago bancario completo NO entra como gasto financiero (sigue fuera del ER).
    app(EstadoResultadosProcessor::class)->process($p);
    $d = app(EstadoResultadosDatasetBuilder::class)->build($p);
    expect($d['results']['resultado_financiero_neto'])->toBe('-17000.00')
        ->and(collect($d['movements'])->where('concept', 'PAGO CREDITO LEROM')->pluck('treatment')->unique()->values()->all())->toBe(['capital_deuda']);
});

it('subir la tabla exige el permiso de ajustes y alcance general; la plantilla descargable se puede volver a importar', function () {
    $sinPermiso = G::userWith(['cargar']);
    $file = new UploadedFile(gfXlsx(gfLeromRows()), 'tabla.xlsx', null, null, true);
    $this->actingAs($sinPermiso)->post('/estado-resultados/creditos/lerom/amortizacion', ['file' => $file], ['Accept' => 'application/json'])->assertForbidden();

    $admin = G::admin();
    $this->actingAs($admin)->post('/estado-resultados/creditos/lerom/amortizacion', ['file' => new UploadedFile(gfXlsx(gfLeromRows()), 'tabla.xlsx', null, null, true)], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('upload.rows', 6)->assertJsonPath('idempotent', false);
    $this->actingAs($admin)->post('/estado-resultados/creditos/nada/amortizacion', ['file' => new UploadedFile(gfXlsx(gfLeromRows()), 'tabla.xlsx', null, null, true)], ['Accept' => 'application/json'])->assertNotFound();

    $res = $this->actingAs($admin)->get('/estado-resultados/creditos/plantilla-amortizacion?credito=bbva')->assertOk();
    $tmp = storage_path('framework/testing/er/'.uniqid('tpl_', true).'.xlsx');
    copy($res->baseResponse->getFile()->getPathname(), $tmp);
    $u = gfImport('bbva', $tmp, 'plantilla.xlsx')['upload'];
    expect($u->rows_count)->toBe(8)->and($u->warnings)->toBe([]);
});

it('periodo aprobado: los intereses quedan congelados; una tabla nueva cambia la firma y el periodo se marca desactualizado', function () {
    F::fakeRadiography();
    G::fakePdfRenderer();
    $period = G::readyPeriod(); // 01-sep..30-sep, ajustes confirmados en $0
    DB::table('estado_resultados_adjustments')->where('period_id', $period->id)->where('scope_key', 'CONSOLIDADO')->where('concept', 'intereses_lerom')->delete();
    gfImport('lerom', gfXlsx(gfLeromRows()));
    $admin = G::admin();

    $live = $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/dataset")->assertOk()->json('dataset');
    expect(gfAdj($live, 'intereses_lerom')['amount'])->toBe('17000.00');
    $sigBefore = app(ApprovalInputSignature::class)->compute($period);

    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/aprobar", ['expected_hash' => $live['meta']['dataset_hash']])->assertOk();

    // Tabla sustituida después de aprobar (otro interés en septiembre).
    $rows = gfLeromRows();
    $rows[2][4] = 5300.00;
    $rows[2][3] = 39012.00;
    gfImport('lerom', gfXlsx($rows), 'tabla_v2.xlsx');

    $after = $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/dataset")->assertOk()->json('dataset');
    expect($after['meta']['report']['source'])->toBe('snapshot')
        ->and(gfAdj($after, 'intereses_lerom')['amount'])->toBe('17000.00')
        ->and($after['results'])->toBe($live['results']);

    $sigAfter = app(ApprovalInputSignature::class)->compute($period);
    expect(ApprovalInputSignature::diff($sigBefore, $sigAfter))->toContain('amortization');

    $panel = $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/gastos-financieros")->assertOk()->json();
    $c = collect($panel['credits'])->firstWhere('credit.key', 'lerom');
    expect($panel['locked']['version'])->toBe(1)
        ->and($c['frozen'])->toMatchArray(['version' => 1, 'amount' => '17000.00', 'live_amount' => '18000.00', 'outdated' => true]);
});
