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
 * Módulo OKR (08-sep-2026) — check-in semanal (sección 29 del pedido). El
 * "resultado actual" SIEMPRE viene del KPI automático cuando existe — el
 * usuario nunca lo sobrescribe silenciosamente (solo agrega contexto
 * cualitativo: bloqueo/acción correctiva). Único por (objective, semana,
 * usuario) — UNIQUE en la migración evita doble captura.
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

        DB::transaction(function () use ($request, $objective, $weekNumber) {
            $snapshot = $objective->keyResults()->get()->mapWithKeys(fn ($kr) => [$kr->kpi_id => $kr->current_value])->all();

            $checkIn = $objective->checkIns()->create([
                'week_number'            => $weekNumber,
                'check_in_date'          => now()->toDateString(),
                'user_id'                => auth()->id(),
                'main_blocker'           => $request->input('main_blocker'),
                'corrective_action'      => $request->input('corrective_action'),
                'actual_value_snapshot'  => $snapshot,
            ]);

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
