<?php

use App\Models\Period;
use App\Models\User;
use App\Services\Access\ActionPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

// Las páginas nuevas aún no están en el manifest de Vite hasta el siguiente build.
beforeEach(fn () => $this->withoutVite());

/**
 * Prioridad 8/9 — Roles y permisos: crear rol → módulos → acciones → usuario
 * con ese rol → lo permitido pasa, lo prohibido es 403 por URL directa;
 * cambiar permisos no deja privilegios residuales; delegación sin escalar.
 */
function rpAdmin(): User
{
    return User::factory()->create(['role' => 'admin', 'access_enabled_at' => now()]);
}

function rpPeriod(): Period
{
    return Period::query()->create([
        'name' => 'Semana 1 - Abril 2026', 'code' => 'W-2026-04-01', 'type' => 'weekly', 'year' => 2026, 'month' => 4,
        'sequence' => 1, 'start_date' => '2026-04-01', 'end_date' => '2026-04-05', 'is_closed' => false,
    ]);
}

/** Crea el rol por la ruta real y devuelve la respuesta. */
function rpCreateRole($test, User $actor, array $payload)
{
    return $test->actingAs($actor)->post(route('roles.store'), $payload + ['base_level' => 'gerencial']);
}

function rpUserWithRole(string $role): User
{
    return User::factory()->create(['role' => $role, 'access_enabled_at' => now()]);
}

