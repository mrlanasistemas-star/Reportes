<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Okr\Concerns\ResolvesOperativeBranches;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\OkrObjective;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Módulo OKR (08-sep-2026) — dashboard general (sección 20 del pedido). Cards
 * ejecutivas + listado, con filtros combinados (sucursal/colaborador/estatus/
 * periodo/KPI/responsable/buscador) — sin N+1 (eager load de relaciones).
 */
class DashboardController extends Controller
{
    use ResolvesOperativeBranches, AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('okr.view');

        $query = OkrObjective::query()
            ->with(['branch:id,name', 'employee:id,full_name', 'responsibleUser:id,name', 'keyResults.kpi:id,name,code,unit'])
            ->whereNull('okr_objectives.deleted_at')
            ->where('lifecycle_status', '!=', OkrObjective::STATUS_CLOSED);

        if ($branchId = $request->integer('branch_id')) {
            $query->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereHas('parent', fn ($p) => $p->where('branch_id', $branchId)));
        }
        if ($employeeId = $request->integer('employee_id')) {
            $query->where('employee_id', $employeeId);
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('lifecycle_status', $status);
        }
        if ($search = $request->string('search')->toString()) {
            $query->where('title', 'like', "%{$search}%");
        }

        $objectives = $query->orderByDesc('id')->get();

        $activeCount   = $objectives->where('lifecycle_status', OkrObjective::STATUS_ACTIVE)->count();
        $riskCount     = $objectives->whereIn('health_status', [OkrObjective::HEALTH_RISK, OkrObjective::HEALTH_OFF_TRACK])->count();
        $notMetCount   = OkrObjective::query()->where('final_status', OkrObjective::FINAL_NOT_COMPLETED)->count();
        $avgCompliance = $objectives->isNotEmpty()
            ? round($objectives->avg(fn ($o) => $this->objectiveCompliance($o)), 2)
            : 0.0;
        $branchesWithOkr = $objectives->pluck('branch_id')->filter()->unique()->count();
        $employeesWithOkr = OkrObjective::query()->where('scope_type', OkrObjective::SCOPE_EMPLOYEE)->distinct('employee_id')->count('employee_id');

        return Inertia::render('Okr/Dashboard', [
            'objectives' => $objectives->map(fn ($o) => $this->toCard($o))->values(),
            'cards' => [
                'active'            => $activeCount,
                'risk'              => $riskCount,
                'not_met'           => $notMetCount,
                'avg_compliance'    => $avgCompliance,
                'branches_with_okr' => $branchesWithOkr,
                'employees_with_okr'=> $employeesWithOkr,
                'total_branches'    => count($this->operativeBranchNames()),
                'total_employees'   => Employee::query()->where('is_active', true)->count(),
            ],
            'filters' => [
                'branches'   => Branch::whereIn('name', $this->operativeBranchNames())->orderBy('name')->get(['id', 'name']),
                'employees'  => Employee::query()->where('is_active', true)->orderBy('full_name')->limit(500)->get(['id', 'full_name']),
                'statuses'   => [OkrObjective::STATUS_DRAFT, OkrObjective::STATUS_ACTIVE, OkrObjective::STATUS_CLOSED, OkrObjective::STATUS_CANCELLED],
            ],
            'query' => $request->only(['branch_id', 'employee_id', 'status', 'search']),
        ]);
    }

    private function objectiveCompliance(OkrObjective $objective): float
    {
        $calc = app(\App\Services\Okr\OkrProgressCalculator::class);
        $krs = $objective->keyResults->map(fn ($kr) => ['raw_progress' => $kr->actual_progress_percentage, 'weight' => (float) $kr->weight])->all();

        return $calc->objectiveCompliance($krs);
    }

    private function toCard(OkrObjective $o): array
    {
        return [
            'id'               => $o->id,
            'title'            => $o->title,
            'scope_type'       => $o->scope_type,
            'branch'           => $o->branch?->name,
            'employee'         => $o->employee?->full_name,
            'responsible'      => $o->responsibleUser?->name,
            'lifecycle_status' => $o->lifecycle_status,
            'health_status'    => $o->health_status,
            'start_date'       => $o->start_date?->toDateString(),
            'end_date'         => $o->end_date?->toDateString(),
            'duration_weeks'   => $o->duration_weeks,
            'current_week'     => $o->currentWeekNumber(),
            'compliance'       => $this->objectiveCompliance($o),
            'key_results_count'=> $o->keyResults->count(),
        ];
    }
}
