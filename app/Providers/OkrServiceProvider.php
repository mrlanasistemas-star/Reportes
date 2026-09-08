<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo OKR — permisos granulares (sección 35 del pedido).
 *
 * AUDITORÍA (08-sep-2026): este repo NO tiene spatie/laravel-permission, ni
 * Policies, ni un sistema de roles dedicado, ni vínculo User↔Employee — TODA
 * la app hoy solo exige `auth`+`verified` (ver routes/web.php). SÍ existe,
 * sin usarse en ningún otro lugar del código, una columna real `users.role`
 * (string, `default('admin')` en la migración original) — "reutiliza el
 * sistema existente" aquí significa exactamente eso: la columna YA está en
 * la tabla, nunca se inventó una nueva. Como todos los usuarios existentes
 * ya tienen role='admin' por ese default, activar esta distinción NO cambia
 * el acceso de ningún usuario actual — solo evita que un usuario NUEVO
 * creado con otro rol (ej. 'colaborador'/'gestor') herede automáticamente
 * permisos administrativos.
 *
 * Corte mínimo aplicado:
 *   - Operación diaria del OKR (ver/crear/actualizar/asignar/check-in/subir
 *     evidencia/ver histórico) → cualquier usuario autenticado, igual que el
 *     resto de Reportería.
 *   - Administrativos (eliminar OKR, administrar catálogo KPI, okr.admin) →
 *     SOLO users.role === 'admin'.
 *
 * "Gestor no puede ver OKR ajeno" (restricción por identidad real de usuario)
 * sigue sin ser implementable sin inventar un vínculo User↔Employee que no
 * existe — fuera de alcance ("no inventes autenticación aparte"). Limitación
 * conocida y documentada, no un olvido.
 */
class OkrServiceProvider extends ServiceProvider
{
    public const PERMISSIONS = [
        'okr.view', 'okr.create', 'okr.update', 'okr.delete', 'okr.assign',
        'okr.checkin', 'okr.evidence.upload', 'okr.kpi.manage', 'okr.history.view',
        'okr.admin',
    ];

    /** Permisos administrativos — requieren users.role === 'admin'. */
    private const ADMIN_ONLY_PERMISSIONS = ['okr.delete', 'okr.kpi.manage', 'okr.admin'];

    public function boot(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Gate::define($permission, fn ($user) => in_array($permission, self::ADMIN_ONLY_PERMISSIONS, true)
                ? $user !== null && ($user->role ?? null) === 'admin'
                : $user !== null);
        }
    }
}
