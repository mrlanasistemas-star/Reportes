<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Okr\Concerns\ResolvesOperativeBranches;
use App\Http\Requests\Okr\StoreObjectiveRequest;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\Period;
use App\Models\User;
use App\Services\Okr\OkrActivationService;
use App\Services\Okr\OkrAuditLogger;
use App\Services\Okr\OkrCalendarService;
use App\Services\Okr\OkrEmployeeBranchResolver;
use App\Services\Okr\OkrKpiValueResolver;
use App\Services\Okr\OkrObjectiveVisibilityService;
use App\Services\Okr\OkrProgressCalculator;
use App\Services\Okr\OkrSnapshotService;
use App\Services\Okr\OkrTrackingPeriodResolver;
use App\Services\Okr\OkrWeightValidator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Módulo OKR (08-sep-2026) — wizard de creación + seguimiento (secciones 22/24
 * del pedido). Controlador delgado: la lógica de negocio vive en los servicios
 * de app/Services/Okr — nunca cálculos aquí.
 */
class ObjectiveController extends Controller
{
    use ResolvesOperativeBranches, AuthorizesRequests;

    /** Parte C4 del cierre (17-sep-2026) — lookups dinámicos nunca cacheables. */
    private const NO_STORE_HEADERS = ['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0', 'Pragma' => 'no-cache'];

    /**
     * Búsqueda ligera de colaboradores de UNA sucursal (bug "empleado de otra
     * sucursal" — sección 46/AP del pedido: nunca cargar la lista completa).
     * Reutiliza OkrEmployeeBranchResolver → employee_branch_assignments, la
     * MISMA fuente que ya usa Reportería — nunca una asignación inventada.
     */
    public function employeesLookup(Request $request, OkrEmployeeBranchResolver $resolver): JsonResponse
    {
        $this->authorize('okr.view');
        $request->validate(['branch_id' => ['nullable', 'integer', 'exists:branches,id'], 'search' => ['nullable', 'string', 'max:100']]);

        // 6 del cierre (05-oct-2026): este lookup es, en la práctica, un
        // directorio de colaboradores de una sucursal — pensado para el
        // wizard de asignación (okr.create, admin/gerencial). Un colaborador
        // autenticado NO debe poder usarlo como directorio general; solo ve
        // su propio Employee vinculado (si tiene uno), nunca el de otros.
        if (!$request->user()->hasManagerialAccess()) {
            $own = $request->user()->employee_id ? Employee::find($request->user()->employee_id) : null;
            $employees = $own ? [['id' => $own->id, 'full_name' => $own->full_name, 'is_active' => $own->is_active]] : [];

            return response()->json(['employees' => $employees, 'total_in_branch' => count($employees)], 200, self::NO_STORE_HEADERS);
        }

        $branchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;
        $personnel = app(\App\Services\Okr\OkrPersonnelResolver::class);

        // 15-oct-2026 — dos listas distintas: 'assign' (default) solo
        // colaboradores VIGENTES de la sucursal para NUEVAS asignaciones;
        // 'history' = personas que participan o participaron en un OKR (aunque
        // hoy sean baja) para filtros de seguimiento/histórico.
        if ($request->input('mode') === 'history') {
            $search = trim($request->string('search')->toString());
            $ids = OkrObjective::query()
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->get(['employee_id', 'accountable_employee_id'])
                ->flatMap(fn ($o) => [$o->employee_id, $o->accountable_employee_id])
                ->filter()->unique()->values()->all();
            $active = $resolver->activeMap($ids);
            $participants = Employee::query()->whereIn('id', $ids)
                ->when($search !== '', fn ($q) => $q->where('full_name', 'like', "%{$search}%"))
                ->orderBy('full_name')->limit(50)->get(['id', 'full_name'])
                ->map(fn ($e) => ['id' => $e->id, 'full_name' => $e->full_name . (($active[$e->id] ?? false) ? '' : ' (baja)'), 'is_active' => $active[$e->id] ?? false]);

            return response()->json(['employees' => $participants, 'mode' => 'history'], 200, self::NO_STORE_HEADERS);
        }

        $employees = $resolver->employeesForBranch($branchId, $request->string('search')->toString());
        $ids = $employees->pluck('id')->all();
        $positions = $personnel->positionsFor($ids);
        // 16-oct-2026: ruta y fuente de pertenencia vigente + señales (sin NOI,
        // sin actividad) — visibles, nunca convertidas en baja.
        $memberships = $resolver->membershipMap($ids);
        $flags = $resolver->statusFlags($ids);
        $normById = Employee::query()->whereIn('id', $ids)->pluck('normalized_name', 'id');
        $codes = $personnel->directoryCodesByName($normById->filter()->values()->all());
        $employees = $employees->map(fn ($e) => [
            'id' => $e->id, 'full_name' => $e->full_name, 'position' => $positions[$e->id] ?? null,
            'employee_code' => ($codes[$normById[$e->id] ?? ''] ?? [null])[0],
            'route' => $memberships[$e->id]['route'] ?? null,
            'branch_name' => $memberships[$e->id]['branch_name'] ?? null,
            'membership_source' => $memberships[$e->id]['source_label'] ?? null,
            'flags' => $flags[$e->id] ?? [],
        ])->values();

        // Nunca cacheable (Parte C4 del cierre, 17-sep-2026) — resultado sensible
        // a búsqueda/sucursal, un cache compartido/proxy podría servir la lista de
        // otro filtro.
        return response()->json([
            'employees' => $employees,
            // Total real de la sucursal (sin el límite de 30 del buscador) —
            // lo usa el resumen del wizard de asignación para mostrar "todos
            // todos" los colaboradores cuando no se activan OKR individuales.
            'total_in_branch' => $branchId !== null ? $resolver->countForBranch($branchId) : null,
            // Nómina vigente usada para decidir quién está activo (14-oct-2026).
            'roster_period' => $resolver->currentRosterPeriodLabel(),
            'roster' => $resolver->rosterStatus(),
        ], 200, self::NO_STORE_HEADERS);
    }

