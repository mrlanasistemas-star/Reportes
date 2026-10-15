<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invitación / correo de bienvenida al crear un usuario. NUNCA viaja una
 * contraseña en texto plano: el enlace usa el mismo mecanismo de Fortify que
 * "¿Olvidaste tu contraseña?" (Password::broker()->createToken()), de un solo
 * uso y con la vigencia de config/auth.php (passwords.users.expire).
 *
 * Enviado SÍNCRONO (no implementa ShouldQueue) — ver UserInvitationService.
 */
class UserAccessGrantedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $setPasswordUrl,
        public int $expiresInMinutes = 60,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Invitación a Reportes MR LANA — establece tu contraseña');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.users.acceso-creado',
            with: [
                'user' => $this->user,
                'setPasswordUrl' => $this->setPasswordUrl,
                'expiresLabel' => $this->expiresLabel(),
                'loginUrl' => route('login'),
                'supportEmail' => config('mail.support_address') ?: config('mail.from.address'),
            ],
        );
    }

    private function expiresLabel(): string
    {
        $m = $this->expiresInMinutes;
        if ($m % 1440 === 0) {
            $d = intdiv($m, 1440);

            return $d === 1 ? '24 horas' : "{$d} días";
        }
        if ($m % 60 === 0) {
            $h = intdiv($m, 60);

            return $h === 1 ? '1 hora' : "{$h} horas";
        }

        return "{$m} minutos";
    }
}
