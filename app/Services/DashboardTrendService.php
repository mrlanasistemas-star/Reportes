<?php

namespace App\Services;

use App\Models\Period;
use App\Models\PeriodSummary;
use Illuminate\Support\Facades\Cache;

/**
 * Tendencia EBITDA/OPEX del dashboard — extraída de DashboardController (cierre
 * 17-sep-2026, ronda 6) para poder recalentar la caché desde un job en cuanto una
 * radiografía nueva termina de generarse, en vez de que el primer usuario que abre
 * el dashboard tenga que esperar ~6 buildSnapshot() encadenados en su propia
 * request (causa real de "tarda muchísimo"/timeout reportada en producción: PHP
 * puede fatalear por max_execution_time a la mitad de ese cálculo, dejando el
 * fetch del frontend sin JSON válido — ver DashboardController::trend()).
 */
class DashboardTrendService
{
    private const CACHE_TTL = 900; // 15 min
    private const PERIODS_COUNT = 6;

    public function __construct(private readonly RadiografiaExportService $exportService)
    {
    }

    /** Periodos mensuales reales (nunca de prueba/migración) con radiografía generada, más reciente primero. */
    public function availablePeriods()
    {
        $generatedPeriodIds = PeriodSummary::query()->where('status', 'generated')->pluck('period_id');

        return Period::query()
            ->where('type', 'monthly')
            ->where('year', '<=', now()->year)
            ->whereIn('id', $generatedPeriodIds)
            ->orderByDesc('year')->orderByDesc('month')
            ->get();
    }

    /** Construye (o reutiliza de caché) la tendencia de los últimos periodos del sistema. */
    public function build(): array
    {
        $periods = $this->availablePeriods();
        if ($periods->isEmpty()) {
            return [];
        }

        // ->all() (array PHP plano, nunca un objeto Collection) antes de cachear — el
        // driver 'database' de cache serializa/deserializa vía serialize() nativo de
        // PHP; un Collection devuelto por Cache::remember() en este contexto volvía
        // como __PHP_Incomplete_Class (confirmado con inspección directa de la fila en
        // la tabla `cache`, que sí tenía el valor correcto serializado — el problema
        // era la reconstrucción del objeto Collection en el mismo request, no el dato).
        // Un array plano no tiene esa ambigüedad de clase — json_encode() nunca falla.
        return Cache::remember($this->cacheKey($periods), self::CACHE_TTL, function () use ($periods) {
            return $periods->take(self::PERIODS_COUNT)->reverse()->map(function (Period $p) {
                $s = $this->exportService->buildSnapshot($p, ['scope' => 'general']);

                return [
                    'label'  => $p->label,
                    'ebitda' => round((float) ($s['summary']['ebitda_final'] ?? 0), 2),
                    'opex'   => round((float) ($s['summary']['opex_total'] ?? 0), 2),
                ];
            })->values()->all();
        });
    }

    private function cacheKey($periods): string
    {
        return 'dashboard_trend_' . $periods->take(self::PERIODS_COUNT)->pluck('id')->implode('_');
    }
}
