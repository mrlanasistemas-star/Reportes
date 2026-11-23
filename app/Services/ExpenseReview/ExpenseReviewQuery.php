<?php

namespace App\Services\ExpenseReview;

use App\Models\ExpenseReview\ExpenseConceptRule;
use App\Models\ExpenseReview\ExpenseReviewDecision as D;
use App\Models\Period;
use App\Services\OpexClassificationService as Opex;
use App\Services\Radiography\BranchRadiographyCalculator;
use App\Support\ExpenseCategoryMapper;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lecturas de la Revisión de gastos (solo lectura): desglose paginado, totales por grupo
 * y catálogo de conceptos con su destino ACTUAL en el reporte. Reutiliza la clasificación
 * canónica (OpexClassificationService + BranchRadiographyCalculator::gastoReportLine()):
 * aquí no se decide nada nuevo, solo se muestra cómo lo trata hoy el sistema.
 *
 * Quién lo usa: ExpenseReviewController (endpoints JSON del paso «Revisión de gastos» del
 * flujo Histórico general). No escribe en BD.
 *
 * Alcance: los métodos que reciben $allowedBranchIds restringen a esas sucursales
 * (null = alcance general; [] = ninguna sucursal → sin resultados).
 */
class ExpenseReviewQuery
{
    /**
     * Filtros válidos del listado: pendientes (sin colaborador o sin sucursal, no confirmados),
     * sin_empleado, sin_sucursal, revisados (con alguna decisión manual), corporativo y todos.
     */
    public const FILTERS = ['pendientes', 'sin_empleado', 'sin_sucursal', 'revisados', 'corporativo', 'todos'];

    /** Etiqueta en español de cada fuente de gastos (data_sources.code). */
    public const SOURCE_LABELS = [
        'gastos_lendus' => 'Lendus (PDF)',
        'gastos_lendus_excel' => 'Lendus (Excel)',
        'gastos_erp' => 'ERP',
        'gastos' => 'Gastos',
        'imss' => 'IMSS',
    ];

    /**
     * @param  ExpenseReviewService  $service  Huellas y sucursal CORPORATIVO.
     * @param  Opex  $opex  Clasificación canónica OPEX/nómina/excluido.
     * @param  BranchRadiographyCalculator  $branchCalculator  Línea del reporte donde cae cada gasto OPEX.
     */
    public function __construct(
        private readonly ExpenseReviewService $service,
        private readonly Opex $opex,
        private readonly BranchRadiographyCalculator $branchCalculator,
    ) {}

    /**
     * Expresión SQL del monto efectivo del gasto: paid_amount si es distinto de 0; si no, amount
     * (misma regla que la huella de ExpenseReviewService).
     *
     * @return string Fragmento SQL (alias de tabla «e»).
     */
    private function amountSql(): string
    {
        return 'COALESCE(NULLIF(e.paid_amount,0), e.amount)';
    }

    /**
     * Consulta base: gastos del periodo con fuente (ds), colaborador (emp) y sucursal (b),
     * restringida al alcance de sucursales del usuario.
     *
     * @param  Period  $period  Periodo en revisión.
     * @param  list<int>|null  $allowedBranchIds  null = alcance general
     * @return Builder Query sin columnas seleccionadas.
     */
    private function base(Period $period, ?array $allowedBranchIds): Builder
    {
        return DB::table('fact_expenses as e')
            ->leftJoin('report_uploads as ru', 'ru.id', '=', 'e.report_upload_id')
            ->leftJoin('data_sources as ds', 'ds.id', '=', 'ru.data_source_id')
            ->leftJoin('employees as emp', 'emp.id', '=', 'e.employee_id')
            ->leftJoin('branches as b', 'b.id', '=', 'e.branch_id')
            ->where('e.period_id', $period->id)
            ->when($allowedBranchIds !== null, fn ($q) => $q->whereIn('e.branch_id', $allowedBranchIds === [] ? [0] : $allowedBranchIds));
    }

    /**
     * Restringe a gastos sin colaborador que el usuario NO ha confirmado como «sin colaborador».
     *
     * @param  Builder  $q  Query a restringir (se modifica).
     * @return Builder La misma query.
     */
    private function pendingEmployee(Builder $q): Builder
    {
        return $q->whereNull('e.employee_id')
            ->where(fn ($w) => $w->whereNull('e.employee_review_status')->orWhere('e.employee_review_status', '!=', D::EMPLOYEE_NONE));
    }

