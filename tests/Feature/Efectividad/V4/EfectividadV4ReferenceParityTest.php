<?php

use App\Models\PortfolioSnapshot;
use App\Services\Efectividad\V4\EfectividadV4Calculator;
use App\Services\Efectividad\V4\EfectividadV4DataAdapter;
use App\Services\Efectividad\V4\EfectividadV4ExcelExporter;
use App\Services\Efectividad\V4\EfectividadV4ParityComparator;
use App\Services\ReportAnalysisService;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\EfectividadV4Reference as Ref;

/**
 * PARIDAD contra el libro de referencia MR_LANA_EFECTIVIDAD.xlsm (hoja
 * EFECTIVIDAD ya calculada por la macro V4 + bitácora _AUDITORIA_EFECTIVIDAD).
 *
 * Valores esperados = los del libro, sin modificar. Tolerancias explícitas en
 * EfectividadV4ParityComparator (1e-6 en montos, 1e-12 en porcentajes); el
 * resultado actual es IGUALDAD EXACTA en los 372 valores.
 *
 * El libro tiene datos personales y no se versiona: sin él, estas pruebas se omiten.
 */
beforeEach(function () {
    if (! Ref::available()) {
        $this->markTestSkipped('No está '.Ref::PATH.' (libro de referencia local, no versionado).');
    }
});

it('reproduce exactamente la hoja EFECTIVIDAD y la auditoría del libro (motor puro, 8 fotografías)', function () {
    $ref = Ref::data();
    $c = $ref['control'];

    expect($c['modo'])->toBe('SEMANAL')
        ->and($c['domingo_base'])->toBe('2026-10-04')
        ->and($c['fecha_corte'])->toBe('2026-10-11')
        ->and($c['inicio_ingresos'])->toBe('2026-10-05')
        ->and($c['fin_ingresos'])->toBe('2026-10-11');

    $res = (new EfectividadV4Calculator)->calculate(
        ['modo' => $c['modo'], 'domingo_base' => $c['domingo_base'], 'fecha_corte' => $c['fecha_corte']],
        $ref['fotos'], $ref['ingresos'], $ref['historial'],
    );

    expect($res['parametros']['inicio_ingresos'])->toBe($c['inicio_ingresos'])
        ->and($res['parametros']['fin_ingresos'])->toBe($c['fin_ingresos']);

    $cmp = (new EfectividadV4ParityComparator)->compare($ref['esperado'], $res['filas'], $ref['incidencias_esperadas'], $res['incidencias']);

    $diferentes = array_values(array_filter($cmp['filas'], fn ($f) => ! str_starts_with($f['estado'], 'IGUAL')));
    expect($diferentes)->toBe([])
        ->and($cmp['resumen']['filas_excel'])->toBe(31)
        ->and($cmp['resumen']['valores_comparados'])->toBe(372)
        ->and($cmp['resumen']['valores_exactos'])->toBe(372)
        ->and($cmp['resumen']['mismo_orden'])->toBeTrue()
        ->and($cmp['incidencias']['excel'])->toBe(237)
        ->and($cmp['incidencias']['solo_excel'])->toBe([])
        ->and($cmp['incidencias']['solo_laravel'])->toBe([])
        ->and($cmp['incidencias']['texto_distinto'])->toBe([])
        ->and($cmp['incidencias']['mismo_orden'])->toBeTrue();

    // Fila de control citada en el requerimiento (20 DE NOVIEMBRE, primer promotor).
    $fila = collect($res['filas'])->firstWhere('promotor', 'JOSE ALFREDO JIMENEZ FLORES');
    expect($fila['sano_pc'])->toBe(54058.0)
        ->and($fila['sano_cob'])->toBe(48817.32)
        ->and($fila['sano_pct'])->toBe(0.9030544970217174)
        ->and($fila['atraso_pc'])->toBe(7339.5)
        ->and($fila['atraso_cob'])->toBe(4809.5)
        ->and($fila['atraso_pct'])->toBe(0.08896925524436716)
        ->and($fila['vencido_pc'])->toBe(56811.98000000001)
        ->and($fila['vencido_cob'])->toBe(0.0)
        ->and($fila['cobranza_total'])->toBe(53626.82)
        ->and($fila['pct_total'])->toBe(0.9920237522660845)
        ->and($fila['mora'])->toBe(431.1800000000003);

    // El XLSX exportado conserva bit a bit las 31 filas (A-M) y las 237 incidencias, en orden.
    $path = tempnam(sys_get_temp_dir(), 'efv4ref').'.xlsx';
    (new EfectividadV4ExcelExporter)->export($res['filas'], $res['parametros'], $res['incidencias'], $path);
    $book = IOFactory::load($path);
    @unlink($path);
    $ws = $book->getSheetByName('EFECTIVIDAD');
    $campos = ['sano_pc', 'sano_cob', 'sano_pct', 'atraso_pc', 'atraso_cob', 'atraso_pct', 'vencido_pc', 'vencido_cob', 'vencido_pct', 'cobranza_total', 'pct_total', 'mora'];
    $leidas = [];
    for ($r = 6; $r <= $ws->getHighestDataRow('A'); $r++) {
        if (! isset($ws->getMergeCells()["A{$r}:M{$r}"])) {
            $leidas[] = [$ws->getCell("A{$r}")->getValue(), ...array_map(fn ($j) => (float) $ws->getCell(chr(ord('B') + $j).$r)->getValue(), array_keys($campos))];
        }
    }
    $esperadas = array_map(fn ($f) => [$f['promotor'], ...array_map(fn ($c) => (float) $f[$c], $campos)], $res['filas']);
    expect($leidas)->toBe($esperadas)->and(count($leidas))->toBe(31);
    $aud = $book->getSheetByName('_AUDITORIA_EFECTIVIDAD');
    expect($aud->getHighestDataRow('A') - 5)->toBe(237)
        ->and(array_map(fn ($r) => $aud->getCell("A{$r}")->getValue(), range(6, 242)))->toBe(array_column($ref['incidencias_esperadas'], 'id'));
})->group('referencia');

