<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\ExpenseObservationAttributionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría 27-ago-2026 (ampliada 07-sep-2026) — atribución de OPEX a
 * colaboradores vía Observación/Justificación (ver
 * Services/ExpenseObservationAttributionService.php). SOLO diagnóstico — nunca
 * escribe (el propio servicio corre en dryRun=true).
 *
 *   php artisan reports:audit-expense-attribution 28
 *   php artisan reports:audit-expense-attribution 28 --examples=40
 *
 * Ampliación 07-sep-2026: agrega el resto de las secciones pedidas en la
 * auditoría de negocio — archivo/upload de origen, filas Excel vs fact_expenses
 * vs OPEX evaluable, desglose por categoría y por colaborador, ejemplos de NO
 * ENCONTRADOS con la razón concreta (ExpenseObservationAttributionService ahora
 * expone 'reason'), y ejemplos de AMBIGUOS con la lista de candidatos y su score
 * (ahora expuesta como 'candidates'). No cambia NADA de la lógica de atribución
 * — solo reporta con más detalle lo que el servicio ya decide.
 */
class ReportsAuditExpenseAttributionCommand extends Command
{
    protected $signature = 'reports:audit-expense-attribution {period : ID del periodo} {--examples=20 : Cuántos ejemplos resueltos mostrar} {--not-found=30 : Cuántos ejemplos de "no encontrados" mostrar} {--ambiguous=30 : Cuántos ejemplos de "ambiguos" mostrar}';

    protected $description = 'Diagnóstico (dry-run) de atribución de OPEX a colaboradores vía Observación/Justificación del Excel de Gastos Lendus.';

    public function handle(ExpenseObservationAttributionService $service): int
    {
        $period = Period::find((int) $this->argument('period'));
        if (!$period) {
            $this->error('No existe el periodo ID=' . $this->argument('period'));
            return 1;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(empty($weeklyIds) ? [] : $weeklyIds, [$period->id])));

        // ── PERIODO / ARCHIVO ──────────────────────────────────────────────
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("PERIODO: {$period->label} (ID {$period->id})");

        $lendusExcelId = DB::table('data_sources')->where('code', 'gastos_lendus_excel')->value('id');
        $upload = $lendusExcelId
            ? DB::table('report_uploads')
                ->whereIn('period_id', $dataIds)
                ->where('data_source_id', $lendusExcelId)
                ->orderByDesc('id')
                ->first()
            : null;

        if ($upload) {
            $this->info("ARCHIVO: {$upload->original_name} (report_uploads.id={$upload->id}, subido " . ($upload->uploaded_at ?? '—') . ')');
        } else {
            $this->warn('ARCHIVO: no hay ningún upload de gastos_lendus_excel para este periodo.');
        }
        $this->info('════════════════════════════════════════════════════════════');
        $this->newLine();

        // ── RESUMEN: filas Excel vs fact_expenses vs OPEX evaluable ─────────
        $filasFactExpenses = $upload
            ? (int) DB::table('fact_expenses')->where('report_upload_id', $upload->id)->count()
            : 0;

        $results = $service->attributeForPeriod($period, $dataIds, dryRun: true);

        $montoOpexEvaluable = collect($results)->sum('amount');

        $this->comment('RESUMEN');
        $this->line("  Filas fact_expenses del upload más reciente: {$filasFactExpenses} (1 fila Excel = 1 fila fact_expenses — el importador no agrupa ni descarta filas)");
        $this->line('  Filas OPEX evaluables por este servicio (tras excluir Nómina/IMSS/Fondeo/Pólizas y conceptos de Motos, ya resueltos por otro motor): ' . count($results));
        $this->line('  Monto OPEX evaluable: $' . number_format($montoOpexEvaluable, 2));
        $this->newLine();

        if (empty($results)) {
            $this->comment('No hay gastos del Excel de Lendus con Observación/Justificación para evaluar en este periodo (o falta el roster de colaboradores del periodo).');
            return 0;
        }

        $yaCorrecto   = array_values(array_filter($results, fn ($r) => $r['estado'] === 'ya_correcto'));
        $atribuido    = array_values(array_filter($results, fn ($r) => $r['estado'] === 'atribuido'));
        $porObs       = array_values(array_filter($atribuido, fn ($r) => $r['fuente'] === 'observation'));
        $porJust      = array_values(array_filter($atribuido, fn ($r) => $r['fuente'] === 'justification'));
        $conflictos   = array_values(array_filter($results, fn ($r) => $r['estado'] === 'conflicto'));
        $ambiguos     = array_values(array_filter($results, fn ($r) => $r['estado'] === 'ambiguo'));
        $noAtribuible = array_values(array_filter($results, fn ($r) => $r['estado'] === 'no_atribuible'));

        $montoEncontrados = collect($atribuido)->sum('amount') + collect($yaCorrecto)->sum('amount');
        $montoNoEncontrados = collect($noAtribuible)->sum('amount');
        $montoAmbiguo = collect($ambiguos)->sum('amount');
        $montoConflicto = collect($conflictos)->sum('amount');

