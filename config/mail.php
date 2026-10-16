<?php

/*
 * Deriva el `scheme` SMTP que Laravel 13 / Symfony Mailer realmente lee a partir
 * del antiguo `MAIL_ENCRYPTION` (que este framework ya ignora). Así una config
 * compartida con `MAIL_ENCRYPTION=ssl` sobre el puerto 465 sigue cifrando bien.
 *   - ssl            => 'smtps' (TLS implícito)
 *   - tls + 465      => 'smtps'
 *   - tls + otro     => null  (STARTTLS automático; p. ej. Gmail 587)
 *   - sin encryption => null  (lo resuelve el framework por el puerto)
 */
$resolveSmtpScheme = static function (?string $encryption, int $port): ?string {
    $encryption = $encryption !== null ? strtolower(trim($encryption)) : null;

    if ($encryption === 'ssl') {
        return 'smtps';
    }
    if ($encryption === 'tls' && $port === 465) {
        return 'smtps';
    }

    return null;
};

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            // Laravel 13 (Symfony Mailer) decide el cifrado SOLO por el `scheme`:
            //   - 'smtps' => TLS implícito (puerto 465)
            //   - 'smtp'  => STARTTLS automático si el servidor lo ofrece (puerto 587)
            // Este framework YA NO lee `MAIL_ENCRYPTION`. Para no romper configuraciones
            // compartidas que solo traen `MAIL_ENCRYPTION=tls|ssl`, derivamos aquí el
            // scheme equivalente. `MAIL_SCHEME` (si se define) siempre tiene prioridad.
            'scheme' => env('MAIL_SCHEME', $resolveSmtpScheme(
                env('MAIL_ENCRYPTION'),
                (int) env('MAIL_PORT', 2525),
            )),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dirección de soporte
    |--------------------------------------------------------------------------
    |
    | Mostrada al usuario en el correo de invitación (UserAccessGrantedMail).
    | Si no se define, se usa la dirección global "from".
    |
    | NOTA Gmail: `MAIL_FROM_ADDRESS` debe ser la MISMA cuenta de `MAIL_USERNAME`
    | o un alias "Enviar como" verificado en esa cuenta; de lo contrario Gmail
    | rechaza el envío ("sender address rejected" / reescritura del remitente).
    |
    */

    'support_address' => env('MAIL_SUPPORT_ADDRESS'),

];
