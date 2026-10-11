<?php

namespace App\Http\Middleware\Api;

use App\Models\ApiConsumer;
use App\Models\ApiQueryLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fase 1.6 / 3.3 — auditoría de consultas sensibles. Deliberadamente solo
 * registra METADATA (quién/qué recurso/qué alcance/cuándo/con qué status
 * respondió) — nunca el payload ni valores financieros de la respuesta (ver
 * migración create_api_query_logs_table).
 *
 * Fase 3.3 — dos protecciones de rendimiento añadidas tras la auditoría:
 *   1. `last_used_at` solo se escribe si cambió hace más de 60s — un
 *      consumidor con tráfico alto no debe generar un UPDATE en
 *      `api_consumers` en CADA petición (contención de fila en caliente).
 *   2. Toda la auditoría corre en try/catch: una falla al escribir el log
 *      JAMÁS debe aparecer como un error no controlado — la respuesta al
 *      cliente ya se envió antes de que `terminate()` se ejecute.
 */
class LogApiQuery
{
    private const LAST_USED_THROTTLE_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $consumer = $request->user();

        if (!$consumer instanceof ApiConsumer) {
            return;
        }

        try {
            $this->touchLastUsedAt($consumer);
            $this->recordQueryLog($request, $response, $consumer);
        } catch (\Throwable $e) {
            Log::warning('LogApiQuery: fallo al registrar auditoría de API (no afecta la respuesta ya enviada).', [
                'consumer_id' => $consumer->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    private function touchLastUsedAt(ApiConsumer $consumer): void
    {
        if ($consumer->last_used_at !== null && $consumer->last_used_at->gt(now()->subSeconds(self::LAST_USED_THROTTLE_SECONDS))) {
            return;
        }

        $consumer->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    private function recordQueryLog(Request $request, Response $response, ApiConsumer $consumer): void
    {
        $routePeriod = $request->route('periodId') ?? $request->route('period');
        $periodId = is_object($routePeriod) ? $routePeriod->getKey() : ($routePeriod ?: $request->query('period_id'));

        ApiQueryLog::query()->create([
            'api_consumer_id' => $consumer->id,
            'endpoint'        => $request->path(),
            'method'          => $request->method(),
            'response_status' => $response->getStatusCode(),
            'scope'           => $request->query('scope'),
            'branch_id'       => $request->query('branch_id') ?? $request->route('branchId'),
            'employee_id'     => $request->query('employee_id') ?? $request->route('employeeId'),
            'period_id'       => $periodId,
            'ip'              => $request->ip(),
            'created_at'      => now(),
        ]);
    }
}
