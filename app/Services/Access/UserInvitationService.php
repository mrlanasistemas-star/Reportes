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
            // `cause` es la causa normalizada para que Sistemas la pueda filtrar;
            // `error` conserva el mensaje original del transporte (sin contraseñas:
            // Symfony nunca incluye la contraseña, solo host/puerto/usuario).
            Log::error('No se pudo enviar la invitación de acceso.', [
                'user_id' => $user->id,
                'cause' => self::technicalCause($e),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

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

    /**
     * Traduce el error técnico del transporte a un mensaje útil (en español) para
     * el administrador, SIN exponer secretos. El detalle técnico completo queda
     * únicamente en el log del servidor. La causa técnica normalizada (clave corta)
     * se puede consultar con {@see self::technicalCause()} para diagnóstico interno.
     */
    private function friendlyReason(\Throwable $e): string
    {
        return match (self::technicalCause($e)) {
            'auth_app_password' => 'Gmail rechazó la contraseña: se requiere una “Contraseña de aplicación” (con verificación en dos pasos activa), no la contraseña normal. Revisa la configuración de correo del sistema.',
            'auth_credentials' => 'El servidor de correo rechazó las credenciales configuradas (usuario o contraseña). Revisa la configuración de correo del sistema.',
            'sender_not_authorized' => 'El remitente configurado no está autorizado por el servidor de correo: la dirección “De” (MAIL_FROM_ADDRESS) debe coincidir con la cuenta autenticada o ser un alias verificado.',
            'recipient_rejected' => 'El servidor de correo rechazó la dirección del destinatario. Verifica el correo del usuario.',
            'tls' => 'Falló la negociación de cifrado (TLS/STARTTLS) con el servidor de correo. Revisa el puerto y el esquema de cifrado configurados.',
            'dns' => 'No se pudo resolver el nombre del servidor de correo (DNS). Revisa MAIL_HOST en la configuración del sistema.',
            'port_blocked' => 'No hubo conexión con el servidor de correo (posible puerto bloqueado por la red). Revisa host/puerto y el firewall de salida.',
            'timeout' => 'El servidor de correo no respondió a tiempo. Intenta de nuevo en unos minutos.',
            'connection', 'connection_refused' => 'No hubo conexión con el servidor de correo. Intenta de nuevo en unos minutos.',
            'not_configured' => 'El transporte de correo no está configurado (todavía apunta a valores de ejemplo). Configura MAIL_* en el sistema.',
            default => 'El servidor de correo no aceptó el mensaje. Intenta de nuevo; si persiste, revisa la configuración de correo.',
        };
    }

    /**
     * Clasifica el error del transporte en una causa técnica corta y estable, sin
     * incluir datos sensibles. Útil para el log y el diagnóstico de Sistemas.
     */
    public static function technicalCause(\Throwable $e): string
    {
        $raw = mb_strtolower($e->getMessage());

        return match (true) {
            str_contains($raw, 'application-specific password') || str_contains($raw, 'application specific password') => 'auth_app_password',
            str_contains($raw, '535') || str_contains($raw, 'username and password not accepted') || str_contains($raw, 'authentication failed') || str_contains($raw, 'auth') || str_contains($raw, 'credential') => 'auth_credentials',
            str_contains($raw, 'sender address') || str_contains($raw, 'not authorized') || str_contains($raw, 'send-as') || str_contains($raw, 'send as') || str_contains($raw, '5.7.0') => 'sender_not_authorized',
            str_contains($raw, 'recipient') || str_contains($raw, 'mailbox') || (str_contains($raw, 'address') && ! str_contains($raw, 'sender')) => 'recipient_rejected',
            str_contains($raw, 'tls') || str_contains($raw, 'ssl') || str_contains($raw, 'starttls') || str_contains($raw, 'certificate') || str_contains($raw, 'encryption') => 'tls',
            str_contains($raw, 'getaddrinfo') || str_contains($raw, 'name or service not known') || str_contains($raw, 'could not resolve') || str_contains($raw, 'dns') => 'dns',
            str_contains($raw, 'connection refused') => 'connection_refused',
            str_contains($raw, 'network is unreachable') || str_contains($raw, 'no route to host') => 'port_blocked',
            str_contains($raw, 'timed out') || str_contains($raw, 'timeout') => 'timeout',
            str_contains($raw, 'could not be established') || str_contains($raw, 'unable to connect') || str_contains($raw, 'connection') || str_contains($raw, 'could not') => 'connection',
            default => 'unknown',
        };
    }
}
