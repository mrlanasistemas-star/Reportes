<?php

namespace App\Http\Responses;

use App\Services\Access\ModuleAccessService;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * config('fortify.home') = '/dashboard', pero no todos los roles ven el
 * Dashboard (14-oct-2026: módulos por rol configurables, ver
 * ModuleAccessService). Quien sí lo ve conserva el comportamiento de siempre
 * (incluida la URL "intended" si venía de un enlace directo); quien no,
 * aterriza en su primer módulo permitido (ej. un gerente solo con
 * Efectividad → /efectividad-cobranza, un colaborador → /okr).
 */
class LoginResponse implements LoginResponseContract
{
    /**
     * @param  ModuleAccessService  $access  Servicio que define módulos visibles y la URL de aterrizaje por rol.
     */
    public function __construct(private readonly ModuleAccessService $access) {}

    /**
     * Respuesta tras un login exitoso de Fortify. Para peticiones JSON
     * devuelve `{two_factor: false}`; si el usuario no tiene acceso al
     * Dashboard lo redirige a la URL "intended" o, si no hay, a su primer
     * módulo permitido; en otro caso, a la URL "intended" o al home de Fortify.
     *
     * @param  Request  $request  Petición del login.
     * @return Response JSON o redirección.
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $user = $request->user();
        if ($user && ! $this->access->allows($user, 'dashboard')) {
            return redirect()->intended($this->access->landingUrlFor($user));
        }

        return redirect()->intended(Fortify::redirects('login'));
    }
}
