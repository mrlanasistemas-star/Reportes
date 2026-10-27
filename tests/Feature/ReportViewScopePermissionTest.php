<?php

use App\Models\Branch;
use App\Models\Period;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\Access\ActionPermissionService;
use App\Services\Access\ReportVisibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/**
 * 27-oct-2026 — alcance por AUTOR en Reportes mensuales: «Ver reportes propios»
 * vs «Ver reportes de todos». El alcance por sucursal (DataScopeService) se
 * aplica encima. Caso real: rol personalizado «Gerente regional» con el módulo
 * Reportes que no veía los reportes de los demás.
 */
function rvsAdmin(): User
{
    return User::factory()->create(['role' => 'admin', 'access_enabled_at' => now()]);
}

function rvsRole($test, User $actor, string $name, array $reportActions, array $extraModules = [], array $extraActions = [])
{
    return $test->actingAs($actor)->post(route('roles.store'), [
        'name' => $name, 'base_level' => 'gerencial',
        'modules' => array_merge(['reportes'], $extraModules),
        'actions' => array_merge(['reportes' => $reportActions], $extraActions),
    ]);
}

/** @param list<int>|null $branchIds null = alcance general */
function rvsUser(string $role, ?array $branchIds = null): User
{
    $user = User::factory()->create(['role' => $role, 'access_enabled_at' => now(), 'branch_scope' => $branchIds === null ? 'all' : 'branches']);
    foreach ($branchIds ?? [] as $id) {
        DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $user;
}

function rvsBranch(string $name): Branch
{
    return Branch::query()->firstOrCreate(['normalized_name' => mb_strtolower($name)], ['code' => mb_substr($name, 0, 4) . random_int(10, 99), 'name' => $name, 'is_active' => true]);
}

function rvsPeriod(int $month): Period
{
    return Period::query()->create([
        'name' => "Mes {$month} 2026", 'code' => sprintf('M-2026-%02d', $month), 'type' => 'monthly', 'year' => 2026, 'month' => $month,
        'sequence' => 1, 'start_date' => sprintf('2026-%02d-01', $month), 'end_date' => sprintf('2026-%02d-28', $month), 'is_closed' => false,
    ]);
}

function rvsRun(Period $period, ?User $author, string $scope = 'general', ?int $branchId = null): PeriodRadiographyRun
{
    return PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'report_type' => 'simple', 'scope' => $scope, 'branch_id' => $branchId,
        'status' => 'success', 'finished_at' => now(), 'created_by' => $author?->id,
    ]);
}

function rvsListedIds($test, User $user): array
{
    $ids = [];
    $test->actingAs($user)->get(route('reportes-mensuales.index'))->assertOk()
        ->assertInertia(function ($page) use (&$ids) {
            $ids = collect($page->toArray()['props']['generatedReports'])->pluck('id')->all();

            return $page;
        });

    return $ids;
}

it('keeps current behaviour: system roles (and roles that existed before) can see the reports of everyone', function () {
    $matrix = app(ActionPermissionService::class)->matrix();
    expect($matrix['gerencial']['reportes'])->toContain('ver_propios', 'ver_todos')
        ->and($matrix['colaborador']['reportes'])->toContain('ver_propios', 'ver_todos');

    $gerente = User::factory()->create(['role' => 'gerencial', 'access_enabled_at' => now()]);
    $other = User::factory()->create(['role' => 'gerencial']);
    $period = rvsPeriod(6);
    $run = rvsRun($period, $other, 'branch', rvsBranch('ATLIXCO')->id);

    expect(app(ReportVisibilityService::class)->mode($gerente))->toBe(ReportVisibilityService::ALL)
        ->and(rvsListedIds($this, $gerente))->toContain($run->id);
});

