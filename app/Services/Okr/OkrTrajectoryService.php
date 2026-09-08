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
    /** Fracción de tiempo transcurrido, acotada a [0, 1]. */
    public function elapsedFraction(int $currentWeek, int $totalWeeks): float
    {
        if ($totalWeeks <= 0) {
            return 1.0;
        }

        return max(0.0, min(1.0, $currentWeek / $totalWeeks));
    }

    public function expectedValue(float $baseline, float $target, int $currentWeek, int $totalWeeks): float
    {
        $fraction = $this->elapsedFraction($currentWeek, $totalWeeks);

        return round($baseline + ($target - $baseline) * $fraction, 4);
    }

    public function expectedProgressPercentage(int $currentWeek, int $totalWeeks): float
    {
        return round($this->elapsedFraction($currentWeek, $totalWeeks) * 100, 2);
    }
}