    /**
     * Búsqueda ligera de Objectives para el filtro "Objective / OKR" de
     * Seguimiento (docs/imagenesOKR/9.png) — permite cambiar de OKR sin volver
     * al Dashboard. Nunca precarga todos los Objectives del sistema.
     */
    public function objectivesLookup(Request $request, OkrObjectiveVisibilityService $visibility): JsonResponse
    {
        $this->authorize('okr.view');
        $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'period_id' => ['nullable', 'integer', 'exists:periods,id'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        // 5 del cierre (05-oct-2026): este lookup devolvía id/title de
        // CUALQUIER Objective — un colaborador podía descubrir OKR ajenos
        // (incluso solo buscando texto) sin abrir la ficha. Mismo scope
        // central que Policy/Dashboard/History/Alerts.
        $query = $visibility->applyScope(
            OkrObjective::query()->whereNull('deleted_at')->where('lifecycle_status', '!=', OkrObjective::STATUS_CANCELLED),
            $request->user(),
        );
        if ($branchId = $request->integer('branch_id')) {
            $query->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereHas('parent', fn ($p) => $p->where('branch_id', $branchId)));
        }
        if ($employeeId = $request->integer('employee_id')) {
            // 15-oct-2026: OKR donde la persona realmente participa (su OKR individual o como titular de sucursal).
            $query->where(fn ($q) => $q->where('employee_id', $employeeId)->orWhere('accountable_employee_id', $employeeId));
        }
        if ($periodId = $request->integer('period_id')) {
            $period = Period::find($periodId);
            if ($period && $period->start_date && $period->end_date) {
                // Mismo criterio de traslape que DashboardController::applyDashboardFilters().
                $query->where('start_date', '<=', $period->end_date)->where('end_date', '>=', $period->start_date);
            }
        }
        if ($search = $request->string('search')->toString()) {
            $query->where('title', 'like', "%{$search}%");
        }

        $objectives = $query->with(['branch:id,name', 'employee:id,full_name'])->orderByDesc('id')->limit(30)->get(['id', 'title', 'scope_type', 'branch_id', 'employee_id', 'lifecycle_status'])
            ->map(fn ($o) => [
                'id' => $o->id,
                'title' => $o->title,
                'label' => ($o->scope_type === OkrObjective::SCOPE_BRANCH ? 'Sucursal ' . ($o->branch?->name ?? '') : 'Gestor ' . ($o->employee?->full_name ?? '')) . ' — ' . $o->title,
                'scope_type' => $o->scope_type,
            ]);

        return response()->json(['objectives' => $objectives], 200, self::NO_STORE_HEADERS);
    }

