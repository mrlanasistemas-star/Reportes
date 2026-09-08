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
 * Módulo OKR — catálogo de "Responsables" (08-sep-2026, pedido del usuario en
 * la misma sesión: "que aparte pueda agregar más o administrarlos"). Un
 * responsable ES un `User` de la tabla real (responsible_user_id ya apunta a
 * `users`) — esto NO crea una entidad paralela, solo da una pantalla cómoda
 * para verlos y agregar uno nuevo sin pasar por un flujo de registro
 * completo. Solo administradores (users.role='admin') pueden agregar/ver esta
 * pantalla de administración — ver OkrServiceProvider.
 *
 * LIMITACIÓN CONOCIDA: no hay envío de correo de invitación configurado en
 * este repo — el usuario nuevo se crea con una contraseña aleatoria y debe
 * usar "¿Olvidaste tu contraseña?" en el login para establecer la suya.
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

        $responsibles = User::query()->orderBy('name')->get(['id', 'name', 'email', 'role'])->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role,
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

        User::query()->create([
            'name'              => $data['name'],
            'email'             => $data['email'],
            'password'          => Hash::make(Str::random(32)),
            'role'              => $data['role'],
            'email_verified_at' => now(),
        ]);

        return back()->with('success', 'Responsable agregado. Debe usar "¿Olvidaste tu contraseña?" en el login para entrar la primera vez.');
    }
}
