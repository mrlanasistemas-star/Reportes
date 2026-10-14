<?php

use App\Models\User;
use App\Services\Access\ModuleAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Módulos por rol (14-oct-2026) — configurables desde "Usuarios y accesos".
 * Caso real: gerentes que SOLO deben entrar a Efectividad de Cobranza.
 */
function soloEfectividadParaGerentes(): void
{
    app(ModuleAccessService::class)->save(['gerencial' => ['efectividad'], 'colaborador' => ['okr']]);
}

it('seeds the previous behaviour: gerencial sees everything, colaborador only OKR', function () {
    expect(app(ModuleAccessService::class)->matrix())->toBe([
        'gerencial' => ['dashboard', 'carga', 'periodos', 'colaboradores', 'reportes', 'efectividad', 'okr'],
        'colaborador' => ['okr'],
    ]);
});

it('a gerente limited to Efectividad can open it but is forbidden from the other modules', function () {
    soloEfectividadParaGerentes();
    $gerente = User::factory()->create(['role' => 'gerencial', 'access_enabled_at' => now()]);

    $this->actingAs($gerente)->get(route('efectividad-cobranza.index'))->assertOk();
    $this->actingAs($gerente)->get(route('periodos.index'))->assertForbidden();
    $this->actingAs($gerente)->get(route('historico-general.index'))->assertForbidden();
    $this->actingAs($gerente)->get(route('reportes-mensuales.index'))->assertForbidden();
    $this->actingAs($gerente)->get(route('validaciones.index'))->assertForbidden();
    $this->actingAs($gerente)->get(route('asignaciones-empleado-sucursal.index'))->assertForbidden();
    $this->actingAs($gerente)->get(route('okr.dashboard'))->assertForbidden();
});

it('sends a gerente without Dashboard to their first allowed module instead of a 403', function () {
    soloEfectividadParaGerentes();
    $gerente = User::factory()->create(['role' => 'gerencial']);

    $this->actingAs($gerente)->get(route('dashboard'))->assertRedirect('/efectividad-cobranza');
});

it('after login, a gerente limited to Efectividad lands on it', function () {
    soloEfectividadParaGerentes();
    $gerente = User::factory()->create(['role' => 'gerencial', 'password' => bcrypt('password-test-123')]);

    $this->post('/login', ['email' => $gerente->email, 'password' => 'password-test-123'])
        ->assertRedirect('/efectividad-cobranza');
});

it('admin always keeps every module regardless of the configuration', function () {
    app(ModuleAccessService::class)->save(['gerencial' => [], 'colaborador' => []]);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->get(route('dashboard'))->assertOk();
    $this->actingAs($admin)->get(route('efectividad-cobranza.index'))->assertOk();
    $this->actingAs($admin)->get(route('okr.responsibles.index'))->assertOk();
});

it('shares only the allowed modules to the frontend menu', function () {
    soloEfectividadParaGerentes();
    $gerente = User::factory()->create(['role' => 'gerencial']);

    $this->actingAs($gerente)->get(route('efectividad-cobranza.index'))
        ->assertInertia(fn ($page) => $page->where('auth.modules', ['efectividad']));
});

it('admin can save the modules per role from Usuarios y accesos', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->put(route('okr.responsibles.module-access'), ['access' => ['gerencial' => ['efectividad'], 'colaborador' => []]])
        ->assertRedirect();

    expect(app(ModuleAccessService::class)->matrix())->toBe(['gerencial' => ['efectividad'], 'colaborador' => []]);
});

it('rejects unknown modules and non-admin editors', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $gerente = User::factory()->create(['role' => 'gerencial', 'access_enabled_at' => now()]);

    $this->actingAs($admin)
        ->put(route('okr.responsibles.module-access'), ['access' => ['gerencial' => ['inventado'], 'colaborador' => []]])
        ->assertSessionHasErrors('access.gerencial.0');

    $this->actingAs($gerente)
        ->put(route('okr.responsibles.module-access'), ['access' => ['gerencial' => ['dashboard'], 'colaborador' => []]])
        ->assertForbidden();
});
