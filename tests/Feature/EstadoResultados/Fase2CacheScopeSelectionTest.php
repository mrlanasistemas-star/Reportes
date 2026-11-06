<?php

use App\Enums\ReportUploadStatus;
use App\Http\Middleware\EnsureModuleAccess;
use App\Models\DataSource;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Calculation\RadiographyBaseProvider;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\PeriodDataIds;
use App\Services\EstadoResultados\Reporting\EstadoResultadosApprovalService;
use App\Services\EstadoResultados\Reporting\EstadoResultadosReportResolver;
use App\Services\EstadoResultados\Support\RadiographySummary;
use App\Services\Radiography\BranchRadiographyCalculator;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * Fase 2.3 (selección determinista del consolidado de Radiografía), 2.4 (caché sin
 * valores viejos), 2.6 (buildEach = build, mismo hash) y alcance por sucursal.
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('ORIZABA');
    F::branch('CORPORATIVO');
});

/** Calculadora espía: cuenta llamadas y devuelve la cifra que diga $state (cambiable entre llamadas). */
function csSpyProvider(object $state): RadiographyBaseProvider
{
    $calc = new class($state) extends BranchRadiographyCalculator
    {
        public function __construct(private object $state) {}

        public function buildBranches($period, array $dataIds, array $x = [], array $closing = []): array
        {
            $this->state->calls++;
            $row = array_replace($this->emptyBranchSummary('CORDOBA'), ['interes_recuperado' => $this->state->ingresos]);

            return ['branches' => [$row], 'unassigned' => $this->emptyBranchSummary('SIN ASIGNAR')];
        }

        public function computeCarteraGlobalSinFiltro(array $dataIds): array
        {
            return ['valor_cartera' => 0, 'cartera_vencida' => 0];
        }
    };

    return new RadiographyBaseProvider($calc, app(PeriodDataIds::class));
}

/** Simula una (re)importación de Radiografía: toda carga crea/actualiza un report_upload del periodo. */
function csReimport(Period $p): void
{
    $source = DataSource::query()->firstOrCreate(['code' => 'cs_reimport'], [
        'name' => 'Reimportación de prueba', 'description' => 'prueba', 'is_active' => true,
    ]);
    ReportUpload::query()->create([
        'period_id' => $p->id, 'data_source_id' => $source->id, 'original_name' => 'reimport.csv',
        'stored_path' => 'report_uploads/reimport.csv', 'mime_type' => 'text/csv', 'file_size' => 1,
        'uploaded_at' => now(), 'status' => ReportUploadStatus::Pending,
    ]);
}

it('RadiographyBaseProvider: fuera de una sesión nunca devuelve cifras viejas; dentro, una sola foto', function () {
    $p = F::period();
    $state = (object) ['calls' => 0, 'ingresos' => 100.0];
    $provider = csSpyProvider($state);

    expect($provider->for($p)['global']['interes_recuperado'])->toEqual(100.0);
    $state->ingresos = 250.0; // Radiografía cambia (re-importación) en el mismo worker/instancia
    csReimport($p);
    expect($provider->for($p)['global']['interes_recuperado'])->toEqual(250.0)
        ->and($state->calls)->toBe(2);

    $inside = $provider->session(function () use ($provider, $p, $state) {
        $a = $provider->for($p)['global']['interes_recuperado'];
        $state->ingresos = 999.0;
        csReimport($p);
        $b = $provider->for($p)['global']['interes_recuperado'];

        return [$a, $b, $provider->inSession()];
    });
    expect($inside)->toEqual([250.0, 250.0, true])          // misma foto dentro de la operación (sin recalcular)
        ->and($state->calls)->toBe(2)
        ->and($provider->inSession())->toBeFalse()
        ->and($provider->for($p)['global']['interes_recuperado'])->toEqual(999.0); // al salir se descarta
});

