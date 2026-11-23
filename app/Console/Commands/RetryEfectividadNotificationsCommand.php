<?php

namespace App\Console\Commands;

use App\Models\EfectividadCobranzaRun;
use App\Services\Efectividad\EfectividadCobranzaRunNotifier;
use Illuminate\Console\Command;

/**
 * Comando `efectividad:retry-notifications` — reintenta correos/notificaciones internas de
 * Efectividad de Cobranza que fallaron (SMTP caído, sin configurar, etc.). Idempotente: el
 * notificador reclama cada envío con un UPDATE condicional y lleva registro por destinatario,
 * así que nunca duplica un correo ya entregado. Respeta el máximo de intentos
 * (efectividad.notification_max_attempts, 5 por defecto).
 *
 * Solo considera ejecuciones terminadas que pasaron por el flujo de notificaciones
 * (`notification_status` no nulo) — nunca notifica retroactivamente ejecuciones históricas
 * anteriores a esta funcionalidad.
 *
 * Escribe en BD: SÍ (estado de notificación de `efectividad_cobranza_runs`) y envía correos.
 *
 * Ejemplos:
 *   php artisan efectividad:retry-notifications
 *   php artisan efectividad:retry-notifications --run=42
 */
class RetryEfectividadNotificationsCommand extends Command
{
    protected $signature = 'efectividad:retry-notifications {--run= : Limitar a una ejecución específica}';

    protected $description = 'Reintenta las notificaciones (correo e internas) pendientes o fallidas de Efectividad de Cobranza.';

    /**
     * Busca ejecuciones con correo fallido (bajo el máximo de intentos) o sin notificación interna y
     * reintenta ambas.
     *
     * @param  EfectividadCobranzaRunNotifier  $notifier  Notificador de ejecuciones.
     * @return int Siempre self::SUCCESS.
     */
    public function handle(EfectividadCobranzaRunNotifier $notifier): int
    {
        $maxAttempts = max(1, (int) config('efectividad.notification_max_attempts', 5));

        $runs = EfectividadCobranzaRun::query()
            ->whereIn('status', EfectividadCobranzaRun::TERMINAL_STATUSES)
            ->whereNotNull('notification_status')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('notification_status', EfectividadCobranzaRun::NOTIFICATION_FAILED)->where('notification_attempts', '<', $maxAttempts))
                ->orWhereNull('in_app_notified_at'))
            ->when($this->option('run'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->get();

        if ($runs->isEmpty()) {
            $this->info('No hay notificaciones pendientes.');

            return self::SUCCESS;
        }

        foreach ($runs as $run) {
            $notifier->notifyInApp($run);
            $status = $notifier->sendMail($run);
            $run->refresh();
            $this->line(sprintf('#%d → correo: %s%s', $run->id, $status, $run->notification_error ? " ({$run->notification_error})" : ''));
        }

        return self::SUCCESS;
    }
}
