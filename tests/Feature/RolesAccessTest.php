<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Parte 1 del cierre (04-oct-2026): tres roles — ADMIN (acceso total),
 * GERENCIAL (Reportería + OKR operativo, sin usuarios/catálogo KPI) y
 * COLABORADOR (solo seguimiento propio de OKR, NUNCA Reportería financiero
 * global). Autorización BACKEND real (EnsureReporteriaAccess), no solo UI.
 */
it('admin can access Reportería (financiero global)', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->get(route('dashboard'))->assertOk();
    $this->actingAs($admin)->get(route('periodos.index'))->assertOk();
});

it('gerencial can access Reportería (financiero global), same as admin', function () {
    $gerencial = User::factory()->create(['role' => 'gerencial']);

    $this->actingAs($gerencial)->get(route('dashboard'))->assertOk();
    $this->actingAs($gerencial)->get(route('periodos.index'))->assertOk();
});

it('colaborador is forbidden from Reportería (financiero global) — backend enforced, not just hidden menu', function () {
    $colaborador = User::factory()->create(['role' => 'colaborador']);

    $this->actingAs($colaborador)->get(route('dashboard'))->assertForbidden();
    $this->actingAs($colaborador)->get(route('periodos.index'))->assertForbidden();
    $this->actingAs($colaborador)->get(route('historico-general.index'))->assertForbidden();
    $this->actingAs($colaborador)->get(route('asignaciones-empleado-sucursal.index'))->assertForbidden();
    $this->actingAs($colaborador)->get(route('validaciones.index'))->assertForbidden();
    $this->actingAs($colaborador)->get(route('reportes-mensuales.index'))->assertForbidden();
});

it('colaborador can still reach OKR and the system guide — only Reportería financiero global is blocked', function () {
    $colaborador = User::factory()->create(['role' => 'colaborador', 'access_enabled_at' => now()]);

    $this->actingAs($colaborador)->get(route('okr.dashboard'))->assertOk();
    $this->actingAs($colaborador)->get(route('guia-sistema.index'))->assertOk();
});

it('a guest is redirected to login, never reaching the 403 check', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('after login, a colaborador lands on /okr, never on /dashboard where they would be 403d', function () {
    $colaborador = User::factory()->create(['role' => 'colaborador', 'password' => bcrypt('password-test-123')]);

    $response = $this->post('/login', ['email' => $colaborador->email, 'password' => 'password-test-123']);

    $response->assertRedirect(route('okr.dashboard'));
});

it('after login, an admin/gerencial lands on the usual Reportería home', function () {
    $admin = User::factory()->create(['role' => 'admin', 'password' => bcrypt('password-test-123')]);

    $response = $this->post('/login', ['email' => $admin->email, 'password' => 'password-test-123']);

    $response->assertRedirect('/dashboard');
});
