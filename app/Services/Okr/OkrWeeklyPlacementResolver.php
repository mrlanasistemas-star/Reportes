<?php

namespace App\Services\Okr;

use App\Models\Employee;
use App\Models\OkrObjective;
use App\Models\OkrPlacementUpload;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Parte 6/8 del cierre (04-oct-2026) — colocación semanal real por Objective,
 * fuente canónica ÚNICA para el KPI 'reporteria.placement' cuando el
 * Objective (o su padre de sucursal, para un hijo individual) tiene AL MENOS
 * una carga semanal — ver hasAnyUpload(). Si nunca se ha usado la carga
 * semanal, OkrSnapshotService sigue con el proxy mensual de siempre.
 *
 * weekly = colocación de ESA semana exacta. cumulative = suma de las semanas
 * 1..N — null (nunca 0) si CUALQUIERA de esas semanas no tiene dato real.
 *
 * Corrección 14-oct-2026 — UNA sola carga de evidencia para sucursal y gestores:
 *   - El valor de cada semana se suma desde los MOVIMIENTOS (no desde el total
 *     del archivo) descartando los que ya se contaron en una semana anterior
 *     (misma huella: contrato+fecha+importe, o gestor+fecha+importe). Un
 *     movimiento que reaparece en el reporte de la semana siguiente NO se
 *     suma dos veces.
 *   - El gestor individual se resuelve por su identidad canónica completa
 *     (todos sus Employee.id con el mismo normalized_name) — la colocación
 *     pudo quedar ligada a otro id histórico de la misma persona.
 *   - La sucursal suma TODOS los movimientos válidos (incluidos gestores sin
 *     OKR individual y filas sin gestor identificado); el gestor solo los
 *     suyos. Los mismos movimientos alimentan ambos niveles, nunca se cargan
 *     archivos aparte que luego se sumen entre sí.
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
        return $this->compute(collect([$objective]), $weekNumber)[$objective->id] ?? [
            'weekly' => null, 'cumulative' => null, 'missing_weeks' => $weekNumber >= 1 ? range(1, $weekNumber) : [], 'upload_id' => null, 'weeks' => [],
        ];
    }

    /**
     * Versión en LOTE (Parte 14 del cierre) — "Seguimiento por gestor" de una
     * sucursal con N hijos: un número fijo de consultas, nunca una por gestor.
     *
     * @param  Collection<int, OkrObjective>  $objectives
     * @return array<int, array{weekly: ?float, cumulative: ?float, missing_weeks: int[], upload_id: ?int, weeks: array<int, ?float>}>
     */
    public function resolveMany(Collection $objectives, int $weekNumber): array
    {
        return $this->compute($objectives, $weekNumber);
    }

    /**
     * @param  Collection<int, OkrObjective>  $objectives
     */
    private function compute(Collection $objectives, int $weekNumber): array
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
            ->get(['id', 'okr_objective_id', 'week_number', 'total_amount', 'coverage_status']);

        $uploadsByObjectiveWeek = [];
        foreach ($uploads as $upload) {
            $uploadsByObjectiveWeek[$upload->okr_objective_id][$upload->week_number] = $upload;
        }

        // Movimientos de todas esas cargas (una consulta) agrupados por carga.
        $movementsByUpload = [];
        if ($uploads->isNotEmpty()) {
            DB::table('okr_placement_movements')
                ->whereIn('okr_placement_upload_id', $uploads->pluck('id')->all())
                ->orderBy('id')
                ->get(['okr_placement_upload_id', 'employee_id', 'amount', 'fingerprint'])
                ->each(function ($m) use (&$movementsByUpload) {
                    $movementsByUpload[$m->okr_placement_upload_id][] = $m;
                });
        }

        $canonicalIdsByEmployee = $this->canonicalIdsByEmployee(
            $objectives->filter(fn ($o) => $o->scope_type === OkrObjective::SCOPE_EMPLOYEE && $o->employee_id)->pluck('employee_id')->unique()->values()->all(),
        );

        $results = [];
        foreach ($objectives as $objective) {
            $weeklyValues = [];
            $missingWeeks = [];
            $uploadIdForWeek = null;
            $seen = [];
            $isChild = $objective->scope_type === OkrObjective::SCOPE_EMPLOYEE && $objective->parent_id && $objective->employee_id;
            $employeeIds = $isChild ? ($canonicalIdsByEmployee[$objective->employee_id] ?? [(int) $objective->employee_id]) : null;

            for ($w = 1; $w <= $weekNumber; $w++) {
                $own = $uploadsByObjectiveWeek[$objective->id][$w] ?? null;
                if ($own) {
                    $weeklyValues[$w] = $this->sumUnseen($movementsByUpload[$own->id] ?? [], $seen);
                    if ($w === $weekNumber) {
                        $uploadIdForWeek = $own->id;
                    }

                    continue;
                }

                if ($isChild) {
                    $parentUpload = $uploadsByObjectiveWeek[$objective->parent_id][$w] ?? null;
                    if ($parentUpload) {
                        $rows = array_values(array_filter($movementsByUpload[$parentUpload->id] ?? [], fn ($m) => in_array((int) $m->employee_id, $employeeIds, true)));
                        // 18: sin fila propia Y archivo no exhaustivo → dato faltante, nunca 0 asumido.
                        if ($rows !== [] || $parentUpload->coverage_status === OkrPlacementUpload::COVERAGE_FULL) {
                            $weeklyValues[$w] = $this->sumUnseen($rows, $seen);
                            if ($w === $weekNumber) {
                                $uploadIdForWeek = $parentUpload->id;
                            }

                            continue;
                        }
                    }
                }

                $missingWeeks[] = $w;
            }

            $results[$objective->id] = [
                'weekly' => $weeklyValues[$weekNumber] ?? null,
                'cumulative' => empty($missingWeeks) ? round(array_sum($weeklyValues), 2) : null,
                'missing_weeks' => $missingWeeks,
                'upload_id' => $uploadIdForWeek,
                // Histórico semana a semana (null = sin dato) — para el seguimiento por gestor.
                'weeks' => array_map(fn ($w) => $weeklyValues[$w] ?? null, range(1, $weekNumber)),
            ];
        }

        return $results;
    }

    /** Suma los movimientos cuya huella no se contó en una semana anterior. */
    private function sumUnseen(array $movements, array &$seen): float
    {
        $sum = 0.0;
        $thisWeek = [];
        foreach ($movements as $m) {
            if (isset($seen[$m->fingerprint])) {
                continue; // reaparece de una semana anterior — ya contado
            }
            $thisWeek[$m->fingerprint] = true;
            $sum += (float) $m->amount;
        }
        $seen += $thisWeek;

        return round($sum, 2);
    }

    /**
     * @param  int[]  $employeeIds
     * @return array<int, int[]>
     */
    private function canonicalIdsByEmployee(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $norms = Employee::query()->whereIn('id', $employeeIds)->pluck('normalized_name', 'id');
        $groups = Employee::query()
            ->whereIn('normalized_name', $norms->filter(fn ($n) => $n !== null && trim($n) !== '')->values()->all())
            ->get(['id', 'normalized_name'])
            ->groupBy('normalized_name');

        $map = [];
        foreach ($employeeIds as $id) {
            $norm = $norms[$id] ?? null;
            $map[$id] = ($norm !== null && isset($groups[$norm]))
                ? $groups[$norm]->pluck('id')->map(fn ($v) => (int) $v)->all()
                : [(int) $id];
        }

        return $map;
    }
}
