<?php

namespace App\Services\Okr;

use App\Models\OkrKpi;

/**
 * Módulo OKR (08-sep-2026) — fuente ÚNICA de la fórmula de cumplimiento de un
 * Key Result (ver docs/MANUAL-DESARROLLADOR.md). NUNCA recalculada distinto en Dashboard/
 * Detalle/Excel/API — todo consumidor llama a esta clase.
 *
 * Incrementar (target > baseline, ej. EBITDA, colocación):
 *   raw = (current - baseline) / (target - baseline)
 *
 * Disminuir (target < baseline, ej. mora):
 *   raw = (baseline - current) / (baseline - target)
 *
 * target == baseline: sin rango que medir — nunca divide entre cero. Se
 * considera 100% si ya se alcanzó/superó la meta según la dirección, 0% si no.
 *
 * display vs weighted: display permite sobre-cumplimiento (134%) para que el
 * usuario VEA el dato real; weighted aplica un tope configurable (ver
 * DEFAULT_WEIGHTED_CAP) para que un KR sobre-cumplido no "tape" artificialmente
 * el incumplimiento de otros KR del mismo Objective — documentado y probado
 * (ver tests/Unit/Okr/OkrProgressCalculatorTest.php).
 */
class OkrProgressCalculator
{
    /** Tope (%) por defecto que puede aportar un KR al cumplimiento ponderado. */
    public const DEFAULT_WEIGHTED_CAP = 150.0;

    /**
     * Avance crudo del KR en % según la fórmula de la clase (puede ser negativo o > 100).
     *
     * @param  float|null  $baseline  Línea base.
     * @param  float|null  $target  Meta.
     * @param  float|null  $current  Valor actual del KPI.
     * @param  string  $direction  OkrKpi::DIRECTION_* (incrementar/disminuir).
     * @return float|null null solo si baseline/target/current no están disponibles.
     */
    public function rawProgress(?float $baseline, ?float $target, ?float $current, string $direction): ?float
    {
        if ($baseline === null || $target === null || $current === null) {
            return null;
        }

        if (abs($target - $baseline) < 1e-9) {
            if ($direction === OkrKpi::DIRECTION_DECREASE) {
                return $current <= $target ? 100.0 : 0.0;
            }

            return $current >= $target ? 100.0 : 0.0;
        }

        $raw = $direction === OkrKpi::DIRECTION_DECREASE
            ? ($baseline - $current) / ($baseline - $target)
            : ($current - $baseline) / ($target - $baseline);

        return round($raw * 100, 2);
    }

    /**
     * Avance para mostrar en pantalla: nunca negativo (un KR no "resta"
     * cumplimiento visualmente) — sí permite >100%.
     *
     * @param  float|null  $rawProgressPercentage  Avance crudo.
     * @return float|null Avance acotado a mínimo 0, o null si no hay dato.
     */
    public function displayProgress(?float $rawProgressPercentage): ?float
    {
        if ($rawProgressPercentage === null) {
            return null;
        }

        return max(0.0, $rawProgressPercentage);
    }

    /**
     * Contribución ponderada al cumplimiento del Objective — tope configurable
     * (nunca el KR compensa artificialmente el resto del OKR).
     *
     * @param  float|null  $rawProgressPercentage  Avance crudo del KR; null aporta 0.
     * @param  float  $weight  Peso del KR (0–100).
     * @param  float|null  $capPercentage  Tope de avance; null usa DEFAULT_WEIGHTED_CAP.
     * @return float Puntos que aporta el KR al cumplimiento del Objective (4 decimales).
     */
    public function weightedContribution(?float $rawProgressPercentage, float $weight, ?float $capPercentage = null): float
    {
        if ($rawProgressPercentage === null) {
            return 0.0;
        }

        $cap = $capPercentage ?? self::DEFAULT_WEIGHTED_CAP;
        $capped = max(0.0, min($rawProgressPercentage, $cap));

        return round(($capped / 100) * $weight, 4);
    }

    /**
     * Cumplimiento general del Objective — Σ(cumplimiento KR × ponderación KR).
     * ÚNICA función canónica — Dashboard/Detalle/Excel/API deben llamar esta,
     * nunca recalcular la suma por su cuenta.
     *
     * @param  array<int, array{raw_progress: ?float, weight: float}>  $keyResults  Avance crudo y peso de cada KR.
     * @param  float|null  $capPercentage  Tope por KR; null usa DEFAULT_WEIGHTED_CAP.
     * @return float Cumplimiento del Objective en % (2 decimales).
     */
    public function objectiveCompliance(array $keyResults, ?float $capPercentage = null): float
    {
        $total = 0.0;
        foreach ($keyResults as $kr) {
            $total += $this->weightedContribution($kr['raw_progress'] ?? null, (float) $kr['weight'], $capPercentage);
        }

        return round($total, 2);
    }

    /**
     * Desviación en puntos porcentuales — real - esperado.
     *
     * @param  float|null  $actualProgressPercentage  Avance real en %.
     * @param  float|null  $expectedProgressPercentage  Avance esperado a la fecha en %.
     * @return float|null Desviación en pp, o null si falta alguno de los dos.
     */
    public function deviationPp(?float $actualProgressPercentage, ?float $expectedProgressPercentage): ?float
    {
        if ($actualProgressPercentage === null || $expectedProgressPercentage === null) {
            return null;
        }

        return round($actualProgressPercentage - $expectedProgressPercentage, 2);
    }
}
