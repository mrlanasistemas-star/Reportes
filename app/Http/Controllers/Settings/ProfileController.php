<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración > Perfil: ver y editar nombre/correo del propio usuario y
 * eliminar su cuenta (con el candado del último administrador).
 */
class ProfileController extends Controller
{
    /**
     * Muestra la página de configuración del perfil del usuario.
     *
     * @param  Request  $request  Petición del usuario autenticado.
     * @return Response Página Inertia `settings/Profile` con `mustVerifyEmail` y el `status` flash de la sesión.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Actualiza la información del perfil del usuario.
     *
     * Escribe en `users`; si cambió el correo, borra `email_verified_at` para
     * exigir verificarlo de nuevo.
     *
     * @param  ProfileUpdateRequest  $request  Datos validados (nombre, correo).
     * @return RedirectResponse Redirección a `profile.edit`.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return to_route('profile.edit');
    }

    /**
     * Elimina la cuenta del usuario autenticado.
     *
     * Rechaza (error en `password`) si es el único administrador habilitado.
     * Si procede: cierra sesión, borra el registro `users`, invalida la sesión
     * y regenera el token CSRF.
     *
     * @param  ProfileDeleteRequest  $request  Petición validada (incluye confirmación de contraseña).
     * @return RedirectResponse Regreso con error, o redirección a '/'.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        // El único administrador habilitado no puede eliminar su propia cuenta: el
        // sistema se quedaría sin nadie que administre usuarios y roles (mismo
        // candado que bloquear/degradar al último administrador en Usuarios).
        if ($user->isAdmin() && $user->disabled_at === null
            && User::query()->where('role', User::ROLE_ADMIN)->whereNull('disabled_at')->whereKeyNot($user->id)->doesntExist()) {
            return back()->withErrors(['password' => 'No puedes eliminar tu cuenta: eres el único administrador habilitado. Asigna antes a otro administrador.']);
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
