<?php

namespace App\Services\ExpenseReview;

use App\Models\ExpenseReview\ExpenseConceptRule;
use App\Models\ExpenseReview\ExpenseReviewDecision as D;
use App\Models\ExpenseReview\ExpenseReviewLog;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Revisión manual de gastos (23-nov-2026) — capa OPCIONAL encima de la atribución
 * automática. NO cambia ninguna regla de negocio: solo escribe, por decisión explícita
 * del usuario, las MISMAS columnas que ya usa todo el pipeline:
 *
 *  - fact_expenses.employee_id  (beneficiario — igual que ExpenseObservationAttributionService)
 *  - fact_expenses.branch_id    (sucursal; CORPORATIVO = la sucursal CORPORATIVO existente,
 *                                que BranchRadiographyCalculator ya excluye de sucursales y
 *                                que el Estado de Resultados ya lee como universo «corporativo»)
 *  - fact_expenses.category     (destino por concepto, vía expense_concept_rules)
 *
 * Persistencia ante reimportación: los importadores borran y reinsertan fact_expenses, así
 * que cada decisión se guarda con una huella estable (fuente+fecha+concepto+monto+
 * observaciones+ocurrencia) y applyForPeriod() la vuelve a aplicar:
 *   1) al terminar cada importación de gastos (ReportAnalysisService),
 *   2) después de la atribución automática en GenerateRadiographyJob (para que la decisión
 *      manual gane sobre el texto de Observación/Justificación),
 *   3) al guardar la decisión.
 *
 * Sin decisiones ni reglas, applyForPeriod() no escribe NADA (sale antes de leer filas):
 * las cifras quedan exactamente igual que hoy.
 *
 * Quién lo usa: ExpenseReviewController (guardar decisiones y reglas desde la UI),
 * ReportAnalysisService (reaplicar tras importar), GenerateRadiographyJob (reaplicar antes de
 * calcular), PeriodCloseGuardService (installed()) y ExpenseReviewQuery (huellas/corporativo).
 *
 * Efectos: escribe fact_expenses (employee_id, branch_id, category y columnas de trazabilidad),
 * expense_review_decisions, expense_concept_rules, expense_review_logs y period_summaries
 * (marca la radiografía para regenerar).
 */
class ExpenseReviewService
{
    /** attribution_method cuando el usuario asignó un colaborador. */
    public const METHOD_MANUAL = 'manual';

    /** attribution_method cuando el usuario confirmó «sin colaborador». */
    public const METHOD_MANUAL_NONE = 'manual_ninguno';

    /** attribution_source de todo gasto tocado por la revisión manual. */
    public const SOURCE_MANUAL = 'revision_manual';

    /** Fuentes cuyo concepto admite regla de destino → tipo de fuente de OpexClassificationService. */
    public const SOURCE_TYPES = [
        'gastos_lendus' => 'lendus',
        'gastos_lendus_excel' => 'lendus',
        'gastos' => 'lendus',
        'gastos_erp' => 'erp',
    ];

    /** Fuentes cuyos importadores escriben fact_expenses (tras importarlas se reaplican decisiones). */
    public const EXPENSE_SOURCES = ['gastos_lendus', 'gastos_lendus_excel', 'gastos', 'gastos_erp', 'imss'];

    /** Memo estático de installed(): solo se memoriza el «true». */
    private static ?bool $installed = null;

    /**
     * @param  BranchRadiographyCalculator  $branchCalculator  Se usa para resolver la sucursal CORPORATIVO
     *                                                         con la misma regla que la radiografía.
     */
    public function __construct(private readonly BranchRadiographyCalculator $branchCalculator) {}

