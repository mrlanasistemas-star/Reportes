<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\ExpenseObservationAttributionService;
use App\Services\FinanciamientoMotosAssignmentService;
use App\Services\OpexClassificationService;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría 07-sep-2026 (cierre, punto 16) — reconciliación matcher vs BD vs
 * export, por colaborador. SOLO diagnóstico (dry-run siempre) — nunca escribe.
 *
 * Para cada colaborador (fila fusionada de buildAllEmployeeGestorRows(), MISMA
 * identidad — _employee_ids — que usa el export real) compara tres números que
 * DEBERÍAN coincidir después de aplicar las correcciones reales:
 *   A) monto_eligible_matcher     — lo que ExpenseObservationAttributionService
 *      (dry-run) dice que le pertenece HOY, aplicando la regla de prioridad
 *      completa (Observación > Justificación > alias > nombre > combinación >
 *      fuzzy > employee_id previo).
 *   B) monto_bd_atribuido         — lo que fact_expenses.employee_id tiene
 *      REALMENTE guardado ahora mismo (antes de cualquier --apply).
 *   C) opex_automatico_exportable — lo que buildEmployeeExpenseDetail() —
 *      fuente única de Web/Excel/PDF — expone HOY como "OPEX AUTOMÁTICO".
 *
 * DIFERENCIA = A − C (matcher esperado vs lo que el colaborador ve/exporta).
 * Objetivo tras `reports:repair-expense-attribution {period} --apply`:
 * DIFERENCIAS = 0 para todos los colaboradores.
 *
 *   php artisan reports:audit-employee-expenses 21
 *   php artisan reports:audit-employee-expenses 21 --only-diff
 */
class ReportsAuditEmployeeExpensesCommand extends Command
{
    protected $signature = 'reports:audit-employee-expenses
        {period : ID del periodo}
        {--only-diff : Solo lista colaboradores con diferencia distinta de cero}';

    protected $description = 'Diagnóstico (dry-run) de reconciliación matcher vs BD vs export, por colaborador — objetivo DIFERENCIAS=0 tras el repair.';

    public function handle(
        ExpenseObservationAttributionService $attributionService,
        FinanciamientoMotosAssignmentService $motosAssignment,
        OpexClassificationService $opexClassifier,
        RadiographySnapshotBuilder $snapshotBuilder,
    ): int {
        $period = Period::find((int) $this->argument('period'));
        if (!$period) {
            $this->error('No existe el periodo ID=' . $this->argument('period'));
            return 1;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(empty($weeklyIds) ? [] : $weeklyIds, [$period->id])));

        $this->info('════════════════════════════════════════════════════════════');
        $this->info("PERIODO: {$period->label} (ID {$period->id})");
        $this->info('════════════════════════════════════════════════════════════');
        $this->newLine();

        // ── A) Lo que el matcher propone HOY (dry-run, nunca escribe) ────────
        // Dos resolutores independientes conforman el universo COMPLETO de "lo que
        // debería pertenecer a cada colaborador": el matcher general (Observación/
        // Justificación) y FinanciamientoMotosAssignmentService (Motos/Cascos —
        // excluido a propósito del matcher general, ver ExpenseObservationAttribution
        // Service::attributeForPeriod()'s $delegatedConcepts). Sin el segundo, TODA
        // fila de Moto/Cascos aparecería como "diferencia" aunque esté correctamente
        // atribuida — falso positivo sistemático, no un problema real.
        $matcherResults = $attributionService->attributeForPeriod($period, $dataIds, dryRun: true);
        $motosResults   = $motosAssignment->assignForPeriod($period, $dataIds, dryRun: true);
        $matcherAmountByEmployeeId = [];
        foreach ($matcherResults as $r) {
            $eid = $r['employee_id'] ?? null;
            if (!$eid) {
                continue; // ambiguo/conflicto/branch_general/no_atribuible — no le pertenece a ningún colaborador
            }
            $matcherAmountByEmployeeId[$eid] = ($matcherAmountByEmployeeId[$eid] ?? 0.0) + (float) $r['amount'];
        }
        foreach ($motosResults as $r) {
            $eid = $r['employee_id'] ?? null;
            if (!$eid) {
                continue; // sin_resolver — no le pertenece a ningún colaborador todavía
            }
            $matcherAmountByEmployeeId[$eid] = ($matcherAmountByEmployeeId[$eid] ?? 0.0) + (float) $r['amount'];
        }

        // ── B) Lo que fact_expenses.employee_id tiene REALMENTE guardado hoy ──
        $bdRows = DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->selectRaw("employee_id, COALESCE(category,'') as category, COALESCE(concept,'') as concept, SUM(COALESCE(NULLIF(paid_amount,0), amount)) as total")
            ->groupBy('employee_id', 'category', 'concept')
            ->get();
        $bdAmountByEmployeeId = [];
        foreach ($bdRows as $r) {
            if (!$opexClassifier->classify($r->category, $r->concept, OpexClassificationService::SOURCE_LENDUS)['eligible_for_attribution']) {
                continue;
            }
            $eid = (int) $r->employee_id;
            $bdAmountByEmployeeId[$eid] = ($bdAmountByEmployeeId[$eid] ?? 0.0) + (float) $r->total;
        }

