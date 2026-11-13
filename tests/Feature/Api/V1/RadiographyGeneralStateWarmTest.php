<?php

use App\Console\Commands\WarmRadiografiaGeneralCommand;
use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\RadiografiaExportService;
use App\Services\Radiography\BranchRadiographyCalculator;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * 13-nov-2026 — la corrida general de la radiografía (la que leen
 * /api/v1/employees/{id}/financial-summary y /api/v1/periods/{id}/branches) se
 * calcula UNA sola vez por clave (candado), se precalienta con
 * `radiografia:precalentar` y las respuestas de la API son idénticas en frío y en
 * caliente. El builder real se sustituye por uno que cuenta corridas: aquí se prueba
 * el mecanismo de caché, no las cifras (esas tienen sus propias pruebas).
 */
function rgwPeriod(int $month, string $type = 'monthly'): Period
{
    return Period::query()->create([
        'name' => "RGW Mes {$month} 2026", 'code' => sprintf('M-RGW-2026-%02d', $month), 'type' => $type,
        'year' => 2026, 'month' => $month, 'sequence' => 1,
        'start_date' => sprintf('2026-%02d-01', $month), 'end_date' => sprintf('2026-%02d-28', $month), 'is_closed' => false,
    ]);
}

function rgwSummary(Period $period): PeriodSummary
{
    return PeriodSummary::query()->create([
        'period_id' => $period->id, 'status' => 'generated', 'generated_at' => now(), 'global_metrics' => [],
    ]);
}

function rgwKey(Period $period, PeriodSummary $summary): string
{
    return sprintf('radiography_general_state:%d:%d:%d', $period->id, $summary->id, $summary->fresh()->updated_at?->timestamp ?? 0);
}

function rgwToken(array $abilities): string
{
    $consumer = ApiConsumer::query()->create([
        'name' => 'RGW', 'system_code' => 'rgw-'.uniqid(), 'is_active' => true, 'abilities' => $abilities,
        'branch_access_mode' => ApiConsumer::BRANCH_ACCESS_GLOBAL, 'allowed_branch_ids' => null,
    ]);

    return $consumer->createToken('test', $abilities)->plainTextToken;
}

function rgwGeneralSnapshot(): array
{
    $calc = app(BranchRadiographyCalculator::class);
    $tula = array_merge($calc->emptyBranchSummary('TULA'), [
        'valor_cartera' => 1_000_000.0, 'colocacion' => 400_000.0, 'recuperacion_total' => 500_000.0,
        'interes_recuperado' => 90_000.0, 'impuesto_recuperado' => 14_400.0, 'cartera_vencida' => 70_000.0,
        'gastos_operativos' => 30_000.0, 'nomina_total' => 45_000.0,
    ]);
    $unassigned = (new ReflectionMethod($calc, 'emptyUnassigned'))->invoke($calc);
    $global = $calc->sumGlobal([$tula], $unassigned);

    return [
        'generated_at' => '13/11/2026 09:00',
        'scope' => ['type' => 'general', 'available' => true],
        'branch_radiography' => ['branches' => [$tula], 'global' => $global, 'unassigned' => $unassigned],
        'summary' => [
            'recovery_total' => 500_000.0, 'placement_total' => 400_000.0, 'portfolio_total' => 1_000_000.0,
            'overdue_portfolio' => 70_000.0, 'mora_index' => 7.0, 'opex_total' => 30_000.0,
            'ebitda_final' => BranchRadiographyCalculator::ebitdaFinalFor($global),
            'margen_ebitda' => BranchRadiographyCalculator::margenEbitdaFor($global),
        ],
        'sections' => [],
    ];
}

/**
 * Sustituye el builder pesado por uno que cuenta corridas generales. $duringBuild se
 * ejecuta DENTRO del cálculo (mientras el candado está tomado).
 *
 * @return object{general:int, projections:int}
 */
