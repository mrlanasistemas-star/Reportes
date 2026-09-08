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
use App\Services\Okr\OkrAuditLogger;
use App\Services\Okr\OkrKpiValueResolver;
use App\Services\Okr\OkrProgressCalculator;
use App\Services\Okr\OkrSnapshotService;
use App\Services\Okr\OkrWeightValidator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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

    public function create(): Response
    {
        $this->authorize('okr.create');

        return Inertia::render('Okr/Wizard', [
            'branches'  => Branch::whereIn('name', $this->operativeBranchNames())->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::query()->where('is_active', true)->orderBy('full_name')->get(['id', 'full_name']),
            'users'     => User::query()->orderBy('name')->get(['id', 'name']),
            'kpis'      => OkrKpi::query()->where('is_active', true)->orderBy('name')->get(),
            'objectives' => OkrObjective::query()->where('scope_type', OkrObjective::SCOPE_BRANCH)->where('lifecycle_status', '!=', OkrObjective::STATUS_CANCELLED)->get(['id', 'title', 'branch_id']),
        ]);
    }

    public function store(StoreObjectiveRequest $request, OkrWeightValidator $weightValidator, OkrAuditLogger $logger): RedirectResponse
    {
        $data = $request->validated();

        $totalWeight = round(array_sum(array_column($data['key_results'], 'weight')), 2);
        if (abs($totalWeight - 100.0) > 0.01) {
            return back()->withErrors(['key_results' => "La ponderación de los Key Results debe sumar 100% (actual: {$totalWeight}%)."])->withInput();
        }

        $employeeId = $data['scope_type'] === OkrObjective::SCOPE_EMPLOYEE ? $data['employee_id'] : null;
        $branchId   = $data['branch_id'] ?? null;

        $objective = DB::transaction(function () use ($data, $employeeId, $branchId, $logger) {
            $startDate = \Carbon\Carbon::parse($data['start_date']);
            $objective = OkrObjective::query()->create([
                'parent_id'            => $data['parent_id'] ?? null,
                'scope_type'           => $data['scope_type'],
                'branch_id'            => $branchId,
                'employee_id'          => $employeeId,
                'title'                => $data['title'],
                'responsible_user_id'  => $data['responsible_user_id'] ?? auth()->id(),
                'created_by'           => auth()->id(),
                'start_date'           => $startDate,
                'end_date'             => $startDate->copy()->addWeeks((int) $data['duration_weeks']),
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

            return $objective;
        });

        return redirect()->route('okr.show', $objective)->with('success', 'Objective creado en borrador. Revisa la línea base antes de activar.');
    }

    public function show(OkrObjective $objective): Response
    {
        $this->authorize('okr.view');

        $objective->load(['branch', 'employee', 'responsibleUser', 'creator', 'parent', 'children.branch', 'children.employee',
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
            'snapshots' => $kr->snapshots->map(fn ($s) => [
                'week_number' => $s->week_number, 'actual_value' => $s->actual_value, 'expected_value' => $s->expected_value,
                'actual_progress_percentage' => $s->actual_progress_percentage, 'expected_progress_percentage' => $s->expected_progress_percentage,
                'deviation_pp' => $s->deviation_pp,
            ]),
        ]);

        $weightSummary = app(OkrWeightValidator::class)->summary($objective);
        $compliance = $calc->objectiveCompliance($krPayload->map(fn ($kr) => ['raw_progress' => $kr['actual_progress_percentage'], 'weight' => (float) $kr['weight']])->all());

        return Inertia::render('Okr/Show', [
            'objective' => [
                'id' => $objective->id, 'title' => $objective->title, 'scope_type' => $objective->scope_type,
                'branch' => $objective->branch?->only(['id', 'name']), 'employee' => $objective->employee?->only(['id', 'full_name']),
                'responsible' => $objective->responsibleUser?->only(['id', 'name']), 'creator' => $objective->creator?->only(['id', 'name']),
                'parent' => $objective->parent?->only(['id', 'title']),
                'children' => $objective->children->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'employee' => $c->employee?->full_name]),
                'start_date' => $objective->start_date->toDateString(), 'end_date' => $objective->end_date->toDateString(),
                'duration_weeks' => $objective->duration_weeks, 'current_week' => $objective->currentWeekNumber(),
                'lifecycle_status' => $objective->lifecycle_status, 'health_status' => $objective->health_status,
                'final_status' => $objective->final_status,
                'compliance' => $compliance, 'weight_summary' => $weightSummary,
            ],
            'keyResults' => $krPayload,
            'checkIns' => $objective->checkIns->map(fn ($c) => ['id' => $c->id, 'week_number' => $c->week_number, 'check_in_date' => $c->check_in_date->toDateString(), 'user' => $c->user->name, 'main_blocker' => $c->main_blocker, 'corrective_action' => $c->corrective_action]),
            'correctiveActions' => $objective->correctiveActions->map(fn ($a) => ['id' => $a->id, 'description' => $a->description, 'responsible' => $a->responsibleUser->name, 'due_date' => $a->due_date->toDateString(), 'status' => $a->status, 'is_overdue' => $a->isOverdue()]),
            'evidences' => $objective->evidences->map(fn ($e) => ['id' => $e->id, 'original_name' => $e->original_name, 'uploader' => $e->uploader->name, 'week_number' => $e->week_number, 'created_at' => $e->created_at->toDateTimeString(), 'comment' => $e->comment]),
            'alerts' => $objective->alerts->map(fn ($a) => ['id' => $a->id, 'type' => $a->type, 'message' => $a->message, 'created_at' => $a->created_at->toDateTimeString()]),
        ]);
    }

    public function activate(OkrObjective $objective, OkrWeightValidator $weightValidator, OkrKpiValueResolver $resolver, OkrSnapshotService $snapshotService, OkrAuditLogger $logger): RedirectResponse
    {
        $this->authorize('okr.assign');

        if ($objective->lifecycle_status !== OkrObjective::STATUS_DRAFT) {
            return back()->withErrors(['activate' => 'Solo un Objective en borrador puede activarse.']);
        }
        if (!$weightValidator->isValid($objective)) {
            $summary = $weightValidator->summary($objective);

            return back()->withErrors(['activate' => "La ponderación debe sumar 100% (actual: {$summary['total']}%)."]);
        }

        $period = $snapshotService->resolveTrackingPeriod();

        DB::transaction(function () use ($objective, $period, $resolver, $logger) {
            foreach ($objective->keyResults()->get() as $kr) {
                if ($kr->baseline_value === null && $kr->kpi->isAutomatic() && $period) {
                    $baseline = $resolver->getValue($kr->kpi, $objective->scope_type, $objective->branch_id, $objective->employee_id, $period);
                    if ($baseline !== null) {
                        $kr->baseline_value = $baseline;
                    }
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
        $this->authorize('okr.update');
        $snapshotService->evaluateObjective($objective);

        return back()->with('success', 'Progreso recalculado desde Reportería.');
    }

    public function updateGoal(Request $request, OkrObjective $objective, OkrAuditLogger $logger): RedirectResponse
    {
        $this->authorize('okr.update');
        $request->validate([
            'key_result_id' => ['required', 'integer', 'exists:okr_key_results,id'],
            'target_value'  => ['nullable', 'numeric'],
            'weight'        => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'reason'        => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $kr = $objective->keyResults()->findOrFail($request->integer('key_result_id'));

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

    public function destroy(OkrObjective $objective): RedirectResponse
    {
        $this->authorize('okr.delete');
        $objective->update(['lifecycle_status' => OkrObjective::STATUS_CANCELLED]);
        $objective->delete(); // soft delete — conserva histórico

        return redirect()->route('okr.dashboard')->with('success', 'OKR cancelado.');
    }
}