        // ── C) Lo que el colaborador REALMENTE ve/exporta hoy (fuente única) ──
        $gestorRows = $snapshotBuilder->buildAllEmployeeGestorRows($period);

        $rows = [];
        $totalDiferencia = 0.0;
        $conDiferencia = 0;

        foreach ($gestorRows as $row) {
            $employeeIds = $row['_employee_ids'] ?? [];
            $primaryId   = $employeeIds[0] ?? null;
            if (!$primaryId) {
                continue;
            }

            $expenseDetail = $snapshotBuilder->buildEmployeeExpenseDetail($employeeIds, $primaryId);
            $exportable    = round((float) $expenseDetail['automatic_total'], 2);

            $matcherAmount = 0.0;
            $bdAmount      = 0.0;
            $factIdsConDiff = [];
            foreach ($employeeIds as $eid) {
                $matcherAmount += (float) ($matcherAmountByEmployeeId[$eid] ?? 0.0);
                $bdAmount      += (float) ($bdAmountByEmployeeId[$eid] ?? 0.0);
            }
            $matcherAmount = round($matcherAmount, 2);
            $bdAmount      = round($bdAmount, 2);
            $diferencia    = round($matcherAmount - $exportable, 2);

            if (abs($diferencia) > 0.01) {
                // fact_expense_ids concretos donde el matcher propone un dueño DISTINTO
                // al employee_id actual de la BD, dentro de este grupo de identidad.
                foreach ($matcherResults as $r) {
                    $proposedEid  = $r['employee_id'] ?? null;
                    $previousEid  = $r['previous_employee_id'] ?? null;
                    $belongsHere  = ($proposedEid && in_array($proposedEid, $employeeIds, true))
                        || ($previousEid && in_array($previousEid, $employeeIds, true));
                    if ($belongsHere && $proposedEid !== $previousEid) {
                        $factIdsConDiff[] = $r['fact_expense_id'];
                    }
                }
            }

            $rows[] = [
                'employee_id'                 => $primaryId,
                'nombre'                       => $row['name'] ?? '-',
                'branch'                       => $row['branch'] ?? 'Sin asignar',
                'cantidad_movimientos'         => count($employeeIds),
                'monto_eligible_matcher'       => $matcherAmount,
                'monto_bd_atribuido'           => $bdAmount,
                'opex_automatico_exportable'   => $exportable,
                'diferencia'                   => $diferencia,
                'fact_expense_ids_diferencia'  => $factIdsConDiff,
            ];

            if (abs($diferencia) > 0.01) {
                $conDiferencia++;
                $totalDiferencia += abs($diferencia);
            }
        }

        $onlyDiff = (bool) $this->option('only-diff');
        $header = ['ID', 'COLABORADOR', 'SUCURSAL', 'MOV.', 'MATCHER ($)', 'BD ($)', 'EXPORTABLE ($)', 'DIFERENCIA ($)'];
        $table  = [];
        foreach ($rows as $r) {
            if ($onlyDiff && abs($r['diferencia']) <= 0.01) {
                continue;
            }
            $table[] = [
                $r['employee_id'],
                mb_strimwidth($r['nombre'], 0, 30, '…'),
                mb_strimwidth($r['branch'], 0, 18, '…'),
                $r['cantidad_movimientos'],
                number_format($r['monto_eligible_matcher'], 2),
                number_format($r['monto_bd_atribuido'], 2),
                number_format($r['opex_automatico_exportable'], 2),
                number_format($r['diferencia'], 2),
            ];
        }
        $this->table($header, $table);

        if (!$onlyDiff || $conDiferencia > 0) {
            $this->newLine();
            foreach ($rows as $r) {
                if (abs($r['diferencia']) <= 0.01 || empty($r['fact_expense_ids_diferencia'])) {
                    continue;
                }
                $this->line("  → {$r['nombre']} (ID {$r['employee_id']}): fact_expense_ids con diferencia = [" . implode(', ', $r['fact_expense_ids_diferencia']) . ']');
            }
        }

        $this->newLine();
        $this->info('════════════════════════════════════════════════════════════');
        $this->info('COLABORADORES EVALUADOS: ' . count($rows));
        $this->info('CON DIFERENCIA: ' . $conDiferencia);
        $this->info('SIN DIFERENCIA: ' . (count($rows) - $conDiferencia));
        $this->info('TOTAL DIFERENCIA: $' . number_format($totalDiferencia, 2));
        $this->info('════════════════════════════════════════════════════════════');

        return 0;
    }
}
