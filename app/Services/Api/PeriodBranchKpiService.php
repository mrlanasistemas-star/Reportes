<?php

namespace App\Services\Api;

use App\Models\Branch;
use App\Models\Period;
use App\Services\Catalog\BranchRouteCatalog;
use App\Services\RadiografiaExportService;
use App\Services\Radiography\BranchRadiographyCalculator;
use App\Services\Radiography\RadiographyStyleHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * API v1 — KPIs por SUCURSAL, bloque CORPORATIVO y CONSOLIDADO de un periodo.
 * Solo lectura.
 *
 * Nunca recalcula: lee el snapshot GENERAL del reporte
 * (RadiografiaExportService::buildSnapshot(), cacheado 12 h e invalidado al
 * regenerar la radiografía) — exactamente lo que alimentan Web/Excel/PDF:
 *
 *   - Sucursales: snapshot.branch_radiography.branches (BranchRadiographyCalculator),
 *     con EBITDA/Nómina/Gastos por las funciones canónicas *For() — las mismas
 *     que usa RadiographySnapshotBuilder::summaryFromRow() para la vista de
 *     una sucursal y RadiographyStyleHelper para el Excel.
 *   - Corporativo: snapshot.branch_radiography.unassigned (nómina de
 *     colaboradores sin sucursal operativa — Corporativo y sin asignar; hoja
 *     "SIN ASIGNAR" del Excel) + sections.corporate_funding (excedentes enviados
 *     a corporativo). Los gastos ERP/Lendus de Corporativo están EXCLUIDOS del
 *     OPEX del reporte por regla (BranchRadiographyCalculator::accumulateGastos).
 *   - Consolidado: snapshot.summary (cifras de portada del reporte) +
 *     snapshot.branch_radiography.global (sumGlobal = sucursales + nómina sin
 *     sucursal).
 */
class PeriodBranchKpiService
{
    public const STATUS_OK = 'ok';

    public const STATUS_NOT_AVAILABLE = 'not_available';

    private const MORA_BUCKETS = ['mora_0_30', 'mora_31_60', 'mora_61_90', 'mora_91_120', 'mora_120_plus'];

    /** @var array<int, array> memo por petición */
    private array $memo = [];

    public function __construct(
        private readonly RadiografiaExportService $exportService,
    ) {}

    /**
     * @return array{status:string, message:?string, calculated_at:?string, branches:list<array>, corporate:?array, consolidated:?array, reconciliation:?array}
     */
    public function forPeriod(Period $period): array
    {
        if (isset($this->memo[$period->id])) {
            return $this->memo[$period->id];
        }

        try {
            $snapshot = $this->exportService->buildSnapshot($period);
        } catch (RuntimeException $e) {
            Log::info('Api\\PeriodBranchKpiService: sin radiografía generada para el periodo.', ['period_id' => $period->id, 'error' => $e->getMessage()]);

            return $this->memo[$period->id] = [
                'status' => self::STATUS_NOT_AVAILABLE,
                'message' => $e->getMessage(),
                'calculated_at' => null,
                'branches' => [],
                'corporate' => null,
                'consolidated' => null,
                'reconciliation' => null,
            ];
        }

        $radiography = $snapshot['branch_radiography'] ?? [];
        $branchRows = $radiography['branches'] ?? [];
        $unassigned = $radiography['unassigned'] ?? [];
        $global = $radiography['global'] ?? [];
        $sections = $snapshot['sections'] ?? [];

        $dbBranches = Branch::query()->with('sucursal')->get();
        $byName = [];
        foreach ($dbBranches as $b) {
            $byName[$this->eq($b->name)] ??= $b;
        }

        $turnoverByBranch = [];
        foreach (($sections['rotation']['por_sucursal'] ?? []) as $r) {
            $turnoverByBranch[$this->eq($r['sucursal'] ?? '')] = isset($r['indice_rotacion']) ? (float) $r['indice_rotacion'] : null;
        }

        $noiByBranchId = $this->noiByBranch($period, $dbBranches->pluck('id')->all());

        $branches = [];
        foreach ($branchRows as $row) {
            $name = (string) ($row['sucursal'] ?? '');
            $db = $byName[$this->eq($name)] ?? null;
            $attribution = $db?->attributionSummary();
            $branches[] = [
                'name' => $name,
                'branch_id' => $db?->id,
                'branch' => $db === null ? null : ['id' => $db->id, 'code' => $db->code, 'name' => $db->name],
                'sucursal' => $attribution['sucursal'] ?? null,
                'region' => $attribution['region'] ?? null,
                'routes' => collect(BranchRouteCatalog::routesFor($name))
                    ->map(fn (bool $active, string $route) => ['route' => $route, 'active' => $active])
                    ->values()->all(),
                'kpis' => $this->branchKpis($row, $db === null ? null : ($noiByBranchId[$db->id] ?? ['percepciones' => 0.0, 'deducciones' => 0.0]), $turnoverByBranch[$this->eq($name)] ?? null),
            ];
        }

        $corporate = $this->corporateBlock($unassigned, $sections);
        $consolidated = $this->consolidatedBlock($snapshot, $global, $sections);

        $sumBranchesEbitda = round(array_sum(array_map(fn ($b) => $b['kpis']['ebitda']['ebitda'], $branches)), 2);
        $consolidatedEbitda = $consolidated['kpis']['ebitda']['ebitda'];
        $sumBranchesPortfolio = round(array_sum(array_map(fn ($b) => $b['kpis']['portfolio']['total'], $branches)), 2);

        return $this->memo[$period->id] = [
            'status' => self::STATUS_OK,
            'message' => null,
            'calculated_at' => $snapshot['generated_at'] ?? null,
            'branches' => $branches,
            'corporate' => $corporate,
            'consolidated' => $consolidated,
            'reconciliation' => [
                'ebitda' => [
                    'sum_branches' => $sumBranchesEbitda,
                    'corporate_contribution' => $corporate['ebitda_contribution'],
                    'consolidated' => $consolidatedEbitda,
                    'difference' => round($consolidatedEbitda - ($sumBranchesEbitda + $corporate['ebitda_contribution']), 2),
                    'rule' => 'EBITDA consolidado = Σ EBITDA de sucursales − Nómina y Capital Humano sin sucursal (corporativo).',
                ],
                'portfolio' => [
                    'sum_branches' => $sumBranchesPortfolio,
                    'consolidated' => $consolidated['kpis']['portfolio']['total'],
                    'difference' => round($consolidated['kpis']['portfolio']['total'] - $sumBranchesPortfolio, 2),
                    'rule' => 'La cartera consolidada del reporte sale de "Saldos por cliente" sin filtro (todas las rutas excepto Aguascalientes); la de sucursal solo cuenta las sucursales operativas, por eso puede diferir.',
                ],
            ],
        ];
    }

