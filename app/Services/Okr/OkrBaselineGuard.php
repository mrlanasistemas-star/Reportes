<?php

namespace App\Services\Okr;

use App\Models\OkrKpi;

/**
 * Regla ÚNICA de línea base capturada (asistente y "Completar Key Results",
 * 14/15-oct-2026): un KPI automático solo acepta una base manual AUTORIZADA —
 * con motivo (≥ 10 caracteres) y únicamente si Reportería NO tiene dato
 * verificable para ESE alcance (sucursal o gestor). Nunca se sustituye en
 * silencio una base real.
 */
class OkrBaselineGuard
{
    /** Origen de la línea base capturada para un OKR individual (gestor). */
    public const SOURCE_MANUAL_INDIVIDUAL = 'manual_individual';

    public function __construct(
        private readonly OkrTrackingPeriodResolver $periodResolver,
        private readonly OkrKpiValueResolver $valueResolver,
    ) {
    }

    /**
     * @return array{field: string, message: string}|null
     */
    public function check(array $kr, string $scopeType, ?int $branchId, ?int $employeeId, string $startDate): ?array
    {
        // Regla definitiva (16-oct-2026): el OKR INDIVIDUAL usa una línea base
        // MANUAL definida para cada gestor — nunca se consulta en Reportería,
        // nunca se toma la de la sucursal y su ausencia de dato automático no
        // bloquea. Solo se exige que esté capturada.
        if ($scopeType === \App\Models\OkrObjective::SCOPE_EMPLOYEE) {
            return isset($kr['kpi_id']) && ($kr['baseline_value'] ?? null) === null
                ? ['field' => 'baseline_value', 'message' => 'Captura la línea base del gestor: es el valor inicial desde el cual se medirá su incremento.']
                : null;
        }

        if (($kr['baseline_value'] ?? null) === null || !isset($kr['kpi_id'])) {
            return null;
        }

        $kpi = OkrKpi::query()->find($kr['kpi_id']);
        if (!$kpi || !$kpi->isAutomatic()) {
            return null;
        }

        $reason = trim((string) ($kr['baseline_manual_reason'] ?? ''));
        if ($reason === '') {
            return ['field' => 'baseline_value', 'message' => 'Este KPI es automático — no se puede capturar una línea base manual, se obtiene sola al activar.'];
        }
        if (mb_strlen($reason) < 10) {
            return ['field' => 'baseline_manual_reason', 'message' => 'Explica el motivo de la línea base manual (mínimo 10 caracteres).'];
        }

        $period = $this->periodResolver->getPeriodForDate($startDate);
        $available = $period ? $this->valueResolver->getValue($kpi, $scopeType, $branchId, $employeeId, $period) : null;

        return $available !== null
            ? ['field' => 'baseline_value', 'message' => 'Reportería sí tiene una línea base verificable para este alcance — no se puede sustituir por una manual.']
            : null;
    }
}
