<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Test punto 30 L / 39 H — email_verified_at nunca se finge, y ya no se reutiliza como "acceso habilitado". */
it('creating a responsible never marks their email as verified — that requires an explicit admin action', function () {
    $admin = User::factory()->create();

    $response = $this->actingAs($admin)->post('/okr/responsibles', [
        'name' => 'Nuevo Responsable', 'email' => 'nuevo.responsable@example.com', 'role' => 'colaborador',
    ]);

    $response->assertSessionHasNoErrors();
    $user = User::query()->where('email', 'nuevo.responsable@example.com')->firstOrFail();
    expect($user->email_verified_at)->toBeNull();
    expect($user->access_enabled_at)->toBeNull();
});

it('a "pending" responsible only becomes active after the admin explicitly enables access, via access_enabled_at — never email_verified_at', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin)->post('/okr/responsibles', ['name' => 'Ana Pendiente', 'email' => 'ana.pendiente@example.com', 'role' => 'colaborador']);
    $user = User::query()->where('email', 'ana.pendiente@example.com')->firstOrFail();
    expect($user->access_enabled_at)->toBeNull();

    $this->actingAs($admin)->post("/okr/responsibles/{$user->id}/enable-access")->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->access_enabled_at)->not->toBeNull();
    // Bug corregido punto 32 — habilitar acceso NUNCA debe fingir una
    // verificación de correo real que no ocurrió.
    expect($user->email_verified_at)->toBeNull();
});

it('a non-admin cannot enable access for a responsible', function () {
    $admin = User::factory()->create();
    $colaborador = User::factory()->create(['role' => 'colaborador']);
    $this->actingAs($admin)->post('/okr/responsibles', ['name' => 'Bloqueado', 'email' => 'bloqueado@example.com', 'role' => 'colaborador']);
    $user = User::query()->where('email', 'bloqueado@example.com')->firstOrFail();

    $this->actingAs($colaborador)->post("/okr/responsibles/{$user->id}/enable-access")->assertForbidden();
    expect($user->fresh()->access_enabled_at)->toBeNull();
});