    /**
     * Restringe a gastos sin sucursal que el usuario NO ha confirmado como «sin sucursal».
     *
     * @param  Builder  $q  Query a restringir (se modifica).
     * @return Builder La misma query.
     */
    private function pendingBranch(Builder $q): Builder
    {
        return $q->whereNull('e.branch_id')
            ->where(fn ($w) => $w->whereNull('e.branch_review_status')->orWhere('e.branch_review_status', '!=', D::BRANCH_NONE));
    }

    /**
     * Aplica filtro/búsqueda/fuente — compartido por el listado y la acción «todos los del filtro».
     *
     * @param  Period  $period  Periodo en revisión.
     * @param  array{filter?:string, source?:string, q?:string}  $f  filter: uno de FILTERS (default
     *                                                               'pendientes'); source: código de fuente; q: texto a buscar en
     *                                                               concepto, categoría, observaciones, colaborador o sucursal.
     * @param  list<int>|null  $allowedBranchIds  Alcance de sucursales (null = general).
     * @return Builder Query filtrada (sin columnas seleccionadas).
     */
    public function filtered(Period $period, array $f, ?array $allowedBranchIds): Builder
    {
        $q = $this->base($period, $allowedBranchIds);
        $corporateId = $this->service->corporateBranchId();

        match ($f['filter'] ?? 'pendientes') {
            'sin_empleado' => $this->pendingEmployee($q),
            'sin_sucursal' => $this->pendingBranch($q),
            'pendientes' => $q->where(fn ($w) => $w
                ->where(fn ($a) => $this->pendingEmployee($a))
                ->orWhere(fn ($a) => $this->pendingBranch($a))),
            'revisados' => $q->where(fn ($w) => $w->whereNotNull('e.employee_review_status')->orWhereNotNull('e.branch_review_status')),
            'corporativo' => $q->where('e.branch_id', $corporateId ?? 0),
            default => $q,
        };

        if (! empty($f['source'])) {
            $q->where('ds.code', $f['source']);
        }
        $search = trim((string) ($f['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $q->where(fn ($w) => $w->where('e.concept', 'like', $like)
                ->orWhere('e.category', 'like', $like)
                ->orWhere('e.observations', 'like', $like)
                ->orWhere('emp.full_name', 'like', $like)
                ->orWhere('b.name', 'like', $like));
        }

        return $q;
    }

    /**
     * Listado paginado de gastos para la tabla de revisión, con la decisión manual de cada uno
     * (ligada por huella), el total filtrado y el resumen por grupo.
     *
     * @param  Period  $period  Periodo en revisión.
     * @param  array{filter?:string, source?:string, q?:string, sort?:string, page?:int, per_page?:int}  $f
     *                                                                                                       sort: monto (default, desc) | fecha (desc) | concepto (asc); per_page: 25|50|100.
     * @param  list<int>|null  $allowedBranchIds  Alcance de sucursales (null = general).
     * @return array<string, mixed> {rows, pagination{page, per_page, total, last_page}, filtered_total, summary}
     */
    public function list(Period $period, array $f, ?array $allowedBranchIds): array
    {
        $perPage = (int) ($f['per_page'] ?? 25);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $page = max(1, (int) ($f['page'] ?? 1));
        $sort = match ($f['sort'] ?? 'monto') {
            'fecha' => ['e.expense_date', 'desc'],
            'concepto' => ['e.concept', 'asc'],
            default => [DB::raw($this->amountSql()), 'desc'],
        };

        $filtered = $this->filtered($period, $f, $allowedBranchIds);
        $agg = (clone $filtered)->selectRaw('COUNT(*) as c, COALESCE(SUM('.$this->amountSql().'),0) as s')->first();
        $total = (int) ($agg->c ?? 0);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $rows = (clone $filtered)
            ->orderBy($sort[0], $sort[1])
            ->orderBy('e.id')
            ->forPage($page, $perPage)
            ->get([
                'e.id', 'e.expense_date', 'e.concept', 'e.category', 'e.observations', 'e.employee_id', 'e.branch_id',
                'e.attribution_method', 'e.attribution_needs_review', 'e.employee_review_status', 'e.branch_review_status',
                'e.category_before_rule', 'ds.code as source_code', 'emp.full_name as employee_name', 'b.name as branch_name',
                DB::raw($this->amountSql().' as effective_amount'),
            ]);

        $fps = $rows->isEmpty() ? [] : $this->service->fingerprintsForPeriod($period->id);
        $decisions = $rows->isEmpty() ? collect() : D::query()->where('period_id', $period->id)
            ->whereIn('fingerprint', array_values(array_intersect_key($fps, array_flip($rows->pluck('id')->all()))))
            ->with(['employee:id,full_name', 'branch:id,name'])
            ->leftJoin('users as u', 'u.id', '=', 'expense_review_decisions.updated_by')
            ->get(['expense_review_decisions.*', 'u.name as updated_by_name'])
            ->keyBy('fingerprint');

        return [
            'rows' => $rows->map(fn ($r) => $this->presentRow($r, $fps[(int) $r->id] ?? null, $decisions))->values()->all(),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage],
            'filtered_total' => round((float) ($agg->s ?? 0), 2),
            'summary' => $this->summary($period, $allowedBranchIds),
        ];
    }

