<?php

namespace App\Services\Okr;

use Illuminate\Support\Carbon;

/**
 * Módulo OKR — CORRECCIÓN 09-sep-2026 (punto 6 — off-by-one de end_date):
 * fuente ÚNICA para toda la aritmética de calendario del OKR. Antes
 * `ObjectiveController::store()` calculaba `end_date` con
 * `start_date->addWeeks($n)`, que da un día MÁS que el fin real de la semana
 * N (semana N termina en start + N*7-1 días, inclusive). Ejemplo: inicio
 * 01/09, 8 semanas → debe terminar 26/10 (no 27/10, que ya sería el primer
 * día de la semana 9). Todo el módulo (creación, semana actual, resolución
 * de periodo por semana, semanas restantes) usa esta MISMA fórmula — nunca
 * fórmulas paralelas que puedan desalinearse.
 */
class OkrCalendarService
{
    /** Último día (inclusive) de un OKR de $durationWeeks semanas que inicia en $start. */
    public function endDate(Carbon|string $start, int $durationWeeks): Carbon
    {
        return Carbon::parse($start)->startOfDay()->addDays(max(1, $durationWeeks) * 7 - 1);
    }

    /** Primer día (inclusive) de la semana N (1-based). */
    public function weekStart(Carbon|string $start, int $weekNumber): Carbon
    {
        $weekNumber = max(1, $weekNumber);

        return Carbon::parse($start)->startOfDay()->addDays(($weekNumber - 1) * 7);
    }

    /** Último día (inclusive) de la semana N (1-based). */
    public function weekEnd(Carbon|string $start, int $weekNumber): Carbon
    {
        $weekNumber = max(1, $weekNumber);

        return Carbon::parse($start)->startOfDay()->addDays($weekNumber * 7 - 1);
    }

    /**
     * Semana actual (1-based) contando desde start_date — 0 si $asOf es
     * anterior al inicio (el OKR todavía no arrancó — NUNCA se evalúa ni se
     * guarda snapshot para semana 0, ver OkrSnapshotService).
     */
    public function currentWeekNumber(Carbon|string $start, int $durationWeeks, Carbon|string|null $asOf = null): int
    {
        $start = Carbon::parse($start)->startOfDay();
        $asOf  = $asOf ? Carbon::parse($asOf)->startOfDay() : now()->startOfDay();

        if ($asOf->lt($start)) {
            return 0;
        }

        $elapsedDays = $start->diffInDays($asOf);
        $week = (int) floor($elapsedDays / 7) + 1;

        return min($week, max(1, $durationWeeks));
    }

    public function remainingWeeks(int $currentWeek, int $durationWeeks): int
    {
        return max(0, $durationWeeks - $currentWeek);
    }
}
