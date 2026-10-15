<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Models\OkrKeyResult;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Services\Okr\OkrAuditLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Módulo OKR — Key Results de un Objective en DRAFT (una vez activo, la
 * meta/peso solo cambian vía ObjectiveController::updateGoal()/updateWeights(),
 * con motivo y bitácora — nunca aquí).
 *
 * CORRECCIÓN 09-sep-2026 (punto 3 de la auditoría): un KPI automático NUNCA
 * acepta una `baseline_value` manual — ese valor solo puede venir de
 * Reportería al activar (ver ObjectiveController::activate()/baselinePreview()).
 * Antes el request aceptaba cualquier número para cualquier KPI.
 *
 * CORRECCIÓN (punto 11): autorización ahora vía Policy sobre el Objective
 * (admin o responsable), nunca un Gate global sin contexto.
 */
class KeyResultController extends Controller
{
    use AuthorizesRequests;

    public function store(Request $request, OkrObjective $objective): RedirectResponse
    {
        $this->authorize('update', $objective);
        $this->assertDraft($objective);

        $data = $request->validate([
            // Un KPI inactivo nunca puede asignarse a un KR nuevo (sección 43 del
            // pedido) — un KR ya existente conserva su KPI aunque luego se
            // desactive (no se le retira retroactivamente).
            'kpi_id'         => ['required', 'integer', Rule::exists('okr_kpis', 'id')->where('is_active', true)],
            'description'    => ['required', 'string', 'max:191'],
            'baseline_value' => ['nullable', 'numeric'],
            'target_value'   => ['required', 'numeric'],
            'weight'         => ['required', 'numeric', 'min:0.01', 'max:100'],
        ]);

        $this->assertBaselineAllowed($data, $objective);

        $objective->keyResults()->create($data + ($objective->scope_type === OkrObjective::SCOPE_EMPLOYEE
            ? ['baseline_source' => \App\Services\Okr\OkrBaselineGuard::SOURCE_MANUAL_INDIVIDUAL] : []));

        return back()->with('success', 'Key Result agregado.');
    }

    /**
     * "Completar Key Results" (15-oct-2026) — para OKR en borrador que nacieron
     * SIN KR (individuales creados antes de la corrección del asistente):
     * captura en bloque los KR del colaborador con SU línea base y SU meta,
     * conservando el vínculo con el OKR de sucursal. Nunca copia la meta de la
     * sucursal. Pesos al 100%, misma regla de base manual autorizada que el
     * asistente, todo en una transacción y en bitácora.
     */
    public function complete(Request $request, OkrObjective $objective, OkrAuditLogger $logger, \App\Services\Okr\OkrBaselineGuard $guard): \Illuminate\Http\JsonResponse
    {
        $this->authorize('update', $objective);
        if ($objective->lifecycle_status !== OkrObjective::STATUS_DRAFT) {
            return response()->json(['message' => 'Solo un OKR en borrador puede completarse; uno activo se ajusta con "Editar meta".'], 422);
        }
        // "Editar metas" en borrador (15-oct-2026): con replace=true se sustituyen
        // los KR del borrador (los anteriores quedan en soft-delete y bitácora).
        if ($objective->keyResults()->exists() && !$request->boolean('replace')) {
            return response()->json(['message' => 'Este OKR ya tiene Key Results — usa "Editar metas" para reemplazarlos.'], 422);
        }

        $data = $request->validate([
            'key_results'                          => ['required', 'array', 'min:1'],
            'key_results.*.kpi_id'                 => ['required', 'integer', Rule::exists('okr_kpis', 'id')->where('is_active', true)],
            'key_results.*.description'            => ['required', 'string', 'max:191'],
            'key_results.*.baseline_value'         => ['nullable', 'numeric'],
            'key_results.*.baseline_manual_reason' => ['nullable', 'string', 'max:500'],
            'key_results.*.target_value'           => ['required', 'numeric'],
            'key_results.*.weight'                 => ['required', 'numeric', 'min:0.01', 'max:100'],
            'reason'                               => ['nullable', 'string', 'max:500'],
        ]);

        $total = round(array_sum(array_column($data['key_results'], 'weight')), 2);
        if (abs($total - 100.0) > 0.01) {
            return response()->json(['message' => "La ponderación debe sumar 100% (actual: {$total}%)."], 422);
        }

        $errors = [];
        foreach ($data['key_results'] as $i => $kr) {
            $error = $guard->check($kr, $objective->scope_type, $objective->branch_id, $objective->employee_id, $objective->start_date->toDateString());
            if ($error !== null) {
                $errors["key_results.{$i}.{$error['field']}"] = [$error['message']];
            }
            if ($error === null && ($kr['baseline_value'] ?? null) === null && !OkrKpi::query()->find($kr['kpi_id'])?->isAutomatic()) {
                $errors["key_results.{$i}.baseline_value"] = ['Captura la línea base de este KPI manual.'];
            }
        }
        if ($errors !== []) {
            return response()->json(['message' => collect($errors)->flatten()->first(), 'errors' => $errors], 422);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($objective, $data, $logger) {
            foreach ($objective->keyResults()->with('kpi')->get() as $old) {
                $logger->log('key_result', $old->id, auth()->id(), 'key_result_removed', 'target_value', $old->target_value, null,
                    "Reemplazado al editar las metas del borrador ({$old->kpi->name}).");
                $old->delete();
            }
            $isIndividual = $objective->scope_type === OkrObjective::SCOPE_EMPLOYEE;
            foreach ($data['key_results'] as $row) {
                $reason = trim((string) ($row['baseline_manual_reason'] ?? ''));
                $authorized = !$isIndividual && ($row['baseline_value'] ?? null) !== null && $reason !== '';
                $kr = $objective->keyResults()->create([
                    'kpi_id' => $row['kpi_id'], 'description' => $row['description'],
                    'baseline_value' => $row['baseline_value'] ?? null,
                    'baseline_source' => $isIndividual ? \App\Services\Okr\OkrBaselineGuard::SOURCE_MANUAL_INDIVIDUAL : ($authorized ? 'manual_authorized' : null),
                    'target_value' => $row['target_value'], 'weight' => $row['weight'],
                ]);
                $logger->log('key_result', $kr->id, auth()->id(), 'key_result_completed', 'target_value', null, $kr->target_value,
                    $data['reason'] ?? 'Key Result capturado para completar un OKR que nació sin KR.');
                if ($isIndividual) {
                    $logger->log('key_result', $kr->id, auth()->id(), 'baseline_manual_individual', 'baseline_value', null, $kr->baseline_value, 'Línea base individual capturada para el gestor.');
                } elseif ($authorized) {
                    $logger->log('key_result', $kr->id, auth()->id(), 'baseline_manual_authorized', 'baseline_value', null, $kr->baseline_value, $reason);
                }
            }
        });

        return response()->json(['success' => true, 'key_results' => $objective->keyResults()->count()]);
    }

