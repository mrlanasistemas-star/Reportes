<?php

namespace App\Services\Okr;

use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\OkrProgressSnapshot;
use App\Models\OkrWarning;
use App\Models\User;
use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Parte 3/10 del cierre (04-oct-2026) — Warning Rojo. El sistema NUNCA
 * sanciona solo: detecta desviación y HABILITA el botón; admin/gerencial
 * decide si lo emite (3.1). Basado SIEMPRE en el snapshot de la SEMANA
 * evaluada (OkrProgressSnapshot.week_number) — nunca en current_value "de
 * hoy" (3.3/10 Test 10). Si esa semana no tiene NINGÚN dato real todavía,
 * se rechaza — "NO WARNING SIN DATOS" (10.1): nunca se finge un 0.
 */
class OkrWarningService
{
    public function __construct(
        private readonly OkrCalendarService $calendar,
        private readonly OkrDocumentFolioGenerator $folioGenerator,
        private readonly BrowsershotPdfRenderer $renderer,
    ) {
    }

    /**
     * @return array{rows: array, has_any_data: bool, has_breach: bool}
     */
    public function buildWeekSnapshot(OkrObjective $objective, int $weekNumber): array
    {
        $rows = [];
        $hasAnyData = false;
        $hasBreach = false;

        foreach ($objective->keyResults()->with('kpi')->get() as $kr) {
            $snapshot = OkrProgressSnapshot::query()
                ->where('okr_key_result_id', $kr->id)
                ->where('week_number', $weekNumber)
                ->first();

            $hasData = $snapshot !== null && $snapshot->actual_value !== null;
            if ($hasData) {
                $hasAnyData = true;
            }

            // 14: brecha en unidades/$ — MISMA semántica de dirección que
            // OkrProgressCalculator (nunca "target - actual" a secas, que da
            // signo equivocado en KPI tipo DECREASE como mora/cartera vencida).
            //   INCREASE (colocación, EBITDA): brecha = meta - real (positivo = falta por cubrir).
            //   DECREASE (mora): brecha = real - límite (positivo = se excedió el límite).
            $gap = null;
            if ($hasData && $snapshot->actual_value !== null) {
                $gap = $kr->kpi->direction === OkrKpi::DIRECTION_DECREASE
                    ? (float) $snapshot->actual_value - (float) $kr->target_value
                    : (float) $kr->target_value - (float) $snapshot->actual_value;
            }

            // 12/13: incumplimiento real = desviación negativa (real por debajo de
            // lo esperado) en el deviation_pp CANÓNICO ya calculado por
            // OkrProgressCalculator sobre este snapshot — nunca una segunda
            // semántica inventada aquí.
            $hasRowBreach = $hasData && $snapshot->deviation_pp !== null && (float) $snapshot->deviation_pp < 0;
            if ($hasRowBreach) {
                $hasBreach = true;
            }

            $rows[] = [
                'kpi_name' => $kr->kpi->name,
                'unit' => $kr->kpi->unit,
                'target_value' => (float) $kr->target_value,
                'expected_value' => $hasData ? $snapshot->expected_value : null,
                'actual_value' => $hasData ? $snapshot->actual_value : null,
                'compliance_percentage' => $hasData ? $snapshot->actual_progress_percentage : null,
                'gap' => $gap,
                'has_data' => $hasData,
                'has_breach' => $hasRowBreach,
            ];
        }

        return ['rows' => $rows, 'has_any_data' => $hasAnyData, 'has_breach' => $hasBreach];
    }

    /**
     * @throws RuntimeException si la semana no tiene NINGÚN dato real todavía,
     *   o si tiene datos pero NINGÚN KR muestra incumplimiento real (12/13 —
     *   nunca se permite emitir un Warning "porque sí", ni esquivando el botón
     *   deshabilitado del frontend llamando al endpoint directo).
     */
    public function generate(OkrObjective $objective, int $weekNumber, string $correctiveActions, ?string $observations, User $user): OkrWarning
    {
        if ($weekNumber < 1 || $weekNumber > (int) $objective->duration_weeks) {
            throw new RuntimeException('La semana indicada está fuera del rango del Objective.');
        }

        $weekData = $this->buildWeekSnapshot($objective, $weekNumber);
        if (!$weekData['has_any_data']) {
            throw new RuntimeException("La semana {$weekNumber} todavía no tiene información cargada — no se puede generar un Warning sin datos reales (nunca se finge un incumplimiento).");
        }
        if (!$weekData['has_breach']) {
            throw new RuntimeException('No existe incumplimiento en la semana seleccionada.');
        }

        $objective->loadMissing(['branch', 'employee', 'responsibleUser', 'commitmentLetter']);
        $weekStart = $this->calendar->weekStart($objective->start_date, $weekNumber);
        $weekEnd   = $this->calendar->weekEnd($objective->start_date, $weekNumber);

        $snapshot = [
            'objective_title' => $objective->title,
            'scope_type' => $objective->scope_type,
            'branch_name' => $objective->branch?->name,
            'employee_name' => $objective->employee?->full_name,
            'position' => $objective->employee?->position,
            'responsible_name' => $objective->responsibleUser?->name,
            // 11: referencia a la Carta Compromiso original — solo si ya existe
            // (nunca se finge una fecha/folio de una carta que no se ha emitido).
            'letter_folio' => $objective->commitmentLetter?->folio,
            'letter_date' => $objective->commitmentLetter?->generated_at?->toDateString(),
            'week_number' => $weekNumber,
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'rows' => $weekData['rows'],
        ];

        return DB::transaction(function () use ($objective, $weekNumber, $weekStart, $weekEnd, $snapshot, $correctiveActions, $observations, $user) {
            $warning = OkrWarning::query()->create([
                'folio' => 'PENDIENTE',
                'okr_objective_id' => $objective->id,
                'week_number' => $weekNumber,
                'week_start' => $weekStart, 'week_end' => $weekEnd,
                'snapshot' => $snapshot,
                'corrective_actions' => $correctiveActions,
                'observations' => $observations,
                'generated_by' => $user->id,
                'generated_at' => now(),
                'stored_path' => '', 'disk' => 'local',
            ]);

            $folio = $this->folioGenerator->forId('WR', $warning->id);
            $disk = 'local';
            $path = 'okr-warnings/' . $objective->id . "/{$folio}.pdf";
            $absolutePath = Storage::disk($disk)->path($path);

            $this->renderer->renderViewToFile('reports.okr-warning-pdf', [
                'folio' => $folio, 'snapshot' => $snapshot,
                'correctiveActions' => $correctiveActions, 'observations' => $observations,
                'generatedAt' => $warning->generated_at, 'generatedByName' => $user->name,
            ], $absolutePath, ['full_bleed' => true]);

            $warning->update(['folio' => $folio, 'stored_path' => $path, 'disk' => $disk]);

            return $warning->fresh();
        });
    }
}