    /**
     * Indica si la revisión manual está instalada (tabla expense_review_decisions y columna
     * fact_expenses.employee_review_status existen). Tolera entornos sin la migración aplicada
     * (nunca rompe importación/generación/cierre): cualquier error de esquema devuelve false.
     *
     * @return bool true si la migración 2026_11_23_300001 ya corrió.
     */
    public static function installed(): bool
    {
        // Se memoriza sólo el «sí»: un entorno recién migrado lo detecta sin reiniciar workers.
        if (self::$installed !== true) {
            try {
                self::$installed = Schema::hasTable('expense_review_decisions') && Schema::hasColumn('fact_expenses', 'employee_review_status');
            } catch (\Throwable) {
                self::$installed = false;
            }
        }

        return self::$installed;
    }

    /**
     * Normaliza un texto para comparar conceptos/observaciones: MAYÚSCULAS, sin acentos
     * (Á→A, Ñ→N, etc.), sin espacios al inicio/fin y con espacios internos colapsados.
     *
     * @param  string|null  $value  Texto original (null se trata como cadena vacía).
     * @return string Texto normalizado.
     */
    public static function normalizeText(?string $value): string
    {
        $v = mb_strtoupper(trim((string) $value));
        $v = strtr($v, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);

        return preg_replace('/\s+/u', ' ', $v) ?? $v;
    }

    /**
     * Traduce el código de fuente (data_sources.code) al tipo de fuente usado por las reglas
     * de concepto ('lendus' | 'erp').
     *
     * @param  string|null  $sourceCode  Código de la fuente del gasto.
     * @return string|null Tipo de fuente, o null si la fuente no admite reglas (p. ej. imss).
     */
    public static function sourceTypeFor(?string $sourceCode): ?string
    {
        return self::SOURCE_TYPES[(string) $sourceCode] ?? null;
    }

    /**
     * Sucursal CORPORATIVO existente (misma resolución que la radiografía). Memo por instancia.
     * Si hay varias sucursales mapeadas a «corporativo», usa la de menor id.
     *
     * @return int|null id de la sucursal CORPORATIVO, o null si no existe ninguna.
     */
    public function corporateBranchId(): ?int
    {
        if ($this->corporateResolved) {
            return $this->corporateId;
        }
        $ids = $this->branchCalculator->buildBranchMap()['corporativo'] ?? [];
        $this->corporateResolved = true;

        return $this->corporateId = ($ids === [] ? null : (int) min($ids));
    }

    /** true cuando corporateBranchId() ya se resolvió en esta instancia. */
    private bool $corporateResolved = false;

    /** Resultado memorizado de corporateBranchId(). */
    private ?int $corporateId = null;

    /**
     * Huella estable de cada gasto del periodo: no depende del id (que cambia al
     * reimportar) ni de los campos que la revisión modifica (empleado/sucursal/categoría).
     * Gastos idénticos se distinguen por su número de ocurrencia en orden de id ('|#1', '|#2'…).
     * Solo lectura.
     *
     * @param  int  $periodId  Periodo a recorrer.
     * @return array<int, string> fact_expense_id => huella
     */
    public function fingerprintsForPeriod(int $periodId): array
    {
        $out = [];
        $occurrence = [];
        foreach ($this->baseRows($periodId, ['e.id', 'e.expense_date', 'e.concept', 'e.amount', 'e.paid_amount', 'e.observations']) as $r) {
            $key = $this->fingerprintKey($r);
            $occurrence[$key] = ($occurrence[$key] ?? 0) + 1;
            $out[(int) $r->id] = sha1($key.'|#'.$occurrence[$key]);
        }

        return $out;
    }

    /**
     * Arma la llave base (sin ocurrencia) de la huella: fuente|fecha|CONCEPTO|monto en
     * centavos|OBSERVACIONES. El monto es paid_amount si es distinto de cero; si no, amount.
     *
     * @param  object  $r  Fila de fact_expenses con source_code, expense_date, concept, amount,
     *                     paid_amount y observations.
     * @return string Llave base de la huella.
     */
    private function fingerprintKey(object $r): string
    {
        $amount = (float) $r->paid_amount != 0.0 ? (float) $r->paid_amount : (float) $r->amount;

        return implode('|', [
            (string) ($r->source_code ?? ''),
            $r->expense_date ? substr((string) $r->expense_date, 0, 10) : '',
            self::normalizeText($r->concept),
            (string) (int) round($amount * 100),
            self::normalizeText($r->observations),
        ]);
    }

