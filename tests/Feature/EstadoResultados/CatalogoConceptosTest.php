<?php

use App\Models\EstadoResultados\EstadoResultadosConceptRule;
use App\Models\EstadoResultados\EstadoResultadosConceptRuleLog;
use App\Models\Period;
use App\Models\User;
use App\Services\Access\ActionPermissionService;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Classification\ClassificationService;
use App\Services\EstadoResultados\Classification\ConceptCatalogService;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Reporting\ApprovalInputSignature;
use App\Services\EstadoResultados\Support\Treatment as T;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * Catálogo de conceptos (20-nov-2026): «cuando en el estado de cuenta venga ESTE texto, el
 * movimiento va a TAL concepto del reporte». Gana sobre las reglas automáticas por texto, nunca
 * sobre lo conciliado con ERP; lo manual gana sobre el catálogo; el reporte agrupa por concepto.
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
    F::fakeRadiography();
});

function ccRule(array $over = []): EstadoResultadosConceptRule
{
    return app(ConceptCatalogService::class)->create(array_replace([
        'name' => 'Renta de oficinas', 'match_text' => 'renta oficina', 'match_mode' => 'contiene',
        'fields' => ['concept', 'observations'], 'account_slot' => null, 'direction' => 'cualquiera',
        'treatment' => T::OPEX_CORPORATIVO, 'priority' => 100,
    ], $over), null);
}

function ccBuild(Period $period): array
{
    app(EstadoResultadosProcessor::class)->process($period);

    return app(EstadoResultadosDatasetBuilder::class)->build($period, 'CONSOLIDADO');
}

function ccMovement(array $d, string $concept): array
{
    return collect($d['movements'])->firstWhere('concept', $concept);
}

/** Líneas de las categorías de OPEX (label => amount). */
function ccOpexLines(array $d): array
{
    $opex = collect($d['statement'])->firstWhere('key', 'opex');

    return collect($opex['categories'])->flatMap(fn ($c) => $c['lines'])->pluck('amount', 'label')->all();
}

it('una regla del catálogo clasifica un pago que antes quedaba pendiente y guarda su concepto', function () {
    $period = F::period();
    G::import($period, F::adminXlsx([
        ['2026-09-03', 15000.00, null, 'S/R', 'PAGO RENTA OFICINA CENTRAL', 'CORPORATIVO'],
        ['2026-09-04', 800.00, null, 'S/R', 'PAPELERIA', 'CORPORATIVO'],
    ]));

    $before = ccMovement(ccBuild($period), 'PAGO RENTA OFICINA CENTRAL');
    expect($before['treatment'])->toBe(T::PENDIENTE);

    $rule = ccRule(['match_text' => 'Rénta   Oficina']); // normaliza acentos / espacios
    $m = ccMovement($d = ccBuild($period), 'PAGO RENTA OFICINA CENTRAL');

    expect($m['treatment'])->toBe(T::OPEX_CORPORATIVO)
        ->and($m['rule_code'])->toBe('catalogo:'.$rule->id)
        ->and($m['rule_reason'])->toContain('Catálogo de conceptos: “Rénta Oficina” → Renta de oficinas (OPEX Corporativo)')
        ->and($m['treatment_source'])->toBe('catalogo')
        ->and($m['concept_rule_id'])->toBe($rule->id)
        ->and($m['concept_label'])->toBe('Renta de oficinas')
        ->and($m['needs_review'])->toBeFalse()
        ->and(ccMovement($d, 'PAPELERIA')['treatment'])->toBe(T::PENDIENTE)
        ->and(ccMovement($d, 'PAPELERIA')['concept_rule_id'])->toBeNull();

    // Filtros: sólo abonos ⇒ ya no aplica a un cargo.
    app(ConceptCatalogService::class)->update($rule, ['direction' => 'abono'] + $rule->only(['name', 'match_text', 'match_mode', 'fields', 'treatment', 'priority']), null);
    expect(ccMovement(ccBuild($period), 'PAGO RENTA OFICINA CENTRAL')['treatment'])->toBe(T::PENDIENTE);
});

