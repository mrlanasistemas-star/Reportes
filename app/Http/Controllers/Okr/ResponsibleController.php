<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\OkrObjective;
use App\Models\User;
use App\Services\Okr\OkrEmployeeBranchResolver;
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
 * optó por la opción C del pedido: el responsable se crea SIN acceso y un
 * administrador lo habilita explícitamente cuando confirma la identidad de
 * la persona (enableAccess()).
 *
 * CORRECCIÓN 10-sep-2026 (punto 32 de la auditoría): la primera versión de
 * este fix seguía usando `email_verified_at` para representar "acceso
 * habilitado" — mezclaba VERIFICACIÓN DE CORREO con HABILITACIÓN DE ACCESO,
 * dos conceptos distintos. Ahora existe una columna propia
 * `users.access_enabled_at` para lo segundo; `email_verified_at` recupera su
 * significado real y NUNCA se fija artificialmente aquí.
 *
 * Nota técnica verificada: `User` NO implementa `MustVerifyEmail` (ver
 * app/Models/User.php, comentado), así que el middleware `verified` del
 * grupo de rutas es hoy un no-op para todos los usuarios — ninguna versión
 * de este flujo (ni antes ni ahora) bloqueaba realmente el login a nivel
 * framework. `access_enabled_at` es un estado administrativo/informativo
 * (Activo/Pendiente en la UI), no un control de acceso de Laravel.
 *
 * La contraseña temporal se muestra UNA SOLA VEZ al admin en la respuesta
 * (nunca se vuelve a poder consultar) — la persona debe usar "¿Olvidaste tu
 * contraseña?" para fijar la suya antes de poder entrar.
 */
