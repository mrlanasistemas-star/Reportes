<?php

use App\Models\PushSubscription;

/*
 * Web Push (23-nov-2026) — laravel-notification-channels/webpush, sin
 * servicios de pago: el navegador entrega el aviso con su propio servicio push
 * (FCM en Chrome/Android, Mozilla, Apple) usando las claves VAPID del servidor.
 * Generar claves: `php artisan webpush:vapid` (ver docs/MANUAL-DESARROLLADOR.md).
 * Sin claves, el canal push simplemente no se usa (la campana y el correo siguen).
 */
return [

    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('APP_URL')),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    // Modelo propio: guarda `endpoint_hash` para el índice único (compatible con MyISAM).
    'model' => PushSubscription::class,

    'table_name' => env('WEBPUSH_DB_TABLE', 'push_subscriptions'),

    // null = conexión por defecto (la misma de la migración y de las pruebas).
    'database_connection' => env('WEBPUSH_DB_CONNECTION'),

    // Tiempo máximo por envío: un servicio push lento nunca debe colgar al worker.
    'client_options' => [
        'timeout' => 15,
        'connect_timeout' => 5,
    ],

    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),

];
