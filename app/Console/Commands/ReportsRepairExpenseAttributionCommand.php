<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\ExpenseObservationAttributionService;
use App\Services\OpexClassificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Auditoría 27-ago-2026 (guardas endurecidas 07-sep-2026, cierre sección 7) —
 * aplica (o simula) la atribución de OPEX a colaboradores vía Observación/
 * Justificación para un periodo YA importado, sin necesidad de volver a subir
 * el Excel de Gastos Lendus. Mismo patrón que reports:repair-period: --dry-run
 * por seguridad si no se indica --apply, transaccional, nunca borra/duplica
 * gastos, nunca cambia amount/category/concept.
 *
 *   php artisan reports:repair-expense-attribution 28 --dry-run
 *   php artisan reports:repair-expense-attribution 28 --dry-run --export=storage/app/audits/propuesta_28.csv
 *   php artisan reports:repair-expense-attribution 28 --apply
 *
 * Cierre 07-sep-2026: las invariantes ahora se verifican DENTRO de la
 * transacción — si cualquiera se viola, se hace ROLLBACK real (antes el
 * chequeo corría DESPUÉS de que la transacción ya había hecho commit, así que
 * una violación solo se reportaba, sin deshacer la escritura). Guardas nuevas:
 * COUNT(fact_expenses) y SUM(paid_amount) por separado (antes solo se medía la
 * suma combinada COALESCE), y el total OPEX (vía OpexClassificationService) no
 * debe cambiar tampoco.
 */
class ReportsRepairExpenseAttributionCommand extends Command
{
    protected $signature = 'reports:repair-expense-attribution {period : ID del periodo} {--dry-run} {--apply} {--export= : Vuelca los cambios propuestos a un CSV en la ruta indicada}';

    protected $description = 'Aplica (o simula) la atribución de OPEX a colaboradores vía Observación/Justificación para un periodo ya importado.';

    public function handle(ExpenseObservationAttributionService $service, OpexClassificationService $opexClassifier): int
    {
        $period = Period::find((int) $this->argument('period'));
        if (!$period) {
            $this->error('No existe el periodo ID=' . $this->argument('period'));
            return 1;
        }

        $apply = (bool) $this->option('apply');
        if (!$apply && !$this->option('dry-run')) {
            $this->comment('Ni --dry-run ni --apply indicados — corriendo en modo dry-run por seguridad.');
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(empty($weeklyIds) ? [] : $weeklyIds, [$period->id])));

        $this->info(($apply ? 'APLICANDO' : 'DRY-RUN — sin escribir nada') . " sobre periodo {$period->label} (ID {$period->id})");
        $this->newLine();

        // ── Snapshot ANTES — count, sum(amount), sum(paid_amount) y total OPEX
        // (vía la fuente canónica). Todas deben quedar EXACTAMENTE iguales después.
        $before = $this->snapshotInvariants($dataIds, $opexClassifier);

        $results = [];
        $violation = null;
        try {
            DB::transaction(function () use ($period, $dataIds, $service, $apply, $opexClassifier, $before, &$results, &$violation) {
                $results = $service->attributeForPeriod($period, $dataIds, dryRun: !$apply);

                if (!$apply) {
                    return; // dry-run: nada que verificar dentro de la transacción, no hubo escritura
                }

                // Guarda adicional: ningún 'atribuido' con confianza insuficiente —
                // defensa en profundidad (el servicio YA solo devuelve 'atribuido' con
                // confianza suficiente, esto nunca debería dispararse; si lo hace, es
                // señal de una regresión real y debe abortar).
                foreach ($results as $r) {
                    if ($r['estado'] === 'atribuido' && (float) $r['confianza'] < 0.90) {
                        $violation = "fact_expense_id={$r['fact_expense_id']} propuesto con confianza insuficiente ({$r['confianza']}).";
                        throw new \RuntimeException($violation);
                    }
                }

                $after = $this->snapshotInvariants($dataIds, $opexClassifier);

                if ($after['count'] !== $before['count']) {
                    $violation = "COUNT(fact_expenses) cambió de {$before['count']} a {$after['count']} — nunca debe pasar (esto solo reasigna employee_id/branch_id, nunca inserta/borra filas).";
                    throw new \RuntimeException($violation);
                }
                if (abs($after['sum_amount'] - $before['sum_amount']) > 0.01) {
                    $violation = 'SUM(amount) cambió de $' . number_format($before['sum_amount'], 2) . ' a $' . number_format($after['sum_amount'], 2) . '.';
                    throw new \RuntimeException($violation);
                }
                if (abs($after['sum_paid_amount'] - $before['sum_paid_amount']) > 0.01) {
                    $violation = 'SUM(paid_amount) cambió de $' . number_format($before['sum_paid_amount'], 2) . ' a $' . number_format($after['sum_paid_amount'], 2) . '.';
                    throw new \RuntimeException($violation);
                }
                if (abs($after['opex_total'] - $before['opex_total']) > 0.01) {
                    $violation = 'Total OPEX (clasificación canónica) cambió de $' . number_format($before['opex_total'], 2) . ' a $' . number_format($after['opex_total'], 2) . ' — la reatribución dimensional NUNCA debe mover el total financiero.';
                    throw new \RuntimeException($violation);
                }
            });
        } catch (\RuntimeException $e) {
            $this->error('INVARIANTE VIOLADA — TRANSACCIÓN REVERTIDA (ROLLBACK), ningún cambio quedó guardado.');
            $this->error('  ' . ($violation ?? $e->getMessage()));
            return 1;
        }

