<?php

namespace App\Services\Okr;

use App\Models\Employee;
use App\Services\CanonicalEmployeeResolver;
use App\Services\Catalog\BranchRouteCatalog;
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
            // 16-oct-2026 — no aparecer en la nómina del periodo NO es una baja
            // confirmada (vacaciones, incapacidad, alta reciente, importación
            // incompleta). Se excluye: (a) la baja CONFIRMADA en la nómina vigente
            // (is_active_for_period = 0) y (b) quien no tiene ninguna fuente
            // vigente (ni nómina activa ni directorio ACTIVO — "posible baja").
            // Quien solo falta en NOI pero sigue ACTIVO en el directorio vigente
            // permanece elegible y se marca "Sin registro NOI del periodo".
            $query->whereNotIn('id', DB::table('period_employee_rosters')
                ->where('period_id', $rosterPeriodId)
                ->where('is_active_for_period', false)
                ->select('employee_id'));

            $activeInDirectory = $this->personnelResolver->activeDirectoryNames();
            $query->where(fn ($q) => $q
                ->whereIn('id', DB::table('period_employee_rosters')
                    ->where('period_id', $rosterPeriodId)
                    ->where('is_active_for_period', true)
                    ->select('employee_id'))
                ->when($activeInDirectory !== [], fn ($qq) => $qq->orWhereIn('normalized_name', $activeInDirectory)));
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
     * IDs de colaboradores ELEGIBLES cuya sucursal VIGENTE (membershipMap) es
     * $branchId — 16-oct-2026: una sola regla para el buscador, el conteo y
     * la validación de pertenencia.
     */
    private function employeeIdsForBranch(int $branchId): \Illuminate\Support\Collection
    {
        $ids = $this->eligibleEmployeesQuery()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return collect($this->membershipMap($ids))
            ->filter(fn ($m) => $m['branch_id'] === $branchId)
            ->keys()
            ->values();
    }

    /**
     * Sucursal VIGENTE de una persona (16-oct-2026, corrección definitiva del
     * caso DAVID GAMBOA CANO / CHOL y RAFAEL PEREZ CHAO / TOCH → ATLIXCO).
     *
     * Antes se tomaba la asignación de mayor `period_id` (los ids de periodo no
     * son cronológicos) con su `branch_id` tal cual: si esa fila apuntaba a un
     * registro de RUTA (CHOL/TOCH existen en `branches`) o a un periodo viejo,
     * el gestor se rechazaba con "no pertenece a la sucursal seleccionada".
     *
     * Ahora se reúnen las fuentes vigentes, se traduce cada una con el
     * catálogo oficial (ruta → sucursal) y gana la del PERIODO más reciente por
     * fecha; a igual fecha, en este orden de prioridad:
     *   1. Ruta en la cartera Lendus identificada por NÚMERO DE EMPLEADO
     *      (promoter_code = código del directorio, p. ej. ATLX12513). Una ruta
     *      INACTIVA no respalda una asignación nueva (se informa, no se usa).
     *   2. Nómina NOI del periodo (period_employee_rosters, activo).
     *   3. Asignación de Reportería (employee_branch_assignments).
     *   4. Directorio de colaboradores Lendus vigente (fila ACTIVO) — su
     *      sucursal se infiere solo del PREFIJO del código, que no basta por sí
     *      solo (empleados transferidos), por eso es el último recurso.
     * Nunca se exigen pagos ni colocación para pertenecer, ni se reescriben
     * periodos anteriores: un cambio de sucursal posterior simplemente es la
     * fuente más reciente.
     */
    public function currentBranchIdFor(int $employeeId): ?int
    {
        return $this->membershipFor($employeeId)['branch_id'];
    }

    public function employeeBelongsToBranch(int $employeeId, int $branchId): bool
    {
        return $this->currentBranchIdFor($employeeId) === $branchId;
    }

    /**
     * @return array{branch_id:?int, branch_name:?string, route:?string, inactive_route:?string, source:?string, source_label:?string}
     */
    public function membershipFor(int $employeeId): array
    {
        return $this->membershipMap([$employeeId])[$employeeId];
    }

    /** @var array<int, array> */
    private array $membershipCache = [];

    /**
     * Versión en lote de membershipFor() — número fijo de consultas.
     *
     * @param  int[]  $employeeIds
     * @return array<int, array{branch_id:?int, branch_name:?string, route:?string, inactive_route:?string, source:?string, source_label:?string}>
     */
    public function membershipMap(array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        $pending = array_values(array_filter($employeeIds, fn ($id) => !isset($this->membershipCache[$id])));

        if ($pending !== []) {
            $this->computeMemberships($pending);
        }

        return collect($employeeIds)->mapWithKeys(fn ($id) => [$id => $this->membershipCache[$id]])->all();
    }

    private function computeMemberships(array $employeeIds): void
    {
        $employees = Employee::query()->whereIn('id', $employeeIds)->get(['id', 'normalized_name'])->keyBy('id');
        $norms = $employees->pluck('normalized_name')->filter(fn ($n) => $n !== null && trim($n) !== '')->unique()->values()->all();

        // Grupo canónico (mismo normalized_name): la evidencia puede estar ligada a otro id histórico.
        $byNorm = $norms === [] ? collect() : Employee::query()->whereIn('normalized_name', $norms)->get(['id', 'normalized_name'])->groupBy('normalized_name');
        $groupOf = [];
        foreach ($employeeIds as $id) {
            $norm = $employees->get($id)?->normalized_name;
            $groupOf[$id] = $norm && isset($byNorm[$norm]) ? $byNorm[$norm]->pluck('id')->map(fn ($v) => (int) $v)->all() : [$id];
        }
        $allIds = array_values(array_unique(array_merge([], ...array_values($groupOf))));

        $officialId = $this->officialBranchIdResolver();
        $candidates = []; // employee_id => list<[prioridad, fecha_fin, branch_id, fuente, etiqueta, ruta]>
        $inactiveRoute = [];

        // 1. Ruta vigente en la cartera Lendus, por número de empleado.
        $codesByNorm = $this->personnelResolver->directoryCodesByName($norms);
        $allCodes = array_values(array_unique(array_merge([], ...array_values($codesByNorm))));
        $routesByCode = [];
        if ($allCodes !== []) {
            DB::table('fact_portfolios as f')
                ->join('periods as p', 'p.id', '=', 'f.period_id')
                ->whereIn('f.promoter_code', $allCodes)
                ->whereNotNull('f.route_name')
                ->select('f.promoter_code', 'f.route_name', 'f.branch_id', 'p.end_date', 'p.name as period_name', DB::raw('count(*) as n'))
                ->groupBy('f.promoter_code', 'f.route_name', 'f.branch_id', 'p.end_date', 'p.name')
                ->get()
                ->each(function ($r) use (&$routesByCode) {
                    $routesByCode[mb_strtoupper(trim((string) $r->promoter_code))][] = $r;
                });
        }

        foreach ($employeeIds as $id) {
            $norm = $employees->get($id)?->normalized_name;
            $rows = collect($codesByNorm[$norm] ?? [])->flatMap(fn ($code) => $routesByCode[$code] ?? []);
            if ($rows->isEmpty()) {
                continue;
            }
            $latestDate = $rows->max('end_date');
            $top = $rows->where('end_date', $latestDate)->sortByDesc('n')->first();
            $catalogRoute = BranchRouteCatalog::route($top->route_name);
            if ($catalogRoute !== null && !$catalogRoute['active']) {
                $inactiveRoute[$id] = $catalogRoute['route'];

                continue;
            }
            $branchId = $officialId($top->branch_id ? (int) $top->branch_id : null, BranchRouteCatalog::branchForRoute($top->route_name));
            if ($branchId !== null) {
                $candidates[$id][] = [1, (string) $latestDate, $branchId, 'portfolio_route', "Ruta {$top->route_name} · cartera Lendus {$top->period_name}", $top->route_name];
            }
        }

        // 2. Nómina NOI (activo en el periodo más reciente con registro).
        $rosters = DB::table('period_employee_rosters as r')
            ->join('periods as p', 'p.id', '=', 'r.period_id')
            ->whereIn('r.employee_id', $allIds)
            ->where('r.is_active_for_period', true)
            ->orderByDesc('p.end_date')
            ->get(['r.employee_id', 'r.branch_id', 'r.branch_name', 'p.end_date', 'p.name as period_name']);

        // 3. Asignación de Reportería — la más reciente POR FECHA, nunca por mayor id.
        $assignments = DB::table('employee_branch_assignments as a')
            ->join('periods as p', 'p.id', '=', 'a.period_id')
            ->whereIn('a.employee_id', $allIds)
            ->orderByDesc('p.end_date')
            ->get(['a.employee_id', 'a.branch_id', 'p.end_date', 'p.name as period_name']);

        // 4. Directorio Lendus vigente (fila ACTIVO; sucursal inferida por prefijo).
        $directory = $this->personnelResolver->currentDirectory();
        $dirRows = $this->personnelResolver->currentDirectoryRows($norms);

        foreach ($employeeIds as $id) {
            $group = $groupOf[$id];
            $r = $rosters->first(fn ($row) => in_array((int) $row->employee_id, $group, true));
            if ($r && ($b = $officialId($r->branch_id ? (int) $r->branch_id : null, $r->branch_name))) {
                $candidates[$id][] = [2, (string) $r->end_date, $b, 'noi_roster', "Nómina NOI {$r->period_name}", null];
            }

            $norm = $employees->get($id)?->normalized_name;
            $d = $norm ? ($dirRows[$norm] ?? null) : null;
            if ($d && $directory && mb_strtoupper((string) $d->estatus) === 'ACTIVO'
                && ($b = $officialId($d->inferred_branch_id ? (int) $d->inferred_branch_id : null, $d->inferred_branch_name))) {
                $candidates[$id][] = [4, (string) ($directory['period_end'] ?? ''), $b, 'lendus_directory', 'Directorio Lendus ' . ($directory['period_label'] ?? 'vigente'), null];
            }

            $a = $assignments->first(fn ($row) => in_array((int) $row->employee_id, $group, true));
            if ($a && ($b = $officialId((int) $a->branch_id, null))) {
                $candidates[$id][] = [3, (string) $a->end_date, $b, 'reporting_assignment', "Asignación de Reportería {$a->period_name}", null];
            }
        }

        $branchNames = \App\Models\Branch::query()->pluck('name', 'id');
        foreach ($employeeIds as $id) {
            // Fecha más reciente primero; a igual fecha, menor número de prioridad.
            $best = collect($candidates[$id] ?? [])->sort(fn ($x, $y) => [$y[1], $x[0]] <=> [$x[1], $y[0]])->first();

            $this->membershipCache[$id] = [
                'branch_id' => $best[2] ?? null,
                'branch_name' => $best ? ($branchNames[$best[2]] ?? null) : null,
                'route' => $best[5] ?? null,
                'inactive_route' => $inactiveRoute[$id] ?? null,
                'source' => $best[3] ?? null,
                'source_label' => $best[4] ?? null,
            ];
        }
    }

    /**
     * Traduce un branch_id/nombre (que puede ser una RUTA registrada como fila
     * de `branches`, p. ej. CHOL) a la sucursal OFICIAL. El nombre explícito
     * (p. ej. la sucursal de la ruta según catálogo) prevalece sobre el id.
     * Un registro de `branches` que no es ruta conocida se conserva tal cual
     * (nunca se descarta evidencia por no estar en el catálogo).
     *
     * @return \Closure(?int, ?string): ?int
     */
    private function officialBranchIdResolver(): \Closure
    {
        $branches = \App\Models\Branch::query()->get(['id', 'name']);
        $idByKey = $branches->mapWithKeys(fn ($b) => [BranchRouteCatalog::normalize($b->name) => (int) $b->id])->all();
        $nameById = $branches->pluck('name', 'id')->all();
        $routeResolver = app(\App\Services\BranchResolverService::class);

        return function (?int $branchId, ?string $name) use ($idByKey, $nameById, $routeResolver): ?int {
            $raw = $name ?? ($branchId !== null ? ($nameById[$branchId] ?? null) : null);
            if ($raw === null) {
                return null;
            }
            $official = BranchRouteCatalog::officialBranchFor($raw) ?? $routeResolver->resolveRealBranchFromRoute($raw);
            $officialId = $official !== null ? ($idByKey[BranchRouteCatalog::normalize($official)] ?? null) : null;

            return $officialId ?? ($branchId !== null && isset($nameById[$branchId]) ? $branchId : ($idByKey[BranchRouteCatalog::normalize($raw)] ?? null));
        };
    }

    /**
     * Señales laborales/operativas para la UI (16-oct-2026) — nunca una baja
     * inventada: "Sin registro NOI" o "Sin cartera en el periodo" no son una
     * baja confirmada; solo distinguen a quien no está produciendo.
     *
     * @param  int[]  $employeeIds
     * @return array<int, array<int, array{code:string, label:string}>>
     */
    public function statusFlags(array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($employeeIds === []) {
            return [];
        }

        $rosterPeriodId = $this->currentRosterPeriodId();
        $rosterLabel = $this->currentRosterPeriodLabel();
        $inRoster = $rosterPeriodId === null ? [] : array_flip(DB::table('period_employee_rosters')
            ->where('period_id', $rosterPeriodId)->where('is_active_for_period', true)
            ->pluck('employee_id')->map(fn ($v) => (int) $v)->all());

        $employees = Employee::query()->whereIn('id', $employeeIds)->get(['id', 'normalized_name'])->keyBy('id');
        $norms = $employees->pluck('normalized_name')->filter()->unique()->values()->all();
        $codesByNorm = $this->personnelResolver->directoryCodesByName($norms);
        $latestPortfolio = DB::table('fact_portfolios as f')->join('periods as p', 'p.id', '=', 'f.period_id')
            ->orderByDesc('p.end_date')->first(['f.period_id', 'p.name']);
        $producing = $latestPortfolio === null ? [] : array_flip(DB::table('fact_portfolios')
            ->where('period_id', $latestPortfolio->period_id)->whereNotNull('promoter_code')->distinct()
            ->pluck('promoter_code')->map(fn ($c) => mb_strtoupper(trim((string) $c)))->all());
        $memberships = $this->membershipMap($employeeIds);

        $flags = [];
        foreach ($employeeIds as $id) {
            $list = [];
            if ($rosterPeriodId !== null && !collect($this->canonicalIdsFor($id))->contains(fn ($g) => isset($inRoster[$g]))) {
                $list[] = ['code' => 'no_noi', 'label' => 'Sin registro NOI ' . ($rosterLabel ?? 'del periodo') . ' · vigencia por verificar'];
            }
            $codes = $codesByNorm[$employees->get($id)?->normalized_name] ?? [];
            if ($latestPortfolio && $codes !== [] && !collect($codes)->contains(fn ($c) => isset($producing[$c]))) {
                $list[] = ['code' => 'no_activity', 'label' => "Sin actividad en cartera ({$latestPortfolio->name})"];
            }
            if ($memberships[$id]['inactive_route']) {
                $list[] = ['code' => 'inactive_route', 'label' => "Ruta {$memberships[$id]['inactive_route']} inactiva (solo histórico)"];
            }
            if ($memberships[$id]['branch_id'] === null) {
                $list[] = ['code' => 'branch_pending', 'label' => 'Sucursal vigente pendiente de verificar'];
            }
            $flags[$id] = $list;
        }

        return $flags;
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
}
