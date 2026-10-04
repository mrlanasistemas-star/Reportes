<?php

namespace App\Services\Okr;

use App\Models\OkrObjective;
use App\Models\OkrPlacementUpload;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    /**
     * Parte 14 del cierre (04-oct-2026) — versión en LOTE de resolve() para
     * "Rendimiento por gestor" (sucursal con N hijos individuales, misma
     * semana para todos): 2 consultas totales en vez de hasta 2×N×semana —
     * nunca resuelve la colocación gestor por gestor contra la BD.
     *
     * @param  Collection<int, OkrObjective>  $objectives
     * @return array<int, array{weekly: ?float, cumulative: ?float, missing_weeks: int[]}>
     */
    public function resolveMany(Collection $objectives, int $weekNumber): array
    {
        if ($objectives->isEmpty() || $weekNumber < 1) {
            return [];
        }

        $objectiveIds = $objectives->pluck('id')->all();
        $parentIds = $objectives->pluck('parent_id')->filter()->unique()->values()->all();
        $allIds = array_values(array_unique(array_merge($objectiveIds, $parentIds)));

        $uploads = OkrPlacementUpload::query()
            ->whereIn('okr_objective_id', $allIds)
            ->where('status', OkrPlacementUpload::STATUS_ACTIVE)
            ->where('week_number', '<=', $weekNumber)
            ->get(['id', 'okr_objective_id', 'week_number', 'total_amount']);

        $uploadsByObjectiveWeek = [];
        foreach ($uploads as $upload) {
            $uploadsByObjectiveWeek[$upload->okr_objective_id][$upload->week_number] = ['amount' => (float) $upload->total_amount, 'id' => $upload->id];
        }

        $parentUploadIds = $uploads->whereIn('okr_objective_id', $parentIds)->pluck('id')->all();
        $movementSums = [];
        if (!empty($parentUploadIds)) {
            $rows = DB::table('okr_placement_movements')
                ->selectRaw('okr_placement_upload_id, employee_id, SUM(amount) as total')
                ->whereIn('okr_placement_upload_id', $parentUploadIds)
                ->whereNotNull('employee_id')
                ->groupBy('okr_placement_upload_id', 'employee_id')
                ->get();
            foreach ($rows as $row) {
                $movementSums[$row->okr_placement_upload_id][$row->employee_id] = (float) $row->total;
            }
        }

        $results = [];
        foreach ($objectives as $objective) {
            $weeklyValues = [];
            $missingWeeks = [];

            for ($w = 1; $w <= $weekNumber; $w++) {
                $own = $uploadsByObjectiveWeek[$objective->id][$w] ?? null;
                if ($own) {
                    $weeklyValues[$w] = $own['amount'];

                    continue;
                }

                if ($objective->scope_type === OkrObjective::SCOPE_EMPLOYEE && $objective->parent_id && $objective->employee_id) {
                    $parentUpload = $uploadsByObjectiveWeek[$objective->parent_id][$w] ?? null;
                    if ($parentUpload) {
                        $weeklyValues[$w] = round($movementSums[$parentUpload['id']][$objective->employee_id] ?? 0.0, 2);

                        continue;
                    }
                }

                $missingWeeks[] = $w;
            }

            $results[$objective->id] = [
                'weekly' => $weeklyValues[$weekNumber] ?? null,
                'cumulative' => empty($missingWeeks) ? round(array_sum($weeklyValues), 2) : null,
                'missing_weeks' => $missingWeeks,
            ];
        }

        return $results;
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