it('custom role with only «Ver reportes propios» lists and opens only its own reports; a foreign run by URL is 403', function () {
    $admin = rvsAdmin();
    rvsRole($this, $admin, 'Gerente regional', ['ver_propios', 'exportar'])->assertSessionHasNoErrors();
    $me = rvsUser('gerente_regional');
    $other = rvsUser('gerente_regional');
    $atl = rvsBranch('ATLIXCO');
    $junio = rvsPeriod(6);
    $mayo = rvsPeriod(5);

    $mine = rvsRun($junio, $me, 'branch', $atl->id);
    $foreign = rvsRun($junio, $other, 'general');
    $foreignBranch = rvsRun($mayo, $other, 'branch', $atl->id);

    expect(app(ReportVisibilityService::class)->mode($me))->toBe(ReportVisibilityService::OWN);
    expect(rvsListedIds($this, $me))->toBe([$mine->id]);

    // Propio: pasa el candado (404 = el archivo no existe en la prueba; ver redirige a la vista).
    $this->actingAs($me)->get(route('reportes-mensuales.run-excel', $mine))->assertNotFound();
    $this->actingAs($me)->get(route('reportes-mensuales.run-ver', $mine))->assertRedirect();
    $this->actingAs($me)->get(route('reportes-mensuales.preview', ['period' => $junio->id, 'scope' => 'branch', 'branch_id' => $atl->id]))->assertOk();

    // Ajeno por URL directa → 403 con el motivo y cómo resolverlo.
    $denied = $this->actingAs($me)->getJson(route('reportes-mensuales.run-ver', $foreign));
    $denied->assertForbidden();
    expect($denied->json('message'))->toContain('Ver reportes de todos');
    $this->actingAs($me)->get(route('reportes-mensuales.run-pdf', $foreignBranch))->assertForbidden();
    $this->actingAs($me)->get(route('reportes-mensuales.run-excel', $foreignBranch))->assertForbidden();
    $this->actingAs($me)->get(route('reportes-mensuales.preview', $mayo))->assertForbidden();
    $this->actingAs($me)->getJson(route('reportes-mensuales.scoped-data', ['period' => $mayo->id, 'scope' => 'branch', 'branch_id' => $atl->id]))->assertForbidden();
    $this->actingAs($me)->get(route('reportes-mensuales.export-radiography', $mayo))->assertForbidden();
    $this->actingAs($me)->get(route('reportes-mensuales.export-radiography-pdf', $mayo))->assertForbidden();
    $this->actingAs($me)->get(route('reportes-mensuales.export-filtered-radiography', ['period' => $mayo->id, 'scope' => 'branch', 'branch_id' => $atl->id]))->assertForbidden();
    $this->actingAs($me)->getJson(route('reportes-mensuales.comparativo-data', ['period_a' => $junio->id, 'period_b' => $mayo->id]))->assertForbidden();
    $this->actingAs($me)->get(route('reportes-mensuales.comparativo', ['period_a' => $junio->id, 'period_b' => $mayo->id]))->assertForbidden();
    // El roster de un periodo ajeno tampoco se expone.
    expect($this->actingAs($me)->getJson(route('reportes-mensuales.comparativo-employees-lookup', ['period_id' => $mayo->id]))->json('employees'))->toBe([]);
});

it('generating the period consolidated report makes all its views «own»', function () {
    $admin = rvsAdmin();
    rvsRole($this, $admin, 'Solo propios', ['ver_propios'])->assertSessionHasNoErrors();
    $me = rvsUser('solo_propios');
    $junio = rvsPeriod(6);
    $summary = PeriodSummary::query()->create(['period_id' => $junio->id, 'status' => 'generated', 'generated_at' => now(), 'generated_by' => $me->id]);

    expect(rvsListedIds($this, $me))->toBe(['summary-' . $summary->id]);
    expect($this->actingAs($me)->get(route('reportes-mensuales.preview', $junio))->status())->not->toBe(403); // pasa el candado (el snapshot real no corre en SQLite)
});

it('custom role with «Ver reportes de todos» sees the reports generated by other users', function () {
    $admin = rvsAdmin();
    rvsRole($this, $admin, 'Gerente regional', ['ver_todos'])->assertSessionHasNoErrors();
    $me = rvsUser('gerente_regional');
    $other = User::factory()->create(['role' => 'gerencial']);
    $junio = rvsPeriod(6);
    $foreign = rvsRun($junio, $other, 'branch', rvsBranch('ATLIXCO')->id);

    expect(rvsListedIds($this, $me))->toContain($foreign->id);
    $this->actingAs($me)->get(route('reportes-mensuales.run-ver', $foreign))->assertRedirect();
    $this->actingAs($me)->get(route('reportes-mensuales.preview', $junio))->assertOk();
});

it('«Ver reportes de todos» never widens the branch scope: restricted to ATLIXCO sees ATLIXCO of everyone, never TULA nor the general', function () {
    $admin = rvsAdmin();
    rvsRole($this, $admin, 'Gerente regional', ['ver_todos', 'exportar'])->assertSessionHasNoErrors();
    $atl = rvsBranch('ATLIXCO');
    $tula = rvsBranch('TULA');
    $me = rvsUser('gerente_regional', [$atl->id]);
    $other = User::factory()->create(['role' => 'gerencial']);
    $junio = rvsPeriod(6);

    $atlRun = rvsRun($junio, $other, 'branch', $atl->id);
    $tulaRun = rvsRun($junio, $other, 'branch', $tula->id);
    $general = rvsRun($junio, $other, 'general');

    expect(rvsListedIds($this, $me))->toBe([$atlRun->id]);
    $this->actingAs($me)->get(route('reportes-mensuales.run-excel', $atlRun))->assertNotFound(); // pasa ambos candados
    $this->actingAs($me)->get(route('reportes-mensuales.run-ver', $tulaRun))->assertForbidden();
    $this->actingAs($me)->get(route('reportes-mensuales.run-ver', $general))->assertForbidden();

    $this->actingAs($me)->get(route('reportes-mensuales.index'))->assertInertia(fn ($page) => $page
        ->where('visibility.mode', 'all')
        ->where('visibility.general_scope', false)
        ->where('visibility.text', fn ($t) => str_contains($t, 'ATLIXCO') && str_contains($t, 'todos los usuarios')));
});

