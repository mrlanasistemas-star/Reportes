<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\ExpenseObservationAttributionService;
use App\Services\OpexClassificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Auditoría 27-ago-2026 (ampliada 07-sep-2026, cerrada 07-sep-2026) —
 * atribución de OPEX a colaboradores vía Observación/Justificación (ver
 * Services/ExpenseObservationAttributionService.php). SOLO diagnóstico —
 * nunca escribe (el propio servicio corre en dryRun=true).
 *
 *   php artisan reports:audit-expense-attribution 28
 *   php artisan reports:audit-expense-attribution 28 --examples=40
 *   php artisan reports:audit-expense-attribution 28 --export
 *
 * Cierre 07-sep-2026: distingue DOS universos, nunca mezclados —
 *   A) OPEX TOTAL FINANCIERO: TODAS las filas fact_expenses del upload
 *      (incluye las que antes se excluían por observations NULL), clasificadas
 *      vía OpexClassificationService (fuente canónica única).
 *   B) EVALUABLE PARA MATCH DE PERSONA: subconjunto de A con is_opex=true —
 *      lo que devuelve ExpenseObservationAttributionService::attributeForPeriod()
 *      (que YA excluye Nómina/IMSS/Fondeo/Excedentes/Pólizas por diseño, y YA
 *      NO excluye filas sin observations — quedan como 'branch_general' o
 *      'no_atribuible', nunca desaparecen del universo).
 * Dentro de B: colaborador / branch_general (gasto general de sucursal, NO es
 * un error) / ambiguo / conflicto / sin destino real.
 */
class ReportsAuditExpenseAttributionCommand extends Command
{
    protected $signature = 'reports:audit-expense-attribution
        {period : ID del periodo}
        {--examples=20 : Cuántos ejemplos resueltos mostrar}
        {--not-found=30 : Cuántos ejemplos de "no encontrados" mostrar}
        {--ambiguous=30 : Cuántos ejemplos de "ambiguos" mostrar}
        {--export : Exporta el detalle completo a storage/app/audits/opex_attribution_period_{id}.xlsx}';

    protected $description = 'Diagnóstico (dry-run) de atribución de OPEX a colaboradores vía Observación/Justificación del Excel de Gastos Lendus.';

    public function handle(ExpenseObservationAttributionService $service, OpexClassificationService $opexClassifier): int
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

        // ── UNIVERSO A — OPEX TOTAL FINANCIERO (TODAS las filas, sin filtro de observations) ──
        // MISMO alcance que Universo B (whereIn dataIds + data_source_id, no solo el
        // último upload) — un periodo compuesto (mensual derivado de semanas) puede
        // tener más de un report_upload de gastos_lendus_excel; limitarse al último
        // dejaría fuera filas reales que sí forman parte del periodo auditado.
        $allRows = $lendusExcelId
            ? DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->whereIn('e.period_id', $dataIds)
                ->where('ru.data_source_id', $lendusExcelId)
                ->select(
                    'e.id', 'e.category', 'e.concept', 'e.employee_id', 'e.branch_id', 'e.observations', 'e.attribution_needs_review',
                    DB::raw('COALESCE(NULLIF(e.paid_amount,0), e.amount) as amount')
                )
                ->get()
            : collect();

        $classifiedAll = $allRows->map(function ($row) use ($opexClassifier) {
            $row->classification = $opexClassifier->classify($row->category, $row->concept, OpexClassificationService::SOURCE_LENDUS);
            return $row;
        });

        $opexRowsA    = $classifiedAll->filter(fn ($r) => $r->classification['is_opex']);
        $nonOpexRowsA = $classifiedAll->filter(fn ($r) => !$r->classification['is_opex']);

        $this->comment('UNIVERSO A — OPEX TOTAL FINANCIERO (todas las filas del upload, incluidas las que no tienen Observación/Justificación):');
        $this->line('  Filas totales: ' . $classifiedAll->count() . ' | Monto total: $' . number_format($classifiedAll->sum('amount'), 2));
        $this->line('  Filas OPEX puro (is_opex=true): ' . $opexRowsA->count() . ' | Monto: $' . number_format($opexRowsA->sum('amount'), 2));
        $this->line('  Filas NO-OPEX (' . $nonOpexRowsA->count() . ' | $' . number_format($nonOpexRowsA->sum('amount'), 2) . '), por tipo:');
        $byExclusionType = $nonOpexRowsA->groupBy(fn ($r) => $r->classification['type']);
        foreach ($byExclusionType as $type => $group) {
            $eligibleNote = $type === \App\Services\OpexClassificationService::TYPE_NOMINA_EMPLEADO
                ? ' (NO es OPEX, pero SÍ es atribuible a la persona que lo recibió — Finiquito/Médicos/Motos)'
                : ' (nunca atribuible a un colaborador)';
            $this->line("    - {$type}: " . $group->count() . ' filas | $' . number_format($group->sum('amount'), 2) . $eligibleNote);
        }
        $this->newLine();

