<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de bienvenida al crear un usuario (sección 11 de la auditoría,
 * 15-oct-2026). NUNCA viaja una contraseña en texto plano por correo (ver
 * ResponsibleController::store()) — el enlace usa el mismo mecanismo de
 * Fortify que "¿Olvidaste tu contraseña?" (Password::broker()->createToken()),
 * de un solo uso y expira con la configuración estándar de auth.php.
 *
 * Enviado SÍNCRONO (no implementa ShouldQueue): es un envío puntual y poco
 * frecuente (alta de usuario) — el admin necesita saber en el momento si de
 * verdad se envió, no descubrirlo horas después si el worker de colas no
 * estaba corriendo.
 */
class UserAccessGrantedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $setPasswordUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu acceso a Reportes MR LANA está listo');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.users.acceso-creado',
            with: [
                'user' => $this->user,
                'setPasswordUrl' => $this->setPasswordUrl,
            ],
        );
    }
}
