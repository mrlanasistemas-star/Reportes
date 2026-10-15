<?php

namespace App\Http\Middleware;

use App\Services\Access\ActionPermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permiso por ACCIÓN dentro de un módulo (Fase 7 de la auditoría, 15-oct-2026)
 * — ej. `permission:reportes,exportar`. Complementario a `module:X` (que ya
 * decide si el rol entra al módulo); este decide si puede ESTA acción puntual
 * dentro de él. Ver ActionPermissionService — el admin siempre pasa.
 */
class EnsureActionPermission
{
    public function __construct(private readonly ActionPermissionService $permissions) {}

    public function handle(Request $request, Closure $next, string $module, string $action): Response
    {
        abort_unless($this->permissions->canAction($request->user(), $module, $action), 403, 'No tienes permiso para esta acción.');

        return $next($request);
    }
}
