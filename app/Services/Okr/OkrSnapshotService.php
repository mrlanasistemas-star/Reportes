<?php

namespace App\Services\Okr;

use App\Models\OkrKeyResult;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\OkrProgressSnapshot;
use App\Models\Period;
use App\Models\PeriodSummary;

/**
 * Módulo OKR (08-sep-2026) — evalúa un Key Result: pide el valor real a
 * OkrKpiValueResolver (nunca calcula financiero por su cuenta), calcula
 * trayectoria/desviación/proyección/semáforo, actualiza el CACHE del KR y
 * guarda el snapshot semanal IDEMPOTENTE (mismo KR + misma semana = update,
 * nunca duplicado — ver UNIQUE en la migración).
 *
 * Resolución del "periodo de referencia": OKR no tiene su propio calendario
 * financiero — usa el periodo MENSUAL más reciente con radiografía generada
 * (mismo criterio que MonthlyReportController::previewPage() para "el reporte
 * vigente"), nunca inventa un periodo propio incompatible.
 */
class OkrSnapshotService
{
    public function __construct(
        private readonly OkrKpiValueResolver $resolver,
        private readonly OkrTrajectoryService $trajectory,
        private readonly OkrProjectionService $projection,
        private readonly OkrProgressCalculator $calculator,
        private readonly OkrHealthService $health,
    ) {
    }

    public function resolveTrackingPeriod(): ?Period
    {
        $periodId = PeriodSummary::query()
            ->where('status', 'generated')
            ->whereNull('invalidated_at')
            ->join('periods', 'period_summaries.period_id', '=', 'periods.id')
            ->where('periods.type', 'monthly')
            ->orderByDesc('periods.id')
            ->value('periods.id');

        return $periodId ? Period::find($periodId) : null;
    }

    public function evaluateKeyResult(OkrKeyResult $kr, ?Period $period = null): OkrKeyResult
    {
        $objective = $kr->objective;
        $kpi       = $kr->kpi;
        $period  ??= $this->resolveTrackingPeriod();

        $currentValue = $kpi->isAutomatic() && $period
            ? $this->resolver->getValue($kpi, $objective->scope_type, $objective->branch_id, $objective->employee_id, $period)
            : $kr->current_value; // manual — el check-in ya lo capturó, nunca se sobreescribe aquí

        $weekNumber = $objective->currentWeekNumber();
        $totalWeeks = (int) $objective->duration_weeks;
        $baseline   = $kr->baseline_value !== null ? (float) $kr->baseline_value : null;
        $target     = (float) $kr->target_value;

        $expectedValue    = $baseline !== null ? $this->trajectory->expectedValue($baseline, $target, $weekNumber, $totalWeeks) : null;
        $expectedProgress = $this->trajectory->expectedProgressPercentage($weekNumber, $totalWeeks);
        $actualProgress   = $this->calculator->rawProgress($baseline, $target, $currentValue, $kpi->direction);
        $deviation        = $this->calculator->deviationPp($actualProgress, $expectedProgress);
        $healthStatus     = $this->health->classify($deviation);

        [$projectedValue, $projectedCompliance] = $this->project($kr, $kpi, $currentValue, $baseline, $target, $weekNumber, $totalWeeks);

        $kr->update([
            'current_value'                    => $currentValue,
            'expected_value'                   => $expectedValue,
            'actual_progress_percentage'       => $actualProgress,
            'expected_progress_percentage'     => $expectedProgress,
            'deviation_pp'                     => $deviation,
            'projected_value'                  => $projectedValue,
            'projected_compliance_percentage'  => $projectedCompliance,
            'health_status'                    => $healthStatus,
            'last_evaluated_at'                => now(),
        ]);

        OkrProgressSnapshot::query()->updateOrCreate(
            ['okr_key_result_id' => $kr->id, 'week_number' => $weekNumber],
            [
                'snapshot_date'                    => now()->toDateString(),
                'actual_value'                      => $currentValue,
                'expected_value'                    => $expectedValue,
                'actual_progress_percentage'         => $actualProgress,
                'expected_progress_percentage'       => $expectedProgress,
                'deviation_pp'                       => $deviation,
                'projected_value'                    => $projectedValue,
                'projected_compliance_percentage'    => $projectedCompliance,
                'health_status'                      => $healthStatus,
                'source_reference'                   => $period ? "period:{$period->id}" : null,
                'calculated_at'                      => now(),
            ],
        );

        return $kr->fresh();
    }

    private function project(OkrKeyResult $kr, OkrKpi $kpi, ?float $currentValue, ?float $baseline, float $target, int $weekNumber, int $totalWeeks): array
    {
        if ($currentValue === null) {
            return [null, null];
        }

        $remainingWeeks = max(0, $totalWeeks - $weekNumber);

        if ($kpi->type === OkrKpi::TYPE_CUMULATIVE) {
            $projectedValue = $this->projection->projectCumulative($currentValue, $weekNumber, $totalWeeks);
        } else {
            $history = $kr->snapshots()
                ->orderBy('week_number')
                ->pluck('actual_value')
                ->filter(fn ($v) => $v !== null)
                ->map(fn ($v) => (float) $v)
                ->values()
                ->all();
            $history[] = $currentValue;
            $projectedValue = $this->projection->projectTrend($history, $currentValue, $remainingWeeks);
        }

        $projectedCompliance = $this->projection->projectedCompliancePercentage($projectedValue, $baseline, $target, $kpi->direction, $this->calculator);

        return [$projectedValue, $projectedCompliance];
    }

    public function evaluateObjective(OkrObjective $objective): OkrObjective
    {
        $period = $this->resolveTrackingPeriod();
        foreach ($objective->keyResults()->get() as $kr) {
            $this->evaluateKeyResult($kr, $period);
        }

        $healths = $objective->keyResults()->pluck('health_status')->all();
        $objective->update(['health_status' => $this->health->worstOf($healths)]);

        return $objective->fresh();
    }
}
