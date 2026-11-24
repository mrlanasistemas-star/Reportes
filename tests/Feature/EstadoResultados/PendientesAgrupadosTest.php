<?php

use App\Models\EstadoResultados\EstadoResultadosClassification;
use App\Models\Period;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Control\MovementControlService;
use App\Services\EstadoResultados\Control\PendingGroupService;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Support\Treatment as T;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * Pendientes agrupados por concepto y clasificación masiva segura (24-nov-2026): los
 * «DEPOSITO RENTA LOCAL» repetidos se ven como UN grupo, la vista previa dice qué se excluye y por
 * qué, aplicar deja bitácora con usuario y sin permiso no se puede.
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('ORIZABA');
    F::branch('CORPORATIVO');
    F::fakeRadiography();
});

function pgPeriod(): Period
{
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-77', '15000.00', '2026-09-03', 'Rentas', 'RENTA OFICINA');
    G::import($period, F::adminXlsx([
        ['2026-09-02', null, 4095.00, 'S/R', 'DEPOSITO RENTA LOCAL', 'CORDOBA'],
        ['2026-09-09', null, 4095.00, 'S/R', 'DEPOSITO RENTA LOCAL', 'ORIZABA'],
        ['2026-09-16', null, 4095.00, 'S/R', 'DEPOSITO RENTA LOCAL', 'CORDOBA'],
        ['2026-09-23', null, 4095.00, 'S/R', 'DEPÓSITO  RENTA LOCAL', 'CORDOBA'],
        ['2026-09-03', 15000.00, null, 'REQ-77', 'RENTA OFICINA SEPTIEMBRE', 'CORPORATIVO'],
        ['2026-09-04', 800.00, null, 'S/R', 'PAPELERIA', 'CORPORATIVO'],
    ]));
    app(EstadoResultadosProcessor::class)->process($period);

    return $period;
}

function pgDataset(Period $period): array
{
    return app(EstadoResultadosDatasetBuilder::class)->build($period, 'CONSOLIDADO');
}

it('agrupa los 4 «DEPOSITO RENTA LOCAL» en un solo grupo con su suma y el total cuadra con el filtro Pendientes', function () {
    $period = pgPeriod();
    $d = pgDataset($period);
    $pending = collect($d['movements'])->filter(fn ($m) => MovementControlService::controlOf($m)['status'] === MovementControlService::PENDIENTE);
    $lerom = $pending->filter(fn ($m) => str_contains(PendingGroupService::normalizeConcept($m['concept']), 'DEPOSITO RENTA LOCAL'));
    expect($lerom)->toHaveCount(4) // las 4 aportaciones siguen pendientes
        ->and(PendingGroupService::normalizeConcept('Depósito  Renta Local REF 000123 15/09/2026'))->toBe('DEPOSITO RENTA LOCAL');

    $res = $this->actingAs(G::admin())->getJson("/estado-resultados/{$period->id}/control-movimientos/grupos")->assertOk()->json();
    $group = collect($res['groups'])->firstWhere('label', 'DEPOSITO RENTA LOCAL');

    expect($group)->not->toBeNull()
        ->and($group['direction'])->toBe('abono')
        ->and($group['count'])->toBe(4)
        ->and($group['total'])->toBe('16380.00')
        ->and($group['typical_amount'])->toBe('4095.00')
        ->and($group['typical_count'])->toBe(4)
        ->and($group['rule_text'])->toBe('DEPOSITO RENTA LOCAL')
        ->and($group['rule_covers'])->toBe(4)
        ->and(collect($group['branches'])->pluck('count', 'name')->all())->toBe(['CORDOBA' => 3, 'ORIZABA' => 1])
        ->and($res['groups'][0]['label'])->toBe('DEPOSITO RENTA LOCAL') // el más repetido primero
        // Conteos: la suma de los grupos = contador «Pendientes» = filas que muestra ese filtro.
        ->and($res['total_pending'])->toBe($pending->count())
        ->and(collect($res['groups'])->sum('count') + $res['redacted'])->toBe($pending->count());

    // Los filtros de estado particionan «Todos» (cada fila cae en exactamente un estado).
    $byStatus = collect($d['movements'])->countBy(fn ($m) => MovementControlService::controlOf($m)['status']);
    expect($byStatus->sum())->toBe(count($d['movements']));
});

it('aplicar el grupo deja bitácora con usuario y motivo; deshacer lo regresa a la regla automática', function () {
    $period = pgPeriod();
    $d = pgDataset($period);
    $lerom = collect($d['movements'])->filter(fn ($m) => str_contains(PendingGroupService::normalizeConcept($m['concept']), 'DEPOSITO RENTA LOCAL'))->pluck('fingerprint')->all();
    $erp = collect($d['movements'])->firstWhere('concept', 'RENTA OFICINA SEPTIEMBRE')['fingerprint'];
    $admin = G::admin();

    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/control-movimientos/grupos/clasificar", [
        'fingerprints' => [...$lerom, $erp], 'treatment' => T::CAPITAL_DEUDA, 'notes' => 'Depósitos de renta del local',
    ])->assertOk()->assertJsonPath('count', 4)->assertJsonPath('excluded', 1);

    $rows = EstadoResultadosClassification::query()->where('period_id', $period->id)->get();
    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('user_id')->unique()->all())->toBe([$admin->id])
        ->and($rows->pluck('previous_treatment')->unique()->all())->toBe([T::PENDIENTE])
        ->and($rows->pluck('item_fingerprint')->all())->not->toContain($erp); // lo conciliado nunca se toca

    $after = collect(pgDataset($period)['movements'])->whereIn('fingerprint', $lerom);
    expect($after->pluck('treatment')->unique()->all())->toBe([T::CAPITAL_DEUDA])
        ->and($after->first()['override']['user'])->toBe($admin->name);

    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/control-movimientos/deshacer", ['fingerprints' => $lerom, 'notes' => 'Me equivoqué de grupo'])
        ->assertOk()->assertJsonPath('count', 4);
    expect(EstadoResultadosClassification::query()->where('period_id', $period->id)->count())->toBe(8)
        ->and(collect(pgDataset($period)['movements'])->whereIn('fingerprint', $lerom)->pluck('treatment')->unique()->all())->toBe([T::PENDIENTE]);
});

it('sin permiso «clasificar» la vista previa, el grupo y deshacer responden 403 (agrupar sí se puede leer)', function () {
    $period = pgPeriod();
    $fp = collect(pgDataset($period)['movements'])->firstWhere('concept', 'PAPELERIA')['fingerprint'];
    $viewer = G::userWith([]);
    $body = ['fingerprints' => [$fp], 'treatment' => T::OPEX_CORPORATIVO, 'notes' => 'Prueba'];

    $this->actingAs($viewer)->getJson("/estado-resultados/{$period->id}/control-movimientos/grupos")->assertOk();
    $this->actingAs($viewer)->postJson("/estado-resultados/{$period->id}/control-movimientos/grupos/vista-previa", $body)->assertForbidden();
    $this->actingAs($viewer)->postJson("/estado-resultados/{$period->id}/control-movimientos/grupos/clasificar", $body)->assertForbidden();
    $this->actingAs($viewer)->postJson("/estado-resultados/{$period->id}/control-movimientos/deshacer", $body)->assertForbidden();
    expect(EstadoResultadosClassification::query()->count())->toBe(0);
});
