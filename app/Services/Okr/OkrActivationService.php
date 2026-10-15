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
        return $this->attempt($objective, $userId)['message'];
    }

    /**
     * Igual que activate() pero indica también la acción que resuelve el bloqueo
     * (p.ej. 'configure_kr' → "Completar línea base") para que la pantalla la ofrezca.
     *
     * @return array{message: string|null, action: string|null, action_label: string|null}
     */
    public function attempt(OkrObjective $objective, int $userId): array
    {
        $fail = fn (?string $message, ?string $action = null, ?string $label = null) => ['message' => $message, 'action' => $action, 'action_label' => $label];

        if ($objective->lifecycle_status !== OkrObjective::STATUS_DRAFT) {
            return $fail('Este OKR ya no está en borrador (' . $this->statusLabel($objective->lifecycle_status) . '): solo un borrador puede activarse.');
        }
        // Mismas validaciones que la revisión previa (colaborador vigente, KR, periodo).
        $readiness = $this->readiness($objective);
        if (!$readiness['ready']) {
            $first = $readiness['items'][0] ?? null;
            $message = count($readiness['items']) === 1 && ($first['reason'] ?? null) !== null
                ? "No puedes activar el OKR de {$readiness['label']} porque {$first['reason']}."
                : "No puedes activar el OKR de {$readiness['label']}. " . implode(' ', $readiness['issues']);

            return $fail($message, $first['action'] ?? null, $first['action_label'] ?? null);
        }
        if (!$this->weightValidator->isValid($objective)) {
            $summary = $this->weightValidator->summary($objective);

            return $fail("No puedes activar el OKR de {$readiness['label']}: la ponderación debe sumar 100% (actual: {$summary['total']}%).", 'configure_kr', 'Ajustar ponderación');
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

            return $fail("No puedes activar el OKR de {$readiness['label']}: Reportería no tiene un valor de {$list} para este alcance y periodo, así que no hay línea base que congelar. "
                . 'Genera la radiografía de ese periodo o captura la línea base manual con su motivo.', 'configure_kr', 'Capturar línea base');
        }

        $activated = DB::transaction(function () use ($objective, $period, $resolvedBaselines, $userId) {
            // Bloqueo de fila: dos clics (o dos usuarios) no activan ni congelan dos veces.
            $locked = OkrObjective::query()->whereKey($objective->id)->lockForUpdate()->first();
            if ($locked === null || $locked->lifecycle_status !== OkrObjective::STATUS_DRAFT) {
                return false;
            }
            foreach ($objective->keyResults()->with('kpi')->get() as $kr) {
                if (isset($resolvedBaselines[$kr->id])) {
                    $kr->baseline_value = $resolvedBaselines[$kr->id];
                    $kr->baseline_source = $kr->kpi->provider_key;
                } elseif ($objective->scope_type === OkrObjective::SCOPE_EMPLOYEE) {
                    $kr->baseline_source = OkrBaselineGuard::SOURCE_MANUAL_INDIVIDUAL; // capturada para el gestor
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

            return true;
        });

        if (!$activated) {
            return $fail('Este OKR acaba de ser activado por otra solicitud; no se volvió a congelar su línea base.');
        }

        // La activación ya quedó completa (transacción). Si la primera evaluación
        // falla, no se revierte: se registra y se recalcula en la siguiente evaluación.
        try {
            $this->snapshotService->evaluateObjective($objective->fresh());
        } catch (\Throwable $e) {
            report($e);
        }

        return $fail(null);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            OkrObjective::STATUS_ACTIVE => 'activo',
            OkrObjective::STATUS_DRAFT => 'borrador',
            default => $status,
        };
    }

    private function personName(OkrObjective $objective): string
    {
        $name = $objective->employee?->full_name;

        return $name ? mb_convert_case(mb_strtolower(trim($name)), MB_CASE_TITLE) : ($objective->title ?? 'este colaborador');
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
        $items = [];
        $notes = [];
        $isEmployee = $objective->scope_type === OkrObjective::SCOPE_EMPLOYEE;
        $label = $isEmployee ? $this->personName($objective) : 'Sucursal ' . ($objective->branch?->name ?? '');
        // Cada pendiente: mensaje concreto + la acción que lo resuelve (la UI la ofrece como botón).
        $add = function (string $message, ?string $action = null, ?string $actionLabel = null, ?string $reason = null) use (&$issues, &$items) {
            $issues[] = $message;
            $items[] = ['message' => $message, 'action' => $action, 'action_label' => $actionLabel, 'reason' => $reason];
        };

        if ($objective->lifecycle_status !== OkrObjective::STATUS_DRAFT) {
            return ['id' => $objective->id, 'label' => $label, 'scope' => $objective->scope_type, 'status' => $objective->lifecycle_status,
                'ready' => false, 'issues' => [], 'items' => [], 'notes' => ['Ya no está en borrador (' . $this->statusLabel($objective->lifecycle_status) . ').']];
        }

        $krs = $objective->keyResults;
        if ($krs->isEmpty()) {
            $add("Falta configurar los Key Results de {$label} (KPI, línea base, incremento y meta).", 'configure_kr', 'Configurar Key Results');
        } else {
            $total = round($krs->sum(fn ($kr) => (float) $kr->weight), 2);
            if (abs($total - 100.0) > 0.01) {
                $add("La ponderación de los Key Results de {$label} suma {$total}% y debe sumar 100%.", 'configure_kr', 'Ajustar ponderación');
            }
            foreach ($krs as $kr) {
                if ($kr->baseline_value === null && $isEmployee) {
                    // Regla definitiva (16-oct-2026): base del gestor = captura manual.
                    $add("Falta capturar la línea base de {$label} en «{$kr->kpi->name}» (valor inicial desde el cual se medirá su incremento).", 'configure_kr', 'Completar línea base', "falta capturar su línea base en «{$kr->kpi->name}»");
                } elseif ($kr->baseline_value === null && !$kr->kpi->isAutomatic()) {
                    $add("Falta capturar la línea base de «{$kr->kpi->name}»: es un KPI manual y Reportería no la calcula.", 'configure_kr', 'Completar línea base');
                } elseif ($kr->baseline_value === null) {
                    $notes[] = "{$kr->kpi->name}: la línea base se consulta y congela en Reportería al activar.";
                }
                if ($kr->target_value === null) {
                    $add("Falta la meta de «{$kr->kpi->name}».", 'configure_kr', 'Capturar meta');
                }
            }
        }

        if ($objective->end_date && $objective->end_date->lt(now()->startOfDay())) {
            $add('Su periodo ya terminó (' . $objective->end_date->format('d/m/Y') . '): un OKR vencido no puede activarse. Crea uno nuevo con fechas vigentes.');
        }

        if ($isEmployee && $objective->employee_id) {
            $resolver = app(OkrEmployeeBranchResolver::class);
            if (!$resolver->employeeIsActive((int) $objective->employee_id)) {
                $add("{$label} no aparece como vigente en la nómina, así que no se activa una asignación nueva. Si sigue trabajando, concilia su situación en Colaboradores.", 'employees', 'Revisar en Colaboradores');
            } elseif ($objective->branch_id && !$resolver->employeeBelongsToBranch((int) $objective->employee_id, (int) $objective->branch_id)) {
                $membership = $resolver->membershipFor((int) $objective->employee_id);
                if (($membership['conflict'] ?? null) !== null) {
                    $add($membership['conflict']['message'] . ' ' . $membership['conflict']['detail'] . '. Concilia su sucursal antes de activar.', 'reconcile_branch', 'Conciliar sucursal');
                } elseif ($membership['branch_id'] === null) {
                    $add("No hay una fuente vigente (cartera, nómina o directorio) que ubique a {$label} en una sucursal: verifica su registro.", 'employees', 'Revisar en Colaboradores');
                } else {
                    $add("{$label} está vigente en {$membership['branch_name']} ({$membership['source_label']}), no en la sucursal de este OKR.", 'employees', 'Revisar en Colaboradores');
                }
            }
        }

        return ['id' => $objective->id, 'label' => $label, 'scope' => $objective->scope_type, 'status' => $objective->lifecycle_status,
            'ready' => $issues === [], 'issues' => $issues, 'items' => $items, 'notes' => $notes];
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
