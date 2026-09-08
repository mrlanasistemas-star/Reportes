<?php

namespace App\Providers;

use App\Models\OkrObjective;
use App\Policies\OkrObjectivePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo OKR — permisos (sección 35 del pedido original, corregido en la
 * auditoría del 09-sep-2026, punto 11).
 *
 * AUDITORÍA (08-sep-2026): este repo NO tiene spatie/laravel-permission, ni
 * un sistema de roles dedicado, ni vínculo User↔Employee — TODA la app hoy
 * solo exige `auth`+`verified` (ver routes/web.php). SÍ existe, sin usarse en
 * ningún otro lugar del código, una columna real `users.role` (string,
 * `default('admin')` en la migración original) — se reutiliza tal cual.
 *
 * CORRECCIÓN 09-sep-2026 (punto 11): antes TODAS las acciones sobre un
 * Objective concreto (activar, editar meta/peso, check-in, evidencia,
 * eliminar) pasaban por Gates GLOBALES sin contexto (`okr.update`,
 * `okr.assign`, ...) — cualquier autenticado podía modificar/asignar/eliminar
 * CUALQUIER OKR, no solo el suyo. Esas acciones ahora son una Policy real
 * (OkrObjectivePolicy) que recibe el Objective y decide según admin/
 * responsable/otro (ver esa clase). Los Gates de cadena (`okr.*`) que
 * SIGUEN aquí son solo para acciones SIN un Objective concreto todavía
 * (listar/crear/catálogo KPI/histórico/admin) — nunca para acciones sobre un
 * Objective ya existente.
 */
class OkrServiceProvider extends ServiceProvider
{
    /** Gates SIN contexto de Objective (ver docblock). */
    public const PERMISSIONS = [
        'okr.view', 'okr.create', 'okr.kpi.manage', 'okr.history.view', 'okr.admin',
    ];

    /** Permisos administrativos — requieren users.role === 'admin'. */
    private const ADMIN_ONLY_PERMISSIONS = ['okr.kpi.manage', 'okr.admin'];

    public function boot(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Gate::define($permission, fn ($user) => in_array($permission, self::ADMIN_ONLY_PERMISSIONS, true)
                ? $user !== null && ($user->role ?? null) === 'admin'
                : $user !== null);
        }

        // Acciones CON un Objective concreto — ver OkrObjectivePolicy.
        Gate::policy(OkrObjective::class, OkrObjectivePolicy::class);
    }
}
