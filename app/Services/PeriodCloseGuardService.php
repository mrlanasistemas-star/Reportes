<?php

namespace App\Services;

use App\Models\EmployeeBranchAssignment;
use App\Models\Expense;
use App\Models\MonthlyEmployeeSummary;
use App\Models\NoiMovement;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\ExpenseReview\ExpenseReviewService;

/**
 * Guardia de cierre de periodo: decide si un periodo puede marcarse como cerrado.
 *
 * En el negocio, cerrar un periodo significa congelarlo (ya no se re-importa ni se recalcula).
 * Antes de permitirlo se revisa que no queden pendientes de calidad de datos: archivos con
 * error, asignaciones de sucursal sin match, movimientos NOI de empleados sin sucursal,
 * gastos sin colaborador o sin sucursal (descontando los que el usuario confirmó en la
 * Revisión manual de gastos) y filas excluidas del consolidado mensual.
 *
 * Usado por: PeriodController::index() (para mostrar en la pantalla Periodos si se puede cerrar
 * y por qué no) y PeriodController::close() (bloquea el cierre si hay pendientes).
 * Solo lectura: no escribe en BD.
 */
class PeriodCloseGuardService
{
    /**
     * Evalúa todos los pendientes del periodo y devuelve si se puede cerrar.
     *
     * Reglas (cada una agrega un mensaje en español a `issues` si su conteo es > 0):
     *  1. report_uploads del periodo con status 'failed'.
     *  2. employee_branch_assignments sin branch_id o con match_type 'unmatched'.
     *  3. Movimientos NOI con empleado que no tiene ninguna asignación con sucursal en el periodo.
     *  4. Gastos (fact_expenses) sin employee_id, salvo los marcados 'sin_empleado' por revisión manual.
     *  5. Gastos sin branch_id, salvo los marcados 'sin_sucursal' por revisión manual.
     *  6. monthly_employee_summaries con included_in_report = false.
     *
     * Las reglas 4 y 5 solo descuentan la revisión manual si la migración está instalada
     * (ExpenseReviewService::installed()); sin ella, el conteo es el histórico.
     *
     * @param  Period  $period  Periodo a evaluar.
     * @return array{can_close: bool, issues: list<string>} can_close = true si no hay ningún pendiente.
     */
    public function canClose(Period $period): array
    {
        $issues = [];

        $failedUploads = ReportUpload::query()
            ->where('period_id', $period->id)
            ->where('status', 'failed')
            ->count();

        if ($failedUploads > 0) {
            $issues[] = "Hay {$failedUploads} archivo(s) con error de procesamiento.";
        }

        $unmatchedAssignments = EmployeeBranchAssignment::query()
            ->where('period_id', $period->id)
            ->where(function ($query) {
                $query->whereNull('branch_id')
                    ->orWhere('match_type', 'unmatched');
            })
            ->count();

        if ($unmatchedAssignments > 0) {
            $issues[] = "Hay {$unmatchedAssignments} asignación(es) de sucursal pendiente(s) o sin match.";
        }

        $noiWithoutAssignment = NoiMovement::query()
            ->where('period_id', $period->id)
            ->whereNotNull('employee_id')
            ->get()
            ->filter(function (NoiMovement $movement) use ($period) {
                return ! EmployeeBranchAssignment::query()
                    ->where('period_id', $period->id)
                    ->where('employee_id', $movement->employee_id)
                    ->whereNotNull('branch_id')
                    ->exists();
            })
            ->count();

        if ($noiWithoutAssignment > 0) {
            $issues[] = "Hay {$noiWithoutAssignment} movimiento(s) NOI con empleado sin sucursal asignada.";
        }

        // Revisión de gastos (23-nov-2026): lo que el usuario confirmó como «no pertenece a
        // ningún colaborador» / «sin sucursal» ya no cuenta como pendiente. Sin revisión
        // manual (columnas nulas) el conteo es idéntico al de siempre.
        $reviewInstalled = ExpenseReviewService::installed();

        $expensesWithoutEmployee = Expense::query()
            ->where('period_id', $period->id)
            ->whereNull('employee_id')
            ->when($reviewInstalled, fn ($q) => $q->where(fn ($w) => $w->whereNull('employee_review_status')->orWhere('employee_review_status', '!=', 'sin_empleado')))
            ->count();

        if ($expensesWithoutEmployee > 0) {
            $issues[] = "Hay {$expensesWithoutEmployee} gasto(s) sin empleado relacionado.";
        }

        $expensesWithoutBranch = Expense::query()
            ->where('period_id', $period->id)
            ->whereNull('branch_id')
            ->when($reviewInstalled, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_review_status')->orWhere('branch_review_status', '!=', 'sin_sucursal')))
            ->count();

        if ($expensesWithoutBranch > 0) {
            $issues[] = "Hay {$expensesWithoutBranch} gasto(s) sin sucursal relacionada.";
        }

        $excludedSummaries = MonthlyEmployeeSummary::query()
            ->where('period_id', $period->id)
            ->where('included_in_report', false)
            ->count();

        if ($excludedSummaries > 0) {
            $issues[] = "Hay {$excludedSummaries} fila(s) excluida(s) del consolidado.";
        }

        return [
            'can_close' => count($issues) === 0,
            'issues' => $issues,
        ];
    }
}
