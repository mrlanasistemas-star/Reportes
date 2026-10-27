<?php

use App\Enums\ReportUploadStatus;
use App\Jobs\GenerateRadiographyJob;
use App\Jobs\UpdatePeriodDatabaseJob;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\OkrObjective;
use App\Models\Period;
use App\Models\PeriodDatabaseUpdateRun;
use App\Models\PeriodRadiographyExport;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\Access\DataScopeService;
use App\Services\DatabaseUpdateService;
use App\Services\PeriodRadiographyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/**
 * Fase 6.4 / 6.5 / 10 — alcance por sucursal real, auditoría de permisos y
 * colas. Un gerente solo-ATLIXCO no consulta TULA por enlace directo, cambio de
 * parámetros ni exportación; los usuarios existentes no quedan restringidos de
 * más; un cambio de rol/alcance o un bloqueo aplica en la siguiente petición;
 * un proceso cancelado nunca termina como exitoso.
 */
function bscBranch(string $name): Branch
{
    return Branch::query()->firstOrCreate(['normalized_name' => mb_strtolower($name)], ['code' => mb_substr($name, 0, 4) . random_int(10, 99), 'name' => $name, 'is_active' => true]);
}

function bscMonthly(int $month): Period
{
    return Period::query()->create([
        'name' => "Mes {$month} 2026", 'code' => sprintf('M-2026-%02d', $month), 'type' => 'monthly', 'year' => 2026, 'month' => $month,
        'sequence' => 1, 'start_date' => sprintf('2026-%02d-01', $month), 'end_date' => sprintf('2026-%02d-28', $month), 'is_closed' => false,
    ]);
}

function bscAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

/** Admin crea el rol y el usuario por las rutas reales; devuelve el usuario solo-ATLIXCO. */
function bscAtlixcoManager($test, User $admin, Branch $atlixco, array $extraModules = [], array $extraActions = []): User
{
    $test->actingAs($admin)->post(route('roles.store'), [
        'name' => 'Gerente Atlixco', 'description' => 'Opera solo su sucursal', 'base_level' => 'gerencial',
        'modules' => array_merge(['dashboard', 'reportes', 'colaboradores', 'okr'], $extraModules),
        'actions' => array_merge(['reportes' => ['ver_todos', 'exportar'], 'colaboradores' => ['editar', 'exportar'], 'okr' => ['exportar', 'activar']], $extraActions),
    ])->assertSessionHasNoErrors();

    $test->actingAs($admin)->post(route('usuarios.store'), [
        'name' => 'Gerente Atlixco', 'email' => 'atlixco@example.com', 'role' => 'gerente_atlixco',
        'password_mode' => 'auto', 'send_invitation' => false, 'enable_access' => true,
        'branch_scope' => 'branches', 'branch_ids' => [$atlixco->id],
    ])->assertSessionHasNoErrors();

    return User::query()->where('email', 'atlixco@example.com')->firstOrFail();
}

function bscObjective(User $owner, Branch $branch): OkrObjective
{
    return OkrObjective::query()->create([
        'scope_type' => OkrObjective::SCOPE_BRANCH, 'branch_id' => $branch->id, 'title' => 'Colocación ' . $branch->name,
        'responsible_user_id' => $owner->id, 'created_by' => $owner->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(8)->toDateString(), 'duration_weeks' => 8,
        'lifecycle_status' => OkrObjective::STATUS_DRAFT,
    ]);
}