    private function branchKpis(array $row, ?array $noi, ?float $turnover): array
    {
        $cartera = (float) ($row['valor_cartera'] ?? 0);
        $vencida = 0.0;
        $buckets = [];
        foreach (self::MORA_BUCKETS as $bucket) {
            $amount = (float) ($row[$bucket] ?? 0);
            $vencida += $amount;
            $buckets[$bucket] = ['contracts' => (int) ($row["{$bucket}_cnt"] ?? 0), 'amount' => round($amount, 2)];
        }

        return array_merge($this->financialKpis($row, $cartera, $vencida, $buckets), [
            'payroll' => $this->payrollKpis($row, $noi),
            'turnover_pct' => $turnover,
            'collections_effectiveness' => null,
        ]);
    }

    /** Bloque común sucursal/consolidado — mismas claves y funciones canónicas. */
    private function financialKpis(array $row, float $cartera, float $vencida, array $buckets): array
    {
        $ebitda = BranchRadiographyCalculator::ebitdaFinalFor($row);

        return [
            'placement' => [
                'amount' => round((float) ($row['colocacion'] ?? 0), 2),
                'credits' => (int) ($row['creditos_colocados'] ?? 0),
            ],
            'recovery' => [
                'total' => round((float) ($row['recuperacion_total'] ?? 0), 2),
                'gross' => round((float) ($row['recuperacion_bruta'] ?? 0), 2),
                'components' => [
                    'capital' => round((float) ($row['capital_recuperado'] ?? 0), 2),
                    'interest' => round((float) ($row['interes_recuperado'] ?? 0), 2),
                    'tax' => round((float) ($row['impuesto_recuperado'] ?? 0), 2),
                    'late_charges' => round((float) ($row['charges'] ?? 0), 2),
                    'additional_charges' => round((float) ($row['cargos_adicionales'] ?? 0), 2),
                    'surplus' => round((float) ($row['excedente_recuperado'] ?? 0), 2),
                    'initial_charges' => round((float) ($row['cargos_inicio'] ?? 0), 2),
                    'opening_fee' => round((float) ($row['comision_apertura'] ?? 0), 2),
                    'crece_insurance_recognized' => round((float) ($row['seguro_crece_reconocido'] ?? 0), 2),
                    'other' => round((float) ($row['otros_recuperacion'] ?? 0), 2),
                ],
                'excluded' => [
                    'insurance_gross' => round((float) ($row['seguro_excluido_bruto'] ?? 0), 2),
                    'savehearts_gross' => round((float) ($row['seguro_savehearts_bruto'] ?? 0), 2),
                    'comadres_gross' => round((float) ($row['seguro_comadres_bruto'] ?? 0), 2),
                    'crece_gross' => round((float) ($row['seguro_crece_bruto'] ?? 0), 2),
                    'crece_not_recognized' => round(max(0.0, (float) ($row['seguro_crece_bruto'] ?? 0) - (float) ($row['seguro_crece_reconocido'] ?? 0)), 2),
                    'unification' => round((float) ($row['unificacion_excluida'] ?? 0), 2),
                    'condonation' => round((float) ($row['condonacion_excluida'] ?? 0), 2),
                ],
            ],
            'portfolio' => [
                'total' => round($cartera, 2),
                'contracts' => (int) ($row['contratos'] ?? 0),
                'overdue' => round($vencida, 2),
                'current' => max(0.0, round($cartera - $vencida, 2)),
                'mora_pct' => $cartera > 0 ? round($vencida / $cartera * 100, 2) : 0.0,
                'buckets' => $buckets,
            ],
            'expenses' => [
                'opex_total' => round((float) ($row['gastos_operativos'] ?? 0), 2),
                'erp' => round((float) ($row['gastos_erp_total'] ?? 0), 2),
                'lendus' => round((float) ($row['gastos_lendus_total'] ?? 0), 2),
                'erp_loaded' => round((float) ($row['gastos_erp_cargado'] ?? 0), 2),
                'erp_excluded_insurance' => round((float) ($row['gastos_erp_excluido_seguros'] ?? 0), 2),
                'lendus_loaded' => round((float) ($row['gastos_lendus_cargado'] ?? 0), 2),
                'lendus_excluded_funding' => round((float) ($row['gastos_lendus_excluido_fondeo'] ?? 0), 2),
                'lendus_excluded_surplus' => round((float) ($row['gastos_lendus_excluido_excedentes'] ?? 0), 2),
                'lendus_excluded_payroll' => round((float) ($row['gastos_lendus_excluido_nomina'] ?? 0), 2),
                'lendus_reclassified_payroll' => round((float) ($row['gastos_lendus_reclasificado_nomina'] ?? 0), 2),
                'lendus_excluded_policies' => round((float) ($row['gastos_lendus_excluido_polizas'] ?? 0), 2),
                'by_concept' => $this->roundMap($row['gastos_detalle'] ?? []),
            ],
            'commissions' => round((float) ($row['comisiones'] ?? 0), 2),
            'funding' => [
                'interbranch_loans' => round((float) ($row['prestamos_fondea'] ?? 0), 2),
                'surplus_sent_to_corporate' => round((float) ($row['excedentes'] ?? 0), 2),
            ],
            'insurance' => [
                'lendus_bridge' => round((float) ($row['seguros_lendus_puente'] ?? 0), 2),
                'crece_policies_30' => round((float) ($row['polizas_crece_30'] ?? 0), 2),
                'crece_comadres_informative' => round((float) ($row['seguro_crece_comadres_informativo'] ?? 0), 2),
            ],
            'ebitda' => [
                'income_base' => round(BranchRadiographyCalculator::ingresoEbitdaBaseFor($row), 2),
                'total_expenses' => round(BranchRadiographyCalculator::gastosTotalesFor($row), 2),
                'ebitda' => round($ebitda, 2),
                'margin_pct' => BranchRadiographyCalculator::margenEbitdaFor($row),
                'category' => RadiographyStyleHelper::ebitdaCategory($ebitda),
            ],
        ];
    }

