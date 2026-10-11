<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/health — sin autenticación, sin exposición sensible (Fase 1.2).
 * Nunca revela nombres de tablas, conteos de datos ni configuración interna.
 */
class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'status'  => 'ok',
            'service' => 'reportes-api',
            'version' => 'v1',
            'time'    => now()->toIso8601String(),
        ]);
    }
}
