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

    /** 16-oct-2026 — periodo recién generado cuya radiografía general se precalienta (comparativo/OKR). */
    public function __construct(public ?int $periodId = null)
    {
    }

    public function handle(DashboardTrendService $trendService): void
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(240);
        $jobStarted = microtime(true);

        try {
            $trendService->build();

            // El comparativo y la línea base OKR leen el snapshot GENERAL del
            // periodo (cacheado por summary): construirlo aquí evita que el primer
            // usuario espere ~10-25 s en pantalla.
            if ($this->periodId !== null && ($period = \App\Models\Period::query()->find($this->periodId))) {
                app(\App\Services\RadiografiaExportService::class)->buildSnapshot($period);
            }

            // Fase 7.1 — tendencia por sucursal: con la corrida general de cada periodo
            // ya cacheada (arriba), cada punto de sucursal es solo una proyección
            // (~0.2 s), así que se precalientan las 13 operativas. Con tope de tiempo:
            // si no alcanza, la request del usuario calcula lo que falte (igual de exacto).
            foreach (app(\App\Services\Reporting\OperativeBranchService::class)->names() as $name) {
                if (microtime(true) - $jobStarted > 200) {
                    break;
                }
                $branchId = \App\Models\Branch::query()->where('name', $name)->value('id');
                if ($branchId) {
                    $trendService->build((int) $branchId);
                }
            }
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
