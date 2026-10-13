<?php

/**
 * Procesamiento en segundo plano de Efectividad de Cobranza Aprobada.
 *
 * Umbrales de salud del Job (ver EfectividadCobranzaRunHealthService). Son
 * deliberadamente holgados: un Excel grande puede tardar varios minutos en una
 * etapa sin reportar avance (el cálculo aprobado es una sola llamada), así que
 * "lleva mucho tiempo" NUNCA basta por sí solo para declarar muerto un Job —
 * además se consulta el estado real de la cola cuando el driver lo permite.
 */
return [
    // Tiempo máximo de ejecución de UN intento del Job (segundos). Debe ser
    // menor que `queue.connections.database.retry_after` (DB_QUEUE_RETRY_AFTER)
    // para que la cola no re-entregue un intento que sigue vivo.
    'job_timeout' => (int) env('EFECTIVIDAD_JOB_TIMEOUT', 900),

    // Minutos en "En cola" sin que ningún worker tome el trabajo antes de
    // mostrarlo como "Retrasado".
    'queued_delay_minutes' => (int) env('EFECTIVIDAD_QUEUED_DELAY_MINUTES', 5),

    // Minutos sin actividad registrada (heartbeat) en una ejecución en proceso
    // antes de mostrarla como "Retrasado" / revisar si el worker sigue vivo.
    'heartbeat_stale_minutes' => (int) env('EFECTIVIDAD_HEARTBEAT_STALE_MINUTES', 15),

    // Minutos en cola/sin actividad MUY por encima de lo plausible (un archivo
    // grande sí puede tardar; 2+ horas sin que NINGÚN worker haya avanzado no
    // es "lento", es evidencia de que el proceso de colas no está corriendo).
    // A partir de este umbral el diagnóstico deja de sugerir "espera, puede
    // tardar" y pasa a "Sin actividad del worker" con la causa más probable.
    'critically_stalled_minutes' => (int) env('EFECTIVIDAD_CRITICALLY_STALLED_MINUTES', 120),

    // Cada cuántos registros (o segundos) se persiste el avance de una etapa
    // medible. Nunca se escribe en la BD por cada registro.
    'progress_every_rows' => (int) env('EFECTIVIDAD_PROGRESS_EVERY_ROWS', 5000),
    'progress_every_seconds' => (int) env('EFECTIVIDAD_PROGRESS_EVERY_SECONDS', 3),

    // Inspección de encabezados del archivo físico (solo primeras filas).
    'header_scan_rows' => 30,

    // Máximo de intentos de envío del correo de notificación (independiente
    // del Job financiero).
    'notification_max_attempts' => (int) env('EFECTIVIDAD_NOTIFICATION_MAX_ATTEMPTS', 5),
];