it('creates a role in 3 steps (name, modules, actions) and shows the exact summary', function () {
    $admin = rpAdmin();

    rpCreateRole($this, $admin, [
        'name' => 'Gerente de Sucursal', 'description' => 'Opera su sucursal',
        'modules' => ['periodos', 'reportes', 'carga', 'efectividad'],
        'actions' => ['periodos' => ['crear'], 'carga' => ['subir', 'generar'], 'efectividad' => ['calcular', 'exportar'], 'reportes' => []],
    ])->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Rol «Gerente de Sucursal» creado. Gerente de Sucursal puede utilizar 4 módulos y tiene 5 acciones permitidas.');

    $this->actingAs($admin)->get(route('roles.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('roles/Index')
            ->where('roles', fn ($roles) => collect($roles)->firstWhere('key', 'gerente_de_sucursal')['summary'] === 'Gerente de Sucursal puede utilizar 4 módulos y tiene 5 acciones permitidas.'));
});

it('a user with the new role can do what is allowed and gets 403 (with a clear message) on everything else by direct URL', function () {
    $admin = rpAdmin();
    rpCreateRole($this, $admin, [
        'name' => 'Gerente de Sucursal', 'modules' => ['periodos', 'reportes', 'carga', 'efectividad'],
        'actions' => ['periodos' => ['crear'], 'carga' => ['subir'], 'efectividad' => ['calcular']],
    ])->assertSessionHasNoErrors();

    Mail::fake();
    $this->actingAs($admin)->post(route('usuarios.store'), [
        'name' => 'Gerente Uno', 'email' => 'gerente1@example.test', 'role' => 'gerente_de_sucursal', 'password_mode' => 'auto', 'enable_access' => true,
    ])->assertSessionHasNoErrors();
    $user = User::query()->where('email', 'gerente1@example.test')->firstOrFail();
    $period = rpPeriod();

    // Permitido: pasa el permiso (puede caer en validación, nunca en 403).
    expect($this->actingAs($user)->post(route('periodos.store'), [])->status())->not->toBe(403);
    $this->actingAs($user)->get(route('periodos.index'))->assertOk();

    // Prohibido por URL directa.
    $this->actingAs($user)->deleteJson(route('periodos.destroy', $period))->assertForbidden()
        ->assertJson(['message' => 'Tu rol no permite «Eliminar periodos» en Periodos. Pide a un administrador que lo habilite en Roles y permisos.']);
    $this->actingAs($user)->postJson(route('periodos.close', $period))->assertForbidden();
    $this->actingAs($user)->getJson(route('reportes-mensuales.export-radiography', $period))->assertForbidden();
    $this->actingAs($user)->postJson(route('historico-general.generate-radiography', $period))->assertForbidden();
    $this->actingAs($user)->getJson(route('efectividad-cobranza.export', ['token' => 'abc', 'formato' => 'pdf']))->assertForbidden();
    // Módulos no elegidos.
    $this->actingAs($user)->get(route('asignaciones-empleado-sucursal.index'))->assertForbidden();
    $this->actingAs($user)->get(route('usuarios.index'))->assertForbidden();
    $this->actingAs($user)->get(route('roles.index'))->assertForbidden();
    $this->actingAs($user)->postJson(route('usuarios.store'), ['name' => 'X', 'email' => 'x@example.test'])->assertForbidden();
    expect(User::query()->where('email', 'x@example.test')->exists())->toBeFalse();
});

it('changing the permissions leaves no residual privileges', function () {
    $admin = rpAdmin();
    rpCreateRole($this, $admin, ['name' => 'Supervisor', 'modules' => ['periodos', 'reportes'], 'actions' => ['periodos' => ['crear', 'eliminar', 'cerrar'], 'reportes' => ['exportar']]])
        ->assertSessionHasNoErrors();
    $user = rpUserWithRole('supervisor');
    $period = rpPeriod();

    expect(app(ActionPermissionService::class)->canAction($user, 'reportes', 'exportar'))->toBeTrue();
    expect(app(ActionPermissionService::class)->canAction($user, 'periodos', 'eliminar'))->toBeTrue();

    // Se quita el módulo Periodos completo → sus acciones desaparecen de la BD.
    $this->actingAs($admin)->put(route('roles.update', 'supervisor'), ['name' => 'Supervisor', 'base_level' => 'gerencial', 'modules' => ['reportes'], 'actions' => ['reportes' => ['exportar'], 'periodos' => ['crear', 'eliminar']]])
        ->assertSessionHasNoErrors();
    expect(DB::table('role_action_permissions')->where('role', 'supervisor')->where('module', 'periodos')->exists())->toBeFalse();
    $this->actingAs($user)->post(route('periodos.store'), [])->assertForbidden();

    // Se vuelve a dar el módulo pero sin acciones → "crear" NO regresa solo.
    $this->actingAs($admin)->put(route('roles.update', 'supervisor'), ['name' => 'Supervisor', 'base_level' => 'gerencial', 'modules' => ['reportes', 'periodos'], 'actions' => ['reportes' => []]])
        ->assertSessionHasNoErrors();
    $this->actingAs($user)->get(route('periodos.index'))->assertOk();
    $this->actingAs($user)->postJson(route('periodos.store'), [])->assertForbidden();
    $this->actingAs($user)->deleteJson(route('periodos.destroy', $period))->assertForbidden();
    $this->actingAs($user)->getJson(route('reportes-mensuales.export-radiography', $period))->assertForbidden();
});

it('applies the OKR permissions independently by direct URL', function () {
    $admin = rpAdmin();
    rpCreateRole($this, $admin, ['name' => 'Consulta OKR', 'modules' => ['okr'], 'actions' => ['okr' => []]])->assertSessionHasNoErrors();
    $viewer = rpUserWithRole('consulta_okr');

    $this->actingAs($viewer)->get(route('okr.dashboard'))->assertOk();
    $this->actingAs($viewer)->postJson(route('okr.store'), [])->assertForbidden()
        ->assertJson(['message' => 'Tu rol no permite «Crear OKR» en OKR. Pide a un administrador que lo habilite en Roles y permisos.']);
    $this->actingAs($viewer)->getJson(route('okr.baseline-preview'))->assertForbidden();

    $this->actingAs($admin)->put(route('roles.update', 'consulta_okr'), ['name' => 'Consulta OKR', 'base_level' => 'gerencial', 'modules' => ['okr'], 'actions' => ['okr' => ['crear']]])
        ->assertSessionHasNoErrors();
    expect($this->actingAs($viewer)->postJson(route('okr.store'), [])->status())->toBe(422); // pasa el permiso; falla la validación
});

it('every action shown in the matrix is enforced by a route middleware (or the OKR policy)', function () {
    $enforced = [];
    foreach (Route::getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $mw) {
            if (is_string($mw) && str_starts_with($mw, 'permission:')) {
                [$module, $actions] = explode(',', substr($mw, strlen('permission:')));
                foreach (explode('|', $actions) as $action) {
                    $enforced["{$module}.{$action}"] = true;
                }
            }
        }
    }
    $enforced['okr.administrar_otros'] = true; // OkrObjectivePolicy::managesOthers()

    $missing = collect(app(ActionPermissionService::class)->catalog())
        ->map(fn ($a) => "{$a['module']}.{$a['action']}")
        ->reject(fn ($key) => isset($enforced[$key]))
        ->values()->all();

    expect($missing)->toBe([]);
});

