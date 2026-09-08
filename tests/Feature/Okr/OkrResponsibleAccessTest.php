<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Test punto 30 L — bug corregido 09-sep-2026: email_verified_at ya no se finge al crear un responsable. */
it('creating a responsible never marks their email as verified — that requires an explicit admin action', function () {
    $admin = User::factory()->create();

    $response = $this->actingAs($admin)->post('/okr/responsibles', [
        'name' => 'Nuevo Responsable', 'email' => 'nuevo.responsable@example.com', 'role' => 'colaborador',
    ]);

    $response->assertSessionHasNoErrors();
    $user = User::query()->where('email', 'nuevo.responsable@example.com')->firstOrFail();
    expect($user->email_verified_at)->toBeNull();
});

it('a "pending" responsible only becomes active after the admin explicitly enables access', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin)->post('/okr/responsibles', ['name' => 'Ana Pendiente', 'email' => 'ana.pendiente@example.com', 'role' => 'colaborador']);
    $user = User::query()->where('email', 'ana.pendiente@example.com')->firstOrFail();
    expect($user->email_verified_at)->toBeNull();

    $this->actingAs($admin)->post("/okr/responsibles/{$user->id}/enable-access")->assertSessionHasNoErrors();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('a non-admin cannot enable access for a responsible', function () {
    $admin = User::factory()->create();
    $colaborador = User::factory()->create(['role' => 'colaborador']);
    $this->actingAs($admin)->post('/okr/responsibles', ['name' => 'Bloqueado', 'email' => 'bloqueado@example.com', 'role' => 'colaborador']);
    $user = User::query()->where('email', 'bloqueado@example.com')->firstOrFail();

    $this->actingAs($colaborador)->post("/okr/responsibles/{$user->id}/enable-access")->assertForbidden();
    expect($user->fresh()->email_verified_at)->toBeNull();
});
