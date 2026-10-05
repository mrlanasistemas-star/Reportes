<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Models\OkrAlert;
use App\Services\Okr\OkrObjectiveVisibilityService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Módulo OKR (08-sep-2026) — alertas internas (sección 34 del pedido). */
class AlertController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, OkrObjectiveVisibilityService $visibility): JsonResponse
    {
        $this->authorize('okr.view');

        // 8 del cierre (05-oct-2026) — BUG CRÍTICO: devolvía TODAS las
        // alertas del sistema sin importar de quién era el Objective. Mismo
        // scope central que Dashboard/History/Lookup/Policy.
        $alerts = OkrAlert::query()
            ->with('objective:id,title,branch_id,employee_id')
            ->whereHas('objective', fn ($q) => $visibility->applyScope($q, $request->user()))
            ->whereNull('read_at')
            ->latest()
            ->limit(50)
            ->get();

        return response()->json(['alerts' => $alerts]);
    }

    public function markRead(OkrAlert $alert): RedirectResponse
    {
        // 9 del cierre (05-oct-2026) — BUG: solo validaba el gate global
        // `okr.view` (true para cualquier autenticado) y modificaba la
        // alerta directo — un colaborador podía marcar como leída la alerta
        // de OTRO colaborador. Ahora autoriza el Objective REAL asociado
        // ANTES de tocar nada (misma Policy que el resto del módulo).
        $this->authorize('view', $alert->objective);

        $alert->update(['read_at' => now(), 'read_by' => auth()->id()]);

        return back();
    }
}
