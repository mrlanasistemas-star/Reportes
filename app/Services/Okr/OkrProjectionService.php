<?php

namespace App\Services\Okr;

/**
 * Módulo OKR (08-sep-2026) — proyección de cierre. Determinístico y testeable
 * (ver docs/MANUAL-DESARROLLADOR.md, sección Proyección).
 *
 * cumulative: usa el RITMO observado (acumulado / semanas transcurridas) y lo
 * extrapola a las semanas totales del plazo — ej. meta 1,000,000 en 8 semanas,
 * semana 5, actual 510,000 → ritmo 102,000/semana → proyección 816,000.
 *
 * balance/percentage: usa la TENDENCIA de los snapshots semanales (pendiente
 * promedio entre puntos consecutivos); con menos de 2 puntos de historia hace
 * fallback conservador al valor actual (nunca proyecta de la nada).
 */
class OkrProjectionService
{
    /**
     * Proyección para KPIs acumulativos (colocación, recuperación...): extrapola
     * el ritmo semanal observado a la duración total del OKR.
     *
     * @param  float  $currentAccumulated  Valor acumulado a la fecha.
     * @param  int  $elapsedWeeks  Semanas transcurridas; si es <= 0 no hay ritmo y devuelve 0.
     * @param  int  $totalWeeks  Semanas totales del plazo.
     * @return float Valor proyectado al cierre (4 decimales).
     */
    public function projectCumulative(float $currentAccumulated, int $elapsedWeeks, int $totalWeeks): float
    {
        if ($elapsedWeeks <= 0) {
            return 0.0;
        }

        $rate = $currentAccumulated / $elapsedWeeks;

        return round($rate * $totalWeeks, 4);
    }

    /**
     * Proyección por tendencia para KPIs de saldo/porcentaje (cartera, mora...):
     * valor actual + delta promedio entre snapshots consecutivos × semanas restantes.
     * Con menos de 2 puntos o sin semanas restantes devuelve el valor actual.
     *
     * @param  array<int, float>  $historicalValues  actual_value de snapshots, en orden cronológico.
     * @param  float  $currentValue  Valor actual del KPI.
     * @param  int  $remainingWeeks  Semanas que faltan para el cierre.
     * @return float Valor proyectado al cierre (4 decimales).
     */
    public function projectTrend(array $historicalValues, float $currentValue, int $remainingWeeks): float
    {
        $n = count($historicalValues);
        if ($n < 2 || $remainingWeeks <= 0) {
            return round($currentValue, 4);
        }

        $deltas = [];
        for ($i = 1; $i < $n; $i++) {
            $deltas[] = $historicalValues[$i] - $historicalValues[$i - 1];
        }
        $avgDelta = array_sum($deltas) / count($deltas);

        return round($currentValue + $avgDelta * $remainingWeeks, 4);
    }

    /**
     * Convierte un valor proyectado en % de cumplimiento con la misma fórmula del
     * avance real (OkrProgressCalculator::rawProgress, sin tope).
     *
     * @param  float  $projectedValue  Valor proyectado al cierre.
     * @param  float|null  $baseline  Línea base del KR.
     * @param  float|null  $target  Meta del KR.
     * @param  string  $direction  Dirección del KR (incrementar/disminuir).
     * @param  OkrProgressCalculator  $calculator  Calculadora de avance a reutilizar.
     * @return float Porcentaje proyectado; 0.0 si no se puede calcular (base/meta faltantes).
     */
    public function projectedCompliancePercentage(
        float $projectedValue,
        ?float $baseline,
        ?float $target,
        string $direction,
        OkrProgressCalculator $calculator,
    ): float {
        return $calculator->rawProgress($baseline, $target, $projectedValue, $direction) ?? 0.0;
    }
}
