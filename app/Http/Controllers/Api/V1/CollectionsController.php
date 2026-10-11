<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Models\Period;
use App\Services\Api\ApiScopeResolver;
use App\Services\Api\FinancialMetricResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * GET /api/v1/collections/effectiveness — Fase 1.3 exige "distinguiendo
 * versiones". La calculadora "Aprobada" (Fase 2) está BLOQUEADA: falta el
 * .xlsm fuente para extraer el VBA (ver docs/efectividad-cobranza-aprobada.md)
 * — nunca se inventa su valor. Se expone únicamente la versión histórica YA
 * EXISTENTE (sections.efectividad_cobranza), marcada explícitamente como tal.
 */
class CollectionsController extends Controller
{
    public function effectiveness(Request $request, FinancialMetricResolver $resolver, ApiScopeResolver $scopeResolver): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();

        $periodId = $request->query('period_id');
        if (!$periodId) {
            throw new HttpException(422, "El parámetro 'period_id' es obligatorio.");
        }
        $period = Period::query()->findOrFail((int) $periodId);
        $scope  = $scopeResolver->resolve($request, $consumer);

        $legacy = $resolver->resolve('collections_effectiveness_legacy', $period, $scope['type'], $scope['branch_id'], $scope['employee_id']);

        return response()->json([
            'period_id' => $period->id,
            'scope'     => $scope,
            'versions'  => [
                'legacy' => $legacy + ['version' => 'legacy_vigente_atrasado_vencido'],
                'approved' => [
                    'available' => false,
                    'status'    => 'blocked',
                    'reason'    => 'Fuente VBA (CALCULADORA DE EFECTIVIDAD DE COBRANZA APROBADO.xlsm) no disponible en el repositorio — ver docs/efectividad-cobranza-aprobada.md.',
                    'version'   => 'approved_pending_validation',
                ],
            ],
        ]);
    }
}