    public function update(Request $request, OkrObjective $objective, OkrKeyResult $keyResult): RedirectResponse
    {
        $this->authorize('update', $objective);
        $this->assertDraft($objective);

        $data = $request->validate([
            'description'    => ['required', 'string', 'max:191'],
            // Un KPI inactivo nunca puede asignarse a un KR nuevo (sección 43 del
            // pedido) — un KR ya existente conserva su KPI aunque luego se
            // desactive (no se le retira retroactivamente).
            'kpi_id'         => ['required', 'integer', Rule::exists('okr_kpis', 'id')->where('is_active', true)],
            'baseline_value' => ['nullable', 'numeric'],
            'target_value'   => ['required', 'numeric'],
            'weight'         => ['required', 'numeric', 'min:0.01', 'max:100'],
        ]);

        $this->assertBaselineAllowed($data, $objective);

        $keyResult->update($data + ($objective->scope_type === OkrObjective::SCOPE_EMPLOYEE
            ? ['baseline_source' => \App\Services\Okr\OkrBaselineGuard::SOURCE_MANUAL_INDIVIDUAL] : []));

        return back()->with('success', 'Key Result actualizado.');
    }

    public function destroy(OkrObjective $objective, OkrKeyResult $keyResult, OkrAuditLogger $logger): RedirectResponse
    {
        $this->authorize('update', $objective);
        $this->assertDraft($objective);

        $keyResult->delete();
        $logger->log('objective', $objective->id, auth()->id(), 'key_result_removed', reason: "KR #{$keyResult->id} eliminado en borrador.");

        return back()->with('success', 'Key Result eliminado.');
    }

    private function assertDraft(OkrObjective $objective): void
    {
        abort_if($objective->lifecycle_status !== OkrObjective::STATUS_DRAFT, 422, 'Solo se pueden modificar Key Results mientras el Objective está en borrador. Usa "editar meta" para un OKR activo.');
    }

    /**
     * KPI automático de SUCURSAL → baseline_value SIEMPRE null aquí (solo
     * Reportería la fija al activar). OKR individual (16-oct-2026) → la línea
     * base es MANUAL y obligatoria.
     */
    private function assertBaselineAllowed(array $data, OkrObjective $objective): void
    {
        if ($objective->scope_type === OkrObjective::SCOPE_EMPLOYEE) {
            if (($data['baseline_value'] ?? null) === null) {
                throw ValidationException::withMessages(['baseline_value' => 'Captura la línea base del gestor.']);
            }

            return;
        }

        if (($data['baseline_value'] ?? null) === null) {
            return;
        }

        $kpi = OkrKpi::query()->find($data['kpi_id']);
        if ($kpi && $kpi->isAutomatic()) {
            throw ValidationException::withMessages([
                'baseline_value' => 'Este KPI es automático — su línea base la calcula Reportería al activar el OKR, no se puede capturar a mano. Usa "Consultar línea base" para verla antes de activar.',
            ]);
        }
    }
}
