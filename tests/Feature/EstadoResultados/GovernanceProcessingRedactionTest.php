<?php

use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Reporting\EstadoResultadosLockedException;
use App\Services\EstadoResultados\Reporting\EstadoResultadosRedactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * Procesamiento bajo gobierno (409 en curso / 423 aprobado) y cobertura del snapshot
 * y de la redacción sobre las claves nuevas del dataset (validation, dimensions,
 * meta.recognition, movements[].{dimensions,dates,amounts,links}).
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
    F::fakeRadiography();
});

it('procesar con otro procesamiento en curso responde 409 con su mensaje (no 500)', function () {
    $period = F::period();
    G::import($period, F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO']]));
    $lock = Cache::lock(EstadoResultadosProcessor::lockKey($period->id), 600);
    expect($lock->get())->toBeTrue();

    $this->actingAs(G::admin())->postJson("/estado-resultados/{$period->id}/procesar")
        ->assertStatus(409)
        ->assertJsonPath('processing', true)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'Ya hay un procesamiento de conciliación en curso'));

    $lock->release();
    $this->actingAs(G::admin())->postJson("/estado-resultados/{$period->id}/procesar")->assertOk();
});

it('reprocesar un periodo aprobado se bloquea con 423 (ruta y servicio) y no crea corridas', function () {
    $period = G::readyPeriod();
    $admin = G::admin();
    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/aprobar")->assertOk();
    $runs = DB::table('estado_resultados_runs')->where('period_id', $period->id)->count();

    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/procesar")
        ->assertStatus(423)
        ->assertJsonPath('locked', true)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'está aprobado (versión 1)'));
    expect(fn () => app(EstadoResultadosProcessor::class)->process($period))->toThrow(EstadoResultadosLockedException::class)
        ->and(DB::table('estado_resultados_runs')->where('period_id', $period->id)->count())->toBe($runs);

    // Con nueva versión abierta vuelve a poder procesarse.
    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/nueva-version", ['reason' => 'Llegó un movimiento tardío del banco'])->assertOk();
    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/procesar")->assertOk();
});

it('el snapshot congela también validation, dimensions, meta.recognition y el detalle por movimiento', function () {
    $period = G::readyPeriod();
    $admin = G::admin();
    $live = $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/dataset")->json('dataset');
    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/aprobar")->assertOk();

    // Cambia el origen después de aprobar.
    F::fakeRadiography(['interes_recuperado' => 1.00]);
    F::erp($period, 'CORPORATIVO', 'REQ-TARDE', '99999.00', '2026-09-20', 'Luz', 'LUZ EXTRA');
    $after = $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/dataset")->json('dataset');

    foreach (['validation', 'dimensions'] as $key) {
        expect($live)->toHaveKey($key)
            ->and($after[$key])->toEqual($live[$key]);
    }
    expect($after['meta']['recognition'])->toEqual($live['meta']['recognition'])
        ->and(app(EstadoResultadosDatasetBuilder::class)->build($period)['validation'])->not->toEqual($live['validation']);
    foreach ($live['movements'] as $i => $m) {
        foreach (['dimensions', 'dates', 'amounts', 'links'] as $k) {
            expect($m)->toHaveKey($k)
                ->and($after['movements'][$i][$k])->toEqual($m[$k]);
        }
    }
});

