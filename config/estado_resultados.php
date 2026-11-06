<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Conciliación banco ↔ ERP
    |--------------------------------------------------------------------------
    | tolerancia_dias: diferencia MÁXIMA (en días naturales) entre la fecha
    | bancaria y la fecha ERP (fecha_pago, o fecha_captura si no hay pago) para
    | aceptar una conciliación. Con 0 días la coincidencia es "exacta"; dentro
    | de la tolerancia queda registrada como tipo "conciliado_erp_tolerancia"
    | (nunca se esconde). Fuera de ella el movimiento pasa a revisión.
    |
    | max_pagos_agregados: tamaño máximo del grupo de pagos bancarios de un
    | mismo folio que se prueba para la conciliación 1:N (suma exacta).
    */
    'conciliacion' => [
        'tolerancia_dias' => (int) env('ER_CONCILIACION_TOLERANCIA_DIAS', 3),
        'max_pagos_agregados' => 8,
    ],

    /*
    | Versión de las reglas de clasificación automática. Se guarda en cada
    | procesamiento para saber con qué reglas se clasificó un periodo.
    */
    'rules_version' => '2026-11-05.1',
];
