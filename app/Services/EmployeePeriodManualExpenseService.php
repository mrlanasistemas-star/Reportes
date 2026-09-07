<?php

namespace App\Services;

use App\Models\EmployeePeriodManualExpense;
use App\Models\PeriodSummary;

/**
 * ⚠️ DESCONECTADO DEL CÁLCULO — no usar para ningún flujo nuevo (reversión
 * 07-sep-2026, cierre). Este servicio y la tabla `employee_period_manual_expenses`
 * que administra quedan intactos (no se borran/truncan) pero YA NO tienen
 * ningún lector ni escritor activo en Web/Histórico/scoped-data/Excel/PDF/
 * radiografía — el "Gasto general por gestor" es ahora 100% EFÍMERO
 * (`manual_adjustment` viaja por request/config, nunca por BD). Ver
 * RadiographySnapshotBuilder::buildEmployeeExpenseDetail()/
 * applyGeneralManualAdjustment(), RadiografiaExportService::
 * resolveManualAdjustmentFor(), EmployeesHistoricoExportService::build(). Se
 * conserva el código por si se decide una limpieza/migración de borrado
 * separada más adelante — hasta entonces, ZERO READS / ZERO WRITES desde
 * cualquier cálculo real.
 *
 * ── Historia (frente 4, superada) ──────────────────────────────────────────
 * Fuente ÚNICA de lectura/escritura del "Gasto general por gestor" persistente
 * (auditoría 07-sep-2026, frente 4). Antes vivía solo en la config de cada
 * request (extra_employee_expense_amount/notes) — efímero, y la vista Web en
 * vivo (MonthlyReportController::scopedData()) nunca lo recibía, así que
 * divergía de Excel/PDF. Se guardaba UNA vez por (period_id, employee_id) —
 * updateOrCreate sobre el UNIQUE de la tabla. El cierre 07-sep-2026 revirtió
 * este diseño: el usuario determinó que el ajuste NUNCA debe modificar datos
 * guardados — debe volver a $0.00 al salir/reentrar al reporte.
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
