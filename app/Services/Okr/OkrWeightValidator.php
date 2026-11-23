<?php

namespace App\Services\Okr;

use App\Models\OkrObjective;

/**
 * Módulo OKR (08-sep-2026) — la suma de `weight` de los KR NO eliminados de un
 * Objective debe ser EXACTAMENTE 100. Validado en backend SIEMPRE (nunca solo
 * en Vue) — ver docs/MANUAL-DESARROLLADOR.md.
 */
class OkrWeightValidator
{
    private const TOLERANCE = 0.01; // margen por redondeo decimal, nunca por diseño laxo

    /**
     * Suma los pesos de los KR del Objective (la relación keyResults excluye los eliminados).
     *
     * Efecto: consulta SUM sobre la BD.
     *
     * @param  OkrObjective  $objective  Objective a evaluar.
     * @return float Suma de pesos redondeada a 2 decimales.
     */
    public function totalWeight(OkrObjective $objective): float
    {
        return round((float) $objective->keyResults()->sum('weight'), 2);
    }

    /**
     * Indica si los pesos de los KR suman 100 (con tolerancia de redondeo).
     *
     * @param  OkrObjective  $objective  Objective a validar.
     * @return bool true si la suma está a ±0.01 de 100.
     */
    public function isValid(OkrObjective $objective): bool
    {
        return abs($this->totalWeight($objective) - 100.0) <= self::TOLERANCE;
    }

    /**
     * Resumen de pesos para la UI: total asignado, faltante para 100 y si es válido.
     *
     * @param  OkrObjective  $objective  Objective a resumir.
     * @return array{total: float, remaining: float, is_valid: bool}
     */
    public function summary(OkrObjective $objective): array
    {
        $total = $this->totalWeight($objective);

        return [
            'total' => $total,
            'remaining' => round(100.0 - $total, 2),
            'is_valid' => abs($total - 100.0) <= self::TOLERANCE,
        ];
    }
}
