<?php

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\Radiography\BranchRadiographyCalculator as C;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Tests\Integration\UsesRealDevDatabase;

uses(UsesRealDevDatabase::class);

/**
 * REGRESIÓN DE RADIOGRAFÍA con datos reales de desarrollo (SOLO LECTURA).
 *
 * Valores "golden" capturados el 05-nov-2026 ANTES de implementar el módulo
 * Estado de Resultados (Junio 2026, periodo M-2026-06). El módulo no modificó
 * ningún archivo de Radiografía; esta prueba demuestra que Radiografía sigue
 * dando exactamente lo mismo (incluido que CORPORATIVO sigue EXCLUIDO) y que el
 * EBITDA de sucursales del ER concilia a $0.00 contra ella.
 *
 * Si los datos de desarrollo se re-importan, los golden deben recapturarse.
 */
function erGoldenPeriod(): ?Period
{
    return Period::query()->where('code', 'M-2026-06')->first();
}

beforeEach(function () {
    $this->useRealDevDatabaseOrSkip();
    if (! erGoldenPeriod()) {
        $this->markTestSkipped('La BD de desarrollo no tiene el periodo M-2026-06.');
    }
});

it('Radiografía conserva exactamente su EBITDA, ingresos, OPEX y nómina (golden pre-implementación)', function () {
    $period = erGoldenPeriod();
    $ids = app(RadiographySnapshotBuilder::class)->resolveDataIdsPublic($period);
    $calc = app(C::class);
    $r = $calc->buildBranches($period, $ids);
    $g = $calc->sumGlobal($r['branches'], $r['unassigned']);

    expect(sprintf('%.2f', C::ingresoEbitdaBaseFor($g)))->toBe('5387934.42')
        ->and(sprintf('%.2f', $g['gastos_operativos']))->toBe('899937.33')
        ->and(sprintf('%.2f', C::nominaTotalFor($g)))->toBe('2626123.81')
        ->and(sprintf('%.2f', C::ebitdaFinalFor($g)))->toBe('1861873.28')
        ->and(sprintf('%.2f', $g['gastos_erp_total']))->toBe('505648.22');

    $golden = [
        'ATLACOMULCO' => '-18443.58', 'ATLIXCO' => '-21248.80', 'CORDOBA' => '629400.92', 'CUERNAVACA' => '-104848.45',
        'HUAMANTLA' => '-19869.89', 'IXTLAHUACA' => '-1965.67', 'MIACATLAN' => '4085.59', 'ORIZABA' => '1088470.70',
        'SAN JUAN DEL RÍO' => '2418.12', 'SAN LUIS POTOSI' => '112783.40', 'TENANGO DEL VALLE' => '-136193.37',
        'TLAXCALA' => '99886.45', 'TULA' => '227397.86',
    ];
    foreach ($r['branches'] as $b) {
        expect(sprintf('%.2f', C::ebitdaFinalFor($b)))->toBe($golden[$b['sucursal']], $b['sucursal']);
    }
});

it('el snapshot de Radiografía (web/Excel/PDF) conserva sus KPIs', function () {
    $period = erGoldenPeriod();
    $summary = PeriodSummary::query()->where('period_id', $period->id)->first();
    if (! $summary) {
        $this->markTestSkipped('Sin PeriodSummary para M-2026-06.');
    }
    $s = app(RadiographySnapshotBuilder::class)->build($period, $summary)['summary'];

    expect(sprintf('%.2f', $s['ebitda_global']))->toBe('1861873.28')
        ->and(sprintf('%.2f', $s['ingreso_ebitda_base']))->toBe('5387934.42')
        ->and(sprintf('%.2f', $s['opex_total']))->toBe('899937.33')
        ->and(sprintf('%.2f', $s['nomina_capital_humano_total']))->toBe('2626123.81')
        ->and(sprintf('%.2f', $s['margen_ebitda']))->toBe('34.56')
        ->and(sprintf('%.2f', $s['recovery_total']))->toBe('17888526.74')
        ->and(sprintf('%.2f', $s['placement_total']))->toBe('14151329.00');
});

it('CORPORATIVO sigue excluido de Radiografía: ERP total = Radiografía + pólizas excluidas + CORPORATIVO', function () {
    $period = erGoldenPeriod();
    $ids = app(RadiographySnapshotBuilder::class)->resolveDataIdsPublic($period);
    $calc = app(C::class);
    $r = $calc->buildBranches($period, $ids);
    $g = $calc->sumGlobal($r['branches'], $r['unassigned']);

    $erpTotal = (float) DB::table('fact_expenses as e')->join('report_uploads as ru', 'ru.id', '=', 'e.report_upload_id')
        ->join('data_sources as ds', 'ds.id', '=', 'ru.data_source_id')->where('ds.code', 'gastos_erp')
        ->whereIn('e.period_id', $ids)->sum(DB::raw('COALESCE(NULLIF(e.paid_amount,0), e.amount)'));
    $corp = (float) DB::table('fact_expenses as e')->join('report_uploads as ru', 'ru.id', '=', 'e.report_upload_id')
        ->join('data_sources as ds', 'ds.id', '=', 'ru.data_source_id')->join('branches as b', 'b.id', '=', 'e.branch_id')
        ->where('ds.code', 'gastos_erp')->where('b.name', 'CORPORATIVO')
        ->whereIn('e.period_id', $ids)->sum(DB::raw('COALESCE(NULLIF(e.paid_amount,0), e.amount)'));

    expect(round($corp, 2))->toBe(410568.60)
        ->and(round((float) $g['gastos_erp_total'] + (float) $g['gastos_erp_excluido_seguros'] + $corp, 2))->toBe(round($erpTotal, 2));
});

it('Estado de Resultados: EBITDA sucursales = Radiografía ($0.00) y CORPORATIVO sí participa (nivel 2)', function () {
    if (! Schema::hasTable('estado_resultados_movements')) {
        $this->markTestSkipped('Falta ejecutar la migración del Estado de Resultados en la BD de desarrollo.');
    }
    $d = app(EstadoResultadosDatasetBuilder::class)->build(erGoldenPeriod());

    expect($d['controls']['ebitda_sucursales'])->toBe('1861873.28')
        ->and($d['radiography_reconciliation']['radiografia_ebitda'])->toBe('1861873.28')
        ->and($d['radiography_reconciliation']['difference'])->toBe('0.00')
        ->and($d['conciliation']['erp_fuera_radiografia'])->toBe(['count' => 80, 'amount' => '410568.60'])
        ->and((float) $d['controls']['opex_corporativo'] + (float) $d['controls']['nomina_corporativa'])->toBeGreaterThan(0)
        ->and(collect($d['checks'])->where('ok', false)->pluck('key')->all())->toBe([]);

    // EBITDA corporativo = sucursales − (lo efectivamente clasificado como Corporativo); lo pendiente NO se suma.
    $incorporado = (float) $d['conciliation']['incorporado_erp_fuera_radiografia'];
    expect(round(1861873.28 - $incorporado, 2))->toBe((float) $d['controls']['ebitda_corporativo']);
});
