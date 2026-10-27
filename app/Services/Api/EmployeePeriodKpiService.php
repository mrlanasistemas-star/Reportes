<?php

namespace App\Services\Api;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\Catalog\BranchRouteCatalog;
use App\Services\EmployeeNameCanonicalizer;
use App\Services\OpexClassificationService;
use App\Services\Radiography\BranchRadiographyCalculator;
use App\Services\Radiography\RadiographySnapshotBuilder;
use App\Services\Roster\RosterMovementStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * API v1 — KPIs por COLABORADOR y PERIODO (exportación "colaborador por
 * periodos"). Solo lectura: nunca escribe en BD.
 *
 * NO es una fórmula nueva. Reutiliza, pieza por pieza, la MISMA fuente que el
 * "Excel de colaboradores" del reporte mensual
 * (Radiography\EmployeesHistoricoExportService) y la vista Web de un
 * colaborador (RadiographySnapshotBuilder::applyEmployeeScope()):
 *
 *   - Filas: RadiographySnapshotBuilder::buildAllEmployeeGestorRows() — el MISMO
 *     buildEmployeesGestores() (recuperación/colocación/cartera/vencida/nómina/
 *     ingreso base EBITDA, con la fusión de identidad por nombre canónico).
 *   - OPEX automático: OpexClassificationService (eligible_for_attribution), una
 *     sola consulta agrupada por employee_id+categoría+concepto — idéntica a la
 *     del Excel de colaboradores.
 *   - EBITDA/margen/mora: RadiographySnapshotBuilder::computeEmployeeFinancialMetrics()
 *     — la función ÚNICA que usan Web/Excel/PDF.
 *   - Nómina por concepto: BranchRadiographyCalculator::classifyPercepcionConcept()
 *     — misma partición que buildEmployeePayrollDetail().
 *   - Estado operativo ACTIVO/BAJA: misma regla que el Excel (ingreso real u
 *     OPEX automático > 0).
 *
 * El ajuste manual de gasto del reporte es EFÍMERO (nunca se persiste), así que
 * la API siempre entrega la cifra OFICIAL (manual = 0).
 *
 * Atribución (sucursal/ruta) — la sucursal del periodo es la asignación
 * registrada en employee_branch_assignments para ESE periodo (misma regla que
 * EmployeeBranchHistoryResolver); la sucursal institucional sale de
 * branches.sucursal_id (nunca del nombre). Las rutas salen de la asignación
 * (si el branch asignado es una ruta del catálogo oficial) y de la cartera
 * Lendus del periodo (fact_portfolios.route_name) del gestor.
 *
 * Caché: 12 h por periodo + versión de la radiografía vigente (summary id +
 * updated_at) — mismo criterio de invalidación que RadiografiaExportService.
 */
class EmployeePeriodKpiService
{
    public const STATUS_OK = 'ok';

    public const STATUS_NOT_AVAILABLE = 'not_available';

    private const CACHE_TTL = 43_200;

    private const CACHE_VERSION = 'v1';

    /** Categorías canónicas de percepción NOI (mismas claves que BranchRadiographyCalculator). */
    private const PAYROLL_BUCKETS = [
        'nomina_total' => 'sueldo',
        'comisiones' => 'comisiones',
        'bonos' => 'bonos',
        'bonos_aceleradores' => 'bonos_aceleradores',
        'vacaciones' => 'vacaciones',
        'prima_vacacional' => 'prima_vacacional',
        'otros_percepciones' => 'otras_percepciones',
    ];

    /** @var array<int, array> memo por petición */
    private array $memo = [];

    public function __construct(
        private readonly RadiographySnapshotBuilder $snapshotBuilder,
        private readonly OpexClassificationService $opexClassifier,
        private readonly EmployeeNameCanonicalizer $canonicalizer,
    ) {}

    /**
     * @return array{status:string, message:?string, calculated_at:?string, radiography_version:?int, rows:list<array>, unidentified:list<array>}
     */
    public function forPeriod(Period $period): array
    {
        if (isset($this->memo[$period->id])) {
            return $this->memo[$period->id];
        }

        $summary = $this->currentSummary($period);
        if ($summary === null) {
            return $this->memo[$period->id] = [
                'status' => self::STATUS_NOT_AVAILABLE,
                'message' => "No existe una radiografía vigente para el periodo {$period->label}. Genérala desde el reporte mensual.",
                'calculated_at' => null,
                'radiography_version' => null,
                'rows' => [],
                'unidentified' => [],
            ];
        }

        $key = sprintf(
            'api_v1_employee_period_kpis:%s:%d:%d:%d',
            self::CACHE_VERSION,
            $period->id,
            $summary->id,
            $summary->updated_at?->timestamp ?? 0
        );

        return $this->memo[$period->id] = Cache::remember($key, self::CACHE_TTL, fn () => $this->compute($period, $summary));
    }

    /**
     * Efectividad de cobranza (histórica) de UN colaborador en un periodo — la
     * MISMA llamada que hace applyEmployeeScope() del reporte
     * (buildEfectividadCobranza(periodo, nombre de la fila)). Es costosa (lee
     * el DPD de toda la cartera), por eso solo se ofrece en el endpoint de un
     * colaborador (/employees/{id}/periods), nunca en listados masivos.
     */
    public function effectivenessFor(Period $period, string $reportName): array
    {
        $summary = $this->currentSummary($period);
        $key = sprintf(
            'api_v1_employee_effectiveness:%s:%d:%d:%d:%s',
            self::CACHE_VERSION,
            $period->id,
            $summary?->id ?? 0,
            $summary?->updated_at?->timestamp ?? 0,
            md5(mb_strtoupper(trim($reportName)))
        );

        return Cache::remember($key, self::CACHE_TTL, function () use ($period, $reportName) {
            $this->snapshotBuilder->warmDataIds($period);
            $result = $this->snapshotBuilder->buildEfectividadCobranza($period, $reportName);
            $eff = $result['efectividad'] ?? [];

            return [
                'status' => ($eff['efectividad_pct'] ?? null) === null ? 'not_available' : 'ok',
                'effectiveness_pct' => $eff['efectividad_pct'] ?? null,
                'recovered_from_overdue' => $eff['recuperado_de_mora'] ?? null,
                'previous_period_overdue_portfolio' => $eff['cartera_mora_periodo_anterior'] ?? null,
                'previous_period_label' => $eff['periodo_anterior_label'] ?? null,
                'composition' => [
                    'current' => round((float) ($result['vigente']['total'] ?? 0), 2),
                    'late' => round((float) ($result['atrasado']['total'] ?? 0), 2),
                    'overdue' => round((float) ($result['vencido']['total'] ?? 0), 2),
                ],
                'source' => 'RadiographySnapshotBuilder::buildEfectividadCobranza(periodo, colaborador)',
                'note' => ($eff['efectividad_pct'] ?? null) === null
                    ? 'Sin cartera en mora del mes anterior para este colaborador: la efectividad no se puede calcular (nunca se reporta 0%).'
                    : null,
            ];
        });
    }

    public function currentSummary(Period $period): ?PeriodSummary
    {
        return PeriodSummary::query()
            ->where('period_id', $period->id)
            ->where('status', 'generated')
            ->whereNull('invalidated_at')
            ->latest('id')
            ->first();
    }

    private function compute(Period $period, PeriodSummary $summary): array
    {
        $gestorRows = $this->snapshotBuilder->buildAllEmployeeGestorRows($period);
        $dataIds = $this->snapshotBuilder->resolveDataIdsPublic($period);

        $allIds = [];
        foreach ($gestorRows as $row) {
            foreach ($row['_employee_ids'] ?? [] as $id) {
                $allIds[(int) $id] = true;
            }
        }
        $allIds = array_keys($allIds);

        // ── OPEX automático — MISMA consulta/clasificación que el Excel de colaboradores.
        $opexByEmployee = [];
        $opexPureByEmployee = [];
        $opexItemsByEmployee = [];
        DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->selectRaw("employee_id, COALESCE(category,'') as category, COALESCE(concept,'') as concept, SUM(COALESCE(NULLIF(paid_amount,0), amount)) as total")
            ->groupBy('employee_id', 'category', 'concept')
            ->get()
            ->each(function ($r) use (&$opexByEmployee, &$opexPureByEmployee, &$opexItemsByEmployee) {
                $classification = $this->opexClassifier->classify($r->category, $r->concept, OpexClassificationService::SOURCE_LENDUS);
                if (! $classification['eligible_for_attribution']) {
                    return;
                }
                $eid = (int) $r->employee_id;
                $amount = (float) $r->total;
                $opexByEmployee[$eid] = ($opexByEmployee[$eid] ?? 0.0) + $amount;
                if ($classification['is_opex']) {
                    $opexPureByEmployee[$eid] = ($opexPureByEmployee[$eid] ?? 0.0) + $amount;
                }
                $opexItemsByEmployee[$eid][] = [
                    'category' => (string) $r->category,
                    'concept' => (string) $r->concept,
                    'amount' => round($amount, 2),
                    'type' => $classification['is_opex'] ? 'opex' : 'nomina_empleado',
                ];
            });

        // ── NOI por colaborador y concepto — UNA consulta (percepciones/deducciones).
        $noi = [];
        if ($allIds !== []) {
            foreach (array_chunk($allIds, 500) as $chunk) {
                DB::table('fact_noi_movements')
                    ->whereIn('period_id', $dataIds)
                    ->whereIn('employee_id', $chunk)
                    ->whereIn('concept_type', ['percepcion', 'deduccion'])
                    ->selectRaw("employee_id, concept_type, COALESCE(concept, 'Sin concepto') as concept, SUM(amount) as total")
                    ->groupBy('employee_id', 'concept_type', 'concept')
                    ->get()
                    ->each(function ($r) use (&$noi) {
                        $noi[(int) $r->employee_id][] = ['type' => (string) $r->concept_type, 'concept' => (string) $r->concept, 'amount' => (float) $r->total];
                    });
            }
        }

        // ── Identidad, asignación del periodo y roster — en lote.
        $employees = $allIds === [] ? collect() : Employee::query()
            ->whereIn('id', $allIds)
            ->get(['id', 'employee_code', 'full_name', 'position', 'is_active', 'source_system'])
            ->keyBy('id');

        $assignmentByEmployee = $this->assignmentsFor($period, $allIds);

        $branches = Branch::query()->with('sucursal')->get()->keyBy('id');
        $branchByName = [];
        foreach ($branches as $b) {
            $branchByName[mb_strtoupper(trim((string) $b->name))] ??= $b;
        }

        $rosterByEmployee = $allIds === [] ? collect() : DB::table('period_employee_rosters')
            ->where('period_id', $period->id)
            ->whereIn('employee_id', $allIds)
            ->get()
            ->keyBy('employee_id');

        [$routesByNorm, $routesByCode] = $this->portfolioRoutes($dataIds);

        $rows = [];
        $unidentified = [];
        foreach ($gestorRows as $row) {
            $ids = array_values(array_unique(array_map('intval', $row['_employee_ids'] ?? [])));
            if ($ids === []) {
                // El Excel de colaboradores tampoco los exporta: no hay identidad de
                // colaborador (gestor que solo aparece en Lendus, sin registro Employee).
                $unidentified[] = [
                    'report_name' => $row['name'] ?? null,
                    'promoter_code' => $row['code'] ?? null,
                    'report_branch_name' => $row['branch'] ?? null,
                    'route' => $row['route'] ?? null,
                    'placement' => round((float) ($row['colocacion'] ?? 0), 2),
                    'recovery' => round((float) ($row['recuperacion'] ?? 0), 2),
                    'portfolio_total' => round((float) ($row['cartera'] ?? 0), 2),
                    'portfolio_overdue' => round((float) ($row['vencida'] ?? 0), 2),
                ];

                continue;
            }

            $rows[] = $this->buildRow(
                $period, $row, $ids, $employees, $assignmentByEmployee, $branches, $branchByName,
                $rosterByEmployee, $opexByEmployee, $opexPureByEmployee, $opexItemsByEmployee, $noi,
                $routesByNorm, $routesByCode,
            );
        }

        usort($rows, fn ($a, $b) => strcmp((string) $a['employee']['full_name'], (string) $b['employee']['full_name']));

        return [
            'status' => self::STATUS_OK,
            'message' => null,
            'calculated_at' => now('America/Mexico_City')->toIso8601String(),
            'radiography_version' => (int) $summary->id,
            'rows' => $rows,
            'unidentified' => $unidentified,
        ];
    }

    private function buildRow(
        Period $period, array $row, array $ids, $employees, array $assignmentByEmployee, $branches, array $branchByName,
        $rosterByEmployee, array $opexByEmployee, array $opexPureByEmployee, array $opexItemsByEmployee, array $noi,
        array $routesByNorm, array $routesByCode,
    ): array {
        $canonicalId = min($ids);
        $canonical = $employees->get($canonicalId) ?? $employees->first(fn ($e) => in_array((int) $e->id, $ids, true));

        // ── Sucursal del periodo: asignación registrada (fuente de autorización).
        $assignedBranchId = null;
        foreach ([$canonicalId, ...$ids] as $eid) {
            if (isset($assignmentByEmployee[$eid])) {
                $assignedBranchId = $assignmentByEmployee[$eid];
                break;
            }
        }
        $branch = $assignedBranchId !== null ? $branches->get($assignedBranchId) : null;
        $branchSource = $branch !== null ? 'assignment' : null;
        if ($branch === null && ! empty($row['branch'])) {
            $branch = $branchByName[mb_strtoupper(trim((string) $row['branch']))] ?? null;
            $branchSource = $branch !== null ? 'report_row' : null;
        }
        $attribution = $branch?->attributionSummary();

        // ── OPEX / NOI agregados sobre TODOS los employee_id fusionados (igual que el Excel).
        $opexAuto = 0.0;
        $opexPure = 0.0;
        $opexItems = [];
        $payroll = array_fill_keys(array_values(self::PAYROLL_BUCKETS), 0.0);
        $percepciones = 0.0;
        $deducciones = 0.0;
        $deductionItems = [];
        foreach ($ids as $eid) {
            $opexAuto += (float) ($opexByEmployee[$eid] ?? 0.0);
            $opexPure += (float) ($opexPureByEmployee[$eid] ?? 0.0);
            foreach ($opexItemsByEmployee[$eid] ?? [] as $item) {
                $opexItems[] = $item;
            }
            foreach ($noi[$eid] ?? [] as $mov) {
                if ($mov['type'] === 'percepcion') {
                    $percepciones += $mov['amount'];
                    $bucket = self::PAYROLL_BUCKETS[BranchRadiographyCalculator::classifyPercepcionConcept($mov['concept'])] ?? 'otras_percepciones';
                    $payroll[$bucket] += $mov['amount'];
                } else {
                    $deducciones += $mov['amount'];
                    $deductionItems[$mov['concept']] = ($deductionItems[$mov['concept']] ?? 0.0) + $mov['amount'];
                }
            }
        }

        // Fuente ÚNICA de EBITDA/margen/mora del colaborador (manual = 0: la API es oficial).
        $metrics = $this->snapshotBuilder->computeEmployeeFinancialMetrics($row, ['total' => round($opexAuto, 2)]);

        $recovery = round((float) ($row['recuperacion'] ?? 0), 2);
        $placement = round((float) ($row['colocacion'] ?? 0), 2);
        $operationalStatus = ($recovery > 0 || $placement > 0 || round($opexAuto, 2) > 0) ? 'ACTIVO' : 'BAJA';

        // ── Roster del periodo (estado laboral).
        $roster = null;
        foreach ([$canonicalId, ...$ids] as $eid) {
            if ($rosterByEmployee->has($eid)) {
                $roster = $rosterByEmployee->get($eid);
                break;
            }
        }
        $rosterStatus = $roster === null ? null : RosterMovementStatus::resolve(
            $roster->reconciliation_status ?? null,
            $roster->movement_type ?? null,
            (bool) $roster->is_active_for_period,
        );

        $routes = $this->routesFor($row, $branch, $routesByNorm, $routesByCode);

        $components = $row['_recovery_components'] ?? null;
        $buckets = [];
        foreach ($row['_mora_buckets'] ?? [] as $bucketKey => $data) {
            $buckets[$bucketKey] = ['contracts' => (int) ($data['contratos'] ?? 0), 'amount' => round((float) ($data['monto'] ?? 0), 2)];
        }

        return [
            'employee' => [
                'id' => $canonicalId,
                'employee_ids' => $ids,
                'full_name' => $canonical?->full_name ?? ($row['name'] ?? null),
                'report_name' => $row['name'] ?? null,
                'employee_code' => $canonical?->employee_code,
                'employee_codes' => $employees->only($ids)->pluck('employee_code')->filter()->unique()->values()->all(),
                'promoter_code' => $row['code'] ?? null,
                'position' => $canonical?->position,
                'is_active' => $canonical === null ? null : (bool) $canonical->is_active,
            ],
            'branch_id' => $branch?->id,
            'branch' => $branch === null ? null : ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name],
            'branch_status' => $assignedBranchId !== null ? 'known' : 'unknown',
            'branch_source' => $branchSource,
            'assigned_branch_id' => $assignedBranchId,
            'sucursal' => $attribution['sucursal'] ?? null,
            'region' => $attribution['region'] ?? null,
            'report_branch_name' => $row['branch'] ?? null,
            'routes' => $routes,
            'employment' => [
                'operational_status' => $operationalStatus,
                'roster_status' => $rosterStatus,
                'roster_status_label' => $rosterStatus === null ? null : RosterMovementStatus::label($rosterStatus),
                'movement_type' => $roster->movement_type ?? null,
                'is_active_for_period' => $roster === null ? null : (bool) $roster->is_active_for_period,
                'requires_review' => $roster === null ? null : (bool) ($roster->requires_review ?? false),
                'roster_available' => $roster !== null,
            ],
            'kpis' => [
                'placement' => [
                    'amount' => $placement,
                    'operations' => (int) ($row['operaciones'] ?? 0),
                    'by_product' => array_map(fn ($p) => [
                        'product' => $p['producto'] ?? null,
                        'operations' => (int) ($p['operaciones'] ?? 0),
                        'amount' => round((float) ($p['colocacion'] ?? 0), 2),
                    ], $row['_placements_by_product'] ?? []),
                ],
                'recovery' => [
                    'total' => $recovery,
                    'components' => $components === null ? null : [
                        'capital' => $components['capital_recuperado'] ?? 0.0,
                        'interest' => $components['interes_recuperado'] ?? 0.0,
                        'tax' => $components['impuesto_recuperado'] ?? 0.0,
                        'late_charges' => $components['charges'] ?? 0.0,
                        'additional_charges' => $components['cargos_adicionales'] ?? 0.0,
                        'surplus' => $components['excedente_recuperado'] ?? 0.0,
                        'initial_charges' => $components['cargos_inicio'] ?? 0.0,
                        'opening_fee' => $components['comision_apertura'] ?? 0.0,
                        'crece_insurance_recognized' => $components['seguro_crece_reconocido'] ?? 0.0,
                        'other' => $components['otros_recuperacion'] ?? 0.0,
                    ],
                ],
                'portfolio' => [
                    'total' => round($metrics['cartera'], 2),
                    'overdue' => round($metrics['vencida'], 2),
                    'current' => max(0.0, round($metrics['cartera'] - $metrics['vencida'], 2)),
                    'mora_pct' => $metrics['mora_index'],
                    'buckets' => $buckets,
                ],
                'payroll' => [
                    'human_capital_total' => round($metrics['neto'], 2),
                    'payments' => round($metrics['pagos'], 2),
                    'bonuses' => round($metrics['bonos'], 2),
                    'discounts' => round($metrics['descuentos'], 2),
                    'noi_perceptions' => round($percepciones, 2),
                    'noi_deductions' => round($deducciones, 2),
                    'noi_net_paid' => round($percepciones - $deducciones, 2),
                    'concepts' => array_map(fn ($v) => round($v, 2), $payroll),
                    'deductions_detail' => collect($deductionItems)->map(fn ($v, $k) => ['concept' => $k, 'amount' => round($v, 2)])->values()->all(),
                ],
                'commissions' => round($payroll['comisiones'], 2),
                'opex' => [
                    'automatic_total' => round($opexAuto, 2),
                    'opex_pure' => round($opexPure, 2),
                    'employee_payroll_expenses' => round($opexAuto - $opexPure, 2),
                    'manual' => 0.0,
                    'total' => round($metrics['opex_total'], 2),
                    'items' => $opexItems,
                ],
                'ebitda' => [
                    'income_base' => round($metrics['ingreso_base'], 2),
                    'total_expenses' => round($metrics['opex_total'] + $metrics['neto'], 2),
                    'ebitda' => round($metrics['ebitda'], 2),
                    'margin_pct' => $metrics['margen_ebitda'],
                    'category' => $metrics['ebitda_categoria'],
                ],
                'collections_effectiveness' => null,
            ],
            'availability' => [
                'collections_effectiveness' => 'Solo en GET /api/v1/employees/{id}/periods (cálculo pesado por colaborador; misma función que el reporte). La versión aprobada V4 semanal está en GET /api/v1/collections/effectiveness?scope=employee.',
                'roster' => $roster === null ? 'Sin fila de roster persistida para este periodo (solo se materializa para periodos mensuales con NOI).' : null,
                'manual_expense_adjustment' => 'El ajuste manual del reporte es efímero (nunca se guarda); la API entrega la cifra oficial sin ajuste.',
            ],
        ];
    }

    /**
     * Asignación del periodo por employee_id: la del periodo exacto; si no hay,
     * la del periodo componente más reciente (misma regla que
     * EmployeeBranchHistoryResolver::resolveForPeriod()). Nunca "la más reciente".
     *
     * @return array<int, int> employee_id => branch_id
     */
    private function assignmentsFor(Period $period, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $componentIds = collect($period->component_period_ids ?? [])->map(fn ($id) => (int) $id)->all();
        $periodIds = array_values(array_unique(array_merge([$period->id], $componentIds)));

        $direct = [];
        $viaComponent = [];
        foreach (array_chunk($employeeIds, 500) as $chunk) {
            EmployeeBranchAssignment::query()
                ->whereIn('employee_id', $chunk)
                ->whereIn('period_id', $periodIds)
                ->whereNotNull('branch_id')
                ->orderByDesc('period_id')
                ->get(['employee_id', 'period_id', 'branch_id'])
                ->each(function ($a) use ($period, &$direct, &$viaComponent) {
                    $eid = (int) $a->employee_id;
                    if ((int) $a->period_id === (int) $period->id) {
                        $direct[$eid] = (int) $a->branch_id;
                    } else {
                        $viaComponent[$eid] ??= (int) $a->branch_id;
                    }
                });
        }

        return $direct + $viaComponent;
    }

    /**
     * Rutas de cartera Lendus del periodo por gestor (nombre normalizado y
     * código de promotor). Una sola consulta.
     *
     * @return array{0: array<string, array<string, array>>, 1: array<string, array<string, array>>}
     */
    private function portfolioRoutes(array $dataIds): array
    {
        $byNorm = [];
        $byCode = [];
        DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('route_name')
            ->where(fn ($q) => $q->whereNotNull('promoter_name')->orWhereNotNull('promoter_code'))
            ->selectRaw('promoter_name, promoter_code, route_name, COUNT(*) as contracts, SUM(balance) as balance')
            ->groupBy('promoter_name', 'promoter_code', 'route_name')
            ->get()
            ->each(function ($r) use (&$byNorm, &$byCode) {
                $route = trim((string) $r->route_name);
                if ($route === '') {
                    return;
                }
                $entry = ['contracts' => (int) $r->contracts, 'balance' => (float) $r->balance];
                $norm = $this->canonicalizer->normalize((string) ($r->promoter_name ?? ''));
                if ($norm !== '') {
                    $byNorm[$norm][$route] = $this->mergeRouteEntry($byNorm[$norm][$route] ?? null, $entry);
                }
                $code = mb_strtoupper(trim((string) ($r->promoter_code ?? '')));
                if ($code !== '') {
                    $byCode[$code][$route] = $this->mergeRouteEntry($byCode[$code][$route] ?? null, $entry);
                }
            });

        return [$byNorm, $byCode];
    }

    private function mergeRouteEntry(?array $current, array $entry): array
    {
        if ($current === null) {
            return $entry;
        }

        return ['contracts' => $current['contracts'] + $entry['contracts'], 'balance' => $current['balance'] + $entry['balance']];
    }

    /** @return list<array{route:string, catalog_route:?string, catalog_branch:?string, active:?bool, source:string, contracts:?int, balance:?float}> */
    private function routesFor(array $row, ?Branch $branch, array $routesByNorm, array $routesByCode): array
    {
        $routes = [];
        $add = function (string $raw, string $source, ?int $contracts = null, ?float $balance = null) use (&$routes) {
            $catalog = BranchRouteCatalog::route($raw);
            $key = $catalog['route'] ?? BranchRouteCatalog::normalize($raw);
            if (isset($routes[$key])) {
                if (! in_array($source, $routes[$key]['sources'], true)) {
                    $routes[$key]['sources'][] = $source;
                }
                if ($contracts !== null) {
                    $routes[$key]['contracts'] = ($routes[$key]['contracts'] ?? 0) + $contracts;
                    $routes[$key]['balance'] = round(($routes[$key]['balance'] ?? 0) + (float) $balance, 2);
                }

                return;
            }
            $routes[$key] = [
                'route' => $raw,
                'catalog_route' => $catalog['route'] ?? null,
                'catalog_branch' => $catalog['branch'] ?? null,
                'active' => $catalog['active'] ?? null,
                'sources' => [$source],
                'contracts' => $contracts,
                'balance' => $balance === null ? null : round($balance, 2),
            ];
        };

        // 1. El branch asignado en el periodo ES una ruta del catálogo oficial.
        if ($branch !== null && BranchRouteCatalog::route($branch->name) !== null) {
            $add((string) $branch->name, 'assignment');
        }

        // 2. Cartera Lendus del periodo (por nombre canónico y por código de promotor).
        $seen = [];
        $norm = (string) ($row['_norm_key'] ?? '');
        $code = mb_strtoupper(trim((string) ($row['code'] ?? '')));
        foreach ([$routesByNorm[$norm] ?? [], $code !== '' ? ($routesByCode[$code] ?? []) : []] as $set) {
            foreach ($set as $route => $data) {
                if (isset($seen[$route])) {
                    continue; // misma ruta vista por nombre y por código: no se duplica
                }
                $seen[$route] = true;
                $add((string) $route, 'portfolio', $data['contracts'], $data['balance']);
            }
        }

        // 3. Ruta que el propio reporte ya trae en la fila (cartera fusionada).
        if (! empty($row['route']) && ! isset($seen[(string) $row['route']])) {
            $add((string) $row['route'], 'portfolio');
        }

        return array_values($routes);
    }
}
