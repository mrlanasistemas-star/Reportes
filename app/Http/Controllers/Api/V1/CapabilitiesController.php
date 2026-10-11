<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Services\Api\FinancialMetricRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/capabilities — Fase 2.8: separa tres cosas que antes se
 * mezclaban en un solo booleano "disponible":
 *
 *   implemented — ¿el sistema tiene este recurso construido, sin importar
 *                 quién pregunta? (verdad del sistema, igual para todos)
 *   permitted   — ¿ESTE token/consumidor puede usarlo HOY? (implemented AND
 *                 tiene la ability correspondiente AND está activo)
 *   blocked     — implemented=false con una razón explícita (nunca "false"
 *                 sin contexto — ver docs/efectividad-cobranza-aprobada.md)
 */
class CapabilitiesController extends Controller
{
    private const RESOURCE_ABILITIES = [
        'branches' => 'branches.read',
        'employees' => 'employees.read',
        'periods' => 'periods.read',
        'financial_summary' => 'financial.read',
        'financial_metrics_catalog' => 'financial.read',
        'collections_effectiveness_legacy' => 'collections.read',
        'collections_effectiveness_approved' => 'collections.read',
        'okr_summary' => 'okr.read',
    ];

    private const BLOCKED_RESOURCES = [
        'collections_effectiveness_approved' => 'Falta la fuente VBA (.xlsm) para extraer la lógica aprobada — ver docs/efectividad-cobranza-aprobada.md. No se expone ningún valor hasta validarlo contra el Excel aprobado real.',
    ];

    public function index(Request $request): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();

        $tokenAbilities = $consumer->currentAccessToken()?->abilities ?? [];
        $hasWildcard = in_array('*', $tokenAbilities, true);

        $resources = [];
        foreach (self::RESOURCE_ABILITIES as $resource => $ability) {
            $implemented = !array_key_exists($resource, self::BLOCKED_RESOURCES);
            $consumerHasAbility = $consumer->is_active
                && $consumer->hasAbility($ability)
                && ($hasWildcard || in_array($ability, $tokenAbilities, true));

            $resources[$resource] = [
                'implemented' => $implemented,
                'permitted'   => $implemented && $consumerHasAbility,
                'blocked_reason' => self::BLOCKED_RESOURCES[$resource] ?? null,
            ];
        }

        return response()->json([
            'api_version'         => 'v1',
            'definition_version'  => FinancialMetricRegistry::DEFINITION_VERSION,
            'consumer'            => [
                'system_code' => $consumer->system_code,
                'is_active' => $consumer->is_active,
                'branch_access_mode' => $consumer->branch_access_mode,
                'allowed_branch_ids' => $consumer->allowed_branch_ids,
                'abilities' => $consumer->allowedAbilities(),
            ],
            'resources' => $resources,
            'metrics_catalog' => array_keys(FinancialMetricRegistry::definitions()),
        ]);
    }
}
