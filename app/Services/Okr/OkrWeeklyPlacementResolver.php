<?php

namespace App\Services\Okr;

use App\Models\OkrObjective;
use App\Models\OkrPlacementUpload;

/**
 * Parte 6/8 del cierre (04-oct-2026) — colocación semanal real por Objective,
 * fuente canónica ÚNICA para el KPI 'reporteria.placement' cuando el
 * Objective (o su padre de sucursal, para un hijo individual) tiene AL MENOS
 * una carga semanal — ver hasAnyUpload(). Si nunca se ha usado la carga
 * semanal, OkrSnapshotService sigue con el proxy mensual de siempre (nunca
 * rompe Objectives existentes que no adoptaron esta función).
 *
 * weekly = colocación de ESA semana exacta. cumulative = suma de las semanas
 * 1..N — null (nunca 0) si CUALQUIERA de esas semanas no tiene dato real
 * (8.1/8.3: un acumulado que "brinca" una semana sin archivo sería un dato
 * inventado, no el acumulado real).
 */
class OkrWeeklyPlacementResolver
{
    public function hasAnyUpload(OkrObjective $objective): bool
    {
        if ($objective->placementUploads()->where('status', OkrPlacementUpload::STATUS_ACTIVE)->exists()) {
            return true;
        }

        if ($objective->scope_type === OkrObjective::SCOPE_EMPLOYEE && $objective->parent_id) {
            return OkrPlacementUpload::query()
                ->where('okr_objective_id', $objective->parent_id)
                ->where('status', OkrPlacementUpload::STATUS_ACTIVE)
                ->exists();
        }

        return false;
    }

    /**
     * @return array{weekly: ?float, cumulative: ?float, missing_weeks: int[], upload_id: ?int}
     */
    public function resolve(OkrObjective $objective, int $weekNumber): array
    {
        $weeklyValues = [];
        $missingWeeks = [];
        $uploadIdForWeek = null;

        for ($w = 1; $w <= $weekNumber; $w++) {
            [$value, $uploadId] = $this->weeklyValueFor($objective, $w);
            if ($value === null) {
                $missingWeeks[] = $w;
            } else {
                $weeklyValues[$w] = $value;
            }
            if ($w === $weekNumber) {
                $uploadIdForWeek = $uploadId;
            }
        }

        $cumulative = empty($missingWeeks) ? round(array_sum($weeklyValues), 2) : null;

        return [
            'weekly'        => $weeklyValues[$weekNumber] ?? null,
            'cumulative'    => $cumulative,
            'missing_weeks' => $missingWeeks,
            'upload_id'     => $uploadIdForWeek,
        ];
    }

    /** @return array{0: ?float, 1: ?int} [valor, upload_id] */
    private function weeklyValueFor(OkrObjective $objective, int $weekNumber): array
    {
        $ownUpload = OkrPlacementUpload::query()
            ->where('okr_objective_id', $objective->id)
            ->where('week_number', $weekNumber)
            ->where('status', OkrPlacementUpload::STATUS_ACTIVE)
            ->first();

        if ($ownUpload) {
            return [(float) $ownUpload->total_amount, $ownUpload->id];
        }

        // Hijo individual sin carga propia esa semana — se deriva del archivo
        // de LA SUCURSAL (6.4), nunca sumando aparte (6.5). Si la sucursal SÍ
        // cargó esa semana pero este colaborador no tiene fila, es un CERO
        // real (el archivo cubre a todos), no "falta información".
        if ($objective->scope_type === OkrObjective::SCOPE_EMPLOYEE && $objective->parent_id && $objective->employee_id) {
            $parentUpload = OkrPlacementUpload::query()
                ->where('okr_objective_id', $objective->parent_id)
                ->where('week_number', $weekNumber)
                ->where('status', OkrPlacementUpload::STATUS_ACTIVE)
                ->first();

            if ($parentUpload) {
                $sum = $parentUpload->movements()->where('employee_id', $objective->employee_id)->sum('amount');

                return [round((float) $sum, 2), $parentUpload->id];
            }
        }

        return [null, null];
    }
}