it('no aplica a lo conciliado con el ERP (nunca doble gasto) ni a traspasos entre cuentas propias', function () {
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-77', '15000.00', '2026-09-03', 'Rentas', 'RENTA OFICINA');
    G::import($period, F::adminXlsx([
        ['2026-09-03', 15000.00, null, 'REQ-77', 'RENTA OFICINA SEPTIEMBRE', 'CORPORATIVO'],
        ['2026-09-05', 500.00, null, 'S/R', 'TRASPASO ENTRE CUENTAS RENTA OFICINA', 'CORPORATIVO'],
    ]));
    ccRule(['treatment' => T::OPEX_CORPORATIVO]);

    $d = ccBuild($period);
    $erp = ccMovement($d, 'RENTA OFICINA SEPTIEMBRE');
    expect($erp['treatment'])->toBe(T::CONCILIADO_ERP)
        ->and($erp['concept_rule_id'])->toBeNull()
        ->and(ccMovement($d, 'TRASPASO ENTRE CUENTAS RENTA OFICINA')['treatment'])->toBe(T::TRANSFERENCIA_INTERNA)
        ->and(ccOpexLines($d))->not->toHaveKey('Bancos · Renta de oficinas');

    // La vista previa lo explica antes de guardar.
    $preview = app(ConceptCatalogService::class)->preview($period, ['match_text' => 'renta oficina', 'treatment' => T::OPEX_CORPORATIVO]);
    expect($preview['total'])->toBe(2)->and($preview['applies'])->toBe(0)
        ->and(collect($preview['items'])->pluck('blocked_reason')->implode(' '))->toContain('conciliado con el ERP')->toContain('traspaso');
});

it('la clasificación manual del movimiento gana sobre el catálogo', function () {
    $period = F::period();
    G::import($period, F::adminXlsx([['2026-09-03', 15000.00, null, 'S/R', 'RENTA OFICINA', 'CORPORATIVO']]));
    ccRule();
    $m = ccMovement(ccBuild($period), 'RENTA OFICINA');
    app(ClassificationService::class)->classify($period, [['fingerprint' => $m['fingerprint'], 'source' => 'banco', 'current' => $m['treatment']]], T::CAPEX, 'Es la compra del local, no renta', null);

    $d = ccBuild($period);
    $after = ccMovement($d, 'RENTA OFICINA');
    expect($after['treatment'])->toBe(T::CAPEX)
        ->and($after['treatment_source'])->toBe('manual')
        ->and(ccOpexLines($d))->not->toHaveKey('Bancos · Renta de oficinas');

    $preview = app(ConceptCatalogService::class)->preview($period, ['match_text' => 'renta oficina', 'treatment' => T::OPEX_CORPORATIVO]);
    expect($preview['applies'])->toBe(0)->and($preview['items'][0]['blocked_reason'])->toContain('manual');
});

it('si dos reglas coinciden gana la de mayor prioridad y, si empatan, la más específica; el conflicto se expone', function () {
    $period = F::period();
    G::import($period, F::adminXlsx([['2026-09-03', 1000.00, null, 'S/R', 'PAGO RENTA OFICINA', 'CORPORATIVO']]));
    $general = ccRule(['name' => 'Rentas', 'match_text' => 'RENTA', 'priority' => 100, 'treatment' => T::OPEX_CORPORATIVO]);
    $specific = ccRule(['name' => 'Renta de oficinas', 'match_text' => 'RENTA OFICINA', 'priority' => 100, 'treatment' => T::OPEX_CORPORATIVO]);

    $m = ccMovement(ccBuild($period), 'PAGO RENTA OFICINA');
    expect($m['rule_code'])->toBe('catalogo:'.$specific->id)  // empate ⇒ texto más largo
        ->and($m['rule_reason'])->toContain('También coinciden: “RENTA” → Rentas');

    app(ConceptCatalogService::class)->update($general, ['priority' => 500] + $general->only(['name', 'match_text', 'match_mode', 'fields', 'direction', 'treatment']), null);
    $m = ccMovement(ccBuild($period), 'PAGO RENTA OFICINA');
    expect($m['rule_code'])->toBe('catalogo:'.$general->id)
        ->and($m['concept_label'])->toBe('Rentas');

    // La vista previa de la regla perdedora dice quién gana.
    $preview = app(ConceptCatalogService::class)->preview($period, $specific->only(['name', 'match_text', 'match_mode', 'fields', 'direction', 'treatment', 'priority']), $specific->id);
    expect($preview['applies'])->toBe(0)->and($preview['items'][0]['blocked_reason'])->toContain('Otra regla del catálogo gana');
});

