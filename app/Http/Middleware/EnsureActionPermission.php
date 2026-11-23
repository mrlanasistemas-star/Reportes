<?php

namespace App\Http\Middleware;

use App\Services\Access\ActionPermissionService;
use App\Services\Access\ModuleAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    /**
     * @param  ActionPermissionService  $permissions  Servicio que resuelve si el rol del usuario tiene la acción.
     */
    public function __construct(private readonly ActionPermissionService $permissions) {}

    /**
     * Verifica, una por una, cada acción pedida por la ruta. Si una acción no
     * existe en el catálogo ActionPermissionService::ACTIONS se reporta como
     * error de programación (report()) y se niega con 403; si existe pero el
     * rol no la tiene, 403 con el nombre legible de la acción y del módulo.
     *
     * @param  Request  $request  Petición con el usuario autenticado.
     * @param  Closure  $next  Siguiente capa del pipeline.
     * @param  string  $module  Clave del módulo (ej. "reportes", "okr").
     * @param  string  $actions  Acciones separadas por "|"; se exigen TODAS.
     * @return Response Respuesta de la siguiente capa si todas las acciones están permitidas.
     *
     * @throws HttpException 403 si falta alguna acción o no está catalogada.
     */
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
