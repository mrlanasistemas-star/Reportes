<?php

use App\Models\Period;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\Comparatives\ComparisonAccessException;
use App\Services\EstadoResultados\Comparatives\ComparisonService;
use App\Services\EstadoResultados\Reporting\EstadoResultadosApprovalService;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * Comparativas del Estado de Resultados con casos de referencia INDEPENDIENTES:
 * cifras sintéticas conocidas (Radiografía sintética por periodo + ajustes) y variaciones
 * calculadas a mano. Nunca datos reales.
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('ORIZABA');
    F::branch('CORPORATIVO');
});

/** Sucursal sintética: ingresos (intereses), OPEX de Radiografía y nómina. */
function cmpBranch(float $ingresos, float $gastos, float $nomina): array
{
    return [
        'interes_recuperado' => $ingresos,
        'gastos_operativos' => $gastos, 'gastos_detalle' => $gastos > 0 ? ['Renta Oficina' => $gastos] : [],
        'nomina_total' => $nomina,
    ];
}

/** Ajustes del consolidado: capturados los indicados; el resto confirmado en $0 (ISR puede quedar sin información). */
function cmpAdjust(Period $p, array $captured, bool $isrPending = false): void
{
    foreach (array_keys(AdjustmentService::CONCEPTS) as $concept) {
        if (isset($captured[$concept])) {
            app(AdjustmentService::class)->save($p, 'CONSOLIDADO', $concept, AdjustmentService::CAPTURADO, $captured[$concept], 'prueba', null);
        } elseif (! ($concept === 'isr' && $isrPending)) {
            app(AdjustmentService::class)->save($p, 'CONSOLIDADO', $concept, AdjustmentService::CONFIRMADO_CERO, null, 'prueba', null);
        }
    }
}

function cmp(Period $p, ?string $scope = null, array $opts = [], $user = null): array
{
    return app(ComparisonService::class)->build($p, $scope, $user, $opts);
}

function cmpRow(array $mode, string $key): array
{
    return collect($mode['rows'])->firstWhere('key', $key);
}

/** Junio (referencia) y Mayo (anterior), ambos de 28 días y con la misma cobertura de fuentes. */
function cmpReferencePeriods(): array
{
    $may = F::monthPeriod(2026, 5, '2026-04-27', '2026-05-24');
    $jun = F::monthPeriod(2026, 6, '2026-05-25', '2026-06-21');
    F::fakeRadiographyByPeriod([
        // Caso de referencia: 1,000,000 / 600,000 / 400,000 / 30,000 / 370,000 / −30,000 / 340,000 / 100,000 / 240,000
        $jun->id => ['CORDOBA' => cmpBranch(1000000, 400000, 200000)],
        // Anterior: 800,000 / 500,000 / 300,000 / 30,000 / 270,000 / −30,000 / 240,000 / 100,000 / 140,000
        $may->id => ['CORDOBA' => cmpBranch(800000, 300000, 200000)],
    ]);
    cmpAdjust($jun, ['depreciacion' => '30000', 'intereses_bbva' => '30000', 'isr' => '100000']);
    cmpAdjust($may, ['depreciacion' => '30000', 'intereses_bbva' => '30000', 'isr' => '100000']);

    return [$may, $jun];
}

