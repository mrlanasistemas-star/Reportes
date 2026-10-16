<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Models\Employee;
use App\Models\OkrObjective;
use App\Services\Api\EmployeeBranchHistoryResolver;
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
    /**
     * CORRECCIÓN seguridad por sucursal (igual que employeeSummary): la
     * autorización es por la sucursal EFECTIVA del objetivo —su propia
     * `branch_id` o la de su Objective padre si es individual— NUNCA por la
     * asignación histórica del colaborador. Antes se admitía un objetivo vía
     * `employee_id ∈ (empleados que ALGUNA VEZ estuvieron en una sucursal
     * autorizada)`, lo que filtraba objetivos cuya sucursal efectiva pertenece
     * a otra sucursal no autorizada (p. ej. tras una transferencia). El
     * pre-filtro SQL solo acota el volumen; el conteo final se filtra en PHP
     * por `canAccessBranch(sucursal efectiva)`, que es la única fuente de verdad.
     */
    public function summary(Request $request): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();

        if ($consumer->hasNoBranchAccess()) {
            return response()->json([
                'active_count' => 0, 'at_risk_count' => 0,
                'branches_with_okr' => 0, 'employees_with_okr' => 0,
            ]);
        }

        $query = OkrObjective::query()
            ->where('lifecycle_status', OkrObjective::STATUS_ACTIVE)
            ->with('parent:id,branch_id');

        if ($consumer->isRestrictedToBranches()) {
            // Pre-filtro amplio (solo para no traer toda la tabla): objetivos cuya
            // sucursal propia o la del padre está autorizada. La autorización real
            // se aplica abajo con canAccessBranch sobre la sucursal EFECTIVA.
            $query->where(function ($q) use ($consumer) {
                $q->whereIn('branch_id', $consumer->allowed_branch_ids)
                    ->orWhereHas('parent', fn ($p) => $p->whereIn('branch_id', $consumer->allowed_branch_ids));
            });
        }

        $active = $query->get(['id', 'parent_id', 'branch_id', 'employee_id', 'health_status'])
            ->filter(fn (OkrObjective $o) => $consumer->canAccessBranch($o->branch_id ?? $o->parent?->branch_id))
            ->values();

        return response()->json([
            'active_count'      => $active->count(),
            'at_risk_count'     => $active->whereIn('health_status', [OkrObjective::HEALTH_RISK, OkrObjective::HEALTH_OFF_TRACK])->count(),
            'branches_with_okr' => $active->pluck('branch_id')->filter()->unique()->count(),
            'employees_with_okr' => $active->pluck('employee_id')->filter()->unique()->count(),
        ]);
    }

    /**
     * Fase 2.6 — "no debe ser posible consultar objetivos de otra sucursal
     * simplemente porque el trabajador perteneció a ella en otro momento".
     * El filtro es POR OBJETIVO (su propia sucursal, o la de su Objective
     * padre si es individual — nunca la asignación "actual" del colaborador
     * como proxy de TODOS sus objetivos). Si existen objetivos pero ninguno
     * es visible para este consumidor, se responde 403 (sin acceso real a
     * este colaborador); si el colaborador simplemente no tiene objetivos,
     * se responde 200 con lista vacía — ausencia de datos, no de permiso.
     */
    public function employeeSummary(Request $request, int $employeeId, OkrProgressCalculator $calculator, EmployeeBranchHistoryResolver $branchHistory): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $employee = Employee::query()->findOrFail($employeeId);

        $allObjectives = OkrObjective::query()
            ->where('employee_id', $employeeId)
            ->with(['keyResults', 'parent'])
            ->orderByDesc('id')
            ->get();

        $visibleObjectives = $allObjectives->filter(
            fn (OkrObjective $objective) => $consumer->canAccessBranch($objective->branch_id ?? $objective->parent?->branch_id)
        )->values();

        if ($allObjectives->isNotEmpty() && $visibleObjectives->isEmpty()) {
            throw new HttpException(403, 'Este consumidor no está autorizado para el colaborador solicitado.');
        }

        return response()->json([
            'employee_id' => $employeeId,
            'objectives'  => $visibleObjectives->map(fn (OkrObjective $objective) => [
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
}
