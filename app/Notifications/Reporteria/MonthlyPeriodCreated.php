<?php

namespace App\Notifications\Reporteria;

use App\Models\UserNotificationPreference;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Se creó un NUEVO mes operativo (PeriodController::store →
 * PeriodGenerationService::generateMonthly). No existía un correo para este
 * evento, así que esta notificación también lleva el canal `mail`.
 */
class MonthlyPeriodCreated extends ReporteriaProcessNotification
{
    /**
     * @param  int  $periodId  Id del periodo recién creado.
     * @param  string  $periodName  Nombre visible del periodo (p. ej. "Junio 2026").
     * @param  string|null  $createdByName  Nombre del usuario que lo creó, si se conoce.
     */
    public function __construct(
        public readonly int $periodId,
        public readonly string $periodName,
        public readonly ?string $createdByName = null,
    ) {}

    /**
     * Tipo de preferencia de notificación que controla este aviso.
     *
     * @return string Constante UserNotificationPreference::TYPE_*.
     */
    public function preferenceType(): string
    {
        return UserNotificationPreference::TYPE_PERIODO;
    }

    /**
     * Este aviso sí incluye correo (no existe otro Mailable para este evento).
     *
     * @return bool Siempre true.
     */
    protected function includesMail(): bool
    {
        return true;
    }

    /**
     * Tipo visual del aviso en la campana.
     *
     * @return string success|failed|blocked|cancelled|info.
     */
    protected function kind(): string
    {
        return 'info';
    }

    /**
     * Título corto en español del aviso.
     *
     * @return string Título.
     */
    protected function title(): string
    {
        return "Nuevo periodo mensual: {$this->periodName}.";
    }

    /**
     * Texto descriptivo del aviso.
     *
     * @return string Cuerpo del mensaje.
     */
    protected function body(): string
    {
        return $this->createdByName
            ? "{$this->createdByName} creó el mes operativo. Ya puedes subir sus archivos."
            : 'Se creó el mes operativo. Ya puedes subir sus archivos.';
    }

    /**
     * Ruta relativa a la que lleva el aviso (campana y clic en el push).
     *
     * @return string URL relativa.
     */
    protected function relativeUrl(): string
    {
        return route('periodos.index', [], false);
    }

    /**
     * Nombre del periodo que se muestra en el aviso.
     *
     * @return string|null Nombre del periodo.
     */
    protected function periodLabel(): ?string
    {
        return $this->periodName;
    }

    /**
     * Correo en español con el nombre del periodo, enlace a Periodos y nota para
     * desactivar el aviso.
     *
     * @param  object  $notifiable  Usuario destinatario.
     * @return MailMessage Mensaje de correo.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Nuevo periodo mensual — {$this->periodName}")
            ->greeting('Hola'.(filled($notifiable->name ?? null) ? ", {$notifiable->name}" : '').'.')
            ->line($this->title())
            ->line($this->body())
            ->action('Ver periodos', url($this->relativeUrl()))
            ->line('Puedes desactivar este aviso en Configuración > Notificaciones.')
            ->salutation('Reportería MR LANA');
    }
}
