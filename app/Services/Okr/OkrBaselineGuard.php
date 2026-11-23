<?php

namespace App\Services\Okr;

use App\Models\OkrKpi;
use App\Models\OkrObjective;

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

    /**
     * @param  OkrTrackingPeriodResolver  $periodResolver  Resuelve el periodo de Reportería para la fecha de inicio.
     * @param  OkrKpiValueResolver  $valueResolver  Consulta si Reportería tiene dato del KPI para el alcance.
     */
    public function __construct(
        private readonly OkrTrackingPeriodResolver $periodResolver,
        private readonly OkrKpiValueResolver $valueResolver,
    ) {}

    /**
     * Valida la línea base capturada de un KR antes de guardarlo.
     *
     * Reglas: OKR individual (gestor) → la base manual es obligatoria si hay KPI.
     * Sucursal/general con KPI automático → la base manual exige motivo de al
     * menos 10 caracteres y solo se acepta si Reportería NO tiene dato para ese
     * alcance en el periodo de la fecha de inicio. KPI manual o sin base → OK.
     *
     * Efecto: lecturas de BD (KPI) y posible cálculo del snapshot de Reportería.
     *
     * @param  array  $kr  Datos del KR del formulario (kpi_id, baseline_value, baseline_manual_reason...).
     * @param  string  $scopeType  Alcance del Objective (general/branch/employee).
     * @param  int|null  $branchId  Sucursal del alcance, si aplica.
     * @param  int|null  $employeeId  Colaborador del alcance, si aplica.
     * @param  string  $startDate  Fecha de inicio del OKR (Y-m-d) para ubicar el periodo.
     * @return array{field: string, message: string}|null Error de validación (campo + mensaje en español) o null si es válido.
     */
    public function check(array $kr, string $scopeType, ?int $branchId, ?int $employeeId, string $startDate): ?array
    {
        // Regla definitiva (16-oct-2026): el OKR INDIVIDUAL usa una línea base
        // MANUAL definida para cada gestor — nunca se consulta en Reportería,
        // nunca se toma la de la sucursal y su ausencia de dato automático no
        // bloquea. Solo se exige que esté capturada.
        if ($scopeType === OkrObjective::SCOPE_EMPLOYEE) {
            return isset($kr['kpi_id']) && ($kr['baseline_value'] ?? null) === null
                ? ['field' => 'baseline_value', 'message' => 'Captura la línea base del gestor: es el valor inicial desde el cual se medirá su incremento.']
                : null;
        }

        if (($kr['baseline_value'] ?? null) === null || ! isset($kr['kpi_id'])) {
            return null;
        }

        $kpi = OkrKpi::query()->find($kr['kpi_id']);
        if (! $kpi || ! $kpi->isAutomatic()) {
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
