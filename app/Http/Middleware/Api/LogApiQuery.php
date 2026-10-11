<?php

namespace App\Http\Middleware\Api;

use App\Models\ApiConsumer;
use App\Models\ApiQueryLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fase 1.6 — auditoría de consultas sensibles. Deliberadamente solo registra
 * METADATA (quién/qué recurso/qué alcance/cuándo/con qué status respondió) —
 * nunca el payload ni valores financieros de la respuesta (ver migración
 * create_api_query_logs_table).
 */
class LogApiQuery
{
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

        $consumer->forceFill(['last_used_at' => now()])->saveQuietly();

        $routePeriod = $request->route('period');
        $periodId = is_object($routePeriod) ? $routePeriod->getKey() : ($routePeriod ?: $request->query('period_id'));

        ApiQueryLog::query()->create([
            'api_consumer_id' => $consumer->id,
            'endpoint'        => $request->path(),
            'method'          => $request->method(),
            'response_status' => $response->getStatusCode(),
            'scope'           => $request->query('scope'),
            'branch_id'       => $request->query('branch_id'),
            'employee_id'     => $request->query('employee_id'),
            'period_id'       => $periodId,
            'ip'              => $request->ip(),
            'created_at'      => now(),
        ]);
    }
}
