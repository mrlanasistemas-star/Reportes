<?php

namespace App\Models;

use NotificationChannels\WebPush\PushSubscription as BasePushSubscription;

/**
 * Suscripción Web Push (23-nov-2026). Extiende el modelo del paquete
 * laravel-notification-channels/webpush solo para mantener `endpoint_hash`
 * (índice único compatible con MyISAM — ver la migración
 * 2026_11_23_100001_create_push_subscriptions_table).
 *
 * Tabla: `push_subscriptions`. Cada fila es un navegador/dispositivo de un usuario
 * suscrito a notificaciones push (endpoint + llaves del paquete).
 */
class PushSubscription extends BasePushSubscription
{
    /**
     * Registra el evento `saving` que recalcula `endpoint_hash` cada vez que se
     * guarda la suscripción, para mantener el índice único sincronizado.
     */
    protected static function booted(): void
    {
        static::saving(function (self $subscription) {
            $subscription->endpoint_hash = self::hashEndpoint((string) $subscription->endpoint);
        });
    }

    /**
     * Calcula el hash SHA-256 de un endpoint push.
     *
     * @param  string  $endpoint  URL del endpoint del servicio push del navegador.
     * @return string Hash hexadecimal de 64 caracteres.
     */
    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * Búsqueda por hash (indexada) en lugar de por el TEXT completo.
     *
     * @param  string  $endpoint  URL del endpoint push a buscar.
     * @return static|null La suscripción encontrada o null.
     */
    public static function findByEndpoint(string $endpoint): ?static
    {
        return static::query()->where('endpoint_hash', self::hashEndpoint($endpoint))->first();
    }
}
