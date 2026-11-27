<?php

namespace App\Services\HardData;

use App\Models\Branch;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\Access\DataScopeService;
use App\Services\Api\EmployeePeriodKpiService;
use App\Services\Catalog\BranchRouteCatalog;
use App\Services\People\PeopleMatcher;
use App\Services\People\PeopleRosterClient;

/**
 * Módulo «Datos duros» — resumen de las cifras finales por colaborador y periodo
 * SIN abrir la radiografía completa.
 *
 * NO calcula nada nuevo: proyecta las filas de EmployeePeriodKpiService (las
 * MISMAS fuentes que el Excel de colaboradores, la vista Web de un colaborador y
 * la API v1 — colocación, recuperación, cartera, mora, nómina, OPEX y EBITDA)
 * a una forma ligera para la pantalla, y aplica el alcance por sucursal del
 * usuario (DataScopeService) contra la sucursal HISTÓRICA del periodo.
 *
 * Esa fuente ya cachea 12 h por periodo + versión de radiografía, así que abrir
 * un periodo ya consultado es instantáneo y se invalida solo al regenerar.
 *
 * Puesto, sucursal y bajas vienen de MR LANA PEOPLE (fuente de verdad del
 * personal) cuando hay conexión; si no, de Reportería. El ALCANCE (qué filas ve
 * cada usuario) siempre se decide con la sucursal de Reportería.
 */
class HardDataService
{
    public function __construct(
        private readonly EmployeePeriodKpiService $kpis,
        private readonly DataScopeService $scope,
        private readonly PeopleRosterClient $people,
        private readonly PeopleMatcher $matcher,
    ) {}

    /**
     * Periodos con radiografía vigente (más reciente primero). Excluye periodos
     * de prueba con año futuro (mismo criterio que el Dashboard).
     *
     * @return list<array{id:int, name:string, range:?string, type:string, year:?int, month:?int}>
     */
    public function periods(): array
    {
        $ids = PeriodSummary::query()
            ->where('status', 'generated')
            ->whereNull('invalidated_at')
            ->pluck('period_id')
            ->unique();

        return Period::query()
            ->whereIn('id', $ids)
            ->where('year', '<=', now()->year)
            ->orderByDesc('start_date')
            ->get()
            ->map(fn (Period $p) => $this->describePeriod($p))
            ->values()
            ->all();
    }

    /**
     * Datos duros de un periodo para el usuario: filas ligeras + metadatos.
     *
     * @return array{period:array, status:string, message:?string, calculated_at:?string, rows:list<array>, scope:array}
     */
    public function dataset(Period $period, User $user): array
    {
        $result = $this->kpis->forPeriod($period);
        $allowed = $this->scope->allowedBranchIds($user);
        $canSee = $this->visibilityFilter($allowed);

        $inScope = collect($result['rows'])->filter($canSee);
        $roster = $this->people->roster();
        $this->matcher->load($roster['people']);
        $rows = $inScope
            ->filter(fn (array $r) => $this->workedInPeriod($r))
            ->map(fn (array $r) => $this->withPeople($this->slim($r)))
            ->values()
            ->all();
        $matched = collect($rows)->whereNotNull('people')->count();

        return [
            'period' => $this->describePeriod($period),
            'status' => $result['status'],
            'message' => $result['message'],
            'calculated_at' => $result['calculated_at'],
            'rows' => $rows,
            'hidden' => $inScope->count() - count($rows),
            'people' => [
                'configured' => $this->people->configured(),
                'connected' => $roster['connected'],
                'stale' => $roster['stale'],
                'synced_at' => $roster['synced_at'],
                'matched' => $matched,
                'unmatched' => count($rows) - $matched,
                'error' => $roster['error'] === null || $roster['error'] === 'not_configured' ? $roster['error'] : 'unreachable',
            ],
            'scope' => [
                'general' => $allowed === null,
                'label' => $this->scope->label($user),
            ],
        ];
    }

