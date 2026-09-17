<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | These options configures if and how Inertia uses Server Side Rendering
    | to pre-render each initial request made to your application's pages
    | so that server rendered HTML is delivered for the user's browser.
    |
    | See: https://inertiajs.com/server-side-rendering
    |
    */

    // Parte C1 del cierre (17-sep-2026) — auditoría explícita pedida por el usuario
    // ("NO ASUMAS SSR"): este proyecto NO tiene SSR real. No existe
    // resources/js/ssr.ts, nunca se corre `npm run build:ssr` en el flujo normal, y
    // no hay bundle en bootstrap/ssr/. Con `enabled: true` pero sin bundle, Laravel
    // ya hacía un no-op seguro en producción (HttpGateway::dispatch() detecta que
    // el bundle no existe y devuelve null ANTES de cualquier request HTTP — nunca
    // fue la causa de "página pegada"), pero en `npm run dev` (Vite hot) SÍ
    // intentaba una request HTTP real a `/__inertia_ssr` en cada carga de página,
    // sin nada escuchando ahí — un riesgo de lentitud/timeout innecesario en
    // desarrollo, además de una bandera de config engañosa (dice "SSR activo"
    // cuando nunca lo estuvo). Se desactiva explícitamente — no se instala SSR sin
    // haber confirmado que hace falta (instrucción explícita del cierre).
    'ssr' => [
        'enabled' => false,
        'url' => 'http://127.0.0.1:13714',
        // 'bundle' => base_path('bootstrap/ssr/ssr.mjs'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | These options configure how Inertia discovers page components on the
    | filesystem. The paths and extensions are used to locate components
    | when rendering responses and during testing assertions.
    |
    */

    'pages' => [

        'paths' => [
            resource_path('js/pages'),
        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | The values described here are used to locate Inertia components on the
    | filesystem. For instance, when using `assertInertia`, the assertion
    | attempts to locate the component as a file relative to the paths.
    |
    */

    'testing' => [

        'ensure_pages_exist' => true,

    ],

];
