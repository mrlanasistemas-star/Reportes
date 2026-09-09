<?php

namespace App\Services\Okr;

use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Módulo OKR — CORRECCIÓN 08-sep-2026 (bug "empleado de otra sucursal"): antes
 * el wizard cargaba TODOS los empleados activos sin relación con la sucursal
 * elegida, y el backend no validaba que el `employee_id` enviado perteneciera
 * realmente a la `branch_id` del contexto. Este servicio reutiliza la MISMA
 * tabla que ya puebla EmployeeBranchAutoMatchService para el resto de
 * Reportería (`employee_branch_assignments`) — nunca una asignación nueva.
 *
 * "Pertenece a la sucursal" = su asignación MÁS RECIENTE (mayor period_id)
 * apunta a esa sucursal. Un empleado sin ninguna asignación registrada no
 * pertenece a ninguna sucursal todavía (no se asume nada).
 */
class OkrEmployeeBranchResolver
{
    /**
     * Empleados activos cuya asignación de sucursal MÁS RECIENTE es $branchId
     * (o de cualquier sucursal si $branchId es null — usado por el buscador
     * general del dashboard). Paginado por búsqueda (nunca la lista completa
     * — sección "performance UI" del pedido: un dropdown de cientos de
     * colaboradores es lento e inútil).
     *
     * @return Collection<int, Employee>
     */
    public function employeesForBranch(?int $branchId, ?string $search = null, int $limit = 30): Collection
    {
        $query = Employee::query()->where('is_active', true);

        if ($branchId !== null) {
            $query->whereIn('id', $this->employeeIdsForBranch($branchId));
        }

        if ($search !== null && trim($search) !== '') {
            $query->where('full_name', 'like', '%' . trim($search) . '%');
        }

        return $query->orderBy('full_name')->limit($limit)->get(['id', 'full_name']);
    }

    /**
     * Total REAL de colaboradores activos de una sucursal — sin límite de 30 y
     * sin filtro de búsqueda, a diferencia de employeesForBranch() (que es solo
     * para el dropdown tipo buscador). Lo usa el resumen del wizard de
     * asignación: cuando NO se activa "OKR individuales por vendedor", el
     * Objective de sucursal aplica a TODOS los colaboradores de esa sucursal,
     * no solo a los que alcanzaron a aparecer en el buscador limitado.
     */
    public function countForBranch(int $branchId): int
    {
        return Employee::query()
            ->where('is_active', true)
            ->whereIn('id', $this->employeeIdsForBranch($branchId))
            ->count();
    }

    /** IDs de empleados activos cuya asignación más reciente apunta a $branchId. */
    private function employeeIdsForBranch(int $branchId): \Illuminate\Support\Collection
    {
        return EmployeeBranchAssignment::query()
            ->whereIn('id', $this->latestAssignmentIdsSubquery())
            ->where('branch_id', $branchId)
            ->pluck('employee_id');
    }

    /** Branch_id de la asignación MÁS RECIENTE del empleado, o null si no tiene ninguna. */
    public function currentBranchIdFor(int $employeeId): ?int
    {
        return EmployeeBranchAssignment::query()
            ->where('employee_id', $employeeId)
            ->orderByDesc('period_id')
            ->value('branch_id');
    }

    public function employeeBelongsToBranch(int $employeeId, int $branchId): bool
    {
        return $this->currentBranchIdFor($employeeId) === $branchId;
    }

    /** IDs de la fila más reciente (mayor period_id) por employee_id. */
    private function latestAssignmentIdsSubquery(): \Illuminate\Support\Collection|array
    {
        return DB::table('employee_branch_assignments as eba')
            ->select(DB::raw('eba.id'))
            ->whereRaw('eba.period_id = (select max(eba2.period_id) from employee_branch_assignments eba2 where eba2.employee_id = eba.employee_id)')
            ->pluck('id');
    }
}
