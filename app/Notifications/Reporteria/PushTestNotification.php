<?php

namespace App\Notifications\Reporteria;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Prueba de push desde Configuración > Notificaciones. Se envía al momento
 * (sin cola) para que el usuario vea de inmediato si su dispositivo la recibe,
 * y solo por WebPush: no ensucia la campana ni el correo.
 */
class PushTestNotification extends Notification
{
    /**
     * Canal único: WebPush.
     *
     * @param  object  $notifiable  Usuario que solicitó la prueba.
     * @return array<int,string> Lista de canales.
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    /**
     * Mensaje push de prueba con logo, etiqueta 'prueba-push', enlace a la
     * configuración de notificaciones y TTL de 10 minutos.
     *
     * @param  object  $notifiable  Usuario destinatario.
     * @param  mixed  $notification  Notificación (parámetro del canal WebPush, no se usa).
     * @return WebPushMessage Mensaje push.
     */
    public function toWebPush(object $notifiable, $notification = null): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Notificaciones activadas')
            ->body('Así verás los avisos de Reportería MR LANA en este dispositivo.')
            ->icon('/logoMrLana.png')
            ->badge('/logoMrLana.png')
            ->tag('prueba-push')
            ->data(['url' => route('notification-settings.edit', [], false)])
            ->options(['TTL' => 600]);
    }
}
