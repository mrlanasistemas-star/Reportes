<?php

use App\Http\Controllers\Okr\ResponsibleController;
use App\Mail\UserAccessGrantedMail;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\OkrCommitmentLetter;
use App\Models\OkrObjective;
use App\Models\OkrWarning;
use App\Models\Period;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\Access\DataScopeService;
use App\Services\Access\ModuleAccessService;
use App\Services\DashboardTrendService;
use App\Services\Okr\OkrEmployeeBranchResolver;
use App\Services\RadiografiaExportService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Exception\TransportException;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/**
 * Demostración de que el BACKEND aplica el alcance por sucursal y los permisos
 * (no solo el selector de la UI): varias sucursales, sin sucursales, usuarios
 * previos a la Fase 6.4, módulos de toda la empresa, documentos OKR por URL
 * directa, API, delegación de roles, autenticación e invitaciones.
 * Complementa a BranchDataScopeTest (escenario solo-ATLIXCO).
 */
function aseBranch(string $name): Branch
{
    return Branch::query()->firstOrCreate(['normalized_name' => mb_strtolower($name)], ['code' => mb_substr($name, 0, 3) . random_int(100, 999), 'name' => $name, 'is_active' => true]);
}

function aseAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function aseMonthly(int $month, bool $withSummary = false): Period
{
    $period = Period::query()->create([
        'name' => "Mes {$month} 2026", 'code' => sprintf('ASE-2026-%02d', $month), 'type' => 'monthly', 'year' => 2026, 'month' => $month,
        'sequence' => 1, 'start_date' => sprintf('2026-%02d-01', $month), 'end_date' => sprintf('2026-%02d-28', $month), 'is_closed' => false,
    ]);
    if ($withSummary) {
        PeriodSummary::query()->create(['period_id' => $period->id, 'status' => 'generated', 'generated_at' => now(), 'global_metrics' => [], 'warnings' => [], 'version' => 1]);
    }

    return $period;
}

/** Crea por las rutas reales un rol de nivel gerencial y un usuario con el alcance dado ('all' o lista de ids). */
function aseScopedUser($test, User $admin, string $email, array|string $scope, array $modules, array $actions = [], string $roleName = 'Gerente Regional'): User
{
    $key = \Illuminate\Support\Str::slug($roleName, '_');
    if (! DB::table('roles')->where('key', $key)->exists()) {
        $test->actingAs($admin)->post(route('roles.store'), [
            'name' => $roleName, 'description' => 'Prueba de alcance', 'base_level' => 'gerencial', 'modules' => $modules, 'actions' => $actions,
        ])->assertSessionHasNoErrors();
    }

    $test->actingAs($admin)->post(route('usuarios.store'), [
        'name' => 'Usuario ' . $email, 'email' => $email, 'role' => $key, 'password_mode' => 'auto', 'send_invitation' => false, 'enable_access' => true,
    ] + ($scope === 'all' ? ['branch_scope' => 'all'] : ['branch_scope' => 'branches', 'branch_ids' => $scope]))->assertSessionHasNoErrors();

    return User::query()->where('email', $email)->firstOrFail();
}

function aseObjective(User $owner, Branch $branch, ?Employee $employee = null): OkrObjective
{
    return OkrObjective::query()->create([
        'scope_type' => $employee ? OkrObjective::SCOPE_EMPLOYEE : OkrObjective::SCOPE_BRANCH, 'branch_id' => $branch->id, 'employee_id' => $employee?->id,
        'title' => 'OKR ' . $branch->name, 'responsible_user_id' => $owner->id, 'created_by' => $owner->id,
        'start_date' => now()->subWeeks(3)->toDateString(), 'end_date' => now()->addWeeks(5)->toDateString(), 'duration_weeks' => 8,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now()->subWeeks(3),
    ]);
}

/** RadiografiaExportService falso que registra cada alcance pedido (nunca calcula). */
function aseCaptureSnapshots(): ArrayObject
{
    $configs = new ArrayObject();
    $export = Mockery::mock(RadiografiaExportService::class);
    $export->shouldReceive('buildSnapshot')->andReturnUsing(function ($period, array $config) use ($configs) {
        $configs[] = $config;

        return [
            'scope' => ['type' => $config['scope'], 'branch_id' => $config['branch_id'] ?? null, 'branch_name' => null, 'available' => true],
            'summary' => ['ebitda_final' => 1.0, 'margen_ebitda' => 1.0, 'opex_total' => 1.0, 'placement_total' => 1.0, 'recovery_total' => 1.0, 'portfolio_total' => 1.0, 'mora_index' => 1.0],
        ];
    });
    $export->shouldReceive('buildExecutiveChartsData')->andReturn(['moraBuckets' => [], 'categorias' => [], 'categoriaLabels' => [], 'categoriaCounts' => [], 'categoriaColors' => [], 'sucursalRows' => []]);
    app()->instance(RadiografiaExportService::class, $export);
    $trend = Mockery::mock(DashboardTrendService::class);
    $trend->shouldReceive('build')->andReturnUsing(function ($branchId) use ($configs) {
        $configs[] = ['scope' => $branchId ? 'branch' : 'general', 'branch_id' => $branchId, 'source' => 'trend'];

        return [];
    });
    $trend->shouldReceive('scopeLabel')->andReturn('x');
    app()->instance(DashboardTrendService::class, $trend);

    return $configs;
}