it('with the module but neither view permission: empty list with a clear reason and 403 by URL', function () {
    $admin = rvsAdmin();
    rvsRole($this, $admin, 'Sin ver', [])->assertSessionHasNoErrors();
    $me = rvsUser('sin_ver');
    $junio = rvsPeriod(6);
    $run = rvsRun($junio, $me, 'general');

    expect(rvsListedIds($this, $me))->toBe([]);
    $this->actingAs($me)->get(route('reportes-mensuales.index'))->assertInertia(fn ($page) => $page->where('visibility.mode', 'none'));
    $this->actingAs($me)->get(route('reportes-mensuales.run-ver', $run))->assertForbidden();
});

it('saves the required actions automatically (ver_todos and exportar imply ver_propios) and exposes them in the catalog', function () {
    $admin = rvsAdmin();
    rvsRole($this, $admin, 'Solo todos', ['ver_todos'])->assertSessionHasNoErrors();
    rvsRole($this, $admin, 'Solo exportar', ['exportar'])->assertSessionHasNoErrors();

    $matrix = app(ActionPermissionService::class)->matrix();
    expect($matrix['solo_todos']['reportes'])->toBe(['ver_propios', 'ver_todos'])
        ->and($matrix['solo_exportar']['reportes'])->toBe(['ver_propios', 'exportar']);

    $catalog = collect(app(ActionPermissionService::class)->catalog())->keyBy(fn ($a) => "{$a['module']}.{$a['action']}");
    expect($catalog['reportes.ver_todos']['requires'])->toBe(['ver_propios'])
        ->and($catalog['reportes.ver_todos']['column'])->toBe('consultar')
        ->and($catalog['reportes.ver_propios']['requires'])->toBe([]);
});

it('delegation: nobody grants «Ver reportes de todos» without having it', function () {
    $admin = rvsAdmin();
    rvsRole($this, $admin, 'Jefe propio', ['ver_propios'], ['roles'], ['roles' => ['crear', 'editar']])->assertSessionHasNoErrors();
    $jefe = rvsUser('jefe_propio');

    $res = rvsRole($this, $jefe, 'Inflado', ['ver_todos']);
    $res->assertSessionHasErrors('modules');
    expect(session('errors')->first('modules'))->toContain('Ver reportes de todos');
    expect(DB::table('roles')->where('name', 'Inflado')->exists())->toBeFalse();

    rvsRole($this, $jefe, 'Contenido', ['ver_propios'])->assertSessionHasNoErrors();

    // Con «ver todos» en su propio rol, ya puede otorgarlo.
    rvsRole($this, $admin, 'Jefe todos', ['ver_todos'], ['roles'], ['roles' => ['crear']])->assertSessionHasNoErrors();
    rvsRole($this, rvsUser('jefe_todos'), 'Regional dos', ['ver_todos'])->assertSessionHasNoErrors();
});

it('the migration grants both view actions to EXISTING custom roles (nothing changes for them)', function () {
    DB::table('roles')->insert(['key' => 'gerente_regional', 'name' => 'Gerente regional', 'base_level' => 'colaborador', 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('role_action_permissions')->insert(['role' => 'gerente_regional', 'module' => 'reportes', 'action' => 'exportar', 'created_at' => now(), 'updated_at' => now()]);
    app(\App\Services\Access\RoleCatalogService::class)->forget();

    (require database_path('migrations/2026_10_27_000001_seed_reportes_view_scope_permissions.php'))->up();
    (require database_path('migrations/2026_10_27_000001_seed_reportes_view_scope_permissions.php'))->up(); // idempotente

    expect(DB::table('role_action_permissions')->where('role', 'gerente_regional')->where('module', 'reportes')->orderBy('action')->pluck('action')->all())
        ->toBe(['exportar', 'ver_propios', 'ver_todos'])
        ->and(DB::table('role_action_permissions')->where('role', 'admin')->exists())->toBeFalse();
});
