<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo OKR (08-sep-2026) — permisos granulares (sección 35 del pedido).
 *
 * HALLAZGO DE AUDITORÍA (documentado también en docs/OKR.md): este repo NO
 * tiene ningún sistema de roles/permisos (no hay spatie/laravel-permission, ni
 * Policies, ni Gates, ni columna de rol en `users`, ni vínculo User↔Employee) —
 * TODA la app hoy solo exige `auth`+`verified` (ver routes/web.php). "Reutiliza
 * el sistema existente" en este caso significa: mismo criterio que el resto de
 * Reportería (autenticado = acceso), expuesto aquí como Gates NOMBRADOS
 * (okr.view, okr.create, ...) para que activar restricciones reales en el
 * futuro sea un cambio de una línea por permiso, sin tocar controladores.
 *
 * "Gestor no puede ver OKR ajeno" (restricción por identidad real de usuario)
 * NO es implementable hoy sin inventar un vínculo User↔Employee que no existe
 * — deliberadamente NO se fabricó uno nuevo (fuera de alcance, ver pedido:
 * "no inventes autenticación aparte"). Esta es una limitación conocida y
 * documentada, no un olvido.
 */
class OkrServiceProvider extends ServiceProvider
{
    public const PERMISSIONS = [
        'okr.view', 'okr.create', 'okr.update', 'okr.delete', 'okr.assign',
        'okr.checkin', 'okr.evidence.upload', 'okr.kpi.manage', 'okr.history.view',
        'okr.admin',
    ];

    public function boot(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Gate::define($permission, fn ($user) => $user !== null);
        }
    }
}
