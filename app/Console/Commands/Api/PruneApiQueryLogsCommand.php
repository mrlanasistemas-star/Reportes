<?php

namespace App\Console\Commands\Api;

use App\Models\ApiQueryLog;
use Illuminate\Console\Command;

/**
 * Fase 3.3 — retención CONFIGURABLE de `api_query_logs` (nunca crecimiento
 * ilimitado). Default 90 días, vía --days o API_QUERY_LOG_RETENTION_DAYS.
 * Borra en chunks para no bloquear la tabla con un DELETE masivo.
 */
class PruneApiQueryLogsCommand extends Command
{
    protected $signature = 'api:query-logs:prune {--days= : Días de retención (default: API_QUERY_LOG_RETENTION_DAYS o 90)}';

    protected $description = 'Elimina registros de api_query_logs más antiguos que el periodo de retención configurado';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? env('API_QUERY_LOG_RETENTION_DAYS', 90));
        $cutoff = now()->subDays($days);

        // Por lotes de IDs (no DELETE...LIMIT: no es portable entre MySQL/SQLite) —
        // nunca un solo DELETE masivo que bloquee la tabla bajo tráfico real.
        $total = 0;
        do {
            $ids = ApiQueryLog::query()->where('created_at', '<', $cutoff)->limit(1000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $total += ApiQueryLog::query()->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        $this->info("Eliminados {$total} registros de api_query_logs anteriores a {$cutoff->toDateString()} (retención: {$days} días).");

        return 0;
    }
}
