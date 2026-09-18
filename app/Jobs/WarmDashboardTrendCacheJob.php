<?php

namespace App\Jobs;

use App\Services\DashboardTrendService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Recalienta la caché de tendencia EBITDA/OPEX del dashboard en segundo plano justo
 * después de que una radiografía termina de generarse — para que el primer usuario
 * que abra el dashboard nunca sea quien paga, en su propia request HTTP, el costo de
 * recalcular hasta 6 buildSnapshot() encadenados (ver DashboardTrendService). Si el
 * job no llega a correr a tiempo (worker caído, etc.) no pasa nada grave: la request
 * normal sigue pudiendo calcularlo ella misma, solo más lenta.
 */
class WarmDashboardTrendCacheJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function handle(DashboardTrendService $trendService): void
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(240);

        try {
            $trendService->build();
        } catch (\Throwable $exception) {
            // No crítico: el dashboard sigue funcionando, solo pierde el
            // precalentamiento — nunca debe reintentar ni fallar el job padre.
            Log::warning('WarmDashboardTrendCacheJob: no se pudo precalentar la caché de tendencia.', [
                'exception' => get_class($exception),
                'message'   => $exception->getMessage(),
            ]);
        }
    }
}
