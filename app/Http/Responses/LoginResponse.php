<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;

/**
 * Parte 1 del cierre (04-oct-2026): config('fortify.home') = '/dashboard', pero
 * desde esta misma sesión /dashboard ya exige acceso managerial
 * (EnsureReporteriaAccess) — un colaborador que acabara de iniciar sesión
 * caería directo en un 403 si se mantuviera el redirect genérico. Un
 * colaborador aterriza en su módulo real (OKR); admin/gerencial conservan el
 * comportamiento de siempre (incluida la URL "intended" si venía de un
 * enlace directo).
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $user = $request->user();
        if ($user && !$user->hasManagerialAccess()) {
            return redirect()->route('okr.dashboard');
        }

        return redirect()->intended(Fortify::redirects('login'));
    }
}