        // ── ATRIBUCIÓN ───────────────────────────────────────────────────
        $this->comment('ATRIBUCIÓN');
        $this->line('  Encontrados y asignados ahora: ' . count($atribuido) . ' (por Observación: ' . count($porObs) . ', por Justificación: ' . count($porJust) . ')');
        $this->line('  Ya estaban correctos: ' . count($yaCorrecto));
        $this->line('  Reasignados (tenían otro employee_id y cambiaron): ' . count(array_filter($atribuido, fn ($r) => $r['previous_employee_id'] !== null)));
        $this->line('  No encontrados: ' . count($noAtribuible));
        $this->line('  Ambiguos: ' . count($ambiguos));
        $this->line('  Conflictos (Observación ≠ Justificación): ' . count($conflictos));
        $this->line('  Duplicados evitados: N/A — el servicio SOLO reasigna employee_id/branch_id de filas fact_expenses ya existentes (UPDATE), nunca inserta filas nuevas; no hay operación en este pipeline que pueda duplicar un gasto.');
        $this->newLine();

        // ── MONTOS ───────────────────────────────────────────────────────
        $this->comment('MONTOS');
        $this->line('  Monto de encontrados (nuevo + ya correcto): $' . number_format($montoEncontrados, 2));
        $this->line('  Monto de no encontrados: $' . number_format($montoNoEncontrados, 2));
        $this->line('  Monto ambiguo: $' . number_format($montoAmbiguo, 2));
        $this->line('  Monto conflicto: $' . number_format($montoConflicto, 2));
        $this->line('  Total comprobado: $' . number_format($montoEncontrados + $montoNoEncontrados + $montoAmbiguo + $montoConflicto, 2) . ' (debe ser igual al monto OPEX evaluable de arriba)');
        $this->newLine();

        // ── POR COLABORADOR ──────────────────────────────────────────────
        $byEmployee = [];
        foreach (array_merge($atribuido, $yaCorrecto) as $r) {
            $eid = $r['employee_id'];
            $byEmployee[$eid] ??= ['nombre' => $r['employee_name'], 'sucursal' => $r['branch_name'], 'cantidad' => 0, 'monto' => 0.0];
            $byEmployee[$eid]['cantidad']++;
            $byEmployee[$eid]['monto'] += $r['amount'];
        }
        uasort($byEmployee, fn ($a, $b) => $b['monto'] <=> $a['monto']);

        $this->comment('POR COLABORADOR (' . count($byEmployee) . ' colaboradores con gasto atribuido):');
        foreach ($byEmployee as $eid => $d) {
            $this->line(sprintf('  employee_id=%s | %s | %s | %d gasto(s) | $%s', $eid, $d['nombre'], $d['sucursal'] ?? 'sin sucursal', $d['cantidad'], number_format($d['monto'], 2)));
        }
        $this->newLine();

        // ── POR CONCEPTO ─────────────────────────────────────────────────
        $byConcept = [];
        foreach ($results as $r) {
            $key = $r['concept'] ?: 'Sin concepto';
            $byConcept[$key] ??= ['registros' => 0, 'encontrados' => 0, 'no_encontrados' => 0, 'monto' => 0.0];
            $byConcept[$key]['registros']++;
            if (in_array($r['estado'], ['atribuido', 'ya_correcto'], true)) {
                $byConcept[$key]['encontrados']++;
                $byConcept[$key]['monto'] += $r['amount'];
            } elseif ($r['estado'] === 'no_atribuible') {
                $byConcept[$key]['no_encontrados']++;
            }
        }
        uasort($byConcept, fn ($a, $b) => $b['monto'] <=> $a['monto']);

        $this->comment('POR CONCEPTO:');
        foreach ($byConcept as $concept => $data) {
            $this->line("  {$concept}: registros={$data['registros']} encontrados={$data['encontrados']} no_encontrados={$data['no_encontrados']} monto_atribuible=\$" . number_format($data['monto'], 2));
        }
        $this->newLine();

        // ── POR CATEGORÍA ────────────────────────────────────────────────
        $byCategory = [];
        foreach ($results as $r) {
            $key = $r['category'] ?: 'Sin categoría';
            $byCategory[$key] ??= ['registros' => 0, 'encontrados' => 0, 'no_encontrados' => 0, 'monto' => 0.0];
            $byCategory[$key]['registros']++;
            if (in_array($r['estado'], ['atribuido', 'ya_correcto'], true)) {
                $byCategory[$key]['encontrados']++;
                $byCategory[$key]['monto'] += $r['amount'];
            } elseif ($r['estado'] === 'no_atribuible') {
                $byCategory[$key]['no_encontrados']++;
            }
        }
        uasort($byCategory, fn ($a, $b) => $b['monto'] <=> $a['monto']);

        $this->comment('POR CATEGORÍA:');
        foreach ($byCategory as $category => $data) {
            $this->line("  {$category}: registros={$data['registros']} encontrados={$data['encontrados']} no_encontrados={$data['no_encontrados']} monto_atribuible=\$" . number_format($data['monto'], 2));
        }
        $this->newLine();