// ─────────────────────────── Alcance por sucursal ───────────────────────────

it('usuario con VARIAS sucursales: ve exactamente esas (ATLIXCO y TULA) y recibe 403 para CORDOBA en dashboard, reportes, descargas, OKR y colaboradores', function () {
    Mail::fake();
    $admin = aseAdmin();
    [$atl, $tula, $cor] = [aseBranch('ATLIXCO'), aseBranch('TULA'), aseBranch('CORDOBA')];
    $user = aseScopedUser($this, $admin, 'dos@example.test', [$atl->id, $tula->id], ['dashboard', 'reportes', 'colaboradores', 'okr'], ['reportes' => ['exportar'], 'okr' => ['exportar']]);

    expect(app(DataScopeService::class)->allowedBranchIds($user))->toBe(collect([$atl->id, $tula->id])->sort()->values()->all());

    foreach ([$atl, $tula] as $ok) {
        $this->actingAs($user)->getJson(route('dashboard.data', ['branch_id' => $ok->id]))->assertOk();
        $this->actingAs($user)->getJson(route('dashboard.trend', ['branch_id' => $ok->id]))->assertOk();
    }
    $denied = $this->actingAs($user)->getJson(route('dashboard.data', ['branch_id' => $cor->id]));
    $denied->assertForbidden();
    expect($denied->json('message'))->toContain('CORDOBA')->toContain('ATLIXCO, TULA');
    $this->actingAs($user)->getJson(route('dashboard.trend', ['branch_id' => $cor->id]))->assertForbidden();
    $this->actingAs($user)->get(route('dashboard.export-excel', ['period_id' => 1, 'branch_id' => $cor->id]))->assertForbidden();
    $this->actingAs($user)->get(route('dashboard.export-pdf', ['period_id' => 1, 'branch_id' => $cor->id]))->assertForbidden();

    // Reportes mensuales: TULA pasa el candado (sin snapshot → nunca 403), CORDOBA y el general no.
    $junio = aseMonthly(6);
    $mayo = aseMonthly(5);
    expect($this->actingAs($user)->getJson(route('reportes-mensuales.scoped-data', ['period' => $junio->id, 'scope' => 'branch', 'branch_id' => $tula->id]))->status())->not->toBe(403);
    $this->actingAs($user)->getJson(route('reportes-mensuales.scoped-data', ['period' => $junio->id, 'scope' => 'branch', 'branch_id' => $cor->id]))->assertForbidden();
    $this->actingAs($user)->getJson(route('reportes-mensuales.scoped-data', ['period' => $junio->id, 'scope' => 'general']))->assertForbidden();
    $this->actingAs($user)->getJson(route('reportes-mensuales.comparativo-data', ['period_a' => $junio->id, 'period_b' => $mayo->id, 'scope' => 'branch', 'branch_id' => $cor->id]))->assertForbidden();
    expect($this->actingAs($user)->getJson(route('reportes-mensuales.comparativo-data', ['period_a' => $junio->id, 'period_b' => $mayo->id, 'scope' => 'branch', 'branch_id' => $tula->id]))->status())->not->toBe(403);
    $this->actingAs($user)->get(route('reportes-mensuales.export-filtered-radiography', ['period' => $junio->id, 'scope' => 'branch', 'branch_id' => $cor->id]))->assertForbidden();
    $this->actingAs($user)->get(route('reportes-mensuales.export-filtered-radiography-pdf', ['period' => $junio->id, 'scope' => 'branch', 'branch_id' => $cor->id]))->assertForbidden();

    // Archivos ya generados: por ID directo, solo los de sus sucursales.
    $runs = collect([$atl, $tula, $cor])->mapWithKeys(fn ($b) => [$b->name => PeriodRadiographyRun::query()->create(['period_id' => $junio->id, 'report_type' => 'simple', 'scope' => 'branch', 'branch_id' => $b->id, 'status' => 'success', 'finished_at' => now()])]);
    $general = PeriodRadiographyRun::query()->create(['period_id' => $junio->id, 'report_type' => 'simple', 'scope' => 'general', 'status' => 'success', 'finished_at' => now()]);
    $this->actingAs($user)->get(route('reportes-mensuales.run-excel', $runs['TULA']))->assertNotFound(); // pasa el candado; el archivo no existe en la prueba
    $this->actingAs($user)->get(route('reportes-mensuales.run-pdf', $runs['CORDOBA']))->assertForbidden();
    $this->actingAs($user)->get(route('reportes-mensuales.run-excel', $runs['CORDOBA']))->assertForbidden();
    $this->actingAs($user)->get(route('reportes-mensuales.run-ver', $general))->assertForbidden();
    $this->actingAs($user)->get(route('reportes-mensuales.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('generatedReports', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === [$runs['ATLIXCO']->id, $runs['TULA']->id]));

    // OKR: índice, detalle y filtros.
    $okrs = collect([$atl, $tula, $cor])->mapWithKeys(fn ($b) => [$b->name => aseObjective($admin, $b)]);
    $this->actingAs($user)->get(route('okr.show', $okrs['TULA']))->assertOk();
    $this->actingAs($user)->get(route('okr.show', $okrs['CORDOBA']))->assertForbidden();
    $this->actingAs($user)->get(route('okr.dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.branches', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$atl->id, $tula->id])->sort()->values()->all())->etc());
    $this->actingAs($user)->get(route('okr.dashboard', ['branch_id' => $cor->id]))->assertForbidden();
    $lookup = $this->actingAs($user)->getJson(route('okr.objectives.lookup', ['q' => 'OKR']));
    $lookup->assertOk();
    expect(json_encode($lookup->json()))->not->toContain('OKR CORDOBA');

    // Colaboradores: catálogo de sucursales y asignaciones solo de su alcance.
    foreach ([[$atl, 'A'], [$tula, 'T'], [$cor, 'C']] as [$b, $code]) {
        $e = Employee::query()->create(['employee_code' => "ASE-{$code}", 'full_name' => "GESTOR {$b->name}", 'normalized_name' => mb_strtolower("gestor {$b->name}"), 'is_active' => true]);
        EmployeeBranchAssignment::query()->create(['period_id' => $junio->id, 'employee_id' => $e->id, 'branch_id' => $b->id]);
    }
    $this->actingAs($user)->get(route('asignaciones-empleado-sucursal.index', ['period_id' => $junio->id]))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('branches', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['ATLIXCO', 'TULA'])
            ->where('assignments', fn ($rows) => collect($rows)->pluck('employee_name')->sort()->values()->all() === ['GESTOR ATLIXCO', 'GESTOR TULA'])
            ->etc());
    $this->actingAs($user)->get(route('asignaciones-empleado-sucursal.index', ['period_id' => $junio->id, 'branch_id' => $cor->id]))->assertForbidden();
});

