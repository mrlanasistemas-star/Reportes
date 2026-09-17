<?php

namespace App\Services\Okr;

use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Services\CanonicalEmployeeResolver;
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
    public function __construct(private readonly CanonicalEmployeeResolver $canonicalResolver)
    {
    }

    /**
     * Empleados activos cuya asignación de sucursal MÁS RECIENTE es $branchId
     * (o de cualquier sucursal si $branchId es null — usado por el buscador
     * general del dashboard). Paginado por búsqueda (nunca la lista completa
     * — sección "performance UI" del pedido: un dropdown de cientos de
     * colaboradores es lento e inútil).
     *
     * BUG REAL corregido aquí (D1/D2 del cierre, 17-sep-2026): antes devolvía UNA
     * fila por cada registro `Employee` activo, sin canonicalizar — el mismo
     * colaborador real puede tener varios `Employee.id` históricos (fusión de
     * fuentes/periodos), todos `is_active=true` y con el MISMO
     * `normalized_name`. El dropdown mostraba "ADRIAN DAVID MUÑIZ VAZ..."
     * repetido una vez por cada ID histórico. Se agrupa aquí por
     * `normalized_name` — la MISMA identidad canónica que ya usa Reportería
     * (ver RadiographySnapshotBuilder::buildEmployeesGestores()::_employee_ids
     * y PersonIdentityResolverService::resolveBranchFromCanonicalEmployee()) —
     * nunca `distinct(full_name)` a ciegas: dos personas reales con el mismo
     * nombre pero `normalized_name` distinto (o el mismo nombre pero SIN
     * relación de identidad) siguen siendo dos opciones separadas.
     *
     * @return Collection<int, object{id:int, full_name:string, employee_ids:int[]}>
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

        $rows = $query->orderBy('id')->get(['id', 'full_name', 'normalized_name']);

        return $this->canonicalize($rows)->sortBy('full_name')->take($limit)->values();
    }

    /**
     * Agrupa filas de Employee por identidad canónica — delega en
     * CanonicalEmployeeResolver (extraído en el cierre 17-sep-2026, ronda 2, C2:
     * "una única identidad... debe ser la MISMA para Reportería/Excel/OKR").
     * Ver el docblock de esa clase para la regla exacta y su limitación conocida
     * (dos personas reales con el normalized_name IDÉNTICO se agruparían — igual
     * que en el resto de Reportería, no es un problema exclusivo de OKR).
     *
     * @param  \Illuminate\Support\Collection<int, Employee>  $rows
     * @return Collection<int, object{id:int, full_name:string, employee_ids:int[]}>
     */
    private function canonicalize(\Illuminate\Support\Collection $rows): Collection
    {
        return $this->canonicalResolver->canonicalize($rows);
    }

    /**
     * Total REAL de colaboradores activos de una sucursal (personas ÚNICAS, ver
     * canonicalize()) — sin límite de 30 y sin filtro de búsqueda, a diferencia
     * de employeesForBranch() (que es solo para el dropdown tipo buscador). Lo
     * usa el resumen del wizard de asignación: cuando NO se activa "OKR
     * individuales por vendedor", el Objective de sucursal aplica a TODOS los
     * colaboradores de esa sucursal, no solo a los que alcanzaron a aparecer en
     * el buscador limitado.
     */
    public function countForBranch(int $branchId): int
    {
        $rows = Employee::query()
            ->where('is_active', true)
            ->whereIn('id', $this->employeeIdsForBranch($branchId))
            ->get(['id', 'full_name', 'normalized_name']);

        return $this->canonicalize($rows)->count();
    }

    /**
     * Total REAL de colaboradores activos del sistema (personas ÚNICAS, ver
     * canonicalize()) — denominador correcto para la card "Colaboradores con
     * OKR" cuando NO hay filtro de sucursal (D12 del cierre, 17-sep-2026).
     * `Employee::where('is_active', true)->count()` a secas sufre el MISMO bug
     * de duplicados que employeesForBranch() tenía antes de canonicalizar.
     */
    public function countAllActive(): int
    {
        $rows = Employee::query()->where('is_active', true)->get(['id', 'full_name', 'normalized_name']);

        return $this->canonicalize($rows)->count();
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
