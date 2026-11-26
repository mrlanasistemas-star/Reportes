<?php

namespace App\Services\HardData;

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\Access\DataScopeService;
use App\Services\Api\EmployeePeriodKpiService;

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
 */
class HardDataService
{
    public function __construct(
        private readonly EmployeePeriodKpiService $kpis,
        private readonly DataScopeService $scope,
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

        $inScope = collect($result['rows'])
            ->filter(fn (array $r) => $allowed === null || in_array((int) ($r['branch_id'] ?? 0), $allowed, true));
        $rows = $inScope
            ->filter(fn (array $r) => $this->workedInPeriod($r))
            ->map(fn (array $r) => $this->slim($r))
            ->values()
            ->all();

        return [
            'period' => $this->describePeriod($period),
            'status' => $result['status'],
            'message' => $result['message'],
            'calculated_at' => $result['calculated_at'],
            'rows' => $rows,
            'hidden' => $inScope->count() - count($rows),
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
        $allowed = $this->scope->allowedBranchIds($user);

        $periods = Period::query()
            ->whereIn('id', collect($this->periods())->pluck('id'))
            ->where('type', $type)
            ->orderByDesc('start_date')
            ->limit($limit)
            ->get()
            ->reverse();

        return $periods->map(function (Period $period) use ($employeeId, $allowed) {
            $row = collect($this->kpis->forPeriod($period)['rows'])
                ->first(fn (array $r) => in_array($employeeId, $r['employee']['employee_ids'] ?? [$r['employee']['id'] ?? null], true));

            if ($row === null || ! $this->workedInPeriod($row) || ($allowed !== null && ! in_array((int) ($row['branch_id'] ?? 0), $allowed, true))) {
                return ['period' => $this->describePeriod($period), 'present' => false];
            }

            $k = $row['kpis'];

            return [
                'period' => $this->describePeriod($period),
                'present' => true,
                'branch' => $row['branch']['name'] ?? $row['report_branch_name'] ?? null,
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

    /** Forma compacta de una fila de EmployeePeriodKpiService para la pantalla. */
    private function slim(array $r): array
    {
        $k = $r['kpis'];
        $routes = collect($r['routes'] ?? [])->pluck('route')->filter()->unique()->values()->all();

        return [
            'id' => $r['employee']['id'],
            'name' => $r['employee']['full_name'] ?? $r['employee']['report_name'],
            'code' => $r['employee']['employee_code'] ?? null,
            'promoter_code' => $r['employee']['promoter_code'] ?? null,
            'position' => $r['employee']['position'] ?? null,
            'branch_id' => $r['branch_id'],
            'branch' => $r['branch']['name'] ?? $r['report_branch_name'] ?? null,
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
