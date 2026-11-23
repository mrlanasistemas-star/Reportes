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
    /**
     * @param  EfectividadCobranzaRun  $run  Ejecución de Efectividad que terminó (éxito, bloqueo, cancelación o error).
     */
    public function __construct(public readonly EfectividadCobranzaRun $run) {}

    /**
     * Canales de entrega: solo `database` (campana).
     *
     * @param  object  $notifiable  Usuario que recibe la notificación.
     * @return array<int,string> Lista de canales.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Datos que se guardan en `notifications.data`: tipo (success/blocked/cancelled/failed)
     * según el estatus de la ejecución, título en español, periodo, sucursal
     * ('General' si no hay) y URL relativa al detalle de la ejecución.
     * Efecto secundario: carga las relaciones period y branch si faltan.
     *
     * @param  object  $notifiable  Usuario que recibe la notificación.
     * @return array<string,mixed> Carga útil de la notificación.
     */
    public function toArray(object $notifiable): array
    {
        $run = $this->run->loadMissing(['period', 'branch']);

        [$kind, $title] = match ($run->status) {
            EfectividadCobranzaRun::STATUS_SUCCESS => ['success', 'Tu reporte de Efectividad de Cobranza está listo.'],
            EfectividadCobranzaRun::STATUS_BLOCKED => ['blocked', 'No se pudo generar el reporte porque faltan datos obligatorios.'],
            EfectividadCobranzaRun::STATUS_CANCELLED => ['cancelled', 'La generación fue cancelada.'],
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
