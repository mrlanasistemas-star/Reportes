<?php

namespace App\Services\Access;

use App\Mail\UserAccessGrantedMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * Invitación / correo de bienvenida (prioridad 10). Enlace de un solo uso del
 * broker de contraseñas de Fortify — nunca la contraseña por correo.
 *
 * Síncrono a propósito: el administrador necesita saber AHORA si el servidor
 * de correo aceptó el mensaje. "Aceptado" significa solo eso: el transporte
 * lo tomó para su envío. Nunca se afirma que el destinatario lo recibió.
 */
class UserInvitationService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_FAILED = 'failed';

    public const MSG_ACCEPTED = 'El servidor de correo aceptó la invitación para su envío.';
    public const MSG_FAILED = 'No se pudo enviar la invitación. El usuario fue creado y puedes intentar enviarla nuevamente.';

    /** @return array{sent:bool, message:string} */
    public function send(User $user): array
    {
        $attempts = (int) ($user->welcome_email_attempts ?? 0) + 1;

        try {
            $token = Password::broker()->createToken($user);
            $url = route('password.reset', ['token' => $token, 'email' => $user->email]);

            Mail::to($user->email)->send(new UserAccessGrantedMail($user, $url, $this->expiresInMinutes()));

            $user->forceFill([
                'welcome_email_sent_at' => now(), 'welcome_email_error' => null,
                'welcome_email_status' => self::STATUS_ACCEPTED, 'welcome_email_attempts' => $attempts,
                'welcome_email_last_attempt_at' => now(),
            ])->save();

            return ['sent' => true, 'message' => self::MSG_ACCEPTED];
        } catch (\Throwable $e) {
            // El detalle técnico solo va al log del servidor, nunca a la pantalla.
            Log::error('No se pudo enviar la invitación de acceso.', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            $user->forceFill([
                'welcome_email_sent_at' => null, 'welcome_email_error' => $this->friendlyReason($e),
                'welcome_email_status' => self::STATUS_FAILED, 'welcome_email_attempts' => $attempts,
                'welcome_email_last_attempt_at' => now(),
            ])->save();

            return ['sent' => false, 'message' => self::MSG_FAILED];
        }
    }

    public function expiresInMinutes(): int
    {
        $broker = config('auth.defaults.passwords', 'users');

        return (int) config("auth.passwords.{$broker}.expire", 60);
    }

    /** Estado normalizado (filas anteriores a la columna welcome_email_status se deducen). */
    public static function statusOf(User $user): string
    {
        if ($user->welcome_email_status) {
            return $user->welcome_email_status;
        }
        if ($user->welcome_email_sent_at) {
            return self::STATUS_ACCEPTED;
        }

        return $user->welcome_email_error ? self::STATUS_FAILED : self::STATUS_PENDING;
    }

    private function friendlyReason(\Throwable $e): string
    {
        $raw = mb_strtolower($e->getMessage());

        return match (true) {
            str_contains($raw, 'auth') || str_contains($raw, 'credential') || str_contains($raw, '535') => 'El servidor de correo rechazó las credenciales configuradas. Revisa la configuración de correo del sistema.',
            str_contains($raw, 'connection') || str_contains($raw, 'timed out') || str_contains($raw, 'could not') => 'No hubo conexión con el servidor de correo. Intenta de nuevo en unos minutos.',
            str_contains($raw, 'address') || str_contains($raw, 'recipient') || str_contains($raw, 'mailbox') => 'El servidor de correo rechazó la dirección del destinatario. Verifica el correo del usuario.',
            default => 'El servidor de correo no aceptó el mensaje. Intenta de nuevo; si persiste, revisa la configuración de correo.',
        };
    }
}
