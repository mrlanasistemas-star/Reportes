<?php

namespace App\Http\Controllers;

use App\Models\EfectividadCobranzaRun;
use App\Services\Efectividad\EfectividadCobranzaRunPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notificaciones internas persistentes (canal `database` de Laravel) y
 * trabajos activos en segundo plano, para el indicador de la cabecera.
 *
 * Solo lee/modifica notificaciones del PROPIO usuario autenticado. Los
 * trabajos activos (Reportería financiera) solo se incluyen para roles con
 * acceso gerencial — un colaborador nunca los ve.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly EfectividadCobranzaRunPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = $user->notifications()
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'data' => $n->data,
                'read_at' => $n->read_at?->toISOString(),
                'created_at' => $n->created_at?->toISOString(),
            ]);

        $active = $user->hasManagerialAccess()
            ? EfectividadCobranzaRun::query()
                ->with(['period', 'branch', 'createdBy'])
                ->requestedBy($user->id)
                ->active()
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (EfectividadCobranzaRun $r) => $this->presenter->summary($r))
                ->values()
            : collect();

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
            'items' => $items,
            'active' => $active,
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