    /**
     * Un colaborador a lo largo de los periodos con radiografía (más antiguo
     * primero, máximo $limit). Solo periodos del mismo tipo que $type para que
     * la tendencia compare cosas comparables (mensual con mensual).
     *
     * @return list<array>
     */
    public function history(int $employeeId, User $user, string $type = 'monthly', int $limit = 12): array
    {
        $canSee = $this->visibilityFilter($this->scope->allowedBranchIds($user));

        $periods = Period::query()
            ->whereIn('id', collect($this->periods())->pluck('id'))
            ->where('type', $type)
            ->orderByDesc('start_date')
            ->limit($limit)
            ->get()
            ->reverse();

        return $periods->map(function (Period $period) use ($employeeId, $canSee) {
            $row = collect($this->kpis->forPeriod($period)['rows'])
                ->first(fn (array $r) => in_array($employeeId, $r['employee']['employee_ids'] ?? [$r['employee']['id'] ?? null], true));

            if ($row === null || ! $this->workedInPeriod($row) || ! $canSee($row)) {
                return ['period' => $this->describePeriod($period), 'present' => false];
            }

            $k = $row['kpis'];

            return [
                'period' => $this->describePeriod($period),
                'present' => true,
                'branch' => $this->officialBranch($row)['branch'],
                'placement' => $k['placement']['amount'],
                'recovery' => $k['recovery']['total'],
                'portfolio' => $k['portfolio']['total'],
                'overdue' => $k['portfolio']['overdue'],
                'mora_pct' => $k['portfolio']['mora_pct'],
                'payroll' => $k['payroll']['human_capital_total'],
                'opex' => $k['opex']['total'],
                'ebitda' => $k['ebitda']['ebitda'],
                'category' => $k['ebitda']['category'],
            ];
        })->values()->all();
    }

    /**
     * Sucursal OFICIAL de la fila (BranchRouteCatalog): varias asignaciones
     * apuntan a un branch que en realidad es una RUTA (CRUZ AZUL y ATOTONILCO
     * → TULA, CORDOBA PADELMA → CORDOBA, MAT-CEN → ATLIXCO…). Una ruta nunca
     * es sucursal: se muestra la sucursal dueña y la ruta pasa a la lista de
     * rutas. Si el nombre no está en el catálogo se deja tal cual (nunca se
     * adivina por parecido).
     *
     * @return array{branch:?string, route:?string}
     */
    private function officialBranch(array $r): array
    {
        $raw = $r['branch']['name'] ?? $r['report_branch_name'] ?? null;
        $official = BranchRouteCatalog::officialBranchFor($raw);

        if ($official === null) {
            foreach (BranchRouteCatalog::HISTORICAL_FINANCIAL_BRANCHES as $historical) {
                if (BranchRouteCatalog::normalize($historical) === BranchRouteCatalog::normalize($raw)) {
                    return ['branch' => $historical, 'route' => null];
                }
            }

            return ['branch' => $raw, 'route' => null];
        }

        $isRoute = BranchRouteCatalog::normalize($official) !== BranchRouteCatalog::normalize($raw);

        return ['branch' => $official, 'route' => $isRoute ? BranchRouteCatalog::route($raw)['route'] ?? $raw : null];
    }

    /**
     * Filtro de alcance: el branch asignado está permitido, o su sucursal
     * oficial es una de las sucursales permitidas (un gerente de TULA ve a quien
     * quedó asignado a la ruta CRUZ AZUL).
     *
     * @param  list<int>|null  $allowed
     * @return \Closure(array): bool
     */
    private function visibilityFilter(?array $allowed): \Closure
    {
        if ($allowed === null) {
            return fn (array $r) => true;
        }

        $allowedOfficial = Branch::query()->whereIn('id', $allowed)->pluck('name')
            ->map(fn ($name) => BranchRouteCatalog::normalize(BranchRouteCatalog::officialBranchFor($name) ?? $name))
            ->filter()->unique()->all();

        return fn (array $r) => in_array((int) ($r['branch_id'] ?? 0), $allowed, true)
            || in_array(BranchRouteCatalog::normalize($this->officialBranch($r)['branch']), $allowedOfficial, true);
    }

    /**
     * Solo cuentan quienes trabajaron el periodo: con ingreso base y con nómina
     * pagada. Sin ingresos o sin pago = baja / vacante, no se muestra (y no suma
     * en los totales de la pantalla).
     */
    private function workedInPeriod(array $r): bool
    {
        $k = $r['kpis'] ?? [];

        return (float) ($k['ebitda']['income_base'] ?? 0) > 0
            && (float) ($k['payroll']['human_capital_total'] ?? 0) > 0;
    }

