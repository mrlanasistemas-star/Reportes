<?php

use App\Models\EstadoResultados\EstadoResultadosCredit;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Export\EstadoResultadosExcelExporter;
use App\Services\EstadoResultados\FinancialExpenses\AmortizationUploadService;
use App\Services\EstadoResultados\FinancialExpenses\FinancialExpenseService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\EstadoResultadosFixtures as F;

/**
 * Hoja «Financiamientos» del Excel del ER: cifras tomadas de FinancialExpenseService (fuente
 * única), sólo en el consolidado. Datos 100 % SINTÉTICOS.
 */
it('el Excel consolidado trae la hoja «Financiamientos» con los totales del servicio; la de sucursal no', function () {
    config(['estado_resultados.gastos_financieros.comisiones_en_resultado_financiero' => true]);
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
    $p = F::period('2026-09-01', '2026-09-28');
    F::fakeRadiographyByPeriod([$p->id => ['CORDOBA' => [
        'interes_recuperado' => 1000000.00, 'gastos_operativos' => 400000.00, 'gastos_detalle' => ['Renta Oficina' => 400000.00], 'nomina_total' => 200000.00,
    ]]]);
    foreach (array_keys(AdjustmentService::CONCEPTS) as $concept) {
        if (! in_array($concept, ['intereses_lerom', 'comisiones_financieras'], true)) {
            app(AdjustmentService::class)->save($p, 'CONSOLIDADO', $concept, AdjustmentService::CONFIRMADO_CERO, null, 'prueba', null);
        }
    }
    $dir = storage_path('framework/testing/er');
    is_dir($dir) || mkdir($dir, 0775, true);
    $csv = $dir.'/'.uniqid('lerom_', true).'.csv';
    $lines = ['Pago,Fecha,Amortización Capital,Importe Comisión,IVA Comisión,Pago Total,Saldo Insoluto'];
    foreach (['31/08/2026', '01/09/2026', '02/09/2026', '03/09/2026', '04/09/2026', '01/10/2026'] as $i => $date) {
        $lines[] = ($i + 1).','.$date.',1000.00,100.00,16.00,1116.00,'.number_format(6696 - 1116 * ($i + 1), 2, '.', '');
    }
    file_put_contents($csv, implode("\n", $lines)."\n");
    app(AmortizationUploadService::class)->importPath(EstadoResultadosCredit::query()->where('key', 'lerom')->firstOrFail(), $csv, 'tabla.csv', null);

    $summary = app(FinancialExpenseService::class)->panel($p)['summary'];
    expect($summary['commission_recognized'])->toBe('400.00')->and($summary['ebt_effect'])->toBe('-400.00');

    $path = $dir.'/fin_'.uniqid().'.xlsx';
    app(EstadoResultadosExcelExporter::class)->export(app(EstadoResultadosDatasetBuilder::class)->build($p), $path);
    $book = IOFactory::load($path);
    $ws = $book->getSheetByName('Financiamientos');
    expect($ws)->not->toBeNull()
        ->and($ws->getCell('B6')->getValue())->toBe('PAGO OBSERVADO')
        ->and($ws->getCell('C7')->getValue())->toBe((float) $summary['principal_informative'])
        ->and($ws->getCell('D7')->getValue())->toBe((float) $summary['interest_recognized'])
        ->and($ws->getCell('E7')->getValue())->toBe((float) $summary['commission_recognized'])
        ->and($ws->getCell('F7')->getValue())->toBe((float) $summary['vat_separated'])
        ->and($ws->getCell('G7')->getValue())->toBe((float) $summary['ebt_effect'])
        ->and($ws->getFreezePane())->toBe('C10')
        ->and($ws->getAutoFilter()->getRange())->toStartWith('B9:O');
    // Fila de LEROM (valores numéricos reales) y gráfica nativa que apunta a la hoja.
    expect($ws->getCell('B10')->getValue())->toContain('LEROM')
        ->and($ws->getCell('K10')->getValue())->toBe(400.0);
    $zip = new ZipArchive;
    $zip->open($path);
    $refs = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (preg_match('#^xl/charts/chart\d+\.xml$#', $zip->getNameIndex($i))) {
            $refs .= $zip->getFromIndex($i);
        }
    }
    $zip->close();
    expect($refs)->toContain('Financiamientos');
    // El índice liga a la hoja nueva.
    $links = array_map(fn ($l) => $l->getUrl(), $book->getSheetByName('Índice')->getHyperlinkCollection());
    expect(implode(' ', $links))->toContain("'Financiamientos'");

    // Alcance de sucursal: sin hoja de financiamientos (los créditos son del consolidado).
    $branchPath = $dir.'/fin_suc_'.uniqid().'.xlsx';
    app(EstadoResultadosExcelExporter::class)->export(app(EstadoResultadosDatasetBuilder::class)->build($p, 'CORDOBA'), $branchPath);
    expect(IOFactory::load($branchPath)->getSheetByName('Financiamientos'))->toBeNull();
    @unlink($path);
    @unlink($branchPath);
    @unlink($csv);
});
