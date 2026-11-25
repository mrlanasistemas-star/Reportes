<?php

use App\Models\Period;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * 25-nov-2026: «Ya considerado» (total y parcial con reparto al centavo), estado de control de
 * los movimientos clasificados y cuadre de gastos e ingresos. Datos 100 % SINTÉTICOS.
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
});

function ycPeriod(): Period
{
    $p = F::period('2026-09-01', '2026-09-28');
    F::fakeRadiographyByPeriod([$p->id => ['CORDOBA' => [
        'interes_recuperado' => 1000000.00,
        'gastos_operativos' => 400000.00, 'gastos_detalle' => ['Renta Oficina' => 400000.00],
        'nomina_total' => 200000.00,
    ]]]);
    foreach (array_keys(AdjustmentService::CONCEPTS) as $concept) {
        app(AdjustmentService::class)->save($p, 'CONSOLIDADO', $concept, AdjustmentService::CONFIRMADO_CERO, null, 'prueba', null);
    }
    G::import($p, F::adminXlsx([
        ['2026-09-02', 1000.00, null, 'S/R', 'PAGO PROVEEDOR XYZ', 'CORPORATIVO'],
        ['2026-09-03', 800.00, null, 'S/R', 'COMISIONES BANCARIAS', 'CORPORATIVO'],
        ['2026-09-04', 250.55, null, 'S/R', 'GASTO VARIO SIN REGLA', 'CORPORATIVO'],
        ['2026-09-05', null, 500.10, null, 'DEPOSITO ERRONEO', null],
    ]));
    app(EstadoResultadosProcessor::class)->process($p);

    return $p;
}

function ycDataset($test, Period $p): array
{
    return $test->actingAs(G::admin())->getJson("/estado-resultados/{$p->id}/dataset")->assertOk()->json('dataset');
}

function ycMovement(array $d, string $concept): array
{
    return collect($d['movements'])->firstWhere('concept', $concept);
}

function ycCheck(array $d, string $key): array
{
    return collect($d['checks'])->firstWhere('key', $key);
}

/** Σ de las partes del cuadre de un movimiento = su bruto, al centavo. */
function ycPartsCents(array $m): int
{
    return (int) array_sum(array_map(fn ($p) => (int) round(((float) $p['amount']) * 100), $m['parts']));
}

it('el control de cada movimiento coincide con su tratamiento: auto y manual quedan «completado»', function () {
    $p = ycPeriod();
    $d = ycDataset($this, $p);
    $pending = ycMovement($d, 'GASTO VARIO SIN REGLA');
    expect($pending['treatment'])->toBe('pendiente')->and($pending['control']['status'])->toBe('pendiente');

    $this->actingAs(G::admin())->postJson("/estado-resultados/{$p->id}/clasificar", [
        'fingerprints' => [$pending['fingerprint']], 'treatment' => 'opex_corporativo', 'notes' => 'prueba',
    ])->assertOk();

    $d = ycDataset($this, $p);
    foreach ($d['movements'] as $m) {
        expect($m['control']['status'])->toBe($m['treatment'] === 'pendiente' ? 'pendiente' : 'completado');
    }
    expect(ycMovement($d, 'GASTO VARIO SIN REGLA')['control']['status'])->toBe('completado')
        ->and(ycMovement($d, 'COMISIONES BANCARIAS')['control']['status'])->toBe('completado');
});

