<?php

namespace App\Http\Middleware\Api;

use App\Models\ApiConsumer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fase 1.6 — permisos de lectura por recurso (ability del token Sanctum). Se
 * usa como `ensure.ability:financial.read` etc. en routes/api.php. Separado
 * de `EnsureBranchScope` porque una ability es "qué TIPO de dato puede leer
 * este consumidor", nunca "qué sucursal".
 */
class EnsureApiAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $consumer = $request->user();

        if (!$consumer instanceof ApiConsumer || !$consumer->is_active) {
            return response()->json(['message' => 'Consumidor inactivo o no autorizado.'], 403);
        }

        if (!$consumer->tokenCan($ability)) {
            return response()->json(['message' => "El token no tiene el permiso requerido: {$ability}."], 403);
        }

        return $next($request);
    }
}
