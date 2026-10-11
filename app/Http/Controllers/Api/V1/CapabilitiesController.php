<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Services\Api\FinancialMetricRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/v1/capabilities — funciones y versiones disponibles para EL consumidor autenticado. */
class CapabilitiesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();

        return response()->json([
            'api_version'         => 'v1',
            'definition_version'  => FinancialMetricRegistry::DEFINITION_VERSION,
            'consumer'            => [
                'system_code' => $consumer->system_code,
                'restricted_to_branches' => $consumer->isRestrictedToBranches(),
                'allowed_branch_ids' => $consumer->allowed_branch_ids,
                'abilities' => $consumer->currentAccessToken()?->abilities ?? [],
            ],
            'resources' => [
                'branches' => true,
                'employees' => true,
                'periods' => true,
                'financial_summary' => true,
                'financial_metrics_catalog' => true,
                'collections_effectiveness_approved' => false, // bloqueado — ver docs/efectividad-cobranza-aprobada.md
                'collections_effectiveness_legacy' => true,
                'okr_summary' => true,
            ],
            'metrics_catalog' => array_keys(FinancialMetricRegistry::definitions()),
        ]);
    }
}
