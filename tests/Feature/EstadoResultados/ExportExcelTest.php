<?php

use App\Models\Period;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Export\EstadoResultadosExcelExporter;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * Excel del Estado de Resultados (modelo de EBITDA único). Datos 100 % SINTÉTICOS:
 * caso de referencia del dueño — ingresos 1,000,000 · OPEX 600,000 → EBITDA 400,000 ·
 * D&A 30,000 → EBIT 370,000 · RFN −30,000 → EBT 340,000 · ISR 100,000 → utilidad neta 240,000.
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('ORIZABA');
    F::branch('CORPORATIVO');
});

/** Agosto (anterior) y Septiembre (referencia) con dos sucursales; Sept = caso de referencia. */
function exRefPeriods(bool $isrPending = false): array
{
    $ago = F::monthPeriod(2026, 8, '2026-08-02', '2026-08-31');
    $sep = F::monthPeriod(2026, 9, '2026-09-01', '2026-09-30');
    F::fakeRadiographyByPeriod([
        $ago->id => [
            'CORDOBA' => ['interes_recuperado' => 500000.00, 'gastos_operativos' => 200000.00, 'gastos_detalle' => ['Renta Oficina' => 200000.00], 'nomina_total' => 100000.00],
            'ORIZABA' => ['interes_recuperado' => 300000.00, 'gastos_operativos' => 150000.00, 'gastos_detalle' => ['Renta Oficina' => 150000.00], 'nomina_total' => 50000.00],
        ],
        $sep->id => [
            'CORDOBA' => ['interes_recuperado' => 600000.00, 'gastos_operativos' => 250000.00, 'gastos_detalle' => ['Renta Oficina' => 150000.00, 'Gasolina' => 100000.00], 'nomina_total' => 120000.00],
            'ORIZABA' => ['interes_recuperado' => 400000.00, 'gastos_operativos' => 150000.00, 'gastos_detalle' => ['Renta Oficina' => 150000.00], 'nomina_total' => 80000.00],
        ],
    ]);
    exAdjust($ago, ['depreciacion' => '30000', 'intereses_bbva' => '20000', 'isr' => '50000']);
    exAdjust($sep, ['depreciacion' => '30000', 'intereses_bbva' => '30000', 'isr' => '100000'], $isrPending ? ['isr'] : []);

    return [$ago, $sep];
}

function exAdjust(Period $p, array $captured, array $pending = []): void
{
    foreach (array_keys(AdjustmentService::CONCEPTS) as $concept) {
        if (in_array($concept, $pending, true)) {
            continue;
        }
        isset($captured[$concept])
            ? app(AdjustmentService::class)->save($p, 'CONSOLIDADO', $concept, AdjustmentService::CAPTURADO, $captured[$concept], 'prueba', null)
            : app(AdjustmentService::class)->save($p, 'CONSOLIDADO', $concept, AdjustmentService::CONFIRMADO_CERO, null, 'prueba', null);
    }
}

/** Fila (B = etiqueta) → valor calculado de la columna indicada. */
function exRow($ws, string $label, string $col = 'C'): mixed
{
    foreach ($ws->getRowIterator() as $row) {
        $r = $row->getRowIndex();
        $l = trim((string) $ws->getCell("B{$r}")->getValue());
        if ($l === $label || str_starts_with($l, $label.'  (')) {
            return $ws->getCell("{$col}{$r}")->getCalculatedValue();
        }
    }

    return '__no_encontrado__';
}

function exCellOf($ws, string $label, string $col = 'C'): ?string
{
    foreach ($ws->getRowIterator() as $row) {
        $r = $row->getRowIndex();
        if (trim((string) $ws->getCell("B{$r}")->getValue()) === $label) {
            return "{$col}{$r}";
        }
    }

    return null;
}

/** Recalcula TODAS las fórmulas del libro con el motor de PhpSpreadsheet: ninguna debe dar error. */
function exFormulaErrors(Spreadsheet $book): array
{
    Calculation::getInstance($book)->clearCalculationCache();
    $errors = [];
    $count = 0;
    foreach ($book->getWorksheetIterator() as $ws) {
        foreach ($ws->getCellCollection()->getCoordinates() as $coord) {
            $cell = $ws->getCell($coord);
            if ($cell->getDataType() !== DataType::TYPE_FORMULA) {
                continue;
            }
            $count++;
            $v = $cell->getCalculatedValue();
            if (is_string($v) && preg_match('/^#(REF!|VALUE!|DIV\/0!|NAME\?|N\/A|NUM!|NULL!)/', $v)) {
                $errors[] = $ws->getTitle().'!'.$coord.' '.$cell->getValue().' → '.$v;
            }
        }
    }

    return [$errors, $count];
}

