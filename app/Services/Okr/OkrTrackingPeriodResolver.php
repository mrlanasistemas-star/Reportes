<?php

namespace App\Services\Okr;

use App\Models\Period;
use App\Models\PeriodSummary;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Módulo OKR — CORRECCIÓN 08-sep-2026 (bug crítico "periodo de seguimiento"):
 * antes OkrSnapshotService::resolveTrackingPeriod() usaba SIEMPRE el último
 * periodo mensual con radiografía generada, sin importar qué semana del OKR
 * se estuviera evaluando. Un OKR de varias semanas (ej. 15-ago → 10-oct)
 * terminaba con TODAS sus semanas comparadas contra el mismo mes, incluso
 * cuando la semana evaluada cae en un mes distinto al más reciente.
 *
 * Este resolver es la ÚNICA fuente para "¿qué Period financiero corresponde
 * a esta fecha/semana?" — nunca hardcodea IDs, nunca inventa un calendario
 * propio. La granularidad REAL de radiografía en este sistema es MENSUAL
 * (Period::canReceiveUploads() solo es true para type=monthly — las semanas
 * son metadata organizativa, ver Period::isBase()); por eso "el periodo que
 * contiene la fecha" siempre resuelve a un Period type=monthly con
 * PeriodSummary status=generated, nunca a un Period semanal suelto.
 */
class OkrTrackingPeriodResolver
{
    /**
     * Periodo mensual GENERADO cuyo rango [start_date, end_date] contiene $date.
     * Si el mes calendario de esa fecha todavía no cierra su radiografía (ej. la
     * semana en curso del mes actual), cae al último mes generado ANTERIOR o
     * igual a esa fecha — el dato real más cercano disponible, nunca uno futuro
     * ni "el más reciente que exista" sin relación con la fecha pedida.
     */
    public function getPeriodForDate(CarbonInterface|\DateTimeInterface|string $date): ?Period
    {
        $dateStr = Carbon::parse($date)->toDateString();

        $periodId = $this->generatedMonthlyQuery()
            ->where('periods.start_date', '<=', $dateStr)
            ->where('periods.end_date', '>=', $dateStr)
            ->orderByDesc('periods.id')
            ->value('periods.id');

        if ($periodId) {
            return Period::find($periodId);
        }

        $periodId = $this->generatedMonthlyQuery()
            ->where('periods.start_date', '<=', $dateStr)
            ->orderByDesc('periods.id')
            ->value('periods.id');

        return $periodId ? Period::find($periodId) : null;
    }

    /**
     * Periodo real para la semana N (1-based) de un Objective — resuelto según
     * SU fecha calendario (fin de esa semana), nunca "el último mensual
     * generado" sin relación con qué semana se está evaluando.
     */
    public function getPeriodForObjectiveWeek(CarbonInterface|\DateTimeInterface|string $objectiveStartDate, int $weekNumber): ?Period
    {
        $weekNumber  = max(1, $weekNumber);
        $weekEndDate = Carbon::parse($objectiveStartDate)->addDays(($weekNumber * 7) - 1);

        return $this->getPeriodForDate($weekEndDate);
    }

    /**
     * Último periodo mensual con radiografía generada, sin relación a ninguna
     * fecha — únicamente para contextos que genuinamente no tienen una fecha
     * propia (ej. catálogo). NUNCA usar esto para evaluar el progreso de una
     * semana concreta de un Objective (ese es exactamente el bug corregido).
     */
    public function latestGenerated(): ?Period
    {
        $periodId = $this->generatedMonthlyQuery()->orderByDesc('periods.id')->value('periods.id');

        return $periodId ? Period::find($periodId) : null;
    }

    private function generatedMonthlyQuery()
    {
        return PeriodSummary::query()
            ->where('status', 'generated')
            ->whereNull('invalidated_at')
            ->join('periods', 'period_summaries.period_id', '=', 'periods.id')
            ->where('periods.type', 'monthly');
    }
}