function rgwFakeBuilder(?Closure $duringBuild = null): object
{
    $calls = (object) ['general' => 0, 'projections' => 0];
    $mock = Mockery::mock(RadiographySnapshotBuilder::class);
    $mock->shouldReceive('buildGeneralState')->andReturnUsing(function (Period $p, PeriodSummary $s) use ($calls, $duringBuild) {
        $calls->general++;
        if ($duringBuild) {
            $duringBuild($p, $s);
        }

        return ['snapshot' => rgwGeneralSnapshot(), 'empGestores' => [], 'gm' => []];
    });
    $mock->shouldReceive('projectScopeFromState')->andReturnUsing(function (array $state, Period $p, array $config) use ($calls) {
        $calls->projections++;

        return [
            'generated_at' => $state['snapshot']['generated_at'],
            'scope' => ['type' => 'employee', 'employee_id' => $config['employee_id'] ?? null, 'available' => true],
            'summary' => [
                'ebitda_final' => 1234.5, 'margen_ebitda' => 12.3, 'opex_total' => 450.0, 'placement_total' => 20_000.0,
                'recovery_total' => 15_000.0, 'portfolio_total' => 60_000.0, 'overdue_portfolio' => 10_000.0, 'mora_index' => 16.67,
            ],
            'sections' => [],
        ];
    });
    app()->instance(RadiographySnapshotBuilder::class, $mock);
    app()->forgetInstance(RadiografiaExportService::class);

    return $calls;
}

/** Instancia nueva del servicio = otra petición/proceso (sin el memo por instancia). */
function rgwService(): RadiografiaExportService
{
    app()->forgetInstance(RadiografiaExportService::class);

    return app()->make(RadiografiaExportService::class);
}

// ── Una sola corrida ────────────────────────────────────────────────────────

it('calcula la corrida general UNA sola vez aunque dos peticiones consecutivas lleguen en frío', function () {
    $period = rgwPeriod(9);
    rgwSummary($period);
    $calls = rgwFakeBuilder();

    $first = rgwService()->buildSnapshot($period);
    $second = rgwService()->buildSnapshot($period);                         // otra «petición»
    $scoped = rgwService()->buildSnapshot($period, ['scope' => 'general']); // misma corrida

    expect($calls->general)->toBe(1)
        ->and($second)->toEqual($first)
        ->and($scoped)->toEqual($first)
        ->and($first)->toEqual(rgwGeneralSnapshot());
});

it('mantiene el candado durante el cálculo en frío: quien llega a la vez no calcula en paralelo', function () {
    $period = rgwPeriod(9);
    $summary = rgwSummary($period);
    $key = rgwKey($period, $summary);
    $seen = (object) ['lockFree' => null, 'concurrentWarm' => null];

    $calls = rgwFakeBuilder(function (Period $p) use ($key, $seen) {
        // Otro proceso que llega MIENTRAS se calcula: el candado ya está tomado…
        $other = Cache::lock('lock:'.$key, 10);
        $seen->lockFree = $other->get();
        if ($seen->lockFree) {
            $other->release();
        }
        // …y el precalentado concurrente no duplica la corrida.
        $seen->concurrentWarm = app()->make(RadiografiaExportService::class)->warmGeneralState($p, 0);
    });

    rgwService()->buildSnapshot($period);

    expect($seen->lockFree)->toBeFalse()
        ->and($seen->concurrentWarm)->toBe('ocupado')
        ->and($calls->general)->toBe(1);

    // Al terminar, el candado queda libre y el valor en caché.
    $after = Cache::lock('lock:'.$key, 10);
    expect($after->get())->toBeTrue();
    $after->release();
    expect(Cache::get($key))->toBeArray();
});

it('quien llega mientras otro proceso calcula recibe ese resultado sin recalcular', function () {
    $period = rgwPeriod(9);
    $summary = rgwSummary($period);
    $key = rgwKey($period, $summary);
    $calls = rgwFakeBuilder();
    $state = ['snapshot' => rgwGeneralSnapshot(), 'empGestores' => [], 'gm' => []];

    // «Otro proceso» termina su cálculo justo DESPUÉS de nuestra lectura en frío (el
    // evento CacheMissed se dispara al fallar esa lectura): al obtener el candado, la
    // segunda lectura ya encuentra el valor y no se repite la corrida.
    $written = false;
    Event::listen(CacheMissed::class, function (CacheMissed $event) use ($key, $state, &$written) {
        if (! $written && $event->key === $key) {
            $written = true;
            Cache::put($key, $state, 600);
        }
    });

    $snapshot = rgwService()->buildSnapshot($period);

    expect($written)->toBeTrue()
        ->and($calls->general)->toBe(0)
        ->and($snapshot)->toEqual($state['snapshot']);
});

