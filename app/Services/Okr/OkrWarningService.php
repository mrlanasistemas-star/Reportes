<?php

namespace App\Services\Okr;

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
     * @return array{rows: array, has_any_data: bool}
     */
    public function buildWeekSnapshot(OkrObjective $objective, int $weekNumber): array
    {
        $rows = [];
        $hasAnyData = false;

        foreach ($objective->keyResults()->with('kpi')->get() as $kr) {
            $snapshot = OkrProgressSnapshot::query()
                ->where('okr_key_result_id', $kr->id)
                ->where('week_number', $weekNumber)
                ->first();

            $hasData = $snapshot !== null && $snapshot->actual_value !== null;
            if ($hasData) {
                $hasAnyData = true;
            }

            $rows[] = [
                'kpi_name' => $kr->kpi->name,
                'unit' => $kr->kpi->unit,
                'target_value' => (float) $kr->target_value,
                'expected_value' => $hasData ? $snapshot->expected_value : null,
                'actual_value' => $hasData ? $snapshot->actual_value : null,
                'compliance_percentage' => $hasData ? $snapshot->actual_progress_percentage : null,
                // Brecha = cuánto falta para la meta final del Objective, con el
                // resultado REAL de esa semana (nunca el de hoy) — mismo criterio
                // de signo que OkrProgressCalculator (positivo = falta por cubrir).
                'gap' => $hasData && $snapshot->actual_value !== null ? (float) $kr->target_value - (float) $snapshot->actual_value : null,
                'has_data' => $hasData,
            ];
        }

        return ['rows' => $rows, 'has_any_data' => $hasAnyData];
    }

    /**
     * @throws RuntimeException si la semana no tiene NINGÚN dato real todavía.
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

        $objective->loadMissing(['branch', 'employee', 'responsibleUser']);
        $weekStart = $this->calendar->weekStart($objective->start_date, $weekNumber);
        $weekEnd   = $this->calendar->weekEnd($objective->start_date, $weekNumber);

        $snapshot = [
            'objective_title' => $objective->title,
            'scope_type' => $objective->scope_type,
            'branch_name' => $objective->branch?->name,
            'employee_name' => $objective->employee?->full_name,
            'responsible_name' => $objective->responsibleUser?->name,
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
            ], $absolutePath, ['footer_left' => 'MR LANA · Warning Rojo']);

            $warning->update(['folio' => $folio, 'stored_path' => $path, 'disk' => $disk]);

            return $warning->fresh();
        });
    }
}