it('el Estado de Resultados agrupa lo del catálogo por concepto y deja el resto como hoy', function () {
    $period = F::period();
    G::import($period, F::adminXlsx([
        ['2026-09-03', 15000.00, null, 'S/R', 'RENTA OFICINA', 'CORPORATIVO'],
        ['2026-09-04', 1200.00, null, 'S/R', 'INTERNET TELMEX', 'CORPORATIVO'],
        ['2026-09-05', 300.00, null, 'S/R', 'CAFETERIA', 'CORPORATIVO'],
    ]));
    ccRule();
    ccRule(['name' => 'Internet y telefonía', 'match_text' => 'TELMEX']);
    $cafe = ccMovement(ccBuild($period), 'CAFETERIA');
    app(ClassificationService::class)->classify($period, [['fingerprint' => $cafe['fingerprint'], 'source' => 'banco', 'current' => $cafe['treatment']]], T::OPEX_CORPORATIVO, 'Gasto de oficina', null);

    $lines = ccOpexLines(ccBuild($period));
    expect($lines['Bancos · Renta de oficinas'] ?? null)->toBe('15000.00')
        ->and($lines['Bancos · Internet y telefonía'] ?? null)->toBe('1200.00')
        ->and($lines['Pagos complementarios (estados de cuenta)'] ?? null)->toBe('300.00');
});

it('cambiar el catálogo deja el periodo desactualizado hasta reprocesar y entra a la firma de aprobación', function () {
    $period = F::period();
    G::import($period, F::adminXlsx([['2026-09-03', 15000.00, null, 'S/R', 'RENTA OFICINA', 'CORPORATIVO']]));
    $d = ccBuild($period);
    $sigBefore = app(ApprovalInputSignature::class)->compute($period);
    expect($d['sources']['procesamiento']['stale_catalog'])->toBeFalse()
        ->and($sigBefore['components'])->not->toHaveKey('concept_catalog');

    $rule = ccRule();
    $stale = app(EstadoResultadosDatasetBuilder::class)->build($period, 'CONSOLIDADO');
    expect($stale['sources']['procesamiento']['stale_catalog'])->toBeTrue()
        ->and($stale['sources']['procesamiento']['status'])->toBe('con_incidencias')
        ->and(ApprovalInputSignature::diff($sigBefore, app(ApprovalInputSignature::class)->compute($period)))->toContain('concept_catalog');

    $fresh = ccBuild($period);
    expect($fresh['sources']['procesamiento']['stale_catalog'])->toBeFalse()
        ->and($fresh['sources']['procesamiento']['last_run']['summary']['catalogo']['movimientos'])->toBe(1);

    // Desactivar también cambia la firma.
    app(ConceptCatalogService::class)->setActive($rule, false, null);
    expect(app(EstadoResultadosDatasetBuilder::class)->build($period, 'CONSOLIDADO')['sources']['procesamiento']['stale_catalog'])->toBeTrue();
});