    /**
     * Vista previa READ-ONLY de la línea base real (punto 3 de la auditoría
     * 09-sep-2026) — nunca guarda nada. Deja ver el valor que Reportería
     * tiene ANTES de crear/activar el Objective, para que el usuario sepa qué
     * va a congelarse sin adivinar.
     */
    public function baselinePreview(Request $request, OkrKpiValueResolver $resolver, OkrTrackingPeriodResolver $periodResolver): JsonResponse
    {
        $this->authorize('okr.create');

        $data = $request->validate([
            'kpi_id'      => ['required', 'integer', 'exists:okr_kpis,id'],
            'scope_type'  => ['required', 'in:general,branch,employee'],
            'branch_id'   => ['nullable', 'integer', 'exists:branches,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'start_date'  => ['required', 'date'],
        ]);

        $kpi = OkrKpi::query()->findOrFail($data['kpi_id']);

        // Regla definitiva (16-oct-2026): la línea base de un OKR INDIVIDUAL se
        // captura manualmente — nunca se consulta en Reportería ni se sustituye
        // por la de la sucursal. Se responde sin consultar y sin bloquear.
        if ($data['scope_type'] === OkrObjective::SCOPE_EMPLOYEE) {
            return response()->json([
                'available' => false, 'value' => null, 'period' => null, 'source' => 'manual_individual',
                'scope_type' => $data['scope_type'], 'manual_required' => true,
                'message' => 'Captura la línea base del gestor.',
            ], 200, self::NO_STORE_HEADERS);
        }

        if (!$kpi->isAutomatic()) {
            return response()->json(['available' => false, 'value' => null, 'period' => null, 'source' => 'manual']);
        }

        $period = $periodResolver->getPeriodForDate($data['start_date']);
        $value  = $period
            ? $resolver->getValue($kpi, $data['scope_type'], $data['branch_id'] ?? null, $data['employee_id'] ?? null, $period)
            : null;

        return response()->json([
            'available' => $value !== null,
            'value'     => $value,
            'period'    => $period ? ['id' => $period->id, 'label' => $period->label, 'start_date' => $period->start_date?->toDateString(), 'end_date' => $period->end_date?->toDateString()] : null,
            'source'    => $kpi->provider_key,
            'scope_type' => $data['scope_type'],
        ], 200, self::NO_STORE_HEADERS);
    }

    public function store(StoreObjectiveRequest $request, OkrWeightValidator $weightValidator, OkrCalendarService $calendar, OkrAuditLogger $logger): RedirectResponse
    {
        $data = $request->validated();

        $totalWeight = round(array_sum(array_column($data['key_results'], 'weight')), 2);
        if (abs($totalWeight - 100.0) > 0.01) {
            return back()->withErrors(['key_results' => "La ponderación de los Key Results debe sumar 100% (actual: {$totalWeight}%)."])->withInput();
        }

        $employeeId = $data['scope_type'] === OkrObjective::SCOPE_EMPLOYEE ? $data['employee_id'] : null;
        $branchId   = $data['branch_id'] ?? null;
        $individualRows = $data['scope_type'] === OkrObjective::SCOPE_BRANCH ? ($data['individual_objectives'] ?? []) : [];

        $objective = DB::transaction(function () use ($data, $employeeId, $branchId, $individualRows, $calendar, $logger) {
            $startDate = \Carbon\Carbon::parse($data['start_date']);
            $responsibleId = $data['responsible_user_id'] ?? auth()->id();
            $endDate = $calendar->endDate($startDate, (int) $data['duration_weeks']);

            $objective = OkrObjective::query()->create([
                'parent_id'            => $data['parent_id'] ?? null,
                'scope_type'           => $data['scope_type'],
                'branch_id'            => $branchId,
                'employee_id'          => $employeeId,
                'title'                => $data['title'],
                'responsible_user_id'  => $responsibleId,
                'created_by'           => auth()->id(),
                'start_date'           => $startDate,
                // Fin INCLUSIVO de la semana N (ver OkrCalendarService) — antes
                // addWeeks($n) dejaba un día de más (bug corregido punto 6).
                'end_date'             => $endDate,
                'duration_weeks'       => $data['duration_weeks'],
                'lifecycle_status'     => OkrObjective::STATUS_DRAFT,
            ]);

            $this->createKeyResults($objective, $data['key_results'], $logger);

            $logger->log('objective', $objective->id, auth()->id(), 'created');

            // OKR individuales de la misma asignación — ligados al principal
            // (parent_id), misma sucursal, mismo plazo, EN BORRADOR. Corrección
            // 14-oct-2026 (defecto 1): cada uno se guarda con SUS PROPIOS Key
            // Results capturados en el asistente (KPI, línea base individual,
            // incremento → meta, peso) — antes nacían vacíos. Nunca se copian
            // la base ni la meta de la sucursal. Si cualquiera falla, toda la
            // transacción (principal + individuales + KRs) se revierte.
            foreach ($individualRows as $row) {
                $child = OkrObjective::query()->create([
                    'parent_id'            => $objective->id,
                    'scope_type'           => OkrObjective::SCOPE_EMPLOYEE,
                    'branch_id'            => $objective->branch_id,
                    'employee_id'          => $row['employee_id'],
                    'title'                => $row['title'],
                    'responsible_user_id'  => $responsibleId,
                    'created_by'           => auth()->id(),
                    'start_date'           => $startDate,
                    'end_date'             => $endDate,
                    'duration_weeks'       => $data['duration_weeks'],
                    'lifecycle_status'     => OkrObjective::STATUS_DRAFT,
                ]);
                $logger->log('objective', $child->id, auth()->id(), 'created', reason: "OKR individual creado junto con el Objective de sucursal #{$objective->id}.");
                $this->createKeyResults($child, $row['key_results'] ?? [], $logger);
            }

            return $objective;
        });

        return redirect()->route('okr.show', $objective)->with('success', 'Objective creado en borrador. Revisa la línea base antes de activar.');
    }

    /**
     * Crea los Key Results de un Objective. Una línea base capturada para un
     * KPI automático solo llega aquí si StoreObjectiveRequest ya verificó que
     * Reportería no tiene dato para ese alcance y que trae motivo: se marca
     * como 'manual_authorized' y el motivo queda en la bitácora.
     */
    private function createKeyResults(OkrObjective $objective, array $keyResults, OkrAuditLogger $logger): void
    {
        $isIndividual = $objective->scope_type === OkrObjective::SCOPE_EMPLOYEE;

        foreach ($keyResults as $krData) {
            $reason = trim((string) ($krData['baseline_manual_reason'] ?? ''));
            $isAuthorizedManual = !$isIndividual && ($krData['baseline_value'] ?? null) !== null && $reason !== '';

            $kr = $objective->keyResults()->create([
                'kpi_id'          => $krData['kpi_id'],
                'description'     => $krData['description'],
                'baseline_value'  => $krData['baseline_value'] ?? null,
                // OKR individual (16-oct-2026): línea base MANUAL del gestor, siempre.
                'baseline_source' => $isIndividual ? \App\Services\Okr\OkrBaselineGuard::SOURCE_MANUAL_INDIVIDUAL : ($isAuthorizedManual ? 'manual_authorized' : null),
                'target_value'    => $krData['target_value'],
                'weight'          => $krData['weight'],
            ]);

            if ($isIndividual) {
                $logger->log('key_result', $kr->id, auth()->id(), 'baseline_manual_individual', 'baseline_value', null, $kr->baseline_value, 'Línea base individual capturada para el gestor.');
            } elseif ($isAuthorizedManual) {
                $logger->log('key_result', $kr->id, auth()->id(), 'baseline_manual_authorized', 'baseline_value', null, $kr->baseline_value, $reason);
            }
        }
    }

    public function show(OkrObjective $objective): Response
    {
        $this->authorize('view', $objective);

        $objective->load(['branch', 'employee', 'responsibleUser', 'creator', 'parent', 'children.branch', 'children.employee', 'children.keyResults.kpi', 'children.keyResults.snapshots' => fn ($q) => $q->orderBy('week_number'), 'children.evidences', 'children.commitmentLetter', 'children.warnings', 'accountableEmployee', 'commitmentLetters.voidedByUser', 'commitmentLetters.generatedByUser',
            'keyResults.kpi', 'keyResults.snapshots' => fn ($q) => $q->orderBy('week_number'),
            'checkIns.user', 'correctiveActions.responsibleUser', 'evidences.uploader', 'alerts' => fn ($q) => $q->whereNull('read_at')->latest(),
            'commitmentLetter', 'warnings' => fn ($q) => $q->latest('week_number')]);

        $calc = app(OkrProgressCalculator::class);
        $krPayload = $objective->keyResults->map(fn ($kr) => [
            'id' => $kr->id, 'description' => $kr->description,
            'kpi' => $kr->kpi->only(['id', 'code', 'name', 'unit', 'type', 'direction', 'automation', 'provider_key']),
            'baseline_value' => $kr->baseline_value, 'baseline_source' => $kr->baseline_source, 'target_value' => $kr->target_value, 'weight' => $kr->weight,
            'current_value' => $kr->current_value, 'expected_value' => $kr->expected_value,
            'actual_progress_percentage' => $kr->actual_progress_percentage, 'expected_progress_percentage' => $kr->expected_progress_percentage,
            'deviation_pp' => $kr->deviation_pp, 'projected_value' => $kr->projected_value,
            'projected_compliance_percentage' => $kr->projected_compliance_percentage, 'health_status' => $kr->health_status,
            'baseline_locked_at' => $kr->baseline_locked_at?->toDateTimeString(),
            'last_evaluated_at' => $kr->last_evaluated_at?->toDateTimeString(),
            'last_manual_input_at' => $kr->last_manual_input_at?->toDateTimeString(),
            'snapshots' => $kr->snapshots->map(fn ($s) => [
                'week_number' => $s->week_number, 'actual_value' => $s->actual_value, 'expected_value' => $s->expected_value,
                'actual_progress_percentage' => $s->actual_progress_percentage, 'expected_progress_percentage' => $s->expected_progress_percentage,
                'deviation_pp' => $s->deviation_pp, 'health_status' => $s->health_status,
                // Trazabilidad de fuente (punto 4 de la auditoría 09-sep-2026) —
                // la UI nunca finge granularidad semanal real que no existe.
                'source_quality' => $s->source_quality, 'source_granularity' => $s->source_granularity,
                'source_period_code' => $s->source_period_code,
            ]),
        ]);

        $weightSummary = app(OkrWeightValidator::class)->summary($objective);
        $compliance = $calc->objectiveCompliance($krPayload->map(fn ($kr) => ['raw_progress' => $kr['actual_progress_percentage'], 'weight' => (float) $kr['weight']])->all());
        // "Avance esperado"/"Proyección de cierre" a nivel Objective (sección
        // 24 de la auditoría 10-sep-2026, docs/imagenesOKR/9.png) — MISMA
        // fórmula canónica (Σ cumplimiento×peso), solo cambia qué campo del KR
        // se le pasa (esperado/proyectado en vez de real) — nunca una fórmula nueva.
        $expectedCompliance = $calc->objectiveCompliance($krPayload->map(fn ($kr) => ['raw_progress' => $kr['expected_progress_percentage'], 'weight' => (float) $kr['weight']])->all());
        $hasProjection = $krPayload->contains(fn ($kr) => $kr['projected_compliance_percentage'] !== null);
        $projectedCompliance = $hasProjection
            ? $calc->objectiveCompliance($krPayload->map(fn ($kr) => ['raw_progress' => $kr['projected_compliance_percentage'], 'weight' => (float) $kr['weight']])->all())
            : null;
        $deviation = $calc->deviationPp($compliance, $expectedCompliance);

        // Bitácora de auditoría (sección AD del pedido) — "una meta no se puede
        // modificar silenciosamente": cambios del Objective mismo + de sus KR.
        $keyResultIds = $objective->keyResults->pluck('id')->all();
        $auditLogs = \App\Models\OkrAuditLog::query()
            ->where(fn ($q) => $q->where('auditable_type', 'objective')->where('auditable_id', $objective->id))
            ->orWhere(fn ($q) => $q->where('auditable_type', 'key_result')->whereIn('auditable_id', $keyResultIds))
            ->with('user:id,name')
            ->latest()
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id, 'action' => $log->action, 'field' => $log->field,
                // Unidad del KPI para mostrar "$638,576.00" en vez de "638576" (14-oct-2026).
                'unit' => $log->auditable_type === 'key_result' ? $objective->keyResults->firstWhere('id', $log->auditable_id)?->kpi?->unit : null,
                'old_value' => $log->old_value, 'new_value' => $log->new_value, 'reason' => $log->reason,
                'user' => $log->user?->name, 'created_at' => $log->created_at->toDateTimeString(),
            ]);