        // ── UNIVERSO B — evaluable para match de persona (is_opex=true O nomina_empleado de A) ──
        $results = $service->attributeForPeriod($period, $dataIds, dryRun: true);
        $montoOpexEvaluable = collect($results)->sum('amount');

        $this->comment('UNIVERSO B — EVALUABLE PARA MATCH DE PERSONA (elegibles para atribución: OPEX puro + Nómina-empleado de A — NUNCA incluye Nómina cubierta por NOI/Fondeo/Excedentes/Pólizas):');
        $this->line('  Filas: ' . count($results) . ' | Monto: $' . number_format($montoOpexEvaluable, 2) . ' (puede incluir Finiquito/Médicos — ver POR CONCEPTO/CATEGORÍA para distinguirlos del OPEX puro)');
        $this->newLine();

        if (empty($results)) {
            $this->comment('No hay gastos OPEX evaluables en este periodo (o falta el roster de colaboradores del periodo).');
            return 0;
        }

        $yaCorrecto     = array_values(array_filter($results, fn ($r) => $r['estado'] === 'ya_correcto'));
        $atribuido      = array_values(array_filter($results, fn ($r) => $r['estado'] === 'atribuido'));
        $porObs         = array_values(array_filter($atribuido, fn ($r) => $r['fuente'] === 'observation'));
        $porJust        = array_values(array_filter($atribuido, fn ($r) => $r['fuente'] === 'justification'));
        $conflictos     = array_values(array_filter($results, fn ($r) => $r['estado'] === 'conflicto'));
        $ambiguos       = array_values(array_filter($results, fn ($r) => $r['estado'] === 'ambiguo'));
        $branchGeneral  = array_values(array_filter($results, fn ($r) => $r['estado'] === 'branch_general'));
        $noAtribuible   = array_values(array_filter($results, fn ($r) => $r['estado'] === 'no_atribuible'));

        $montoEncontrados     = collect($atribuido)->sum('amount') + collect($yaCorrecto)->sum('amount');
        $montoBranchGeneral   = collect($branchGeneral)->sum('amount');
        $montoAmbiguo         = collect($ambiguos)->sum('amount');
        $montoConflicto       = collect($conflictos)->sum('amount');
        $montoSinDestinoReal  = collect($noAtribuible)->sum('amount');

        // ── ATRIBUCIÓN ───────────────────────────────────────────────────
        $this->comment('ATRIBUCIÓN');
        $this->line('  Con colaborador (nuevo + ya correcto): ' . (count($atribuido) + count($yaCorrecto)) . ' | $' . number_format($montoEncontrados, 2));
        $this->line('    - Encontrados y asignados ahora: ' . count($atribuido) . ' (por Observación: ' . count($porObs) . ', por Justificación: ' . count($porJust) . ')');
        $this->line('    - Ya estaban correctos: ' . count($yaCorrecto));
        $this->line('    - Reasignados (tenían otro employee_id y cambiaron): ' . count(array_filter($atribuido, fn ($r) => $r['previous_employee_id'] !== null)));
        $this->line('  General de sucursal (branch_general — sin colaborador, pero NO es un error: renta/luz/agua/limpieza/etc. con sucursal ya resuelta): ' . count($branchGeneral) . ' | $' . number_format($montoBranchGeneral, 2));
        $this->line('  Ambiguo: ' . count($ambiguos) . ' | $' . number_format($montoAmbiguo, 2));
        $this->line('  Conflicto (Observación ≠ Justificación): ' . count($conflictos) . ' | $' . number_format($montoConflicto, 2));
        $this->line('  Verdaderamente sin destino (ni colaborador ni sucursal resuelta): ' . count($noAtribuible) . ' | $' . number_format($montoSinDestinoReal, 2));
        $this->line('  Duplicados evitados: N/A — el servicio SOLO reasigna employee_id/branch_id de filas fact_expenses ya existentes (UPDATE), nunca inserta filas nuevas.');
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

        // ── NO ENCONTRADOS — CLASIFICACIÓN FINAL (agrupado, sección 6 del pedido) ──
        $this->comment('NO ENCONTRADOS — CLASIFICACIÓN FINAL:');
        $this->line('  GENERAL SUCURSAL: cantidad ' . count($branchGeneral) . ' | monto $' . number_format($montoBranchGeneral, 2));
        $this->line('  AMBIGUOS: cantidad ' . count($ambiguos) . ' | monto $' . number_format($montoAmbiguo, 2));
        $this->line('  CONFLICTOS: cantidad ' . count($conflictos) . ' | monto $' . number_format($montoConflicto, 2));
        $this->line('  SIN INFORMACIÓN (verdaderamente sin destino): cantidad ' . count($noAtribuible) . ' | monto $' . number_format($montoSinDestinoReal, 2));
        $this->newLine();

