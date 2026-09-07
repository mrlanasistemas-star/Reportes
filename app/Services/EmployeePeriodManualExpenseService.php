<?php

namespace App\Services;

use App\Models\EmployeePeriodManualExpense;
use App\Models\PeriodSummary;

/**
 * Fuente ÚNICA de lectura/escritura del "Gasto general por gestor" persistente
 * (auditoría 07-sep-2026, frente 4). Antes vivía solo en la config de cada
 * request (extra_employee_expense_amount/notes) — efímero, y la vista Web en
 * vivo (MonthlyReportController::scopedData()) nunca lo recibía, así que
 * divergía de Excel/PDF. Ahora:
 *   - Se guarda UNA vez por (period_id, employee_id) — updateOrCreate sobre el
 *     UNIQUE de la tabla, así que guardar dos veces nunca duplica ni suma.
 *   - RadiographySnapshotBuilder::buildEmployeeExpenseDetail() lo lee de aquí
 *     directamente (ya no recibe el monto por parámetro/config) — mismo dato
 *     para Web, Excel y PDF sin ninguna lógica adicional.
 *   - Al guardar, se "toca" el PeriodSummary del periodo para invalidar la
 *     única caché financiera del sistema (RadiografiaExportService::
 *     buildSnapshotCached(), cuya key incluye PeriodSummary->updated_at) — la
 *     siguiente lectura del snapshot del periodo recalcula con el valor nuevo,
 *     sin necesidad de F5 forzado ni de tocar el mecanismo de caché.
 */
class EmployeePeriodManualExpenseService
{
    /**
     * @return array{amount: float, notes: string}
     */
    public function getForPeriodEmployee(int $periodId, int $employeeId): array
    {
        $row = EmployeePeriodManualExpense::query()
            ->where('period_id', $periodId)
            ->where('employee_id', $employeeId)
            ->first();

        return [
            'amount' => $row ? (float) $row->amount : 0.0,
            'notes'  => $row ? (string) ($row->notes ?? '') : '',
        ];
    }

    /**
     * @return array{amount: float, notes: string}
     */
    public function upsert(int $periodId, int $employeeId, float $amount, string $notes, ?int $userId = null): array
    {
        $amount = round(max(0.0, $amount), 2);
        $notes  = $amount > 0 ? trim($notes) : trim($notes); // las notas se conservan aunque el monto sea 0 (ej. al limpiar)

        $existing = EmployeePeriodManualExpense::query()
            ->where('period_id', $periodId)
            ->where('employee_id', $employeeId)
            ->first();

        EmployeePeriodManualExpense::query()->updateOrCreate(
            ['period_id' => $periodId, 'employee_id' => $employeeId],
            [
                'amount'     => $amount,
                'notes'      => $notes,
                'created_by' => $existing?->created_by ?? $userId,
                'updated_by' => $userId,
            ]
        );

        $this->invalidatePeriodSnapshotCache($periodId);

        return ['amount' => $amount, 'notes' => $notes];
    }

    /**
     * Invalida RadiografiaExportService::buildSnapshotCached() (única caché
     * financiera del sistema) sin tocar el mecanismo de caché en sí — su key
     * incluye PeriodSummary->updated_at, así que "tocar" el summary basta para
     * que la siguiente lectura recalcule con el gasto manual recién guardado.
     */
    private function invalidatePeriodSnapshotCache(int $periodId): void
    {
        PeriodSummary::query()->where('period_id', $periodId)->update(['updated_at' => now()]);
    }
}
