<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * Prioridad 11: un error técnico real devuelve un mensaje humano con referencia;
 * el detalle (SQL, rutas, trazas) solo queda en el log bajo esa referencia.
 */
beforeEach(function () {
    config(['app.debug' => false]);
    Route::middleware('web')->get('/_prueba/error-tecnico', fn () => throw new RuntimeException('SQLSTATE[42S02] tabla secreta C:\\wamp64\\www'));
    Route::middleware('web')->post('/_prueba/error-tecnico', fn () => throw new RuntimeException('fallo al guardar'));
    Route::middleware('web')->get('/_prueba/validacion', fn () => throw Illuminate\Validation\ValidationException::withMessages(['campo' => 'Falta capturar la línea base de David.']));
});

it('responde JSON con referencia sin exponer SQL ni rutas y registra la misma referencia en el log', function () {
    $logged = null;
    Log::listen(function ($e) use (&$logged) {
        $logged = $e->context['error_ref'] ?? $logged;
    });

    $res = $this->actingAs(User::factory()->create())->getJson('/_prueba/error-tecnico')->assertStatus(500);
    $ref = $res->json('reference');

    expect($ref)->toMatch('/^ERR-[A-Z0-9]{8}$/')
        ->and($res->json('message'))->toContain("proporciona esta referencia a Sistemas: {$ref}")
        ->and($res->getContent())->not->toContain('SQLSTATE')->not->toContain('wamp64')->not->toContain('trace')
        ->and($res->headers->get('X-Error-Reference'))->toBe($ref)
        ->and($logged)->toBe($ref);
});

it('las peticiones Inertia también reciben el mensaje con referencia', function () {
    $this->actingAs(User::factory()->create())->post('/_prueba/error-tecnico', [], ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertStatus(500)->assertJsonStructure(['message', 'reference']);
});

it('los errores con causa conocida (validación) conservan su mensaje específico', function () {
    $this->actingAs(User::factory()->create())->getJson('/_prueba/validacion')
        ->assertStatus(422)->assertJsonPath('errors.campo.0', 'Falta capturar la línea base de David.');
});
