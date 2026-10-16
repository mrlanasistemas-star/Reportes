<?php

namespace App\Services\Access;

use App\Mail\UserAccessGrantedMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Correo de bienvenida / entrega de accesos (prioridad 10). Entrega las
 * credenciales de primer acceso (usuario + contraseña temporal) dentro del
 * propio correo, con formato formal — sin enlaces de expiración.
 *
 * La contraseña en claro NUNCA se persiste: en `users` solo vive el hash. El
 * valor en claro solo viaja en este correo en el momento del envío:
 *   - En el alta (store) se nos pasa la contraseña temporal recién generada.
 *   - En un reenvío no existe el claro (solo el hash), así que se genera una
 *     NUEVA contraseña temporal y se asigna al usuario. El cambio del hash solo
 *     se persiste si el correo se aceptó, para no dejar a la persona sin acceso
 *     si el transporte falla.
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

    /**
     * Envía el correo con las credenciales de acceso.
     *
     * @param  string|null  $plainPassword  Contraseña temporal en claro ya asignada
     *                                       al usuario (alta). Si es null, se genera
     *                                       una nueva y se asigna solo si el envío
     *                                       se acepta (reenvío).
     * @return array{sent:bool, message:string, regenerated:bool, temporary_password:?string}
     */
    public function send(User $user, ?string $plainPassword = null): array
    {
        $attempts = (int) ($user->welcome_email_attempts ?? 0) + 1;

        // Si no conocemos el claro (reenvío), se prepara una contraseña temporal
        // nueva, pero el nuevo hash NO se guarda hasta confirmar que el correo
        // fue aceptado: así un fallo de transporte nunca deja sin acceso a la
        // persona (su contraseña anterior sigue válida).
        $regenerated = $plainPassword === null || $plainPassword === '';
        $temporary = $regenerated ? Str::password(16) : $plainPassword;

        try {
            Mail::to($user->email)->send(new UserAccessGrantedMail($user, $temporary));

            if ($regenerated) {
                $user->forceFill(['password' => Hash::make($temporary), 'remember_token' => Str::random(60)])->save();
            }

            $user->forceFill([
                'welcome_email_sent_at' => now(), 'welcome_email_error' => null,
                'welcome_email_status' => self::STATUS_ACCEPTED, 'welcome_email_attempts' => $attempts,
                'welcome_email_last_attempt_at' => now(),
            ])->save();

            return ['sent' => true, 'message' => self::MSG_ACCEPTED, 'regenerated' => $regenerated, 'temporary_password' => $temporary];
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

            // No se cambió la contraseña: si era un reenvío, el hash anterior sigue
            // intacto (nunca se guardó el nuevo), así la persona no queda sin acceso.
            $user->forceFill([
                'welcome_email_sent_at' => null, 'welcome_email_error' => $this->friendlyReason($e),
                'welcome_email_status' => self::STATUS_FAILED, 'welcome_email_attempts' => $attempts,
                'welcome_email_last_attempt_at' => now(),
            ])->save();

            return ['sent' => false, 'message' => self::MSG_FAILED, 'regenerated' => false, 'temporary_password' => null];
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