it('reproduce el libro de punta a punta: Excel de Lendus → importadores → BD → adaptador → motor', function () {
    Storage::fake('public');
    Storage::disk('public')->makeDirectory('reports');
    $ref = Ref::data();
    $p = Ref::periods();

    // 8 fotografías con sus 156 columnas originales y 4 filas de título (como el exportado de Lendus).
    foreach (EfectividadV4Calculator::NOMBRES_FOTO as $i => $nombre) {
        $fecha = date('Y-m-d', strtotime('2026-10-04 +'.$i.' days'));
        $rel = "reports/foto_{$fecha}.xlsx";
        Ref::writeXlsx($ref['crudo'][$nombre], Storage::disk('public')->path($rel), 4);
        $upload = Ref::upload('lendus_saldos_cliente_foto', $i === 0 ? $p['prev'] : $p['week'], $rel, $fecha);
        app(ReportAnalysisService::class)->analyze($upload);
    }
    expect(PortfolioSnapshot::query()->active()->count())->toBe(8);
    foreach (EfectividadV4Calculator::NOMBRES_FOTO as $i => $nombre) {
        expect(PortfolioSnapshot::query()->active()->whereDate('snapshot_date', date('Y-m-d', strtotime('2026-10-04 +'.$i.' days')))->value('rows_count'))
            ->toBe($ref['inventario'][$nombre]['filas']);
    }

    // _HIST_INGRESOS completo (semana previa + semana del corte), 35 columnas, 3 filas de título.
    $rel = 'reports/ingresos_cobranza.xlsx';
    Ref::writeXlsx($ref['crudo']['_HIST_INGRESOS'], Storage::disk('public')->path($rel), 3);
    $run = app(ReportAnalysisService::class)->analyze(Ref::upload('lendus_ingresos_cobranza', $p['month'], $rel));
    expect($run->rows_inserted)->toBe(7628);

    $adapter = app(EfectividadV4DataAdapter::class);
    $disp = $adapter->availability($p['week'], null);
    expect($disp['fotos_disponibles'])->toBe(8)
        ->and($disp['bloqueante'])->toBeFalse()
        ->and($disp['requiere_confirmacion'])->toBeFalse()
        ->and($disp['ingresos']['movimientos_corte'])->toBe(2822)
        ->and($disp['ingresos']['dias_con_movimientos'])->toBe(7);

    $in = $adapter->build($p['week'], null);
    $res = (new EfectividadV4Calculator)->calculate($in['parametros'], $in['fotos'], $in['ingresos'], $in['historial']);

    $cmp = (new EfectividadV4ParityComparator)->compare($ref['esperado'], $res['filas']);
    $diferentes = array_values(array_filter($cmp['filas'], fn ($f) => ! str_starts_with($f['estado'], 'IGUAL')));
    expect($diferentes)->toBe([])
        ->and($cmp['resumen']['valores_exactos'])->toBe(372)
        ->and($cmp['resumen']['mismo_orden'])->toBeTrue();

    // Incidencias: mismo código, contrato y orden. La referencia de la macro es la fila de
    // _HIST_INGRESOS; en Reportes es el id del movimiento importado (fila − 1 en esta carga).
    $esperadas = array_map(fn ($i) => explode('|', $i['id']), $ref['incidencias_esperadas']);
    $obtenidas = array_map(fn ($i) => [$i['codigo'], $i['contrato'], (string) ((int) $i['referencia'] + 1)], $res['incidencias']);
    expect($obtenidas)->toBe($esperadas);
})->group('referencia');
