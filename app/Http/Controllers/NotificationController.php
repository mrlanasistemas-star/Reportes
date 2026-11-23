<?php

namespace App\Http\Controllers;

use App\Models\EfectividadCobranzaRun;
use App\Services\Efectividad\EfectividadCobranzaRunPresenter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
    /**
     * @param  EfectividadCobranzaRunPresenter  $presenter  Da formato resumido a las corridas de Efectividad de cobranza.
     */
    public function __construct(private readonly EfectividadCobranzaRunPresenter $presenter) {}

    /**
     * Devuelve las últimas 15 notificaciones del usuario, su conteo de no
     * leídas y, si tiene acceso gerencial, hasta 10 corridas activas de
     * Efectividad de cobranza que él mismo solicitó.
     *
     * Solo lectura.
     *
     * @param  Request  $request  Petición del usuario autenticado.
     * @return JsonResponse {unread_count, items: [{id, data, read_at, created_at}], active: [resumen de corrida]}.
     */
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

    /**
     * Marca como leída una notificación del propio usuario.
     *
     * Escribe `notifications.read_at`. Al buscar dentro de las notificaciones
     * del usuario, impide marcar notificaciones ajenas.
     *
     * @param  Request  $request  Petición del usuario autenticado.
     * @param  string  $notification  UUID de la notificación.
     * @return JsonResponse {ok: true}.
     *
     * @throws ModelNotFoundException 404 si no existe o no es del usuario.
     */
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return response()->json(['ok' => true]);
    }

    /**
     * Marca como leídas todas las notificaciones pendientes del usuario.
     *
     * Actualización masiva de `read_at` en BD.
     *
     * @param  Request  $request  Petición del usuario autenticado.
     * @return JsonResponse {ok: true}.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