        $atribuido  = array_values(array_filter($results, fn ($r) => $r['estado'] === 'atribuido'));
        $yaCorrecto = array_values(array_filter($results, fn ($r) => $r['estado'] === 'ya_correcto'));
        $conflictos = array_values(array_filter($results, fn ($r) => $r['estado'] === 'conflicto'));
        $ambiguos   = array_values(array_filter($results, fn ($r) => $r['estado'] === 'ambiguo'));
        $branchGeneral = array_values(array_filter($results, fn ($r) => $r['estado'] === 'branch_general'));

        $this->line('Evaluados: ' . count($results)
            . ' | Atribuidos ahora: ' . count($atribuido)
            . ' | Ya correctos: ' . count($yaCorrecto)
            . ' | General de sucursal: ' . count($branchGeneral)
            . ' | Conflictos (NEEDS_REVIEW): ' . count($conflictos)
            . ' | Ambiguos (NEEDS_REVIEW): ' . count($ambiguos));
        $this->newLine();

        foreach ($atribuido as $r) {
            $this->line("  + fact_expenses.id={$r['fact_expense_id']} | {$r['concept']} | \$" . number_format($r['amount'], 2)
                . " → employee_id={$r['employee_id']} ({$r['employee_name']}) @ " . ($r['branch_name'] ?? 'sin sucursal')
                . " (método={$r['fuente']}_{$r['metodo']}, confianza=" . number_format((float) $r['confianza'], 2) . ')');
        }

        if (!empty($conflictos)) {
            $this->newLine();
            $this->comment('Conflictos — requieren revisión manual, NO se tocaron:');
            foreach ($conflictos as $r) {
                $this->error("  ! fact_expenses.id={$r['fact_expense_id']} | {$r['concept']} | Observación=\"{$r['observation']}\" | Justificación=\"{$r['justification']}\"");
            }
        }
        if (!empty($ambiguos)) {
            $this->newLine();
            $this->comment('Ambiguos — requieren revisión manual, NO se tocaron:');
            foreach ($ambiguos as $r) {
                $texto = $r['fuente'] === 'justification' ? $r['justification'] : $r['observation'];
                $this->error("  ! fact_expenses.id={$r['fact_expense_id']} | {$r['concept']} | \"{$texto}\" — candidato sin confianza suficiente.");
            }
        }

        $this->newLine();
        if ($apply) {
            $after = $this->snapshotInvariants($dataIds, $opexClassifier);
            $this->info('Invariante OK — count/sum(amount)/sum(paid_amount)/total OPEX sin cambios tras aplicar: $' . number_format($after['sum_paid_amount'], 2));
        } else {
            $this->comment('DRY-RUN — no se verificó invariante post-escritura porque no se escribió nada. Usa --apply para aplicar (con guardas duras) o --export para revisar los cambios propuestos antes.');
        }

        if ($exportPath = $this->option('export')) {
            $this->exportProposedChanges($exportPath, $atribuido);
            $this->info("Cambios propuestos exportados a: {$exportPath}");
        }

        // Conflictos/ambiguos NUNCA son un código de salida distinto de 0 — son un
        // estado final válido y esperado (el gasto sigue existiendo en OPEX general/
        // sucursal, solo sin colaborador atribuido), no un fallo del comando.
        return 0;
    }

    /**
     * @return array{count:int, sum_amount:float, sum_paid_amount:float, opex_total:float}
     */
    private function snapshotInvariants(array $dataIds, OpexClassificationService $opexClassifier): array
    {
        $rows = DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->select('category', 'concept', 'amount', 'paid_amount')
            ->get();

        $opexTotal = 0.0;
        foreach ($rows as $r) {
            $classification = $opexClassifier->classify($r->category, $r->concept, OpexClassificationService::SOURCE_LENDUS);
            if ($classification['is_opex']) {
                $opexTotal += (float) ($r->paid_amount ?: $r->amount);
            }
        }

        return [
            'count'           => $rows->count(),
            'sum_amount'      => (float) $rows->sum('amount'),
            'sum_paid_amount' => (float) $rows->sum('paid_amount'),
            'opex_total'      => round($opexTotal, 2),
        ];
    }

    /**
     * Auditoría 07-sep-2026 (sección 9/16 del pedido) — CSV de revisión humana
     * ANTES de correr --apply: fact_expense_id, concept, category, amount,
     * employee anterior/nuevo, branch anterior/nuevo, texto, método, confianza.
     */
    private function exportProposedChanges(string $path, array $atribuido): void
    {
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'w');
        fputcsv($handle, [
            'fact_expense_id', 'concept', 'category', 'amount',
            'employee_anterior', 'employee_nuevo', 'branch_anterior', 'branch_nuevo',
            'texto', 'metodo', 'confianza',
        ]);
        foreach ($atribuido as $r) {
            fputcsv($handle, [
                $r['fact_expense_id'], $r['concept'], $r['category'], $r['amount'],
                $r['previous_employee_id'], $r['employee_id'], $r['previous_branch_id'], $r['branch_id'],
                $r['fuente'] === 'justification' ? $r['justification'] : $r['observation'],
                "{$r['fuente']}_{$r['metodo']}", $r['confianza'],
            ]);
        }
        fclose($handle);
    }
}