    private function payrollKpis(array $row, ?array $noi): array
    {
        return [
            'human_capital_total' => round(BranchRadiographyCalculator::nominaTotalFor($row), 2),
            'breakdown' => $this->roundMap(BranchRadiographyCalculator::nominaBreakdownFor($row)),
            'imss_employer' => round((float) ($row['imss_patronal'] ?? 0), 2),
            'employee_expenses' => round((float) ($row['gastos_empleados_nomina'] ?? 0), 2),
            'deductions_informative' => $this->roundMap($row['nomina_detalle'] ?? []),
            'noi_perceptions' => $noi === null ? null : round($noi['percepciones'], 2),
            'noi_deductions' => $noi === null ? null : round($noi['deducciones'], 2),
            'noi_net_paid' => $noi === null ? null : round($noi['percepciones'] - $noi['deducciones'], 2),
        ];
    }

    private function corporateBlock(array $unassigned, array $sections): array
    {
        $nomina = BranchRadiographyCalculator::nominaTotalFor($unassigned);
        $funding = $sections['corporate_funding'] ?? [];

        return [
            'name' => 'CORPORATIVO / SIN SUCURSAL',
            'description' => 'Nómina de colaboradores sin sucursal operativa (Corporativo y sin asignar) — hoja "SIN ASIGNAR" del Excel. Entra al consolidado; nunca se reparte entre sucursales.',
            'payroll' => [
                'human_capital_total' => round($nomina, 2),
                'breakdown' => $this->roundMap(BranchRadiographyCalculator::nominaBreakdownFor($unassigned)),
                'imss_employer' => round((float) ($unassigned['imss_patronal'] ?? 0), 2),
                'employee_expenses' => round((float) ($unassigned['gastos_empleados_nomina'] ?? 0), 2),
                'deductions_informative' => $this->roundMap($unassigned['nomina_detalle'] ?? []),
                'employees' => array_map(fn ($e) => [
                    'name' => $e['nombre'] ?? null,
                    'employee_id' => isset($e['emp_id']) ? (int) $e['emp_id'] : null,
                    'source' => $e['fuente'] ?? null,
                    'salary_p001' => round((float) ($e['p001'] ?? 0), 2),
                    'commissions_p002' => round((float) ($e['p002'] ?? 0), 2),
                    'bonuses' => round((float) ($e['bonos'] ?? 0), 2),
                ], $unassigned['empleados'] ?? []),
            ],
            'expenses' => [
                'opex_total' => round((float) ($unassigned['gastos_operativos'] ?? 0), 2),
                'items' => array_map(fn ($g) => [
                    'concept' => $g['concepto'] ?? null,
                    'amount' => round((float) ($g['monto'] ?? 0), 2),
                    'origin' => $g['origen'] ?? null,
                ], $unassigned['gastos_items'] ?? []),
                'note' => 'Los gastos ERP/Lendus de Corporativo se excluyen del OPEX del reporte por regla; aquí solo se listan como referencia.',
            ],
            'imss_excluded_informative' => $this->roundMap($unassigned['imss_excluido'] ?? []),
            'surplus_received' => [
                'total' => round((float) ($funding['total'] ?? 0), 2),
                'by_branch' => array_map(fn ($r) => ['branch' => $r['branch'] ?? null, 'total' => round((float) ($r['total'] ?? 0), 2)], $funding['by_branch'] ?? []),
            ],
            // 0.0 - x evita un "-0" en JSON cuando no hay nómina corporativa.
            'ebitda_contribution' => round(0.0 - $nomina, 2) + 0.0,
            'availability' => [
                'placement' => 'not_attributable',
                'recovery' => 'not_attributable',
                'portfolio' => 'not_attributable',
                'note' => 'Corporativo no coloca, no cobra ni tiene cartera en el reporte: esos KPIs no aplican (null, nunca 0).',
            ],
        ];
    }