it('Dashboard (pantalla, datos, Excel y PDF) de un usuario restringido sin sucursal elegida calcula SU sucursal — nunca el consolidado; el admin sí ve el general', function () {
    Mail::fake();
    $admin = aseAdmin();
    $atl = aseBranch('ATLIXCO');
    aseBranch('TULA');
    $user = aseScopedUser($this, $admin, 'solo-atl@example.test', [$atl->id], ['dashboard', 'reportes'], ['reportes' => ['exportar']]);
    $period = aseMonthly(6, withSummary: true);
    $configs = aseCaptureSnapshots();
    $renderer = Mockery::mock(\App\Services\Pdf\BrowsershotPdfRenderer::class);
    $renderer->shouldReceive('renderViewToFile')->andReturnUsing(function (string $view, array $vars, string $path) {
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($path));
        \Illuminate\Support\Facades\File::put($path, '%PDF-1.4 fake');
    });
    app()->instance(\App\Services\Pdf\BrowsershotPdfRenderer::class, $renderer);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->actingAs($user)->getJson(route('dashboard.data', ['period_id' => $period->id]))->assertOk();
    $this->actingAs($user)->getJson(route('dashboard.trend'))->assertOk();
    $this->actingAs($user)->get(route('dashboard.export-excel', ['period_id' => $period->id]))->assertOk();
    $this->actingAs($user)->get(route('dashboard.export-pdf', ['period_id' => $period->id]))->assertOk();

    expect(count($configs))->toBeGreaterThan(4);
    foreach ($configs as $config) {
        expect($config['scope'])->toBe('branch')->and($config['branch_id'])->toBe($atl->id);
    }

    $configs->exchangeArray([]);
    $this->actingAs($admin)->getJson(route('dashboard.data', ['period_id' => $period->id]))->assertOk();
    expect(collect($configs)->pluck('scope')->unique()->all())->toBe(['general']);
});

it('sin el módulo o sin el permiso de la acción, el backend responde 403 aunque la sucursal sí esté en su alcance', function () {
    Mail::fake();
    $admin = aseAdmin();
    $atl = aseBranch('ATLIXCO');
    $okrOnly = aseScopedUser($this, $admin, 'okr@example.test', [$atl->id], ['okr'], [], 'Solo OKR');
    $noExport = aseScopedUser($this, $admin, 'noexp@example.test', [$atl->id], ['dashboard', 'reportes'], [], 'Sin Exportar');
    $junio = aseMonthly(6);

    $this->actingAs($okrOnly)->getJson(route('dashboard.data', ['branch_id' => $atl->id]))->assertForbidden();
    $this->actingAs($okrOnly)->getJson(route('reportes-mensuales.scoped-data', ['period' => $junio->id, 'scope' => 'branch', 'branch_id' => $atl->id]))->assertForbidden();
    $this->actingAs($okrOnly)->get(route('asignaciones-empleado-sucursal.index'))->assertForbidden();

    $this->actingAs($noExport)->getJson(route('dashboard.data', ['branch_id' => $atl->id]))->assertOk();
    $this->actingAs($noExport)->get(route('dashboard.export-excel', ['period_id' => $junio->id, 'branch_id' => $atl->id]))->assertForbidden();
    $this->actingAs($noExport)->get(route('dashboard.export-pdf', ['period_id' => $junio->id, 'branch_id' => $atl->id]))->assertForbidden();
    $this->actingAs($noExport)->get(route('reportes-mensuales.export-filtered-radiography', ['period' => $junio->id, 'scope' => 'branch', 'branch_id' => $atl->id]))->assertForbidden();
});

