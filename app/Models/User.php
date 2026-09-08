<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

// 'role' agregado a fillable el 08-sep-2026 — módulo OKR (ver
// App\Providers\OkrServiceProvider): columna real ya existente en la tabla
// `users` (default 'admin' en la migración original) que nadie más leía ni
// escribía — se reutiliza tal cual, nunca se creó una columna nueva.
#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // 'access_enabled_at' agregado 10-sep-2026 — módulo OKR (ver
            // ResponsibleController): habilitación ADMINISTRATIVA de acceso,
            // distinta de la verificación real del correo (email_verified_at
            // nunca se debe fingir para representar esto — ver auditoría punto 32).
            'access_enabled_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
