<?php

namespace App\Services\Okr;

/**
 * Módulo OKR (08-sep-2026) — proyección de cierre. Determinístico y testeable
 * (ver docs/OKR.md, sección Proyección).
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
    public function projectCumulative(float $currentAccumulated, int $elapsedWeeks, int $totalWeeks): float
    {
        if ($elapsedWeeks <= 0) {
            return 0.0;
        }

        $rate = $currentAccumulated / $elapsedWeeks;

        return round($rate * $totalWeeks, 4);
    }

    /**
     * @param  array<int, float>  $historicalValues  actual_value de snapshots, en orden cronológico.
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
