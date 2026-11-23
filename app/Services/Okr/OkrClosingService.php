<?php

namespace App\Services\Okr;

use App\Models\OkrObjective;
use App\Models\OkrSetting;

/**
 * Módulo OKR (08-sep-2026) — cierre de Objectives vencidos. Idempotente:
 * cerrar un Objective ya cerrado es un no-op (ver closeDue()/close()).
 * Conserva TODO el histórico (baseline, meta, evidencias, check-ins,
 * snapshots) — nunca borra nada al cerrar.
 */
class OkrClosingService
{
    /** Clave en okr_settings con los rangos de clasificación final (completado/parcial). */
    public const SETTING_KEY = 'okr.final_status_ranges';

    /**
     * @param  OkrSnapshotService  $snapshotService  Evalúa el Objective por última vez antes de cerrarlo.
     * @param  OkrProgressCalculator  $calculator  Calcula el cumplimiento ponderado del Objective.
     */
    public function __construct(
        private readonly OkrSnapshotService $snapshotService,
        private readonly OkrProgressCalculator $calculator,
    ) {}

    /**
     * Cierra todos los Objectives activos cuya fecha fin ya pasó (end_date < hoy).
     * Pensado para la tarea programada de cierre.
     *
     * Efecto: por cada Objective, evaluación final + UPDATE a estado cerrado.
     *
     * @return int Número de Objectives procesados.
     */
    public function closeDue(): int
    {
        $due = OkrObjective::query()
            ->where('lifecycle_status', OkrObjective::STATUS_ACTIVE)
            ->whereDate('end_date', '<', now()->toDateString())
            ->get();

        foreach ($due as $objective) {
            $this->close($objective);
        }

        return $due->count();
    }

    /**
     * Cierra un Objective: lo evalúa una última vez (snapshot), calcula su
     * cumplimiento ponderado y lo clasifica como completado / parcialmente
     * completado / no completado. Idempotente: si ya está cerrado no hace nada.
     *
     * Efecto: escribe snapshots/KR vía OkrSnapshotService y actualiza
     * lifecycle_status, final_status y closed_at del Objective. No borra histórico.
     *
     * @param  OkrObjective  $objective  Objective a cerrar.
     * @return OkrObjective Objective recargado desde BD.
     */
    public function close(OkrObjective $objective): OkrObjective
    {
        if ($objective->lifecycle_status === OkrObjective::STATUS_CLOSED) {
            return $objective; // idempotente — ya cerrado, no re-clasifica
        }

        $this->snapshotService->evaluateObjective($objective);
        $objective->refresh();

        $compliance = $this->computeCompliance($objective);
        $finalStatus = $this->classifyFinal($compliance);

        $objective->update([
            'lifecycle_status' => OkrObjective::STATUS_CLOSED,
            'final_status' => $finalStatus,
            'closed_at' => now(),
        ]);

        return $objective->fresh();
    }

    /**
     * Cumplimiento ponderado del Objective a partir del avance real de cada KR y su peso.
     *
     * @param  OkrObjective  $objective  Objective a medir (lee sus KR de BD).
     * @return float Porcentaje de cumplimiento ponderado.
     */
    public function computeCompliance(OkrObjective $objective): float
    {
        $keyResults = $objective->keyResults()->get()->map(fn ($kr) => [
            'raw_progress' => $kr->actual_progress_percentage,
            'weight' => (float) $kr->weight,
        ])->all();

        return $this->calculator->objectiveCompliance($keyResults);
    }

    /**
     * Clasifica el cumplimiento final según los rangos configurados en
     * okr_settings (o defaultRanges(): ≥90 completado, ≥50 parcial).
     *
     * @param  float  $compliance  Cumplimiento ponderado en %.
     * @return string Constante OkrObjective::FINAL_*.
     */
    private function classifyFinal(float $compliance): string
    {
        $ranges = OkrSetting::getValue(self::SETTING_KEY, self::defaultRanges());

        if ($compliance >= $ranges['completed']) {
            return OkrObjective::FINAL_COMPLETED;
        }
        if ($compliance >= $ranges['partially_completed']) {
            return OkrObjective::FINAL_PARTIALLY_COMPLETED;
        }

        return OkrObjective::FINAL_NOT_COMPLETED;
    }

    /**
     * Rangos por defecto de clasificación final cuando no hay configuración.
     *
     * @return array{completed: float, partially_completed: float}
     */
    public static function defaultRanges(): array
    {
        return ['completed' => 90.0, 'partially_completed' => 50.0];
    }
}