it('alcance «sucursales específicas» sin ninguna sucursal: 403 con mensaje claro, nunca el consolidado', function () {
    $user = User::factory()->create(['role' => 'gerencial']);
    $user->forceFill(['branch_scope' => DataScopeService::MODE_BRANCHES])->save();
    $period = aseMonthly(6, withSummary: true);
    $configs = aseCaptureSnapshots();

    $response = $this->actingAs($user)->getJson(route('dashboard.data', ['period_id' => $period->id]));
    $response->assertForbidden();
    expect($response->json('message'))->toContain('no tiene sucursales autorizadas')->toContain('administrador');
    expect(count($configs))->toBe(0);
    expect(app(DataScopeService::class)->label($user))->toBe('ninguna sucursal asignada');
});

it('usuarios previos sin filas en user_branch_scopes: gerencial = general (explícito y visible como «legacy»), colaborador = solo lo propio, y sin vínculo nada (con mensaje)', function () {
    $atl = aseBranch('ATLIXCO');
    $tula = aseBranch('TULA');
    $scope = app(DataScopeService::class);

    $manager = User::factory()->create(['role' => 'gerencial']);
    expect($manager->branch_scope)->toBeNull()
        ->and(DB::table('user_branch_scopes')->where('user_id', $manager->id)->exists())->toBeFalse()
        ->and($scope->describe($manager))->toMatchArray(['mode' => 'legacy', 'general' => true, 'label' => 'Todas las sucursales']);

    $employee = Employee::query()->create(['employee_code' => 'LEG-1', 'full_name' => 'COLABORADORA ATLIXCO', 'normalized_name' => 'colaboradora atlixco', 'is_active' => true]);
    $this->partialMock(OkrEmployeeBranchResolver::class, fn ($m) => $m->shouldReceive('currentBranchIdFor')->andReturnUsing(fn ($id) => $id === $employee->id ? $atl->id : null));
    $collaborator = User::factory()->create(['role' => 'colaborador', 'employee_id' => $employee->id]);
    expect($scope->allowedBranchIds($collaborator))->toBe([$atl->id])
        ->and($scope->label($collaborator))->toBe('Solo lo propio (ATLIXCO)')
        ->and($scope->allows($collaborator, $tula->id))->toBeFalse();

    $unlinked = User::factory()->create(['role' => 'colaborador']);
    expect($scope->allowedBranchIds($unlinked))->toBe([])
        ->and($scope->isGeneral($unlinked))->toBeFalse()
        ->and($scope->label($unlinked))->toBe('Solo lo propio');
    $tulaOkr = aseObjective(aseAdmin(), $tula);
    $this->actingAs($unlinked)->get(route('okr.show', $tulaOkr))->assertForbidden();
});

it('nunca se concede alcance general por omisión: alta de un usuario gerencial sin alcance elegido se rechaza y subir de nivel a un colaborador congela su alcance', function () {
    Mail::fake();
    $admin = aseAdmin();
    $atl = aseBranch('ATLIXCO');

    $this->actingAs($admin)->post(route('usuarios.store'), ['name' => 'Sin alcance', 'email' => 'sinalcance@example.test', 'role' => 'gerencial', 'password_mode' => 'auto', 'send_invitation' => false])
        ->assertSessionHasErrors(['branch_scope' => 'Elige el alcance por sucursal del usuario: «Todas las sucursales» o «Sucursales específicas».']);
    expect(User::query()->where('email', 'sinalcance@example.test')->exists())->toBeFalse();

    // Colaborador sin elegir alcance: permitido, queda en «solo lo propio» (nunca general).
    $this->actingAs($admin)->post(route('usuarios.store'), ['name' => 'Colab', 'email' => 'colab@example.test', 'role' => 'colaborador', 'password_mode' => 'auto', 'send_invitation' => false])
        ->assertSessionHasNoErrors();
    expect(app(DataScopeService::class)->isGeneral(User::query()->where('email', 'colab@example.test')->first()))->toBeFalse();

    // Promoción hecha por un ADMIN (alcance general) de un colaborador previo: no hereda «todas».
    $employee = Employee::query()->create(['employee_code' => 'PRO-1', 'full_name' => 'PROMOVIDO', 'normalized_name' => 'promovido', 'is_active' => true]);
    $this->partialMock(OkrEmployeeBranchResolver::class, fn ($m) => $m->shouldReceive('currentBranchIdFor')->andReturnUsing(fn ($id) => $id === $employee->id ? $atl->id : null));
    $linked = User::factory()->create(['role' => 'colaborador', 'employee_id' => $employee->id]);
    $this->actingAs($admin)->put(route('usuarios.update-role', $linked), ['role' => 'gerencial'])->assertSessionHasNoErrors();
    $fresh = $linked->fresh();
    expect($fresh->branch_scope)->toBe('branches')
        ->and(app(DataScopeService::class)->allowedBranchIds($fresh))->toBe([$atl->id]);

    $unlinked = User::factory()->create(['role' => 'colaborador']);
    $this->actingAs($admin)->put(route('usuarios.update-role', $unlinked), ['role' => 'gerencial'])
        ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'Todavía no tiene sucursales autorizadas'));
    expect(app(DataScopeService::class)->isGeneral($unlinked->fresh()))->toBeFalse();

    // El admin sí puede concederlo de forma EXPLÍCITA.
    $this->actingAs($admin)->put(route('usuarios.update-branch-scope', $unlinked), ['branch_scope' => 'all'])->assertSessionHas('success');
    expect(app(DataScopeService::class)->isGeneral($unlinked->fresh()))->toBeTrue();
});

