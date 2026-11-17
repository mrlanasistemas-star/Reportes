<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiConsumer;
use App\Models\Period;
use App\Services\Api\ApiPeriodRange;
use App\Services\Api\FinancialMetricRegistry;
use App\Services\Api\PeriodWeeklyActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * GET /api/v1/periods/{periodId}/weekly-activity (2026-11-17) — colocación y
 * recuperación de un MES repartidas en sus semanas componentes, por sucursal y por
 * colaborador. Ver PeriodWeeklyActivityService y docs/api-reporteria.md §15.
 *
 * financial.read para entrar; el bloque de colaboradores exige además
 * employees.read (misma doble verificación token+consumidor que PeriodKpiController).
 */
class PeriodWeeklyActivityController extends Controller
{
    public function show(Request $request, int $periodId): JsonResponse
    {
        // Servicio fresco por petición (lleva memo; nunca cruza peticiones).
        $service = app(PeriodWeeklyActivityService::class);
        /** @var ApiConsumer $consumer */
        $consumer = $request->user();
        $period = Period::query()->findOrFail($periodId);
        $include = $this->includes($request);

        if (in_array('employees', $include, true) && (! $consumer->tokenCan('employees.read') || ! $consumer->hasAbility('employees.read'))) {
            throw new HttpException(403, 'El token no tiene el permiso requerido: employees.read (necesario para el bloque de colaboradores; usa include=branches si solo necesitas sucursales).');
        }

        if (! PeriodWeeklyActivityService::supports($period)) {
            throw new HttpException(422, 'La actividad semanal solo existe para un periodo mensual con semanas componentes. En Reportería las semanas son metadata del mes: la radiografía y los datos (fact_*) se cargan con el periodo MENSUAL, y aquí se reparten por la fecha de cada movimiento. Pide el mes que contiene la semana.');
        }

        $result = $service->forPeriod($period);
        $global = $consumer->canAccessGeneralScope();
        $none = $consumer->hasNoBranchAccess();

        $out = [
            'period' => ApiPeriodRange::describe($period) + ['component_period_ids' => array_map('intval', $period->component_period_ids ?? [])],
            'status' => $result['status'],
            'message' => $result['message'],
            'source' => 'reporteria.weekly_activity',
            'definition_version' => FinancialMetricRegistry::DEFINITION_VERSION,
            'calculated_at' => $result['calculated_at'],
            'radiography_version' => $result['radiography_version'],
            'granularity' => [
                'base' => 'weekly',
                'data_tagging' => 'monthly',
                'note' => 'Las semanas pertenecen al mes por component_period_ids. Los movimientos del mes se reparten por fecha (colocación: operation_date; recuperación: payment_date con la regla única del reporte). Los que no caen en ninguna semana van a outside_weeks. Σ semanas + outside_weeks = total del mes.',
            ],
            'weeks' => $result['weeks'],
        ];

        if (in_array('branches', $include, true)) {
            $out['branches'] = [
                'attribution' => PeriodWeeklyActivityService::ATTRIBUTION,
                'data' => $none ? [] : array_values(array_filter(
                    $result['branches'],
                    fn ($b) => $global || $consumer->canAccessBranch($b['branch_id'])
                )),
                'unassigned' => $global ? $result['unassigned'] : null,
            ];
        }

        if (in_array('employees', $include, true)) {
            $out['employees'] = [
                'data' => $none ? [] : array_values(array_filter(
                    $result['employees'],
                    fn ($e) => $global || ($e['assigned_branch_id'] !== null && $consumer->canAccessBranch($e['assigned_branch_id']))
                )),
                // Sin identidad no se pueden autorizar por sucursal: solo alcance global.
                'unidentified' => $global ? $result['unidentified'] : null,
            ];
        }

        $out['reconciliation'] = $global ? $result['reconciliation'] : null;

        return response()->json($out);
    }

    private function includes(Request $request): array
    {
        $raw = trim((string) $request->query('include', 'branches,employees'));
        $parts = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
        $unknown = array_diff($parts, ['branches', 'employees']);
        if ($parts === [] || $unknown !== []) {
            throw new HttpException(422, "El parámetro 'include' admite: branches, employees (separados por coma).");
        }

        return $parts;
    }
}
