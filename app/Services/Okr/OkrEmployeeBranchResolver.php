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
    public function __construct(
        private readonly CanonicalEmployeeResolver $canonicalResolver,
        private readonly OkrPersonnelResolver $personnelResolver,
    ) {
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
        $query = $this->eligibleEmployeesQuery();

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
        $rows = $this->eligibleEmployeesQuery()
            ->whereIn('id', $this->employeeIdsForBranch($branchId))
            ->get(['id', 'full_name', 'normalized_name']);

        return $this->canonicalize($rows)->count();
    }

    /**
     * Corrección 14-oct-2026 — "aparecen personas que ya no trabajan en la
     * empresa". Causa real verificada en BD: ningún importador apaga
     * `employees.is_active` (157/157 en true), así que ese campo SOLO refleja
     * una baja manual (ResponsibleController::disableAccess). La fuente
     * autorizada de estado laboral vigente es la nómina NOI ya procesada por
     * Reportería: `period_employee_rosters` del ÚLTIMO periodo cargado
     * (is_active_for_period=1 — PeriodEmployeeRosterService ya marca ahí las
     * bajas). Elegible = is_active (sin baja manual) Y presente como activo en
     * esa nómina vigente. Si todavía no hay ninguna nómina procesada (instalación
     * nueva), no existe otra fuente: se cae a is_active, nunca a "todos".
     */
    private function eligibleEmployeesQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = Employee::query()->where('is_active', true);
        $rosterPeriodId = $this->currentRosterPeriodId();

        if ($rosterPeriodId !== null) {
            $query->whereIn('id', DB::table('period_employee_rosters')
                ->where('period_id', $rosterPeriodId)
                ->where('is_active_for_period', true)
                ->select('employee_id'));
        }

        // Segunda señal de baja (15-oct-2026): en el directorio Lendus vigente
        // la persona solo aparece como INACTIVO.
        $inactiveOnly = $this->personnelResolver->inactiveOnlyNames();
        if ($inactiveOnly !== []) {
            $query->where(fn ($q) => $q->whereNull('normalized_name')->orWhereNotIn('normalized_name', $inactiveOnly));
        }

        // Plazas/vacantes (15-oct-2026, cierre de errores OKR): "VACANTE
        // SUBGERENTE MIACATLAN", "TEMIXCO VACANTE", etc. aparecen ACTIVO en el
        // directorio pero no son una persona real elegible para un OKR
        // individual.
        $vacancies = $this->personnelResolver->vacancyPlaceholderNames();
        if ($vacancies !== []) {
            $query->where(fn ($q) => $q->whereNull('normalized_name')->orWhereNotIn('normalized_name', $vacancies));
        }

        return $query;
    }

    /**
     * Versión en LOTE de employeeIsActive() — "Seguimiento por gestor" con N
     * hijos: número fijo de consultas, nunca 3 por gestor.
     *
     * @param  int[]  $employeeIds
     * @return array<int, bool> employee_id => activo
     */
    public function activeMap(array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($employeeIds === []) {
            return [];
        }

        $own = Employee::query()->whereIn('id', $employeeIds)->get(['id', 'normalized_name', 'is_active'])->keyBy('id');
        $norms = $own->pluck('normalized_name')->filter(fn ($n) => $n !== null && trim($n) !== '')->unique()->values()->all();
        $groupIds = Employee::query()->whereIn('normalized_name', $norms)->get(['id', 'normalized_name'])->groupBy('normalized_name');
        $allIds = $groupIds->flatten()->pluck('id')->merge($employeeIds)->unique()->all();
        $eligible = array_flip($this->eligibleEmployeesQuery()->whereIn('id', $allIds)->pluck('id')->map(fn ($v) => (int) $v)->all());

        $map = [];
        foreach ($employeeIds as $id) {
            $row = $own->get($id);
            if (!$row || !$row->is_active) {
                $map[$id] = false;
                continue;
            }
            $members = ($row->normalized_name && isset($groupIds[$row->normalized_name])) ? $groupIds[$row->normalized_name]->pluck('id')->all() : [$id];
            $map[$id] = collect($members)->contains(fn ($m) => isset($eligible[(int) $m]));
        }

        return $map;
    }

    /** Último periodo con nómina (roster) procesada, o null si no hay ninguna. */
    public function currentRosterPeriodId(): ?int
    {
        // 15-oct-2026: el periodo vigente es el de FECHA más reciente con nómina
        // procesada — nunca el mayor id (un periodo puede crearse después de
        // otro más nuevo, como ya ocurre con las cargas de Mayo/Junio).
        $id = DB::table('period_employee_rosters as r')
            ->join('periods as p', 'p.id', '=', 'r.period_id')
            ->orderByDesc('p.end_date')
            ->orderByDesc('p.id')
            ->value('r.period_id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Estado de la nómina vigente para la UI: si su periodo terminó hace más de
     * 45 días se advierte que las altas/bajas recientes pueden no reflejarse.
     *
     * @return array{label:?string, end_date:?string, is_stale:bool, days_old:?int}
     */
    public function rosterStatus(): array
    {
        $periodId = $this->currentRosterPeriodId();
        $period = $periodId ? \App\Models\Period::query()->find($periodId) : null;
        $daysOld = $period?->end_date ? (int) $period->end_date->diffInDays(now()) : null;

        return [
            'label' => $period?->label,
            'end_date' => $period?->end_date?->toDateString(),
            'is_stale' => $daysOld !== null && $daysOld > 45,
            'days_old' => $daysOld,
        ];
    }

    /** Etiqueta del periodo de nómina vigente — la UI la muestra para explicar por qué alguien no aparece. */
    public function currentRosterPeriodLabel(): ?string
    {
        $periodId = $this->currentRosterPeriodId();

        return $periodId !== null ? \App\Models\Period::query()->find($periodId)?->label : null;
    }

    /**
     * IDs de Employee de la MISMA persona (mismo normalized_name — regla de
     * CanonicalEmployeeResolver). La colocación importada puede haber quedado
     * ligada a otro id histórico de la misma persona: el seguimiento individual
     * suma por el grupo completo, nunca por coincidencia insegura de nombre libre.
     *
     * @return int[]
     */
    public function canonicalIdsFor(int $employeeId): array
    {
        $norm = Employee::query()->whereKey($employeeId)->value('normalized_name');
        if ($norm === null || trim($norm) === '') {
            return [$employeeId];
        }

        return Employee::query()->where('normalized_name', $norm)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
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
        $rows = $this->eligibleEmployeesQuery()->get(['id', 'full_name', 'normalized_name']);

        return $this->canonicalize($rows)->count();
    }

    /**
     * IDs de empleados cuya asignación más reciente apunta a $branchId, MÁS
     * los que todavía no tienen ninguna fila en `employee_branch_assignments`
     * pero el directorio de personal Lendus vigente ya resuelve a esa
     * sucursal (fallback, ver currentBranchIdFor()).
     */
    private function employeeIdsForBranch(int $branchId): \Illuminate\Support\Collection
    {
        $assignedBranchByEmployee = EmployeeBranchAssignment::query()
            ->whereIn('id', $this->latestAssignmentIdsSubquery())
            ->pluck('branch_id', 'employee_id');

        $fromAssignment = $assignedBranchByEmployee->filter(fn ($b) => (int) $b === $branchId)->keys();

        $fromDirectory = collect($this->personnelResolver->employeeIdsByInferredBranch($branchId))
            ->reject(fn ($id) => $assignedBranchByEmployee->has($id));

        return $fromAssignment->merge($fromDirectory)->unique()->values();
    }

    /**
     * Branch_id de la asignación MÁS RECIENTE del empleado (fuente
     * transaccional, `employee_branch_assignments`), o — si todavía no tiene
     * ninguna fila ahí (alta reciente, sin cobranza/colocación/nómina
     * procesada todavía) — el que ya resuelve el directorio de personal
     * Lendus vigente para esa persona (BranchResolverService: mismo catálogo
     * de equivalencias ruta/oficina → sucursal oficial que usa el resto de
     * Reportería). Corrección 15-oct-2026 (cierre de errores OKR): antes esta
     * ausencia de fila se interpretaba como "no pertenece a ninguna
     * sucursal" — rechazaba a colaboradores activos y correctamente
     * clasificados en el directorio (p. ej. oficina CHOL/TOCH → ATLIXCO)
     * solo porque aún no generaban movimientos transaccionales. Null = de
     * verdad no hay ninguna fuente con la que resolver su sucursal.
     */
    public function currentBranchIdFor(int $employeeId): ?int
    {
        $branchId = EmployeeBranchAssignment::query()
            ->whereIn('employee_id', $this->canonicalIdsFor($employeeId))
            ->orderByDesc('period_id')
            ->value('branch_id');

        if ($branchId !== null) {
            return (int) $branchId;
        }

        return $this->personnelResolver->profileFor($employeeId)['branch_id'];
    }

    public function employeeBelongsToBranch(int $employeeId, int $branchId): bool
    {
        return $this->currentBranchIdFor($employeeId) === $branchId;
    }

    /**
     * Parte 1.1 del cierre (04-oct-2026): "activo" es la MISMA regla que ya
     * usa employeesForBranch()/countForBranch() para el dropdown — se expone
     * aparte para que StoreObjectiveRequest pueda rechazar en BACKEND (nunca
     * solo ocultar en el wizard) un `employee_id` de alguien en baja enviado
     * directamente, sin depender de que el frontend ya lo haya filtrado.
     */
    public function employeeIsActive(int $employeeId): bool
    {
        // Misma regla que el buscador (eligibleEmployeesQuery) — evaluada sobre
        // el grupo canónico: el id enviado puede ser el histórico de la persona,
        // pero una baja manual explícita de ESE id nunca se ignora.
        if (!Employee::query()->whereKey($employeeId)->where('is_active', true)->exists()) {
            return false;
        }

        return $this->eligibleEmployeesQuery()->whereIn('id', $this->canonicalIdsFor($employeeId))->exists();
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