it('reproduce el caso de referencia del modelo de un solo EBITDA y la cascada con importes reales', function () {
    [, $jun] = cmpReferencePeriods();
    $out = cmp($jun, null, ['modes' => ['anterior']]);

    expect($out['contract_version'])->toBe('er-comparativas/1')
        ->and($out['current']['values'])->toBe([
            'ingresos_operativos' => '1000000.00', 'opex_total' => '600000.00', 'ebitda' => '400000.00',
            'depreciacion_amortizacion' => '30000.00', 'ebit' => '370000.00', 'resultado_financiero_neto' => '-30000.00',
            'ebt' => '340000.00', 'isr' => '100000.00', 'utilidad_neta' => '240000.00',
        ])
        ->and($out['current']['margins'])->toBe(['margen_ebitda' => '40.00', 'margen_ebit' => '37.00', 'margen_neto' => '24.00'])
        ->and($out['current']['source'])->toBe('en_vivo')
        ->and(array_sum(array_map(fn ($c) => (float) $c['amount'], $out['current']['opex_categories'])))->toBe(600000.0);

    $steps = collect($out['charts']['cascada']['steps'])->keyBy('key');
    expect($steps->pluck('key')->all())->toBe(['ingresos_operativos', 'opex_total', 'ebitda', 'depreciacion_amortizacion', 'ebit', 'resultado_financiero_neto', 'ebt', 'isr', 'utilidad_neta'])
        ->and($steps['opex_total']['amount'])->toBe('-600000.00')
        ->and($steps['opex_total']['end'])->toBe('400000.00')
        ->and($steps['resultado_financiero_neto']['amount'])->toBe('-30000.00')
        ->and($steps['resultado_financiero_neto']['end'])->toBe('340000.00')
        ->and($steps['isr']['end'])->toBe('240000.00')
        ->and($steps['utilidad_neta']['amount'])->toBe('240000.00');
});

it('compara contra el periodo anterior con variaciones calculadas a mano y dirección favorable por tipo de concepto', function () {
    [$may, $jun] = cmpReferencePeriods();
    $a = cmp($jun, null, ['modes' => ['anterior']])['modes']['anterior'];

    expect($a['comparison']['period']['id'])->toBe($may->id)
        ->and($a['comparability']['comparable'])->toBeTrue();

    // Ingresos: +200,000 / 800,000 = +25.00 % → favorable.
    expect(cmpRow($a, 'ingresos_operativos'))->toMatchArray(['current' => '1000000.00', 'previous' => '800000.00', 'variation' => '200000.00', 'variation_pct' => '25.00', 'assessment' => 'favorable']);
    // OPEX: +100,000 / 500,000 = +20.00 % → DESFAVORABLE (gasto).
    expect(cmpRow($a, 'opex_total'))->toMatchArray(['variation' => '100000.00', 'variation_pct' => '20.00', 'assessment' => 'desfavorable']);
    // EBITDA: +100,000 / 300,000 = +33.33 %.
    expect(cmpRow($a, 'ebitda'))->toMatchArray(['variation' => '100000.00', 'variation_pct' => '33.33', 'assessment' => 'favorable']);
    // Margen EBITDA 40.00 % vs 37.50 % = +2.50 pp (sin % de un %).
    expect(cmpRow($a, 'margen_ebitda'))->toMatchArray(['current' => '40.00', 'previous' => '37.50', 'variation' => '2.50', 'variation_unit' => 'pp', 'variation_pct' => null, 'assessment' => 'favorable']);
    // D&A y resultado financiero sin cambio → neutral; ISR igual.
    expect(cmpRow($a, 'depreciacion_amortizacion'))->toMatchArray(['variation' => '0.00', 'variation_pct' => '0.00', 'assessment' => 'neutral'])
        ->and(cmpRow($a, 'resultado_financiero_neto'))->toMatchArray(['current' => '-30000.00', 'previous' => '-30000.00', 'assessment' => 'neutral']);
    // Utilidad neta: +100,000 / 140,000 = +71.43 %.
    expect(cmpRow($a, 'utilidad_neta'))->toMatchArray(['previous' => '140000.00', 'variation' => '100000.00', 'variation_pct' => '71.43', 'assessment' => 'favorable']);
});

