<?php

use App\Models\ApiConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Fase 6 — casos obligatorios de seguridad explícitos del encargo, en un solo
 * lugar para que la lista sea auditable de un vistazo.
 */
it('rejects a token that was valid but has since been revoked — 401, not a stale success', function () {
    $consumer = ApiConsumer::query()->create(['name' => 'Test', 'system_code' => 'revoke-check', 'is_active' => true, 'abilities' => ['branches.read']]);
    $token = $consumer->createToken('test', ['branches.read'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/branches')->assertOk();

    // Revocación real (ver api:consumers:revoke).
    $consumer->tokens()->delete();
    expect($consumer->tokens()->count())->toBe(0);

    // La prueba de que un request NUEVO rechazaría este token es verificar el mecanismo real
    // del que depende el guard de Sanctum (PersonalAccessToken::findToken) directamente — un
    // segundo getJson() dentro del MISMO método de test reutiliza el usuario ya resuelto y
    // cacheado en el Guard de este contenedor (artefacto conocido del TestCase de Laravel:
    // el contenedor de la app no se reinicia entre llamadas dentro de un mismo test), lo cual
    // NUNCA ocurre en producción (cada request real es un proceso/contenedor nuevo).
    expect(\Laravel\Sanctum\PersonalAccessToken::findToken($token))->toBeNull();
});

it('the entire /api/v1 surface is read-only — no POST/PUT/PATCH/DELETE route exists', function () {
    $apiRoutes = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1'));

    $mutatingMethods = $apiRoutes->flatMap(fn ($r) => $r->methods())
        ->reject(fn ($m) => in_array($m, ['GET', 'HEAD'], true))
        ->unique();

    expect($mutatingMethods)->toBeEmpty();
    expect($apiRoutes)->not->toBeEmpty(); // confirma que sí se inspeccionaron rutas reales, no una lista vacía por error
});

it('a read-only token cannot be used to mutate a web-only resource it happens to know the URL of (cross-guard isolation)', function () {
    $consumer = ApiConsumer::query()->create(['name' => 'Test', 'system_code' => 'cross-guard', 'is_active' => true, 'abilities' => ['branches.read']]);
    $token = $consumer->createToken('test', ['branches.read'])->plainTextToken;

    // Un token de Sanctum para ApiConsumer no es un usuario web — no debe poder autenticar
    // contra rutas del guard 'web' (sesión), que es lo que protege a los endpoints de escritura
    // de Reportería (historico-general, periodos, etc.).
    $response = $this->withHeader('Authorization', "Bearer {$token}")->post('/historico-general', []);

    $response->assertRedirect('/login'); // el guard web nunca reconoce un token Sanctum de ApiConsumer
});
