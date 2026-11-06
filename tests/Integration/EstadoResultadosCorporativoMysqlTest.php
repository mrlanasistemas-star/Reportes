<?php

use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use App\Services\Radiography\BranchRadiographyCalculator as C;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EstadoResultadosFixtures as F;

uses(RefreshDatabase::class);

/**
 * Fixture sintética sobre MySQL real (reportes_test): usa el BranchRadiographyCalculator
 * REAL (sin base falsa). Demuestra que:
 *  - Radiografía sigue excluyendo CORPORATIVO de su OPEX,
 *  - el Estado de Resultados lo incorpora en el nivel 2 sin tocar el nivel 1,
 *  - un gasto ERP de Corporativo pagado en banco se cuenta una sola vez.
 */
it('CORPORATIVO excluido de Radiografía e incluido una sola vez en el Estado de Resultados', function () {
    $period = F::period('2026-09-01', '2026-09-30');
    F::erp($period, 'CORDOBA', 'REQ-S1', '1200.00', '2026-09-03', 'Gasolina', 'GASOLINA');
    F::erp($period, 'CORPORATIVO', 'REQ-C1', '5000.00', '2026-09-10', 'Teléfono e Internet', 'INTERNET');

    $calc = app(C::class);
    $r = $calc->buildBranches($period, [$period->id]);
    $g = $calc->sumGlobal($r['branches'], $r['unassigned']);
    expect(round((float) $g['gastos_operativos'], 2))->toBe(1200.00);   // Radiografía: sólo la sucursal

    app(EstadoResultadosUploadService::class)->importPath($period, F::adminXlsx([
        ['2026-09-10', 5000.00, null, 'REQ-C1', 'REQ SERVICIOS', 'CORPORATIVO'],
    ]), 'banco.xlsx', 'administrativa', null);
    app(EstadoResultadosProcessor::class)->process($period);
    $d = app(EstadoResultadosDatasetBuilder::class)->build($period);

    expect($d['controls']['opex_sucursales'])->toBe('1200.00')
        ->and($d['radiography_reconciliation']['difference'])->toBe('0.00')
        ->and($d['controls']['opex_corporativo'])->toBe('5000.00')
        ->and($d['conciliation']['conciliados_erp']['count'])->toBe(1)
        ->and($d['conciliation']['incorporado_bancos'])->toBe('0.00')
        ->and(collect($d['checks'])->where('ok', false)->pluck('key')->all())->toBe([]);

    // Radiografía no cambió por haber procesado el ER.
    $g2 = $calc->sumGlobal(...array_values($calc->buildBranches($period, [$period->id])));
    expect(round((float) $g2['gastos_operativos'], 2))->toBe(1200.00);
});