it('base cero, valores negativos y centavos: nunca calcula % con denominador 0', function () {
    $p1 = F::monthPeriod(2026, 7, '2026-06-22', '2026-07-19');
    $p2 = F::monthPeriod(2026, 8, '2026-07-20', '2026-08-16');
    F::fakeRadiographyByPeriod([
        $p1->id => ['CORDOBA' => cmpBranch(500000.01, 400000, 100000)],   // EBITDA 0.01; OPEX 500,000
        $p2->id => ['CORDOBA' => cmpBranch(500000.04, 450000, 100000.05)], // EBITDA −50,000.01
    ]);
    cmpAdjust($p1, []);
    cmpAdjust($p2, []);
    $a = cmp($p2, null, ['modes' => ['anterior']])['modes']['anterior'];

    // Centavos: 500,000.04 − 500,000.01 = 0.03 ; 0.03 / 500,000.01 ≈ 0.00 %.
    expect(cmpRow($a, 'ingresos_operativos'))->toMatchArray(['variation' => '0.03', 'variation_pct' => '0.00', 'assessment' => 'favorable']);
    // EBITDA: −50,000.01 − 0.01 = −50,000.02; |0.01| ⇒ −500,000,200.00 %.
    expect(cmpRow($a, 'ebitda'))->toMatchArray(['current' => '-50000.01', 'previous' => '0.01', 'variation' => '-50000.02', 'variation_pct' => '-500000200.00', 'assessment' => 'desfavorable']);
    // D&A en $0 contra $0: variación 0, % null por base cero, dirección neutral.
    $da = cmpRow($a, 'depreciacion_amortizacion');
    expect($da['variation'])->toBe('0.00')->and($da['variation_pct'])->toBeNull()
        ->and($da['reason']['code'])->toBe('base_cero')->and($da['assessment'])->toBe('neutral');
});

it('distingue «sin datos» (null) de cero en evolución y en meses sin periodo', function () {
    $apr = F::monthPeriod(2026, 4, '2026-03-30', '2026-04-26');
    $may = F::monthPeriod(2026, 5, '2026-04-27', '2026-05-24');
    $jun = F::monthPeriod(2026, 6, '2026-05-25', '2026-06-21');
    // Abril existe pero sin ninguna fuente; Mayo con cifras en cero explícitas no existe → sólo Junio con datos.
    F::fakeRadiographyByPeriod([$jun->id => ['CORDOBA' => cmpBranch(1000, 100, 0)]]);
    $evo = cmp($jun, null, ['modes' => ['evolucion'], 'meses' => 6])['modes']['evolucion'];

    expect($evo['months'])->toBe(6)
        ->and($evo['labels'][0])->toBe('Enero 2026')
        ->and($evo['status'])->toBe(['sin_datos', 'sin_datos', 'sin_datos', 'sin_datos', 'sin_datos', 'parcial'])
        ->and($evo['series']['ingresos_operativos'])->toBe([null, null, null, null, null, '1000.00'])
        ->and($evo['periods'][0]['unavailable_reason']['code'])->toBe('sin_periodo')
        ->and($evo['periods'][3]['period']['id'])->toBe($apr->id)
        ->and($evo['periods'][3]['unavailable_reason']['code'])->toBe('sin_datos')
        ->and($evo['with_data'])->toBe(1);
    expect($may->id)->toBeInt();
});

it('ISR sin información queda PENDIENTE (null, nunca $0) y deja pendiente la utilidad neta', function () {
    [$may, $jun] = cmpReferencePeriods();
    DB::table('estado_resultados_adjustments')->where('period_id', $jun->id)->where('concept', 'isr')->delete();
    $out = cmp($jun, null, ['modes' => ['anterior']]);

    expect($out['current']['values']['isr'])->toBeNull()
        ->and($out['current']['values']['utilidad_neta'])->toBeNull()
        ->and($out['current']['margins']['margen_neto'])->toBeNull()
        ->and($out['current']['values']['ebt'])->toBe('340000.00');
    $isr = cmpRow($out['modes']['anterior'], 'isr');
    expect($isr['variation'])->toBeNull()->and($isr['reason']['code'])->toBe('pendiente_actual');
    $steps = collect($out['charts']['cascada']['steps'])->keyBy('key');
    expect($steps['isr']['pending'])->toBeTrue()->and($steps['isr']['amount'])->toBeNull()
        ->and($steps['utilidad_neta']['pending'])->toBeTrue();
});

