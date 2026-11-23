<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\Access\RoleCatalogService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use NotificationChannels\WebPush\HasPushSubscriptions;

// 'role' agregado a fillable el 08-sep-2026 — módulo OKR (ver
// App\Providers\OkrServiceProvider): columna real ya existente en la tabla
// `users` (default 'admin' en la migración original) que nadie más leía ni
// escribía — se reutiliza tal cual, nunca se creó una columna nueva.
// 'employee_id' agregado 05-oct-2026 — identidad persistente User↔Employee
// (ver migración 2026_10_05_000001): 1 User = 0/1 Employee, nullable,
// UNIQUE. ADMIN/GERENCIAL típicamente NULL. Es el vínculo REAL que resuelve
// "¿este colaborador es el DUEÑO de este Objective individual?" — nunca por
// nombre/email en runtime (ver OkrObjectiveVisibilityService).
/**
 * Usuario humano con acceso al sistema (login con Fortify y 2FA opcional).
 *
 * Tabla: `users`. Cada fila es una cuenta de acceso. Su `role` (admin, gerencial,
 * colaborador o un rol personalizado de la tabla `roles`) determina qué módulos y
 * datos puede ver; `employee_id` la vincula opcionalmente con su Employee para
 * resolver lo "propio" (objetivos OKR individuales, datos del colaborador).
 * Recibe notificaciones por base de datos, correo y Web Push.
 *
 * Columnas/casts clave: `access_enabled_at` (habilitación administrativa),
 * `disabled_at` (cuenta deshabilitada: bloquea el login), `tour_welcome_seen_at`,
 * `welcome_email_sent_at`/`welcome_email_last_attempt_at` (invitación),
 * `last_login_at`, `password` (hashed), campos de 2FA (ocultos al serializar).
 */
#[Fillable(['name', 'email', 'password', 'role', 'employee_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    // HasPushSubscriptions (23-nov-2026): dispositivos con Web Push activo (ver docs/MANUAL-DESARROLLADOR.md).
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable, TwoFactorAuthenticatable;

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

    /** Roles base del sistema (los personalizados se resuelven a uno de estos niveles). */
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_GERENCIAL, self::ROLE_COLABORADOR];

    /**
     * Indica si el usuario es administrador (acceso total).
     *
     * @return bool true si `role` es exactamente `admin`.
     */
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Indica si el usuario tiene nivel gerencial: rol `gerencial` o un rol
     * personalizado cuyo nivel base (RoleCatalogService) sea gerencial.
     * Efecto secundario: puede consultar el catálogo de roles vía el contenedor.
     *
     * @return bool true si se comporta como gerencial.
     */
    public function isGerencial(): bool
    {
        // 16-oct-2026 — un rol personalizado se comporta según su NIVEL BASE
        // (gerencial/colaborador); sus módulos y acciones son propios.
        return $this->role === self::ROLE_GERENCIAL
            || ($this->role !== self::ROLE_ADMIN && app(RoleCatalogService::class)->baseLevel($this->role) === self::ROLE_GERENCIAL);
    }

    /**
     * Indica si el usuario es colaborador: ni admin ni de nivel gerencial.
     *
     * @return bool true si solo tiene acceso a sus propias funciones OKR.
     */
    public function isColaborador(): bool
    {
        return ! $this->isAdmin() && ! $this->isGerencial();
    }

    /**
     * Admin o gerencial — acceso financiero global de Reportería y operación completa de OKR.
     *
     * @return bool true si es admin o de nivel gerencial.
     */
    public function hasManagerialAccess(): bool
    {
        return $this->isAdmin() || $this->isGerencial();
    }

    /** Colaborador (Employee) vinculado a esta cuenta, si existe. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Atributos que se convierten de tipo al leer/escribir (fechas y contraseña hasheada).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'tour_welcome_seen_at' => 'datetime', // bienvenida al recorrido, una vez por usuario (16-oct-2026)
            // 'access_enabled_at' agregado 10-sep-2026 — módulo OKR (ver
            // ResponsibleController): habilitación ADMINISTRATIVA de acceso,
            // distinta de la verificación real del correo (email_verified_at
            // nunca se debe fingir para representar esto — ver auditoría punto 32).
            'access_enabled_at' => 'datetime',
            // Cuenta deshabilitada (14-oct-2026): bloquea el login en todo el sistema.
            'disabled_at' => 'datetime',
            'welcome_email_sent_at' => 'datetime',
            'welcome_email_last_attempt_at' => 'datetime', // invitación: último intento (prioridad 10)
            'last_login_at' => 'datetime', // último inicio de sesión (RecordUserLastLogin)
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