        // Contribución sucursal ↔ gestores (sección 25 de la auditoría 09-sep-2026)
        // — solo tiene sentido para KPI DISTRIBUIBLES (moneda/entero, nunca un
        // porcentaje como mora, que no se puede "sumar" entre gestores). Por
        // cada KR de la sucursal, suma los KR de los hijos que comparten el
        // MISMO kpi_id — nunca mezcla KPIs distintos.
        $contributions = [];
        if ($objective->scope_type === OkrObjective::SCOPE_BRANCH && $objective->children->isNotEmpty()) {
            $placementResolver = app(\App\Services\Okr\OkrWeeklyPlacementResolver::class);

            // Parte 14 del cierre (04-oct-2026) — en LOTE para TODOS los hijos a
            // la vez (agrupados por su semana actual, normalmente la misma para
            // todos): 2 consultas totales, nunca una consulta de colocación por
            // cada gestor dentro del loop de abajo.
            $weeklyByChildId = [];
            $startedChildren = $objective->children->filter(fn ($c) => $c->hasStarted());
            foreach ($startedChildren->groupBy(fn ($c) => $c->currentWeekNumber()) as $weekNumber => $group) {
                $weeklyByChildId += $placementResolver->resolveMany($group, (int) $weekNumber);
            }

            foreach ($objective->keyResults as $parentKr) {
                if ($parentKr->kpi->type === OkrKpi::TYPE_PERCENTAGE) {
                    continue; // no distribuible entre gestores
                }
                $childRows = [];
                foreach ($objective->children as $child) {
                    $childKr = $child->keyResults->firstWhere('kpi_id', $parentKr->kpi_id);
                    if (!$childKr) {
                        continue;
                    }
                    // Parte 9/10 del cierre (04-oct-2026): colocación de ESTA
                    // semana (delta), aparte del acumulado — solo tiene sentido
                    // para el KPI de colocación con carga semanal activa.
                    $weeklyValue = $parentKr->kpi->provider_key === 'reporteria.placement'
                        ? ($weeklyByChildId[$child->id]['weekly'] ?? null)
                        : null;

                    $childRows[] = [
                        'employee' => $child->employee?->full_name ?? $child->title,
                'employee_id' => $child->employee_id,
                        'employee_active' => $child->employee?->is_active ?? true,
                        'target_value' => (float) $childKr->target_value,
                        'current_value' => $childKr->current_value !== null ? (float) $childKr->current_value : null,
                        'weekly_value' => $weeklyValue,
                        'compliance' => $childKr->actual_progress_percentage,
                        'health_status' => $childKr->health_status,
                    ];
                }
                if (empty($childRows)) {
                    continue;
                }
                $sumTarget  = array_sum(array_column($childRows, 'target_value'));
                $sumCurrent = array_sum(array_map(fn ($r) => $r['current_value'] ?? 0, $childRows));
                $branchTarget = (float) $parentKr->target_value;
                $contributions[] = [
                    'kpi' => $parentKr->kpi->only(['id', 'name', 'unit']),
                    'branch_target' => $branchTarget,
                    'children_target_sum' => $sumTarget,
                    'children_current_sum' => $sumCurrent,
                    'coverage_percentage' => $sumTarget > 0 ? round(($sumCurrent / $sumTarget) * 100, 1) : null,
                    'gap' => $branchTarget - $sumTarget,
                    // Parte 9.2 — distribución de la meta entre gestores: si Σ
                    // metas individuales != meta de sucursal, warning visible
                    // (nunca bloquea, ver spec) — nunca silencioso.
                    'distribution_mismatch' => abs($branchTarget - $sumTarget) > 0.01,
                    'rows' => $childRows,
                ];
            }
        }