it('comparación personalizada: cobertura y duración distintas ⇒ «con reservas»: cifras, % y lectura visibles pero marcadas, y lo que falta exacto', function () {
    [$may, $jun] = cmpReferencePeriods();
    $other = F::monthPeriod(2026, 3, '2026-02-23', '2026-03-29'); // 35 días
    F::fakeRadiographyByPeriod([
        $jun->id => ['CORDOBA' => cmpBranch(1000000, 400000, 200000)],
        $other->id => ['CORDOBA' => cmpBranch(900000, 400000, 200000)],
    ]);
    F::erp($other, 'CORPORATIVO', 'REQ-X1', '1000.00', '2026-03-01', 'Luz', 'LUZ'); // ERP sólo en el periodo de comparación
    cmpAdjust($other, ['depreciacion' => '30000', 'intereses_bbva' => '30000', 'isr' => '100000']);

    $p = cmp($jun, null, ['modes' => ['personalizado'], 'contra' => $other])['modes']['personalizado'];
    $codes = array_column($p['comparability']['reasons'], 'code');

    expect($p['comparability']['comparable'])->toBeFalse()
        ->and($codes)->toContain('duracion_distinta', 'cobertura_distinta')
        ->and($p['comparability']['level'])->toBe('con_reservas')
        ->and($p['comparability']['headline'])->toContain('Comparación con reservas')->toContain('ERP de gastos de '.$jun->label);
    // Lo que falta, exacto: el ERP del periodo SELECCIONADO, que se carga en Carga de archivos.
    expect($p['comparability']['missing'])->toHaveCount(1)
        ->and($p['comparability']['missing'][0])->toMatchArray(['side' => 'actual', 'period_id' => $jun->id, 'source' => 'erp', 'where' => 'carga']);
    // Con distinta duración, todo concepto con variación va «con reservas» (nunca «no comparable» a secas).
    $ing = cmpRow($p, 'ingresos_operativos');
    expect($ing['variation'])->toBe('100000.00')
        ->and($ing['variation_pct'])->toBe('11.11')
        ->and($ing['assessment'])->toBe('favorable')
        ->and($ing['assessment_label'])->toBe('Favorable (con reservas)')
        ->and($ing['reservation']['code'])->toBe('con_reservas')
        ->and($ing['reason'])->toBeNull();
});

it('comparación con fuentes distintas y misma duración: conceptos que no dependen de la fuente conservan su lectura normal', function () {
    [$may, $jun] = cmpReferencePeriods();
    F::erp($may, 'CORPORATIVO', 'REQ-M1', '1000.00', '2026-05-01', 'Luz', 'LUZ'); // ERP sólo en mayo
    $p = cmp($jun, null, ['modes' => ['anterior']])['modes']['anterior'];

    expect($p['comparability']['level'])->toBe('con_reservas')
        ->and($p['comparability']['missing'][0])->toMatchArray(['side' => 'actual', 'source' => 'erp', 'period_label' => $jun->label]);
    expect(cmpRow($p, 'ingresos_operativos')['reservation'])->toBeNull()
        ->and(cmpRow($p, 'ingresos_operativos')['assessment_label'])->not->toContain('reservas')
        ->and(cmpRow($p, 'ebitda')['reservation']['code'])->toBe('con_reservas')
        ->and(cmpRow($p, 'ebitda')['variation_pct'])->not->toBeNull();
});

it('acumulado anual: suma sólo meses del mismo año ≤ seleccionado, se etiqueta PARCIAL y sin año anterior no compara', function () {
    [$may, $jun] = cmpReferencePeriods();
    $jul = F::monthPeriod(2026, 7, '2026-06-22', '2026-07-19'); // posterior: no entra al acumulado de junio
    $y = cmp($jun, null, ['modes' => ['acumulado']])['modes']['acumulado'];

    expect($y['partial'])->toBeTrue()
        ->and($y['months_with_data'])->toBe(2)
        ->and($y['months_total'])->toBe(12)
        ->and($y['label'])->toContain('parcial')->toContain('2 de 12 meses')
        ->and($y['current']['values']['ingresos_operativos'])->toBe('1800000.00')
        ->and($y['current']['values']['ebitda'])->toBe('700000.00')
        ->and($y['current']['values']['utilidad_neta'])->toBe('380000.00')
        ->and($y['current']['margins']['margen_ebitda'])->toBe('38.89')
        ->and($y['current']['missing_months'])->toContain('Enero 2026', 'Abril 2026')
        ->and($y['previous_year']['available'])->toBeFalse()
        ->and($y['comparison']['available'])->toBeFalse()
        ->and(cmpRow($y['comparison'], 'ebitda')['variation_pct'])->toBeNull()
        ->and(cmpRow($y['comparison'], 'ebitda')['reason']['code'])->toBe('sin_datos_anterior');
    expect($jul->id)->toBeInt();
});

