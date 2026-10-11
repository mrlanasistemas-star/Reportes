<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Services\Api\ApiScopeResolver;
use App\Services\Api\ApiValidation;
use App\Services\Api\EmployeeBranchHistoryResolver;
use App\Services\Api\FinancialMetricResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EmployeeController extends Controller
{
    /** Métricas atribuibles a nivel individual que se devuelven en financial-summary. */
    private const EMPLOYEE_METRICS = [
        'ebitda', 'ebitda_margin', 'opex', 'placement', 'recovery',
        'portfolio_total', 'portfolio_overdue', 'portfolio_current', 'mora',
        'collections_effectiveness_legacy',
    ];

    public function index(Request $request): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $perPage  = ApiValidation::perPage($request);
        $branchId = ApiValidation::nullableId($request, 'branch_id');

        $query = Employee::query()->where('is_active', true);

        if ($branchId !== null) {
            if (!$consumer->canAccessBranch($branchId)) {
                throw new HttpException(403, 'Este consumidor no está autorizado para la sucursal solicitada.');
            }
            $employeeIds = EmployeeBranchAssignment::query()->where('branch_id', $branchId)->pluck('employee_id');
            $query->whereIn('id', $employeeIds);
        } elseif ($consumer->hasNoBranchAccess()) {
            // Sin acceso a ninguna sucursal: lista vacía, nunca "sin filtro" (Fase 2.5).
            $query->whereRaw('1 = 0');
        } elseif ($consumer->isRestrictedToBranches()) {
            $employeeIds = EmployeeBranchAssignment::query()
                ->whereIn('branch_id', $consumer->allowed_branch_ids)
                ->pluck('employee_id');
            $query->whereIn('id', $employeeIds);
        }

        $employees = $query->orderBy('full_name')->paginate($perPage);

        // Fase 3.2 — una sola consulta para TODA la página, nunca una por empleado.
        $branchByEmployeeId = $this->batchResolveCurrentBranchIds($employees->getCollection()->pluck('id'));

        return response()->json([
            'data' => $employees->getCollection()->map(fn (Employee $employee) => [
                'id'            => $employee->id,
                'employee_code' => $employee->employee_code,
                'full_name'     => $employee->full_name,
                'position'      => $employee->position,
                'branch_id'     => $branchByEmployeeId->get($employee->id),
            ])->values(),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'per_page'     => $employees->perPage(),
                'total'        => $employees->total(),
                'last_page'    => $employees->lastPage(),
            ],
        ]);
    }

    /**
     * Identificación básica de catálogo — SIN contexto de periodo, usa la
     * sucursal "actual" (más reciente conocida) solo para identificación,
     * nunca como atribución financiera de un periodo pasado (ver Fase 2.3,
     * EmployeeBranchHistoryResolver).
     */
    public function show(Request $request, int $employeeId, EmployeeBranchHistoryResolver $branchHistory): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $employee = Employee::query()->findOrFail($employeeId);
        $branchId = $branchHistory->resolveCurrent($employee->id);

        if (!$consumer->canAccessBranch($branchId)) {
            throw new HttpException(403, 'Este consumidor no está autorizado para el colaborador solicitado.');
        }

        return response()->json([
            'id'            => $employee->id,
            'employee_code' => $employee->employee_code,
            'full_name'     => $employee->full_name,
            'position'      => $employee->position,
            'is_active'     => $employee->is_active,
            'branch_id'     => $branchId,
        ]);
    }

    /**
     * GET /api/v1/employees/{id}/financial-summary — ficha financiera
     * ATRIBUIBLE del colaborador para un periodo. Nunca divide el total de
     * sucursal entre colaboradores (Fase 1.3): cada métrica pasa por
     * FinancialMetricResolver con scope=employee, que devuelve null si no es
     * atribuible individualmente.
     *
     * Fase 2.1 — esta ruta exige AMBAS abilities (employees.read Y
     * financial.read) a nivel de middleware (ver routes/api.php) — tener
     * acceso al catálogo de empleados nunca implica acceso a sus datos
     * financieros.
     *
     * Fase 2.2/2.3 — autorización centralizada contra la sucursal HISTÓRICA
     * del colaborador en el periodo pedido, nunca su asignación más reciente.
     */
    public function financialSummary(Request $request, int $employeeId, FinancialMetricResolver $resolver, ApiScopeResolver $scopeResolver, EmployeeBranchHistoryResolver $branchHistory): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $employee = Employee::query()->findOrFail($employeeId);
        $periodId = ApiValidation::requiredId($request, 'period_id');
        $period   = Period::query()->findOrFail($periodId);

        $scopeResolver->assertEmployeeAllowedForPeriod($consumer, $employeeId, $period);
        $resolvedBranch = $branchHistory->resolveForPeriod($employeeId, $period);

        $metrics = collect(self::EMPLOYEE_METRICS)
            ->map(fn (string $code) => $resolver->resolve($code, $period, 'employee', null, $employee->id))
            ->values();

        return response()->json([
            'employee_id'   => $employee->id,
            'period_id'     => $period->id,
            'branch_id'     => $resolvedBranch['branch_id'],
            'branch_status' => $resolvedBranch['status'],
            'metrics'       => $metrics,
        ]);
    }

    /** @return Collection<int, int|null> keyBy employee_id => branch_id */
    private function batchResolveCurrentBranchIds(Collection $employeeIds): Collection
    {
        if ($employeeIds->isEmpty()) {
            return collect();
        }

        return EmployeeBranchAssignment::query()
            ->whereIn('employee_id', $employeeIds)
            ->orderByDesc('id')
            ->get(['employee_id', 'branch_id'])
            ->unique('employee_id')
            ->pluck('branch_id', 'employee_id');
    }
}
