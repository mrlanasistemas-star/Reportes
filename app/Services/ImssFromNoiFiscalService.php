<?php

namespace App\Services;

use App\Models\Period;
use Illuminate\Support\Facades\DB;

/**
 * Deriva IMSS patronal desde el roster canónico (SOLO NOI Nómina Fiscal), sin
 * archivo manual. IMSS por sucursal = colaboradores únicos en NOI fiscal de
 * esa sucursal × $3,500. Corporativo/Tulancingo/sin-sucursal se calculan y
 * guardan para auditoría interna, pero quedan excluidos del reporte general
 * (included_in_report=false), igual que el resto de KPIs operativos.
 */
class ImssFromNoiFiscalService
{
    /** Cuota mensual de IMSS patronal por colaborador (MXN). */
    public const MONTHLY_FEE = 3500.00;

    /**
     * @param  PeriodEmployeeRosterService  $rosterService  Reconstruye el roster canónico del periodo.
     * @param  BranchResolverService  $branchResolver  Traduce nombres de ruta/sucursal del archivo IMSS legacy a sucursal oficial.
     */
    public function __construct(
        private readonly PeriodEmployeeRosterService $rosterService,
        private readonly BranchResolverService $branchResolver,
    ) {}

    /**
     * Calcula el IMSS patronal del periodo por sucursal y lo guarda en `fact_imss`.
     *
     * Pasos y efectos:
     * 1. Reconstruye el roster del periodo (escribe en period_employee_rosters).
     * 2. Cuenta los colaboradores activos que aparecen en NOI fiscal, por sucursal;
     *    los que no tienen sucursal van a una fila "SIN SUCURSAL" no incluida.
     * 3. Borra TODO `fact_imss` del periodo y reinserta (monto = colaboradores × $3,500);
     *    solo las sucursales operativas quedan con included_in_report = true.
     * 4. Si existe archivo IMSS manual (fact_expenses categoría IMSS), suma su monto en
     *    sucursales del reporte y compara (tolerancia $0.01).
     *
     * @param  Period  $period  Periodo a derivar.
     * @return array{collaborators_included:int,amount_included:float,branches:int,sin_sucursal:int,roster:array,legacy_exists:bool,matches_legacy:?bool,legacy_amount:?float}
     *                                                                                                                                                                         branches cuenta todas las sucursales con colaboradores (operativas o no);
     *                                                                                                                                                                         matches_legacy = null si no hay archivo manual.
     */
    public function deriveForPeriod(Period $period): array
    {
        $rosterStats = $this->rosterService->buildForPeriod($period);

        $rows = DB::table('period_employee_rosters')
            ->where('period_id', $period->id)
            ->where('is_active_for_period', true)
            ->where('appears_in_nomina_fiscal', true)
            ->get(['branch_id', 'branch_name', 'is_branch_operativa']);

        $byBranch = [];
        $sinSucursal = 0;
        foreach ($rows as $row) {
            if (! $row->branch_id) {
                $sinSucursal++;

                continue;
            }
            $branchId = (int) $row->branch_id;
            $byBranch[$branchId] ??= [
                'branch_name' => $row->branch_name,
                'is_operativa' => (bool) $row->is_branch_operativa,
                'count' => 0,
            ];
            $byBranch[$branchId]['count']++;
        }

        DB::table('fact_imss')->where('period_id', $period->id)->delete();

        $now = now();
        $insert = [];
        $totalIncluded = 0;
        $amountIncluded = 0.0;

        foreach ($byBranch as $branchId => $d) {
            $amount = $d['count'] * self::MONTHLY_FEE;
            $included = $d['is_operativa'];
            $insert[] = [
                'period_id' => $period->id,
                'branch_id' => $branchId,
                'branch_name' => $d['branch_name'],
                'collaborators_count' => $d['count'],
                'monthly_fee_per_employee' => self::MONTHLY_FEE,
                'amount' => $amount,
                'included_in_report' => $included,
                'excluded_reason' => $included ? null : 'No operativa (Corporativo/Tulancingo/otra unidad fuera de las 13 sucursales oficiales)',
                'source' => 'derived_noi_fiscal',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($included) {
                $totalIncluded += $d['count'];
                $amountIncluded += $amount;
            }
        }

        if ($sinSucursal > 0) {
            $insert[] = [
                'period_id' => $period->id,
                'branch_id' => null,
                'branch_name' => 'SIN SUCURSAL',
                'collaborators_count' => $sinSucursal,
                'monthly_fee_per_employee' => self::MONTHLY_FEE,
                'amount' => $sinSucursal * self::MONTHLY_FEE,
                'included_in_report' => false,
                'excluded_reason' => 'Empleado sin sucursal resuelta (ver period_employee_rosters)',
                'source' => 'derived_noi_fiscal',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($insert)) {
            DB::table('fact_imss')->insert($insert);
        }

        // Legacy = archivo manual IMSS reprocesado en este mismo pipeline run
        // (fact_expenses category=IMSS, ver BranchRadiographyCalculator::accumulateImssPatronal,
        // que sigue siendo la fuente autoritativa mientras exista el archivo cargado).
        $imssSourceId = DB::table('data_sources')->where('code', 'imss')->value('id');
        $legacyAmount = null;
        $legacyExists = false;
        if ($imssSourceId) {
            $legacyRows = DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
                ->where('e.period_id', $period->id)
                ->where('ru.data_source_id', $imssSourceId)
                ->where('e.category', 'IMSS')
                ->selectRaw('b.name as branch_name, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total')
                ->groupBy('b.name')
                ->get();

            if ($legacyRows->isNotEmpty()) {
                $legacyExists = true;
                // Mismo filtro operativo que BranchRadiographyCalculator::accumulateImssPatronal():
                // solo cuenta lo que cae dentro del reporte (excluye Corporativo/Tulancingo/etc).
                $legacyAmount = 0.0;
                foreach ($legacyRows as $row) {
                    $real = $row->branch_name ? $this->branchResolver->resolveRealBranchFromRoute($row->branch_name) : null;
                    if ($real && $this->branchResolver->isSheetBranch($real)) {
                        $legacyAmount += (float) $row->total;
                    }
                }
            }
        }

        $matchesLegacy = $legacyExists ? (abs($legacyAmount - $amountIncluded) <= 0.01) : null;

        return [
            'collaborators_included' => $totalIncluded,
            'amount_included' => $amountIncluded,
            'branches' => count($byBranch),
            'sin_sucursal' => $sinSucursal,
            'roster' => $rosterStats,
            'legacy_exists' => $legacyExists,
            'matches_legacy' => $matchesLegacy,
            'legacy_amount' => $legacyAmount,
        ];
    }
}
