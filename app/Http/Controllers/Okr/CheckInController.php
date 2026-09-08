<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Okr\StoreCheckInRequest;
use App\Models\OkrObjective;
use App\Services\Okr\OkrSnapshotService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Módulo OKR — check-in semanal (sección 29 del pedido original).
 *
 * CORRECCIÓN 09-sep-2026 (punto 1 de la auditoría): antes un KPI manual no
 * tenía NINGÚN camino real para actualizar `current_value` — quedaba NULL o
 * congelado para siempre. Ahora el check-in acepta `manual_results` (uno por
 * cada KR manual/híbrido) y actualiza esos KR dentro de la MISMA transacción
 * del check-in + acción correctiva. El "resultado actual" de un KPI
 * AUTOMÁTICO sigue viniendo SIEMPRE de Reportería — nunca se sobreescribe
 * aquí (validado también en StoreCheckInRequest).
 *
 * CORRECCIÓN 10-sep-2026 (punto 30 de la auditoría — bug real): antes
 * `actual_value_snapshot` se armaba leyendo `current_value` de los KR ANTES
 * de aplicar `manual_results`, y el check-in se creaba con ese snapshot
 * desfasado — el KR terminaba actualizado pero el snapshot guardado DENTRO
 * del check-in conservaba el valor ANTERIOR. Ahora: (1) se crea el check-in
 * con snapshot provisional vacío, (2) se aplican los manual_results y se
 * recalcula cada KR afectado, (3) se vuelve a leer TODOS los current_value
 * YA actualizados, (4) se actualiza `actual_value_snapshot` con esos valores
 * finales. Check-in, KR, snapshot semanal y actual_value_snapshot quedan
 * representando exactamente el mismo instante.
 *
 * CORRECCIÓN (punto 31): el snapshot usaba `kpi_id` como llave — si un
 * Objective tuviera dos Key Results con el MISMO KPI, colisionarían y uno se
 * perdería. Ahora la llave es `key_result_id` (único por definición), con
 * `kpi_id` y `value` como datos dentro de cada entrada — nunca se pierde
 * ningún KR aunque compartan KPI.
 */
class CheckInController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreCheckInRequest $request, OkrObjective $objective, OkrSnapshotService $snapshotService): RedirectResponse
    {
        $weekNumber = $objective->currentWeekNumber();
        if ($weekNumber <= 0) {
            return back()->withErrors(['check_in' => 'El OKR todavía no ha iniciado su primera semana.']);
        }

        if ($objective->checkIns()->where('week_number', $weekNumber)->where('user_id', auth()->id())->exists()) {
            return back()->withErrors(['check_in' => 'Ya registraste el check-in de esta semana.']);
        }

        DB::transaction(function () use ($request, $objective, $weekNumber, $snapshotService) {
            // 1. Check-in con snapshot PROVISIONAL — se completa hasta el final,
            // una vez aplicados los resultados manuales (ver docblock).
            $checkIn = $objective->checkIns()->create([
                'week_number'            => $weekNumber,
                'check_in_date'          => now()->toDateString(),
                'user_id'                => auth()->id(),
                'main_blocker'           => $request->input('main_blocker'),
                'corrective_action'      => $request->input('corrective_action'),
                'actual_value_snapshot'  => null,
            ]);

            // 2. Aplica resultados manuales (ya validados: pertenecen al
            // Objective y su KPI NUNCA es automático — ver StoreCheckInRequest)
            // y recalcula cada KR afectado.
            foreach ($request->input('manual_results', []) as $row) {
                $kr = $objective->keyResults()->findOrFail($row['key_result_id']);
                $kr->update([
                    'current_value'         => $row['value'],
                    'last_manual_input_by'  => auth()->id(),
                    'last_manual_input_at'  => now(),
                ]);
                // Recalcula trayectoria/desviación/proyección con el valor recién
                // capturado y guarda el snapshot de la semana, marcado como
                // fuente manual_checkin — nunca pisa un KPI automático (ver
                // OkrSnapshotService::evaluateKeyResult(), preserva current_value
                // para manual/híbrido).
                $snapshotService->evaluateKeyResult($kr->fresh(), null, $checkIn->id);
            }

            // 3-4. Vuelve a leer TODOS los current_value YA actualizados (post
            // manual_results) y completa el snapshot del check-in con la llave
            // key_result_id (nunca kpi_id — dos KR podrían compartir KPI).
            $finalSnapshot = $objective->keyResults()->get()->mapWithKeys(fn ($kr) => [
                "kr_{$kr->id}" => ['key_result_id' => $kr->id, 'kpi_id' => $kr->kpi_id, 'value' => $kr->current_value],
            ])->all();
            $checkIn->update(['actual_value_snapshot' => $finalSnapshot]);

            // 5. Acción correctiva.
            if ($request->filled('corrective_action') && $request->filled('action_responsible_user_id') && $request->filled('action_due_date')) {
                $objective->correctiveActions()->create([
                    'okr_check_in_id'     => $checkIn->id,
                    'description'         => $request->input('corrective_action'),
                    'responsible_user_id' => $request->input('action_responsible_user_id'),
                    'due_date'            => $request->input('action_due_date'),
                ]);
            }
        });

        return back()->with('success', 'Check-in registrado.');
    }
}
