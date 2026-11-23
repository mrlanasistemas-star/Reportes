<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Comparte con las vistas Blade la preferencia de apariencia (claro/oscuro/
 * sistema) leída de la cookie "appearance", para que la plantilla raíz pinte
 * el tema correcto desde el primer render (sin parpadeo).
 */
class HandleAppearance
{
    /**
     * Publica la variable de vista `appearance` con el valor de la cookie o
     * "light" si el usuario nunca eligió (ver comentario interno).
     *
     * @param  Request  $request  Petición entrante.
     * @param  Closure(Request): (Response)  $next  Siguiente capa del pipeline.
     * @return Response Respuesta de la siguiente capa.
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