it('si la espera del candado se agota calcula por su cuenta (comportamiento anterior, nunca un error)', function () {
    $period = rgwPeriod(9);
    $summary = rgwSummary($period);
    $calls = rgwFakeBuilder();
    config(['radiografia.cache.lock_espera_segundos' => 0]);

    $held = Cache::lock('lock:'.rgwKey($period, $summary), 60);
    expect($held->get())->toBeTrue();

    expect(rgwService()->buildSnapshot($period))->toEqual(rgwGeneralSnapshot())
        ->and($calls->general)->toBe(1);

    // No libera un candado que no es suyo.
    expect(Cache::lock('lock:'.rgwKey($period, $summary), 60)->get())->toBeFalse();
    $held->release();
});

it('una radiografía regenerada (summary.updated_at) usa otra clave: nunca sirve cifras de la versión anterior', function () {
    $period = rgwPeriod(9);
    $summary = rgwSummary($period);
    $calls = rgwFakeBuilder();

    rgwService()->buildSnapshot($period);
    $this->travel(5)->seconds();
    $summary->update(['generated_at' => now()]);
    rgwService()->buildSnapshot($period);

    expect($calls->general)->toBe(2);
});

// ── Comando de precalentado ─────────────────────────────────────────────────

it('radiografia:precalentar calienta los meses recientes con radiografía vigente y es idempotente', function () {
    $old = rgwPeriod(6);
    $july = rgwPeriod(7);
    $august = rgwPeriod(8);
    $noSummary = rgwPeriod(9);                 // sin radiografía: se ignora
    $invalidated = rgwPeriod(10);              // radiografía invalidada: se ignora
    foreach ([$old, $july, $august] as $p) {
        rgwSummary($p);
    }
    rgwSummary($invalidated)->update(['status' => 'invalidated', 'invalidated_at' => now()]);
    $calls = rgwFakeBuilder();

    $this->artisan('radiografia:precalentar', ['--ultimos' => 2])
        ->expectsOutputToContain($august->code)
        ->expectsOutputToContain('calculado')
        ->assertSuccessful();
    expect($calls->general)->toBe(2);

    // Segunda corrida: ya está caliente, no recalcula nada.
    $this->artisan('radiografia:precalentar', ['--ultimos' => 2])
        ->expectsOutputToContain('caliente')
        ->assertSuccessful();
    expect($calls->general)->toBe(2);

    // La API ya no paga el cálculo: la petición siguiente lee de caché.
    rgwService()->buildSnapshot($august);
    rgwService()->buildSnapshot($july);
    expect($calls->general)->toBe(2);
});

it('radiografia:precalentar refresca el valor antes de que caduque y respeta el interruptor', function () {
    $period = rgwPeriod(8);
    $summary = rgwSummary($period);
    $calls = rgwFakeBuilder();
    config(['radiografia.precalentar.refrescar_horas' => 6, 'radiografia.cache.ttl_horas' => 12]);

    $this->artisan('radiografia:precalentar')->assertSuccessful();
    expect($calls->general)->toBe(1);

    $this->travel(5)->hours();
    $this->artisan('radiografia:precalentar')->expectsOutputToContain('caliente')->assertSuccessful();
    expect($calls->general)->toBe(1);

    // A las 7 h (< TTL de 12 h) se recalcula y reemplaza: el valor nunca llega a caducar.
    $this->travel(2)->hours();
    expect(Cache::get(rgwKey($period, $summary)))->toBeArray();
    $this->artisan('radiografia:precalentar')->expectsOutputToContain('refrescado')->assertSuccessful();
    expect($calls->general)->toBe(2);

    $this->artisan('radiografia:precalentar', ['--forzar' => true])->expectsOutputToContain('refrescado')->assertSuccessful();
    expect($calls->general)->toBe(3);

    config(['radiografia.precalentar.activo' => false]);
    Cache::flush(); // store `array` de pruebas, no la BD
    $this->artisan('radiografia:precalentar')->expectsOutputToContain('desactivado')->assertSuccessful();
    expect($calls->general)->toBe(3);
});

it('radiografia:precalentar no falla ni detiene a los demás periodos si uno revienta', function () {
    $july = rgwPeriod(7);
    $august = rgwPeriod(8);
    rgwSummary($july);
    rgwSummary($august);

    $mock = Mockery::mock(RadiographySnapshotBuilder::class);
    $mock->shouldReceive('buildGeneralState')->andReturnUsing(function (Period $p) use ($august) {
        if ($p->id === $august->id) {
            throw new RuntimeException('fallo simulado');
        }

        return ['snapshot' => rgwGeneralSnapshot(), 'empGestores' => [], 'gm' => []];
    });
    app()->instance(RadiographySnapshotBuilder::class, $mock);
    app()->forgetInstance(RadiografiaExportService::class);

    $this->artisan('radiografia:precalentar')
        ->expectsOutputToContain('fallo simulado')
        ->expectsOutputToContain($july->code)
        ->assertSuccessful();
});

