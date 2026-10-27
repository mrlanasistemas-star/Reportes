<?php

namespace App\Http\Middleware;

use App\Support\Seo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * X-Robots-Tag en TODA respuesta (middleware global, 27-oct-2026): web, API,
 * descargas de reportes (PDF/Excel), /up, 404 y redirecciones. Solo las páginas
 * públicas de config/seo.php reciben "index, follow"; robots.txt y sitemap.xml
 * no llevan cabecera. Cubre lo que el <meta name="robots"> no puede (archivos
 * y JSON). Los archivos servidos directo por el servidor web (public/storage)
 * no pasan por Laravel: robots.txt los bloquea con Disallow.
 */
class AddRobotsHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->headers->has('X-Robots-Tag')) {
            $value = Seo::robotsHeaderFor($request);

            if ($value !== null) {
                $response->headers->set('X-Robots-Tag', $value);
            }
        }

        return $response;
    }
}
