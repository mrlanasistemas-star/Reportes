<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\ExpenseReview\ExpenseReviewLog;
use App\Models\Period;
use App\Models\User;
use App\Services\Access\ActionPermissionService;
use App\Services\Access\DataScopeService;
use App\Services\ExpenseReview\ExpenseReviewQuery;
use App\Services\ExpenseReview\ExpenseReviewService;
use App\Services\PeriodEmployeeRosterService;
use App\Services\Reporting\OperativeBranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Revisión de gastos (Carga de archivos, antes de generar el reporte) — OPCIONAL.
 * Lectura con el módulo `carga`; escritura con `permission:carga,procesar` (rutas).
 * Alcance por sucursal (DataScopeService): un usuario restringido sólo ve/edita gastos de
 * sus sucursales; corporativo, «sin sucursal» y reglas por concepto exigen alcance general.
 *
 * Rutas (prefijo /historico-general, nombre historico-general.expense-review.*), todas JSON y
 * consumidas por resources/js/components/historico-general/expense-review/useExpenseReview.ts:
 *  - GET  {period}/revision-gastos                 → index()         listado paginado.
 *  - GET  {period}/revision-gastos/opciones        → filterOptions() catálogos y permisos.
 *  - GET  {period}/revision-gastos/conceptos       → concepts()      catálogo de conceptos.
 *  - GET  {period}/revision-gastos/{expense}/bitacora → history()    bitácora de un gasto.
 *  - POST {period}/revision-gastos/decisiones      → saveDecisions() (permission:carga,procesar).
 *  - POST {period}/revision-gastos/conceptos       → saveConcept()   (permission:carga,procesar).
 *
 * Toda la lógica vive en ExpenseReviewQuery (lectura) y ExpenseReviewService (escritura).
 */
class ExpenseReviewController extends Controller
{
    /**
     * @param  ExpenseReviewQuery  $query  Lecturas (listado, resumen, conceptos).
     * @param  ExpenseReviewService  $service  Escritura de decisiones y reglas.
     * @param  DataScopeService  $scope  Alcance por sucursal del usuario.
     */
    public function __construct(
        private readonly ExpenseReviewQuery $query,
        private readonly ExpenseReviewService $service,
        private readonly DataScopeService $scope,
    ) {}

    /**
     * Corta la petición con 503 si la migración de la revisión de gastos no está aplicada.
     *
     * @return JsonResponse|null Respuesta 503 con mensaje en español, o null si está instalada.
     */
    private function guardInstalled(): ?JsonResponse
    {
        return ExpenseReviewService::installed() ? null : response()->json([
            'message' => 'La revisión de gastos aún no está disponible: falta aplicar la actualización de base de datos (php artisan migrate).',
        ], 503);
    }

