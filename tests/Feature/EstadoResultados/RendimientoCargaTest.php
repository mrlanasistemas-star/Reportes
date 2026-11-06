<?php

use App\Jobs\WarmEstadoResultadosComparativesJob;
use App\Models\User;
use App\Services\EstadoResultados\Comparatives\ComparativesWarmer;
use App\Services\EstadoResultados\Comparatives\ComparisonService;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Reporting\EstadoResultadosAbilities;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * Rendimiento y experiencia de carga del Estado de Resultados (06-nov-2026): precalentado de
 * comparativas, cabeceras de tiempos, periodo inexistente en el enlace, capacidades según el
 * alcance por sucursal y errores técnicos que nunca llegan al usuario.
 */
function rcBranchUser(): User
{
    F::branch('CORDOBA');
    $user = G::userWith(['exportar', 'aprobar', 'cargar', 'procesar', 'clasificar', 'ajustes']);
    $user->forceFill(['branch_scope' => 'branches'])->save();
    DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => F::branch('CORDOBA')->id, 'created_at' => now(), 'updated_at' => now()]);

    return User::query()->find($user->id);
}

it('capacidades: un usuario limitado a sucursal no recibe controles que el servidor le rechazaría', function () {
    $can = app(EstadoResultadosAbilities::class)->for(rcBranchUser());

    expect($can['cargar'])->toBeFalse()
        ->and($can['procesar'])->toBeFalse()
        ->and($can['clasificar'])->toBeFalse()
        ->and($can['aprobar'])->toBeFalse()
        ->and($can['historial_clasificacion'])->toBeFalse()
        ->and($can['acceso_general'])->toBeFalse()
        ->and($can['ajustes'])->toBeTrue()       // por sucursal sí puede ajustar su alcance
        ->and($can['exportar'])->toBeTrue()
        ->and($can['motivo_restriccion'])->toBeString();

    $admin = app(EstadoResultadosAbilities::class)->for(G::admin());
    expect($admin['cargar'])->toBeTrue()
        ->and($admin['aprobar'])->toBeTrue()
        ->and($admin['acceso_general'])->toBeTrue()
        ->and($admin['motivo_restriccion'])->toBeNull();
});

it('un enlace con un periodo que ya no existe abre el más reciente y avisa', function () {
    $this->withoutVite();
    $older = F::period('2026-08-01', '2026-08-31');
    $latest = F::period('2026-09-01', '2026-09-30');

    $this->actingAs(G::admin())->get('/estado-resultados?periodo=999999')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('initialPeriodId', $latest->id)
            ->where('periodNotice', fn ($m) => is_string($m) && str_contains($m, 'ya no está disponible')));

    $this->actingAs(G::admin())->get("/estado-resultados?periodo={$older->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('initialPeriodId', $older->id)
            ->where('periodNotice', null));
});

it('procesar: un error técnico se informa con referencia, nunca con el detalle (SQL, rutas)', function () {
    $p = F::period();
    $this->mock(EstadoResultadosProcessor::class)->shouldReceive('process')
        ->andThrow(new RuntimeException('SQLSTATE[42S02]: tabla secreta C:\\ruta\\interna.php'));

    $res = $this->actingAs(G::admin())->postJson("/estado-resultados/{$p->id}/procesar")->assertStatus(500);

    expect($res->json('message'))->not->toContain('SQLSTATE')
        ->and($res->json('message'))->not->toContain('ruta')
        ->and($res->json('message'))->toContain('referencia')
        ->and($res->json('reference'))->toStartWith('ERR-');
});

it('dataset: informa sus tiempos en Server-Timing y precalienta comparativas sólo con cola asíncrona', function () {
    F::fakeRadiographyByPeriod([]);
    $p = F::period();
    F::fakeRadiographyByPeriod([$p->id => ['CORDOBA' => ['interes_recuperado' => 1000.0]]]);

    // Cola sync (pruebas / sin worker): no se encola nada, la petición no se alenta.
    Queue::fake();
    $res = $this->actingAs(G::admin())->getJson("/estado-resultados/{$p->id}/dataset")->assertOk();
    expect($res->headers->get('Server-Timing'))->toContain('er;')->toContain('base;');
    Queue::assertNothingPushed();

    // Cola asíncrona: se encola un precalentado por periodo.
    config(['queue.default' => 'database']);
    $this->actingAs(G::admin())->getJson("/estado-resultados/{$p->id}/dataset")->assertOk();
    Queue::assertPushed(WarmEstadoResultadosComparativesJob::class, fn ($job) => $job->periodId === $p->id);

    // Con el precalentado desactivado por configuración, tampoco.
    config(['estado_resultados.cache.precalentar' => false]);
    expect(ComparativesWarmer::dispatchFor($p))->toBeFalse();
});

it('el precalentado deja las comparativas en caché con las MISMAS cifras que la consulta normal', function () {
    F::fakeRadiographyByPeriod([]);
    $prev = F::period('2026-08-01', '2026-08-31');
    $p = F::period('2026-09-01', '2026-09-30');
    F::fakeRadiographyByPeriod([
        $prev->id => ['CORDOBA' => ['interes_recuperado' => 800.0]],
        $p->id => ['CORDOBA' => ['interes_recuperado' => 1000.0]],
    ]);

    (new WarmEstadoResultadosComparativesJob($p->id))->handle(app(ComparativesWarmer::class));

    app()->forgetScopedInstances();
    $after = app(ComparisonService::class)->build($p, null, null, ['modes' => ['anterior'], 'charts' => false]);
    expect($after['diagnostics']['periods']['cache_miss'])->toBe(0)
        ->and($after['diagnostics']['periods']['cache_hit'])->toBeGreaterThanOrEqual(2);

    // Mismas cifras que sin caché.
    Cache::flush(); // store `array` de pruebas, no la BD
    app()->forgetScopedInstances();
    $cold = app(ComparisonService::class)->build($p, null, null, ['modes' => ['anterior'], 'charts' => false]);
    expect($after['modes']['anterior']['comparison'] ?? $after['modes']['anterior'])
        ->toEqual($cold['modes']['anterior']['comparison'] ?? $cold['modes']['anterior']);
});

it('comando er:precalentar-comparativas calienta los periodos indicados', function () {
    F::fakeRadiographyByPeriod([]);
    $p = F::period();
    F::fakeRadiographyByPeriod([$p->id => ['CORDOBA' => ['interes_recuperado' => 1000.0]]]);

    $this->artisan('er:precalentar-comparativas', ['--periodo' => [$p->id]])
        ->expectsOutputToContain($p->code)
        ->assertSuccessful();
});