        // Parte 6/7 del cierre (04-oct-2026) — estado de la carga semanal de
        // colocación, semana por semana, solo si este Objective tiene un KR
        // ligado al KPI de colocación (nunca se ofrece el widget para KPIs
        // que no lo usan).
        $placementWeeks = [];
        if ($objective->keyResults->contains(fn ($kr) => $kr->kpi->provider_key === 'reporteria.placement')) {
            $uploads = $objective->placementUploads()->where('status', \App\Models\OkrPlacementUpload::STATUS_ACTIVE)->get()->keyBy('week_number');
            // Un OKR individual sin carga propia se alimenta del archivo de su
            // sucursal (una sola carga de evidencia, 14-oct-2026).
            $parentUploads = $objective->scope_type === OkrObjective::SCOPE_EMPLOYEE && $objective->parent_id
                ? \App\Models\OkrPlacementUpload::query()->where('okr_objective_id', $objective->parent_id)->where('status', \App\Models\OkrPlacementUpload::STATUS_ACTIVE)->get()->keyBy('week_number')
                : collect();
            // Importe CONTADO por semana (sin movimientos repetidos de semanas
            // anteriores y, para un gestor, solo sus movimientos).
            $counted = app(\App\Services\Okr\OkrWeeklyPlacementResolver::class)->resolve($objective, (int) $objective->duration_weeks)['weeks'] ?? [];
            for ($w = 1; $w <= (int) $objective->duration_weeks; $w++) {
                $upload = $uploads->get($w);
                $fromParent = $upload === null ? $parentUploads->get($w) : null;
                $source = $upload ?? $fromParent;
                $placementWeeks[] = [
                    'week_number' => $w,
                    'week_start' => app(OkrCalendarService::class)->weekStart($objective->start_date, $w)->toDateString(),
                    'week_end' => app(OkrCalendarService::class)->weekEnd($objective->start_date, $w)->toDateString(),
                    'gestores_count' => $source ? $source->movements()->whereNotNull('employee_id')->distinct()->count('employee_id') : 0,
                    'uploaded' => $source !== null,
                    'from_branch_file' => $fromParent !== null,
                    'total_amount' => $upload?->total_amount,
                    'counted_amount' => $counted[$w - 1] ?? null,
                    'original_filename' => $source?->original_filename,
                    'uploaded_at' => $source?->created_at?->toDateTimeString(),
                    'rows_count' => $source?->rows_count,
                    'rows_duplicated' => $source?->rows_duplicated,
                    'rows_other_branch' => $source?->rows_other_branch,
                    'rows_cancelled' => $source?->rows_cancelled,
                    'unattributed_amount' => $source?->unattributed_amount,
                    'coverage_status' => $source?->coverage_status,
                ];
            }
        }

        $gestorTracking = $this->gestorTracking($objective, $calc);