it('escenario completo: admin crea rol + usuario solo ATLIXCO; ve ATLIXCO y recibe 403 claro para TULA en dashboard, reportes, exportes, colaboradores, OKR y documentos', function () {
    Mail::fake();
    Storage::fake('local');
    $admin = bscAdmin();
    $atlixco = bscBranch('ATLIXCO');
    $tula = bscBranch('TULA');
    $user = bscAtlixcoManager($this, $admin, $atlixco);

    // Persistencia del alcance (tabla aditiva) y lo que ve la UI.
    expect($user->branch_scope)->toBe('branches');
    expect(DB::table('user_branch_scopes')->where('user_id', $user->id)->pluck('branch_id')->all())->toBe([$atlixco->id]);
    expect(app(DataScopeService::class)->label($user))->toBe('ATLIXCO');

    // ── Dashboard ──
    $denied = $this->actingAs($user)->getJson(route('dashboard.data', ['branch_id' => $tula->id]));
    $denied->assertForbidden();
    expect($denied->json('message'))->toContain('TULA')->toContain('ATLIXCO')->toContain('administrador');
    $this->actingAs($user)->getJson(route('dashboard.data', ['branch_id' => $atlixco->id]))->assertOk();
    // Tendencia: sin sucursal → la de SU sucursal (nunca el consolidado); TULA → 403.
    expect($this->actingAs($user)->getJson(route('dashboard.trend'))->assertOk()->json('scope.branch_id'))->toBe($atlixco->id);
    $this->actingAs($user)->getJson(route('dashboard.trend', ['branch_id' => $tula->id]))->assertForbidden();
    $this->actingAs($user)->get(route('dashboard.export-pdf', ['period_id' => 1, 'branch_id' => $tula->id]))->assertForbidden();

    // ── Reportes mensuales: general/consolidado y TULA por URL directa o parámetros ──
    $mayo = bscMonthly(5);
    $junio = bscMonthly(6);
    $this->actingAs($user)->getJson(route('reportes-mensuales.scoped-data', ['period' => $junio->id, 'scope' => 'general']))->assertForbidden();
    $this->actingAs($user)->getJson(route('reportes-mensuales.scoped-data', ['period' => $junio->id, 'scope' => 'branch', 'branch_id' => $tula->id]))->assertForbidden();
    $this->actingAs($user)->get(route('reportes-mensuales.export-radiography', $junio))->assertForbidden();
    $this->actingAs($user)->get(route('reportes-mensuales.export-radiography-pdf', $junio))->assertForbidden();
    $this->actingAs($user)->get(route('reportes-mensuales.export-summary', $junio))->assertForbidden();
    $this->actingAs($user)->get(route('reportes-mensuales.export-filtered-radiography', ['period' => $junio->id, 'scope' => 'branch', 'branch_id' => $tula->id]))->assertForbidden();
    $this->actingAs($user)->get(route('reportes-mensuales.export-filtered-radiography-pdf', ['period' => $junio->id]))->assertForbidden();
    // scope=branch sin branch_id nunca cae al reporte general.
    $this->actingAs($user)->get(route('reportes-mensuales.export-filtered-radiography-pdf', ['period' => $junio->id, 'scope' => 'branch']))->assertForbidden();
    $this->actingAs($user)->post(route('reportes-mensuales.consolidate', $junio))->assertForbidden();
    $this->actingAs($user)->getJson(route('reportes-mensuales.comparativo-data', ['period_a' => $junio->id, 'period_b' => $mayo->id, 'scope' => 'general']))->assertForbidden();
    // Vista previa sin alcance → se abre directo en SU sucursal (nunca la general).
    $this->actingAs($user)->get(route('reportes-mensuales.preview', $junio))
        ->assertRedirect(route('reportes-mensuales.preview', ['period' => $junio->id, 'scope' => 'branch', 'branch_id' => $atlixco->id]));

    // Gestor de TULA por parámetro employee_id.
    $tulaEmployee = Employee::query()->create(['employee_code' => 'T-1', 'full_name' => 'GESTOR TULA', 'normalized_name' => 'gestor tula', 'is_active' => true]);
    $atlEmployee = Employee::query()->create(['employee_code' => 'A-1', 'full_name' => 'GESTOR ATLIXCO', 'normalized_name' => 'gestor atlixco', 'is_active' => true]);
    $tulaAssignment = EmployeeBranchAssignment::query()->create(['period_id' => $junio->id, 'employee_id' => $tulaEmployee->id, 'branch_id' => $tula->id]);
    $atlAssignment = EmployeeBranchAssignment::query()->create(['period_id' => $junio->id, 'employee_id' => $atlEmployee->id, 'branch_id' => $atlixco->id]);
    $gestorDenied = $this->actingAs($user)->getJson(route('reportes-mensuales.scoped-data', ['period' => $junio->id, 'scope' => 'employee', 'employee_id' => $tulaEmployee->id]));
    $gestorDenied->assertForbidden();
    expect($gestorDenied->json('message'))->toContain('no pertenece a tus sucursales');

    // Reportes ya generados: solo los de SU sucursal se listan y descargan.
    $tulaRun = PeriodRadiographyRun::query()->create(['period_id' => $junio->id, 'report_type' => 'simple', 'scope' => 'branch', 'branch_id' => $tula->id, 'status' => 'success', 'finished_at' => now()]);
    $atlRun = PeriodRadiographyRun::query()->create(['period_id' => $junio->id, 'report_type' => 'simple', 'scope' => 'branch', 'branch_id' => $atlixco->id, 'status' => 'success', 'finished_at' => now()]);
    $generalRun = PeriodRadiographyRun::query()->create(['period_id' => $junio->id, 'report_type' => 'simple', 'scope' => 'general', 'status' => 'success', 'finished_at' => now()]);
    $this->actingAs($user)->get(route('reportes-mensuales.run-excel', $tulaRun))->assertForbidden();
    $this->actingAs($user)->get(route('reportes-mensuales.run-pdf', $generalRun))->assertForbidden();
    $this->actingAs($user)->get(route('reportes-mensuales.run-ver', $tulaRun))->assertForbidden();
    // El de ATLIXCO pasa el candado de alcance (404: el archivo no existe en la prueba).
    $this->actingAs($user)->get(route('reportes-mensuales.run-excel', $atlRun))->assertNotFound();
    $this->actingAs($user)->get(route('reportes-mensuales.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('generatedReports', fn ($rows) => collect($rows)->pluck('id')->all() === [$atlRun->id]));

    // ── Colaboradores ──
    $this->actingAs($user)->get(route('asignaciones-empleado-sucursal.index', ['period_id' => $junio->id]))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('assignments', fn ($rows) => collect($rows)->pluck('employee_id')->all() === [$atlEmployee->id])
            ->where('branches', fn ($rows) => collect($rows)->pluck('id')->all() === [$atlixco->id]));
    $this->actingAs($user)->post(route('asignaciones-empleado-sucursal.manual-match', $tulaAssignment), ['branch_id' => $atlixco->id])->assertForbidden();
    $this->actingAs($user)->put(route('asignaciones-empleado-sucursal.update', $atlAssignment), ['branch_id' => $tula->id])->assertForbidden();
    $this->actingAs($user)->post(route('asignaciones-empleado-sucursal.auto-match'), ['period_id' => $junio->id])->assertForbidden();
    $this->actingAs($user)->get(route('reportes-mensuales.export-employees-historico', ['period' => $junio->id, 'branch_id' => $tula->id]))->assertForbidden();
    expect($tulaAssignment->fresh()->branch_id)->toBe($tula->id);
    expect($atlAssignment->fresh()->branch_id)->toBe($atlixco->id);

    // ── OKR y documentos ──
    $tulaOkr = bscObjective($admin, $tula);
    $atlOkr = bscObjective($admin, $atlixco);
    $this->actingAs($user)->get(route('okr.show', $tulaOkr))->assertForbidden();
    $this->actingAs($user)->get(route('okr.show', $atlOkr))->assertOk();
    $this->actingAs($user)->post(route('okr.activate', $tulaOkr))->assertForbidden();
    $this->actingAs($user)->getJson(route('okr.employees.lookup', ['branch_id' => $tula->id]))->assertForbidden();
    $this->actingAs($user)->get(route('okr.dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.branches', fn ($rows) => collect($rows)->pluck('id')->all() === [$atlixco->id]));

    Storage::disk('local')->put('okr-evidences/tula.pdf', 'pdf');
    $evidence = $tulaOkr->evidences()->create([
        'original_name' => 'tula.pdf', 'stored_path' => 'okr-evidences/tula.pdf', 'disk' => 'local',
        'mime_type' => 'application/pdf', 'size_bytes' => 3, 'uploaded_by' => $admin->id,
    ]);
    $this->actingAs($user)->get(route('okr.evidences.download', $evidence))->assertForbidden();
    $this->actingAs($admin)->get(route('okr.evidences.download', $evidence))->assertOk();
});

it('los usuarios existentes (sin alcance asignado) no quedan restringidos de más: gerencial conserva alcance general, colaborador solo lo propio', function () {
    $atlixco = bscBranch('ATLIXCO');
    $tula = bscBranch('TULA');
    $junio = bscMonthly(6);
    $scope = app(DataScopeService::class);

    $legacyManager = User::factory()->create(['role' => 'gerencial']); // branch_scope = NULL
    $admin = bscAdmin();
    expect($scope->isGeneral($legacyManager))->toBeTrue();
    expect($scope->isGeneral($admin))->toBeTrue();
    expect($scope->describe($legacyManager)['mode'])->toBe('legacy');

    $this->actingAs($legacyManager)->getJson(route('dashboard.data', ['branch_id' => $tula->id]))->assertOk();
    // Radiografía general por alcance: no la bloquea el candado de sucursal (sin snapshot → no 403).
    expect($this->actingAs($legacyManager)->getJson(route('reportes-mensuales.scoped-data', ['period' => $junio->id, 'scope' => 'general']))->status())->not->toBe(403);
    expect($this->actingAs($legacyManager)->getJson(route('reportes-mensuales.scoped-data', ['period' => $junio->id, 'scope' => 'branch', 'branch_id' => $tula->id]))->status())->not->toBe(403);
    $tulaOkr = bscObjective($admin, $tula);
    $this->actingAs($legacyManager)->get(route('okr.show', $tulaOkr))->assertOk();

    // Colaborador previo: OKR solo lo propio (como antes) y sin alcance de sucursales ajenas.
    $collaborator = User::factory()->create(['role' => 'colaborador']);
    expect($scope->isGeneral($collaborator))->toBeFalse();
    $own = bscObjective($collaborator, $atlixco);
    $this->actingAs($collaborator)->get(route('okr.show', $own))->assertOk();
    $this->actingAs($collaborator)->get(route('okr.show', $tulaOkr))->assertForbidden();
});

it('delegación: nadie concede un alcance mayor al propio ni administra a quien tiene más alcance', function () {
    Mail::fake();
    $admin = bscAdmin();
    $atlixco = bscBranch('ATLIXCO');
    $tula = bscBranch('TULA');
    $delegate = bscAtlixcoManager($this, $admin, $atlixco, ['usuarios'], ['usuarios' => ['crear', 'asignar_rol', 'restablecer']]);

    $base = ['email' => 'nuevo@example.com', 'name' => 'Nuevo', 'role' => 'gerente_atlixco', 'password_mode' => 'auto', 'send_invitation' => false];
    $this->actingAs($delegate)->post(route('usuarios.store'), $base + ['branch_scope' => 'all'])->assertForbidden();
    $this->actingAs($delegate)->post(route('usuarios.store'), $base + ['branch_scope' => 'branches', 'branch_ids' => [$tula->id]])->assertForbidden();
    expect(User::query()->where('email', 'nuevo@example.com')->exists())->toBeFalse();

    // Sin elegir alcance, el usuario nuevo hereda las sucursales de quien lo crea (nunca «todas»).
    $this->actingAs($delegate)->post(route('usuarios.store'), $base)->assertSessionHasNoErrors();
    $created = User::query()->where('email', 'nuevo@example.com')->firstOrFail();
    expect(app(DataScopeService::class)->allowedBranchIds($created))->toBe([$atlixco->id]);

    // Un usuario con alcance general no lo administra un delegado restringido.
    $generalManager = User::factory()->create(['role' => 'gerencial']);
    $this->actingAs($delegate)->post(route('usuarios.reset-password', $generalManager), ['password_mode' => 'auto'])->assertForbidden();
    $this->actingAs($delegate)->put(route('usuarios.update-branch-scope', $created), ['branch_scope' => 'branches', 'branch_ids' => [$tula->id]])->assertForbidden();

    // Subir de rol a un colaborador sin alcance explícito no le da «todas» por arrastre.
    $legacyCollaborator = User::factory()->create(['role' => 'colaborador']);
    $this->actingAs($delegate)->put(route('usuarios.update-role', $legacyCollaborator), ['role' => 'gerente_atlixco'])->assertSessionHasNoErrors();
    expect(app(DataScopeService::class)->isGeneral($legacyCollaborator->fresh()))->toBeFalse();
});

it('cambio de alcance y de rol durante una sesión activa aplica en la siguiente petición (sin privilegios residuales)', function () {
    Mail::fake();
    $admin = bscAdmin();
    $atlixco = bscBranch('ATLIXCO');
    $tula = bscBranch('TULA');
    $user = bscAtlixcoManager($this, $admin, $atlixco);

    $this->actingAs($user)->getJson(route('dashboard.data', ['branch_id' => $atlixco->id]))->assertOk();

    $this->actingAs($admin)->put(route('usuarios.update-branch-scope', $user), ['branch_scope' => 'branches', 'branch_ids' => [$tula->id]])
        ->assertSessionHas('success', 'Alcance de Gerente Atlixco actualizado: TULA.');
    $this->actingAs($user->fresh())->getJson(route('dashboard.data', ['branch_id' => $atlixco->id]))->assertForbidden();
    $this->actingAs($user->fresh())->getJson(route('dashboard.data', ['branch_id' => $tula->id]))->assertOk();
    expect(DB::table('okr_audit_logs')->where('action', 'branch_scope_changed')->where('auditable_id', $user->id)->exists())->toBeTrue();

    // Cambio de rol: el módulo se pierde en la siguiente petición.
    $this->actingAs($admin)->put(route('usuarios.update-role', $user), ['role' => 'colaborador'])->assertSessionHasNoErrors();
    $this->actingAs($user->fresh())->get(route('dashboard'))->assertRedirect('/okr');
    $this->actingAs($user->fresh())->getJson(route('dashboard.data'))->assertForbidden();
});

it('una cuenta bloqueada con sesión abierta pierde el acceso en su siguiente petición', function () {
    $admin = bscAdmin();
    $user = User::factory()->create(['role' => 'gerencial']);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $this->actingAs($admin)->post(route('usuarios.toggle-disabled', $user))->assertSessionHas('success');

    $this->actingAs($user->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('cambiar la propia contraseña cierra las demás sesiones abiertas y anula el «recordarme»', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create(['role' => 'gerencial', 'password' => Hash::make('password'), 'remember_token' => 'token-anterior']);
    DB::table('sessions')->insert([
        'id' => 'otra-sesion-abierta', 'user_id' => $user->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'otro equipo',
        'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp,
    ]);

    $this->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'password', 'password' => 'NuevaClave#2026x', 'password_confirmation' => 'NuevaClave#2026x',
    ])->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('id', 'otra-sesion-abierta')->exists())->toBeFalse();
    expect($user->fresh()->remember_token)->not->toBe('token-anterior');
    expect(Hash::check('NuevaClave#2026x', $user->fresh()->password))->toBeTrue();
});

