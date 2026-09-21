<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Motor único de generación de PDF — Browsershot (Puppeteer + Chrome)
    |--------------------------------------------------------------------------
    |
    | Toda salida PDF de la aplicación (radiografía general/sucursal/gestor,
    | comparativos, guía del sistema) pasa por App\Services\Pdf\BrowsershotPdfRenderer,
    | que centraliza esta configuración. Nunca hardcodear rutas de Node/Chrome
    | dentro de un servicio — el VPS y el entorno local usan binarios distintos,
    | siempre resueltos vía .env.
    |
    */

    'browsershot' => [
        'node_binary' => env('BROWSERSHOT_NODE_BINARY'),
        'npm_binary'  => env('BROWSERSHOT_NPM_BINARY'),
        // PUPPETEER_EXECUTABLE_PATH como fallback: es la variable estándar que
        // usa Puppeteer/`npx puppeteer browsers install` para reportar dónde dejó
        // el Chrome que descargó, por si BROWSERSHOT_CHROME_PATH no se define aparte.
        'chrome_path' => env('BROWSERSHOT_CHROME_PATH', env('PUPPETEER_EXECUTABLE_PATH')),
        'timeout'     => (int) env('BROWSERSHOT_TIMEOUT', 180),
        'no_sandbox'  => (bool) env('BROWSERSHOT_NO_SANDBOX', false),
    ],

    'defaults' => [
        'format'            => 'Letter',
        'print_background'  => true,
        // Milisegundos que espera a que window.__PDF_READY__ === true antes de
        // fallar con PDF_RENDER_CHART_TIMEOUT — ver sección 12 del pedido
        // (nunca un sleep() fijo, siempre una señal determinista de la página).
        'pdf_ready_timeout' => (int) env('BROWSERSHOT_PDF_READY_TIMEOUT', 20000),
    ],

    'branding' => [
        'app_name' => 'MR LANA · Reportes',
    ],

];