class ResponsibleController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, OkrEmployeeBranchResolver $branchResolver): Response
    {
        $this->authorize('okr.admin');

        $usageCounts = OkrObjective::query()
            ->whereNotNull('responsible_user_id')
            ->selectRaw('responsible_user_id, count(*) as total')
            ->groupBy('responsible_user_id')
            ->pluck('total', 'responsible_user_id');

        $responsibles = User::query()->with('employee:id,full_name,is_active')->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'access_enabled_at', 'employee_id'])
            ->map(function ($u) use ($usageCounts, $branchResolver) {
                $currentBranchId = $u->employee_id ? $branchResolver->currentBranchIdFor($u->employee_id) : null;

                return [
                    'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role,
                    // Activo = el admin habilitó el acceso explícitamente. Pendiente =
                    // creado pero sin habilitar todavía. Campo propio — NUNCA
                    // email_verified_at (ver docblock de la clase).
                    'status' => $u->access_enabled_at !== null ? 'active' : 'pending',
                    'objectives_count' => (int) ($usageCounts[$u->id] ?? 0),
                    // 1/2 del cierre (05-oct-2026) — identidad persistente User↔Employee.
                    'employee_id' => $u->employee_id,
                    'employee_name' => $u->employee?->full_name,
                    'employee_is_active' => $u->employee?->is_active,
                    'current_branch' => $currentBranchId ? Branch::find($currentBranchId)?->name : null,
                ];
            });

        // Catálogo de Employees vinculables: activos, MÁS cualquiera que ya
        // esté vinculado a un User (aunque esté inactivo, para no ocultar un
        // vínculo histórico existente — 2 del cierre). linked_user_id deja
        // que el frontend excluya, por fila, los que ya pertenecen a OTRO
        // usuario, sin pedir al backend fila por fila.
        $employees = Employee::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereHas('user'))
            ->with('user:id,employee_id')
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'is_active'])
            ->map(fn ($e) => [
                'id' => $e->id, 'full_name' => $e->full_name, 'is_active' => $e->is_active,
                'linked_user_id' => $e->user?->id,
            ]);

        return Inertia::render('Okr/Responsibles', [
            'responsibles' => $responsibles,
            'employees' => $employees,
            // Se muestra UNA SOLA VEZ justo después de crear un responsable —
            // session()->pull() la lee Y la borra en el mismo golpe, así que
            // un refresh posterior de esta misma pantalla ya no la repite.
            // (Este repo no comparte `flash` de sesión a Inertia globalmente
            // — ver HandleInertiaRequests — por eso viaja como prop normal.)
            'temp_password' => session()->pull('temp_password'),
            // current_user_id/admin_count (cierre 17-sep-2026, ronda 4) — para que la UI
            // oculte por sí sola "cambiar rol" en tu propia fila y "quitar admin" cuando
            // eres el único admin, en vez de dejar que el usuario intente algo que el
            // backend rechazará igual (ver updateRole()) sin ningún mensaje visible (este
            // repo no comparte flash de sesión a Inertia globalmente).
            'current_user_id' => $request->user()->id,
            'admin_count' => $responsibles->where('role', 'admin')->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('okr.admin');

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
        ]);

        $tempPassword = Str::password(16);

        // D7 del cierre (17-sep-2026): esta pantalla NUNCA puede crear un admin —
        // antes aceptaba 'role' del request (Rule::in(['admin','colaborador'])),
        // así que cualquiera con acceso a "Responsables OKR" podía elevarse a
        // administrador desde aquí. El rol siempre es 'colaborador'; los admins se
        // administran fuera de OKR.
        User::query()->create([
            'name'              => $data['name'],
            'email'             => $data['email'],
            'password'          => Hash::make($tempPassword),
            'role'              => 'colaborador',
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
     *
     * 05-oct-2026 (acoplado a petición explícita del usuario): si tiene un
     * Employee vinculado, también lo reactiva (is_active=true) — "acceso
     * habilitado" y "colaborador activo" son el MISMO estado para este
     * sistema, nunca dos interruptores que puedan quedar desincronizados
     * (un acceso habilitado con el Employee todavía marcado de baja sería
     * un estado inconsistente).
     */
    public function enableAccess(User $user): RedirectResponse
    {
        $this->authorize('okr.admin');

        $user->forceFill(['access_enabled_at' => now()])->save();
        $user->employee?->update(['is_active' => true]);

        return back()->with('success', "Acceso habilitado para {$user->name}. Debe usar \"¿Olvidaste tu contraseña?\" para entrar la primera vez.");
    }

    /**
     * Revierte la habilitación de acceso (cierre 17-sep-2026, ronda 4) — antes
     * esta pantalla solo podía HABILITAR, nunca deshabilitar de nuevo. Sin
     * efecto sobre un admin (EnsureOkrAccessEnabled ya lo deja pasar siempre,
     * sin importar access_enabled_at) — solo aplica de verdad a colaboradores.
     *
     * 05-oct-2026: "quitar acceso" para un colaborador operativo SIGNIFICA
     * que causó baja — también marca su Employee vinculado como inactivo
     * (is_active=false), lo que a su vez lo saca de roster/reportes de
     * Reportería que ya filtran por empleados activos. Sin efecto si el
     * usuario no tiene Employee vinculado (ej. admin/gerencial puros).
     */
    public function disableAccess(User $user): RedirectResponse
    {
        $this->authorize('okr.admin');

        $user->forceFill(['access_enabled_at' => null])->save();
        $user->employee?->update(['is_active' => false]);

        return back()->with('success', "Acceso deshabilitado para {$user->name}.");
    }

    /**
     * Cambia el rol de un usuario (cierre 17-sep-2026, ronda 4) — antes esta
     * pantalla no permitía editar el rol de nadie después de creado. Dos
     * candados de seguridad, ambos para evitar quedarse sin ningún admin que
     * pueda entrar a arreglarlo:
     *   - nadie puede cambiar su PROPIO rol desde aquí (evita que un admin se
     *     autodegrade por error mientras está operando esta pantalla).
     *   - no se puede degradar al ÚLTIMO admin restante.
     */
    public function updateRole(User $user, Request $request): RedirectResponse
    {
        $this->authorize('okr.admin');

        $data = $request->validate([
            'role' => ['required', Rule::in(User::ROLES)],
        ]);

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'No puedes cambiar tu propio rol desde aquí.');
        }

        if ($data['role'] !== 'admin' && $user->role === 'admin') {
            $adminCount = User::query()->where('role', 'admin')->count();
            if ($adminCount <= 1) {
                return back()->with('error', 'No puedes quitar el único administrador restante del sistema.');
            }
        }

        $user->forceFill(['role' => $data['role']])->save();

        $roleLabels = ['admin' => 'Administrador', 'gerencial' => 'Gerencial', 'colaborador' => 'Colaborador'];

        return back()->with('success', "Rol de {$user->name} actualizado a " . ($roleLabels[$data['role']] ?? $data['role']) . '.');
    }

    /**
     * 1/2 del cierre (05-oct-2026) — vincula/desvincula el Employee real de
     * este User. Backend valida también (nunca solo el frontend): un
     * Employee no puede quedar vinculado a dos Users a la vez (además hay
     * UNIQUE a nivel BD, pero aquí se devuelve un mensaje claro en vez de un
     * 500 por violación de constraint).
     */
    public function linkEmployee(User $user, Request $request): RedirectResponse
    {
        $this->authorize('okr.admin');

        $data = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        if ($data['employee_id'] !== null) {
            $alreadyLinked = User::query()->where('employee_id', $data['employee_id'])->where('id', '!=', $user->id)->exists();
            if ($alreadyLinked) {
                return back()->with('error', 'Ese colaborador ya está vinculado a otro usuario.');
            }
        }

        $user->forceFill(['employee_id' => $data['employee_id']])->save();

        return back()->with('success', $data['employee_id'] !== null
            ? "Colaborador vinculado a {$user->name}."
            : "Vínculo de colaborador removido para {$user->name}.");
    }
}
