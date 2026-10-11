<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Services\Api\ApiScopeResolver;
use App\Services\Api\FinancialMetricRegistry;
use App\Services\Api\FinancialMetricResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fase 1.3 — indicadores financieros generales/por sucursal/por colaborador.
 * Todos los endpoints requieren 'period_id' explícito: Fase 1.5 prohíbe
 * mezclar indicadores de cortes distintos sin informarlo, así que la API
 * nunca adivina "el periodo más reciente".
 */
class FinancialController extends Controller
{
    private const GENERAL_METRICS = [
        'ebitda', 'ebitda_margin', 'opex', 'placement', 'recovery',
        'portfolio_total', 'portfolio_overdue', 'portfolio_current', 'mora',
        'turnover', 'collections_effectiveness_legacy',
    ];

    public function summary(Request $request, FinancialMetricResolver $resolver, ApiScopeResolver $scopeResolver): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $period   = $this->resolvePeriod($request);
        $scope    = $scopeResolver->resolve($request, $consumer);

        $metrics = collect(self::GENERAL_METRICS)
            ->map(fn (string $code) => $resolver->resolve($code, $period, $scope['type'], $scope['branch_id'], $scope['employee_id']))
            ->values();

        return response()->json(['period_id' => $period->id, 'scope' => $scope, 'metrics' => $metrics]);
    }

    public function branchSummary(Request $request, int $branchId, FinancialMetricResolver $resolver, ApiScopeResolver $scopeResolver): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $scopeResolver->assertBranchAllowed($consumer, $branchId);
        $period = $this->resolvePeriod($request);

        $metrics = collect(self::GENERAL_METRICS)
            ->map(fn (string $code) => $resolver->resolve($code, $period, 'branch', $branchId, null))
            ->values();

        return response()->json(['period_id' => $period->id, 'branch_id' => $branchId, 'metrics' => $metrics]);
    }

    public function employeeSummary(Request $request, int $employeeId, FinancialMetricResolver $resolver): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $employee = Employee::query()->findOrFail($employeeId);
        $branchId = EmployeeBranchAssignment::query()->where('employee_id', $employeeId)->latest('id')->value('branch_id');

        if ($consumer->isRestrictedToBranches() && !$consumer->canAccessBranch($branchId)) {
            throw new HttpException(403, 'Este consumidor no está autorizado para el colaborador solicitado.');
        }

        $period = $this->resolvePeriod($request);

        $metrics = collect(['ebitda', 'ebitda_margin', 'opex', 'placement', 'recovery', 'portfolio_total', 'portfolio_overdue', 'portfolio_current', 'mora', 'collections_effectiveness_legacy'])
            ->map(fn (string $code) => $resolver->resolve($code, $period, 'employee', null, $employee->id))
            ->values();

        return response()->json(['period_id' => $period->id, 'employee_id' => $employee->id, 'branch_id' => $branchId, 'metrics' => $metrics]);
    }

    /** GET /api/v1/financial/metrics — catálogo de indicadores y definiciones. */
    public function metrics(): JsonResponse
    {
        $definitions = collect(FinancialMetricRegistry::definitions())
            ->map(fn (array $def, string $code) => [
                'code'      => $code,
                'label'     => $def['label'],
                'unit'      => $def['unit'],
                'direction' => $def['direction'],
                'scopes'    => $def['scopes'],
                'source'    => $def['source'],
                'definition' => $def['definition'] ?? null,
                'definition_version' => FinancialMetricRegistry::DEFINITION_VERSION,
            ])
            ->values();

        return response()->json(['data' => $definitions]);
    }

    /** GET /api/v1/financial/metric-values — valores filtrados por periodo y alcance, explícitos. */
    public function metricValues(Request $request, FinancialMetricResolver $resolver, ApiScopeResolver $scopeResolver): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $period   = $this->resolvePeriod($request);
        $scope    = $scopeResolver->resolve($request, $consumer);

        $requested = array_filter(explode(',', (string) $request->query('metrics', '')));
        if (empty($requested)) {
            throw new HttpException(422, "El parámetro 'metrics' es obligatorio (lista separada por comas de codes del catálogo /financial/metrics).");
        }

        $unknown = array_values(array_diff($requested, array_keys(FinancialMetricRegistry::definitions())));
        if (!empty($unknown)) {
            throw new HttpException(422, 'Métricas desconocidas: ' . implode(', ', $unknown));
        }

        $metrics = collect($requested)
            ->map(fn (string $code) => $resolver->resolve($code, $period, $scope['type'], $scope['branch_id'], $scope['employee_id']))
            ->values();

        return response()->json(['period_id' => $period->id, 'scope' => $scope, 'metrics' => $metrics]);
    }

    private function resolvePeriod(Request $request): Period
    {
        $periodId = $request->query('period_id');
        if (!$periodId) {
            throw new HttpException(422, "El parámetro 'period_id' es obligatorio.");
        }

        return Period::query()->findOrFail((int) $periodId);
    }
}
