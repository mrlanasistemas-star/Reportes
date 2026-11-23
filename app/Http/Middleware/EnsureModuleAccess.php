<?php

namespace App\Http\Middleware;

use App\Services\Access\DataScopeService;
use App\Services\Access\ModuleAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    /**
     * @param  ModuleAccessService  $access  Servicio que decide qué módulos ve cada rol.
     */
    public function __construct(private readonly ModuleAccessService $access) {}

    /**
     * Permite el paso si el usuario tiene acceso al módulo. Si no lo tiene:
     * - GET a la ruta "dashboard"/"home" del módulo dashboard: redirige a su primer módulo permitido.
     * - Si el rol sí tiene el módulo pero el módulo exige alcance general y el
     *   usuario es restringido por sucursal: 403 explicando su alcance actual.
     * - En cualquier otro caso: 403 genérico "tu rol no tiene acceso".
     * Peticiones sin usuario pasan (la autenticación la resuelve otro middleware).
     *
     * @param  Request  $request  Petición entrante.
     * @param  Closure  $next  Siguiente capa del pipeline.
     * @param  string  $module  Clave del módulo declarada en la ruta (ej. "efectividad").
     * @return Response Respuesta de la siguiente capa o redirección al módulo de aterrizaje.
     *
     * @throws HttpException 403 si no tiene acceso.
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        if ($user && ! $this->access->allows($user, $module)) {
            if ($module === 'dashboard' && $request->isMethod('GET') && $request->routeIs('dashboard', 'home')) {
                return redirect($this->access->landingUrlFor($user));
            }

            // Fase 6.4 — su rol sí tiene el módulo, pero opera sobre todas las
            // sucursales y su alcance es restringido: mensaje propio, no "tu rol".
            if ($this->access->blockedByScope($user, $module)) {
                $label = ModuleAccessService::MODULES[$module]['label'] ?? 'Este módulo';
                abort(403, "{$label} opera sobre todas las sucursales y solo está disponible para usuarios con alcance general. "
                    .'Tu alcance autorizado: '.app(DataScopeService::class)->label($user).'. Si lo necesitas, pide a un administrador que amplíe tu alcance en Usuarios.');
            }

            abort(403, 'Tu rol no tiene acceso a este módulo. Pide a un administrador que lo habilite en Roles y permisos.');
        }

        return $next($request);
    }
}