    /**
     * Puesto, sucursal y estatus de People sobre la fila (si se empata). Se
     * conserva la sucursal de Reportería en `reportes_branch` para comparar.
     */
    private function withPeople(array $row): array
    {
        $row['reportes_branch'] = $row['branch'];
        $row['position'] = PeopleMatcher::position($row['position']);
        $row['people'] = null;
        $hit = $this->matcher->isEmpty() ? null : $this->matcher->match($row['code'], $row['name']);

        if ($hit === null) {
            return $row;
        }

        $p = $hit['person'];
        $row['position'] = PeopleMatcher::position($p['position']) ?? $row['position'];
        $row['branch'] = PeopleMatcher::officialBranch($p['branch']) ?? $row['branch'];

        if ($p['is_baja']) {
            $row['status'] = 'BAJA';
        }

        $row['people'] = [
            'via' => $hit['via'],
            'number' => $p['number'],
            'status' => $p['status'],
            'is_baja' => $p['is_baja'],
            'baja_date' => $p['baja_date'],
        ];

        return $row;
    }

    /** Forma compacta de una fila de EmployeePeriodKpiService para la pantalla. */
    private function slim(array $r): array
    {
        $k = $r['kpis'];
        $official = $this->officialBranch($r);
        $routes = collect($r['routes'] ?? [])->pluck('route')
            ->prepend($official['route'])
            ->filter()->unique()->values()->all();

        return [
            'id' => $r['employee']['id'],
            'name' => $r['employee']['full_name'] ?? $r['employee']['report_name'],
            'code' => $r['employee']['employee_code'] ?? null,
            'promoter_code' => $r['employee']['promoter_code'] ?? null,
            'position' => $r['employee']['position'] ?? null,
            'branch_id' => $r['branch_id'],
            'branch' => $official['branch'],
            'region' => $r['region'] ?? null,
            'routes' => $routes,
            'status' => $r['employment']['operational_status'] ?? null,
            'roster_label' => $r['employment']['roster_status_label'] ?? null,
            'placement' => $k['placement']['amount'],
            'operations' => $k['placement']['operations'],
            'placement_by_product' => $k['placement']['by_product'],
            'recovery' => $k['recovery']['total'],
            'recovery_components' => $k['recovery']['components'],
            'portfolio' => $k['portfolio']['total'],
            'overdue' => $k['portfolio']['overdue'],
            'current' => $k['portfolio']['current'],
            'mora_pct' => $k['portfolio']['mora_pct'],
            'buckets' => $k['portfolio']['buckets'],
            'payroll' => $k['payroll']['human_capital_total'],
            'perceptions' => $k['payroll']['noi_perceptions'],
            'deductions' => $k['payroll']['noi_deductions'],
            'payroll_concepts' => $k['payroll']['concepts'],
            'commissions' => $k['commissions'],
            'opex' => $k['opex']['total'],
            'opex_items' => $k['opex']['items'],
            'income_base' => $k['ebitda']['income_base'],
            'total_expenses' => $k['ebitda']['total_expenses'],
            'ebitda' => $k['ebitda']['ebitda'],
            'margin_pct' => $k['ebitda']['margin_pct'],
            'category' => $k['ebitda']['category'],
        ];
    }

    /** Nombre corto y rango por separado ("Junio 2026" / "25 may – 21 jun 2026"). */
    private function describePeriod(Period $p): array
    {
        $label = (string) $p->label;
        $name = $label;
        $range = null;

        if (preg_match('/^(.*?)\s*\((.+)\)\s*$/u', $label, $m)) {
            $name = trim($m[1]);
            $range = trim($m[2]);
        } elseif ($p->start_date && $p->end_date) {
            // Bimestres/trimestres: el nombre no trae fechas, se calculan.
            $range = Period::dateRangeLabel($p->start_date, $p->end_date);
        }

        return [
            'id' => $p->id,
            'name' => $name,
            'range' => $range,
            'type' => (string) $p->type,
            'year' => $p->year !== null ? (int) $p->year : null,
            'month' => $p->month !== null ? (int) $p->month : null,
        ];
    }
}
