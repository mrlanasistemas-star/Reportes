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

        $this->assertBaselineAllowed($data);

        $objective->keyResults()->create($data);

        return back()->with('success', 'Key Result agregado.');
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

        $this->assertBaselineAllowed($data);

        $keyResult->update($data);

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

    /** KPI automático → baseline_value SIEMPRE null aquí (solo Reportería la fija al activar). */
    private function assertBaselineAllowed(array $data): void
    {
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
