<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Models\OkrObjective;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Módulo OKR — catálogo de "Responsables". Un responsable ES un `User` de la
 * tabla real (responsible_user_id ya apunta a `users`) — esto NO crea una
 * entidad paralela, solo da una pantalla cómoda para verlos y agregar uno
 * nuevo sin pasar por un flujo de registro completo. Solo administradores
 * (users.role='admin') pueden entrar aquí — ver OkrServiceProvider.
 *
 * CORRECCIÓN 09-sep-2026 (punto 10 de la auditoría): antes se ponía
 * `email_verified_at = now()` artificialmente al crear — una mentira (nadie
 * verificó nada). Este repo no tiene envío de correo configurado, así que se
 * optó por la opción C del pedido: el responsable se crea SIN acceso
 * (`email_verified_at = null`, real) y un administrador lo habilita
 * explícitamente cuando confirma la identidad de la persona (enableAccess()).
 * La contraseña temporal se muestra UNA SOLA VEZ al admin en la respuesta
 * (nunca se vuelve a poder consultar) — la persona debe usar "¿Olvidaste tu
 * contraseña?" para fijar la suya antes de poder entrar.
 */
class ResponsibleController extends Controller
{
    use AuthorizesRequests;

    public function index(): Response
    {
        $this->authorize('okr.admin');

        $usageCounts = OkrObjective::query()
            ->whereNotNull('responsible_user_id')
            ->selectRaw('responsible_user_id, count(*) as total')
            ->groupBy('responsible_user_id')
            ->pluck('total', 'responsible_user_id');

        $responsibles = User::query()->orderBy('name')->get(['id', 'name', 'email', 'role', 'email_verified_at'])->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role,
            // Activo = ya tiene acceso habilitado (email_verified_at real, nunca
            // fingido). Pendiente = creado pero sin habilitar todavía.
            'status' => $u->email_verified_at !== null ? 'active' : 'pending',
            'objectives_count' => (int) ($usageCounts[$u->id] ?? 0),
        ]);

        return Inertia::render('Okr/Responsibles', ['responsibles' => $responsibles]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('okr.admin');

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
            'role'  => ['required', Rule::in(['admin', 'colaborador'])],
        ]);

        $tempPassword = Str::password(16);

        User::query()->create([
            'name'              => $data['name'],
            'email'             => $data['email'],
            'password'          => Hash::make($tempPassword),
            'role'              => $data['role'],
            'email_verified_at' => null, // real: nadie ha verificado nada todavía
        ]);

        return back()
            ->with('success', 'Responsable agregado — sin acceso todavía.')
            ->with('temp_password', $tempPassword);
    }

    /**
     * Habilita el acceso de un responsable ya creado — decisión EXPLÍCITA del
     * administrador (nunca automática), después de confirmar la identidad de
     * la persona fuera de este sistema.
     */
    public function enableAccess(User $user): RedirectResponse
    {
        $this->authorize('okr.admin');

        $user->forceFill(['email_verified_at' => now()])->save();

        return back()->with('success', "Acceso habilitado para {$user->name}. Debe usar \"¿Olvidaste tu contraseña?\" para entrar la primera vez.");
    }
}
