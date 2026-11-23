<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\Api\ApiValidation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/periods y /api/v1/periods/{id} — catálogo de periodos de
 * reporte (semanas, meses, etc.) para consumidores externos de la API.
 *
 * Además de los datos del periodo expone el estado de la radiografía
 * calculada (tabla period_summaries) para que el consumidor sepa si ya hay
 * números listos para consultar.
 */
class PeriodController extends Controller
{
    /** Tipos de periodo aceptados por el filtro `type`. */
    private const PERIOD_TYPES = ['weekly', 'monthly', 'bimonthly', 'quarterly', 'semiannual', 'annual'];

    /**
     * Lista paginada de periodos, del más reciente al más antiguo (start_date desc).
     *
     * Solo lectura. Agrega a cada periodo el estado de su radiografía
     * (`missing` si nunca se calculó) y la fecha del último cálculo.
     *
     * @param  Request  $request  `per_page` (paginación) y `type` opcional (uno de PERIOD_TYPES; valor inválido → error de validación de ApiValidation).
     * @return JsonResponse {data: [periodo...], meta: paginación}.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = ApiValidation::perPage($request);
        $type = ApiValidation::enumValue($request, 'type', self::PERIOD_TYPES);

        $query = Period::query()->orderByDesc('start_date');

        if ($type) {
            $query->where('type', $type);
        }

        $periods = $query->paginate($perPage);
        $summaries = PeriodSummary::query()
            ->whereIn('period_id', $periods->getCollection()->pluck('id'))
            ->get(['period_id', 'status', 'updated_at'])
            ->keyBy('period_id');

        return response()->json([
            'data' => $periods->getCollection()->map(fn (Period $period) => [
                'id' => $period->id,
                'code' => $period->code,
                'type' => $period->type,
                'label' => $period->label,
                // Aditivo (23-nov-2026): nombre manual (null si no hay) y el del sistema.
                'custom_name' => $period->custom_name,
                'system_label' => $period->systemLabel(),
                'start_date' => optional($period->start_date)->toDateString(),
                'end_date' => optional($period->end_date)->toDateString(),
                'is_closed' => $period->is_closed,
                // Aditivo (2026-11-17): semanas que componen un mes (orden del catálogo).
                'component_period_ids' => array_map('intval', $period->component_period_ids ?? []),
                'radiography_status' => $summaries->get($period->id)?->status ?? 'missing',
                'calculated_at' => optional($summaries->get($period->id)?->updated_at)->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $periods->currentPage(),
                'per_page' => $periods->perPage(),
                'total' => $periods->total(),
                'last_page' => $periods->lastPage(),
            ],
        ]);
    }

    /**
     * Detalle de un periodo con el estado de su radiografía.
     *
     * Incluye `radiography_invalidated` (true cuando el resumen fue marcado
     * como desactualizado, p. ej. tras re-importar datos).
     *
     * @param  int  $periodId  ID del periodo.
     * @return JsonResponse Objeto del periodo.
     *
     * @throws ModelNotFoundException Si el periodo no existe (404).
     */
    public function show(int $periodId): JsonResponse
    {
        $period = Period::query()->findOrFail($periodId);
        $summary = PeriodSummary::query()->where('period_id', $period->id)->first(['status', 'updated_at', 'invalidated_at']);

        return response()->json([
            'id' => $period->id,
            'code' => $period->code,
            'type' => $period->type,
            'label' => $period->label,
            'custom_name' => $period->custom_name,
            'system_label' => $period->systemLabel(),
            'start_date' => optional($period->start_date)->toDateString(),
            'end_date' => optional($period->end_date)->toDateString(),
            'is_closed' => $period->is_closed,
            'component_period_ids' => array_map('intval', $period->component_period_ids ?? []),
            'radiography_status' => $summary?->status ?? 'missing',
            'radiography_invalidated' => (bool) $summary?->invalidated_at,
            'calculated_at' => optional($summary?->updated_at)->toIso8601String(),
        ]);
    }
}