/** Gráficas del .xlsx (xl/charts/chartN.xml) y sus referencias de series. */
function exCharts(string $path): array
{
    $zip = new ZipArchive;
    $zip->open($path);
    $charts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('#^xl/charts/chart\d+\.xml$#', $name)) {
            $xml = $zip->getFromName($name);
            preg_match_all('#<c:f>([^<]+)</c:f>#', $xml, $m);
            $charts[$name] = array_map(fn ($f) => html_entity_decode($f, ENT_QUOTES | ENT_XML1), $m[1]);
        }
    }
    $zip->close();

    return $charts;
}

it('caso de referencia: el Excel (valores y fórmulas recalculadas) es idéntico al dataset y a la web', function () {
    [, $sep] = exRefPeriods();
    $admin = G::admin();

    $web = $this->actingAs($admin)->getJson("/estado-resultados/{$sep->id}/dataset")->assertOk()->json('dataset');
    $built = app(EstadoResultadosDatasetBuilder::class)->build($sep);
    $expected = [
        'ingresos_operativos' => '1000000.00', 'opex_total' => '600000.00', 'ebitda' => '400000.00',
        'depreciacion_amortizacion' => '30000.00', 'ebit' => '370000.00', 'resultado_financiero_neto' => '-30000.00',
        'ebt' => '340000.00', 'isr' => '100000.00', 'utilidad_neta' => '240000.00',
    ];
    expect($built['results'])->toBe($expected)->and($web['results'])->toBe($expected)
        ->and($web['meta']['dataset_hash'])->toBe($built['meta']['dataset_hash']);

    $res = $this->actingAs($admin)->get("/estado-resultados/{$sep->id}/exportar/excel")->assertOk()
        ->assertHeader('X-Dataset-Hash', $web['meta']['dataset_hash']);
    $path = $res->getFile()->getPathname();
    $book = IOFactory::load($path);

    // Hojas en el orden pedido (y anexos después).
    expect(array_slice($book->getSheetNames(), 0, 6))->toBe(array_values(EstadoResultadosExcelExporter::SHEETS))
        ->and($book->getSheetNames())->toContain('Anexo Movimientos', 'Anexo Ajustes');

    // Hoja 2: subtotales y resultados son FÓRMULAS y su valor recalculado = dataset.
    $er = $book->getSheetByName('Estado de Resultados');
    $map = ['TOTAL INGRESOS OPERATIVOS' => 'ingresos_operativos', 'TOTAL OPEX' => 'opex_total', 'EBITDA' => 'ebitda', 'TOTAL D&A' => 'depreciacion_amortizacion',
        'EBIT' => 'ebit', 'RESULTADO FINANCIERO NETO' => 'resultado_financiero_neto', 'EBT · UTILIDAD ANTES DE IMPUESTOS' => 'ebt', 'TOTAL ISR' => 'isr', 'UTILIDAD NETA' => 'utilidad_neta'];
    foreach ($map as $label => $key) {
        $cell = exCellOf($er, $label);
        expect($cell)->not->toBeNull($label)
            ->and($er->getCell($cell)->getDataType())->toBe(DataType::TYPE_FORMULA, $label)
            ->and(number_format((float) $er->getCell($cell)->getCalculatedValue(), 2, '.', ''))->toBe($expected[$key], $label)
            ->and((float) $er->getCell('E'.substr($cell, 1))->getCalculatedValue())->toBe(0.0, 'control '.$label);
    }
    $ebitda = exCellOf($er, 'EBITDA');
    expect($er->getCell($ebitda)->getValue())->toBe('='.exCellOf($er, 'TOTAL INGRESOS OPERATIVOS').'-'.exCellOf($er, 'TOTAL OPEX'))
        ->and($er->getCell(exCellOf($er, 'UTILIDAD NETA'))->getValue())->toContain('ISNUMBER(');

    // Hoja 1: lee el ER con fórmulas; márgenes y variación contra Agosto (EBITDA ago = 800,000 − 500,000 = 300,000).
    $sum = $book->getSheetByName('Resumen ejecutivo');
    expect((float) exRow($sum, '3. EBITDA'))->toBe(400000.0)
        ->and((float) exRow($sum, '9. Utilidad neta'))->toBe(240000.0)
        ->and((float) exRow($sum, '3. EBITDA', 'D'))->toBe(300000.0)
        ->and((float) exRow($sum, '3. EBITDA', 'E'))->toBe(100000.0)
        ->and(round((float) exRow($sum, '3. EBITDA', 'F'), 4))->toBe(0.3333)
        ->and(exRow($sum, '3. EBITDA', 'G'))->toBe('Favorable')
        ->and(round((float) exRow($sum, 'Margen EBITDA'), 4))->toBe(0.4)
        ->and(round((float) exRow($sum, 'Margen neto'), 4))->toBe(0.24);

    // Comparativa y evolución con las cifras de ComparisonService.
    $cmp = $book->getSheetByName('Comparativa mensual');
    expect((float) exRow($cmp, 'EBITDA'))->toBe(400000.0)->and((float) exRow($cmp, 'EBITDA', 'D'))->toBe(300000.0);
    $evo = $book->getSheetByName('Evolución histórica');
    $evoRows = collect($evo->toArray(null, true, false, false))->filter(fn ($r) => in_array($r[1] ?? null, [$sep->label, Period::find($sep->id - 1)?->label], true));
    expect($evoRows)->not->toBeEmpty();

    // Sucursales: Corporativo/DG aparte, nunca asignados; Σ sucursales con fórmula.
    $br = $book->getSheetByName('Sucursales');
    expect((float) exRow($br, 'Sucursal CORDOBA', 'F'))->toBe(230000.0)
        ->and((float) exRow($br, 'Sucursal ORIZABA', 'F'))->toBe(170000.0)
        ->and((float) exRow($br, 'Total sucursales', 'F'))->toBe(400000.0);

    // Trazabilidad: hash y versión.
    expect($book->getSheetByName('Conciliación y trazabilidad')->getCell('C6')->getValue())->toBe($web['meta']['dataset_hash']);

    // Ninguna fórmula del libro da error al recalcular.
    [$errors, $count] = exFormulaErrors($book);
    expect($errors)->toBe([])->and($count)->toBeGreaterThan(30);

    // Paneles fijos y autofiltros.
    foreach (EstadoResultadosExcelExporter::SHEETS as $title) {
        expect($book->getSheetByName($title)->getFreezePane())->not->toBeNull($title);
    }
    expect($book->getSheetByName('Evolución histórica')->getAutoFilter()->getRange())->not->toBe('')
        ->and($book->getSheetByName('Anexo Movimientos')->getAutoFilter()->getRange())->not->toBe('');

    // Gráficas nativas: existen y cada serie apunta a una hoja real del libro con un rango válido.
    $charts = exCharts($path);
    expect(count($charts))->toBeGreaterThanOrEqual(9);
    foreach ($charts as $name => $refs) {
        expect($refs)->not->toBeEmpty($name);
        foreach ($refs as $ref) {
            expect((bool) preg_match("/^'(.+)'!\\$([A-Z]+)\\$(\\d+)(:\\$([A-Z]+)\\$(\\d+))?$/u", $ref, $m))->toBeTrue($ref);
            expect($book->getSheetByName(str_replace("''", "'", $m[1])))->not->toBeNull($ref);
        }
    }
});

