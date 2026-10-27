<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Models\Branch;
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
        $perPage = ApiValidation::perPage($request);
        $branchId = ApiValidation::nullableId($request, 'branch_id');

        $query = Employee::query()->where('is_active', true);

        if ($branchId !== null) {
            if (! $consumer->canAccessBranch($branchId)) {
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

        // Branches (con su sucursal) de TODA la página en una sola consulta —
        // nunca una por empleado. El `branch`/`sucursal`/`region` son ADITIVOS:
        // la sucursal sale de la relación REAL del branch (sucursal_id), jamás
        // se infiere del nombre del colaborador.
        $branchesById = Branch::query()
            ->with('sucursal')
            ->whereIn('id', $branchByEmployeeId->values()->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        return response()->json([
            'data' => $employees->getCollection()->map(function (Employee $employee) use ($branchByEmployeeId, $branchesById) {
                $branchId = $branchByEmployeeId->get($employee->id);
                $attribution = $this->branchAttribution($branchId === null ? null : $branchesById->get($branchId));

                return [
                    'id' => $employee->id,
                    'employee_code' => $employee->employee_code,
                    'full_name' => $employee->full_name,
                    'position' => $employee->position,
                    'is_active' => $employee->is_active,
                    'branch_id' => $branchId,
                    'branch' => $attribution['branch'],
                    'sucursal' => $attribution['sucursal'],
                    'region' => $attribution['region'],
                ];
            })->values(),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'per_page' => $employees->perPage(),
                'total' => $employees->total(),
                'last_page' => $employees->lastPage(),
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

        if (! $consumer->canAccessBranch($branchId)) {
            throw new HttpException(403, 'Este consumidor no está autorizado para el colaborador solicitado.');
        }

        $branch = $branchId === null ? null : Branch::query()->with('sucursal')->find($branchId);
        $attribution = $this->branchAttribution($branch);

        return response()->json([
            'id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'position' => $employee->position,
            'is_active' => $employee->is_active,
            'branch_id' => $branchId,
            'branch' => $attribution['branch'],
            'sucursal' => $attribution['sucursal'],
            'region' => $attribution['region'],
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
        $period = Period::query()->findOrFail($periodId);

        $scopeResolver->assertEmployeeAllowedForPeriod($consumer, $employeeId, $period);
        $resolvedBranch = $branchHistory->resolveForPeriod($employeeId, $period);

        // Atribución a una de las 11 sucursales de OKR para ESE periodo (histórica,
        // consistente con la foto-del-periodo: un cambio de ruta dentro de la misma
        // sucursal conserva el mismo sucursal_id). ADITIVO: sucursal_id sale de la
        // relación real del branch del periodo (branches.sucursal_id), nunca del
        // nombre. unknown cuando el branch del periodo es null o no mapea a las 11.
        $sucursalId = $resolvedBranch['branch_id'] === null
            ? null
            : Branch::query()->whereKey($resolvedBranch['branch_id'])->value('sucursal_id');

        $metrics = collect(self::EMPLOYEE_METRICS)
            ->map(fn (string $code) => $resolver->resolve($code, $period, 'employee', null, $employee->id))
            ->values();

        return response()->json([
            'employee_id' => $employee->id,
            'period_id' => $period->id,
            // Ruta/sucursal-branch registrada para ESE periodo (foto histórica).
            'branch_id' => $resolvedBranch['branch_id'],
            'branch_status' => $resolvedBranch['status'],
            'sucursal_id' => $sucursalId === null ? null : (int) $sucursalId,
            'sucursal_status' => $sucursalId === null ? 'unknown' : 'known',
            'metrics' => $metrics,
        ]);
    }

    /**
     * Bloque ADITIVO de atribución para un branch (o null): la referencia de
     * branch {id, code, name} y su sucursal institucional {id, codigo, nombre}
     * + región, tomadas de la relación REAL del branch (sucursal_id), nunca del
     * nombre del colaborador. Branch null o no atribuible → todo null (honesto).
     *
     * @return array{branch: array{id:int, code:?string, name:string}|null, sucursal: array{id:int, codigo:string, nombre:string}|null, region: string|null}
     */
    private function branchAttribution(?Branch $branch): array
    {
        if ($branch === null) {
            return ['branch' => null, 'sucursal' => null, 'region' => null];
        }

        $summary = $branch->attributionSummary();

        return [
            'branch' => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name],
            'sucursal' => $summary['sucursal'],
            'region' => $summary['region'],
        ];
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
