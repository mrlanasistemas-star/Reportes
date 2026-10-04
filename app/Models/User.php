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
     * Roles del sistema (cierre 04-oct-2026, Parte 1): ADMIN (acceso total),
     * GERENCIAL (Reportería + OKR operativo: asignación/individualización/
     * Carta/Warning/seguimiento, SIN gestión de usuarios ni catálogo KPI) y
     * COLABORADOR (solo sus propias funciones OKR — check-in, evidencia,
     * seguimiento propio — NUNCA acceso financiero global de Reportería).
     */
    public const ROLE_ADMIN = 'admin';
    public const ROLE_GERENCIAL = 'gerencial';
    public const ROLE_COLABORADOR = 'colaborador';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_GERENCIAL, self::ROLE_COLABORADOR];

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isGerencial(): bool
    {
        return $this->role === self::ROLE_GERENCIAL;
    }

    public function isColaborador(): bool
    {
        return !$this->isAdmin() && !$this->isGerencial();
    }

    /** Admin o gerencial — acceso financiero global de Reportería y operación completa de OKR. */
    public function hasManagerialAccess(): bool
    {
        return $this->isAdmin() || $this->isGerencial();
    }

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
