<?php

namespace App\Http\Middleware\Api;

use App\Models\ApiConsumer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fase 1.6 / 2.1 / 2.9 — permisos de lectura por recurso. Acepta una o más
 * abilities separadas por coma (`ensure.ability:employees.read,financial.read`)
 * — TODAS deben cumplirse, nunca basta con una sola cuando una ruta expone
 * datos de dos naturalezas distintas (p.ej. ficha financiera de un
 * colaborador: exige poder leer empleados Y datos financieros).
 *
 * Doble verificación por ability (Fase 2.9 — "un token nunca obtiene más
 * privilegios que su consumidor"):
 *   1. El TOKEN Sanctum debe tener la ability (`tokenCan()`).
 *   2. El CONSUMIDOR debe tener la ability en su propia lista (`hasAbility()`)
 *      — techo real, independiente de lo que el token diga tener. Si alguna
 *      vez se emite un token con más abilities de las que el consumidor
 *      tiene asignadas (p. ej. por un bug futuro en la emisión), esta capa lo
 *      bloquea igual.
 */
class EnsureApiAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $consumer = $request->user();

        if (!$consumer instanceof ApiConsumer || !$consumer->is_active) {
            return response()->json(['message' => 'Consumidor inactivo o no autorizado.'], 403);
        }

        foreach ($abilities as $ability) {
            if (!$consumer->tokenCan($ability) || !$consumer->hasAbility($ability)) {
                return response()->json(['message' => "El token no tiene el permiso requerido: {$ability}."], 403);
            }
        }

        return $next($request);
    }
}