        return Inertia::render('Okr/Show', [
            'objective' => [
                'id' => $objective->id, 'title' => $objective->title, 'scope_type' => $objective->scope_type,
                'branch' => $objective->branch?->only(['id', 'name']), 'employee' => $objective->employee?->only(['id', 'full_name']),
                'responsible' => $objective->responsibleUser?->only(['id', 'name']), 'creator' => $objective->creator?->only(['id', 'name']),
                'parent' => $objective->parent ? ['id' => $objective->parent->id, 'title' => $objective->parent->title, 'branch' => $objective->branch?->name] : null,
                'children' => $objective->children->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'employee' => $c->employee?->full_name, 'lifecycle_status' => $c->lifecycle_status]),
                'contributions' => $contributions,
                'gestor_tracking' => $gestorTracking,
                'start_date' => $objective->start_date->toDateString(), 'end_date' => $objective->end_date->toDateString(),
                'duration_weeks' => $objective->duration_weeks, 'current_week' => $objective->currentWeekNumber(),
                'lifecycle_status' => $objective->lifecycle_status, 'health_status' => $objective->health_status,
                'final_status' => $objective->final_status,
                'compliance' => $compliance, 'expected_compliance' => $expectedCompliance,
                'projected_compliance' => $projectedCompliance, 'deviation_pp' => $deviation,
                'weight_summary' => $weightSummary,
                'placement_weeks' => $placementWeeks,
                'is_read_only' => $objective->isReadOnly(),
                'activated_at' => $objective->activated_at?->toDateTimeString(),
                'commitment_letter' => $objective->commitmentLetter ? [
                    'id' => $objective->commitmentLetter->id, 'folio' => $objective->commitmentLetter->folio,
                    'collaborator' => $objective->commitmentLetter->snapshot['collaborator_name'] ?? $objective->commitmentLetter->snapshot['employee_name'] ?? null,
                    'generated_at' => $objective->commitmentLetter->generated_at->toDateTimeString(),
                    'is_signed' => $objective->commitmentLetter->isSigned(),
                    'download_url' => route('okr.commitment-letters.download', $objective->commitmentLetter),
                    'view_url' => route('okr.commitment-letters.download', $objective->commitmentLetter) . '?view=1',
                ] : null,
                // 15-oct-2026: historial de cartas (vigente y anuladas) y persona evaluada.
                'commitment_letters' => $objective->commitmentLetters->sortByDesc('id')->values()->map(fn ($l) => [
                    'id' => $l->id, 'folio' => $l->folio, 'status' => $l->status,
                    'collaborator' => $l->snapshot['collaborator_name'] ?? $l->snapshot['employee_name'] ?? null,
                    'generated_at' => $l->generated_at?->toDateTimeString(), 'generated_by' => $l->generatedByUser?->name,
                    'is_signed' => $l->isSigned(), 'voided_at' => $l->voided_at?->toDateTimeString(), 'void_reason' => $l->void_reason,
                    'voided_by' => $l->voidedByUser?->name, 'download_url' => route('okr.commitment-letters.download', $l),
                    'view_url' => route('okr.commitment-letters.download', $l) . '?view=1',
                ]),
                'accountable' => $objective->accountableEmployee ? ['id' => $objective->accountableEmployee->id, 'full_name' => $objective->accountableEmployee->full_name] : null,
                'subject' => $this->subjectPayload($objective),
            ],
            'warnings' => $objective->warnings->map(fn ($w) => [
                'id' => $w->id, 'folio' => $w->folio, 'week_number' => $w->week_number, 'status' => $w->status ?? 'issued',
                'collaborator' => $w->snapshot['collaborator_name'] ?? $w->snapshot['employee_name'] ?? null,
                'generated_at' => $w->generated_at->toDateTimeString(), 'is_signed' => $w->isSigned(),
                'voided_at' => $w->voided_at?->toDateTimeString(), 'void_reason' => $w->void_reason,
                'download_url' => route('okr.warnings.download', $w),
                'view_url' => route('okr.warnings.download', $w) . '?view=1',
            ]),
            // Parte 5/7 del cierre (04-oct-2026) — catálogo de KPI para el
            // editor de Key Results en DRAFT (ver Show.vue): un Objective
            // individual creado por el wizard de individualización nace SIN
            // KRs propios (a propósito, ver ObjectiveController::store()) —
            // esto es lo que permite completarlo y poder activarlo.
            'kpis' => OkrKpi::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'unit', 'type', 'direction', 'automation', 'provider_key']),
            'keyResults' => $krPayload,
            'checkIns' => $objective->checkIns->map(fn ($c) => ['id' => $c->id, 'week_number' => $c->week_number, 'check_in_date' => $c->check_in_date->toDateString(), 'user' => $c->user->name, 'main_blocker' => $c->main_blocker, 'corrective_action' => $c->corrective_action]),
            'correctiveActions' => $objective->correctiveActions->map(fn ($a) => ['id' => $a->id, 'description' => $a->description, 'responsible' => $a->responsibleUser->name, 'due_date' => $a->due_date->toDateString(), 'status' => $a->status, 'is_overdue' => $a->isOverdue()]),
            'evidences' => $objective->evidences->map(fn ($e) => ['id' => $e->id, 'original_name' => $e->original_name, 'uploader' => $e->uploader->name, 'week_number' => $e->week_number, 'created_at' => $e->created_at->toDateTimeString(), 'comment' => $e->comment]),
            'alerts' => $objective->alerts->map(fn ($a) => ['id' => $a->id, 'type' => $a->type, 'message' => $a->message, 'created_at' => $a->created_at->toDateTimeString()]),
            'auditLogs' => $auditLogs,
            'canManage' => auth()->user()?->can('delete', $objective) ?? false,
            'canAssign' => auth()->user()?->can('assign', $objective) ?? false,
            'canUpdate' => auth()->user()?->can('update', $objective) ?? false,
            'canCheckin' => auth()->user()?->can('checkin', $objective) ?? false,
            'canUploadEvidence' => auth()->user()?->can('uploadEvidence', $objective) ?? false,
            // Filtro "Seguimiento" (docs/imagenesOKR/9.png) — cambiar de
            // sucursal/colaborador/OKR/periodo sin volver al Dashboard.
            'filterBranches' => $this->filterBranches(),
            'filterPeriods'  => Period::query()->where('type', 'monthly')->orderByDesc('id')->limit(24)->get()->map(fn ($p) => ['id' => $p->id, 'label' => $p->label]),
            // Para el selector "Responsable" del check-in (acción correctiva).
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * "SEGUIMIENTO POR GESTOR" (14-oct-2026) — una tarjeta por cada OKR
     * individual ligado (parent_id) a este OKR de sucursal, con SUS propios KR:
     * línea base congelada, meta, real, incremento alcanzado, cumplimiento,
     * semáforo, histórico semanal y evidencias. Solo lectura de lo ya evaluado
     * por OkrSnapshotService — nunca recalcula metas ni bases aquí.
     */
    private function gestorTracking(OkrObjective $objective, OkrProgressCalculator $calc): array
    {
        if ($objective->scope_type !== OkrObjective::SCOPE_BRANCH || $objective->children->isEmpty()) {
            return [];
        }

        $employeeResolver = app(OkrEmployeeBranchResolver::class);
        $placementResolver = app(\App\Services\Okr\OkrWeeklyPlacementResolver::class);
        $started = $objective->children->filter(fn ($c) => $c->hasStarted());
        $weeklyByChild = [];
        foreach ($started->groupBy(fn ($c) => $c->currentWeekNumber()) as $week => $group) {
            $weeklyByChild += $placementResolver->resolveMany($group, (int) $week);
        }

        $activeMap = $employeeResolver->activeMap($objective->children->pluck('employee_id')->filter()->all());
        $positions = app(\App\Services\Okr\OkrPersonnelResolver::class)->positionsFor($objective->children->pluck('employee_id')->filter()->values()->all());
        $activation = app(OkrActivationService::class);

        return $objective->children->map(function (OkrObjective $child) use ($calc, $activeMap, $weeklyByChild, $positions, $activation) {
            $krs = $child->keyResults->map(fn ($kr) => [
                'id' => $kr->id,
                'description' => $kr->description,
                'kpi' => $kr->kpi->only(['id', 'name', 'unit', 'type', 'direction', 'automation', 'provider_key']),
                'baseline_value' => $kr->baseline_value,
                'baseline_source' => $kr->baseline_source,
                'baseline_locked' => $kr->baseline_locked_at !== null,
                'target_value' => $kr->target_value,
                'increment_target' => $kr->baseline_value !== null ? round((float) $kr->target_value - (float) $kr->baseline_value, 2) : null,
                'current_value' => $kr->current_value,
                'increment_achieved' => $kr->current_value !== null && $kr->baseline_value !== null ? round((float) $kr->current_value - (float) $kr->baseline_value, 2) : null,
                'expected_value' => $kr->expected_value,
                'projected_value' => $kr->projected_value,
                'weight' => $kr->weight,
                'compliance' => $kr->actual_progress_percentage,
                'expected_compliance' => $kr->expected_progress_percentage,
                'health_status' => $kr->health_status,
                'history' => $kr->snapshots->map(fn ($s) => [
                    'week_number' => $s->week_number, 'actual_value' => $s->actual_value, 'expected_value' => $s->expected_value,
                    'compliance' => $s->actual_progress_percentage, 'source_quality' => $s->source_quality,
                ])->values(),
                'weekly_placement' => $kr->kpi->provider_key === 'reporteria.placement' ? ($weeklyByChild[$child->id]['weeks'] ?? []) : [],
            ])->values();

            $compliance = $krs->isEmpty() ? null : $calc->objectiveCompliance($krs->map(fn ($kr) => ['raw_progress' => $kr['compliance'], 'weight' => (float) $kr['weight']])->all());

            return [
                'id' => $child->id,
                'title' => $child->title,
                'employee' => $child->employee?->full_name ?? $child->title,
                'employee_id' => $child->employee_id,
                'employee_active' => $child->employee_id ? ($activeMap[(int) $child->employee_id] ?? false) : false,
                'lifecycle_status' => $child->lifecycle_status,
                'health_status' => $child->health_status,
                'compliance' => $compliance,
                'key_results' => $krs,
                'evidences_count' => $child->evidences->count(),
                'position' => $child->employee_id ? ($positions[$child->employee_id] ?? null) : null,
                // Qué falta para activarlo (solo borradores) — nunca un mensaje genérico.
                'issues' => $child->lifecycle_status === OkrObjective::STATUS_DRAFT ? $activation->readiness($child)['issues'] : [],
                'letter' => $child->commitmentLetter ? ['folio' => $child->commitmentLetter->folio, 'is_signed' => $child->commitmentLetter->isSigned(), 'download_url' => route('okr.commitment-letters.download', $child->commitmentLetter)] : null,
                'warnings_count' => $child->warnings->where('status', 'issued')->count(),
                'url' => route('okr.show', $child),
            ];
        })->values()->all();
    }

    /** Revisión previa a activar (sucursal + cada OKR individual) — sin efectos. */
    public function activationCheck(OkrObjective $objective, OkrActivationService $activation): JsonResponse
    {
        $this->authorize('assign', $objective);

        return response()->json([
            'objective' => $activation->readiness($objective),
            'children' => $objective->scope_type === OkrObjective::SCOPE_BRANCH
                ? $objective->children()->with(['employee', 'branch', 'keyResults.kpi'])->get()->map(fn ($c) => $activation->readiness($c))->values()
                : [],
        ], 200, self::NO_STORE_HEADERS);
    }

    /** Persona evaluada por este OKR (gestor o titular de sucursal) con su puesto vigente. */
    private function subjectPayload(OkrObjective $objective): ?array
    {
        $employee = $objective->scope_type === OkrObjective::SCOPE_EMPLOYEE ? $objective->employee : $objective->accountableEmployee;
        if (!$employee) {
            return null;
        }

        return [
            'id' => $employee->id, 'full_name' => $employee->full_name,
            'position' => app(\App\Services\Okr\OkrPersonnelResolver::class)->profileFor($employee->id)['position'],
            'is_active' => app(OkrEmployeeBranchResolver::class)->employeeIsActive($employee->id),
        ];
    }

    public function activate(Request $request, OkrObjective $objective, OkrActivationService $activation): RedirectResponse
    {
        $this->authorize('assign', $objective);

        $error = $activation->activate($objective, (int) auth()->id());
        if ($error !== null) {
            return back()->withErrors(['activate' => $error]);
        }

        // Corrección 14-oct-2026: al activar el OKR de sucursal se pueden activar
        // en la misma acción sus OKR individuales en borrador — cada uno con SU
        // propia línea base de gestor. Uno incompleto no se activa y se informa.
        $message = 'OKR activado — la línea base quedó congelada.';
        if ($objective->scope_type === OkrObjective::SCOPE_BRANCH && $request->boolean('include_children')) {
            $children = $activation->activateChildren($objective, (int) auth()->id());
            if ($children['activated']) {
                $message .= ' También se activaron ' . count($children['activated']) . ' OKR individual(es).';
            }
            if ($children['failed']) {
                $detail = collect($children['failed'])->map(fn ($reason, $name) => "{$name}: {$reason}")->implode(' | ');

                return redirect()->route('okr.show', $objective)->with('success', $message)
                    ->with('warning', "Estos OKR individuales siguen en borrador: {$detail}");
            }
        }

        return redirect()->route('okr.show', $objective)->with('success', $message);
    }

    public function refresh(OkrObjective $objective, OkrSnapshotService $snapshotService): RedirectResponse
    {
        $this->authorize('update', $objective);
        $snapshotService->evaluateObjective($objective);
        $backfilled = $snapshotService->backfillMissingWeeks($objective);

        return back()->with('success', $backfilled
            ? "Progreso recalculado desde Reportería — se rellenaron {$backfilled} semana(s) sin snapshot."
            : 'Progreso recalculado desde Reportería.');
    }

    public function updateGoal(Request $request, OkrObjective $objective, OkrWeightValidator $weightValidator, OkrAuditLogger $logger): RedirectResponse
    {
        $this->authorize('update', $objective);
        abort_if($objective->isReadOnly(), 422, 'Este OKR está cerrado/cancelado — es de solo lectura.');
        $request->validate([
            'key_result_id' => ['required', 'integer', 'exists:okr_key_results,id'],
            'target_value'  => ['nullable', 'numeric'],
            'weight'        => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'reason'        => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $kr = $objective->keyResults()->findOrFail($request->integer('key_result_id'));

        // BUG CORREGIDO 08-sep-2026: crear/activar ya validaban 100%, pero
        // cambiar el peso de un KR activo (updateGoal) no volvía a validar el
        // total — podía dejar la suma en 120%, 85%, etc. Ahora se calcula la
        // suma PROYECTADA (los demás pesos + el nuevo) ANTES de guardar nada;
        // si no da exactamente 100%, se rechaza completo (nada se guarda).
        if ($request->filled('weight') && (float) $request->input('weight') !== (float) $kr->weight) {
            $othersTotal = round((float) $objective->keyResults()->where('id', '!=', $kr->id)->sum('weight'), 2);
            $projectedTotal = round($othersTotal + (float) $request->input('weight'), 2);

            if (abs($projectedTotal - 100.0) > 0.01) {
                return back()->withErrors([
                    'weight' => "La ponderación total debe mantenerse en 100%. Con este cambio quedaría en {$projectedTotal}%.",
                ]);
            }
        }

        DB::transaction(function () use ($request, $kr, $logger) {
            if ($request->filled('target_value') && (float) $request->input('target_value') !== (float) $kr->target_value) {
                $logger->logFieldChange('key_result', $kr->id, auth()->id(), 'target_value', $kr->target_value, $request->input('target_value'), $request->input('reason'));
                $kr->target_value = $request->input('target_value');
            }
            if ($request->filled('weight') && (float) $request->input('weight') !== (float) $kr->weight) {
                $logger->logFieldChange('key_result', $kr->id, auth()->id(), 'weight', $kr->weight, $request->input('weight'), $request->input('reason'));
                $kr->weight = $request->input('weight');
            }
            $kr->save();
        });

        return back()->with('success', 'Meta actualizada — cambio registrado en la bitácora.');
    }

    /**
     * Redistribución de pesos EN BLOQUE (punto 2 de la auditoría 09-sep-2026)
     * — bug de diseño corregido: antes solo se podía cambiar UN KR a la vez y
     * la validación de 100% exacto (updateGoal()) hacía IMPOSIBLE mover peso
     * de un KR a otro en dos pasos (60/40 → 70/30: el primer paso solo, por sí
     * mismo, ya rompía el 100%). Aquí se reciben TODOS los pesos nuevos juntos,
     * se valida una sola vez, y se guardan todos en una sola transacción.
     */
    public function updateWeights(Request $request, OkrObjective $objective, OkrAuditLogger $logger): RedirectResponse
    {
        $this->authorize('update', $objective);
        abort_if($objective->isReadOnly(), 422, 'Este OKR está cerrado/cancelado — es de solo lectura.');

        $data = $request->validate([
            'weights'                  => ['required', 'array', 'min:1'],
            'weights.*.key_result_id'  => ['required', 'integer'],
            'weights.*.weight'         => ['required', 'numeric', 'min:0.01', 'max:100'],
            'reason'                   => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $krs = $objective->keyResults()->get()->keyBy('id');
        $payload = collect($data['weights'])->keyBy('key_result_id');

        // Debe cubrir EXACTAMENTE el mismo conjunto de KR del Objective — una
        // redistribución parcial dejaría pesos "huérfanos" sin nueva cifra,
        // rompiendo la coherencia del total.
        if ($payload->keys()->sort()->values()->all() !== $krs->keys()->sort()->values()->all()) {
            return back()->withErrors(['weights' => 'Debes indicar el nuevo peso de TODOS los Key Results del Objective, ninguno puede quedar fuera.']);
        }

        $total = round($payload->sum('weight'), 2);
        if (abs($total - 100.0) > 0.01) {
            return back()->withErrors(['weights' => "La suma de los pesos debe ser exactamente 100% (actual: {$total}%)."]);
        }

        DB::transaction(function () use ($krs, $payload, $data, $logger) {
            foreach ($payload as $krId => $row) {
                $kr = $krs[$krId];
                $newWeight = (float) $row['weight'];
                if ($newWeight !== (float) $kr->weight) {
                    $logger->logFieldChange('key_result', $kr->id, auth()->id(), 'weight', $kr->weight, $newWeight, $data['reason']);
                    $kr->update(['weight' => $newWeight]);
                }
            }
        });

        return back()->with('success', 'Ponderación redistribuida — cambios registrados en la bitácora.');
    }

    public function destroy(OkrObjective $objective): RedirectResponse
    {
        $this->authorize('delete', $objective);
        $objective->update(['lifecycle_status' => OkrObjective::STATUS_CANCELLED]);
        $objective->delete(); // soft delete — conserva histórico

        return redirect()->route('okr.dashboard')->with('success', 'OKR cancelado.');
    }
}
