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
    public const SETTING_KEY = 'okr.final_status_ranges';

    public function __construct(
        private readonly OkrSnapshotService $snapshotService,
        private readonly OkrProgressCalculator $calculator,
    ) {
    }

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

    public function close(OkrObjective $objective): OkrObjective
    {
        if ($objective->lifecycle_status === OkrObjective::STATUS_CLOSED) {
            return $objective; // idempotente — ya cerrado, no re-clasifica
        }

        $this->snapshotService->evaluateObjective($objective);
        $objective->refresh();

        $compliance  = $this->computeCompliance($objective);
        $finalStatus = $this->classifyFinal($compliance);

        $objective->update([
            'lifecycle_status' => OkrObjective::STATUS_CLOSED,
            'final_status'     => $finalStatus,
            'closed_at'        => now(),
        ]);

        return $objective->fresh();
    }

    public function computeCompliance(OkrObjective $objective): float
    {
        $keyResults = $objective->keyResults()->get()->map(fn ($kr) => [
            'raw_progress' => $kr->actual_progress_percentage,
            'weight'       => (float) $kr->weight,
        ])->all();

        return $this->calculator->objectiveCompliance($keyResults);
    }

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

    public static function defaultRanges(): array
    {
        return ['completed' => 90.0, 'partially_completed' => 50.0];
    }
}
