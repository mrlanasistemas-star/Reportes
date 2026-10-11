<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Period;
use App\Models\PeriodSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PeriodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), 100);

        $query = Period::query()->orderByDesc('start_date');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        $periods = $query->paginate($perPage);
        $summaries = PeriodSummary::query()
            ->whereIn('period_id', $periods->getCollection()->pluck('id'))
            ->get(['period_id', 'status', 'updated_at'])
            ->keyBy('period_id');

        return response()->json([
            'data' => $periods->getCollection()->map(fn (Period $period) => [
                'id'         => $period->id,
                'code'       => $period->code,
                'type'       => $period->type,
                'label'      => $period->label,
                'start_date' => optional($period->start_date)->toDateString(),
                'end_date'   => optional($period->end_date)->toDateString(),
                'is_closed'  => $period->is_closed,
                'radiography_status' => $summaries->get($period->id)?->status ?? 'missing',
                'calculated_at'       => optional($summaries->get($period->id)?->updated_at)->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $periods->currentPage(),
                'per_page'     => $periods->perPage(),
                'total'        => $periods->total(),
                'last_page'    => $periods->lastPage(),
            ],
        ]);
    }

    public function show(int $periodId): JsonResponse
    {
        $period  = Period::query()->findOrFail($periodId);
        $summary = PeriodSummary::query()->where('period_id', $period->id)->first(['status', 'updated_at', 'invalidated_at']);

        return response()->json([
            'id'         => $period->id,
            'code'       => $period->code,
            'type'       => $period->type,
            'label'      => $period->label,
            'start_date' => optional($period->start_date)->toDateString(),
            'end_date'   => optional($period->end_date)->toDateString(),
            'is_closed'  => $period->is_closed,
            'radiography_status'      => $summary?->status ?? 'missing',
            'radiography_invalidated' => (bool) $summary?->invalidated_at,
            'calculated_at'           => optional($summary?->updated_at)->toIso8601String(),
        ]);
    }
}