it('keeps what existing roles could already do (defaults seeded for the new actions)', function () {
    $permissions = app(ActionPermissionService::class);
    $gerente = rpUserWithRole('gerencial');
    $colaborador = rpUserWithRole('colaborador');

    expect($permissions->canAction($gerente, 'carga', 'subir'))->toBeTrue()
        ->and($permissions->canAction($gerente, 'okr', 'activar'))->toBeTrue()
        ->and($permissions->canAction($gerente, 'okr', 'autorizar_warning'))->toBeTrue()
        ->and($permissions->canAction($gerente, 'periodos', 'cerrar'))->toBeTrue()
        ->and($permissions->canAction($gerente, 'okr', 'administrar_otros'))->toBeFalse()
        ->and($permissions->canAction($gerente, 'usuarios', 'crear'))->toBeFalse()
        ->and($permissions->canAction($colaborador, 'okr', 'registrar_seguimiento'))->toBeTrue()
        ->and($permissions->canAction($colaborador, 'okr', 'cargar_evidencia'))->toBeTrue()
        ->and($permissions->canAction($colaborador, 'okr', 'emitir_carta'))->toBeFalse()
        ->and($permissions->canAction($colaborador, 'okr', 'activar'))->toBeFalse();

    $admin = rpAdmin();
    foreach ($permissions->catalog() as $a) {
        expect($permissions->canAction($admin, $a['module'], $a['action']))->toBeTrue();
    }
});

it('protects the Administrator role: it is never configurable or deletable', function () {
    $admin = rpAdmin();

    $this->actingAs($admin)->put(route('roles.update', 'admin'), ['name' => 'Otro', 'modules' => []])->assertStatus(422);
    $this->actingAs($admin)->delete(route('roles.destroy', 'admin'))->assertStatus(422);
    $this->actingAs($admin)->delete(route('roles.destroy', 'gerencial'))->assertSessionHas('error', 'Los roles del sistema no se pueden eliminar.');
    expect(DB::table('roles')->where('key', 'admin')->exists())->toBeTrue();
});

it('a delegated role manager can never grant more than their own role has', function () {
    $admin = rpAdmin();
    rpCreateRole($this, $admin, [
        'name' => 'Jefe de personal', 'modules' => ['usuarios', 'roles', 'reportes'],
        'actions' => ['usuarios' => ['crear', 'asignar_rol', 'gestionar_acceso'], 'roles' => ['crear', 'editar'], 'reportes' => ['exportar']],
    ])->assertSessionHasNoErrors();
    $jefe = rpUserWithRole('jefe_de_personal');
    Mail::fake();

    // No puede otorgar un módulo (Periodos) ni una acción (eliminar roles) que no tiene.
    rpCreateRole($this, $jefe, ['name' => 'Rol inflado', 'modules' => ['periodos'], 'actions' => ['periodos' => ['eliminar']]])->assertSessionHasErrors('modules');
    expect(DB::table('roles')->where('name', 'Rol inflado')->exists())->toBeFalse();

    // Sí puede crear un rol contenido en el suyo y dar de alta usuarios con él.
    rpCreateRole($this, $jefe, ['name' => 'Solo reportes', 'modules' => ['reportes'], 'actions' => ['reportes' => ['exportar']]])->assertSessionHasNoErrors();
    $this->actingAs($jefe)->post(route('usuarios.store'), ['name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'solo_reportes', 'password_mode' => 'auto'])
        ->assertSessionHasNoErrors();

    // Nunca un rol con más permisos (gerencial), nunca Administrador, nunca su propio rol.
    $this->actingAs($jefe)->post(route('usuarios.store'), ['name' => 'Beto', 'email' => 'beto@example.test', 'role' => 'gerencial', 'password_mode' => 'auto'])->assertForbidden();
    $ana = User::query()->where('email', 'ana@example.test')->firstOrFail();
    $this->actingAs($jefe)->put(route('usuarios.update-role', $ana), ['role' => 'admin'])->assertForbidden();
    $this->actingAs($jefe)->put(route('roles.update', 'jefe_de_personal'), ['name' => 'Jefe de personal', 'modules' => ['usuarios', 'roles', 'reportes', 'periodos']])->assertForbidden();

    // Nunca toca a un Administrador.
    $this->actingAs($jefe)->post(route('usuarios.toggle-disabled', $admin))->assertForbidden();
    expect($admin->fresh()->disabled_at)->toBeNull()
        ->and(User::query()->where('email', 'beto@example.test')->exists())->toBeFalse()
        ->and($ana->fresh()->role)->toBe('solo_reportes');
});
