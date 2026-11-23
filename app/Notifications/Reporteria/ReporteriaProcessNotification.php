<?php

namespace App\Notifications\Reporteria;

use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Base de las notificaciones de procesos de Reportería (23-nov-2026):
 * lectura de archivos, generación de Radiografía y nuevo periodo mensual.
 *
 * Canales (mismo patrón que EfectividadCobranzaRunFinished):
 *  - `database`: siempre — alimenta la campana de la cabecera.
 *  - WebPush: si el usuario lo dejó activo para este tipo, tiene al menos un
 *    dispositivo suscrito y el servidor tiene claves VAPID.
 *  - `mail`: solo las subclases que no tienen un correo propio (los Jobs de
 *    carga y de Radiografía ya envían su Mailable detallado).
 *
 * Se envía en cola (ShouldQueue): una falla de push o SMTP nunca afecta el
 * Job que la originó.
 */
abstract class ReporteriaProcessNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Tipo de preferencia de notificación que controla este aviso.
     *
     * @return string Constante UserNotificationPreference::TYPE_*.
     */
    abstract public function preferenceType(): string;

    /**
     * Tipo visual del aviso en la campana.
     *
     * @return string success|failed|blocked|cancelled|info.
     */
    abstract protected function kind(): string;

    /**
     * Título corto en español del aviso.
     *
     * @return string Título.
     */
    abstract protected function title(): string;

    /**
     * Texto descriptivo del aviso.
     *
     * @return string Cuerpo del mensaje.
     */
    abstract protected function body(): string;

    /**
     * Ruta relativa a la que lleva el aviso (campana y clic en el push).
     *
     * @return string URL relativa.
     */
    abstract protected function relativeUrl(): string;

    /**
     * Nombre del periodo que se muestra en el aviso.
     *
     * @return string|null Nombre del periodo.
     */
    abstract protected function periodLabel(): ?string;

    /**
     * Etiqueta de alcance (sucursal/gerencia) del reporte.
     *
     * @return string|null Etiqueta de alcance o null si es general.
     */
    protected function branchLabel(): ?string
    {
        return null;
    }

    /**
     * Identificador de la ejecución relacionada (agrupa los push del mismo proceso).
     *
     * @return int|null Id de la ejecución.
     */
    protected function runId(): ?int
    {
        return null;
    }

    /**
     * Indica si esta notificación lleva correo propio. Por defecto no (los Jobs ya
     * envían su Mailable detallado); MonthlyPeriodCreated lo activa.
     *
     * @return bool true si debe agregarse el canal mail.
     */
    protected function includesMail(): bool
    {
        return false;
    }

    /**
     * Decide los canales: siempre `database`; `mail` si la subclase lo incluye, el
     * usuario tiene correo y su preferencia lo permite; WebPush si hay claves
     * VAPID, la preferencia lo permite y el usuario tiene al menos un dispositivo
     * suscrito. Para notifiables que no son User solo se usa `database`.
     *
     * @param  object  $notifiable  Destinatario.
     * @return array<int,string> Canales de entrega.
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (! $notifiable instanceof User) {
            return $channels;
        }

        if ($this->includesMail()
            && filled($notifiable->email)
            && UserNotificationPreference::allows($notifiable, $this->preferenceType(), UserNotificationPreference::CHANNEL_MAIL)) {
            $channels[] = 'mail';
        }

        if (self::pushIsConfigured()
            && UserNotificationPreference::allows($notifiable, $this->preferenceType(), UserNotificationPreference::CHANNEL_PUSH)
            && self::hasPushSubscriptions($notifiable)) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * Datos guardados en `notifications.data` para la campana (módulo 'reporteria').
     *
     * @param  object  $notifiable  Destinatario.
     * @return array<string,mixed> Carga útil.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'module' => 'reporteria',
            'type' => $this->preferenceType(),
            'kind' => $this->kind(),
            'title' => $this->title(),
            'body' => $this->body(),
            'run_id' => $this->runId(),
            'period_label' => $this->periodLabel(),
            'branch_label' => $this->branchLabel(),
            'url' => $this->relativeUrl(),
        ];
    }

    /**
     * Mensaje push con título, cuerpo, logo, URL y una etiqueta por tipo+ejecución
     * (o periodo) para que avisos del mismo proceso se reemplacen. TTL de 24 h.
     *
     * @param  object  $notifiable  Destinatario.
     * @param  mixed  $notification  Parámetro del canal WebPush (no se usa).
     * @return WebPushMessage Mensaje push.
     */
    public function toWebPush(object $notifiable, $notification = null): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title())
            ->body($this->body())
            ->icon('/logoMrLana.png')
            ->badge('/logoMrLana.png')
            ->tag($this->preferenceType().'-'.($this->runId() ?? $this->periodLabel() ?? 'general'))
            ->data(['url' => $this->relativeUrl()])
            ->options(['TTL' => 86400, 'urgency' => 'normal']);
    }

    /**
     * Indica si el servidor tiene configuradas las claves VAPID pública y privada.
     *
     * @return bool true si se pueden enviar push.
     */
    public static function pushIsConfigured(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    /**
     * Indica si el usuario tiene al menos una suscripción push. Cualquier error de
     * BD (p. ej. tabla inexistente) se trata como "sin suscripciones".
     *
     * @param  User  $user  Destinatario.
     * @return bool true si tiene dispositivos suscritos.
     */
    private static function hasPushSubscriptions(User $user): bool
    {
        try {
            return $user->pushSubscriptions()->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
