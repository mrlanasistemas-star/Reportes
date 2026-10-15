<?php

namespace App\Services\Okr;

use App\Models\OkrObjective;
use Illuminate\Support\Facades\DB;

/**
 * Activación de un Objective (extraída de ObjectiveController::activate el
 * 14-oct-2026 para reutilizarla al activar en cascada los OKR individuales
 * de una sucursal). Reglas intactas: ponderación 100%, línea base congelada
 * "a hoy" por alcance REAL del Objective (sucursal o gestor — nunca cae a la
 * de la sucursal para un gestor), y si cualquier KR no resuelve su línea base
 * la activación completa se rechaza.
 */
class OkrActivationService
{
    public function __construct(
        private readonly OkrWeightValidator $weightValidator,
        private readonly OkrKpiValueResolver $resolver,
        private readonly OkrSnapshotService $snapshotService,
        private readonly OkrTrackingPeriodResolver $periodResolver,
        private readonly OkrAuditLogger $logger,
    ) {
    }

    /**
     * @return string|null Mensaje de error (null = activado).
     */
    public function activate(OkrObjective $objective, int $userId): ?string
    {
        if ($objective->lifecycle_status !== OkrObjective::STATUS_DRAFT) {
            return 'Solo un Objective en borrador puede activarse.';
        }
        // Mismas validaciones que la revisión previa (colaborador vigente, KR, periodo).
        $readiness = $this->readiness($objective);
        if (!$readiness['ready']) {
            return implode(' ', $readiness['issues']);
        }
        if (!$this->weightValidator->isValid($objective)) {
            $summary = $this->weightValidator->summary($objective);

            return "La ponderación debe sumar 100% (actual: {$summary['total']}%).";
        }

        $period = $this->periodResolver->getPeriodForDate(now());

        $failures = [];
        $resolvedBaselines = [];
        foreach ($objective->keyResults()->with('kpi')->get() as $kr) {
            if ($kr->baseline_value !== null) {
                continue; // capturada (manual o manual autorizada) — se congela tal cual
            }

            if (!$kr->kpi->isAutomatic()) {
                $failures[] = "{$kr->kpi->name} (manual — captura la línea base antes de activar)";
                continue;
            }

            $baseline = $period
                ? $this->resolver->getValue($kr->kpi, $objective->scope_type, $objective->branch_id, $objective->employee_id, $period)
                : null;

            if ($baseline === null) {
                $failures[] = $kr->kpi->name;
                continue;
            }

            $resolvedBaselines[$kr->id] = $baseline;
        }

        if (!empty($failures)) {
            $list = implode(', ', $failures);

            return "No fue posible obtener la línea base automática del KPI {$list} para este alcance y periodo. "
                . 'Verifica que Reportería tenga radiografía generada para ese alcance/periodo, o captura la línea base manualmente antes de activar.';
        }

        DB::transaction(function () use ($objective, $period, $resolvedBaselines, $userId) {
            foreach ($objective->keyResults()->with('kpi')->get() as $kr) {
                if (isset($resolvedBaselines[$kr->id])) {
                    $kr->baseline_value = $resolvedBaselines[$kr->id];
                    $kr->baseline_source = $kr->kpi->provider_key;
                } elseif ($kr->baseline_source === null) {
                    $kr->baseline_source = $kr->kpi->isAutomatic() ? $kr->kpi->provider_key : 'manual';
                }
                // 'manual_authorized' (línea base manual con motivo) se conserva — trazabilidad.
                $kr->baseline_period_date = $period?->end_date;
                $kr->baseline_locked_at = now();
                $kr->save();
            }

            $objective->update(['lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now()]);
            $this->logger->log('objective', $objective->id, $userId, 'activated');
        });

        $this->snapshotService->evaluateObjective($objective->fresh());

        return null;
    }

    /**
     * Revisión PREVIA a la activación (15-oct-2026) — sin efectos: qué está
     * listo y qué falta, con mensajes concretos. Las líneas base automáticas no
     * se consultan aquí (Reportería es costosa); se indica que se congelan al
     * activar y, si no hubiera dato, la activación de ESE OKR se rechaza y se
     * informa.
     *
     * @return array{id:int, label:string, scope:string, status:string, ready:bool, issues:string[], notes:string[]}
     */
    public function readiness(OkrObjective $objective): array
    {
        $objective->loadMissing(['employee', 'branch', 'keyResults.kpi']);
        $issues = [];
        $notes = [];
        $label = $objective->scope_type === OkrObjective::SCOPE_EMPLOYEE
            ? ($objective->employee?->full_name ?? $objective->title)
            : 'Sucursal ' . ($objective->branch?->name ?? '');

        if ($objective->lifecycle_status !== OkrObjective::STATUS_DRAFT) {
            return ['id' => $objective->id, 'label' => $label, 'scope' => $objective->scope_type, 'status' => $objective->lifecycle_status,
                'ready' => false, 'issues' => [], 'notes' => ['Ya no está en borrador (' . $objective->lifecycle_status . ').']];
        }

        $krs = $objective->keyResults;
        if ($krs->isEmpty()) {
            $issues[] = 'No tiene Key Results — usa "Configurar KR" para capturar su KPI, línea base y meta.';
        } else {
            $total = round($krs->sum(fn ($kr) => (float) $kr->weight), 2);
            if (abs($total - 100.0) > 0.01) {
                $issues[] = "La ponderación de sus KR suma {$total}% (debe ser 100%).";
            }
            foreach ($krs as $kr) {
                if ($kr->baseline_value === null && !$kr->kpi->isAutomatic()) {
                    $issues[] = "{$kr->kpi->name}: falta capturar la línea base (KPI manual).";
                } elseif ($kr->baseline_value === null) {
                    $notes[] = "{$kr->kpi->name}: la línea base se consulta y congela en Reportería al activar.";
                }
                if ($kr->target_value === null) {
                    $issues[] = "{$kr->kpi->name}: falta la meta.";
                }
            }
        }

        if ($objective->end_date && $objective->end_date->lt(now()->startOfDay())) {
            $issues[] = 'Su periodo ya terminó (' . $objective->end_date->format('d/m/Y') . ').';
        }

        if ($objective->scope_type === OkrObjective::SCOPE_EMPLOYEE && $objective->employee_id) {
            $resolver = app(OkrEmployeeBranchResolver::class);
            if (!$resolver->employeeIsActive((int) $objective->employee_id)) {
                $issues[] = 'El colaborador ya no está vigente en la nómina — no se activa una asignación nueva.';
            } elseif ($objective->branch_id && !$resolver->employeeBelongsToBranch((int) $objective->employee_id, (int) $objective->branch_id)) {
                $issues[] = 'El colaborador ya no pertenece a la sucursal del OKR.';
            }
        }

        return ['id' => $objective->id, 'label' => $label, 'scope' => $objective->scope_type, 'status' => $objective->lifecycle_status,
            'ready' => $issues === [], 'issues' => $issues, 'notes' => $notes];
    }

    /**
     * Activa en cascada los OKR individuales en borrador de una sucursal.
     * Cada uno se valida por separado: uno incompleto NO se activa (ni bloquea
     * a los demás) y se informa por nombre.
     *
     * @return array{activated: string[], failed: array<string, string>}
     */
    public function activateChildren(OkrObjective $parent, int $userId): array
    {
        $result = ['activated' => [], 'failed' => []];

        foreach ($parent->children()->with('employee')->where('lifecycle_status', OkrObjective::STATUS_DRAFT)->get() as $child) {
            $label = $child->employee?->full_name ?? $child->title;
            $error = $this->activate($child, $userId);
            if ($error === null) {
                $result['activated'][] = $label;
            } else {
                $result['failed'][$label] = $error;
            }
        }

        return $result;
    }
}
