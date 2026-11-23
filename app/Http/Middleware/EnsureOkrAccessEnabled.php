<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * D5 del cierre, 17-sep-2026 — `users.access_enabled_at` existía SOLO como
 * etiqueta visual (Activo/Pendiente) en la pantalla "Responsables OKR"
 * (ResponsibleController::index()), sin ningún control de acceso real: el
 * Gate 'okr.view' (OkrServiceProvider) solo exigía `$user !== null`, así que
 * un responsable recién creado y AÚN sin habilitar podía entrar y operar todo
 * el módulo OKR igual que uno habilitado — la habilitación era pura
 * decoración.
 *
 * Regla, SOLO para el módulo OKR (nunca para el resto de Reportería):
 *   - admin (users.role === 'admin'): entra siempre.
 *   - no-admin: entra solo si access_enabled_at !== null.
 *   - pending (no-admin sin access_enabled_at): 403 al módulo OKR.
 */
class EnsureOkrAccessEnabled
{
    /**
     * Aplica la regla descrita en la clase: admin pasa siempre; un no-admin
     * con `access_enabled_at` nulo recibe 403. Sin usuario, deja pasar.
     *
     * @param  Request  $request  Petición a una ruta del módulo OKR.
     * @param  Closure  $next  Siguiente capa del pipeline.
     * @return Response Respuesta de la siguiente capa.
     *
     * @throws HttpException 403 si el acceso OKR está pendiente.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ($user->role ?? null) !== 'admin' && $user->access_enabled_at === null) {
            abort(403, 'Tu acceso al módulo OKR está pendiente de habilitación por un administrador.');
        }

        return $next($request);
    }
}
