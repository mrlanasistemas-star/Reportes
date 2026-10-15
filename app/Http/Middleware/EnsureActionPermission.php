<?php

namespace App\Http\Middleware;

use App\Services\Access\ActionPermissionService;
use App\Services\Access\ModuleAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permiso por ACCIÓN dentro de un módulo (Fase 7 de la auditoría, 15-oct-2026)
 * — ej. `permission:reportes,exportar`. Complementario a `module:X` (que ya
 * decide si el rol entra al módulo); este decide si puede ESTA acción puntual
 * dentro de él. Ver ActionPermissionService — el admin siempre pasa.
 *
 * Varias acciones separadas por "|" exigen TODAS (`permission:okr,generar_warning|autorizar_warning`).
 */
class EnsureActionPermission
{
    public function __construct(private readonly ActionPermissionService $permissions) {}

    public function handle(Request $request, Closure $next, string $module, string $actions): Response
    {
        foreach (explode('|', $actions) as $action) {
            if (! ActionPermissionService::exists($module, $action)) {
                // Error de programación (ruta con un permiso inexistente): se niega, nunca se deja pasar.
                report(new \LogicException("Permiso no catalogado: {$module}.{$action}"));
                abort(403, 'No tienes permiso para esta acción.');
            }

            if (! $this->permissions->canAction($request->user(), $module, $action)) {
                $label = ActionPermissionService::ACTIONS[$module][$action]['label'];
                $moduleLabel = ModuleAccessService::MODULES[$module]['label'] ?? $module;

                abort(403, "Tu rol no permite «{$label}» en {$moduleLabel}. Pide a un administrador que lo habilite en Roles y permisos.");
            }
        }

        return $next($request);
    }
}
