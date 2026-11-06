<?php

namespace App\Jobs;

use App\Models\Period;
use App\Services\EstadoResultados\Comparatives\ComparativesWarmer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Deja listas en caché las comparativas del Estado de Resultados de un periodo (y las bases
 * de Radiografía de sus periodos de contraste) en segundo plano, para que el usuario que abre
 * la pestaña «Comparativas» o las tarjetas con variación no pague el cálculo en frío.
 * Uno por periodo a la vez (ShouldBeUnique). No crítico: si no corre, la petición normal
 * calcula lo mismo, sólo más lenta.
 */
class WarmEstadoResultadosComparativesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    /** Ventana máxima del candado de unicidad (se libera antes al terminar el job). */
    public int $uniqueFor = 900;

    public function __construct(public int $periodId) {}

    public function uniqueId(): string
    {
        return 'er-comparativas-'.$this->periodId;
    }

    public function handle(ComparativesWarmer $warmer): void
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(600);

        $period = Period::query()->find($this->periodId);
        if (! $period) {
            return;
        }
        try {
            $diag = $warmer->warm($period);
            Log::info('ER: comparativas precalentadas', ['period' => $period->id, 'ms' => $diag['ms'] ?? null, 'periods' => $diag['periods'] ?? null]);
        } catch (\Throwable $e) {
            Log::warning('ER: no se pudieron precalentar las comparativas', ['period' => $period->id, 'error' => $e->getMessage()]);
        }
    }
}
