<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Models\OkrAlert;
use App\Services\Okr\OkrObjectiveVisibilityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Módulo OKR (08-sep-2026) — alertas internas (sección 34 del pedido).
 *
 * Expone las alertas no leídas de los OKR que el usuario puede ver (campana
 * del módulo) y permite marcarlas como leídas. La visibilidad se delega en
 * OkrObjectiveVisibilityService y en la Policy de OkrObjective.
 */
class AlertController extends Controller
{
    use AuthorizesRequests;

    /**
     * Lista (JSON) las últimas 50 alertas sin leer cuyos Objectives están
     * dentro del alcance de visibilidad del usuario autenticado.
     *
     * @param  Request  $request  Petición del usuario autenticado (define el alcance).
     * @param  OkrObjectiveVisibilityService  $visibility  Scope central de visibilidad OKR.
     * @return JsonResponse `{ alerts: OkrAlert[] }` con el objective resumido (id, título, sucursal, colaborador).
     *
     * @throws AuthorizationException Si no tiene el gate `okr.view`.
     */
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

    /**
     * Marca una alerta como leída por el usuario actual.
     *
     * Escribe en BD `read_at` y `read_by` de la alerta. Antes autoriza
     * `view` sobre el Objective dueño de la alerta para impedir que un
     * colaborador marque alertas ajenas.
     *
     * @param  OkrAlert  $alert  Alerta resuelta por route model binding.
     * @return RedirectResponse Regresa a la página anterior.
     *
     * @throws AuthorizationException Si no puede ver el Objective.
     */
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