it('módulos de toda la empresa (Carga de archivos y Periodos): un usuario restringido no los recibe aunque su rol los tenga — 403 con mensaje y fuera del menú', function () {
    Mail::fake();
    Storage::fake('public');
    $admin = aseAdmin();
    $atl = aseBranch('ATLIXCO');
    $modules = ['dashboard', 'carga', 'periodos', 'reportes'];
    $actions = ['carga' => ['subir', 'procesar', 'generar', 'descargar', 'eliminar'], 'periodos' => ['crear', 'cerrar', 'eliminar']];
    $restricted = aseScopedUser($this, $admin, 'carga-atl@example.test', [$atl->id], $modules, $actions, 'Operador Carga');
    $general = aseScopedUser($this, $admin, 'carga-todas@example.test', 'all', $modules, $actions, 'Operador Carga');
    $period = aseMonthly(6);

    expect(app(ModuleAccessService::class)->modulesFor($restricted))->not->toContain('carga')->not->toContain('periodos')
        ->and(app(ModuleAccessService::class)->modulesFor($general))->toContain('carga', 'periodos');

    $denied = $this->actingAs($restricted)->getJson(route('historico-general.index'));
    $denied->assertForbidden();
    expect($denied->json('message'))->toContain('Carga de archivos opera sobre todas las sucursales')->toContain('ATLIXCO');
    $this->actingAs($restricted)->getJson(route('historico-general.period-employees', $period))->assertForbidden();
    $this->actingAs($restricted)->getJson(route('historico-general.incidents', $period))->assertForbidden();
    $this->actingAs($restricted)->postJson(route('historico-general.generate-radiography', $period), ['config' => ['scope' => 'general']])->assertForbidden();
    $this->actingAs($restricted)->getJson(route('periodos.index'))->assertForbidden();
    $this->actingAs($restricted)->deleteJson(route('periodos.destroy', $period))->assertForbidden();
    $this->actingAs($restricted)->postJson(route('periodos.close', $period))->assertForbidden();
    expect(Period::query()->whereKey($period->id)->value('is_closed'))->toBeFalsy();

    $this->actingAs($restricted)->get(route('dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.modules', fn ($m) => ! collect($m)->contains('carga') && ! collect($m)->contains('periodos'))->etc());

    $this->actingAs($general)->get(route('periodos.index'))->assertOk();
    expect($this->actingAs($general)->getJson(route('historico-general.period-employees', $period))->status())->not->toBe(403);
});

it('Cartas Compromiso y Warnings por URL directa: ver, descargar, emitir, subir firmado y anular — solo dentro de SU sucursal', function () {
    Mail::fake();
    Storage::fake('local');
    $admin = aseAdmin();
    $atl = aseBranch('ATLIXCO');
    $tula = aseBranch('TULA');
    $user = aseScopedUser($this, $admin, 'docs@example.test', [$atl->id], ['okr'],
        ['okr' => ['exportar', 'revisar_carta', 'emitir_carta', 'generar_warning', 'autorizar_warning', 'anular_documentos']], 'Gestor Documentos');

    $docs = [];
    foreach ([$atl, $tula] as $branch) {
        $employee = Employee::query()->create(['employee_code' => 'DOC-' . $branch->id, 'full_name' => 'GESTOR ' . $branch->name, 'normalized_name' => 'gestor doc ' . $branch->id, 'is_active' => true]);
        $okr = aseObjective($admin, $branch, $employee);
        Storage::disk('local')->put("okr-commitment-letters/{$okr->id}/cc.pdf", '%PDF');
        Storage::disk('local')->put("okr-warnings/{$okr->id}/w.pdf", '%PDF');
        $letter = OkrCommitmentLetter::query()->create([
            'folio' => 'CC-' . $branch->name, 'okr_objective_id' => $okr->id, 'subject_employee_id' => $employee->id, 'status' => 'issued', 'snapshot' => [],
            'place' => 'Cuernavaca', 'generated_by' => $admin->id, 'generated_at' => now(), 'stored_path' => "okr-commitment-letters/{$okr->id}/cc.pdf", 'disk' => 'local',
        ]);
        $warning = OkrWarning::query()->create([
            'folio' => 'W-' . $branch->name, 'okr_objective_id' => $okr->id, 'week_number' => 1, 'week_start' => now()->subWeeks(3)->toDateString(),
            'week_end' => now()->subWeeks(2)->toDateString(), 'snapshot' => [], 'corrective_actions' => 'Plan', 'generated_by' => $admin->id, 'generated_at' => now(),
            'stored_path' => "okr-warnings/{$okr->id}/w.pdf", 'disk' => 'local', 'subject_employee_id' => $employee->id, 'status' => 'issued',
        ]);
        $docs[$branch->name] = compact('okr', 'letter', 'warning');
    }

    ['okr' => $tOkr, 'letter' => $tLetter, 'warning' => $tWarning] = $docs['TULA'];
    $this->actingAs($user)->get(route('okr.show', $tOkr))->assertForbidden();
    $this->actingAs($user)->get(route('okr.commitment-letters.download', $tLetter))->assertForbidden();
    $this->actingAs($user)->get(route('okr.warnings.download', $tWarning))->assertForbidden();
    $this->actingAs($user)->getJson(route('okr.commitment-letter.review', $tOkr))->assertForbidden();
    $this->actingAs($user)->get(route('okr.commitment-letter.preview', $tOkr))->assertForbidden();
    $this->actingAs($user)->postJson(route('okr.commitment-letter.generate', $tOkr))->assertForbidden();
    $this->actingAs($user)->getJson(route('okr.warnings.eligibility', ['objective' => $tOkr->id, 'week' => 1]))->assertForbidden();
    $this->actingAs($user)->postJson(route('okr.warnings.store', $tOkr), ['week_number' => 1, 'corrective_actions' => 'x', 'authorized' => true])->assertForbidden();
    $this->actingAs($user)->postJson(route('okr.commitment-letters.void', $tLetter), ['reason' => 'Datos incorrectos del documento'])->assertForbidden();
    $this->actingAs($user)->postJson(route('okr.warnings.void', $tWarning), ['reason' => 'Datos incorrectos del documento'])->assertForbidden();
    $this->actingAs($user)->postJson(route('okr.commitment-letters.signed', $tLetter))->assertForbidden();
    $this->actingAs($user)->postJson(route('okr.warnings.signed', $tWarning))->assertForbidden();
    expect($tLetter->fresh()->status)->toBe('issued')->and($tWarning->fresh()->status)->toBe('issued');

    // Los de SU sucursal pasan el candado de alcance.
    ['letter' => $aLetter, 'warning' => $aWarning] = $docs['ATLIXCO'];
    $this->actingAs($user)->get(route('okr.commitment-letters.download', $aLetter))->assertOk();
    $this->actingAs($user)->get(route('okr.warnings.download', $aWarning))->assertOk();
    expect($this->actingAs($user)->postJson(route('okr.warnings.void', $aWarning), ['reason' => 'Datos incorrectos del documento'])->status())->not->toBe(403);
});

