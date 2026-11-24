<?php

use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Pantalla de mantenimiento: con `php artisan down` la web muestra la vista
 * errors.503 y una visita Inertia recarga la página completa (409 + X-Inertia-Location).
 */
beforeEach(function () {
    Route::get('/__mantenimiento_prueba', fn () => throw new HttpException(503));
});

it('muestra la pantalla de mantenimiento en la web', function () {
    $this->get('/__mantenimiento_prueba')
        ->assertStatus(503)
        ->assertSee('Estamos mejorando el sistema')
        ->assertHeader('Retry-After', '60');
});

it('hace que Inertia recargue la página completa', function () {
    $this->get('/__mantenimiento_prueba', ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');
});

it('conserva el 503 en JSON para la API', function () {
    $this->getJson('/__mantenimiento_prueba')->assertStatus(503)->assertJsonStructure(['message']);
});