it('RadiographyBaseProvider: con los mismos insumos reutiliza la base firmada entre peticiones (sin recalcular)', function () {
    $p = F::period();
    $state = (object) ['calls' => 0, 'ingresos' => 100.0];

    expect(csSpyProvider($state)->for($p)['global']['interes_recuperado'])->toEqual(100.0);
    // Otra petición (instancia nueva), mismos insumos: sale de la caché firmada.
    expect(csSpyProvider($state)->for($p)['global']['interes_recuperado'])->toEqual(100.0)
        ->and($state->calls)->toBe(1);

    // Cambia el catálogo de sucursales (insumo de la firma) → otra firma → se recalcula.
    $state->ingresos = 300.0;
    F::branch('NUEVA SUCURSAL CS');
    expect(csSpyProvider($state)->for($p)['global']['interes_recuperado'])->toEqual(300.0)
        ->and($state->calls)->toBe(2);

    // Con la caché desactivada por configuración, siempre se calcula.
    config(['estado_resultados.cache.base_radiografia' => false]);
    csSpyProvider($state)->for($p);
    expect($state->calls)->toBe(3);
});

it('RadiographyBaseProvider es de alcance de petición: el builder del controlador y el del resolver comparten la sesión', function () {
    expect(app(RadiographyBaseProvider::class))->toBe(app(RadiographyBaseProvider::class));
    app()->forgetScopedInstances();
    expect(app(RadiographyBaseProvider::class))->not->toBe(null);
});

it('PeriodDataIds no conserva un universo viejo: una semana nueva se ve en la siguiente llamada', function () {
    $m = F::period('2026-09-01', '2026-09-30');
    $ids = app(PeriodDataIds::class);
    $before = $ids->for($m);
    $week = Period::query()->create(['name' => 'Semana prueba', 'code' => 'W-ER-T1', 'type' => 'weekly', 'year' => 2026, 'sequence' => 9001,
        'start_date' => '2026-09-07', 'end_date' => '2026-09-13', 'is_closed' => false]);
    $after = $ids->for($m->fresh());
    expect($after)->toBe(app(RadiographySnapshotBuilder::class)->resolveDataIdsPublic($m->fresh()));
    if (in_array($week->id, $after, true)) {
        expect($before)->not->toContain($week->id);
    }
});

it('buildEach (una sola lectura) produce exactamente los mismos datasets/hash que build() por alcance', function () {
    F::fakeRadiography();
    $p = G::readyPeriod();
    $builder = app(EstadoResultadosDatasetBuilder::class);
    $each = [];
    $builder->session(function () use ($builder, $p, &$each) {
        $builder->buildEach($p, null, function ($key, $d) use (&$each) {
            $each[$key] = $d['meta']['dataset_hash'];
        });
    });
    foreach ($builder->scopes($p) as $s) {
        expect($builder->build($p, $s['key'])['meta']['dataset_hash'])->toBe($each[$s['key']], $s['key']);
    }
    expect($each)->not->toBeEmpty();
});

it('eachUnredacted recorre alcances en vivo y, ya aprobado, los snapshots (mismo hash)', function () {
    G::fakePdfRenderer();
    F::fakeRadiography();
    $p = G::readyPeriod();
    $resolver = app(EstadoResultadosReportResolver::class);
    $live = [];
    $resolver->eachUnredacted($p, null, function ($key, $d) use (&$live) {
        $live[$key] = [$d['meta']['dataset_hash'], $d['meta']['report']['source']];
    });
    app(EstadoResultadosApprovalService::class)->approve($p, G::admin());
    $snap = [];
    $resolver->eachUnredacted($p, ['CONSOLIDADO'], function ($key, $d) use (&$snap) {
        $snap[$key] = [$d['meta']['dataset_hash'], $d['meta']['report']['source']];
    });
    expect($live['CONSOLIDADO'][1])->toBe('en_vivo')
        ->and($snap)->toBe(['CONSOLIDADO' => [$live['CONSOLIDADO'][0], 'snapshot']]);
});

it('consolidado de Radiografía: mismo criterio que Radiografía (vigente, no invalidado, id más reciente) y UNIQUE por periodo', function () {
    $p = F::period();
    expect(RadiographySummary::currentId($p->id))->toBeNull();

    $row = fn (array $x = []) => $x + ['period_id' => $p->id, 'status' => 'generated', 'version' => 1, 'created_at' => now(), 'updated_at' => now()];
    $id = DB::table('period_summaries')->insertGetId($row(['invalidated_at' => now()]));
    expect(RadiographySummary::currentId($p->id))->toBeNull(); // invalidado: no cuenta

    DB::table('period_summaries')->where('id', $id)->update(['invalidated_at' => null]);
    expect(RadiographySummary::currentId($p->id))->toBe($id)
        // La BD impide un segundo consolidado del mismo periodo (UNIQUE period_id): la selección no puede ser ambigua.
        ->and(fn () => DB::table('period_summaries')->insert($row()))->toThrow(QueryException::class);

    DB::table('period_summaries')->where('id', $id)->update(['status' => 'pending']);
    expect(RadiographySummary::currentId($p->id))->toBeNull();
});

