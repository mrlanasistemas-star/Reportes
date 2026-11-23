<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\UserNotificationPreference;
use App\Notifications\Reporteria\PushTestNotification;
use App\Notifications\Reporteria\ReporteriaProcessNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración > Notificaciones (23-nov-2026): preferencias de correo y push
 * por tipo de evento, y alta/baja de la suscripción Web Push de ESTE
 * dispositivo. Solo opera sobre el propio usuario autenticado.
 */
class NotificationSettingsController extends Controller
{
    /** Más dispositivos que esto por usuario no tiene sentido; se descartan los más viejos. */
    private const MAX_DEVICES = 10;

    /**
     * Muestra la página de preferencias de notificaciones.
     *
     * @param  Request  $request  Petición del usuario autenticado.
     * @return Response Página Inertia `settings/Notifications` con la matriz de
     *                  preferencias por tipo y el estado de push (configurado en
     *                  el servidor, clave pública VAPID, dispositivos suscritos).
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Notifications', [
            'preferences' => UserNotificationPreference::matrixFor($user),
            'push' => [
                'configured' => ReporteriaProcessNotification::pushIsConfigured(),
                'public_key' => config('webpush.vapid.public_key'),
                'devices_count' => $user->pushSubscriptions()->count(),
            ],
        ]);
    }

    /**
     * Guarda las preferencias de correo/push por tipo de evento.
     *
     * Hace updateOrCreate en `user_notification_preferences` por (user_id, type).
     *
     * @param  Request  $request  `preferences`: lista de {type ∈ UserNotificationPreference::TYPES, mail: bool, push: bool}.
     * @return RedirectResponse Regreso con mensaje de éxito.
     *
     * @throws ValidationException Si los datos no son válidos.
     */
    public function updatePreferences(Request $request): RedirectResponse
    {
        $types = array_keys(UserNotificationPreference::TYPES);

        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.type' => ['required', 'string', Rule::in($types)],
            'preferences.*.mail' => ['required', 'boolean'],
            'preferences.*.push' => ['required', 'boolean'],
        ], [
            'preferences.*.type.in' => 'Tipo de notificación no válido.',
        ]);

        $user = $request->user();

        foreach ($validated['preferences'] as $row) {
            UserNotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->id, 'type' => $row['type']],
                ['mail_enabled' => (bool) $row['mail'], 'push_enabled' => (bool) $row['push']],
            );
        }

        return back()->with('success', 'Preferencias de notificación guardadas.');
    }

    /**
     * Registra (o actualiza) la suscripción Web Push de este dispositivo.
     *
     * Guarda en la tabla de suscripciones push del usuario y, si supera
     * MAX_DEVICES, borra las suscripciones más antiguas.
     *
     * @param  Request  $request  `endpoint`, `keys.p256dh`, `keys.auth` y `content_encoding` opcional (aesgcm|aes128gcm, por defecto aes128gcm).
     * @return JsonResponse {ok, devices_count, message}.
     *
     * @throws ValidationException Si la suscripción no es válida.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:2048'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', Rule::in(['aesgcm', 'aes128gcm'])],
        ], [
            'endpoint.required' => 'El navegador no envió la suscripción.',
            'endpoint.url' => 'La suscripción del navegador no es válida.',
            'keys.p256dh.required' => 'La suscripción del navegador está incompleta.',
            'keys.auth.required' => 'La suscripción del navegador está incompleta.',
        ]);

        $user = $request->user();

        $user->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['content_encoding'] ?? 'aes128gcm',
        );

        $extra = $user->pushSubscriptions()->orderByDesc('id')->pluck('id')->slice(self::MAX_DEVICES)->values();
        if ($extra->isNotEmpty()) {
            $user->pushSubscriptions()->whereIn('id', $extra)->delete();
        }

        return response()->json([
            'ok' => true,
            'devices_count' => $user->pushSubscriptions()->count(),
            'message' => 'Notificaciones activadas en este dispositivo.',
        ]);
    }

    /**
     * Elimina la suscripción Web Push del usuario con ese endpoint.
     *
     * @param  Request  $request  `endpoint` de la suscripción del navegador.
     * @return JsonResponse {ok, devices_count, message}.
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);

        $user = $request->user();
        $user->deletePushSubscription($validated['endpoint']);

        return response()->json([
            'ok' => true,
            'devices_count' => $user->pushSubscriptions()->count(),
            'message' => 'Notificaciones desactivadas en este dispositivo.',
        ]);
    }

    /**
     * Envía de inmediato (sin cola, notifyNow) una notificación push de prueba al usuario.
     *
     * Si el envío falla, registra un warning en el log y responde 500.
     *
     * @param  Request  $request  Petición del usuario autenticado.
     * @return JsonResponse {ok, message}; 422 si el servidor no tiene claves VAPID o el usuario no tiene dispositivos.
     */
    public function test(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! ReporteriaProcessNotification::pushIsConfigured()) {
            return response()->json(['ok' => false, 'message' => 'El servidor aún no tiene configuradas las claves de notificaciones push.'], 422);
        }

        if (! $user->pushSubscriptions()->exists()) {
            return response()->json(['ok' => false, 'message' => 'Primero activa las notificaciones en este dispositivo.'], 422);
        }

        try {
            $user->notifyNow(new PushTestNotification);
        } catch (\Throwable $e) {
            Log::warning('Notificaciones: falló el envío de prueba.', ['user_id' => $user->id, 'exception' => get_class($e), 'message' => $e->getMessage()]);

            return response()->json(['ok' => false, 'message' => 'No se pudo enviar la notificación de prueba. Intenta de nuevo en unos minutos.'], 500);
        }

        return response()->json(['ok' => true, 'message' => 'Notificación de prueba enviada. Debe aparecer en unos segundos.']);
    }
}
