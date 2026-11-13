<?php

return [
    'template_path' => resource_path('templates/radiografia_template.xlsx'),

    'sheets' => [
        'dashboard' => 'Dashbord',
        'global' => 'GLOBAL',
    ],

    'maps' => [
        'GLOBAL' => [
            'periodo' => 'B2',
            'total_empleados' => 'H9',
            'gasto_total' => 'H10',
            'neto_total' => 'H11',
            'pagos_total' => 'H12',
            'bonos_total' => 'H13',
            'descuentos_total' => 'H14',
        ],
        'DASHBOARD' => [
            'periodo' => 'B2',
            'total_empleados' => 'C6',
            'gasto_total' => 'C7',
            'neto_total' => 'C8',
        ],
    ],

    /*
    | Caché del estado general de la radiografía (RadiografiaExportService::
    | generalStateCached) — la corrida pesada (~10-25 s) que alimenta Web, Excel/PDF,
    | OKR y la API v1 (/employees/{id}/financial-summary, /periods/{id}/branches).
    | La clave incluye summary.id + summary.updated_at, pero el cálculo también lee
    | fact_* y catálogos en vivo: por eso el TTL NO se sube a días; lo que evita el
    | «frío» es el precalentado programado (radiografia:precalentar), que refresca el
    | valor ANTES de que caduque.
    */
    'cache' => [
        'ttl_horas' => (int) env('RADIOGRAFIA_CACHE_TTL_HORAS', 12),
        // Cuánto espera una petición a que OTRA termine el mismo cálculo antes de
        // calcularlo ella misma (comportamiento anterior).
        'lock_espera_segundos' => (int) env('RADIOGRAFIA_CACHE_LOCK_ESPERA_SEGUNDOS', 45),
        // Vida máxima del candado si el proceso que calcula muere a medias.
        'lock_segundos' => (int) env('RADIOGRAFIA_CACHE_LOCK_SEGUNDOS', 300),
    ],

    'precalentar' => [
        'activo' => (bool) env('RADIOGRAFIA_PRECALENTAR', true),
        // Cuántos periodos mensuales con radiografía vigente (los más recientes) se mantienen calientes.
        'periodos' => (int) env('RADIOGRAFIA_PRECALENTAR_PERIODOS', 3),
        // Edad a partir de la cual el valor en caché se recalcula y reemplaza. Debe ser
        // MENOR que cache.ttl_horas (con margen para la frecuencia del scheduler).
        'refrescar_horas' => (int) env('RADIOGRAFIA_PRECALENTAR_REFRESCAR_HORAS', 6),
        // Frecuencia del scheduler (expresión cron).
        'cron' => env('RADIOGRAFIA_PRECALENTAR_CRON', '*/30 * * * *'),
    ],
];
