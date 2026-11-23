<?php

namespace App\Services\Okr;

/**
 * Módulo OKR (08-sep-2026) — trayectoria esperada base→meta. NUNCA una cifra
 * manual: se calcula por interpolación lineal del tiempo transcurrido —
 * expectedValue(t) = baseline + (target - baseline) * elapsedFraction. La
 * dirección (incrementar/disminuir) queda implícita en el signo de
 * (target - baseline), nunca necesita una rama especial.
 */
class OkrTrajectoryService
{
    /**
     * Fracción de tiempo transcurrido, acotada a [0, 1].
     *
     * @param  int  $currentWeek  Semana actual del ciclo OKR (1..N).
     * @param  int  $totalWeeks  Total de semanas del ciclo; si es <= 0 se considera terminado (1.0).
     * @return float Proporción transcurrida entre 0 y 1.
     */
    public function elapsedFraction(int $currentWeek, int $totalWeeks): float
    {
        if ($totalWeeks <= 0) {
            return 1.0;
        }

        return max(0.0, min(1.0, $currentWeek / $totalWeeks));
    }

    /**
     * Valor esperado del KPI en la semana indicada según la trayectoria lineal base→meta.
     *
     * @param  float  $baseline  Valor base (punto de partida).
     * @param  float  $target  Meta del KR.
     * @param  int  $currentWeek  Semana actual del ciclo.
     * @param  int  $totalWeeks  Total de semanas del ciclo.
     * @return float Valor esperado redondeado a 4 decimales.
     */
    public function expectedValue(float $baseline, float $target, int $currentWeek, int $totalWeeks): float
    {
        $fraction = $this->elapsedFraction($currentWeek, $totalWeeks);

        return round($baseline + ($target - $baseline) * $fraction, 4);
    }

    /**
     * Porcentaje de avance esperado a la fecha (tiempo transcurrido × 100).
     *
     * @param  int  $currentWeek  Semana actual del ciclo.
     * @param  int  $totalWeeks  Total de semanas del ciclo.
     * @return float Porcentaje 0–100 redondeado a 2 decimales.
     */
    public function expectedProgressPercentage(int $currentWeek, int $totalWeeks): float
    {
        return round($this->elapsedFraction($currentWeek, $totalWeeks) * 100, 2);
    }
}