it('acumulado y mismo periodo del año anterior: compara sólo meses equivalentes', function () {
    [$may, $jun] = cmpReferencePeriods();
    $jun25 = F::monthPeriod(2025, 6, '2025-05-26', '2025-06-22');
    $may25 = F::monthPeriod(2025, 5, '2025-04-28', '2025-05-25');
    F::fakeRadiographyByPeriod([
        $jun->id => ['CORDOBA' => cmpBranch(1000000, 400000, 200000)],
        $may->id => ['CORDOBA' => cmpBranch(800000, 300000, 200000)],
        $jun25->id => ['CORDOBA' => cmpBranch(500000, 200000, 100000)],
        $may25->id => ['CORDOBA' => cmpBranch(400000, 200000, 100000)],
    ]);
    cmpAdjust($jun25, ['depreciacion' => '30000', 'intereses_bbva' => '30000', 'isr' => '100000']);
    cmpAdjust($may25, ['depreciacion' => '30000', 'intereses_bbva' => '30000', 'isr' => '100000']);
    $out = cmp($jun, null, ['modes' => ['anio_anterior', 'acumulado']]);

    $yoy = $out['modes']['anio_anterior'];
    expect($yoy['comparison']['period']['id'])->toBe($jun25->id)
        ->and($yoy['comparability']['comparable'])->toBeTrue()
        // EBITDA 400,000 vs 200,000 = +100.00 %
        ->and(cmpRow($yoy, 'ebitda'))->toMatchArray(['variation' => '200000.00', 'variation_pct' => '100.00', 'assessment' => 'favorable']);

    $ytd = $out['modes']['acumulado']['comparison'];
    // 2026: ingresos 1,800,000; 2025 (mayo+junio): 900,000 ⇒ +100.00 %
    expect($ytd['comparability']['comparable'])->toBeTrue()
        ->and(cmpRow($ytd, 'ingresos_operativos'))->toMatchArray(['current' => '1800000.00', 'previous' => '900000.00', 'variation_pct' => '100.00']);

    // Si al año anterior le falta un mes equivalente, el acumulado deja de ser comparable.
    F::fakeRadiographyByPeriod([
        $jun->id => ['CORDOBA' => cmpBranch(1000000, 400000, 200000)],
        $may->id => ['CORDOBA' => cmpBranch(800000, 300000, 200000)],
        $jun25->id => ['CORDOBA' => cmpBranch(500000, 200000, 100000)],
    ]);
    DB::table('estado_resultados_adjustments')->where('period_id', $may25->id)->delete();
    $ytd2 = cmp($jun, null, ['modes' => ['acumulado']])['modes']['acumulado']['comparison'];
    expect($ytd2['comparability']['comparable'])->toBeFalse()
        ->and(array_column($ytd2['comparability']['reasons'], 'code'))->toContain('meses_distintos')
        ->and(cmpRow($ytd2, 'ingresos_operativos')['variation_pct'])->toBeNull();
});

