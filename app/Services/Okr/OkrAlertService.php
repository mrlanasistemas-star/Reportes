<?php

namespace App\Services\Okr;

use App\Models\OkrAlert;
use App\Models\OkrObjective;

/**
 * Módulo OKR (08-sep-2026) — alertas INTERNAS únicamente (nunca WhatsApp/SMS/
 * correo/push externo en esta fase). Deduplicadas por (objective, tipo,
 * semana actual) — firstOrCreate() sobre dedupe_key evita repetir la misma
 * alerta en cada refresh dentro de la misma semana.
 */
class OkrAlertService
{
    public function generateForObjective(OkrObjective $objective): void
    {
        if (!$objective->isActive()) {
            return;
        }

        $label       = $this->label($objective);
        $currentWeek = $objective->currentWeekNumber();

        if (in_array($objective->health_status, [OkrObjective::HEALTH_RISK, OkrObjective::HEALTH_OFF_TRACK], true)) {
            $krRisk = $objective->keyResults()->whereNotNull('deviation_pp')->orderBy('deviation_pp')->first();
            $deviation = $krRisk?->deviation_pp;
            $msg = $deviation !== null
                ? "El OKR de {$label} presenta una desviación de {$deviation} pp."
                : "El OKR de {$label} presenta riesgo de desviación.";
            $this->createAlert($objective, OkrAlert::TYPE_RISK, $msg, $currentWeek);
        }

        if ($objective->health_status === OkrObjective::HEALTH_OFF_TRACK) {
            $this->createAlert($objective, OkrAlert::TYPE_OFF_TRACK, "El OKR de {$label} está fuera de trayectoria.", $currentWeek);
        }

        $weeksRemaining = max(0, (int) $objective->duration_weeks - $currentWeek);
        if ($weeksRemaining > 0 && $weeksRemaining <= 2) {
            $this->createAlert($objective, OkrAlert::TYPE_DEADLINE_NEAR, "Restan {$weeksRemaining} semana(s) para finalizar el OKR de {$label}.", $currentWeek);
        }

        if ($currentWeek > 0 && !$objective->checkIns()->where('week_number', $currentWeek)->exists()) {
            $this->createAlert($objective, OkrAlert::TYPE_CHECKIN_PENDING, "El responsable no ha realizado el check-in de la semana {$currentWeek} del OKR de {$label}.", $currentWeek);
        }
    }

    private function label(OkrObjective $objective): string
    {
        return $objective->branch?->name ?? $objective->employee?->full_name ?? $objective->title;
    }

    private function createAlert(OkrObjective $objective, string $type, string $message, int $week): void
    {
        $dedupeKey = sprintf('%d:%s:%d', $objective->id, $type, $week);
        OkrAlert::query()->firstOrCreate(
            ['dedupe_key' => $dedupeKey],
            ['okr_objective_id' => $objective->id, 'type' => $type, 'message' => $message],
        );
    }
}