it('CRUD por HTTP: lectura con el módulo, escritura y vista previa sólo con el permiso «catalogo»', function () {
    expect(ActionPermissionService::ACTIONS['estado_resultados'])->toHaveKey('catalogo');
    $period = F::period();
    G::import($period, F::adminXlsx([['2026-09-03', 15000.00, null, 'S/R', 'RENTA OFICINA', 'CORPORATIVO']]));
    app(EstadoResultadosProcessor::class)->process($period);

    $body = ['name' => 'Renta de oficinas', 'match_text' => 'renta oficina', 'match_mode' => 'contiene', 'fields' => ['concept'],
        'account_slot' => null, 'direction' => 'cargo', 'treatment' => T::OPEX_CORPORATIVO, 'priority' => 100, 'notes' => 'Contrato anual'];

    $viewer = G::userWith([]);
    $this->actingAs($viewer)->getJson('/estado-resultados/catalogo-conceptos')->assertOk()->assertJsonPath('rules', []);
    $this->actingAs($viewer)->postJson('/estado-resultados/catalogo-conceptos', $body)->assertForbidden();
    $this->actingAs($viewer)->postJson("/estado-resultados/{$period->id}/catalogo-conceptos/vista-previa", $body)->assertForbidden();

    $editor = G::userWith(['catalogo']);
    $this->actingAs($editor)->get('/estado-resultados')->assertInertia(fn ($p) => $p->where('can.catalogo', true));
    $this->actingAs($editor)->postJson("/estado-resultados/{$period->id}/catalogo-conceptos/vista-previa", $body)
        ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('applies', 1)->assertJsonPath('amount', '15000.00');

    // Validación en español: tratamiento de sistema no asignable, texto demasiado corto.
    $this->actingAs($editor)->postJson('/estado-resultados/catalogo-conceptos', ['treatment' => T::CONCILIADO_ERP] + $body)->assertUnprocessable();
    $this->actingAs($editor)->postJson('/estado-resultados/catalogo-conceptos', ['match_text' => ' a '] + $body)
        ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'al menos'));

    $id = $this->actingAs($editor)->postJson('/estado-resultados/catalogo-conceptos', $body)->assertCreated()
        ->assertJsonPath('rule.created_by', $editor->name)->json('rule.id');
    $this->actingAs($editor)->putJson("/estado-resultados/catalogo-conceptos/{$id}", ['priority' => 300] + $body)->assertOk()->assertJsonPath('rule.priority', 300);
    $this->actingAs($editor)->patchJson("/estado-resultados/catalogo-conceptos/{$id}/estado", ['active' => false])->assertOk()->assertJsonPath('rule.active', false);

    // Los permisos son por rol: el rol vuelve a «sólo consultar».
    $viewer = G::userWith([]);
    $this->actingAs($viewer)->getJson('/estado-resultados/catalogo-conceptos')->assertOk()->assertJsonCount(1, 'rules');
    $this->actingAs($viewer)->getJson("/estado-resultados/catalogo-conceptos/{$id}/historial")->assertOk()->assertJsonCount(3, 'logs');
    $this->actingAs($viewer)->putJson("/estado-resultados/catalogo-conceptos/{$id}", $body)->assertForbidden();
    $this->actingAs($viewer)->patchJson("/estado-resultados/catalogo-conceptos/{$id}/estado", ['active' => true])->assertForbidden();
    $this->actingAs($viewer)->deleteJson("/estado-resultados/catalogo-conceptos/{$id}")->assertForbidden();

    $editor = G::userWith(['catalogo']);
    $this->actingAs($editor)->deleteJson("/estado-resultados/catalogo-conceptos/{$id}")->assertOk();

    expect(EstadoResultadosConceptRule::query()->count())->toBe(0)
        ->and(EstadoResultadosConceptRule::withTrashed()->find($id)?->updated_by)->toBe($editor->id)
        ->and(EstadoResultadosConceptRuleLog::query()->where('rule_id', $id)->pluck('action')->all())->toBe(['creada', 'editada', 'desactivada', 'borrada']);

    // Usuario limitado a sucursales: no administra el catálogo global aunque tenga el permiso.
    $branchUser = G::userWith(['catalogo']);
    $branchUser->forceFill(['branch_scope' => 'branches'])->save();
    DB::table('user_branch_scopes')->insert(['user_id' => $branchUser->id, 'branch_id' => F::branch('CORDOBA')->id, 'created_at' => now(), 'updated_at' => now()]);
    $branchUser = User::query()->find($branchUser->id);
    $this->actingAs($branchUser)->postJson('/estado-resultados/catalogo-conceptos', $body)->assertForbidden();
});
