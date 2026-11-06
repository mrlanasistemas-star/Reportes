<?php

namespace App\Services\EstadoResultados\Comparatives;

use App\Jobs\WarmEstadoResultadosComparativesJob;
use App\Models\Period;
use Illuminate\Support\Facades\Log;

/**
 * Precalentado de las comparativas del Estado de Resultados (06-nov-2026).
 *
 * El primer cálculo de comparativas de un periodo necesita la base de Radiografía de cada
 * periodo de contraste (anterior, año anterior, últimos 6 meses, acumulado): varios segundos.
 * Este servicio arma EXACTAMENTE la misma consulta que abre la pestaña (modos por defecto,
 * consolidado, sin usuario = sin redacción) para que los resúmenes por periodo y las bases
 * queden en caché firmada. No cambia ninguna cifra: sólo adelanta el mismo cálculo.
 */
class ComparativesWarmer
{
    public function __construct(private readonly ComparisonService $service) {}

    /** @return array{ms:int, peak_memory_mb:int, periods:array} diagnóstico del cálculo */
    public function warm(Period $period): array
    {
        return $this->service->build($period, null, null, ['charts' => false])['diagnostics'];
    }

    /**
     * Encola el precalentado del periodo si la cola es asíncrona. Con la cola `sync` (pruebas,
     * entornos sin worker configurado) no hace nada: calentar en la misma petición sólo la haría
     * más lenta. Nunca falla la petición que lo pide.
     */
    public static function dispatchFor(Period $period): bool
    {
        if (! config('estado_resultados.cache.precalentar', true) || $period->type !== 'monthly'
            || config('queue.default') === 'sync') {
            return false;
        }
        try {
            WarmEstadoResultadosComparativesJob::dispatch($period->id)
                ->onQueue((string) config('estado_resultados.cache.precalentar_cola', 'default'));

            return true;
        } catch (\Throwable $e) {
            Log::warning('ER: no se pudo encolar el precalentado de comparativas', ['period' => $period->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