    private function consolidatedBlock(array $snapshot, array $global, array $sections): array
    {
        $s = $snapshot['summary'] ?? [];
        $f = fn (string $key) => round((float) ($s[$key] ?? 0), 2);

        $buckets = [];
        foreach (self::MORA_BUCKETS as $bucket) {
            $buckets[$bucket] = ['contracts' => (int) ($global["{$bucket}_cnt"] ?? 0), 'amount' => round((float) ($global[$bucket] ?? 0), 2)];
        }

        // Base: mismas claves que una sucursal, sobre el GLOBAL del calculador…
        $kpis = $this->financialKpis($global, (float) ($s['portfolio_total'] ?? 0), (float) ($s['overdue_portfolio'] ?? 0), $buckets);
        // …y las cifras de portada del reporte (summary) donde el reporte las define distinto.
        $kpis['placement']['amount'] = $f('placement_total');
        $kpis['recovery']['total'] = $f('recovery_total');
        $kpis['portfolio']['mora_pct'] = round((float) ($s['mora_index'] ?? 0), 2);
        $kpis['expenses']['opex_total'] = $f('opex_total');
        $kpis['expenses']['erp'] = $f('expenses_erp');
        $kpis['expenses']['lendus'] = $f('expenses_lendus');
        $kpis['funding']['interbranch_loans'] = $f('fondeo_total');
        $kpis['funding']['surplus_sent_to_corporate'] = $f('excedentes_total');
        $kpis['insurance']['lendus_bridge'] = $f('seguros_lendus_puente');
        $kpis['ebitda'] = [
            'income_base' => $f('ingreso_ebitda_base'),
            'total_expenses' => $f('gastos_totales'),
            'ebitda' => $f('ebitda_final'),
            'margin_pct' => round((float) ($s['margen_ebitda'] ?? 0), 2),
            'category' => $s['ebitda_categoria'] ?? null,
        ];
        $kpis['payroll'] = [
            'human_capital_total' => $f('nomina_capital_humano_total'),
            'breakdown' => $this->roundMap(BranchRadiographyCalculator::nominaBreakdownFor($global)),
            'imss_employer' => round((float) ($global['imss_patronal'] ?? 0), 2),
            'employee_expenses' => round((float) ($global['gastos_empleados_nomina'] ?? 0), 2),
            'deductions_informative' => $this->roundMap($global['nomina_detalle'] ?? []),
            'noi_perceptions' => $f('noi_percepciones'),
            'noi_deductions' => $f('noi_deducciones'),
            'noi_net_paid' => $f('noi_neto_pagado'),
            'employees_count' => isset($s['employees_count']) ? (int) $s['employees_count'] : null,
        ];
        $kpis['turnover_pct'] = isset($sections['rotation']['indice']) ? (float) $sections['rotation']['indice'] : null;
        $kpis['collections_effectiveness'] = isset($sections['efectividad_cobranza']['efectividad'])
            ? [
                'effectiveness_pct' => $sections['efectividad_cobranza']['efectividad']['efectividad_pct'] ?? null,
                'recovered_from_overdue' => $sections['efectividad_cobranza']['efectividad']['recuperado_de_mora'] ?? null,
                'previous_period_overdue_portfolio' => $sections['efectividad_cobranza']['efectividad']['cartera_mora_periodo_anterior'] ?? null,
            ]
            : null;
        $kpis['interbranch_loans_section_total'] = isset($sections['interbranch_loans']['total']) ? round((float) $sections['interbranch_loans']['total'], 2) : null;
        $kpis['corporate_funding_total'] = isset($sections['corporate_funding']['total']) ? round((float) $sections['corporate_funding']['total'], 2) : null;

        return [
            'name' => 'CONSOLIDADO',
            'description' => 'Total de la empresa — cifras de portada del reporte mensual (snapshot.summary). Incluye la nómina corporativa/sin sucursal.',
            'cash' => [
                'opening_balance' => isset($snapshot['saldo_inicial_caja']) ? round((float) $snapshot['saldo_inicial_caja'], 2) : null,
                'closing_balance' => isset($snapshot['saldo_final_caja']) ? round((float) $snapshot['saldo_final_caja'], 2) : null,
            ],
            'kpis' => $kpis,
        ];
    }