it('Warnings: el documento pertenece al colaborador evaluado (no a quien lo emite) y una semana abierta nunca es elegible', function () {
    // Cubierto a fondo en Okr/OkrWarningEligibilityReasonsTest y Okr/OkrWarningTest; aquí la regla mínima.
    $admin = aseAdmin();
    $atl = aseBranch('ATLIXCO');
    $employee = Employee::query()->create(['employee_code' => 'EV-1', 'full_name' => 'EVALUADO', 'normalized_name' => 'evaluado', 'is_active' => true]);
    $okr = aseObjective($admin, $atl, $employee);
    $openWeek = (int) ceil(now()->diffInDays($okr->start_date->copy()->startOfDay(), true) / 7) + 1;

    $check = app(\App\Services\Okr\OkrWarningService::class)->eligibility($okr->fresh(), min($openWeek, 8));
    expect($check['subject']['id'])->toBe($employee->id)
        ->and($check['subject']['id'])->not->toBe($admin->employee_id)
        ->and($check['reasons'])->toContain('week_open')
        ->and($check['status'])->not->toBe('eligible');
});

it('la API (Sanctum, consumidores de servidor) no acepta la sesión de un usuario web: nunca entrega datos por esa vía', function () {
    Mail::fake();
    $admin = aseAdmin();
    $atl = aseBranch('ATLIXCO');
    $user = aseScopedUser($this, $admin, 'api@example.test', [$atl->id], ['dashboard', 'reportes']);

    foreach (['/api/v1/financial/summary', '/api/v1/employees', '/api/v1/branches', '/api/v1/okr/summary', "/api/v1/financial/branches/{$atl->id}"] as $url) {
        expect($this->actingAs($user)->getJson($url)->status())->toBeIn([401, 403]);
        expect($this->actingAs($admin)->getJson($url)->status())->toBeIn([401, 403]);
    }
});

// ─────────────────────────── Usuarios y Roles ───────────────────────────

it('delegación: un administrador delegado no otorga privilegios superiores a los suyos ni toca las matrices globales', function () {
    Mail::fake();
    $admin = aseAdmin();
    $atl = aseBranch('ATLIXCO');
    $delegate = aseScopedUser($this, $admin, 'delegado@example.test', [$atl->id], ['dashboard', 'usuarios', 'roles'],
        ['usuarios' => ['crear', 'asignar_rol', 'gestionar_acceso'], 'roles' => ['crear', 'editar']], 'Jefe Delegado');

    // Rol con un módulo que el delegado no tiene → 403/errores, sin crear nada.
    $resp = $this->actingAs($delegate)->post(route('roles.store'), ['name' => 'Super Rol', 'base_level' => 'gerencial', 'modules' => ['dashboard', 'efectividad'], 'actions' => []]);
    expect($resp->status() === 403 || session()->has('errors'))->toBeTrue();
    expect(DB::table('roles')->where('key', 'super_rol')->exists())->toBeFalse();

    // Nunca asigna Administrador ni roles con más permisos (gerencial del sistema tiene más módulos).
    $target = aseScopedUser($this, $admin, 'objetivo@example.test', [$atl->id], ['dashboard'], [], 'Basico');
    $this->actingAs($delegate)->put(route('usuarios.update-role', $target), ['role' => 'admin'])->assertForbidden();
    $this->actingAs($delegate)->put(route('usuarios.update-role', $target), ['role' => 'gerencial'])->assertForbidden();
    expect($target->fresh()->role)->toBe('basico');
    // Ni su propio rol, ni a un administrador.
    $this->actingAs($delegate)->put(route('roles.update', 'jefe_delegado'), ['name' => 'Jefe Delegado', 'base_level' => 'gerencial', 'modules' => ['dashboard', 'usuarios', 'roles', 'carga'], 'actions' => []])
        ->assertStatus(403);
    $this->actingAs($delegate)->post(route('usuarios.toggle-disabled', $admin))->assertForbidden();
    $this->actingAs($delegate)->post(route('usuarios.reset-password', $admin), ['password_mode' => 'auto'])->assertForbidden();

    // Matrices masivas heredadas: solo Administrador.
    $this->actingAs($delegate)->putJson(route('okr.responsibles.module-access'), ['matrix' => ['gerencial' => ['dashboard']]])->assertForbidden();
    $this->actingAs($delegate)->putJson(route('okr.responsibles.action-permissions'), ['matrix' => []])->assertForbidden();
});