    /**
     * Valida y normaliza los filtros del listado (query string). Por defecto filter = 'pendientes'.
     *
     * @param  Request  $request  Petición con filter, source, q, page, per_page, sort.
     * @return array<string, mixed> Filtros validados.
     *
     * @throws ValidationException Si algún filtro no es válido (422).
     */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'filter' => ['nullable', Rule::in(ExpenseReviewQuery::FILTERS)],
            'source' => ['nullable', 'string', 'max:60'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50, 100])],
            'sort' => ['nullable', Rule::in(['monto', 'fecha', 'concepto'])],
        ]);

        return $data + ['filter' => 'pendientes'];
    }

    /**
     * Listado paginado de gastos del periodo según filtros, dentro del alcance del usuario.
     *
     * @param  Request  $request  Petición con filtros (ver filters()).
     * @param  Period  $period  Periodo en revisión.
     * @return JsonResponse {rows, pagination, filtered_total, summary} (ver ExpenseReviewQuery::list()) o 503.
     */
    public function index(Request $request, Period $period): JsonResponse
    {
        if ($r = $this->guardInstalled()) {
            return $r;
        }

        return response()->json($this->query->list($period, $this->filters($request), $this->scope->allowedBranchIds($request->user())));
    }

    /**
     * Catálogos y banderas para la pantalla: colaboradores vigentes del periodo (nómina, con su
     * sucursal operativa si aplica), sucursales operativas dentro del alcance, si existe
     * CORPORATIVO, fuentes, si el usuario puede editar, si es alcance general y si el periodo
     * está cerrado. No exige la migración (devuelve installed para que la UI avise).
     *
     * @param  Request  $request  Petición del usuario autenticado.
     * @param  Period  $period  Periodo en revisión.
     * @param  PeriodEmployeeRosterService  $roster  Plantilla de colaboradores del periodo.
     * @return JsonResponse {installed, employees, branches, corporate_available, sources, can_edit, is_general, is_closed}
     */
    public function filterOptions(Request $request, Period $period, PeriodEmployeeRosterService $roster): JsonResponse
    {
        $user = $request->user();
        $branches = Branch::query()->whereIn('name', OperativeBranchService::NAMES)->orderBy('name')->get(['id', 'name']);
        $employees = collect($roster->rosterRowsForSelector($period)['rows'])
            ->map(fn (array $r) => [
                'id' => $r['employee_id'],
                'name' => $r['name'],
                'branch_id' => $r['is_branch_operativa'] ? $r['branch_id'] : null,
                'branch_name' => $r['is_branch_operativa'] ? $r['branch_name'] : null,
            ])->sortBy('name')->values();

        return response()->json([
            'installed' => ExpenseReviewService::installed(),
            'employees' => $employees,
            'branches' => $this->scope->filterBranchOptions($user, $branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->all()),
            'corporate_available' => $this->service->corporateBranchId() !== null,
            'sources' => collect(ExpenseReviewQuery::SOURCE_LABELS)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values(),
            'can_edit' => app(ActionPermissionService::class)->canAction($user, 'carga', 'procesar'),
            'is_general' => $this->scope->isGeneral($user),
            'is_closed' => (bool) $period->is_closed,
        ]);
    }

    /**
     * Catálogo de conceptos de gasto del periodo con su destino actual y regla manual, más los
     * ingresos agrupados (solo consulta). Exige alcance general (403 si no).
     *
     * @param  Request  $request  Petición.
     * @param  Period  $period  Periodo en revisión.
     * @return JsonResponse {gastos, ingresos, categories} o 503.
     */
    public function concepts(Request $request, Period $period): JsonResponse
    {
        if ($r = $this->guardInstalled()) {
            return $r;
        }
        $this->scope->requireGeneral($request->user(), 'El catálogo de conceptos del periodo');

        return response()->json($this->query->concepts($period));
    }

    /**
     * Guarda la decisión de colaborador y/o sucursal (y nota) para los gastos seleccionados o
     * para «todos los del filtro actual» (all_matching + criteria).
     *
     * Reglas: el periodo debe estar abierto (422); debe pedirse al menos una acción o nota (422);
     * «corporativo»/«ninguna» exigen alcance general (403); la sucursal asignada debe estar en el
     * alcance (403); «corporativo» exige que exista la sucursal CORPORATIVO (422); con ids
     * explícitos, ninguno puede estar fuera del alcance (403).
     * Efectos: ver ExpenseReviewService::saveDecisions() (BD + radiografía marcada para regenerar).
     *
     * @param  Request  $request  expense_ids[], all_matching, criteria{filter, source, q}, employee_action,
     *                            employee_id, branch_action, branch_id, notes.
     * @param  Period  $period  Periodo en revisión.
     * @return JsonResponse {ok, saved, removed, message, summary} o error 403/422/503.
     */
    public function saveDecisions(Request $request, Period $period): JsonResponse
    {
        if ($r = $this->guardInstalled()) {
            return $r;
        }
        $this->ensureOpen($period);
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'expense_ids' => ['array', 'max:5000'],
            'expense_ids.*' => ['integer'],
            'all_matching' => ['boolean'],
            'criteria' => ['array'],
            'employee_action' => ['nullable', Rule::in(['asignar', 'ninguno', 'automatico'])],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id', 'required_if:employee_action,asignar'],
            'branch_action' => ['nullable', Rule::in(['asignar', 'corporativo', 'ninguna', 'automatico'])],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id', 'required_if:branch_action,asignar'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'employee_id.required_if' => 'Elige el colaborador al que pertenece el gasto.',
            'branch_id.required_if' => 'Elige la sucursal a la que pertenece el gasto.',
        ]);

        if (empty($data['employee_action']) && empty($data['branch_action']) && ! array_key_exists('notes', $data)) {
            return response()->json(['message' => 'Indica qué quieres decidir: colaborador, sucursal o ambos.'], 422);
        }

        $allowed = $this->scope->allowedBranchIds($user);
        if ($allowed !== null && in_array($data['branch_action'] ?? null, ['corporativo', 'ninguna'], true)) {
            abort(403, 'Marcar un gasto como corporativo o sin sucursal sólo está disponible para usuarios con alcance general.');
        }
        if (($data['branch_action'] ?? null) === 'asignar') {
            $this->scope->authorizeBranch($user, (int) $data['branch_id']);
        }
        if (($data['branch_action'] ?? null) === 'corporativo' && $this->service->corporateBranchId() === null) {
            return response()->json(['message' => 'No existe la sucursal CORPORATIVO en el catálogo; no se puede marcar como corporativo.'], 422);
        }

        // Universo: ids explícitos o «todos los del filtro actual». Siempre dentro del periodo y del alcance.
        if (! empty($data['all_matching'])) {
            $criteria = validator($data['criteria'] ?? [], [
                'filter' => ['nullable', Rule::in(ExpenseReviewQuery::FILTERS)],
                'source' => ['nullable', 'string', 'max:60'],
                'q' => ['nullable', 'string', 'max:120'],
            ])->validate();
            $ids = $this->query->filtered($period, $criteria + ['filter' => 'pendientes'], $allowed)->pluck('e.id')->map(fn ($id) => (int) $id)->all();
        } else {
            $ids = array_values(array_unique(array_map('intval', $data['expense_ids'] ?? [])));
            if ($allowed !== null && $ids !== []) {
                $outside = DB::table('fact_expenses')->whereIn('id', $ids)
                    ->where(fn ($q) => $q->whereNull('branch_id')->orWhereNotIn('branch_id', $allowed === [] ? [0] : $allowed))->exists();
                if ($outside) {
                    abort(403, 'Algunos gastos seleccionados están fuera de tu alcance de sucursales.');
                }
            }
        }
        if ($ids === []) {
            return response()->json(['message' => 'No hay gastos seleccionados.'], 422);
        }

        $result = $this->service->saveDecisions(
            $period,
            $ids,
            ['action' => $data['employee_action'] ?? null, 'employee_id' => $data['employee_id'] ?? null],
            ['action' => $data['branch_action'] ?? null, 'branch_id' => $data['branch_id'] ?? null],
            array_key_exists('notes', $data) ? ($data['notes'] ?? '') : null,
            $user,
        );

        $n = $result['saved'] + $result['removed'];

        return response()->json([
            'ok' => true,
            'saved' => $result['saved'],
            'removed' => $result['removed'],
            'message' => $n === 1 ? 'Se guardó la decisión de 1 gasto.' : "Se guardaron las decisiones de {$n} gastos.",
            'summary' => $this->query->summary($period, $allowed),
        ]);
    }

    /**
     * Guarda o quita (category = null) el destino manual de un concepto de gasto. Exige periodo
     * abierto y alcance general; la categoría debe existir en el catálogo (ExpenseReviewQuery::categories()).
     *
     * @param  Request  $request  source_type ('lendus'|'erp'), concept, category (nullable).
     * @param  Period  $period  Periodo en revisión (donde se aplica de inmediato).
     * @return JsonResponse {ok, message} o error 403/422/503.
     */
    public function saveConcept(Request $request, Period $period): JsonResponse
    {
        if ($r = $this->guardInstalled()) {
            return $r;
        }
        $this->ensureOpen($period);
        $this->scope->requireGeneral($request->user(), 'El destino de un concepto');

        $data = $request->validate([
            'source_type' => ['required', Rule::in(['lendus', 'erp'])],
            'concept' => ['required', 'string', 'max:191'],
            'category' => ['nullable', 'string', 'max:120', Rule::in($this->query->categories())],
        ], ['category.in' => 'Elige una categoría existente del catálogo.']);

        $this->service->saveConceptRule($period, $data['source_type'], $data['concept'], $data['category'] ?? null, $request->user());

        return response()->json([
            'ok' => true,
            'message' => isset($data['category'])
                ? "Listo: «{$data['concept']}» irá a «{$data['category']}» en este periodo y en las siguientes cargas."
                : "Se quitó el destino manual de «{$data['concept']}»; vuelve a la clasificación automática.",
        ]);
    }

    /**
     * Bitácora (últimos 50 cambios) de un gasto, ligada por su huella actual, con nombres
     * legibles de colaborador/sucursal antes y después. 404 si el gasto no es del periodo;
     * 403 si un usuario restringido consulta un gasto fuera de su alcance.
     *
     * @param  Request  $request  Petición.
     * @param  Period  $period  Periodo en revisión.
     * @param  int  $expense  id de fact_expenses.
     * @return JsonResponse {logs: list<{id, action, user, at, before, after}>} o 503.
     */
    public function history(Request $request, Period $period, int $expense): JsonResponse
    {
        if ($r = $this->guardInstalled()) {
            return $r;
        }
        $row = DB::table('fact_expenses')->where('period_id', $period->id)->where('id', $expense)->first(['id', 'branch_id']);
        abort_if(! $row, 404, 'El gasto no pertenece a este periodo.');
        if (! $this->scope->isGeneral($request->user())) {
            $this->scope->authorizeBranch($request->user(), $row->branch_id ? (int) $row->branch_id : -1);
        }
        $fp = $this->service->fingerprintsForPeriod($period->id)[$expense] ?? null;

        $logs = ExpenseReviewLog::query()
            ->where('period_id', $period->id)->where('subject', 'gasto')->where('subject_key', $fp)
            ->leftJoin('users as u', 'u.id', '=', 'expense_review_logs.user_id')
            ->orderByDesc('expense_review_logs.id')->limit(50)
            ->get(['expense_review_logs.*', 'u.name as user_name']);

        // Nombres legibles del antes/después (la bitácora guarda ids).
        $ids = fn (string $k) => $logs->flatMap(fn ($l) => [$l->before['gasto'][$k] ?? null, $l->after['gasto'][$k] ?? null])->filter()->unique()->all();
        $empNames = DB::table('employees')->whereIn('id', $ids('employee_id'))->pluck('full_name', 'id');
        $branchNames = DB::table('branches')->whereIn('id', $ids('branch_id'))->pluck('name', 'id');
        $side = fn (?array $s) => $s === null ? null : [
            'employee' => isset($s['gasto']['employee_id']) ? ($empNames[$s['gasto']['employee_id']] ?? "#{$s['gasto']['employee_id']}") : null,
            'branch' => isset($s['gasto']['branch_id']) ? ($branchNames[$s['gasto']['branch_id']] ?? "#{$s['gasto']['branch_id']}") : null,
            'notes' => $s['decision']['notes'] ?? null,
        ];

        $logs = $logs->map(fn ($l) => [
            'id' => $l->id,
            'action' => $l->action,
            'user' => $l->user_name ?? 'Sistema',
            'at' => optional($l->created_at)->toIso8601String(),
            'before' => $side($l->before),
            'after' => $side($l->after),
        ]);

        return response()->json(['logs' => $logs]);
    }

    /**
     * Aborta con 422 si el periodo ya está cerrado (no admite cambios en la revisión).
     *
     * @param  Period  $period  Periodo a validar.
     */
    private function ensureOpen(Period $period): void
    {
        abort_if((bool) $period->is_closed, 422, 'El periodo está cerrado: ya no admite cambios en la revisión de gastos.');
    }
}
