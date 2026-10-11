<?php

namespace App\Services\Api;

use App\Models\EmployeeBranchAssignment;
use App\Models\Period;

/**
 * Fase 2.3 — "sucursal histórica vs sucursal actual". Un gestor que trabajó
 * en Tula en junio y hoy está en Cuernavaca: una consulta sobre junio NUNCA
 * debe atribuirlo a Cuernavaca solo porque es su asignación más reciente.
 *
 * Regla: la sucursal "real" de un colaborador en un periodo es la que quedó
 * registrada en `employee_branch_assignments` para ESE `period_id` exacto (o,
 * si el periodo es compuesto — mensual/bimestral/etc. —, para alguno de sus
 * periodos semanales componentes). Si no hay ninguna asignación para ese
 * periodo, el resultado es `null` — NUNCA se cae a "la asignación más
 * reciente" como sustituto, porque eso es exactamente la coincidencia
 * insegura que esta clase existe para evitar.
 *
 * "Sucursal actual" (sin contexto de periodo) sigue existiendo como un
 * concepto separado y explícito (`resolveCurrent()`), usado solo para
 * catálogos/identificación, nunca para atribuir un valor financiero de un
 * periodo pasado.
 */
class EmployeeBranchHistoryResolver
{
    /** @return array{branch_id: ?int, status: string} status ∈ known|unknown */
    public function resolveForPeriod(int $employeeId, Period $period): array
    {
        $direct = EmployeeBranchAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('period_id', $period->id)
            ->value('branch_id');

        if ($direct !== null) {
            return ['branch_id' => (int) $direct, 'status' => 'known'];
        }

        $componentIds = collect($period->component_period_ids ?? [])->map(fn ($id) => (int) $id)->all();
        if (!empty($componentIds)) {
            $viaComponent = EmployeeBranchAssignment::query()
                ->where('employee_id', $employeeId)
                ->whereIn('period_id', $componentIds)
                ->orderByDesc('period_id')
                ->value('branch_id');

            if ($viaComponent !== null) {
                return ['branch_id' => (int) $viaComponent, 'status' => 'known'];
            }
        }

        return ['branch_id' => null, 'status' => 'unknown'];
    }

    /**
     * Sucursal más reciente conocida, SIN contexto de periodo — solo para
     * catálogos/identificación (p. ej. "¿a qué sucursal pertenece hoy este
     * colaborador?"). Nunca usarla para atribuir datos financieros de un
     * periodo específico — para eso usar resolveForPeriod().
     */
    public function resolveCurrent(int $employeeId): ?int
    {
        return EmployeeBranchAssignment::query()
            ->where('employee_id', $employeeId)
            ->latest('id')
            ->value('branch_id');
    }

    /**
     * Fase 2.2 — IDOR. Decide si un consumidor restringido puede ver a este
     * colaborador EN ESTE PERIODO. Un consumidor sin restricción (global)
     * siempre puede. Un consumidor restringido NUNCA se autoriza por una
     * sucursal desconocida — "unknown" se trata como NO autorizado.
     */
    public function isEmployeeAllowedForConsumer(\App\Models\ApiConsumer $consumer, int $employeeId, Period $period): bool
    {
        if (!$consumer->isRestrictedToBranches() && !$consumer->hasNoBranchAccess()) {
            return true; // acceso global
        }

        $resolved = $this->resolveForPeriod($employeeId, $period);

        if ($resolved['status'] !== 'known') {
            return false; // desconocido → nunca se concede por coincidencia insegura
        }

        return $consumer->canAccessBranch($resolved['branch_id']);
    }
}