it('cada operación de Usuarios y Roles está protegida en backend por permiso (o por el Gate de administrador)', function () {
    $adminGated = ['okr.responsibles.module-access', 'okr.responsibles.action-permissions'];
    $unprotected = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => preg_match('/^(usuarios|roles|okr\.responsibles)\./', (string) $r->getName()))
        ->reject(fn ($r) => $r->methods() === ['GET', 'HEAD'])
        ->reject(fn ($r) => in_array($r->getName(), $adminGated, true))
        ->reject(fn ($r) => collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'permission:')))
        ->map(fn ($r) => $r->getName())->values()->all();

    expect($unprotected)->toBe([]);

    // Y los listados exigen su módulo.
    foreach (['usuarios.index' => 'module:usuarios', 'roles.index' => 'module:roles'] as $name => $mw) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain($mw);
    }
});

it('protección del último administrador también al eliminar la propia cuenta desde Configuración', function () {
    $admin = aseAdmin();

    $this->actingAs($admin)->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors(['password' => 'No puedes eliminar tu cuenta: eres el único administrador habilitado. Asigna antes a otro administrador.']);
    expect($admin->fresh())->not->toBeNull();

    // Un segundo admin BLOQUEADO no cuenta como habilitado.
    User::factory()->create(['role' => 'admin', 'disabled_at' => now()]);
    $this->actingAs($admin)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('password');
    expect($admin->fresh())->not->toBeNull();
});

it('revocar o bloquear el acceso de un usuario nunca cambia el estado laboral de su colaborador', function () {
    $admin = aseAdmin();
    $atl = aseBranch('ATLIXCO');
    $period = aseMonthly(6);
    $employee = Employee::query()->create(['employee_code' => 'LAB-1', 'full_name' => 'LABORAL', 'normalized_name' => 'laboral', 'is_active' => true]);
    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $employee->id, 'branch_id' => $atl->id]);
    $user = User::factory()->create(['role' => 'colaborador', 'employee_id' => $employee->id]);
    $before = [Employee::query()->whereKey($employee->id)->first()->toArray(), EmployeeBranchAssignment::query()->where('employee_id', $employee->id)->get()->toArray()];

    $this->actingAs($admin)->post(route('usuarios.disable-access', $user))->assertSessionHas('success');
    $this->actingAs($admin)->post(route('usuarios.toggle-disabled', $user))->assertSessionHas('success');

    expect([Employee::query()->whereKey($employee->id)->first()->toArray(), EmployeeBranchAssignment::query()->where('employee_id', $employee->id)->get()->toArray()])->toBe($before);
    expect($user->fresh()->access_enabled_at)->toBeNull()->and($user->fresh()->disabled_at)->not->toBeNull();
});

// ─────────────────────────── Autenticación ───────────────────────────

it('login: credenciales válidas llevan al dashboard; inválidas y cuenta deshabilitada responden en español', function () {
    $user = User::factory()->create(['role' => 'gerencial', 'password' => Hash::make('Clave-Segura-1')]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Clave-Segura-1'])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
    $this->post(route('logout'));

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'incorrecta'])
        ->assertSessionHasErrors(['email' => 'El correo o la contraseña no son correctos. Revisa los datos e inténtalo de nuevo.']);
    $this->assertGuest();

    $user->forceFill(['disabled_at' => now()])->save();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Clave-Segura-1'])
        ->assertSessionHasErrors(['email' => 'Tu cuenta está deshabilitada. Contacta a un administrador.']);
    $this->assertGuest();
});

it('una cuenta deshabilitada con sesión abierta pierde la sesión en su siguiente petición (también por JSON)', function () {
    $user = User::factory()->create(['role' => 'gerencial']);
    $this->actingAs($user)->getJson(route('dashboard.data'))->assertOk();
    $user->forceFill(['disabled_at' => now()])->save();

    $this->actingAs($user->fresh())->getJson(route('dashboard.data'))->assertStatus(401)->assertJson(['message' => 'Tu cuenta está deshabilitada.']);
    $this->assertGuest();
});

