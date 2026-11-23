<?php

namespace App\Services;

use App\Models\Period;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Resuelve y guarda branch_id/employee_id en las filas de gastos_lendus_excel que se
 * importan con branch_id = NULL (ese archivo no trae columna de sucursal — confirmado:
 * 672 de 848 filas en Junio 2026, $4,235,962.85).
 *
 * Causa raíz / solución: gastos_lendus (PDF) y gastos_lendus_excel (Excel) son dos
 * exportaciones de EXACTAMENTE las mismas transacciones — verificado en Junio 2026: mismo
 * número de filas (848) y mismo total ($4,393,164.00). El importador del PDF ya resuelve
 * branch_id en el 100% de sus filas (con BranchResolverService), el del Excel nunca. Así
 * que, en lugar de inventar otra heurística por nombre, se empareja cada fila del Excel
 * con su contraparte del PDF por (monto, fecha) — 100% de coincidencia en Junio 2026 sin
 * diferencias de tamaño de grupo — y se copian branch_id/employee_id. Si no hay par en
 * el PDF, se intenta por identidad del colaborador (nombre en Observaciones).
 *
 * Idempotente: las filas que ya traen branch_id no se vuelven a tocar ni a reportar.
 */
class GastosExcelBranchResolverService
{
    /**
     * @param  FinanciamientoMotosAssignmentService  $identityResolver  Resuelve colaborador/sucursal por el nombre en Observaciones cuando no hay par en el PDF.
     */
    public function __construct(
        private readonly FinanciamientoMotosAssignmentService $identityResolver,
    ) {}

    /**
     * Empareja las filas del Excel de gastos Lendus sin sucursal con el PDF y les asigna
     * sucursal/colaborador.
     *
     * Reglas: excluye los conceptos de Motos/Cascos (tienen su propio resolvedor);
     * empareja 1:1 por (monto, fecha) respetando el orden de id dentro de cada grupo;
     * si el par del PDF cae en sucursal no operativa se marca 'excluido_no_operativo';
     * sin par, intenta por identidad; si tampoco, queda 'sin_resolver'.
     * Efectos: si no es simulación, actualiza branch_id/employee_id en fact_expenses.
     *
     * @param  Period  $period  Periodo del reporte (no se usa en las consultas; los periodos salen de $dataIds).
     * @param  int[]  $dataIds  Periodos donde viven los gastos (el periodo y sus semanas base).
     * @param  bool  $dryRun  true = solo calcula y reporta, sin escribir en BD.
     * @return array<int, array{
     *   fact_expense_id:int, category:string, concept:string, amount:float, expense_date:?string,
     *   period_id:int, report_upload_id:int, branch_id:?int, sucursal:?string, employee_id:?int,
     *   metodo:string, estado:string,
     * }>
     */
    public function resolveForPeriod(Period $period, array $dataIds, bool $dryRun = false): array
    {
        $operativeMap = app(BranchRadiographyCalculator::class)
            ->buildBranchMap()['operative'];

        $pdfId = DB::table('data_sources')->where('code', 'gastos_lendus')->value('id');
        $excelId = DB::table('data_sources')->where('code', 'gastos_lendus_excel')->value('id');
        if (! $pdfId || ! $excelId) {
            return [];
        }

        // PDF rows: 100% resolved branch_id (fully populated at import time). Group by
        // (amount, expense_date) preserving row id order within each group.
        $pdfRows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $pdfId)
            ->whereNotNull('e.branch_id')
            ->orderBy('e.id')
            ->get(['e.id', 'e.amount', 'e.expense_date', 'e.branch_id', 'e.employee_id']);

        $pdfGroups = [];
        foreach ($pdfRows as $r) {
            $key = $this->groupKey((float) $r->amount, $r->expense_date);
            $pdfGroups[$key][] = $r;
        }

        // Financiamiento de Motos/Cascos son pagos recurrentes de MISMO importe cada
        // periodo (p. ej. $749 cada ~2 semanas) — el emparejamiento por (monto, fecha
        // exacta) contra el PDF es estructuralmente frágil ahí (varias filas candidatas
        // con el mismo monto y fechas cercanas, ninguna exactamente igual). Esas filas
        // tienen su propio resolvedor dedicado (FinanciamientoMotosAssignmentService,
        // que resuelve por identidad/observations, no por espejo de PDF) y su propio
        // OrFail más adelante en el pipeline — no deben contarse aquí como "sin_resolver".
        $delegatedConcepts = array_map('mb_strtoupper', FinanciamientoMotosAssignmentService::CONCEPTS);

