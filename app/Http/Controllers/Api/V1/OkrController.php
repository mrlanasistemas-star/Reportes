<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\OkrObjective;
use App\Services\Okr\OkrProgressCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fase 1 — OKR autorizado. Solo expone lo que ya es válido mostrar fuera del
 * sistema (estado, cumplimiento cacheado) — nunca texto libre de check-ins o
 * contenido legal de cartas/warnings (fuera de alcance de la API general).
 */
class OkrController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();

        $query = OkrObjective::query()->where('lifecycle_status', OkrObjective::STATUS_ACTIVE);
        if ($consumer->isRestrictedToBranches()) {
            $query->where(function ($q) use ($consumer) {
                $q->whereIn('branch_id', $consumer->allowed_branch_ids)
                    ->orWhereIn('employee_id', $this->employeeIdsForAllowedBranches($consumer));
            });
        }

        $active = $query->get(['id', 'branch_id', 'employee_id', 'health_status']);

        return response()->json([
            'active_count'      => $active->count(),
            'at_risk_count'     => $active->whereIn('health_status', [OkrObjective::HEALTH_RISK, OkrObjective::HEALTH_OFF_TRACK])->count(),
            'branches_with_okr' => $active->pluck('branch_id')->filter()->unique()->count(),
            'employees_with_okr' => $active->pluck('employee_id')->filter()->unique()->count(),
        ]);
    }

    public function employeeSummary(Request $request, int $employeeId, OkrProgressCalculator $calculator): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $employee = Employee::query()->findOrFail($employeeId);
        $branchId = EmployeeBranchAssignment::query()->where('employee_id', $employeeId)->latest('id')->value('branch_id');

        if ($consumer->isRestrictedToBranches() && !$consumer->canAccessBranch($branchId)) {
            throw new HttpException(403, 'Este consumidor no está autorizado para el colaborador solicitado.');
        }

        $objectives = OkrObjective::query()
            ->where('employee_id', $employeeId)
            ->with('keyResults')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'employee_id' => $employeeId,
            'objectives'  => $objectives->map(fn (OkrObjective $objective) => [
                'id'               => $objective->id,
                'title'            => $objective->title,
                'lifecycle_status' => $objective->lifecycle_status,
                'health_status'    => $objective->health_status,
                'final_status'     => $objective->final_status,
                'start_date'       => optional($objective->start_date)->toDateString(),
                'end_date'         => optional($objective->end_date)->toDateString(),
                'compliance_percentage' => $calculator->objectiveCompliance(
                    $objective->keyResults->map(fn ($kr) => ['raw_progress' => $kr->actual_progress_percentage, 'weight' => $kr->weight])->all()
                ),
            ])->values(),
        ]);
    }

    private function employeeIdsForAllowedBranches(ApiConsumer $consumer): array
    {
        return EmployeeBranchAssignment::query()
            ->whereIn('branch_id', $consumer->allowed_branch_ids ?? [])
            ->pluck('employee_id')
            ->unique()
            ->values()
            ->all();
    }
}
