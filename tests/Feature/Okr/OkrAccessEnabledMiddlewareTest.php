<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * D5/D6/D7 del cierre, 17-sep-2026 — `users.access_enabled_at` existía SOLO
 * como etiqueta visual en "Responsables OKR" (Activo/Pendiente), sin ningún
 * control de acceso real: el Gate 'okr.view' solo exigía `$user !== null`, así
 * que un responsable recién creado y AÚN pendiente podía entrar y operar todo
 * el módulo igual que uno habilitado.
 */
it('blocks a pending (non-admin, access_enabled_at=null) user from the OKR dashboard with 403', function () {
    $pending = User::factory()->create(['role' => 'colaborador', 'access_enabled_at' => null]);

    $this->actingAs($pending)->get(route('okr.dashboard'))->assertForbidden();
});

it('allows an enabled (non-admin, access_enabled_at set) user into the OKR dashboard', function () {
    $enabled = User::factory()->create(['role' => 'colaborador', 'access_enabled_at' => now()]);

    $this->actingAs($enabled)->get(route('okr.dashboard'))->assertOk();
});

it('always lets an admin into OKR regardless of access_enabled_at', function () {
    $admin = User::factory()->create(['role' => 'admin', 'access_enabled_at' => null]);

    $this->actingAs($admin)->get(route('okr.dashboard'))->assertOk();
});

it('a pending user is blocked from every OKR route, not just the dashboard', function () {
    $pending = User::factory()->create(['role' => 'colaborador', 'access_enabled_at' => null]);

    $this->actingAs($pending)->get(route('okr.employees.lookup'))->assertForbidden();
    $this->actingAs($pending)->get(route('okr.history'))->assertForbidden();
});

it('rejects assigning a pending user as the responsible_user_id of a new Objective (D6, backend-enforced)', function () {
    $admin = User::factory()->create();
    $pendingResponsible = User::factory()->create(['role' => 'colaborador', 'access_enabled_at' => null]);
    $branch = okrBranch('Huamantla');
    $kpi = okrManualKpi('d6_test_kpi');

    $response = $this->actingAs($admin)->post(route('okr.store'), [
        'scope_type' => 'branch', 'branch_id' => $branch->id,
        'title' => 'Objective de prueba con responsable pendiente',
        'responsible_user_id' => $pendingResponsible->id,
        'start_date' => now()->toDateString(), 'duration_weeks' => 8,
        'key_results' => [
            ['kpi_id' => $kpi->id, 'description' => 'KR de prueba', 'target_value' => 200, 'weight' => 100],
        ],
    ]);

    $response->assertSessionHasErrors('responsible_user_id');
});

it('accepts an enabled user as responsible_user_id', function () {
    $admin = User::factory()->create();
    $enabledResponsible = User::factory()->create(['role' => 'colaborador', 'access_enabled_at' => now()]);
    $branch = okrBranch('Ixtlahuaca');
    $kpi = okrManualKpi('d6_test_kpi_ok');

    $response = $this->actingAs($admin)->post(route('okr.store'), [
        'scope_type' => 'branch', 'branch_id' => $branch->id,
        'title' => 'Objective de prueba con responsable habilitado',
        'responsible_user_id' => $enabledResponsible->id,
        'start_date' => now()->toDateString(), 'duration_weeks' => 8,
        'key_results' => [
            ['kpi_id' => $kpi->id, 'description' => 'KR de prueba', 'target_value' => 200, 'weight' => 100],
        ],
    ]);

    $response->assertSessionHasNoErrors();
});

it('the "Responsables OKR" screen can NEVER create an admin, even if role=admin is sent in the request (D7)', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)->post('/okr/responsibles', [
        'name' => 'Intento De Elevación', 'email' => 'intento.elevacion@example.com', 'role' => 'admin',
    ])->assertSessionHasNoErrors();

    $created = User::query()->where('email', 'intento.elevacion@example.com')->firstOrFail();
    expect($created->role)->toBe('colaborador');
});
