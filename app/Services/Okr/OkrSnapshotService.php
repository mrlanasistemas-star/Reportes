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
        private readonly OkrCalendarService $calendar,
    ) {
    }

    public function evaluateKeyResult(OkrKeyResult $kr, ?Period $period = null, ?int $checkInId = null): OkrKeyResult
    {
        $objective  = $kr->objective;
        $kpi        = $kr->kpi;
        $weekNumber = $objective->currentWeekNumber();

        // BUG CORREGIDO 09-sep-2026 (punto 5): el Objective todavía no inicia
        // (currentWeekNumber()===0) — NUNCA se guarda un snapshot "semana 0".
        // Se deja el KR sin tocar (sin cache ni snapshot) hasta que arranque.
        if ($weekNumber < 1) {
            return $kr;
        }

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

        $weekEndDate = $this->calendar->weekEnd($objective->start_date, $weekNumber);
        $sourceMeta  = $this->resolveSourceMetadata($kpi, $period, $weekEndDate, $checkInId);

        OkrProgressSnapshot::query()->updateOrCreate(
            ['okr_key_result_id' => $kr->id, 'week_number' => $weekNumber],
            array_merge([
                'snapshot_date'                    => now()->toDateString(),
                'actual_value'                      => $currentValue,
                'expected_value'                    => $expectedValue,
                'actual_progress_percentage'         => $actualProgress,
                'expected_progress_percentage'       => $expectedProgress,
                'deviation_pp'                       => $deviation,
                'projected_value'                    => $projectedValue,
                'projected_compliance_percentage'    => $projectedCompliance,
                'health_status'                      => $healthStatus,
                'calculated_at'                      => now(),
            ], $sourceMeta),
        );

        return $kr->fresh();
    }

    /**
     * Metadata de trazabilidad de la fuente (punto 4 de la auditoría 09-sep-2026)
     * — NUNCA finge granularidad semanal real que no existe: este sistema solo
     * genera radiografía a nivel MENSUAL (ver Period::canReceiveUploads()), así
     * que un KPI automático siempre queda 'monthly' — 'monthly_proxy' si el mes
     * resuelto realmente cubre la fecha de esa semana, 'last_available' si tuvo
     * que caer a un mes anterior (ver OkrTrackingPeriodResolver).
     *
     * @return array{source_reference:?string, source_period_id:?int, source_period_code:?string, source_date:?string, source_granularity:?string, source_quality:?string}
     */
    private function resolveSourceMetadata(OkrKpi $kpi, ?Period $period, \Illuminate\Support\Carbon $weekEndDate, ?int $checkInId): array
    {
        if (!$kpi->isAutomatic()) {
            return [
                'source_reference'   => $checkInId ? "manual_checkin:{$checkInId}" : 'manual',
                'source_period_id'   => null,
                'source_period_code' => null,
                'source_date'        => $weekEndDate->toDateString(),
                'source_granularity' => 'manual',
                'source_quality'     => OkrProgressSnapshot::QUALITY_MANUAL_CHECKIN,
            ];
        }

        if (!$period) {
            return [
                'source_reference' => null, 'source_period_id' => null, 'source_period_code' => null,
                'source_date' => null, 'source_granularity' => null, 'source_quality' => null,
            ];
        }

        $coversWeek = $period->start_date && $period->end_date
            && $period->start_date->lte($weekEndDate) && $period->end_date->gte($weekEndDate);

        return [
            'source_reference'   => "period:{$period->id}",
            'source_period_id'   => $period->id,
            'source_period_code' => $period->code,
            'source_date'        => $period->end_date?->toDateString(),
            'source_granularity' => 'monthly',
            'source_quality'     => $coversWeek ? OkrProgressSnapshot::QUALITY_MONTHLY_PROXY : OkrProgressSnapshot::QUALITY_LAST_AVAILABLE,
        ];
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

    /**
     * Punto 4 de la auditoría 09-sep-2026 — "hueco histórico" cuando el cron
     * no corrió una semana: recorre 1..semanaActual y crea el snapshot que
     * falte, SIN duplicar los que ya existen (idempotente vía exists-check) y
     * SIN tocar el cache en vivo del KR (current_value/health_status, que
     * debe seguir reflejando SOLO la semana actual — nunca una semana pasada
     * rellenada).
     *
     * Solo aplica a KPI automático: un KPI manual sin captura real esa semana
     * no tiene ningún valor legítimo que "resolver" — inventarlo violaría
     * "nunca fingir un dato que no existe". Esas semanas simplemente quedan
     * sin snapshot hasta que alguien capture un check-in retroactivo (fuera
     * de alcance de este backfill).
     *
     * @return int Número de snapshots creados.
     */
    public function backfillMissingWeeks(OkrObjective $objective): int
    {
        $currentWeek = $objective->currentWeekNumber();
        if ($currentWeek < 1) {
            return 0; // el Objective todavía no inicia — nada que rellenar
        }

        $created = 0;
        foreach ($objective->keyResults()->with('kpi')->get() as $kr) {
            if (!$kr->kpi->isAutomatic()) {
                continue;
            }

            $existingWeeks = $kr->snapshots()->pluck('week_number')->map(fn ($w) => (int) $w)->all();

            for ($week = 1; $week <= $currentWeek; $week++) {
                if (in_array($week, $existingWeeks, true)) {
                    continue; // ya existe — nunca se duplica ni se pisa
                }

                $this->writeBackfilledSnapshot($kr, $objective, $week);
                $created++;
            }
        }

        return $created;
    }

    private function writeBackfilledSnapshot(OkrKeyResult $kr, OkrObjective $objective, int $weekNumber): void
    {
        $kpi    = $kr->kpi;
        $period = $this->periodResolver->getPeriodForObjectiveWeek($objective->start_date, $weekNumber);

        $currentValue = $period
            ? $this->resolver->getValue($kpi, $objective->scope_type, $objective->branch_id, $objective->employee_id, $period)
            : null;

        $totalWeeks = (int) $objective->duration_weeks;
        $baseline   = $kr->baseline_value !== null ? (float) $kr->baseline_value : null;
        $target     = (float) $kr->target_value;

        $expectedValue    = $baseline !== null ? $this->trajectory->expectedValue($baseline, $target, $weekNumber, $totalWeeks) : null;
        $expectedProgress = $this->trajectory->expectedProgressPercentage($weekNumber, $totalWeeks);
        $actualProgress   = $this->calculator->rawProgress($baseline, $target, $currentValue, $kpi->direction);
        $deviation        = $this->calculator->deviationPp($actualProgress, $expectedProgress);
        $healthStatus     = $this->health->classify($deviation);

        $weekEndDate = $this->calendar->weekEnd($objective->start_date, $weekNumber);
        $sourceMeta  = $this->resolveSourceMetadata($kpi, $period, $weekEndDate, null);

        OkrProgressSnapshot::query()->create(array_merge([
            'okr_key_result_id'                 => $kr->id,
            'week_number'                       => $weekNumber,
            'snapshot_date'                      => $weekEndDate->toDateString(),
            'actual_value'                       => $currentValue,
            'expected_value'                     => $expectedValue,
            'actual_progress_percentage'         => $actualProgress,
            'expected_progress_percentage'       => $expectedProgress,
            'deviation_pp'                       => $deviation,
            'projected_value'                    => null, // proyección solo tiene sentido "hoy", no retroactiva
            'projected_compliance_percentage'    => null,
            'health_status'                      => $healthStatus,
            'calculated_at'                      => now(),
        ], $sourceMeta));
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
