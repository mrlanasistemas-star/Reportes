<?php

namespace App\Services\Efectividad;

use App\Mail\EfectividadCobranzaRunMail;
use App\Models\EfectividadCobranzaRun;
use App\Models\User;
use App\Notifications\EfectividadCobranzaRunFinished;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Notifica el fin de una ejecución de Efectividad de Cobranza — notificación
 * interna (BD) + correo — con estado PROPIO, independiente del resultado
 * financiero:
 *
 *  - Idempotente: cada canal se "reclama" con un UPDATE condicional atómico
 *    (una sola sentencia; funciona igual en MyISAM que en InnoDB). Un
 *    reintento del Job, un Job duplicado o el comando de reintento nunca
 *    envían dos veces el mismo correo ni crean dos notificaciones internas.
 *  - Una falla SMTP NUNCA lanza excepción hacia el Job: el reporte ya está
 *    guardado; el correo queda `failed` con el motivo y puede reintentarse
 *    (`php artisan efectividad:retry-notifications`).
 *  - `sent` solo se registra cuando el transportador ACEPTÓ el mensaje (no
 *    lanzó excepción). Nunca se afirma un envío que no ocurrió.
 *  - Por destinatario: se guardan en metadata los usuarios ya notificados,
 *    para que un reintento parcial no duplique correos a quien ya lo recibió.
 */
class EfectividadCobranzaRunNotifier
{
    public function notify(EfectividadCobranzaRun $run): void
    {
        $run->refresh();

        if (! $run->isTerminal()) {
            return;
        }

        $this->notifyInApp($run);
        $this->sendMail($run);
    }

    public function notifyInApp(EfectividadCobranzaRun $run): bool
    {
        $claimed = EfectividadCobranzaRun::query()
            ->whereKey($run->id)
            ->whereNull('in_app_notified_at')
            ->update(['in_app_notified_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        try {
            foreach ($this->recipients($run) as $user) {
                $user->notify(new EfectividadCobranzaRunFinished($run));
            }

            return true;
        } catch (\Throwable $e) {
            // Se libera el reclamo para que el comando de reintento pueda volver a intentarlo.
            EfectividadCobranzaRun::query()->whereKey($run->id)->update(['in_app_notified_at' => null]);
            Log::error('Efectividad: no se pudo registrar la notificación interna.', [
                'run_id' => $run->id, 'exception' => get_class($e), 'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return string estado final del correo (sent|failed|skipped|…)
     */
    public function sendMail(EfectividadCobranzaRun $run): string
    {
        $maxAttempts = max(1, (int) config('efectividad.notification_max_attempts', 5));

        $claimed = EfectividadCobranzaRun::query()
            ->whereKey($run->id)
            ->whereIn('status', EfectividadCobranzaRun::TERMINAL_STATUSES)
            ->where(fn ($q) => $q->whereNull('notification_status')
                ->orWhere('notification_status', EfectividadCobranzaRun::NOTIFICATION_FAILED))
            ->where('notification_attempts', '<', $maxAttempts)
            ->update([
                'notification_status' => EfectividadCobranzaRun::NOTIFICATION_SENDING,
                'notification_attempts' => DB::raw('notification_attempts + 1'),
            ]);

        $run->refresh();

        if ($claimed === 0) {
            return (string) $run->notification_status;
        }

        $kind = match ($run->status) {
            EfectividadCobranzaRun::STATUS_SUCCESS => EfectividadCobranzaRunMail::KIND_SUCCESS,
            EfectividadCobranzaRun::STATUS_BLOCKED => EfectividadCobranzaRunMail::KIND_BLOCKED,
            default => EfectividadCobranzaRunMail::KIND_FAILED,
        };

        $metadata = $run->metadata ?? [];
        $alreadyNotified = array_map('intval', $metadata['mail_notified_user_ids'] ?? []);
        $pending = $this->recipients($run)
            ->filter(fn (User $u) => filled($u->email) && ! in_array($u->id, $alreadyNotified, true));

        if ($pending->isEmpty() && $alreadyNotified === []) {
            return $this->finishMail($run, EfectividadCobranzaRun::NOTIFICATION_SKIPPED, 'El solicitante no tiene un correo registrado.');
        }

        if (! $this->mailIsConfigured()) {
            Log::warning('Efectividad: correo no enviado — transporte de correo sin configurar.', ['run_id' => $run->id, 'mailer' => config('mail.default')]);

            return $this->finishMail($run, EfectividadCobranzaRun::NOTIFICATION_FAILED, 'El servidor de correo (SMTP) no está configurado. El reporte sí está disponible en el módulo.');
        }

        $detailUrl = route('efectividad-cobranza.runs.show', $run);

        try {
            foreach ($pending as $user) {
                Mail::to($user->email)->send(new EfectividadCobranzaRunMail($run, $user, $kind, $detailUrl));
                $alreadyNotified[] = $user->id;
                $metadata['mail_notified_user_ids'] = $alreadyNotified;
                $run->forceFill(['metadata' => $metadata])->save();
            }
        } catch (\Throwable $e) {
            Log::error('Efectividad: el transportador de correo rechazó el envío.', [
                'run_id' => $run->id, 'exception' => get_class($e), 'message' => $e->getMessage(),
            ]);

            return $this->finishMail(
                $run,
                EfectividadCobranzaRun::NOTIFICATION_FAILED,
                'No se pudo entregar el correo al servidor de correo ('.class_basename($e).'). Se reintentará automáticamente.',
            );
        }

        $metadata['mail_transport'] = config('mail.default');
        $run->forceFill(['metadata' => $metadata])->save();

        return $this->finishMail($run, EfectividadCobranzaRun::NOTIFICATION_SENT, null);
    }

    /** SMTP requiere host y remitente; `log`/`array` se consideran configurados (entornos locales/pruebas). */
    public function mailIsConfigured(): bool
    {
        $mailer = (string) config('mail.default');

        if ($mailer === '' || ! is_array(config("mail.mailers.{$mailer}"))) {
            return false;
        }

        if (! filled(config('mail.from.address'))) {
            return false;
        }

        if (config("mail.mailers.{$mailer}.transport") === 'smtp') {
            return filled(config("mail.mailers.{$mailer}.host"));
        }

        return true;
    }

    /** @return Collection<int,User> */
    private function recipients(EfectividadCobranzaRun $run)
    {
        $ids = $run->recipientUserIds();

        return $ids === [] ? collect() : User::query()->whereIn('id', $ids)->get();
    }

    private function finishMail(EfectividadCobranzaRun $run, string $status, ?string $error): string
    {
        $run->forceFill([
            'notification_status' => $status,
            'notification_error' => $error,
            'notified_at' => $status === EfectividadCobranzaRun::NOTIFICATION_SENT ? now() : $run->notified_at,
        ])->save();

        return $status;
    }
}
