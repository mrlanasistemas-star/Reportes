<?php

use App\Models\User;
use App\Services\Access\ActionPermissionService;
use App\Services\Access\ModuleAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * Roles personalizados (16-oct-2026): el admin crea un rol, define módulos y
 * acciones, crea usuarios con ese rol y el acceso real lo respeta.
 */
function crAdmin(): User
{
    return User::factory()->create(['role' => 'admin', 'access_enabled_at' => now()]);
}

it('seeds the three system roles', function () {
    expect(DB::table('roles')->orderBy('id')->pluck('key')->all())->toBe(['admin', 'gerencial', 'colaborador']);
});

it('creates a custom role with its modules and actions, and a user created with it only sees those modules', function () {
    $admin = crAdmin();

    $this->actingAs($admin)->post(route('okr.responsibles.roles.store'), [
        'name' => 'Supervisor de zona', 'description' => 'Revisa efectividad y reportes',
        'base_level' => 'gerencial', 'modules' => ['efectividad', 'reportes'], 'actions' => ['reportes' => ['exportar']],
    ])->assertSessionHasNoErrors();

    $role = DB::table('roles')->where('name', 'Supervisor de zona')->first();
    expect($role->key)->toBe('supervisor_de_zona')->and((bool) $role->is_system)->toBeFalse();

    Mail::fake();
    $this->actingAs($admin)->post(route('okr.responsibles.store'), [
        'name' => 'Ana Supervisora', 'email' => 'ana@example.test', 'role' => 'supervisor_de_zona', 'password_mode' => 'auto',
    ])->assertSessionHasNoErrors();

    $ana = User::query()->where('email', 'ana@example.test')->firstOrFail();
    expect($ana->role)->toBe('supervisor_de_zona')
        ->and($ana->isGerencial())->toBeTrue() // nivel base
        ->and(app(ModuleAccessService::class)->modulesFor($ana))->toBe(['reportes', 'efectividad'])
        ->and(app(ActionPermissionService::class)->canAction($ana, 'reportes', 'exportar'))->toBeTrue()
        ->and(app(ActionPermissionService::class)->canAction($ana, 'carga', 'eliminar'))->toBeFalse();

    $ana->forceFill(['access_enabled_at' => now()])->save();
    $this->actingAs($ana)->get(route('efectividad-cobranza.index'))->assertOk();
    $this->actingAs($ana)->get(route('periodos.index'))->assertForbidden();

    // Guardar el rol nuevo no tocó los permisos de los roles del sistema.
    expect(app(ModuleAccessService::class)->matrix()['gerencial'])->toContain('dashboard');
});

it('never creates an Administrator from the user form, and rejects unknown roles', function () {
    $admin = crAdmin();
    Mail::fake();

    $this->actingAs($admin)->post(route('okr.responsibles.store'), ['name' => 'Intruso', 'email' => 'x@example.test', 'role' => 'admin'])
        ->assertSessionHasErrors('role');
    $this->actingAs($admin)->post(route('okr.responsibles.store'), ['name' => 'Intruso', 'email' => 'y@example.test', 'role' => 'inventado'])
        ->assertSessionHasErrors('role');
    expect(User::query()->whereIn('email', ['x@example.test', 'y@example.test'])->exists())->toBeFalse();
});

it('updates and deletes a custom role, but never a system role or one still in use', function () {
    $admin = crAdmin();
    $this->actingAs($admin)->post(route('okr.responsibles.roles.store'), ['name' => 'Auditor', 'base_level' => 'colaborador', 'modules' => ['reportes']]);

    $this->actingAs($admin)->put(route('okr.responsibles.roles.update', 'auditor'), ['name' => 'Auditor interno', 'base_level' => 'colaborador', 'modules' => ['reportes', 'dashboard']])
        ->assertSessionHasNoErrors();
    expect(DB::table('roles')->where('key', 'auditor')->value('name'))->toBe('Auditor interno')
        ->and(app(ModuleAccessService::class)->matrix()['auditor'])->toBe(['dashboard', 'reportes']);

    $user = User::factory()->create(['role' => 'auditor']);
    $this->actingAs($admin)->delete(route('okr.responsibles.roles.destroy', 'auditor'))->assertSessionHas('error');
    expect(DB::table('roles')->where('key', 'auditor')->exists())->toBeTrue();

    $user->update(['role' => 'colaborador']);
    $this->actingAs($admin)->delete(route('okr.responsibles.roles.destroy', 'auditor'))->assertSessionHas('success');
    expect(DB::table('roles')->where('key', 'auditor')->exists())->toBeFalse()
        ->and(DB::table('role_module_access')->where('role', 'auditor')->exists())->toBeFalse();

    $this->actingAs($admin)->delete(route('okr.responsibles.roles.destroy', 'gerencial'))->assertSessionHas('error');
    $this->actingAs($admin)->put(route('okr.responsibles.roles.update', 'admin'), ['name' => 'Otro'])->assertStatus(422);
});

it('rejects a duplicated role name and lets non-admins do nothing', function () {
    $admin = crAdmin();
    $this->actingAs($admin)->post(route('okr.responsibles.roles.store'), ['name' => 'gerencial', 'base_level' => 'gerencial'])->assertSessionHasErrors('name');

    $gerente = User::factory()->create(['role' => 'gerencial', 'access_enabled_at' => now()]);
    $this->actingAs($gerente)->post(route('okr.responsibles.roles.store'), ['name' => 'Rol pirata', 'base_level' => 'gerencial'])->assertForbidden();
});

it('resends the welcome email as JSON for the UI', function () {
    $admin = crAdmin();
    $user = User::factory()->create(['role' => 'colaborador']);
    Mail::fake();

    $this->actingAs($admin)->postJson(route('okr.responsibles.resend-welcome-email', $user))
        ->assertOk()->assertJson(['sent' => true]);
    expect($user->fresh()->welcome_email_sent_at)->not->toBeNull();
});

it('shows the tour welcome only once per user', function () {
    $user = User::factory()->create(['role' => 'admin', 'access_enabled_at' => now()]);
    expect($user->tour_welcome_seen_at)->toBeNull();

    $this->actingAs($user)->postJson(route('guia-sistema.welcome-seen'))->assertOk();
    $first = $user->fresh()->tour_welcome_seen_at;
    expect($first)->not->toBeNull();

    $this->actingAs($user)->postJson(route('guia-sistema.welcome-seen'))->assertOk();
    expect($user->fresh()->tour_welcome_seen_at->equalTo($first))->toBeTrue();
});
