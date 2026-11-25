<?php

use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\EstadoResultados\EstadoResultadosRun;
use App\Models\Period;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Classification\ClassificationService;
use App\Services\EstadoResultados\Classification\ConceptCatalogDefaults;
use App\Services\EstadoResultados\Classification\ConceptCatalogService;
use App\Services\EstadoResultados\Classification\MovementClassifier;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Reporting\PeriodLockGuard;
use App\Services\EstadoResultados\RulesRefreshService;
use App\Services\EstadoResultados\Support\Treatment as T;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * 25-nov-2026: reproceso automático por versión de reglas, retiros de Dirección General y socios
 * DEBAJO de la Utilidad neta (no tocan el EBITDA) y reglas automáticas de conceptos recurrentes.
 * Datos 100 % SINTÉTICOS.
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
});

/** Periodo con Radiografía de 1,000,000 de ingresos y 600,000 de OPEX + nómina; movimientos del caso. */
function rdgPeriod(bool $isrPending = false): Period
{
    $p = F::period('2026-09-01', '2026-09-28');
    F::fakeRadiographyByPeriod([$p->id => ['CORDOBA' => [
        'interes_recuperado' => 1000000.00,
        'gastos_operativos' => 400000.00, 'gastos_detalle' => ['Renta Oficina' => 400000.00],
        'nomina_total' => 200000.00,
    ]]]);
    foreach (array_keys(AdjustmentService::CONCEPTS) as $concept) {
        if (! ($isrPending && $concept === 'isr')) {
            app(AdjustmentService::class)->save($p, 'CONSOLIDADO', $concept, AdjustmentService::CONFIRMADO_CERO, null, 'prueba', null);
        }
    }
    G::import($p, F::adminXlsx([
        ['2026-09-02', null, 4095.00, null, 'DEPOSITO LEROM', null],
        ['2026-09-03', 300000.00, null, 'S/R', 'GASTOS DG', 'CORPORATIVO', 'DIRECTOR'],
        ['2026-09-04', 150000.00, null, 'S/R', 'RENTA FAMILIA DG', 'CORPORATIVO'],
        ['2026-09-05', 20000.00, null, 'S/R', 'RETORNO INVERSION', 'CORPORATIVO', 'SOCIO A'],
        ['2026-09-06', 5000.00, null, 'S/R', 'PAGO SERV CONTABLES', 'CORPORATIVO'],
        ['2026-09-07', 800.00, null, 'S/R', 'COMISIONES BANCARIAS', 'CORPORATIVO'],
        ['2026-09-08', 0.01, null, null, 'PRUEBA SPEI', null],
        ['2026-09-09', 90000.00, null, null, 'DISPERSION GRUPAL 09/09/2026', null],
        ['2026-09-10', 3000.00, null, 'S/R', 'PAGO PRESTAMOS SEMANAL', 'CORPORATIVO'],
        ['2026-09-11', null, 1500.00, null, 'DEPOSITO ERRONEO', null],
        ['2026-09-12', 2400.00, null, 'S/R', 'PAGO', 'CORPORATIVO'],
    ]));
    app(EstadoResultadosProcessor::class)->process($p);

    return $p;
}

function rdgMov(Period $p, string $concept): EstadoResultadosMovement
{
    return EstadoResultadosMovement::query()->where('period_id', $p->id)->where('concept', $concept)->firstOrFail();
}

