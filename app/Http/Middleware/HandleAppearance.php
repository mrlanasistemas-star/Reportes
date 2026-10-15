<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Fase 9 de la auditoría (15-oct-2026): por defecto modo claro, nunca
        // "system" — un usuario que jamás tocó esta preferencia no debe heredar
        // el modo oscuro del sistema operativo. Si YA eligió algo (claro, oscuro
        // o explícitamente "seguir sistema"), esa cookie sigue mandando siempre.
        View::share('appearance', $request->cookie('appearance') ?? 'light');

        return $next($request);
    }
}