it('no se descargan archivos ajenos: evidencia de otro colaborador y original cargado para alcance restringido', function () {
    Storage::fake('local');
    Storage::fake('public');
    $atlixco = bscBranch('ATLIXCO');
    $owner = User::factory()->create(['role' => 'colaborador']);
    $stranger = User::factory()->create(['role' => 'colaborador']);
    $objective = bscObjective($owner, $atlixco);
    Storage::disk('local')->put('okr-evidences/propia.pdf', 'pdf');
    $evidence = $objective->evidences()->create([
        'original_name' => 'propia.pdf', 'stored_path' => 'okr-evidences/propia.pdf', 'disk' => 'local',
        'mime_type' => 'application/pdf', 'size_bytes' => 3, 'uploaded_by' => $owner->id,
    ]);
    // El permiso okr.exportar del colaborador se concede para aislar la regla de propiedad.
    DB::table('role_action_permissions')->updateOrInsert(['role' => 'colaborador', 'module' => 'okr', 'action' => 'exportar'], ['created_at' => now(), 'updated_at' => now()]);
    \Illuminate\Support\Facades\Cache::flush();
    $this->actingAs($stranger)->get(route('okr.evidences.download', $evidence))->assertForbidden();
    $this->actingAs($owner)->get(route('okr.evidences.download', $evidence))->assertOk();

    // Original NOI/Lendus: contiene todas las sucursales → solo alcance general.
    $period = bscMonthly(6);
    $source = DataSource::query()->create(['code' => 'noi_scope_test', 'name' => 'NOI', 'description' => 'NOI', 'is_active' => true]);
    Storage::disk('public')->put('reports/noi.xlsx', 'xlsx');
    $upload = ReportUpload::query()->create([
        'period_id' => $period->id, 'data_source_id' => $source->id, 'original_name' => 'NOI.xlsx', 'stored_path' => 'reports/noi.xlsx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'file_size' => 4,
        'uploaded_by' => $owner->id, 'uploaded_at' => now(), 'status' => ReportUploadStatus::Pending,
    ]);
    $restricted = User::factory()->create(['role' => 'gerencial']);
    app(DataScopeService::class)->save($restricted, DataScopeService::MODE_BRANCHES, [$atlixco->id], bscAdmin());
    $this->actingAs($restricted->fresh())->get(route('historico-general.download', $upload))->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => 'gerencial']))->get(route('historico-general.download', $upload))->assertOk();
});