        $excelRows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $excelId)
            ->whereNull('e.branch_id')
            ->whereNotIn(DB::raw("UPPER(TRIM(COALESCE(e.concept,'')))"), $delegatedConcepts)
            ->orderBy('e.id')
            ->get(['e.id', 'e.category', 'e.concept', 'e.amount', 'e.expense_date', 'e.period_id', 'e.report_upload_id', 'e.observations']);

        // Position counter per group so multiple same (amount, date) rows pair 1:1 in order.
        $cursor = [];
        $results = [];

        foreach ($excelRows as $row) {
            $key = $this->groupKey((float) $row->amount, $row->expense_date);
            $pos = $cursor[$key] ?? 0;
            $cursor[$key] = $pos + 1;

            $pdfMatch = $pdfGroups[$key][$pos] ?? null;

            if ($pdfMatch && isset($operativeMap[(int) $pdfMatch->branch_id])) {
                if (! $dryRun) {
                    DB::table('fact_expenses')->where('id', $row->id)->update([
                        'branch_id' => $pdfMatch->branch_id,
                        'employee_id' => $pdfMatch->employee_id,
                        'updated_at' => now(),
                    ]);
                }
                $results[] = [
                    'fact_expense_id' => $row->id,
                    'category' => $row->category,
                    'concept' => $row->concept,
                    'amount' => (float) $row->amount,
                    'expense_date' => $row->expense_date,
                    'period_id' => $row->period_id,
                    'report_upload_id' => $row->report_upload_id,
                    'branch_id' => (int) $pdfMatch->branch_id,
                    'sucursal' => $operativeMap[(int) $pdfMatch->branch_id],
                    'employee_id' => $pdfMatch->employee_id,
                    'metodo' => 'pdf_amount_date_match',
                    'estado' => 'resuelto',
                ];
            } elseif ($pdfMatch) {
                // Matched a PDF row, but that PDF row's own branch is non-operative
                // (Corporativo/Tulancingo/etc.) — real exclusion, not a resolution failure.
                $results[] = [
                    'fact_expense_id' => $row->id,
                    'category' => $row->category,
                    'concept' => $row->concept,
                    'amount' => (float) $row->amount,
                    'expense_date' => $row->expense_date,
                    'period_id' => $row->period_id,
                    'report_upload_id' => $row->report_upload_id,
                    'branch_id' => null,
                    'sucursal' => null,
                    'employee_id' => null,
                    'metodo' => 'pdf_amount_date_match_non_operative',
                    'estado' => 'excluido_no_operativo',
                ];
            } else {
                // Sin espejo en el PDF (ninguna fila con mismo monto+fecha) — antes de
                // declararlo irresoluble, intenta resolver por IDENTIDAD del colaborador
                // usando el mismo motor que ya usa Financiamiento de Motos/Cascos
                // (nombre en `observations` → empleado exacto/alias/canónico → sucursal
                // actual, histórica o de baja). Esto es lo correcto para conceptos como
                // PAGO FINIQUITO: la persona puede ya no estar vigente en Lendus y aun
                // así el gasto es real y debe contabilizarse con su sucursal histórica —
                // "no está vigente" NO es lo mismo que "el gasto es basura".
                $identity = $this->identityResolver->resolve(
                    (string) ($row->observations ?? ''),
                    $dataIds,
                    $operativeMap,
                );

                if ($identity) {
                    if (! $dryRun) {
                        DB::table('fact_expenses')->where('id', $row->id)->update([
                            'branch_id' => $identity['branch_id'],
                            'employee_id' => $identity['employee_id'],
                            'updated_at' => now(),
                        ]);
                    }
                    $results[] = [
                        'fact_expense_id' => $row->id,
                        'category' => $row->category,
                        'concept' => $row->concept,
                        'amount' => (float) $row->amount,
                        'expense_date' => $row->expense_date,
                        'period_id' => $row->period_id,
                        'report_upload_id' => $row->report_upload_id,
                        'branch_id' => $identity['branch_id'],
                        'sucursal' => $identity['branch_name'],
                        'employee_id' => $identity['employee_id'],
                        'metodo' => 'identity_'.$identity['method'],
                        'estado' => 'resuelto',
                    ];
                } else {
                    $results[] = [
                        'fact_expense_id' => $row->id,
                        'category' => $row->category,
                        'concept' => $row->concept,
                        'amount' => (float) $row->amount,
                        'expense_date' => $row->expense_date,
                        'period_id' => $row->period_id,
                        'report_upload_id' => $row->report_upload_id,
                        'branch_id' => null,
                        'sucursal' => null,
                        'employee_id' => null,
                        'metodo' => 'sin_par_pdf_sin_identidad',
                        'estado' => 'sin_resolver',
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Igual que resolveForPeriod() (escribiendo en BD), pero lanza una excepción con
     * mensaje accionable si alguna fila queda realmente sin resolver (sin par en el PDF
     * por monto+fecha ni identidad) — para detener la generación en vez de perder el monto.
     *
     * @param  Period  $period  Periodo del reporte (se usa su etiqueta en el mensaje).
     * @param  int[]  $dataIds  Periodos donde viven los gastos.
     * @return array Resultados por fila, mismo formato que resolveForPeriod().
     *
     * @throws \RuntimeException Si queda al menos una fila 'sin_resolver' (lista cada fila).
     */
    public function resolveForPeriodOrFail(Period $period, array $dataIds): array
    {
        $results = $this->resolveForPeriod($period, $dataIds);
        $unresolved = array_filter($results, fn (array $r) => $r['estado'] === 'sin_resolver');

        if (! empty($unresolved)) {
            $detail = collect($unresolved)->map(fn (array $r) => sprintf(
                '  - fact_expenses.id=%d | %s / %s | $%s | %s | report_upload_id=%d',
                $r['fact_expense_id'], $r['category'], $r['concept'], number_format($r['amount'], 2),
                $r['expense_date'] ?? 'sin fecha', $r['report_upload_id']
            ))->implode("\n");

            throw new \RuntimeException(
                'No se pudo emparejar contra el PDF de Lendus '.count($unresolved).
                " registro(s) del Excel de gastos del periodo {$period->label} (sin fila de monto+fecha ".
                "equivalente en el PDF) — generación detenida:\n".$detail
            );
        }

        return $results;
    }

    /**
     * Arma la clave de emparejamiento "monto(4 decimales)|fecha".
     *
     * @param  float  $amount  Monto del gasto.
     * @param  string|null  $expenseDate  Fecha del gasto (vacío si no tiene).
     * @return string Clave del grupo.
     */
    private function groupKey(float $amount, ?string $expenseDate): string
    {
        return number_format($amount, 4, '.', '').'|'.($expenseDate ?? '');
    }
}