it('redacta vínculos, evidencia NOI y renglones de validación de movimientos de nómina/DG sin el permiso', function () {
    $payroll = ['fingerprint' => 'fp-nom', 'treatment' => 'cubierto_radiografia', 'rule_code' => 'otra', 'concept' => 'PAGO JUAN PEREZ',
        'dimensions' => ['category' => ['key' => 'nomina_sucursales']], 'links' => [], 'noi_employee_id' => 7];
    $dg = ['fingerprint' => 'fp-dg', 'treatment' => 'pendiente', 'rule_code' => 'otra', 'concept' => 'VIAJE DG',
        'dimensions' => ['category' => ['key' => 'opex_dg']], 'links' => []];
    $visible = ['fingerprint' => 'fp-ok', 'treatment' => 'opex_corporativo', 'rule_code' => 'erp_corporativo_opex', 'concept' => 'LUZ', 'noi_employee_id' => null,
        'links' => [
            ['kind' => 'conciliacion', 'target' => 'fp-nom', 'applied' => '10.00', 'evidence' => ['folio' => 'REQ-1']],
            ['kind' => 'reembolso', 'target' => 'emp-7', 'applied' => '5.00', 'evidence' => ['employee' => 'JUAN PEREZ', 'branch' => 'CORDOBA', 'noi_total' => '9000.00']],
            ['kind' => 'conciliacion', 'target' => 'erp-ok', 'applied' => '1.00', 'evidence' => ['folio' => 'REQ-2']],
        ]];
    // Un pago con vínculo de evidencia NOI es, por sí mismo, detalle de nómina.
    $noiPaid = ['fingerprint' => 'fp-noi', 'treatment' => 'cubierto_radiografia', 'rule_code' => 'otra', 'concept' => 'SPEI JUAN',
        'links' => [['kind' => 'evidencia_noi', 'target' => 'emp-7', 'applied' => '1.00', 'evidence' => ['employee' => 'JUAN PEREZ']]]];
    $dataset = [
        'meta' => ['dataset_hash' => 'h'],
        'statement' => [],
        'movements' => [$payroll, $dg, $visible, $noiPaid],
        'validation' => [
            'differences' => ['by_folio' => ['count' => 2, 'amount' => '30.00', 'items' => [
                ['fingerprint' => 'fp-nom', 'concept' => 'PAGO JUAN PEREZ', 'folio' => 'REQ-9', 'bank_amount' => '20.00', 'message' => 'Folio sin ERP JUAN'],
                ['fingerprint' => 'fp-ok', 'concept' => 'LUZ', 'folio' => 'REQ-8', 'bank_amount' => '10.00', 'message' => 'ok'],
            ]]],
            'eliminations' => ['unpaired_transfers' => ['count' => 1, 'items' => [['fingerprint' => 'fp-dg', 'concept' => 'VIAJE DG', 'cargo' => '7.00']]]],
            'noi' => ['employees' => 3, 'total' => '9000.00'],
        ],
    ];
    $r = app(EstadoResultadosRedactor::class);

    $red = $r->apply($dataset, false, false);
    $json = json_encode($red);
    expect(array_column($red['movements'], 'fingerprint'))->toBe(['fp-ok'])
        ->and($json)->not->toContain('JUAN')->not->toContain('VIAJE DG')->not->toContain('fp-nom')->not->toContain('fp-dg')
        ->and($red['movements'][0]['links'][0])->toMatchArray(['target' => null, 'evidence' => [], 'redacted' => true, 'applied' => '10.00'])
        ->and($red['movements'][0]['links'][1])->toMatchArray(['evidence' => [], 'redacted' => true, 'applied' => '5.00'])
        ->and($red['movements'][0]['links'][2]['evidence'])->toBe(['folio' => 'REQ-2'])
        ->and($red['validation']['differences']['by_folio']['count'])->toBe(2)
        ->and($red['validation']['differences']['by_folio']['amount'])->toBe('30.00')
        ->and($red['validation']['differences']['by_folio']['items'][0])->toMatchArray(['concept' => 'Detalle restringido', 'bank_amount' => '20.00', 'redacted' => true])
        ->and($red['validation']['differences']['by_folio']['items'][1]['concept'])->toBe('LUZ')
        ->and($red['validation']['eliminations']['unpaired_transfers']['items'][0]['cargo'])->toBe('7.00')
        ->and($red['validation']['noi'])->toBe(['employees' => 3, 'total' => '9000.00'])
        ->and($red['meta']['redactions']['hidden_movements'])->toBe(['nomina' => 2, 'dg' => 1])
        ->and($red['meta']['redactions']['redacted_references'])->toBeGreaterThanOrEqual(4);

    // Con ambos permisos no se toca nada.
    $full = $r->apply($dataset, true, true);
    expect($full['movements'])->toBe($dataset['movements'])
        ->and($full['validation'])->toBe($dataset['validation'])
        ->and($full['meta']['redactions']['redacted_references'])->toBe(0);

    // Con ver_nomina pero sin ver_dg: la evidencia NOI se ve; lo de DG no.
    $partial = $r->apply($dataset, true, false);
    expect(json_encode($partial))->toContain('JUAN PEREZ')->not->toContain('VIAJE DG');
});

it('en el dataset real, un usuario sin ver_nomina no recibe evidencia NOI ni el nombre del colaborador', function () {
    $period = G::readyPeriod();
    $user = G::userWith([]);
    $d = $this->actingAs($user)->getJson("/estado-resultados/{$period->id}/dataset")->assertOk()->json('dataset');

    $evidence = collect($d['movements'])->flatMap(fn ($m) => $m['links'] ?? [])->pluck('evidence')->filter();
    expect(json_encode($d))->not->toContain('EMPLEADO CONFIDENCIAL')
        ->and($evidence->contains(fn ($e) => isset($e['employee'])))->toBeFalse()
        ->and(collect($d['movements'])->pluck('noi_employee_id')->filter()->all())->toBe([]);
});
