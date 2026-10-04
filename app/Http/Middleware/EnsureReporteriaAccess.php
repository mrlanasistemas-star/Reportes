<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parte 1 del cierre (04-oct-2026): hasta ahora Reportería (dashboard,
 * historico-general, periodos, asignaciones-empleado-sucursal, empleados,
 * validaciones, reportes-mensuales) solo exigía `auth`+`verified`, SIN
 * ningún control de rol — cualquier usuario autenticado, incluido un
 * COLABORADOR, veía datos financieros globales de la empresa.
 *
 * Regla (autorización BACKEND, no solo ocultar menú):
 *   - admin / gerencial (User::hasManagerialAccess()): entran siempre.
 *   - colaborador: 403 — su acceso vive únicamente en /okr (seguimiento
 *     propio, check-in, evidencia), nunca en Reportería financiero global.
 *
 * Nunca se aplica al módulo OKR (ver EnsureOkrAccessEnabled, regla propia) ni
 * a /settings/guia-sistema (ayuda del sistema, sin datos financieros).
 */
class EnsureReporteriaAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->hasManagerialAccess()) {
            abort(403, 'Tu rol no tiene acceso a Reportería. Tu seguimiento está disponible en el módulo OKR.');
        }

        return $next($request);
    }
}