it('sucursales: EBITDA de sucursal sin asignar corporativo/DG; centralizados aparte y contados una sola vez', function () {
    $may = F::monthPeriod(2026, 5, '2026-04-27', '2026-05-24');
    $jun = F::monthPeriod(2026, 6, '2026-05-25', '2026-06-21');
    F::fakeRadiographyByPeriod([
        $jun->id => ['CORDOBA' => cmpBranch(600000, 200000, 100000), 'ORIZABA' => cmpBranch(400000, 100000, 100000)],
        $may->id => ['CORDOBA' => cmpBranch(500000, 200000, 100000), 'ORIZABA' => cmpBranch(400000, 100000, 100000)],
    ]);
    F::erp($jun, 'CORPORATIVO', 'REQ-C1', '7000.00', '2026-06-01', 'Luz', 'LUZ CORPORATIVO');
    F::erp($may, 'CORPORATIVO', 'REQ-C0', '7000.00', '2026-05-01', 'Luz', 'LUZ CORPORATIVO');
    $out = cmp($jun, null, ['modes' => ['sucursales']]);
    $s = $out['modes']['sucursales'];
    $rows = collect($s['rows'])->keyBy('key');

    expect($rows->keys()->sort()->values()->all())->toBe(['CORDOBA', 'ORIZABA'])
        ->and($rows['CORDOBA'])->toMatchArray(['ingresos_operativos' => '600000.00', 'opex_total' => '300000.00', 'ebitda' => '300000.00', 'margen_ebitda' => '50.00', 'participacion_ingresos' => '60.00'])
        ->and($rows['ORIZABA']['ebitda'])->toBe('200000.00')
        ->and($rows['CORDOBA']['participacion_ebitda'])->toBe('60.00')
        // vs Mayo: CORDOBA EBITDA 200,000 → 300,000 = +50.00 %
        ->and($rows['CORDOBA']['variation']['ebitda'])->toMatchArray(['previous' => '200000.00', 'variation' => '100000.00', 'variation_pct' => '50.00', 'assessment' => 'favorable'])
        ->and(collect($rows['CORDOBA']['opex_categories'])->pluck('centralized')->unique()->all())->toBe([false]);

    $consOpex = $out['current']['values']['opex_total'];
    expect($s['totals']['sucursales']['ebitda'])->toBe('500000.00')
        ->and($s['totals']['centralizados']['total'])->toBe('7000.00')
        ->and($s['totals']['consolidado']['ebitda'])->toBe('493000.00')
        ->and($consOpex)->toBe('507000.00')
        ->and($s['totals']['conciliacion']['no_asignado_a_sucursal'])->toBe('0.00');
    // Corporativo/DG: categoría centralizada sólo en el consolidado.
    expect(collect($out['current']['opex_categories'])->where('centralized', true)->sum(fn ($c) => (float) $c['amount']))->toBe(7000.0);
});

it('respeta el alcance de sucursales del usuario: sin consolidado, sin centralizados, rechazo fuera de su alcance', function () {
    $jun = F::monthPeriod(2026, 6, '2026-05-25', '2026-06-21');
    F::fakeRadiographyByPeriod([$jun->id => ['CORDOBA' => cmpBranch(600000, 200000, 100000), 'ORIZABA' => cmpBranch(400000, 100000, 100000)]]);
    $user = G::userWith([]);
    $user->forceFill(['branch_scope' => 'branches'])->save();
    DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => F::branch('CORDOBA')->id, 'created_at' => now(), 'updated_at' => now()]);

    // Capa HTTP: el módulo ER exige alcance general (EnsureModuleAccess) ⇒ 403 antes de llegar aquí.
    $this->actingAs($user)->getJson("/estado-resultados/{$jun->id}/comparativas")->assertForbidden();

    // Defensa en profundidad: el servicio (lo usan también PDF/Excel) aplica el alcance por sí mismo.
    $res = cmp($jun, null, ['modes' => ['sucursales', 'anterior']], $user);
    expect($res['scope']['key'])->toBe('CORDOBA')
        ->and($res['access']['restricted'])->toBeTrue()
        ->and(array_column($res['access']['allowed_scopes'], 'key'))->toBe(['CORDOBA'])
        ->and(array_column($res['modes']['sucursales']['rows'], 'key'))->toBe(['CORDOBA'])
        ->and($res['modes']['sucursales']['totals']['consolidado'])->toBeNull()
        ->and($res['modes']['sucursales']['totals']['centralizados'])->toBeNull()
        ->and($res['modes']['sucursales']['totals']['sucursales']['ebitda'])->toBe('300000.00')
        ->and($res['modes']['sucursales']['rows'][0]['participacion_ingresos'])->toBeNull()
        ->and($res['modes']['sucursales']['rows'][0]['participacion_reason']['code'])->toBe('sin_consolidado')
        ->and($res['charts']['sucursales']['centralized'])->toBeNull();

    expect(fn () => cmp($jun, 'CONSOLIDADO', [], $user))->toThrow(ComparisonAccessException::class)
        ->and(fn () => cmp($jun, 'ORIZABA', [], $user))->toThrow(ComparisonAccessException::class);
});