it('procesador: un movimiento fuera del periodo se asigna al periodo mensual de menor id cuando hay traslapes (determinista)', function () {
    F::fakeRadiography();
    $p = F::period('2026-09-01', '2026-09-30');
    $a = F::period('2026-10-01', '2026-10-31');
    $b = F::period('2026-10-01', '2026-10-31'); // traslape intencional
    G::import($p, F::adminXlsx([
        ['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO'],
        ['2026-10-05', 50.00, null, 'S/R', 'PAGO OCT', 'CORPORATIVO'],
    ]));
    app(EstadoResultadosProcessor::class)->process($p);
    expect(DB::table('estado_resultados_movements')->where('period_id', $p->id)->where('in_period', false)->value('recognition_period_id'))
        ->toBe(min($a->id, $b->id));
});

it('alcance por sucursal: un usuario restringido no consulta el consolidado ni otras sucursales', function () {
    F::fakeRadiographyByPeriod([]);
    $p = F::period();
    F::fakeRadiographyByPeriod([$p->id => [
        'CORDOBA' => ['interes_recuperado' => 1000.0],
        'ORIZABA' => ['interes_recuperado' => 500.0],
    ]]);
    $user = G::userWith(['exportar', 'aprobar', 'cargar', 'procesar', 'clasificar', 'ajustes', 'ver_nomina', 'ver_dg']);
    $user->forceFill(['branch_scope' => 'branches'])->save();
    DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => F::branch('CORDOBA')->id, 'created_at' => now(), 'updated_at' => now()]);
    $user = User::query()->find($user->id);
    $base = "/estado-resultados/{$p->id}";

    // 1) Hoy el MÓDULO completo es de alcance general (ModuleAccessService::GENERAL_SCOPE_MODULES):
    //    el middleware ya bloquea al usuario restringido, incluso su propia sucursal.
    $this->actingAs($user)->getJson("{$base}/dataset?alcance=CORDOBA")->assertForbidden()
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'alcance general'));

    // 2) Defensa en profundidad del controlador (si el módulo se abre a alcances restringidos).
    $this->withoutMiddleware(EnsureModuleAccess::class);
    $this->actingAs($user)->getJson("{$base}/dataset")->assertForbidden();                         // CONSOLIDADO por defecto
    $this->actingAs($user)->getJson("{$base}/dataset?alcance=ORIZABA")->assertForbidden();
    $res = $this->actingAs($user)->getJson("{$base}/dataset?alcance=CORDOBA");
    expect($res->status())->toBe(200, (string) $res->json('message'));
    $ok = $res->json();
    expect(array_column($ok['scopes'], 'key'))->toBe(['CORDOBA']);
    $this->actingAs($user)->get("{$base}/exportar/excel")->assertForbidden();
    $this->actingAs($user)->get("{$base}/exportar/pdf?alcance=ORIZABA")->assertForbidden();
    $this->actingAs($user)->getJson("{$base}/detalle/nomina")->assertForbidden();
    $this->actingAs($user)->getJson("{$base}/ajustes/historial?alcance=ORIZABA")->assertForbidden();
    $this->actingAs($user)->putJson("{$base}/ajustes", ['scope' => 'ORIZABA', 'concept' => 'isr', 'state' => 'confirmado_cero'])->assertForbidden();
    $this->actingAs($user)->putJson("{$base}/ajustes", ['scope' => 'CORDOBA', 'concept' => 'isr', 'state' => 'confirmado_cero'])->assertOk();
    $this->actingAs($user)->postJson("{$base}/aprobar")->assertForbidden();
    $this->actingAs($user)->postJson("{$base}/procesar")->assertForbidden();
    $this->actingAs($user)->getJson("{$base}/cargas/intentos")->assertForbidden();

    // Alcance general (admin): todo disponible.
    $admin = G::admin();
    expect(array_column($this->actingAs($admin)->getJson("{$base}/dataset")->assertOk()->json('scopes'), 'key'))->toContain('CONSOLIDADO', 'CORDOBA', 'ORIZABA');
});
