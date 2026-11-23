<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra la sesión de una cuenta deshabilitada por un administrador
 * (`users.disabled_at`), aunque ya tuviera una sesión abierta o "recordarme".
 */
class EnsureUserNotDisabled
{
    /**
     * Si el usuario autenticado tiene `disabled_at` no nulo: cierra la sesión
     * del guard web, invalida la sesión y regenera el token CSRF. Responde 401
     * JSON a peticiones que esperan JSON (no Inertia) y, en otro caso,
     * redirige al login con un error en el campo "email".
     *
     * @param  Request  $request  Petición entrante.
     * @param  Closure  $next  Siguiente capa del pipeline.
     * @return Response Respuesta normal, 401 JSON o redirección al login.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->disabled_at !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => 'Tu cuenta está deshabilitada.'], 401);
            }

            return redirect()->route('login')->withErrors(['email' => 'Tu cuenta está deshabilitada. Contacta a un administrador.']);
        }

        return $next($request);
    }
}
