<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de bienvenida / entrega de accesos al crear (o reenviar) un usuario.
 *
 * Entrega las credenciales de primer acceso (usuario + contraseña temporal) en
 * un formato formal, sin enlaces de expiración ni metadatos técnicos. La
 * contraseña temporal es de un solo uso práctico: se le pide a la persona
 * cambiarla en Configuración › Seguridad en cuanto ingresa. El valor en claro
 * NUNCA se persiste (en `users` solo vive el hash); solo viaja en este correo
 * en el momento del alta/reenvío.
 *
 * Enviado SÍNCRONO (no implementa ShouldQueue) — ver UserInvitationService.
 */
class UserAccessGrantedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public ?string $temporaryPassword = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tus accesos a Reportes MR LANA');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.users.acceso-creado',
            with: [
                'user' => $this->user,
                'temporaryPassword' => $this->temporaryPassword,
                'loginUrl' => route('login'),
            ],
        );
    }
}
