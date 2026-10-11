<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Services\Api\FinancialMetricResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $perPage  = min((int) $request->query('per_page', 50), 100);

        $query = Employee::query()->where('is_active', true);

        if ($branchId = $request->query('branch_id')) {
            $branchId = (int) $branchId;
            if (!$consumer->canAccessBranch($branchId)) {
                throw new HttpException(403, 'Este consumidor no está autorizado para la sucursal solicitada.');
            }
            $employeeIds = EmployeeBranchAssignment::query()->where('branch_id', $branchId)->pluck('employee_id');
            $query->whereIn('id', $employeeIds);
        } elseif ($consumer->isRestrictedToBranches()) {
            $employeeIds = EmployeeBranchAssignment::query()
                ->whereIn('branch_id', $consumer->allowed_branch_ids)
                ->pluck('employee_id');
            $query->whereIn('id', $employeeIds);
        }

        $employees = $query->orderBy('full_name')->paginate($perPage);

        return response()->json([
            'data' => $employees->getCollection()->map(fn (Employee $employee) => [
                'id'            => $employee->id,
                'employee_code' => $employee->employee_code,
                'full_name'     => $employee->full_name,
                'position'      => $employee->position,
                'branch_id'     => $this->resolveBranchId($employee->id),
            ])->values(),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'per_page'     => $employees->perPage(),
                'total'        => $employees->total(),
                'last_page'    => $employees->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, int $employeeId): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $employee = Employee::query()->findOrFail($employeeId);
        $branchId = $this->resolveBranchId($employee->id);

        if ($consumer->isRestrictedToBranches() && !$consumer->canAccessBranch($branchId)) {
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
     */
    public function financialSummary(Request $request, int $employeeId, FinancialMetricResolver $resolver): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $employee = Employee::query()->findOrFail($employeeId);
        $branchId = $this->resolveBranchId($employee->id);

        if ($consumer->isRestrictedToBranches() && !$consumer->canAccessBranch($branchId)) {
            throw new HttpException(403, 'Este consumidor no está autorizado para el colaborador solicitado.');
        }

        $periodId = $request->query('period_id');
        if (!$periodId) {
            throw new HttpException(422, "El parámetro 'period_id' es obligatorio.");
        }

        $period = Period::query()->findOrFail((int) $periodId);

        $metrics = collect(self::EMPLOYEE_METRICS)
            ->map(fn (string $code) => $resolver->resolve($code, $period, 'employee', null, $employee->id))
            ->values();

        return response()->json([
            'employee_id' => $employee->id,
            'branch_id'   => $branchId,
            'metrics'     => $metrics,
        ]);
    }

    private function resolveBranchId(int $employeeId): ?int
    {
        // Última asignación conocida (sin period_id: visión agregada histórica más
        // reciente) — mismo criterio usado por EmployeeController web para mostrar
        // "sucursal actual" de un colaborador.
        return EmployeeBranchAssignment::query()
            ->where('employee_id', $employeeId)
            ->latest('id')
            ->value('branch_id');
    }
}