        // ── OPEX SIN DESTINO — invariante (sección 8 del pedido) ────────────
        // is_opex=true AND (proyectado tras aplicar la atribución) employee_id
        // NULL AND branch_id NULL AND needs_review=false — exactamente lo que
        // resolveRow() clasifica como 'no_atribuible' (branch_general/ambiguo/
        // conflicto SÍ tienen un destino o quedan marcados needs_review=true, así
        // que nunca cuentan aquí). Se evalúa sobre el RESULTADO proyectado del
        // dry-run, no sobre el estado crudo de la BD — si este comando corriera
        // sobre un periodo donde la atribución real (dryRun=false) TODAVÍA no se
        // ha ejecutado, el estado crudo de fact_expenses tendría employee_id/
        // branch_id en NULL para filas que SÍ tienen un destino resoluble, lo que
        // daría falsos positivos.
        $this->comment('OPEX SIN DESTINO (invariante — debe tender a 0):');
        $this->line('  count = ' . count($noAtribuible) . ' | amount = $' . number_format($montoSinDestinoReal, 2));
        if (!empty($noAtribuible)) {
            $this->warn('  Filas sin colaborador, sin sucursal resuelta y sin ambigüedad/conflicto pendiente (revisar manualmente):');
            foreach ($noAtribuible as $r) {
                $this->warn("    fact_expenses.id={$r['fact_expense_id']} | {$r['concept']} | \$" . number_format($r['amount'], 2) . ' | ' . ($r['reason'] ?? ''));
            }
        }
        $this->newLine();

        // ── POR CONCEPTO / CATEGORÍA ─────────────────────────────────────
        $byConcept = [];
        foreach ($results as $r) {
            $key = $r['concept'] ?: 'Sin concepto';
            $byConcept[$key] ??= ['registros' => 0, 'encontrados' => 0, 'no_encontrados' => 0, 'monto' => 0.0];
            $byConcept[$key]['registros']++;
            if (in_array($r['estado'], ['atribuido', 'ya_correcto'], true)) {
                $byConcept[$key]['encontrados']++;
                $byConcept[$key]['monto'] += $r['amount'];
            } elseif (in_array($r['estado'], ['no_atribuible', 'branch_general'], true)) {
                $byConcept[$key]['no_encontrados']++;
            }
        }
        uasort($byConcept, fn ($a, $b) => $b['monto'] <=> $a['monto']);

        $this->comment('POR CONCEPTO:');
        foreach ($byConcept as $concept => $data) {
            $this->line("  {$concept}: registros={$data['registros']} encontrados={$data['encontrados']} no_encontrados={$data['no_encontrados']} monto_atribuible=\$" . number_format($data['monto'], 2));
        }
        $this->newLine();

        $byCategory = [];
        foreach ($results as $r) {
            $key = $r['category'] ?: 'Sin categoría';
            $byCategory[$key] ??= ['registros' => 0, 'encontrados' => 0, 'no_encontrados' => 0, 'monto' => 0.0];
            $byCategory[$key]['registros']++;
            if (in_array($r['estado'], ['atribuido', 'ya_correcto'], true)) {
                $byCategory[$key]['encontrados']++;
                $byCategory[$key]['monto'] += $r['amount'];
            } elseif (in_array($r['estado'], ['no_atribuible', 'branch_general'], true)) {
                $byCategory[$key]['no_encontrados']++;
            }
        }
        uasort($byCategory, fn ($a, $b) => $b['monto'] <=> $a['monto']);

        $this->comment('POR CATEGORÍA:');
        foreach ($byCategory as $category => $data) {
            $this->line("  {$category}: registros={$data['registros']} encontrados={$data['encontrados']} no_encontrados={$data['no_encontrados']} monto_atribuible=\$" . number_format($data['monto'], 2));
        }
        $this->newLine();

        // ── Ejemplos resueltos ────────────────────────────────────────────
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

        // ── AMBIGUOS — candidatos y score ────────────────────────────────
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

