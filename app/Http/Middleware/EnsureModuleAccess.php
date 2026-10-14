<?php

namespace App\Http\Middleware;

use App\Services\Access\ModuleAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorización BACKEND por módulo (14-oct-2026) — reemplaza a
 * EnsureReporteriaAccess: qué ve cada rol ya no está fijo en código, se
 * configura en "Usuarios y accesos" (ver ModuleAccessService). Uso:
 * ->middleware('module:efectividad').
 *
 * Si alguien sin acceso al Dashboard pide /dashboard (inicio por default,
 * logo del menú), se le lleva a su primer módulo permitido en vez de un 403.
 */
class EnsureModuleAccess
{
    public function __construct(private readonly ModuleAccessService $access) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        if ($user && ! $this->access->allows($user, $module)) {
            if ($module === 'dashboard' && $request->isMethod('GET') && $request->routeIs('dashboard', 'home')) {
                return redirect($this->access->landingUrlFor($user));
            }

            abort(403, 'Tu rol no tiene acceso a este módulo. Pide a un administrador que lo habilite en "Usuarios y accesos".');
        }

        return $next($request);
    }
}