it('clasifica en automático DG, socios y conceptos recurrentes; sólo lo genérico queda pendiente', function () {
    $p = rdgPeriod();
    $rule = fn (string $c) => [rdgMov($p, $c)->auto_treatment, rdgMov($p, $c)->rule_code];

    expect($rule('DEPOSITO LEROM'))->toBe([T::TRANSFERENCIA_INTERNA, 'aportacion_credito'])
        ->and($rule('GASTOS DG'))->toBe([T::PERSONAL_SOCIOS, 'retiro_dg'])
        ->and($rule('RENTA FAMILIA DG'))->toBe([T::PERSONAL_SOCIOS, 'retiro_dg'])
        ->and($rule('RETORNO INVERSION'))->toBe([T::PERSONAL_SOCIOS, 'retiro_socios'])
        ->and($rule('PAGO SERV CONTABLES'))->toBe([T::OPEX_CORPORATIVO, 'opex_corporativo_recurrente'])
        ->and($rule('COMISIONES BANCARIAS'))->toBe([T::GASTO_FINANCIERO, 'comisiones_bancarias'])
        ->and($rule('PRUEBA SPEI'))->toBe([T::BALANCE, 'prueba_spei'])
        ->and($rule('DISPERSION GRUPAL 09/09/2026'))->toBe([T::BALANCE, 'dispersion_creditos'])
        ->and($rule('PAGO PRESTAMOS SEMANAL'))->toBe([T::RECUPERABLE, 'prestamo'])
        ->and($rule('DEPOSITO ERRONEO'))->toBe([T::BALANCE, 'error_bancario'])
        ->and($rule('PAGO')[0])->toBe(T::PENDIENTE);
});

it('los retiros de DG y socios no tocan el EBITDA: van debajo de la Utilidad neta y pueden dejar el resultado en rojo', function () {
    $p = rdgPeriod();
    $d = app(EstadoResultadosDatasetBuilder::class)->build($p);

    // EBITDA = 1,000,000 − 600,000 − OPEX corporativo 5,000 (los 470,000 de DG/socios no restan aquí).
    expect($d['results']['ebitda'])->toBe('395000.00')
        ->and($d['results']['retiros_dg_socios'])->toBe('470000.00')
        ->and($d['results']['utilidad_neta'])->toBe('394200.00') // − comisiones bancarias 800 (otros gastos financieros)
        ->and($d['results']['resultado_despues_retiros'])->toBe('-75800.00')
        ->and(collect($d['statement'][1]['categories'])->pluck('key')->all())->not->toContain('dg');

    $keys = collect($d['statement'])->pluck('key')->all();
    $after = collect($d['statement'])->firstWhere('key', 'resultado_despues_retiros');
    expect(array_slice($keys, -3))->toBe(['utilidad_neta', 'retiros', 'resultado_despues_retiros'])
        ->and($after['negative'])->toBeTrue()
        ->and($after['note'])->toContain('tomaron dinero del flujo siguiente')
        ->and(collect($d['checks'])->firstWhere('key', 'retiros_formula')['ok'])->toBeTrue()
        ->and(collect($d['checks'])->where('ok', false)->pluck('key')->all())->toBe([]);

    $w = $d['withdrawals'];
    $gastos = collect($w['items'])->firstWhere('concept', 'GASTOS DG');
    expect($w['total'])->toBe('470000.00')->and($w['count'])->toBe(3)
        ->and(collect($w['by_concept'])->pluck('label')->all())->toBe(['GASTOS DG', 'RENTA FAMILIA DG', 'RETORNO INVERSION'])
        ->and($gastos)->toMatchArray(['date' => '2026-09-03', 'amount' => '300000.00', 'who' => 'DIRECTOR', 'account_slot' => 'administrativa'])
        ->and(collect($w['items'])->firstWhere('concept', 'RENTA FAMILIA DG')['who'])->toBe('Dirección General');
});

it('con ISR pendiente el resultado después de retiros se calcula sobre el EBT («antes de ISR»)', function () {
    $p = rdgPeriod(true); // primero el periodo: instala la Radiografía de prueba antes de crear el builder
    $pending = app(EstadoResultadosDatasetBuilder::class)->build($p);
    expect($pending['results']['utilidad_neta'])->toBeNull()
        ->and($pending['results']['resultado_despues_retiros'])->toBe('-75800.00')
        ->and($pending['withdrawals']['result_label'])->toContain('ANTES DE ISR');
});

