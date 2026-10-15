<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Cada enlace del menú lateral (resources/js/config/mainNav.ts) debe resolver a una
 * ruta GET registrada. Evita volver a publicar un menú que apunta a páginas que el
 * backend no conoce (404 al entrar a Usuarios / Roles y permisos).
 */
it('cada enlace del sidebar corresponde a una ruta GET registrada', function () {
    $nav = file_get_contents(resource_path('js/config/mainNav.ts'));
    preg_match_all("/href:\s*'([^']+)'/", $nav, $m);
    $paths = array_values(array_unique($m[1]));

    expect($paths)->toContain('/usuarios')->toContain('/roles');

    $faltantes = [];
    foreach ($paths as $path) {
        try {
            Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (Throwable) {
            $faltantes[] = $path;
        }
    }

    expect($faltantes)->toBe([]);
});

it('las páginas Inertia de Usuarios y Roles existen en el código fuente', function () {
    expect(is_file(resource_path('js/pages/usuarios/Index.vue')))->toBeTrue()
        ->and(is_file(resource_path('js/pages/roles/Index.vue')))->toBeTrue();
});
