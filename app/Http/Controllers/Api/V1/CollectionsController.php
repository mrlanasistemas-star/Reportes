<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Models\Employee;
use App\Models\Period;
use App\Services\Api\ApiScopeResolver;
use App\Services\Api\ApiValidation;
use App\Services\Api\FinancialMetricResolver;
use App\Services\Efectividad\EfectividadCobranzaAprobadaCalculator;
use App\Services\Efectividad\EfectividadCobranzaAprobadaReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/collections/effectiveness — Fase 1.3 exige "distinguiendo
 * versiones". Fase 11 (Efectividad de Cobranza Aprobada): la versión
 * "approved" ahora SÍ está conectada al adaptador real (fact_portfolios +
 * fact_recoveries → EfectividadCobranzaAprobadaCalculator, el mismo motor ya
 * probado contra el VBA) — pero `available` nunca es una bandera global: se
 * calcula en vivo para ESTE period_id/scope exacto, con la misma cobertura
 * honesta (coverage.status) que usa la pantalla web del módulo.
 */
class CollectionsController extends Controller
{
    public function effectiveness(
        Request $request,
        FinancialMetricResolver $resolver,
        ApiScopeResolver $scopeResolver,
        EfectividadCobranzaAprobadaReportService $approvedReportService,
        EfectividadCobranzaAprobadaCalculator $calculator,
    ): JsonResponse {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();

        $periodId = ApiValidation::requiredId($request, 'period_id');
        $period = Period::query()->findOrFail($periodId);
        $scope  = $scopeResolver->resolve($request, $consumer, $period);

        $legacy = $resolver->resolve('collections_effectiveness_legacy', $period, $scope['type'], $scope['branch_id'], $scope['employee_id']);

        return response()->json([
            'period_id' => $period->id,
            'scope'     => $scope,
            'versions'  => [
                'legacy'   => $legacy + ['version' => 'legacy_vigente_atrasado_vencido'],
                'approved' => $this->resolveApproved($period, $scope, $approvedReportService, $calculator),
            ],
        ]);
    }

    /**
     * Nunca una bandera global: cada llamada recalcula en vivo para el
     * period_id/scope exacto solicitado, usando el mismo adaptador/calculador
     * que ya respalda la pantalla web (Fase 7-10) — sin inventar valores ni
     * exponer "disponible" cuando la cobertura real está bloqueada.
     */
    private function resolveApproved(
        Period $period,
        array $scope,
        EfectividadCobranzaAprobadaReportService $approvedReportService,
        EfectividadCobranzaAprobadaCalculator $calculator,
    ): array {
        $result = $approvedReportService->generate($period, $scope['branch_id']);

        if (!$result['is_available']) {
            return [
                'available' => false,
                'status'    => 'blocked',
                'reason'    => $result['coverage']['message'],
                'coverage'  => $result['coverage'],
                'version'   => 'approved_real_adapter_v1',
            ];
        }

        if ($scope['type'] === 'employee') {
            $employee = Employee::query()->find($scope['employee_id']);
            $normalizedTarget = $employee ? $calculator->normalizar($employee->normalized_name ?: $employee->full_name) : '';

            $row = collect($result['rows'])
                ->first(fn (array $r) => empty($r['es_total']) && $calculator->normalizar($r['promotor']) === $normalizedTarget);

            if (!$row) {
                return [
                    'available' => false,
                    'status'    => 'not_attributable',
                    'reason'    => 'El cálculo de Efectividad Aprobada para este periodo/sucursal es válido, pero ninguna fila de promotor coincide con el nombre normalizado de este colaborador.',
                    'coverage'  => $result['coverage'],
                    'version'   => 'approved_real_adapter_v1',
                ];
            }

            return [
                'available' => true,
                'status'    => 'ok',
                'row'       => $row,
                'coverage'  => $result['coverage'],
                'version'   => 'approved_real_adapter_v1',
            ];
        }

        $totalRow = collect($result['rows'])->firstWhere('promotor', 'GRAN TOTAL');

        return [
            'available' => true,
            'status'    => 'ok',
            'summary'   => $totalRow,
            'rows'      => $result['rows'],
            'coverage'  => $result['coverage'],
            'version'   => 'approved_real_adapter_v1',
        ];
    }
}
