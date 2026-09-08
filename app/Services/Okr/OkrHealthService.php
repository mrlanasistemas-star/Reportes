<?php

namespace App\Services\Okr;

use App\Models\OkrObjective;
use App\Models\OkrSetting;

/**
 * Módulo OKR (08-sep-2026) — semáforo (ADELANTADO/EN TRAYECTORIA/EN RIESGO/
 * FUERA DE TRAYECTORIA). Umbrales centralizados aquí — NUNCA mágicos dentro de
 * componentes Vue (ver docs/OKR.md). Administrables vía okr_settings
 * (key='okr.health_thresholds'); si no hay fila configurada, usa
 * defaultThresholds().
 *
 * lifecycle_status (draft/active/closed/cancelled) es un concepto DISTINTO —
 * ver OkrObjective — nunca se mezcla con este semáforo.
 */
class OkrHealthService
{
    public const SETTING_KEY = 'okr.health_thresholds';

    /**
     * @return array{ahead: float, on_track: float, risk: float}
     */
    public function thresholds(): array
    {
        return OkrSetting::getValue(self::SETTING_KEY, self::defaultThresholds());
    }

    public static function defaultThresholds(): array
    {
        // ahead:    desviación >= +5 pp
        // on_track: desviación entre -5 pp y +5 pp
        // risk:     desviación entre -15 pp y -5 pp
        // off_track: desviación < -15 pp
        return ['ahead' => 5.0, 'on_track' => -5.0, 'risk' => -15.0];
    }

    public function classify(?float $deviationPp): string
    {
        if ($deviationPp === null) {
            return OkrObjective::HEALTH_ON_TRACK; // sin datos suficientes aún — neutral, no alarma
        }

        $t = $this->thresholds();

        if ($deviationPp >= $t['ahead']) {
            return OkrObjective::HEALTH_AHEAD;
        }
        if ($deviationPp >= $t['on_track']) {
            return OkrObjective::HEALTH_ON_TRACK;
        }
        if ($deviationPp >= $t['risk']) {
            return OkrObjective::HEALTH_RISK;
        }

        return OkrObjective::HEALTH_OFF_TRACK;
    }

    /**
     * Salud general del Objective — el peor semáforo entre sus KR activos
     * (un Objective con un solo KR fuera de trayectoria NO se ve "sano" solo
     * porque el promedio ponderado disimule el problema).
     *
     * @param  array<int, ?string>  $keyResultHealths
     */
    public function worstOf(array $keyResultHealths): string
    {
        $order = [
            OkrObjective::HEALTH_OFF_TRACK => 0,
            OkrObjective::HEALTH_RISK      => 1,
            OkrObjective::HEALTH_ON_TRACK  => 2,
            OkrObjective::HEALTH_AHEAD     => 3,
        ];

        $worst = OkrObjective::HEALTH_AHEAD;
        foreach ($keyResultHealths as $h) {
            if ($h === null) {
                continue;
            }
            if (($order[$h] ?? 2) < ($order[$worst] ?? 2)) {
                $worst = $h;
            }
        }

        return $worst;
    }
}
