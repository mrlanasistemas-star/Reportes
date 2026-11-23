<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/health — sin autenticación, sin exposición sensible (Fase 1.2).
 * Nunca revela nombres de tablas, conteos de datos ni configuración interna.
 *
 * Lo usan monitores externos y consumidores de la API para comprobar que el
 * servicio responde antes de hacer consultas autenticadas.
 */
class HealthController extends Controller
{
    /**
     * Responde un latido mínimo del servicio.
     *
     * No consulta la base de datos ni la caché; solo devuelve estado fijo,
     * nombre del servicio, versión de la API y la hora actual del servidor.
     *
     * @return JsonResponse JSON {status, service, version, time (ISO-8601)}.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'reportes-api',
            'version' => 'v1',
            'time' => now()->toIso8601String(),
        ]);
    }
}
