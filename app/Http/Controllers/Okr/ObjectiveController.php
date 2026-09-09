<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Okr\Concerns\ResolvesOperativeBranches;
use App\Http\Requests\Okr\StoreObjectiveRequest;
use App\Models\Branch;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\Period;
use App\Models\User;
use App\Services\Okr\OkrAuditLogger;
use App\Services\Okr\OkrCalendarService;
use App\Services\Okr\OkrEmployeeBranchResolver;
use App\Services\Okr\OkrKpiValueResolver;
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

        $branchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;
        $employees = $resolver->employeesForBranch($branchId, $request->string('search')->toString());

        return response()->json([
            'employees' => $employees,
            // Total real de la sucursal (sin el límite de 30 del buscador) —
            // lo usa el resumen del wizard de asignación para mostrar "todos
            // todos" los colaboradores cuando no se activan OKR individuales.
            'total_in_branch' => $branchId !== null ? $resolver->countForBranch($branchId) : null,
        ]);
    }

    /**
     * Búsqueda ligera de Objectives para el filtro "Objective / OKR" de
     * Seguimiento (docs/imagenesOKR/9.png) — permite cambiar de OKR sin volver
     * al Dashboard. Nunca precarga todos los Objectives del sistema.
     */
    public function objectivesLookup(Request $request): JsonResponse
    {
        $this->authorize('okr.view');
        $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'period_id' => ['nullable', 'integer', 'exists:periods,id'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = OkrObjective::query()->whereNull('deleted_at')->where('lifecycle_status', '!=', OkrObjective::STATUS_CANCELLED);
        if ($branchId = $request->integer('branch_id')) {
            $query->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereHas('parent', fn ($p) => $p->where('branch_id', $branchId)));
        }
        if ($employeeId = $request->integer('employee_id')) {
            $query->where('employee_id', $employeeId);
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

        $objectives = $query->orderByDesc('id')->limit(30)->get(['id', 'title']);

        return response()->json(['objectives' => $objectives]);
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
            'period'    => $period ? ['id' => $period->id, 'label' => $period->label] : null,
            'source'    => $kpi->provider_key,
        ]);
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

            foreach ($data['key_results'] as $krData) {
                $objective->keyResults()->create([
                    'kpi_id'          => $krData['kpi_id'],
                    'description'     => $krData['description'],
                    'baseline_value'  => $krData['baseline_value'] ?? null,
                    'target_value'    => $krData['target_value'],
                    'weight'          => $krData['weight'],
                ]);
            }

            $logger->log('objective', $objective->id, auth()->id(), 'created');

            // OKR individuales de la misma asignación (sección 8/9 de la
            // auditoría 10-sep-2026) — jerárquicamente ligados al principal
            // (parent_id), misma sucursal, mismo plazo, EN BORRADOR y SIN Key
            // Results propios (se configuran después — nunca se inventan KRs
            // financieros automáticamente aquí). Si cualquiera falla, toda la
            // transacción (principal + individuales) se revierte.
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
            }

            return $objective;
        });

        return redirect()->route('okr.show', $objective)->with('success', 'Objective creado en borrador. Revisa la línea base antes de activar.');
    }

    public function show(OkrObjective $objective): Response
    {
        $this->authorize('view', $objective);

        $objective->load(['branch', 'employee', 'responsibleUser', 'creator', 'parent', 'children.branch', 'children.employee', 'children.keyResults.kpi',
            'keyResults.kpi', 'keyResults.snapshots' => fn ($q) => $q->orderBy('week_number'),
            'checkIns.user', 'correctiveActions.responsibleUser', 'evidences.uploader', 'alerts' => fn ($q) => $q->whereNull('read_at')->latest()]);

        $calc = app(OkrProgressCalculator::class);
        $krPayload = $objective->keyResults->map(fn ($kr) => [
            'id' => $kr->id, 'description' => $kr->description,
            'kpi' => $kr->kpi->only(['id', 'code', 'name', 'unit', 'type', 'direction', 'automation']),
            'baseline_value' => $kr->baseline_value, 'target_value' => $kr->target_value, 'weight' => $kr->weight,
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
                'deviation_pp' => $s->deviation_pp,
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
                    $childRows[] = [
                        'employee' => $child->employee?->full_name ?? $child->title,
                        'target_value' => (float) $childKr->target_value,
                        'current_value' => $childKr->current_value !== null ? (float) $childKr->current_value : null,
                        'compliance' => $childKr->actual_progress_percentage,
                    ];
                }
                if (empty($childRows)) {
                    continue;
                }
                $sumTarget  = array_sum(array_column($childRows, 'target_value'));
                $sumCurrent = array_sum(array_map(fn ($r) => $r['current_value'] ?? 0, $childRows));
                $contributions[] = [
                    'kpi' => $parentKr->kpi->only(['id', 'name', 'unit']),
                    'branch_target' => (float) $parentKr->target_value,
                    'children_target_sum' => $sumTarget,
                    'children_current_sum' => $sumCurrent,
                    'coverage_percentage' => $sumTarget > 0 ? round(($sumCurrent / $sumTarget) * 100, 1) : null,
                    'gap' => (float) $parentKr->target_value - $sumTarget,
                    'rows' => $childRows,
                ];
            }
        }

        return Inertia::render('Okr/Show', [
            'objective' => [
                'id' => $objective->id, 'title' => $objective->title, 'scope_type' => $objective->scope_type,
                'branch' => $objective->branch?->only(['id', 'name']), 'employee' => $objective->employee?->only(['id', 'full_name']),
                'responsible' => $objective->responsibleUser?->only(['id', 'name']), 'creator' => $objective->creator?->only(['id', 'name']),
                'parent' => $objective->parent?->only(['id', 'title']),
                'children' => $objective->children->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'employee' => $c->employee?->full_name]),
                'contributions' => $contributions,
                'start_date' => $objective->start_date->toDateString(), 'end_date' => $objective->end_date->toDateString(),
                'duration_weeks' => $objective->duration_weeks, 'current_week' => $objective->currentWeekNumber(),
                'lifecycle_status' => $objective->lifecycle_status, 'health_status' => $objective->health_status,
                'final_status' => $objective->final_status,
                'compliance' => $compliance, 'expected_compliance' => $expectedCompliance,
                'projected_compliance' => $projectedCompliance, 'deviation_pp' => $deviation,
                'weight_summary' => $weightSummary,
            ],
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
            'filterBranches' => Branch::whereIn('name', $this->operativeBranchNames())->orderBy('name')->get(['id', 'name']),
            'filterPeriods'  => Period::query()->where('type', 'monthly')->orderByDesc('id')->limit(24)->get()->map(fn ($p) => ['id' => $p->id, 'label' => $p->label]),
            // Para el selector "Responsable" del check-in (acción correctiva).
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function activate(OkrObjective $objective, OkrWeightValidator $weightValidator, OkrKpiValueResolver $resolver, OkrSnapshotService $snapshotService, OkrTrackingPeriodResolver $periodResolver, OkrAuditLogger $logger): RedirectResponse
    {
        $this->authorize('assign', $objective);

        if ($objective->lifecycle_status !== OkrObjective::STATUS_DRAFT) {
            return back()->withErrors(['activate' => 'Solo un Objective en borrador puede activarse.']);
        }
        if (!$weightValidator->isValid($objective)) {
            $summary = $weightValidator->summary($objective);

            return back()->withErrors(['activate' => "La ponderación debe sumar 100% (actual: {$summary['total']}%)."]);
        }

        // La línea base se congela "a hoy" (fecha de activación) — se resuelve el
        // periodo real que cubre la fecha de HOY (OkrTrackingPeriodResolver),
        // nunca "el último generado" sin relación con la fecha de activación.
        $period = $periodResolver->getPeriodForDate(now());

        // BUG CRÍTICO CORREGIDO 08-sep-2026: antes un KR sin línea base
        // disponible se activaba igual con baseline_value=null (y
        // baseline_locked_at/lifecycle_status ACTIVE puestos de todos modos).
        // Ahora, si CUALQUIER KR no puede resolver una línea base válida, la
        // activación completa se rechaza — nunca queda un OKR "a medias".
        $failures = [];
        $resolvedBaselines = [];
        foreach ($objective->keyResults()->with('kpi')->get() as $kr) {
            if ($kr->baseline_value !== null) {
                continue; // ya tiene línea base (manual capturada, o precargada en el wizard)
            }

            if (!$kr->kpi->isAutomatic()) {
                $failures[] = "{$kr->kpi->name} (manual — captura la línea base antes de activar)";
                continue;
            }

            $baseline = $period
                ? $resolver->getValue($kr->kpi, $objective->scope_type, $objective->branch_id, $objective->employee_id, $period)
                : null;

            if ($baseline === null) {
                $failures[] = $kr->kpi->name;
                continue;
            }

            $resolvedBaselines[$kr->id] = $baseline;
        }

        if (!empty($failures)) {
            $list = implode(', ', $failures);

            return back()->withErrors([
                'activate' => "No fue posible obtener la línea base automática del KPI {$list} para este alcance y periodo. "
                    . 'Verifica que Reportería tenga radiografía generada para ese alcance/periodo, o captura la línea base manualmente antes de activar.',
            ]);
        }

        DB::transaction(function () use ($objective, $period, $resolvedBaselines, $logger) {
            foreach ($objective->keyResults()->get() as $kr) {
                if (isset($resolvedBaselines[$kr->id])) {
                    $kr->baseline_value = $resolvedBaselines[$kr->id];
                }
                $kr->baseline_source = $kr->kpi->isAutomatic() ? $kr->kpi->provider_key : 'manual';
                $kr->baseline_period_date = $period?->end_date;
                $kr->baseline_locked_at = now();
                $kr->save();
            }

            $objective->update(['lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now()]);
            $logger->log('objective', $objective->id, auth()->id(), 'activated');
        });

        $snapshotService->evaluateObjective($objective->fresh());

        return redirect()->route('okr.show', $objective)->with('success', 'OKR activado — la línea base quedó congelada.');
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