it('ISR sin información: «Pendiente» en el Excel (nunca $0.00) y la utilidad neta también queda pendiente', function () {
    [, $sep] = exRefPeriods(isrPending: true);
    $path = storage_path('framework/testing/er/ex_isr_'.uniqid().'.xlsx');
    $d = app(EstadoResultadosDatasetBuilder::class)->build($sep);
    expect($d['results']['isr'])->toBeNull()->and($d['results']['utilidad_neta'])->toBeNull();

    app(EstadoResultadosExcelExporter::class)->export($d, $path);
    $book = IOFactory::load($path);
    $er = $book->getSheetByName('Estado de Resultados');
    $sum = $book->getSheetByName('Resumen ejecutivo');

    expect(exRow($er, 'TOTAL ISR'))->toBe('Pendiente')
        ->and(exRow($er, 'UTILIDAD NETA'))->toBe('Pendiente')
        ->and((float) exRow($er, 'EBT · UTILIDAD ANTES DE IMPUESTOS'))->toBe(340000.0)
        ->and(exRow($sum, '8. ISR'))->toBe('Pendiente')
        ->and(exRow($sum, '9. Utilidad neta'))->toBe('Pendiente')
        ->and(exRow($sum, 'Margen neto'))->toBe('Pendiente');
    [$errors] = exFormulaErrors($book);
    expect($errors)->toBe([]);
    // Sin comparativas (llamada directa al exportador): aviso claro, no cifras inventadas.
    expect((string) $book->getSheetByName('Comparativa mensual')->getCell('B6')->getValue())->toContain('no están disponibles');
    @unlink($path);
});

it('snapshot del modelo anterior (sin meta.model) sigue exportando con aviso de modelo anterior', function () {
    [, $sep] = exRefPeriods();
    $legacy = exLegacyDataset(app(EstadoResultadosDatasetBuilder::class)->build($sep));
    $path = storage_path('framework/testing/er/ex_legacy_'.uniqid().'.xlsx');

    app(EstadoResultadosExcelExporter::class)->export($legacy, $path);
    $book = IOFactory::load($path);
    $er = $book->getSheetByName('Estado de Resultados');
    $sum = $book->getSheetByName('Resumen ejecutivo');

    expect((string) $er->getCell('B5')->getValue())->toContain('modelo anterior')
        ->and((float) exRow($er, 'EBITDA FINAL'))->toBe(400000.0)
        ->and((float) exRow($sum, '3. EBITDA'))->toBe(400000.0)
        // El resultado financiero del modelo anterior venía como gasto (+30,000): se presenta con signo.
        ->and((float) exRow($sum, '6. Resultado financiero y no operativo neto'))->toBe(-30000.0)
        ->and((float) exRow($sum, '7. EBT · utilidad antes de impuestos'))->toBe(340000.0);
    [$errors] = exFormulaErrors($book);
    expect($errors)->toBe([]);
    @unlink($path);
});

