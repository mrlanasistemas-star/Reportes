<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Models\OkrAlert;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Módulo OKR (08-sep-2026) — alertas internas (sección 34 del pedido). */
class AlertController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('okr.view');

        $alerts = OkrAlert::query()
            ->with('objective:id,title,branch_id,employee_id')
            ->whereNull('read_at')
            ->latest()
            ->limit(50)
            ->get();

        return response()->json(['alerts' => $alerts]);
    }

    public function markRead(OkrAlert $alert): RedirectResponse
    {
        $this->authorize('okr.view');
        $alert->update(['read_at' => now(), 'read_by' => auth()->id()]);

        return back();
    }
}