    /**
     * Da forma JSON a un gasto para la UI: fuente, monto, colaborador/sucursal actuales, cómo se
     * atribuyó, destino en el reporte (classification; CORPORATIVO tiene su propia etiqueta) y
     * la decisión manual vigente (si existe).
     *
     * @param  object  $r  Fila de list().
     * @param  string|null  $fp  Huella del gasto.
     * @param  Collection<string, D>  $decisions  Decisiones del periodo indexadas por huella.
     * @return array<string, mixed> Renglón para ExpenseReviewTable.
     */
    private function presentRow(object $r, ?string $fp, $decisions): array
    {
        $code = (string) $r->source_code;
        $class = $this->classify($code, (string) $r->category, (string) $r->concept);
        $corp = $this->service->corporateBranchId();
        if ($corp !== null && (int) $r->branch_id === $corp) {
            $class = ['type' => 'corporativo', 'is_opex' => false, 'line' => null, 'label' => 'Corporativo (fuera de sucursales)',
                'hint' => 'No suma a la radiografía de sucursales. Si es del ERP, el Estado de Resultados lo toma como gasto corporativo.'];
        }
        $d = $fp ? $decisions->get($fp) : null;

        return [
            'id' => (int) $r->id,
            'fingerprint' => $fp,
            'source_code' => $code,
            'source_label' => self::SOURCE_LABELS[$code] ?? ($code ?: 'Sin fuente'),
            'date' => $r->expense_date ? substr((string) $r->expense_date, 0, 10) : null,
            'concept' => $r->concept,
            'category' => $r->category,
            'category_before_rule' => $r->category_before_rule,
            'observations' => $r->observations,
            'amount' => round((float) $r->effective_amount, 2),
            'employee' => $r->employee_id ? ['id' => (int) $r->employee_id, 'name' => $r->employee_name] : null,
            'branch' => $r->branch_id ? ['id' => (int) $r->branch_id, 'name' => $r->branch_name] : null,
            'attribution_method' => $r->attribution_method,
            'attribution_label' => $this->attributionLabel($r),
            'needs_review' => (bool) $r->attribution_needs_review,
            'employee_review_status' => $r->employee_review_status,
            'branch_review_status' => $r->branch_review_status,
            'classification' => $class,
            'decision' => $d ? [
                'employee_decision' => $d->employee_decision,
                'employee' => $d->employee ? ['id' => $d->employee->id, 'name' => $d->employee->full_name] : null,
                'branch_decision' => $d->branch_decision,
                'branch' => $d->branch ? ['id' => $d->branch->id, 'name' => $d->branch->name] : null,
                'notes' => $d->notes,
                'updated_by' => $d->updated_by_name,
                'updated_at' => optional($d->updated_at)->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * Texto en español de cómo se obtuvo el colaborador del gasto (manual o método automático
     * de ExpenseObservationAttributionService).
     *
     * @param  object  $r  Fila con employee_review_status, attribution_method y employee_id.
     * @return string Etiqueta para la UI.
     */
    private function attributionLabel(object $r): string
    {
        if ($r->employee_review_status === D::EMPLOYEE_ASSIGNED) {
            return 'Asignado manualmente';
        }
        if ($r->employee_review_status === D::EMPLOYEE_NONE) {
            return 'Confirmado: no pertenece a un colaborador';
        }

        return match ($r->attribution_method) {
            'alias' => 'Alias confirmado',
            'exact_name' => 'Nombre exacto en observaciones',
            'fuzzy_name' => 'Nombre similar en observaciones',
            'conflict' => 'Conflicto entre observación y justificación',
            'ambiguous' => 'Nombre ambiguo',
            'previous_employee_confirmed_by_text' => 'Colaborador confirmado por texto',
            'branch_general' => 'Gasto general de sucursal',
            null => $r->employee_id ? 'Desde el archivo' : 'Sin atribución',
            default => 'Automático ('.$r->attribution_method.')',
        };
    }

    /**
     * Destino ACTUAL del gasto en la radiografía (misma regla canónica, sin reinterpretar).
     * Lendus Excel = complementario (el OPEX sale del PDF); fuentes que no son de gastos =
     * informativo; el resto se clasifica con OpexClassificationService y, si es OPEX, se busca
     * la línea del reporte con BranchRadiographyCalculator::gastoReportLine().
     *
     * @param  string  $sourceCode  Código de la fuente.
     * @param  string  $category  Categoría del gasto.
     * @param  string  $concept  Concepto del gasto.
     * @return array{type:string, is_opex:bool, line:?string, label:string, hint:?string}
     */
    public function classify(string $sourceCode, string $category, string $concept): array
    {
        if ($sourceCode === 'gastos_lendus_excel') {
            return ['type' => 'complementario', 'is_opex' => false, 'line' => null,
                'label' => 'Complementario', 'hint' => 'Gemelo del PDF de Lendus: el OPEX por sucursal sale del PDF; este registro sólo detalla por colaborador.'];
        }
        if (! in_array($sourceCode, ['gastos_lendus', 'gastos_erp', 'gastos'], true)) {
            return ['type' => 'informativo', 'is_opex' => false, 'line' => null,
                'label' => 'Informativo', 'hint' => 'Esta fuente no suma al OPEX de la radiografía.'];
        }

        $c = $this->opex->classify($category, $concept, $sourceCode === 'gastos_erp' ? Opex::SOURCE_ERP : Opex::SOURCE_LENDUS);
        $line = $c['is_opex'] ? $this->branchCalculator->gastoReportLine($category, $concept) : null;

        return [
            'type' => $c['type'],
            'is_opex' => $c['is_opex'],
            'line' => $line,
            'label' => match ($c['type']) {
                Opex::TYPE_OPEX => $line ? 'OPEX · '.$line : 'OPEX · Sin clasificar',
                Opex::TYPE_NOMINA_EMPLEADO => 'Nómina y Capital Humano',
                Opex::TYPE_NOMINA_COVERED_BY_NOI => 'Sin efecto (cubierto por NOI/IMSS)',
                Opex::TYPE_EXCEDENTE => 'Excedentes (envío a corporativo)',
                Opex::TYPE_FONDEO => 'Fondeo intersucursal',
                Opex::TYPE_POLIZAS => 'Puente de seguros (no es OPEX)',
                default => 'Excluido',
            },
            'hint' => $c['exclusion_reason'],
        ];
    }

    /**
     * Totales por grupo del periodo (respetando el alcance) para las tarjetas de resumen:
     * total, sin colaborador pendiente, sin sucursal pendiente, corporativo, confirmados sin
     * colaborador/sucursal, revisados y desglose por fuente. No depende de filtros ni búsqueda.
     *
     * @param  Period  $period  Periodo en revisión.
     * @param  list<int>|null  $allowedBranchIds  Alcance de sucursales (null = general).
     * @return array<string, mixed> Cada grupo como {count, total}; by_source como lista {code, label, count, total}.
     */
    public function summary(Period $period, ?array $allowedBranchIds): array
    {
        $amt = $this->amountSql();
        $corp = (int) ($this->service->corporateBranchId() ?? 0);
        $none = D::EMPLOYEE_NONE;
        $bnone = D::BRANCH_NONE;
        $pe = "(e.employee_id IS NULL AND (e.employee_review_status IS NULL OR e.employee_review_status <> '{$none}'))";
        $pb = "(e.branch_id IS NULL AND (e.branch_review_status IS NULL OR e.branch_review_status <> '{$bnone}'))";

        $r = $this->base($period, $allowedBranchIds)->selectRaw(implode(', ', [
            'COUNT(*) as total_c', "COALESCE(SUM({$amt}),0) as total_s",
            "SUM(CASE WHEN {$pe} THEN 1 ELSE 0 END) as sin_empleado_c", "COALESCE(SUM(CASE WHEN {$pe} THEN {$amt} ELSE 0 END),0) as sin_empleado_s",
            "SUM(CASE WHEN {$pb} THEN 1 ELSE 0 END) as sin_sucursal_c", "COALESCE(SUM(CASE WHEN {$pb} THEN {$amt} ELSE 0 END),0) as sin_sucursal_s",
            "SUM(CASE WHEN e.employee_review_status = '{$none}' THEN 1 ELSE 0 END) as conf_sin_empleado_c",
            "SUM(CASE WHEN e.branch_review_status = '{$bnone}' THEN 1 ELSE 0 END) as conf_sin_sucursal_c",
            "SUM(CASE WHEN e.branch_id = {$corp} THEN 1 ELSE 0 END) as corporativo_c", "COALESCE(SUM(CASE WHEN e.branch_id = {$corp} THEN {$amt} ELSE 0 END),0) as corporativo_s",
            'SUM(CASE WHEN e.employee_review_status IS NOT NULL OR e.branch_review_status IS NOT NULL THEN 1 ELSE 0 END) as revisados_c',
        ]))->first();

        $bySource = $this->base($period, $allowedBranchIds)
            ->selectRaw("ds.code as code, COUNT(*) as c, COALESCE(SUM({$amt}),0) as s")
            ->groupBy('ds.code')->orderBy('ds.code')->get()
            ->map(fn ($s) => ['code' => (string) $s->code, 'label' => self::SOURCE_LABELS[$s->code] ?? ($s->code ?: 'Sin fuente'), 'count' => (int) $s->c, 'total' => round((float) $s->s, 2)])
            ->values()->all();

        $g = fn (string $k) => ['count' => (int) ($r->{$k.'_c'} ?? 0), 'total' => round((float) ($r->{$k.'_s'} ?? 0), 2)];

        return [
            'total' => $g('total'),
            'sin_empleado' => $g('sin_empleado'),
            'sin_sucursal' => $g('sin_sucursal'),
            'corporativo' => $g('corporativo'),
            'confirmados_sin_empleado' => (int) ($r->conf_sin_empleado_c ?? 0),
            'confirmados_sin_sucursal' => (int) ($r->conf_sin_sucursal_c ?? 0),
            'revisados' => (int) ($r->revisados_c ?? 0),
            'by_source' => $bySource,
        ];
    }

    /**
     * Conceptos del periodo con su total y destino actual; para gastos, también la regla
     * manual vigente. Ingresos: solo consulta (la regla de recuperación no se toca aquí).
     * Solo considera fuentes que suman OPEX (gastos_lendus, gastos_erp, gastos); no aplica
     * alcance por sucursal.
     *
     * @param  Period  $period  Periodo en revisión.
     * @return array{gastos: list<array<string, mixed>>, ingresos: list<array<string, mixed>>, categories: list<string>}
     *                                                                                                                   needs_destination = OPEX sin línea del reporte (el usuario debería elegir destino).
     */
    public function concepts(Period $period): array
    {
        $amt = $this->amountSql();
        $rows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'ru.id', '=', 'e.report_upload_id')
            ->join('data_sources as ds', 'ds.id', '=', 'ru.data_source_id')
            ->where('e.period_id', $period->id)
            ->whereIn('ds.code', ['gastos_lendus', 'gastos_erp', 'gastos'])
            ->selectRaw("ds.code as code, COALESCE(e.category,'') as category, COALESCE(e.concept,'') as concept, COUNT(*) as c, SUM({$amt}) as s, MAX(e.category_before_rule) as before_rule")
            ->groupBy('ds.code', 'e.category', 'e.concept')
            ->get();

        $rules = ExpenseConceptRule::query()->where('is_active', true)->get()
            ->keyBy(fn ($r) => $r->source_type.'|'.$r->normalized_concept);

        $gastos = $rows->map(function ($r) use ($rules) {
            $type = ExpenseReviewService::sourceTypeFor($r->code) ?? 'lendus';
            $class = $this->classify((string) $r->code, (string) $r->category, (string) $r->concept);
            $rule = $rules->get($type.'|'.ExpenseReviewService::normalizeText($r->concept));

            return [
                'key' => $type.'|'.ExpenseReviewService::normalizeText($r->concept).'|'.$r->category,
                'source_type' => $type,
                'source_label' => $type === 'erp' ? 'ERP' : 'Lendus',
                'concept' => $r->concept !== '' ? $r->concept : 'Sin concepto',
                'category' => $r->category !== '' ? $r->category : 'Sin categoría',
                'category_auto' => $r->before_rule,
                'count' => (int) $r->c,
                'total' => round((float) $r->s, 2),
                'classification' => $class,
                'needs_destination' => $class['type'] === Opex::TYPE_OPEX && $class['line'] === null,
                'rule' => $rule ? ['category' => $rule->category, 'updated_at' => optional($rule->updated_at)->toIso8601String()] : null,
            ];
        })->sortByDesc('total')->values()->all();

        return [
            'gastos' => $gastos,
            'ingresos' => $this->incomes($period),
            'categories' => $this->categories(),
        ];
    }

    /**
     * Catálogo de categorías elegibles como destino: las conocidas por ExpenseCategoryMapper más
     * las que ya existen en fact_expenses (de cualquier periodo), sin vacíos, únicas y ordenadas.
     *
     * @return list<string>
     */
    public function categories(): array
    {
        $db = DB::table('fact_expenses')->whereNotNull('category')->distinct()->pluck('category')->map(fn ($c) => (string) $c)->all();
        $all = array_values(array_unique(array_filter(array_merge(ExpenseCategoryMapper::categories(), $db))));
        sort($all);

        return $all;
    }

    /**
     * Ingresos (recuperación) del periodo agrupados por transacción/operación — solo consulta.
     * Indica a qué destino va cada grupo: solo PAGO/DESCUENTO cuentan como recuperación; Savehearts
     * sin participación CRECE es puente de seguros; con participación CRECE se reconoce parcial.
     *
     * @param  Period  $period  Periodo.
     * @return list<array{transaction:string, operation:string, count:int, total:float, destination:string, counts:bool}>
     */
    private function incomes(Period $period): array
    {
        return DB::table('fact_recoveries')
            ->where('period_id', $period->id)
            ->selectRaw("COALESCE(`transaction`,'') as t, COALESCE(operation,'') as o,
                CASE WHEN is_savehearts = 1 AND COALESCE(savehearts_crece_share,0) = 0 THEN 'seguro'
                     WHEN is_savehearts = 1 THEN 'crece' ELSE 'normal' END as kind,
                COUNT(*) as c, SUM(total_amount) as s")
            ->groupBy('t', 'o', 'kind')
            ->orderByDesc('s')
            ->get()
            ->map(fn ($r) => [
                'transaction' => $r->t !== '' ? $r->t : 'Sin transacción',
                'operation' => $r->o !== '' ? $r->o : 'Sin operación',
                'count' => (int) $r->c,
                'total' => round((float) $r->s, 2),
                'destination' => match (true) {
                    ! in_array($r->t, ['PAGO', 'DESCUENTO'], true) => 'Fuera de recuperación (transacción '.($r->t ?: 'vacía').')',
                    $r->kind === 'seguro' => 'Puente de seguros (no suma a recuperación)',
                    $r->kind === 'crece' => 'Recuperación (Seguro CRECE: solo el 30 % reconocido)',
                    default => 'Recuperación',
                },
                'counts' => in_array($r->t, ['PAGO', 'DESCUENTO'], true) && $r->kind !== 'seguro',
            ])->values()->all();
    }
}