it('una radiografía cancelada en cola nunca se "resucita" ni termina como exitosa', function () {
    Mail::fake();
    $period = bscMonthly(6);
    $run = PeriodRadiographyRun::query()->create(['period_id' => $period->id, 'report_type' => 'simple', 'scope' => 'general', 'status' => 'cancelled', 'cancelled_at' => now()]);

    app()->call([new GenerateRadiographyJob($period->id, null, $run->id, []), 'handle']);

    expect($run->fresh()->status)->toBe('cancelled');
    expect(PeriodRadiographyExport::query()->where('run_id', $run->id)->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('una radiografía cancelada mientras corre se queda cancelada (sin éxito, sin error, sin correo)', function () {
    Mail::fake();
    $user = User::factory()->create();
    $period = bscMonthly(6);
    $summary = PeriodSummary::query()->create(['period_id' => $period->id, 'status' => 'generated', 'generated_at' => now(), 'global_metrics' => [], 'warnings' => [], 'version' => 1]);
    $run = PeriodRadiographyRun::query()->create(['period_id' => $period->id, 'report_type' => 'simple', 'scope' => 'general', 'status' => 'queued', 'queued_at' => now()]);

    // El usuario cancela mientras el Job calcula (misma escritura que ReportUploadController::cancelGeneration).
    $this->mock(PeriodRadiographyService::class, function ($mock) use ($run, $summary) {
        $mock->shouldReceive('generate')->once()->andReturnUsing(function () use ($run, $summary) {
            PeriodRadiographyRun::query()->whereKey($run->id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'log' => 'Generación cancelada por usuario.']);

            return $summary;
        });
    });

    app()->call([new GenerateRadiographyJob($period->id, $user->id, $run->id, []), 'handle']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe('cancelled');
    expect($fresh->log)->toBe('Generación cancelada por usuario.');
    expect(PeriodRadiographyExport::query()->where('run_id', $run->id)->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('una actualización de BD cancelada durante el proceso no termina como exitosa ni como fallida', function () {
    Mail::fake();
    $period = bscMonthly(6);
    $run = PeriodDatabaseUpdateRun::query()->create(['period_id' => $period->id, 'status' => 'queued', 'queued_at' => now()]);

    $this->mock(DatabaseUpdateService::class, function ($mock) use ($run) {
        $mock->shouldReceive('updateForPeriod')->once()->andReturnUsing(function () use ($run) {
            PeriodDatabaseUpdateRun::query()->whereKey($run->id)->update(['status' => 'cancelled', 'log' => 'Carga cancelada por usuario.']);
        });
    });

    app()->call([new UpdatePeriodDatabaseJob($period->id, $run->id, null), 'handle']);

    expect($run->fresh()->status)->toBe('cancelled');
    expect($run->fresh()->log)->toBe('Carga cancelada por usuario.');

    // Cancelado ANTES de que el worker lo tome: nunca pasa a running.
    $queued = PeriodDatabaseUpdateRun::query()->create(['period_id' => $period->id, 'status' => 'cancelled']);
    app()->call([new UpdatePeriodDatabaseJob($period->id, $queued->id, null), 'handle']);
    expect($queued->fresh()->status)->toBe('cancelled');
    expect($queued->fresh()->started_at)->toBeNull();
});

it('Dashboard OKR: los denominadores (colaboradores y sucursales) de un usuario restringido son los de SU alcance, nunca los de toda la empresa', function () {
    Mail::fake();
    $admin = bscAdmin();
    $atlixco = bscBranch('ATLIXCO');
    $tula = bscBranch('TULA');
    $user = bscAtlixcoManager($this, $admin, $atlixco);
    $period = Period::query()->create(['name' => 'Semana OKR', 'code' => 'W-OKR-SCOPE', 'type' => 'weekly', 'year' => 2026, 'month' => 8, 'sequence' => 1, 'start_date' => '2026-08-01', 'end_date' => '2026-08-07']);
    foreach ([['A1', $atlixco], ['T1', $tula], ['T2', $tula]] as [$code, $branch]) {
        $e = Employee::query()->create(['employee_code' => "OKR-{$code}", 'full_name' => "Persona {$code}", 'normalized_name' => mb_strtolower("persona {$code}"), 'is_active' => true, 'source_system' => 'noi']);
        EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $e->id, 'branch_id' => $branch->id]);
    }

    $this->actingAs($admin)->get(route('okr.dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('cards.total_employees', 3)->etc());
    $this->actingAs($user)->get(route('okr.dashboard'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('cards.total_employees', 1)->where('cards.total_branches', 1)->etc());
    $this->actingAs($user)->get(route('okr.dashboard', ['branch_id' => $tula->id]))->assertForbidden();
});

it('Colaboradores: plantilla, conteos e historial de altas/bajas de un usuario restringido solo cuentan SUS sucursales', function () {
    Mail::fake();
    $admin = bscAdmin();
    $atlixco = bscBranch('ATLIXCO');
    $tula = bscBranch('TULA');
    $user = bscAtlixcoManager($this, $admin, $atlixco);
    $junio = bscMonthly(6);
    $roster = function (string $name, Branch $branch, bool $active, string $movement) use ($junio) {
        $e = Employee::query()->create(['employee_code' => 'R-' . $name, 'full_name' => $name, 'normalized_name' => mb_strtolower($name), 'is_active' => true]);
        DB::table('period_employee_rosters')->insert([
            'period_id' => $junio->id, 'employee_id' => $e->id, 'employee_key' => mb_strtolower($name), 'nombre_normalizado' => mb_strtolower($name),
            'nombre_original' => $name, 'branch_id' => $branch->id, 'branch_name' => $branch->name, 'is_branch_operativa' => true,
            'source' => 'noi_nomina', 'is_active_for_period' => $active, 'movement_type' => $movement, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $roster('ALTA ATLIXCO', $atlixco, true, 'alta');
    $roster('ALTA TULA', $tula, true, 'alta');
    $roster('ACTIVO TULA', $tula, true, 'activo');
    $roster('AUSENTE TULA', $tula, false, 'baja');

    $this->actingAs($admin)->get(route('asignaciones-empleado-sucursal.index', ['period_id' => $junio->id]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('labor.roster.plantilla', 3)->where('labor.counts.altas', 2)->etc());

    $this->actingAs($user)->get(route('asignaciones-empleado-sucursal.index', ['period_id' => $junio->id]))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('labor.roster.plantilla', 1)
            ->where('labor.counts.altas', 1)
            ->where('labor.counts.pendientes', 0)
            ->where('summary.plantilla', 1)
            ->where('hires', fn ($rows) => collect($rows)->pluck('employee_name')->all() === ['ALTA ATLIXCO'])
            ->where('labor.pending', fn ($rows) => collect($rows)->isEmpty())
            ->where('labor_history', fn ($rows) => collect($rows)->firstWhere('period_id', $junio->id)['plantilla'] === 1
                && collect($rows)->firstWhere('period_id', $junio->id)['altas'] === 1)
            ->etc());
});

it('Colaboradores: un admin que elige ATLIXCO recibe plantilla, conteos, listas e historial SOLO de ATLIXCO (igual que el usuario restringido); sin elegir, el total', function () {
    Mail::fake();
    $admin = bscAdmin();
    $atlixco = bscBranch('ATLIXCO');
    $tula = bscBranch('TULA');
    $user = bscAtlixcoManager($this, $admin, $atlixco);
    $junio = bscMonthly(6);
    $roster = function (string $name, Branch $branch, bool $active, string $movement) use ($junio) {
        $e = Employee::query()->create(['employee_code' => 'R-' . $name, 'full_name' => $name, 'normalized_name' => mb_strtolower($name), 'is_active' => true]);
        EmployeeBranchAssignment::query()->create(['period_id' => $junio->id, 'employee_id' => $e->id, 'branch_id' => $branch->id]);
        DB::table('period_employee_rosters')->insert([
            'period_id' => $junio->id, 'employee_id' => $e->id, 'employee_key' => mb_strtolower($name), 'nombre_normalizado' => mb_strtolower($name),
            'nombre_original' => $name, 'branch_id' => $branch->id, 'branch_name' => $branch->name, 'is_branch_operativa' => true,
            'source' => 'noi_nomina', 'is_active_for_period' => $active, 'movement_type' => $movement, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $roster('ALTA ATLIXCO', $atlixco, true, 'alta');
    $roster('ALTA TULA', $tula, true, 'alta');
    $roster('ACTIVO TULA', $tula, true, 'activo');

    $atlixcoOnly = fn ($page) => $page
        ->where('labor.roster.plantilla', 1)
        ->where('summary.plantilla', 1)
        ->where('summary.total', 1)
        ->where('labor.counts.altas', 1)
        ->where('assignments', fn ($rows) => collect($rows)->pluck('employee_name')->all() === ['ALTA ATLIXCO'])
        ->where('hires', fn ($rows) => collect($rows)->pluck('employee_name')->all() === ['ALTA ATLIXCO'])
        ->where('labor_history', fn ($rows) => collect($rows)->firstWhere('period_id', $junio->id)['plantilla'] === 1)
        ->etc();

    // Admin sin filtro: toda la empresa.
    $this->actingAs($admin)->get(route('asignaciones-empleado-sucursal.index', ['period_id' => $junio->id]))->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary.plantilla', 3)->where('summary.total', 3)->where('selected_branch_id', null)->etc());

    // Admin con ATLIXCO elegido: mismas cifras que el usuario solo-ATLIXCO.
    $this->actingAs($admin)->get(route('asignaciones-empleado-sucursal.index', ['period_id' => $junio->id, 'branch_id' => $atlixco->id]))->assertOk()
        ->assertInertia(fn ($page) => $atlixcoOnly($page)->where('selected_branch_id', $atlixco->id));
    $this->actingAs($user)->get(route('asignaciones-empleado-sucursal.index', ['period_id' => $junio->id]))->assertOk()
        ->assertInertia($atlixcoOnly);
    $this->actingAs($user)->get(route('asignaciones-empleado-sucursal.index', ['period_id' => $junio->id, 'branch_id' => $atlixco->id]))->assertOk()
        ->assertInertia($atlixcoOnly);

    // El restringido no puede elegir otra sucursal.
    $this->actingAs($user)->get(route('asignaciones-empleado-sucursal.index', ['period_id' => $junio->id, 'branch_id' => $tula->id]))->assertForbidden();
});

it('Comparativo por gestor: el colaborador también se autoriza en el periodo B — si en B pertenecía a otra sucursal, 403 en español y nunca datos de B', function () {
    Mail::fake();
    $admin = bscAdmin();
    $atlixco = bscBranch('ATLIXCO');
    $tula = bscBranch('TULA');
    $user = bscAtlixcoManager($this, $admin, $atlixco);
    $mayo = bscMonthly(5);
    $junio = bscMonthly(6);
    $julio = bscMonthly(7);

    // Junio y julio en ATLIXCO; mayo en TULA (cambio de sucursal).
    $e = Employee::query()->create(['employee_code' => 'CMP-1', 'full_name' => 'GESTOR MOVIDO', 'normalized_name' => 'gestor movido', 'is_active' => true]);
    foreach ([[$mayo, $tula], [$junio, $atlixco], [$julio, $atlixco]] as [$p, $b]) {
        EmployeeBranchAssignment::query()->create(['period_id' => $p->id, 'employee_id' => $e->id, 'branch_id' => $b->id]);
    }

    $calls = new ArrayObject();
    $export = Mockery::mock(\App\Services\RadiografiaExportService::class);
    $export->shouldReceive('comparativeViewData')->andReturnUsing(function ($period, array $config) use ($calls) {
        $calls[] = $config;

        return ['rows' => [], 'scopeLabel' => 'x', 'currentComposite' => null, 'compareComposite' => null];
    });
    app()->instance(\App\Services\RadiografiaExportService::class, $export);

    // A = junio (ATLIXCO) vs B = mayo (TULA) → 403, sin construir el comparativo.
    $denied = $this->actingAs($user)->getJson(route('reportes-mensuales.comparativo-data', ['period_a' => $junio->id, 'period_b' => $mayo->id, 'scope' => 'employee', 'employee_id' => $e->id]));
    $denied->assertForbidden();
    expect($denied->json('message'))->toContain('periodo de comparación')->toContain('ATLIXCO');
    expect($calls)->toHaveCount(0);

    // Lo mismo en la pantalla (deep-link) y en los exportes con compare_period_id.
    $this->actingAs($user)->get(route('reportes-mensuales.comparativo', ['period_a' => $junio->id, 'period_b' => $mayo->id, 'scope' => 'employee', 'employee_id' => $e->id]))->assertForbidden();
    $this->actingAs($user)->get(route('reportes-mensuales.export-filtered-radiography', ['period' => $junio->id, 'scope' => 'employee', 'employee_id' => $e->id, 'compare_period_id' => $mayo->id, 'report_type' => 'comparative_monthly']))->assertForbidden();
    $this->actingAs($user)->get(route('reportes-mensuales.export-filtered-radiography-pdf', ['period' => $junio->id, 'scope' => 'employee', 'employee_id' => $e->id, 'compare_period_id' => $mayo->id, 'report_type' => 'comparative_monthly']))->assertForbidden();

    // A = junio vs B = julio (ambos ATLIXCO) → permitido.
    $this->actingAs($user)->getJson(route('reportes-mensuales.comparativo-data', ['period_a' => $junio->id, 'period_b' => $julio->id, 'scope' => 'employee', 'employee_id' => $e->id]))->assertOk();
    expect($calls)->toHaveCount(1);

    // Admin (alcance general) sí puede comparar contra mayo.
    $this->actingAs($admin)->getJson(route('reportes-mensuales.comparativo-data', ['period_a' => $junio->id, 'period_b' => $mayo->id, 'scope' => 'employee', 'employee_id' => $e->id]))->assertOk();
});