it('recuperación de contraseña y 2FA: mensajes en español, token guardado con hash y reto de dos factores', function () {
    Notification::fake();
    $user = User::factory()->create(['role' => 'gerencial']);

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status', 'Te enviamos un enlace para restablecer tu contraseña. Revisa tu correo (y la carpeta de spam).');
    $plainToken = null;
    Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$plainToken) {
        $plainToken = $n->token;

        return true;
    });
    $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
    expect($stored)->not->toBe($plainToken)->and(Hash::check($plainToken, $stored))->toBeTrue();

    $this->post(route('password.update'), ['token' => $plainToken, 'email' => $user->email, 'password' => 'NuevaClave#2026', 'password_confirmation' => 'NuevaClave#2026'])
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Tu contraseña se restableció. Ya puedes iniciar sesión.');

    // 2FA confirmado: el login no entra directo, pasa por el reto.
    $user->forceFill(['two_factor_secret' => encrypt('secreto'), 'two_factor_recovery_codes' => encrypt(json_encode(['codigo-1'])), 'two_factor_confirmed_at' => now()])->save();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'NuevaClave#2026'])->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
    expect(__('validation.required', ['attribute' => 'correo']))->not->toContain('validation.');
})->skip(fn () => ! \Laravel\Fortify\Features::canManageTwoFactorAuthentication(), '2FA deshabilitado en config/fortify.php');

// ─────────────────────────── Invitaciones y secretos ───────────────────────────

it('invitación: con transporte aceptado el mensaje dice «aceptó el servidor» y nunca «entregado/recibido»; con transporte fallido tampoco', function () {
    $admin = aseAdmin();
    Mail::fake();

    $this->actingAs($admin)->post(route('usuarios.store'), ['name' => 'Inv', 'email' => 'inv@example.test', 'role' => 'colaborador', 'password_mode' => 'invite']);
    $ok = session('invitation_result');
    expect($ok['sent'])->toBeTrue()
        ->and($ok['message'])->toContain('servidor de correo aceptó')
        ->and(mb_strtolower($ok['message']))->not->toContain('entreg')->not->toContain('recib');
    Mail::assertSent(UserAccessGrantedMail::class, fn ($m) => str_contains($m->render(), 'Contraseña temporal'));

    Mail::shouldReceive('to')->andThrow(new TransportException('Connection could not be established with host "smtp.test:587"'));
    $this->actingAs($admin)->post(route('usuarios.store'), ['name' => 'Inv2', 'email' => 'inv2@example.test', 'role' => 'colaborador', 'password_mode' => 'invite']);
    $failed = session('invitation_result');
    expect($failed['sent'])->toBeFalse()
        ->and(mb_strtolower($failed['message']))->not->toContain('entreg')->not->toContain('acept')
        ->and(User::query()->where('email', 'inv2@example.test')->value('welcome_email_status'))->toBe('failed');
});

it('no se guardan secretos en texto plano: contraseña temporal cifrada en la sesión, hash en BD, sin tokens de reset y nada en la auditoría', function () {
    $admin = aseAdmin();
    Mail::fake();
    $atl = aseBranch('ATLIXCO');

    $this->actingAs($admin)->post(route('usuarios.store'), [
        'name' => 'Temporal', 'email' => 'temporal@example.test', 'role' => 'gerencial', 'password_mode' => 'auto', 'send_invitation' => true,
        'branch_scope' => 'branches', 'branch_ids' => [$atl->id],
    ])->assertSessionHasNoErrors();

    $sealed = session('temp_password');
    $secret = ResponsibleController::revealSecret($sealed);
    expect($sealed)->toBeString()->not->toContain($secret['password']);
    $user = User::query()->where('email', 'temporal@example.test')->firstOrFail();
    $rawHash = DB::table('users')->where('id', $user->id)->value('password');
    expect($rawHash)->not->toBe($secret['password'])->and(Hash::check($secret['password'], $rawHash))->toBeTrue();

    // Se muestra UNA vez: la pantalla la recibe descifrada y la siguiente visita ya no.
    $this->actingAs($admin)->withSession(['temp_password' => $sealed])->get(route('usuarios.index'))
        ->assertInertia(fn ($page) => $page->where('temp_password.password', $secret['password'])->etc());
    $this->actingAs($admin)->get(route('usuarios.index'))->assertInertia(fn ($page) => $page->where('temp_password', null)->etc());

    // La invitación entrega la contraseña temporal por correo (no un enlace):
    // es exactamente la que se cifró en sesión, en BD solo vive su hash y no se
    // crea ningún token de restablecimiento.
    $plainTemp = null;
    Mail::assertSent(UserAccessGrantedMail::class, function ($m) use (&$plainTemp) {
        $plainTemp = $m->temporaryPassword;

        return true;
    });
    expect($plainTemp)->toBe($secret['password']);
    expect(DB::table('users')->where('id', $user->id)->value('password'))->not->toBe($plainTemp);
    expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse();

    // Restablecer generado: también cifrado en la sesión.
    $this->actingAs($admin)->post(route('usuarios.reset-password', $user), ['password_mode' => 'auto']);
    $reset = ResponsibleController::revealSecret(session('reset_password'));
    expect(session('reset_password'))->not->toContain($reset['password']);

    // Ninguna bitácora guarda la contraseña temporal ni la de restablecimiento.
    $audit = DB::table('okr_audit_logs')->get()->map(fn ($r) => json_encode($r))->implode(' ');
    expect($audit)->not->toContain($secret['password'])->not->toContain($reset['password']);
});