    /**
     * Percepciones/deducciones NOI por branch (DB) del periodo, en DOS consultas
     * agrupadas — misma regla que computeNoiPercepcionesDeduccionesForBranch()
     * (empleados con asignación al branch en ESTE period_id).
     *
     * @return array<int, array{percepciones: float, deducciones: float}>
     */
    private function noiByBranch(Period $period, array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }

        $dataIds = $this->dataIdsFor($period);
        $out = [];
        DB::table('fact_noi_movements as n')
            ->join('employee_branch_assignments as eba', function ($j) use ($period) {
                $j->on('eba.employee_id', '=', 'n.employee_id')->where('eba.period_id', '=', $period->id);
            })
            ->whereIn('n.period_id', $dataIds)
            ->whereIn('n.concept_type', ['percepcion', 'deduccion'])
            ->selectRaw('eba.branch_id, n.concept_type, SUM(n.amount) as total')
            ->groupBy('eba.branch_id', 'n.concept_type')
            ->get()
            ->each(function ($r) use (&$out) {
                $bid = (int) $r->branch_id;
                $out[$bid] ??= ['percepciones' => 0.0, 'deducciones' => 0.0];
                $out[$bid][$r->concept_type === 'percepcion' ? 'percepciones' : 'deducciones'] += (float) $r->total;
            });

        return $out;
    }

    private function dataIdsFor(Period $period): array
    {
        $weekly = $period->resolveBaseWeeklyIds(Period::all());

        return empty($weekly) ? [$period->id] : array_values(array_unique(array_merge($weekly, [$period->id])));
    }

    private function roundMap(array $map): array
    {
        $out = [];
        foreach ($map as $k => $v) {
            $out[$k] = is_numeric($v) ? round((float) $v, 2) : $v;
        }

        return $out;
    }

    private function eq(mixed $value): string
    {
        return mb_strtoupper(trim((string) $value));
    }
}
