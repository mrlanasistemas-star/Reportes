<?php

return [
    /*
    | Importación de estados de cuenta: tamaño máximo del archivo en MB.
    */
    'import' => [
        'max_mb' => (int) env('ER_IMPORT_MAX_MB', 10),
    ],

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
    | max_pagos_agregados: tamaño máximo de los grupos (pagos o requisiciones de
    | un mismo folio) que se prueban para las conciliaciones 1:N, N:1 y N:M (suma exacta).
    |
    | estatus_pago_parcial: estatus del ERP que DECLARAN un pago parcial. Sólo con
    | ese estatus un pago menor que la requisición se concilia como "parcial"
    | (el ERP conserva su saldo pendiente). Con cualquier otro estatus un monto
    | distinto queda en revisión ("mismo folio, monto diferente").
    */
    'conciliacion' => [
        'tolerancia_dias' => (int) env('ER_CONCILIACION_TOLERANCIA_DIAS', 3),
        'max_pagos_agregados' => 8,
        'estatus_pago_parcial' => ['PAGO_PARCIAL', 'PARCIAL', 'PAGADA_PARCIAL', 'PAGADA_PARCIALMENTE'],
    ],

    /*
    | Reversos y reembolsos.
    |  - reversos.dias: un cargo y un abono de la MISMA cuenta, mismo importe al centavo,
    |    con evidencia (mismo folio o texto de reverso/cancelación) y a ≤ N días se anulan
    |    entre sí: quedan fuera del ER con neto $0 y trazabilidad (par RV-xxx).
    |  - reembolsos.patron: texto que identifica un abono como reembolso/devolución. Si el
    |    folio lo vincula con un gasto reconocido, reduce ESA MISMA línea; si no, queda pendiente.
    */
    'reversos' => [
        'dias' => 3,
        'patron' => 'REVERS|CANCELACION|CANCELADO|DEVOLUCION SPEI|SPEI DEVUELTO|RECHAZAD',
    ],
    'reembolsos' => [
        'patron' => 'DEVOLUCION|REEMBOLSO|REINTEGRO',
    ],

    /*
    | Procesamiento: candado por periodo para que dos procesos simultáneos no se
    | pisen (Cache::lock). lock_segundos = vida máxima del candado.
    */
    'procesamiento' => [
        'lock_segundos' => 600,
    ],

    /*
    | Criterio de RECONOCIMIENTO (periodo gerencial). Versionado: se guarda en cada
    | procesamiento y en el dataset. Cambiar el criterio exige subir la versión.
    */
    'reconocimiento' => [
        'version' => '2026-11-06.1',
        'criterio' => 'flujo_bancario_con_devengo_erp',
        'reglas' => [
            'banco' => 'Un movimiento bancario se reconoce en el periodo cuyo rango contiene su FECHA BANCARIA (fecha de operación del estado de cuenta).',
            'erp' => 'Una requisición ERP se reconoce en el periodo en el que se importó el ERP (mismo universo que Radiografía), sin importar la fecha de su pago.',
            'conciliado' => 'Si un pago bancario concilia con el ERP, el gasto lo reconoce el ERP una sola vez; el banco sólo es evidencia de pago.',
            'fuera_periodo' => 'Un movimiento con fecha fuera del periodo se conserva visible como "fuera del periodo"; nunca se mueve de periodo en automático.',
            'periodo_anterior' => 'Un pago cuyo folio ERP pertenece a un periodo anterior se identifica como "ajuste de periodo anterior" y queda pendiente de revisión.',
        ],
    ],

    /*
    | Versión de las reglas de clasificación automática. Se guarda en cada
    | procesamiento para saber con qué reglas se clasificó un periodo.
    */
    'rules_version' => '2026-11-06.1',
];