/** Convierte un dataset vigente en uno con la forma de un snapshot aprobado del modelo anterior (3 EBITDA). */
function exLegacyDataset(array $d): array
{
    unset($d['meta']['model']);
    $c = $d['controls'];
    $r = $d['results'];
    $d['results'] = [
        'ingresos' => $r['ingresos_operativos'], 'opex_sucursales' => $c['opex_sucursales'], 'nomina_sucursales' => $c['nomina_sucursales'],
        'ebitda_sucursales' => $c['ebitda_sucursales'], 'opex_corporativo' => $c['opex_corporativo'], 'nomina_corporativa' => $c['nomina_corporativa'],
        'ebitda_corporativo' => $c['ebitda_corporativo'], 'dg' => $c['dg'], 'ebitda_final' => $c['ebitda_final'],
        'depreciacion_amortizacion' => $r['depreciacion_amortizacion'], 'ebit' => $r['ebit'],
        'resultado_financiero_neto' => $c['resultado_financiero_neto_gasto'], 'ebt' => $r['ebt'], 'isr' => $r['isr'], 'utilidad_neta' => $r['utilidad_neta'],
    ];
    unset($d['controls']);
    $sec = fn ($key, $n, $title, $lines, $label, $amount) => ['type' => 'section', 'key' => $key, 'number' => $n, 'title' => $title, 'applies' => true, 'note' => null, 'lines' => $lines, 'total' => ['label' => $label, 'amount' => $amount, 'pct' => null]];
    $res = fn ($key, $level, $label, $amount, $formula) => ['type' => 'result', 'key' => $key, 'level' => $level, 'label' => $label, 'amount' => $amount, 'pct' => null, 'formula' => $formula, 'complete' => true, 'missing' => []];
    $byKey = collect($d['statement'])->keyBy('key');
    $cats = collect($byKey['opex']['categories'])->keyBy('key');
    $d['statement'] = [
        $sec('ingresos', '01', 'INGRESOS', $byKey['ingresos']['lines'], 'TOTAL INGRESOS OPERATIVOS', $r['ingresos_operativos']),
        $sec('opex_sucursales', '02', 'OPEX SUCURSALES', $cats['opex_sucursales']['lines'], 'TOTAL OPEX SUCURSALES', $c['opex_sucursales']),
        $sec('nomina_sucursales', '03', 'NÓMINA Y CAPITAL HUMANO', $cats['nomina_sucursales']['lines'], 'TOTAL NÓMINA', $c['nomina_sucursales']),
        $res('ebitda_sucursales', 1, 'EBITDA SUCURSALES', $c['ebitda_sucursales'], 'Ingresos − OPEX − Nómina'),
        $sec('opex_corporativo', '04', 'OPEX CORPORATIVO', [], 'TOTAL OPEX CORPORATIVO', $c['opex_corporativo']),
        $sec('nomina_corporativa', '05', 'NÓMINA CORPORATIVA', [], 'TOTAL NÓMINA CORPORATIVA', $c['nomina_corporativa']),
        $res('ebitda_corporativo', 2, 'EBITDA CORPORATIVO', $c['ebitda_corporativo'], 'EBITDA sucursales − corporativo'),
        $sec('dg', '06', 'DIRECCIÓN GENERAL', [], 'TOTAL DG', $c['dg']),
        $res('ebitda_final', 3, 'EBITDA FINAL', $c['ebitda_final'], 'EBITDA corporativo − DG'),
        $sec('da', '07', 'D&A', $byKey['da']['lines'], 'TOTAL D&A', $r['depreciacion_amortizacion']),
        $res('ebit', 4, 'EBIT', $r['ebit'], 'EBITDA final − D&A'),
        $sec('financiero', '08', 'RESULTADO FINANCIERO', [], 'RESULTADO FINANCIERO NETO', $c['resultado_financiero_neto_gasto']),
        $res('ebt', 5, 'EBT', $r['ebt'], 'EBIT − resultado financiero'),
        $sec('isr', '09', 'ISR', $byKey['isr']['lines'], 'TOTAL ISR', $r['isr']),
        $res('utilidad_neta', 6, 'UTILIDAD NETA', $r['utilidad_neta'], 'EBT − ISR'),
    ];

    return $d;
}
