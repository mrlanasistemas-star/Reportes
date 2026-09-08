<?php

namespace App\Services\Okr;

use App\Models\OkrObjective;

/**
 * Módulo OKR (08-sep-2026) — la suma de `weight` de los KR NO eliminados de un
 * Objective debe ser EXACTAMENTE 100. Validado en backend SIEMPRE (nunca solo
 * en Vue) — ver docs/OKR.md.
 */
class OkrWeightValidator
{
    private const TOLERANCE = 0.01; // margen por redondeo decimal, nunca por diseño laxo

    public function totalWeight(OkrObjective $objective): float
    {
        return round((float) $objective->keyResults()->sum('weight'), 2);
    }

    public function isValid(OkrObjective $objective): bool
    {
        return abs($this->totalWeight($objective) - 100.0) <= self::TOLERANCE;
    }

    /** @return array{total: float, remaining: float, is_valid: bool} */
    public function summary(OkrObjective $objective): array
    {
        $total = $this->totalWeight($objective);

        return [
            'total'     => $total,
            'remaining' => round(100.0 - $total, 2),
            'is_valid'  => abs($total - 100.0) <= self::TOLERANCE,
        ];
    }
}
