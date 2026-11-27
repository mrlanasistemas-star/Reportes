<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | MR LANA PEOPLE — fuente de verdad del personal (puesto, sucursal, baja).
    | Reportes LEE /colaboradores de la API de integración (token ppl_int_ del
    | cliente «reportes»). Sin token, Datos duros sigue funcionando con los datos de
    | Reportería y avisa «Sin conexión con People».
    */
    'people' => [
        'url' => env('PEOPLE_API_URL', 'https://people.mr-lana.com/api/integracion/v1'),
        'token' => env('PEOPLE_API_TOKEN'),
        'timeout' => (int) env('PEOPLE_API_TIMEOUT', 20),
        'cache_minutes' => (int) env('PEOPLE_CACHE_MINUTES', 30),
    ],

];