it('«Ya considerado» parcial: la parte queda fuera, el resto suma y todo cuadra al centavo sin duplicar', function () {
    $p = ycPeriod();
    $before = ycDataset($this, $p);
    $m = ycMovement($before, 'GASTO VARIO SIN REGLA');

    $this->actingAs(G::admin())->postJson("/estado-resultados/{$p->id}/clasificar", [
        'fingerprints' => [$m['fingerprint']], 'treatment' => 'opex_corporativo', 'notes' => 'Parte ya pagada en agosto',
        'considered_reason' => 'otro_periodo', 'considered_amount' => '100.25',
    ])->assertOk();

    $d = ycDataset($this, $p);
    $m = ycMovement($d, 'GASTO VARIO SIN REGLA');
    expect($m['treatment'])->toBe('opex_corporativo')
        ->and($m['impact']['included'])->toBeTrue()
        ->and($m['impact']['amount'])->toBe('150.30')
        ->and($m['amounts']['considered'])->toBe('100.25')
        ->and($m['amounts']['excluded'])->toBe('100.25')
        ->and($m['amounts']['recognized'])->toBe('150.30')
        ->and($m['amounts']['pending'])->toBe('0.00')
        ->and(ycPartsCents($m))->toBe(25055)
        ->and($m['override']['considered_reason'])->toBe('otro_periodo')
        ->and($m['control']['status'])->toBe('completado');

    // El ER suma SÓLO el resto (150.30): el OPEX sube exactamente eso.
    $delta = (float) $d['results']['opex_total'] - (float) $before['results']['opex_total'];
    expect(round($delta, 2))->toBe(150.30);

    // Validaciones al centavo: partición total, complementarios, exclusividad y bancos.
    foreach (['particion_total', 'particion_bancos', 'complementarios_incorporados', 'exclusividad_categorias', 'huella_unica'] as $key) {
        expect(ycCheck($d, $key)['ok'])->toBeTrue("{$key} no cuadra");
    }
    expect($d['validation']['totals']['considered']['amount'])->toBe('100.25');
    $outside = collect($d['outside']['groups'])->firstWhere('key', 'ya_considerado');
    expect($outside['cargos'])->toBe('100.25');

    // Cuadre: Σ categorías = universo, diferencia $0.00; cada movimiento suma su bruto.
    $c = $d['cuadre'];
    expect($c['ok'])->toBeTrue()->and($c['difference'])->toBe('0.00');
    $cat = collect($c['categories'])->keyBy('key');
    expect($cat['ya_considerado']['total'])->toBe('100.25')
        ->and($cat['opex_corporativo']['count'])->toBeGreaterThanOrEqual(1);
    foreach ($d['movements'] as $mv) {
        expect(ycPartsCents($mv))->toBe((int) round(((float) $mv['amounts']['gross']) * 100));
    }
    expect(round((float) $c['ingresos'] + (float) $c['gastos'], 2))->toBe(round((float) $c['universe'], 2));

    // Deshacer: vuelve a la regla automática sin reparto y el periodo sigue cuadrando.
    $this->actingAs(G::admin())->postJson("/estado-resultados/{$p->id}/control-movimientos/deshacer", [
        'fingerprints' => [$m['fingerprint']], 'notes' => 'Se capturó mal',
    ])->assertOk()->assertJsonPath('count', 1);
    $d = ycDataset($this, $p);
    $m = ycMovement($d, 'GASTO VARIO SIN REGLA');
    expect($m['amounts']['considered'])->toBe('0.00')
        ->and($m['override']['considered_amount'])->toBeNull()
        ->and($d['cuadre']['ok'])->toBeTrue();

    // Bitácora con usuario y motivo.
    $h = $this->actingAs(G::admin())->getJson("/estado-resultados/{$p->id}/historial-clasificacion?fingerprint={$m['fingerprint']}")->assertOk()->json('history');
    expect($h[1]['considered_amount'])->toBe('100.25')
        ->and($h[1]['considered_reason_label'])->toBe('Ya reconocido en otro periodo')
        ->and($h[1]['notes'])->toBe('Parte ya pagada en agosto');
});

it('«Ya considerado»: valida el importe al centavo y el modo total usa el tratamiento del motivo', function () {
    $p = ycPeriod();
    $d = ycDataset($this, $p);
    $m = ycMovement($d, 'GASTO VARIO SIN REGLA');
    $other = ycMovement($d, 'DEPOSITO ERRONEO');
    $post = fn (array $body) => $this->actingAs(G::admin())->postJson("/estado-resultados/{$p->id}/clasificar", $body + ['notes' => 'prueba']);

    // Nunca más (ni igual) que el importe; ni 3 decimales; ni varios movimientos con importe.
    $post(['fingerprints' => [$m['fingerprint']], 'treatment' => 'opex_corporativo', 'considered_reason' => 'otra_fuente', 'considered_amount' => '250.55'])->assertStatus(422);
    $post(['fingerprints' => [$m['fingerprint']], 'treatment' => 'opex_corporativo', 'considered_reason' => 'otra_fuente', 'considered_amount' => '300'])->assertStatus(422);
    $post(['fingerprints' => [$m['fingerprint']], 'treatment' => 'opex_corporativo', 'considered_reason' => 'otra_fuente', 'considered_amount' => '10.001'])->assertStatus(422);
    $post(['fingerprints' => [$m['fingerprint'], $other['fingerprint']], 'treatment' => 'opex_corporativo', 'considered_reason' => 'otra_fuente', 'considered_amount' => '10'])->assertStatus(422);
    // El resto no puede ir al mismo «ya considerado».
    $post(['fingerprints' => [$m['fingerprint']], 'treatment' => 'ya_considerado', 'considered_reason' => 'otra_fuente', 'considered_amount' => '10'])->assertStatus(422);
    // Total con un tratamiento que no corresponde al motivo.
    $post(['fingerprints' => [$m['fingerprint']], 'treatment' => 'opex_corporativo', 'considered_reason' => 'radiografia'])->assertStatus(422);

    // Total en lote: Radiografía ⇒ «Ya incluido en Radiografía»; otra fuente ⇒ «Ya considerado».
    $post(['fingerprints' => [$m['fingerprint']], 'treatment' => 'cubierto_radiografia', 'considered_reason' => 'radiografia'])->assertOk();
    $post(['fingerprints' => [$other['fingerprint']], 'treatment' => 'ya_considerado', 'considered_reason' => 'otra_fuente'])->assertOk();
    $d = ycDataset($this, $p);
    expect(ycMovement($d, 'GASTO VARIO SIN REGLA')['treatment'])->toBe('cubierto_radiografia')
        ->and(ycMovement($d, 'DEPOSITO ERRONEO')['treatment'])->toBe('ya_considerado')
        ->and(ycMovement($d, 'DEPOSITO ERRONEO')['amounts']['excluded'])->toBe('500.10')
        ->and($d['cuadre']['ok'])->toBeTrue()
        ->and(collect($d['cuadre']['categories'])->firstWhere('key', 'ya_considerado')['abonos'])->toBe('500.10');
});