it('periodo aprobado: usa el snapshot inmutable aunque cambie la base en vivo', function () {
    F::fakeRadiography();
    $period = G::readyPeriod();
    $before = cmp($period, null, ['modes' => []])['current'];
    expect($before['source'])->toBe('en_vivo');

    app(EstadoResultadosApprovalService::class)->approve($period, G::admin());
    F::fakeRadiography(['interes_recuperado' => 1.00]); // la base en vivo cambia después de aprobar

    $after = cmp($period, null, ['modes' => []])['current'];
    expect($after['source'])->toBe('snapshot')
        ->and($after['status'])->toBe('aprobado')
        ->and($after['source_label'])->toContain('aprobada')
        ->and($after['values'])->toBe($before['values']);
});

it('caché por firma de insumos: reutiliza y se invalida al cambiar un ajuste', function () {
    [, $jun] = cmpReferencePeriods();
    $svc = fn () => app(ComparisonService::class);
    $first = $svc()->build($jun, null, null, ['modes' => []]);
    $second = $svc()->build($jun, null, null, ['modes' => []]);
    expect($first['current']['cache'])->toBe('miss')
        ->and($second['current']['cache'])->toBe('hit')
        ->and($second['current']['values'])->toBe($first['current']['values']);

    app(AdjustmentService::class)->save($jun, 'CONSOLIDADO', 'isr', AdjustmentService::CAPTURADO, '50000', 'cambio', null);
    $third = $svc()->build($jun, null, null, ['modes' => []]);
    expect($third['current']['cache'])->toBe('miss')
        ->and($third['current']['values']['utilidad_neta'])->toBe('290000.00');
});

it('redacción: sin ver_nomina / ver_dg conserva los totales pero no el detalle', function () {
    [, $jun] = cmpReferencePeriods();
    $user = G::userWith([]);
    $res = $this->actingAs($user)->getJson("/estado-resultados/{$jun->id}/comparativas?modos=anterior")->assertOk()->json();
    $cats = collect($res['current']['opex_categories'])->keyBy('key');

    expect($res['access']['ver_nomina'])->toBeFalse()
        ->and($cats['nomina_sucursales']['amount'])->toBe('200000.00')
        ->and($cats['nomina_sucursales']['restricted'])->toBeTrue()
        ->and($cats['nomina_sucursales']['lines'])->toBe([])
        ->and($cats['dg']['restricted'])->toBeTrue()
        ->and($cats['opex_sucursales']['lines'])->not->toBe([])
        ->and($res['current']['values']['opex_total'])->toBe('600000.00');

    $full = $this->actingAs(G::admin())->getJson("/estado-resultados/{$jun->id}/comparativas?modos=anterior")->assertOk()->json();
    expect(collect($full['current']['opex_categories'])->firstWhere('key', 'nomina_sucursales')['lines'])->not->toBe([]);
});

it('valida parámetros del endpoint', function () {
    [, $jun] = cmpReferencePeriods();
    $admin = G::admin();
    $this->actingAs($admin)->getJson("/estado-resultados/{$jun->id}/comparativas?modo=inventado")->assertStatus(422);
    $this->actingAs($admin)->getJson("/estado-resultados/{$jun->id}/comparativas?modo=personalizado")->assertStatus(422);
    $this->actingAs($admin)->getJson("/estado-resultados/{$jun->id}/comparativas?modo=personalizado&contra={$jun->id}")->assertStatus(422);
    $this->actingAs($admin)->getJson("/estado-resultados/{$jun->id}/comparativas?alcance=NOEXISTE")->assertStatus(422);
    $res = $this->actingAs($admin)->getJson("/estado-resultados/{$jun->id}/comparativas")->assertOk()->json();
    expect(array_keys(array_filter($res['modes'])))->toBe(['anterior', 'anio_anterior', 'evolucion', 'acumulado', 'sucursales'])
        ->and(array_keys($res['charts']))->toBe(['evolucion_resultados', 'ingresos_vs_opex', 'composicion_opex', 'cascada', 'margenes', 'sucursales']);
});