it('el precalentado está agendado sin solaparse y en un solo servidor', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'radiografia:precalentar'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/30 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue()
        ->and(class_exists(WarmRadiografiaGeneralCommand::class))->toBeTrue();
});

// ── Las respuestas de la API no cambian ─────────────────────────────────────

it('GET /api/v1/periods/{id}/branches responde EXACTAMENTE lo mismo en frío, en caliente y tras precalentar', function () {
    $period = rgwPeriod(8);
    rgwSummary($period);
    Branch::query()->create(['code' => 'TUL', 'name' => 'TULA', 'normalized_name' => 'tula', 'is_active' => true]);
    $calls = rgwFakeBuilder();
    $h = ['Authorization' => 'Bearer '.rgwToken(['financial.read'])];
    $url = "/api/v1/periods/{$period->id}/branches";

    $cold = $this->withHeaders($h)->getJson($url)->assertOk()->assertJsonPath('status', 'ok');
    app()->forgetInstance(RadiografiaExportService::class);
    $warm = $this->withHeaders($h)->getJson($url)->assertOk();
    expect($calls->general)->toBe(1)
        ->and($warm->json())->toEqual($cold->json())
        ->and($cold->json('consolidated.kpis.ebitda.ebitda'))->toEqual(round(rgwGeneralSnapshot()['summary']['ebitda_final'], 2))
        ->and(collect($cold->json('data'))->pluck('name')->all())->toContain('TULA');

    // Caché vacía + precalentado: la petición no calcula y responde igual.
    Cache::flush(); // store `array` de pruebas, no la BD
    $this->artisan('radiografia:precalentar')->assertSuccessful();
    expect($calls->general)->toBe(2);
    app()->forgetInstance(RadiografiaExportService::class);
    $prewarmed = $this->withHeaders($h)->getJson($url)->assertOk();
    expect($calls->general)->toBe(2)
        ->and($prewarmed->json())->toEqual($cold->json());
});

it('GET /api/v1/employees/{id}/financial-summary responde EXACTAMENTE lo mismo en frío, en caliente y tras precalentar', function () {
    $period = rgwPeriod(8);
    rgwSummary($period);
    $employee = Employee::query()->create(['employee_code' => 'RGW1', 'full_name' => 'ANA RGW', 'normalized_name' => 'ana rgw', 'is_active' => true]);
    $calls = rgwFakeBuilder();
    $h = ['Authorization' => 'Bearer '.rgwToken(['employees.read', 'financial.read'])];
    $url = "/api/v1/employees/{$employee->id}/financial-summary?period_id={$period->id}";

    $cold = $this->withHeaders($h)->getJson($url)->assertOk();
    app()->forgetInstance(RadiografiaExportService::class);
    $warm = $this->withHeaders($h)->getJson($url)->assertOk();

    $metrics = collect($cold->json('metrics'))->keyBy('metric');
    expect($calls->general)->toBe(1)
        ->and($warm->json())->toEqual($cold->json())
        ->and($cold->json('employee_id'))->toBe($employee->id)
        ->and($metrics['ebitda']['status'])->toBe('ok')
        ->and($metrics['ebitda']['value'])->toEqual(1234.5)
        ->and($metrics['portfolio_current']['value'])->toEqual(50_000.0)
        ->and($metrics['ebitda']['calculated_at'])->toBe('13/11/2026 09:00');

    Cache::flush(); // store `array` de pruebas, no la BD
    $this->artisan('radiografia:precalentar')->assertSuccessful();
    expect($calls->general)->toBe(2);
    app()->forgetInstance(RadiografiaExportService::class);
    $prewarmed = $this->withHeaders($h)->getJson($url)->assertOk();
    expect($calls->general)->toBe(2)
        ->and($prewarmed->json())->toEqual($cold->json());
});

it('un periodo sin radiografía sigue respondiendo not_available (sin tocar caché ni candados)', function () {
    $period = rgwPeriod(8);
    $calls = rgwFakeBuilder();

    $this->withHeaders(['Authorization' => 'Bearer '.rgwToken(['financial.read'])])
        ->getJson("/api/v1/periods/{$period->id}/branches")
        ->assertOk()->assertJsonPath('status', 'not_available');
    expect($calls->general)->toBe(0);
});
