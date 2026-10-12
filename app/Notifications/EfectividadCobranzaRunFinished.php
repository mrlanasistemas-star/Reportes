<?php

namespace App\Notifications;

use App\Models\EfectividadCobranzaRun;
use Illuminate\Notifications\Notification;

/**
 * Notificación INTERNA persistente (canal `database`, tabla `notifications`)
 * al terminar una ejecución de Efectividad de Cobranza. Sobrevive a recargas
 * y cambios de página: se lee desde la BD en la campana de la cabecera.
 *
 * El correo se envía por separado (EfectividadCobranzaRunNotifier) para que
 * una falla SMTP nunca impida registrar esta notificación ni viceversa.
 */
class EfectividadCobranzaRunFinished extends Notification
{
    public function __construct(public readonly EfectividadCobranzaRun $run) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $run = $this->run->loadMissing(['period', 'branch']);

        [$kind, $title] = match ($run->status) {
            EfectividadCobranzaRun::STATUS_SUCCESS => ['success', 'Tu reporte de Efectividad de Cobranza está listo.'],
            EfectividadCobranzaRun::STATUS_BLOCKED => ['blocked', 'No se pudo generar el reporte porque faltan datos obligatorios.'],
            default => ['failed', 'Se produjo un error durante la generación.'],
        };

        return [
            'module' => 'efectividad-cobranza',
            'kind' => $kind,
            'title' => $title,
            'run_id' => $run->id,
            'period_label' => $run->period?->label,
            'branch_label' => $run->branch?->name ?? 'General',
            'url' => route('efectividad-cobranza.runs.show', $run, false),
        ];
    }
}
