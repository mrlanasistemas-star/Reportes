<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

/**
 * Configuración > Seguridad: cambio de contraseña y estado de la
 * autenticación de dos factores (Laravel Fortify) del propio usuario.
 */
class SecurityController extends Controller implements HasMiddleware
{
    /**
     * Middleware del controlador.
     *
     * Si Fortify permite gestionar 2FA con la opción `confirmPassword`, la
     * página `edit` exige confirmar la contraseña (`password.confirm`).
     *
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return Features::canManageTwoFactorAuthentication()
            && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword')
                ? [new Middleware('password.confirm', only: ['edit'])]
                : [];
    }

    /**
     * Muestra la página de seguridad del usuario.
     *
     * Si 2FA está habilitado en Fortify, valida el estado del flujo de 2FA
     * (ensureStateIsValid) e indica si el usuario ya lo tiene activo y si se
     * requiere confirmación del código.
     *
     * @param  TwoFactorAuthenticationRequest  $request  Petición del usuario autenticado.
     * @return Response Página Inertia `settings/Security`.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/Security', $props);
    }

    /**
     * Actualiza la contraseña del usuario.
     *
     * Escribe la nueva contraseña (hasheada por el cast del modelo), rota el
     * `remember_token` y, con sesiones en BD, borra las demás sesiones del
     * usuario conservando la actual.
     *
     * @param  PasswordUpdateRequest  $request  Contraseña actual y nueva validadas.
     * @return RedirectResponse Regreso a la página anterior.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->update([
            'password' => $request->password,
        ]);

        // Fase 6.5 — cambiar la contraseña cierra las DEMÁS sesiones abiertas (otro
        // equipo/navegador con la contraseña anterior) y anula los "recordarme"; la
        // sesión actual sigue activa. Antes solo se guardaba la contraseña nueva.
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        return back();
    }
}