        $notFoundLimit = max(1, (int) $this->option('not-found'));
        $notFoundExamples = array_slice($noAtribuible, 0, $notFoundLimit);
        if (!empty($notFoundExamples)) {
            $this->comment('SIN INFORMACIÓN — verdaderamente sin destino (hasta ' . $notFoundLimit . ' de ' . count($noAtribuible) . '):');
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

        // ── NÚMEROS REALES — resumen final ──────────────────────────────
        $this->info('════════════════════════════════════════════════════════════');
        $this->info('NÚMEROS REALES');
        $this->info('TOTAL fact_expenses del upload: ' . $classifiedAll->count());
        $this->info('TOTAL OPEX: ' . $opexRowsA->count() . ' registros | $' . number_format($opexRowsA->sum('amount'), 2));
        $this->info('OPEX con colaborador: ' . (count($atribuido) + count($yaCorrecto)) . ' | $' . number_format($montoEncontrados, 2));
        $this->info('OPEX general de sucursal: ' . count($branchGeneral) . ' | $' . number_format($montoBranchGeneral, 2));
        $this->info('OPEX ambiguo: ' . count($ambiguos) . ' | $' . number_format($montoAmbiguo, 2));
        $this->info('OPEX conflicto: ' . count($conflictos) . ' | $' . number_format($montoConflicto, 2));
        $this->info('OPEX sin destino: ' . count($noAtribuible) . ' | $' . number_format($montoSinDestinoReal, 2));
        $this->info('No-OPEX excluido: ' . $nonOpexRowsA->count() . ' | $' . number_format($nonOpexRowsA->sum('amount'), 2));
        $this->info('Reasignaciones propuestas: ' . count(array_filter($atribuido, fn ($r) => $r['previous_employee_id'] !== null)));
        $this->info('Reasignaciones ya correctas: ' . count($yaCorrecto));
        $this->info('════════════════════════════════════════════════════════════');

        if ($this->option('export')) {
            $path = $this->exportToExcel($period, $upload, $results, $classifiedAll);
            $this->info("Exportado: {$path}");
        }

        return 0;
    }

    /**
     * Auditoría 07-sep-2026 (sección 15/8 del pedido) — vuelca el detalle
     * completo (universo A clasificado + resultado de atribución de universo B)
     * a un .xlsx para revisión humana. Nunca escribe en fact_expenses — es un
     * export de solo lectura.
     */
    private function exportToExcel(Period $period, ?object $upload, array $results, \Illuminate\Support\Collection $classifiedAll): string
    {
        $byId = collect($results)->keyBy('fact_expense_id');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Auditoria OPEX');

        $headers = [
            'FACT_EXPENSE_ID', 'PERIOD', 'UPLOAD', 'CATEGORY', 'CONCEPT', 'AMOUNT',
            'RAW_EMPLOYEE_NAME', 'OBSERVATION', 'JUSTIFICATION',
            'CURRENT_EMPLOYEE_ID', 'CURRENT_EMPLOYEE_NAME', 'CURRENT_BRANCH',
            'PROPOSED_EMPLOYEE_ID', 'PROPOSED_EMPLOYEE_NAME', 'PROPOSED_BRANCH',
            'CLASSIFICATION', 'METHOD', 'CONFIDENCE', 'REASON', 'IS_OPEX', 'DESTINATION_TYPE',
        ];
        foreach ($headers as $i => $h) {
            $sheet->setCellValueByColumnAndRow($i + 1, 1, $h);
        }

        $destinationTypeFor = fn (string $estado) => match ($estado) {
            'atribuido', 'ya_correcto' => 'employee',
            'branch_general'           => 'branch_general',
            'ambiguo'                  => 'ambiguous',
            'conflicto'                => 'conflict',
            default                    => 'unresolved',
        };

        $row = 2;
        foreach ($classifiedAll as $fe) {
            $result = $byId->get($fe->id);
            $isOpex = $fe->classification['is_opex'];

            $values = [
                $fe->id,
                $period->label,
                $upload->original_name ?? '',
                $fe->category,
                $fe->concept,
                (float) $fe->amount,
                $result['raw_employee_name'] ?? null,
                $result['observation'] ?? $fe->observations,
                $result['justification'] ?? null,
                $result['previous_employee_id'] ?? $fe->employee_id,
                null,
                null,
                $result['employee_id'] ?? null,
                $result['employee_name'] ?? null,
                $result['branch_name'] ?? null,
                $fe->classification['type'],
                $result['metodo'] ?? null,
                $result['confianza'] ?? null,
                $result['reason'] ?? $fe->classification['exclusion_reason'],
                $isOpex ? 'YES' : 'NO',
                $isOpex ? ($result ? $destinationTypeFor($result['estado']) : 'unresolved') : 'excluded_non_opex',
            ];
            foreach ($values as $i => $v) {
                $sheet->setCellValueByColumnAndRow($i + 1, $row, $v);
            }
            $row++;
        }

        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->setAutoFilter("A1:{$lastCol}1");
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $directory = storage_path('app/audits');
        File::ensureDirectoryExists($directory);
        $path = $directory . "/opex_attribution_period_{$period->id}.xlsx";
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

        return $path;
    }
}
