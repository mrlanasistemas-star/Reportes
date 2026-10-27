<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Models\EfectividadCobranzaRun;
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
        'collections_effectiveness_export' => 'collections.export',
        'okr_summary' => 'okr.read',
        // Exportación de KPIs por periodo (2026-10-27). Un recurso puede exigir
        // VARIAS abilities (todas deben cumplirse, igual que ensure.ability).
        'period_employees_kpis' => ['employees.read', 'financial.read'],
        'employee_periods_kpis' => ['employees.read', 'financial.read'],
        'period_branches_kpis' => 'financial.read',
        'period_export' => 'financial.read',
    ];

    /** Endpoints de cada recurso de exportación por periodo (informativo, aditivo). */
    private const RESOURCE_ENDPOINTS = [
        'period_employees_kpis' => ['GET /api/v1/periods/{id}/employees'],
        'employee_periods_kpis' => ['GET /api/v1/employees/{id}/periods'],
        'period_branches_kpis' => ['GET /api/v1/periods/{id}/branches'],
        'period_export' => ['GET /api/v1/periods/{id}/export', 'GET /api/v1/periods/export'],
    ];

    /**
     * Fase 11 — "collections_effectiveness_approved" quedó CONECTADO a datos
     * reales (fact_portfolios + fact_recoveries → EfectividadCobranzaAprobadaCalculator,
     * el mismo motor ya probado contra el VBA). `implemented` ahora es true: el
     * sistema SÍ tiene el recurso construido. La disponibilidad real para un
     * period_id/scope específico (puede no haber Excel importado todavía, o
     * faltar Fecha transacción/Fecha cuota en el Excel real) se expone en
     * `versions.approved.available` de la respuesta del endpoint en sí —
     * nunca aquí, que es verdad-del-sistema, no verdad-de-este-periodo.
     */
    private const BLOCKED_RESOURCES = [];

    public function index(Request $request): JsonResponse
    {
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();

        $tokenAbilities = $consumer->currentAccessToken()?->abilities ?? [];
        $hasWildcard = in_array('*', $tokenAbilities, true);

        $resources = [];
        foreach (self::RESOURCE_ABILITIES as $resource => $abilities) {
            $implemented = ! array_key_exists($resource, self::BLOCKED_RESOURCES);
            $consumerHasAbility = $consumer->is_active;
            foreach ((array) $abilities as $ability) {
                $consumerHasAbility = $consumerHasAbility
                    && $consumer->hasAbility($ability)
                    && ($hasWildcard || in_array($ability, $tokenAbilities, true));
            }

            $resources[$resource] = [
                'implemented' => $implemented,
                'permitted' => $implemented && $consumerHasAbility,
                'blocked_reason' => self::BLOCKED_RESOURCES[$resource] ?? null,
            ];
            if (isset(self::RESOURCE_ENDPOINTS[$resource])) {
                $resources[$resource]['required_abilities'] = (array) $abilities;
                $resources[$resource]['endpoints'] = self::RESOURCE_ENDPOINTS[$resource];
            }
            if ($resource === 'period_export') {
                // El bloque de colaboradores del export exige además employees.read.
                $resources[$resource]['employees_block_requires'] = 'employees.read';
            }
            if ($resource === 'branches') {
                // Bandera ADITIVA: este contrato devuelve la atribución a una de
                // las 11 sucursales de OKR (sucursal/region/active en /branches,
                // branch/sucursal/region en /employees, sucursal_id/sucursal_status
                // en financial-summary). Un consumidor viejo simplemente la ignora.
                $resources[$resource]['sucursal_attribution'] = true;
            }
            if ($resource === 'collections_effectiveness_approved') {
                // Versión del motor que respalda `versions.approved` (nunca la calculadora anterior).
                $resources[$resource]['engine_version'] = EfectividadCobranzaRun::currentEngineVersion();
                // Metodología financiera vigente (p. ej. V7), independiente de la versión
                // técnica del motor: un snapshot con metodología anterior no se sirve como vigente.
                $resources[$resource]['methodology'] = EfectividadCobranzaRun::currentMethodology();
                $resources[$resource]['period_type'] = 'weekly';
            }
        }

        return response()->json([
            'api_version' => 'v1',
            'definition_version' => FinancialMetricRegistry::DEFINITION_VERSION,
            // Banderas de contrato ADITIVAS — detección de capacidades nuevas sin
            // romper a consumidores viejos ni mover `api_version`/`definition_version`.
            // Auditoría debe comprobar `features.route_branch_mapping === true` antes
            // de confiar en los campos de atribución a sucursal; si falta (contrato
            // anterior), degrada tratando la sucursal como desconocida.
            'features' => [
                'route_branch_mapping' => true,
                'sucursal_attribution' => true,
                // Exportación de KPIs por periodo: colaboradores (con sucursal y
                // rutas), sucursales, corporativo y consolidado; rango de periodos y CSV.
                'period_kpi_export' => true,
                'csv_format' => true,
            ],
            'consumer' => [
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