        // ── Ejemplos resueltos (mínimo pedido: varios conceptos distintos, no solo uno) ──
        $limit = max(1, (int) $this->option('examples'));
        $examples = collect($atribuido)->groupBy('concept')->flatMap(fn ($group) => $group->take(3))->take($limit);

        $this->comment("Ejemplos resueltos (hasta {$limit}):");
        foreach ($examples as $r) {
            $this->newLine();
            $this->line("fact_expenses.id={$r['fact_expense_id']}");
            $this->line("  Concepto: {$r['concept']}");
            if ($r['fuente'] === 'observation') {
                $this->line("  Observación: {$r['observation']}");
            } else {
                $this->line("  Justificación: {$r['justification']}");
            }
            $this->line("  → employee_id={$r['employee_id']} ({$r['employee_name']})");
            $this->line('  → sucursal histórica=' . ($r['branch_name'] ?? 'sin resolver'));
            $this->line("  → método={$r['fuente']}_{$r['metodo']} confianza=" . number_format($r['confianza'], 2));
            $this->line('  → monto=$' . number_format($r['amount'], 2));
        }
        $this->newLine();

        // ── NO ENCONTRADOS — ejemplos con texto normalizado y razón ─────────
        $notFoundLimit = max(1, (int) $this->option('not-found'));
        $notFoundExamples = array_slice($noAtribuible, 0, $notFoundLimit);
        if (!empty($notFoundExamples)) {
            $this->comment('NO ENCONTRADOS (hasta ' . $notFoundLimit . ' de ' . count($noAtribuible) . '):');
            foreach ($notFoundExamples as $r) {
                $this->newLine();
                $this->line("fact_expenses.id={$r['fact_expense_id']} | {$r['concept']} | \$" . number_format($r['amount'], 2));
                $this->line('  Observación: ' . ($r['observation'] ?? '—'));
                $this->line('  Justificación: ' . ($r['justification'] ?? '—'));
                $this->line('  Solicitante original (columna Empleado): ' . ($r['raw_employee_name'] ?? '—'));
                $this->line('  Razón: ' . ($r['reason'] ?? '—'));
            }
            $this->newLine();
        }

        // ── AMBIGUOS — candidatos y score de cada uno ───────────────────────
        $ambiguousLimit = max(1, (int) $this->option('ambiguous'));
        $ambiguousExamples = array_slice($ambiguos, 0, $ambiguousLimit);
        if (!empty($ambiguousExamples)) {
            $this->comment('AMBIGUOS (hasta ' . $ambiguousLimit . ' de ' . count($ambiguos) . '):');
            foreach ($ambiguousExamples as $r) {
                $this->newLine();
                $this->line("fact_expenses.id={$r['fact_expense_id']} | {$r['concept']} | \$" . number_format($r['amount'], 2));
                $this->line('  Texto (' . $r['fuente'] . '): ' . ($r['fuente'] === 'justification' ? $r['justification'] : $r['observation']));
                $this->line('  Razón: ' . ($r['reason'] ?? '—'));
                if (!empty($r['candidates'])) {
                    $this->line('  Candidatos considerados:');
                    foreach ($r['candidates'] as $c) {
                        $this->line(sprintf('    - employee_id=%s | %s | score=%s%% | método=%s', $c['employee_id'] ?? '—', $c['name'] ?? '—', $c['score'] ?? '—', $c['method'] ?? '—'));
                    }
                }
            }
            $this->newLine();
        }

        if (!empty($conflictos)) {
            $this->comment('CONFLICTOS (requieren revisión manual — Observación y Justificación nombran personas distintas):');
            foreach (array_slice($conflictos, 0, 10) as $r) {
                $this->error("  fact_expenses.id={$r['fact_expense_id']} | {$r['concept']} | Observación=\"{$r['observation']}\" | Justificación=\"{$r['justification']}\" | \$" . number_format($r['amount'], 2));
            }
            $this->newLine();
        }

        // ── NÚMEROS REALES — resumen final tal como se exige reportar ──────
        $this->info('════════════════════════════════════════════════════════════');
        $this->info('NÚMEROS REALES');
        $this->info('Total evaluados: ' . count($results));
        $this->info('Con colaborador: ' . (count($atribuido) + count($yaCorrecto)));
        $this->info('Sin colaborador: ' . count($noAtribuible));
        $this->info('Ambiguos: ' . count($ambiguos));
        $this->info('Conflictos: ' . count($conflictos));
        $this->info('Monto identificado: $' . number_format($montoEncontrados, 2));
        $this->info('Monto sin identificar: $' . number_format($montoNoEncontrados + $montoAmbiguo + $montoConflicto, 2));
        $this->info('Total evaluado (OPEX evaluable): $' . number_format($montoOpexEvaluable, 2));
        $this->info('════════════════════════════════════════════════════════════');

        return 0;
    }
}
