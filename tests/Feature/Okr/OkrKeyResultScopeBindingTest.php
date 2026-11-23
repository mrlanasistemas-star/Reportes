<?php

/**
 * Un KR solo se puede editar o borrar desde la URL de SU objetivo: las rutas usan
 * scopeBindings, así que un KR de otro OKR responde 404 en lugar de modificarse.
 */
it('enforces scoped bindings on key result update and destroy routes', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes());

    foreach (['key-results.update', 'key-results.destroy'] as $suffix) {
        $route = $routes->first(fn ($r) => str_ends_with((string) $r->getName(), $suffix));

        expect($route)->not->toBeNull()
            ->and($route->enforcesScopedBindings())->toBeTrue();
    }
});
