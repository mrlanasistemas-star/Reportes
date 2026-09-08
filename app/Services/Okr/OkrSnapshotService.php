<?php

namespace App\Services\Okr;

use App\Models\OkrKeyResult;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\OkrProgressSnapshot;
use App\Models\Period;

/**
 * Módulo OKR (08-sep-2026, corregido el mismo día) — evalúa un Key Result:
 * pide el valor real a OkrKpiValueResolver (nunca calcula financiero por su
 * cuenta), calcula trayectoria/desviación/proyección/semáforo, actualiza el
 * CACHE del KR y guarda el snapshot semanal IDEMPOTENTE (mismo KR + misma
 * semana = update, nunca duplicado — ver UNIQUE en la migración).
 *
 * Resolución del "periodo de referencia": BUG CRÍTICO CORREGIDO — antes se
 * usaba siempre "el último periodo mensual con radiografía generada" para
 * CUALQUIER semana del OKR, lo que hacía que un OKR de varias semanas (ej.
 * 15-ago → 10-oct) comparara TODAS sus semanas contra el mismo mes. Ahora
 * cada semana resuelve SU PROPIO periodo real vía OkrTrackingPeriodResolver
 * (según la fecha calendario de esa semana), nunca "el último generado" a
 * ciegas — ver docs/OKR.md.
 */
class OkrSnapshotService
{
    public function __construct(
        private readonly OkrKpiValueResolver $resolver,
        private readonly OkrTrajectoryService $trajectory,
        private readonly OkrProjectionService $projection,
        private readonly OkrProgressCalculator $calculator,
        private readonly OkrHealthService $health,
        private readonly OkrTrackingPeriodResolver $periodResolver,
    ) {
    }

    public function evaluateKeyResult(OkrKeyResult $kr, ?Period $period = null): OkrKeyResult
    {
        $objective  = $kr->objective;
        $kpi        = $kr->kpi;
        $weekNumber = $objective->currentWeekNumber();
        // El periodo se resuelve para ESTA semana concreta (según su fecha
        // calendario), nunca "el último mensual generado" sin relación con la
        // semana que se está evaluando — ver OkrTrackingPeriodResolver.
        $period   ??= $this->periodResolver->getPeriodForObjectiveWeek($objective->start_date, $weekNumber);

        $currentValue = $kpi->isAutomatic() && $period
            ? $this->resolver->getValue($kpi, $objective->scope_type, $objective->branch_id, $objective->employee_id, $period)
            : $kr->current_value; // manual — el check-in ya lo capturó, nunca se sobreescribe aquí

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
        // Todos los KR de un mismo Objective comparten la misma semana actual —
        // se resuelve una sola vez el periodo de ESA semana (evita N consultas
        // idénticas), nunca "el último mensual generado" sin relación con ella.
        $period = $this->periodResolver->getPeriodForObjectiveWeek($objective->start_date, $objective->currentWeekNumber());
        foreach ($objective->keyResults()->get() as $kr) {
            $this->evaluateKeyResult($kr, $period);
        }

        $healths = $objective->keyResults()->pluck('health_status')->all();
        $objective->update(['health_status' => $this->health->worstOf($healths)]);

        return $objective->fresh();
    }
}
