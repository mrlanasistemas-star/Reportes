<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;

/**
 * Último acceso (módulo Usuarios, prioridad 8): guarda `users.last_login_at`
 * en cada inicio de sesión. Update directo (sin tocar updated_at ni disparar
 * eventos del modelo) y tolerante a una BD aún sin migrar — nunca impide entrar.
 * Se registra solo por el descubrimiento automático de listeners de Laravel.
 */
class RecordUserLastLogin
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        try {
            DB::table('users')->where('id', $event->user->getAuthIdentifier())->update(['last_login_at' => now()]);
        } catch (\Throwable) {
            // Columna aún sin migrar: el inicio de sesión sigue normal.
        }
    }
}