    /**
     * Lee los gastos del periodo (ordenados por id, orden del que depende la ocurrencia de la
     * huella) con el código de su fuente (join report_uploads → data_sources).
     *
     * @param  int  $periodId  Periodo.
     * @param  list<string>  $columns  Columnas de fact_expenses (alias e.) a traer.
     * @return Collection<int, object> Filas con las columnas pedidas + source_code.
     */
    private function baseRows(int $periodId, array $columns)
    {
        return DB::table('fact_expenses as e')
            ->leftJoin('report_uploads as ru', 'ru.id', '=', 'e.report_upload_id')
            ->leftJoin('data_sources as ds', 'ds.id', '=', 'ru.data_source_id')
            ->where('e.period_id', $periodId)
            ->orderBy('e.id')
            ->get(array_merge($columns, ['ds.code as source_code']));
    }

    /**
     * Igual que applyForPeriod() pero nunca lanza (importación/generación siguen su curso).
     * Ante cualquier error deja un warning en el log y los gastos se quedan con la
     * atribución automática.
     *
     * @param  int  $periodId  Periodo cuyos gastos se re-aplican.
     */
    public function applyForPeriodSafely(int $periodId): void
    {
        try {
            $this->applyForPeriod($periodId);
        } catch (\Throwable $e) {
            Log::warning('ExpenseReviewService: no se pudieron reaplicar las decisiones manuales de gastos (los gastos quedan con la atribución automática).', [
                'period_id' => $periodId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Vuelve a aplicar las decisiones y reglas vigentes a los gastos del periodo.
     *
     * Por cada gasto: (a) aplica la regla de concepto activa (o revierte a category_before_rule
     * si la regla se quitó) y (b) si hay decisión con su huella, captura los valores automáticos
     * (si el registro está recién importado) y escribe employee_id / branch_id según la decisión.
     * Sale sin leer filas si no hay decisiones, reglas ni rastros de reglas que revertir.
     *
     * Efectos: UPDATE en fact_expenses; puede actualizar auto_* de expense_review_decisions
     * (saveQuietly, sin eventos). Puede lanzar excepciones de BD (ver applyForPeriodSafely()).
     *
     * @param  int  $periodId  Periodo a re-aplicar.
     * @return array{employee:int, branch:int, category:int} Cuántos gastos cambiaron en cada dimensión.
     */
    public function applyForPeriod(int $periodId): array
    {
        $stats = ['employee' => 0, 'branch' => 0, 'category' => 0];
        if (! self::installed()) {
            return $stats;
        }

        $decisions = D::query()->where('period_id', $periodId)->get()->keyBy('fingerprint');
        $rules = ExpenseConceptRule::query()->where('is_active', true)->get()
            ->mapWithKeys(fn ($r) => [$r->source_type.'|'.$r->normalized_concept => $r->category])->all();
        $hasRuleTraces = DB::table('fact_expenses')->where('period_id', $periodId)->whereNotNull('category_before_rule')->exists();

        // Sin decisiones ni reglas (ni rastros de reglas por revertir): no se toca nada.
        if ($decisions->isEmpty() && $rules === [] && ! $hasRuleTraces) {
            return $stats;
        }

        $corporateId = $decisions->contains('branch_decision', D::BRANCH_CORPORATE) ? $this->corporateBranchId() : null;
        $rows = $this->baseRows($periodId, [
            'e.id', 'e.expense_date', 'e.concept', 'e.amount', 'e.paid_amount', 'e.observations',
            'e.employee_id', 'e.branch_id', 'e.category', 'e.category_before_rule',
            'e.employee_review_status', 'e.branch_review_status',
        ]);

        $occurrence = [];
        foreach ($rows as $r) {
            $key = $this->fingerprintKey($r);
            $occurrence[$key] = ($occurrence[$key] ?? 0) + 1;
            $fp = sha1($key.'|#'.$occurrence[$key]);
            $update = [];

            // ── Regla de destino por concepto ───────────────────────────────────
            $type = self::sourceTypeFor($r->source_code);
            $ruleCategory = $type ? ($rules[$type.'|'.self::normalizeText($r->concept)] ?? null) : null;
            if ($ruleCategory !== null && (string) $r->category !== $ruleCategory) {
                $update['category'] = $ruleCategory;
                $update['category_before_rule'] = $r->category_before_rule ?? $r->category;
                $stats['category']++;
            } elseif ($ruleCategory === null && $r->category_before_rule !== null) {
                // La regla se quitó: vuelve la categoría automática original.
                $update['category'] = $r->category_before_rule;
                $update['category_before_rule'] = null;
                $stats['category']++;
            }

            // ── Decisión por gasto ─────────────────────────────────────────────
            $d = $decisions->get($fp);
            if ($d) {
                if ($r->employee_review_status === null && $r->branch_review_status === null) {
                    // Registro recién importado (o sin aplicar aún): sus valores actuales son los automáticos.
                    if (! $d->auto_captured || (int) $d->auto_employee_id !== (int) $r->employee_id || (int) $d->auto_branch_id !== (int) $r->branch_id) {
                        $d->forceFill(['auto_employee_id' => $r->employee_id, 'auto_branch_id' => $r->branch_id, 'auto_captured' => true])->saveQuietly();
                    }
                }

                $update += $this->employeeUpdate($d, $r, $stats);
                $update += $this->branchUpdate($d, $r, $corporateId, $stats);
            }

            if ($update !== []) {
                $update['updated_at'] = now();
                DB::table('fact_expenses')->where('id', $r->id)->update($update);
            }
        }

        return $stats;
    }

    /**
     * Calcula los cambios de colaborador que la decisión impone sobre el gasto.
     * 'asignado' → employee_id elegido; 'sin_empleado' → null; sin decisión → nada.
     * Marca la atribución como manual (confianza 1, sin revisión pendiente).
     *
     * @param  D  $d  Decisión del usuario.
     * @param  object  $r  Fila actual del gasto.
     * @param  array{employee:int, branch:int, category:int}  $stats  Contadores (se incrementa 'employee' si hay cambio).
     * @return array<string, mixed> Columnas de fact_expenses a actualizar (vacío si ya está aplicado).
     */
    private function employeeUpdate(D $d, object $r, array &$stats): array
    {
        $target = match ($d->employee_decision) {
            D::EMPLOYEE_ASSIGNED => $d->employee_id ? (int) $d->employee_id : false,
            D::EMPLOYEE_NONE => null,
            default => false,
        };
        if ($target === false) {
            return [];
        }
        $current = $r->employee_id !== null ? (int) $r->employee_id : null;
        if ($current === $target && $r->employee_review_status === $d->employee_decision) {
            return [];
        }
        $stats['employee']++;

        return [
            'employee_id' => $target,
            'attribution_method' => $target === null ? self::METHOD_MANUAL_NONE : self::METHOD_MANUAL,
            'attribution_confidence' => 1,
            'attribution_source' => self::SOURCE_MANUAL,
            'attribution_needs_review' => false,
            'employee_review_status' => $d->employee_decision,
        ];
    }

    /**
     * Calcula los cambios de sucursal que la decisión impone sobre el gasto.
     * 'asignada' → branch_id elegido; 'corporativo' → sucursal CORPORATIVO (si no existe, no
     * se toca); 'sin_sucursal' → null; sin decisión → nada.
     *
     * @param  D  $d  Decisión del usuario.
     * @param  object  $r  Fila actual del gasto.
     * @param  int|null  $corporateId  id de la sucursal CORPORATIVO (null si no aplica / no existe).
     * @param  array{employee:int, branch:int, category:int}  $stats  Contadores (se incrementa 'branch' si hay cambio).
     * @return array<string, mixed> Columnas de fact_expenses a actualizar (vacío si ya está aplicado).
     */
    private function branchUpdate(D $d, object $r, ?int $corporateId, array &$stats): array
    {
        $target = match ($d->branch_decision) {
            D::BRANCH_ASSIGNED => $d->branch_id ? (int) $d->branch_id : false,
            D::BRANCH_CORPORATE => $corporateId ?? false,
            D::BRANCH_NONE => null,
            default => false,
        };
        if ($target === false) {
            return [];
        }
        $current = $r->branch_id !== null ? (int) $r->branch_id : null;
        if ($current === $target && $r->branch_review_status === $d->branch_decision) {
            return [];
        }
        $stats['branch']++;

        return ['branch_id' => $target, 'branch_review_status' => $d->branch_decision];
    }

    /**
     * Guarda la decisión del usuario para uno o varios gastos del periodo.
     *
     * Flujo (en una transacción): por cada gasto obtiene/crea la decisión por huella, aplica la
     * acción de colaborador y de sucursal (null = no tocar esa dimensión), restaura los valores
     * automáticos si se eligió «automatico», borra la decisión si quedó vacía, re-aplica todo el
     * periodo con applyForPeriod() y escribe la bitácora con el estado real posterior.
     * Si algo cambió, marca la radiografía del periodo para regenerar.
     *
     * @param  Period  $period  Periodo en revisión.
     * @param  list<int>  $expenseIds  ids de fact_expenses (los que no son del periodo se ignoran).
     * @param  array{action:?string, employee_id?:?int}  $employee  action: asignar|ninguno|automatico|null
     * @param  array{action:?string, branch_id?:?int}  $branch  action: asignar|corporativo|ninguna|automatico|null
     * @param  string|null  $notes  Nota a guardar (null = no tocar; cadena vacía = borrar la nota).
     * @param  User  $user  Usuario que decide (created_by/updated_by y bitácora).
     * @return array{saved:int, removed:int} Decisiones guardadas y decisiones eliminadas.
     */
    public function saveDecisions(Period $period, array $expenseIds, array $employee, array $branch, ?string $notes, User $user): array
    {
        $fps = $this->fingerprintsForPeriod($period->id);
        $rows = DB::table('fact_expenses as e')
            ->leftJoin('report_uploads as ru', 'ru.id', '=', 'e.report_upload_id')
            ->leftJoin('data_sources as ds', 'ds.id', '=', 'ru.data_source_id')
            ->where('e.period_id', $period->id)
            ->whereIn('e.id', $expenseIds)
            ->get(['e.*', 'ds.code as source_code']);

        $saved = 0;
        $removed = 0;
        DB::transaction(function () use ($rows, $fps, $period, $employee, $branch, $notes, $user, &$saved, &$removed) {
            $pending = [];
            foreach ($rows as $row) {
                $fp = $fps[(int) $row->id] ?? null;
                if ($fp === null) {
                    continue;
                }
                $d = D::query()->firstOrNew(['period_id' => $period->id, 'fingerprint' => $fp]);
                $before = $this->snapshot($d, $row);
                if (! $d->exists) {
                    $d->fill([
                        'source_code' => $row->source_code,
                        'expense_date' => $row->expense_date ? substr((string) $row->expense_date, 0, 10) : null,
                        'concept' => $row->concept,
                        'amount' => (float) $row->paid_amount != 0.0 ? $row->paid_amount : $row->amount,
                        'observations' => $row->observations,
                        'created_by' => $user->id,
                    ]);
                    if ($row->employee_review_status === null && $row->branch_review_status === null) {
                        $d->fill(['auto_employee_id' => $row->employee_id, 'auto_branch_id' => $row->branch_id, 'auto_captured' => true]);
                    }
                }

                $restore = [];
                switch ($employee['action'] ?? null) {
                    case 'asignar':
                        $d->employee_decision = D::EMPLOYEE_ASSIGNED;
                        $d->employee_id = (int) $employee['employee_id'];
                        break;
                    case 'ninguno':
                        $d->employee_decision = D::EMPLOYEE_NONE;
                        $d->employee_id = null;
                        break;
                    case 'automatico':
                        if ($d->employee_decision !== null) {
                            $restore += [
                                'employee_id' => $d->auto_captured ? $d->auto_employee_id : $row->employee_id,
                                'attribution_method' => null, 'attribution_confidence' => null, 'attribution_source' => null,
                                'employee_review_status' => null,
                            ];
                        }
                        $d->employee_decision = null;
                        $d->employee_id = null;
                        break;
                }
                switch ($branch['action'] ?? null) {
                    case 'asignar':
                        $d->branch_decision = D::BRANCH_ASSIGNED;
                        $d->branch_id = (int) $branch['branch_id'];
                        break;
                    case 'corporativo':
                        $d->branch_decision = D::BRANCH_CORPORATE;
                        $d->branch_id = null;
                        break;
                    case 'ninguna':
                        $d->branch_decision = D::BRANCH_NONE;
                        $d->branch_id = null;
                        break;
                    case 'automatico':
                        if ($d->branch_decision !== null) {
                            $restore += [
                                'branch_id' => $d->auto_captured ? $d->auto_branch_id : $row->branch_id,
                                'branch_review_status' => null,
                            ];
                        }
                        $d->branch_decision = null;
                        $d->branch_id = null;
                        break;
                }
                if ($notes !== null) {
                    $d->notes = trim($notes) === '' ? null : trim($notes);
                }

                if ($restore !== []) {
                    DB::table('fact_expenses')->where('id', $row->id)->update($restore + ['updated_at' => now()]);
                }

                if ($d->employee_decision === null && $d->branch_decision === null) {
                    if ($d->exists) {
                        $d->delete();
                        $removed++;
                        $pending[] = [$fp, (int) $row->id, 'decision_quitada', $before, null];
                    }

                    continue;
                }

                $d->updated_by = $user->id;
                $d->save();
                $saved++;
                $pending[] = [$fp, (int) $row->id, 'decision_guardada', $before, $d];
            }

            $this->applyForPeriod($period->id);

            // Bitácora con el «después» REAL del gasto (ya aplicada la decisión).
            $after = DB::table('fact_expenses')->whereIn('id', array_column($pending, 1))->get()->keyBy('id');
            foreach ($pending as [$fp, $id, $action, $before, $decision]) {
                $row = $after->get($id);
                $this->log($period->id, $fp, $id, $action, $before, $row ? $this->snapshot($decision ?? new D, $row) : null, $user);
            }
        });

        if ($saved + $removed > 0) {
            $this->markRadiographyForRegeneration($period, 'Revisión manual de gastos actualizada. Re-genera la Radiografía para reflejar los cambios.');
        }

        return ['saved' => $saved, 'removed' => $removed];
    }

    /**
     * Guarda (o quita, con $category null) el destino de un concepto. Se aplica al periodo
     * en revisión y a las siguientes cargas; los periodos ya cerrados no se tocan.
     *
     * Quitar una regla la desactiva (is_active = false), no la borra. Escribe bitácora
     * (subject = 'concepto'), re-aplica el periodo y marca la radiografía para regenerar.
     * Si se pide quitar una regla inexistente o ya inactiva, no hace nada.
     *
     * @param  Period  $period  Periodo en revisión (donde se aplica de inmediato).
     * @param  string  $sourceType  'lendus' | 'erp'.
     * @param  string  $concept  Concepto tal como aparece en el gasto (se normaliza para la llave).
     * @param  string|null  $category  Categoría destino, o null para quitar la regla.
     * @param  User  $user  Usuario que decide.
     */
    public function saveConceptRule(Period $period, string $sourceType, string $concept, ?string $category, User $user): void
    {
        $normalized = self::normalizeText($concept);
        $rule = ExpenseConceptRule::query()->firstOrNew(['source_type' => $sourceType, 'normalized_concept' => $normalized]);
        $before = $rule->exists ? $rule->only(['category', 'is_active']) : null;

        if ($category === null) {
            if (! $rule->exists || ! $rule->is_active) {
                return;
            }
            $rule->fill(['is_active' => false, 'updated_by' => $user->id])->save();
        } else {
            $rule->fill([
                'concept_label' => trim($concept),
                'category' => $category,
                'is_active' => true,
                'created_by' => $rule->created_by ?? $user->id,
                'updated_by' => $user->id,
            ])->save();
        }

        ExpenseReviewLog::query()->create([
            'period_id' => $period->id,
            'subject' => 'concepto',
            'subject_key' => mb_substr($sourceType.'|'.$normalized, 0, 191),
            'action' => $category === null ? 'regla_quitada' : 'regla_guardada',
            'before' => $before,
            'after' => $category === null ? null : ['category' => $category, 'is_active' => true],
            'user_id' => $user->id,
        ]);

        $this->applyForPeriod($period->id);
        $this->markRadiographyForRegeneration($period, 'Destino de concepto de gasto actualizado. Re-genera la Radiografía para reflejar los cambios.');
    }

    /**
     * Mismo patrón que una fuente reprocesada (ReportUploadController::analyze()): marca para regenerar.
     * Pasa el period_summary 'generated' a 'database_updated' con el motivo indicado, para que
     * la UI pida volver a generar la Radiografía.
     *
     * @param  Period  $period  Periodo afectado.
     * @param  string  $reason  Motivo que verá el usuario (invalidated_reason).
     */
    public function markRadiographyForRegeneration(Period $period, string $reason): void
    {
        PeriodSummary::query()
            ->where('period_id', $period->id)
            ->where('status', 'generated')
            ->update(['status' => 'database_updated', 'invalidated_reason' => $reason, 'updated_at' => now()]);
    }

    /**
     * Foto del gasto y de su decisión para la bitácora (antes/después).
     *
     * @param  D  $d  Decisión (si no existe en BD, 'decision' queda null).
     * @param  object  $row  Fila de fact_expenses.
     * @return array{gasto: array<string, mixed>, decision: array<string, mixed>|null}
     */
    private function snapshot(D $d, object $row): array
    {
        return [
            'gasto' => [
                'employee_id' => $row->employee_id !== null ? (int) $row->employee_id : null,
                'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
                'concept' => $row->concept,
                'amount' => (string) ((float) $row->paid_amount != 0.0 ? $row->paid_amount : $row->amount),
            ],
            'decision' => $d->exists ? [
                'employee_decision' => $d->employee_decision,
                'employee_id' => $d->employee_id,
                'branch_decision' => $d->branch_decision,
                'branch_id' => $d->branch_id,
                'notes' => $d->notes,
            ] : null,
        ];
    }

    /**
     * Escribe un renglón de bitácora (subject = 'gasto') en expense_review_logs.
     *
     * @param  int  $periodId  Periodo.
     * @param  string  $fp  Huella del gasto.
     * @param  int  $expenseId  id actual del gasto.
     * @param  string  $action  'decision_guardada' | 'decision_quitada'.
     * @param  array<string, mixed>|null  $before  Estado previo (snapshot()).
     * @param  array<string, mixed>|null  $after  Estado posterior (snapshot()).
     * @param  User  $user  Usuario que hizo el cambio.
     */
    private function log(int $periodId, string $fp, int $expenseId, string $action, ?array $before, ?array $after, User $user): void
    {
        ExpenseReviewLog::query()->create([
            'period_id' => $periodId,
            'subject' => 'gasto',
            'subject_key' => $fp,
            'fact_expense_id' => $expenseId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'user_id' => $user->id,
        ]);
    }
}