it('un periodo procesado con reglas viejas se reprocesa solo, respeta lo manual y nunca toca un periodo aprobado', function () {
    $p = rdgPeriod();
    $lerom = rdgMov($p, 'DEPOSITO LEROM');
    $pago = rdgMov($p, 'PAGO');
    // Estado de producción del 24-nov: el abono quedó «sin regla» con la versión anterior.
    $lerom->forceFill(['auto_treatment' => T::PENDIENTE, 'rule_code' => 'abono_sin_regla', 'reason' => 'Abono sin regla de clasificación.'])->save();
    EstadoResultadosRun::query()->where('period_id', $p->id)->update(['rules_version' => '2026-11-06.1']);
    app(ClassificationService::class)->classify($p, [['fingerprint' => $pago->fingerprint, 'source' => 'banco', 'current' => T::PENDIENTE]], T::OPEX_CORPORATIVO, 'Proveedor confirmado', null);

    $svc = app(RulesRefreshService::class);
    expect($svc->staleReason($p))->toBe('reglas');

    $task = $svc->refreshIfStale($p); // cola sync: ya terminó
    expect($task?->status)->toBe('ok')
        ->and($lerom->fresh()->only(['auto_treatment', 'rule_code']))->toBe(['auto_treatment' => T::TRANSFERENCIA_INTERNA, 'rule_code' => 'aportacion_credito'])
        ->and(EstadoResultadosRun::query()->where('period_id', $p->id)->latest('id')->value('rules_version'))->toBe(MovementClassifier::RULES_VERSION)
        ->and($svc->staleReason($p))->toBeNull()
        ->and(collect(app(EstadoResultadosDatasetBuilder::class)->build($p)['movements'])->firstWhere('fingerprint', $pago->fingerprint)['treatment'])->toBe(T::OPEX_CORPORATIVO);

    // Al abrir el ER también se detecta y se reprocesa (cola sync: la respuesta ya sale actualizada).
    $lerom->fresh()->forceFill(['auto_treatment' => T::PENDIENTE, 'rule_code' => 'abono_sin_regla'])->save();
    EstadoResultadosRun::query()->where('period_id', $p->id)->update(['rules_version' => '2026-11-06.1']);
    DB::table('estado_resultados_tasks')->update(['queued_at' => now()->subHour()]); // fuera del enfriamiento
    $res = $this->actingAs(G::admin())->getJson("/estado-resultados/{$p->id}/dataset")->assertOk();
    expect($res->json('auto_refresh'))->toBeNull()
        ->and(collect($res->json('dataset.movements'))->firstWhere('fingerprint', $lerom->fingerprint)['rule_code'])->toBe('aportacion_credito');

    // Periodo aprobado: jamás se reprocesa.
    EstadoResultadosRun::query()->where('period_id', $p->id)->update(['rules_version' => 'vieja']);
    $this->mock(PeriodLockGuard::class)->shouldReceive('isLocked')->andReturnTrue();
    expect(app(RulesRefreshService::class)->staleReason($p))->toBeNull()
        ->and(app(RulesRefreshService::class)->refreshAllStale())->toBe([]);
});

it('las reglas iniciales del catálogo se instalan una sola vez y no reviven las borradas', function () {
    expect(ConceptCatalogDefaults::install())->toBe(count(ConceptCatalogDefaults::RULES))
        ->and(ConceptCatalogDefaults::install())->toBe(0);

    $rules = app(ConceptCatalogService::class)->activeRules();
    expect(collect($rules)->pluck('text')->all())->toContain('COMISIONES BANCARIAS', 'PRUEBA SPEI', 'DEVOLUCION POR ERROR EN DEPOSITO');

    $hit = ConceptCatalogService::match(['concept' => 'DEVOLUCION POR ERROR EN DEPOSITO', 'abono' => '10', 'cargo' => '0', 'account_slot' => 'administrativa'], $rules)['rule'];
    expect($hit['treatment'])->toBe(T::BALANCE);

    DB::table('estado_resultados_concept_rules')->where('match_normalized', 'PRUEBA SPEI')->update(['deleted_at' => now()]);
    expect(ConceptCatalogDefaults::install())->toBe(0);
});
