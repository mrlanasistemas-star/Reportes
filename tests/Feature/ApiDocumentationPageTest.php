<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Documentación de la API (14-oct-2026): privada por enlace directo — solo
 * admin, invitado a login, nunca indexable.
 */
it('admin can open the API documentation page', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->get('/documentacion-api')
        ->assertOk()
        ->assertSee('API de Reportería MR Lana')
        ->assertSee('/api/v1', false)
        ->assertSee('noindex', false);
});

it('non-admin roles are forbidden from the API documentation page', function (string $role) {
    $user = User::factory()->create(['role' => $role, 'access_enabled_at' => now()]);

    $this->actingAs($user)->get('/documentacion-api')->assertForbidden();
})->with(['gerencial', 'colaborador']);

it('a guest is redirected to login', function () {
    $this->get('/documentacion-api')->assertRedirect(route('login'));
});

it('robots.txt disallows the API documentation page', function () {
    $this->get('/robots.txt')->assertSee('Disallow: /documentacion-api');
});
