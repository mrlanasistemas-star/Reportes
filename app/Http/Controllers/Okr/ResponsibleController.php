<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\OkrObjective;
use App\Models\User;
use App\Services\Access\AccessDelegationGuard;
use App\Services\Access\ActionPermissionService;
use App\Services\Access\ModuleAccessService;
use App\Services\Access\RoleCatalogService;
use App\Services\Access\UserAccessAuditLogger;
use App\Services\Access\UserInvitationService;
use App\Services\Okr\OkrEmployeeBranchResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Módulo USUARIOS (prioridad 8/10) — antes "Responsables OKR" / "Usuarios y
 * accesos" dentro de /okr. Ahora vive en /usuarios (routes/web.php) con su
 * propio módulo (`module:usuarios`) y un permiso por acción
 * (`permission:usuarios,*`). Las rutas /okr/responsibles/* se conservan como
 * alias con los MISMOS middlewares (routes/okr.php).
 *
 * Reglas que NO cambian:
 *   - email_verified_at nunca se finge (auditoría puntos 30/32).
 *   - access_enabled_at = activación administrativa del acceso (OKR la exige,
 *     ver EnsureOkrAccessEnabled); disabled_at = cuenta bloqueada (no puede
 *     iniciar sesión, ver EnsureUserNotDisabled).
 *   - Contraseñas: siempre con hash; la generada se muestra UNA sola vez.
 *
 * CORRECCIÓN prioridad 2 — ACCESO ≠ SITUACIÓN LABORAL: enableAccess()/
 * disableAccess() modificaban Employee.is_active (alta/baja del colaborador)
 * — administrar el acceso de un usuario daba de baja a la persona en nómina,
 * roster y reportes. Ahora solo cambian columnas de `users`; employees,
 * nómina e históricos nunca se tocan desde aquí.
 */
class ResponsibleController extends Controller
{
    public function __construct(
        private readonly ActionPermissionService $actions,
        private readonly AccessDelegationGuard $guard,
        private readonly UserAccessAuditLogger $audit,
        private readonly UserInvitationService $invitations,
    ) {
    }

    public function index(Request $request, OkrEmployeeBranchResolver $branchResolver, ModuleAccessService $moduleAccess): Response
    {
        $actor = $request->user();
        $catalog = app(RoleCatalogService::class);

        $usageCounts = OkrObjective::query()
            ->whereNotNull('responsible_user_id')
            ->selectRaw('responsible_user_id, count(*) as total')
            ->groupBy('responsible_user_id')
            ->pluck('total', 'responsible_user_id');

        $branchNames = Branch::query()->pluck('name', 'id');
        $enabledAdmins = User::query()->where('role', User::ROLE_ADMIN)->whereNull('disabled_at')->count();

        $users = User::query()->with('employee:id,full_name,is_active')->orderBy('name')->get()
            ->map(function (User $u) use ($usageCounts, $branchResolver, $branchNames, $actor, $catalog, $enabledAdmins) {
                $branchId = $u->employee_id ? $branchResolver->currentBranchIdFor($u->employee_id) : null;
                $manageable = $u->id !== $actor->id && $this->guard->canManageUser($actor, $u);
                $isLastAdmin = $u->isAdmin() && $u->disabled_at === null && $enabledAdmins <= 1;

                return [
                    'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                    'role' => $u->role, 'role_name' => $catalog->label($u->role),
                    // Estado de ACCESO (nunca situación laboral): bloqueado > pendiente > habilitado.
                    'access' => $u->disabled_at !== null ? 'blocked' : ($u->access_enabled_at !== null ? 'enabled' : 'pending'),
                    'access_enabled_at' => $u->access_enabled_at?->toDateTimeString(),
                    'disabled_at' => $u->disabled_at?->toDateTimeString(),
                    'last_login_at' => $u->last_login_at?->toDateTimeString(),
                    'created_at' => $u->created_at?->toDateTimeString(),
                    'invitation' => [
                        'status' => UserInvitationService::statusOf($u),
                        'sent_at' => $u->welcome_email_sent_at?->toDateTimeString(),
                        'error' => $u->welcome_email_error,
                        'attempts' => (int) ($u->welcome_email_attempts ?? 0),
                        'last_attempt_at' => $u->welcome_email_last_attempt_at?->toDateTimeString(),
                    ],
                    'objectives_count' => (int) ($usageCounts[$u->id] ?? 0),
                    'employee_id' => $u->employee_id,
                    'employee_name' => $u->employee?->full_name,
                    'employee_is_active' => $u->employee?->is_active,
                    'current_branch' => $branchId ? ($branchNames[$branchId] ?? null) : null,
                    'is_self' => $u->id === $actor->id,
                    'is_last_admin' => $isLastAdmin,
                    // URLs generadas por el BACKEND (route()): respetan el prefijo/subcarpeta
                    // real del servidor — un '/okr/...' fijo en el frontend daba 404 si la app
                    // no está en la raíz del dominio (ver prioridad 10, botón reenviar).
                    'urls' => [
                        'enable_access' => route('usuarios.enable-access', $u),
                        'disable_access' => route('usuarios.disable-access', $u),
                        'toggle_disabled' => route('usuarios.toggle-disabled', $u),
                        'reset_password' => route('usuarios.reset-password', $u),
                        'resend_invitation' => route('usuarios.resend-invitation', $u),
                        'update_role' => route('usuarios.update-role', $u),
                        'link_employee' => route('usuarios.link-employee', $u),
                    ],
                    'can' => [
                        'manage_access' => $manageable && ! $isLastAdmin && $this->actions->canAction($actor, 'usuarios', 'gestionar_acceso'),
                        'reset_password' => $manageable && $this->actions->canAction($actor, 'usuarios', 'restablecer'),
                        'invite' => $this->guard->canManageUser($actor, $u) && $this->actions->canAction($actor, 'usuarios', 'invitar'),
                        'change_role' => $manageable && ! ($u->isAdmin() && $isLastAdmin) && $this->actions->canAction($actor, 'usuarios', 'asignar_rol'),
                        'link_employee' => $this->guard->canManageUser($actor, $u) && $this->actions->canAction($actor, 'usuarios', 'editar'),
                    ],
                ];
            });

        // Colaboradores vinculables: activos, más cualquiera ya vinculado (aunque esté
        // inactivo) para no ocultar un vínculo histórico existente.
        $employees = Employee::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereHas('user'))
            ->with('user:id,employee_id')
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'is_active'])
            ->map(fn ($e) => ['id' => $e->id, 'full_name' => $e->full_name, 'is_active' => $e->is_active, 'linked_user_id' => $e->user?->id]);

        $roles = collect($catalog->all())->map(fn ($r) => [
            'key' => $r['key'], 'name' => $r['name'], 'description' => $r['description'], 'base_level' => $r['base_level'], 'is_system' => $r['is_system'],
            'modules_count' => $r['key'] === User::ROLE_ADMIN ? count(ModuleAccessService::MODULES) : count($moduleAccess->matrix()[$r['key']] ?? []),
            'assignable' => $r['key'] !== User::ROLE_ADMIN && $this->guard->canGrantRole($actor, $r['key']),
            'grantable' => $this->guard->canGrantRole($actor, $r['key']),
        ])->values()->all();

        return Inertia::render('usuarios/Index', [
            'users' => $users,
            'employees' => $employees,
            'roles' => $roles,
            'audit' => $this->audit->recent(),
            'current_user_id' => $actor->id,
            'enabled_admin_count' => $enabledAdmins,
            'invitation_expires_minutes' => $this->invitations->expiresInMinutes(),
            'store_url' => route('usuarios.store'),
            'roles_url' => $moduleAccess->allows($actor, 'roles') ? route('roles.index') : null,
            'can' => [
                'create' => $this->actions->canAction($actor, 'usuarios', 'crear'),
                'invite' => $this->actions->canAction($actor, 'usuarios', 'invitar'),
                'manage_access' => $this->actions->canAction($actor, 'usuarios', 'gestionar_acceso'),
            ],
            // Se muestran UNA SOLA VEZ (session()->pull() las lee y las borra).
            'temp_password' => session()->pull('temp_password'),
            'reset_password' => session()->pull('reset_password'),
            'invitation_result' => session()->pull('invitation_result'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
            // invite = el usuario establece su contraseña desde el correo;
            // auto = se genera y se muestra una sola vez; manual = la escribe quien da el alta.
            'password_mode' => ['nullable', Rule::in(['invite', 'auto', 'manual'])],
            'password' => ['required_if:password_mode,manual', 'nullable', 'string', 'min:8', 'max:72', 'confirmed'],
            // Nunca Administrador desde aquí (D7): se asigna después, desde la tabla.
            'role' => ['nullable', 'string', Rule::in(app(RoleCatalogService::class)->configurableKeys())],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id', Rule::unique('users', 'employee_id')],
            'enable_access' => ['nullable', 'boolean'],
            'send_invitation' => ['nullable', 'boolean'],
        ], [
            'role.in' => 'Elige un rol válido.',
            'email.unique' => 'Ya existe un usuario con ese correo.',
            'employee_id.unique' => 'Ese colaborador ya está vinculado a otro usuario.',
            'password.required_if' => 'Escribe la contraseña o elige otra opción.',
            'password.confirmed' => 'La confirmación no coincide con la contraseña.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        $role = $data['role'] ?? User::ROLE_COLABORADOR;
        abort_unless($this->guard->canGrantRole($actor, $role), 403, 'No puedes asignar un rol con más permisos que el tuyo.');

        $mode = $data['password_mode'] ?? 'auto';
        $tempPassword = $mode === 'auto' ? Str::password(16) : null;
        // invite: contraseña aleatoria que nadie conoce — la persona fija la suya con el enlace.
        $finalPassword = match ($mode) {
            'manual' => $data['password'],
            'auto' => $tempPassword,
            default => Str::password(40),
        };
        $sendInvitation = $mode === 'invite' || ($data['send_invitation'] ?? true);
        if ($sendInvitation && ! $this->actions->canAction($actor, 'usuarios', 'invitar')) {
            abort_if($mode === 'invite', 403, 'Tu rol no permite enviar invitaciones.');
            $sendInvitation = false;
        }

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($finalPassword),
            'role' => $role,
            'employee_id' => $data['employee_id'] ?? null,
        ]);
        $user->forceFill([
            'email_verified_at' => null, // real: nadie ha verificado nada todavía
            'access_enabled_at' => ($data['enable_access'] ?? false) && $this->actions->canAction($actor, 'usuarios', 'gestionar_acceso') ? now() : null,
            'welcome_email_status' => $sendInvitation ? UserInvitationService::STATUS_PENDING : null,
        ])->save();

        $this->audit->log($actor, UserAccessAuditLogger::TYPE_USER, $user->id, 'user_created', 'role', null, $role, match ($mode) {
            'manual' => 'Contraseña manual', 'auto' => 'Contraseña generada', default => 'Por invitación',
        });

        $response = back()->with('success', "Usuario {$user->name} creado.");

        if ($sendInvitation) {
            $result = $this->invitations->send($user);
            $this->audit->log($actor, UserAccessAuditLogger::TYPE_USER, $user->id, $result['sent'] ? 'invitation_accepted' : 'invitation_failed');
            $response->with('invitation_result', $result + ['email' => $user->email]);
        }

        return $tempPassword !== null
            ? $response->with('temp_password', ['name' => $user->name, 'email' => $user->email, 'password' => $tempPassword])
            : $response;
    }

    /** Reintenta la invitación sin recrear al usuario ni cambiar su contraseña. */
    public function resendWelcomeEmail(Request $request, User $user): RedirectResponse|JsonResponse
    {
        abort_unless($this->guard->canManageUser($request->user(), $user), 403, 'Solo un administrador puede reenviar la invitación de otro administrador.');

        $result = $this->invitations->send($user);
        $this->audit->log($request->user(), UserAccessAuditLogger::TYPE_USER, $user->id, $result['sent'] ? 'invitation_accepted' : 'invitation_failed');

        if ($request->expectsJson()) {
            $fresh = $user->fresh();

            return response()->json([
                'sent' => $result['sent'],
                'message' => $result['message'],
                'invitation' => [
                    'status' => UserInvitationService::statusOf($fresh),
                    'sent_at' => $fresh->welcome_email_sent_at?->toDateTimeString(),
                    'error' => $fresh->welcome_email_error,
                    'attempts' => (int) $fresh->welcome_email_attempts,
                    'last_attempt_at' => $fresh->welcome_email_last_attempt_at?->toDateTimeString(),
                ],
            ], $result['sent'] ? 200 : 422);
        }

        return back()->with($result['sent'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Activa el acceso — decisión EXPLÍCITA de quien administra usuarios.
     * Solo cambia users.access_enabled_at; NUNCA el estado laboral del colaborador.
     */
    public function enableAccess(Request $request, User $user): RedirectResponse
    {
        $this->assertManageable($request, $user);

        $user->forceFill(['access_enabled_at' => now()])->save();
        $this->audit->log($request->user(), UserAccessAuditLogger::TYPE_USER, $user->id, 'access_enabled');

        return back()->with('success', "Acceso activado para {$user->name}.");
    }

    /**
     * Revoca la activación (vuelve a "pendiente de activación"): deja de poder
     * entrar al módulo OKR hasta que se active de nuevo. No toca employees.
     */
    public function disableAccess(Request $request, User $user): RedirectResponse
    {
        $this->assertManageable($request, $user);

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'No puedes revocar tu propio acceso.');
        }

        $user->forceFill(['access_enabled_at' => null])->save();
        $this->audit->log($request->user(), UserAccessAuditLogger::TYPE_USER, $user->id, 'access_revoked');

        return back()->with('success', "Se revocó la activación del acceso de {$user->name}. Su información de colaborador no cambió.");
    }

    /**
     * Restablece la contraseña de otro usuario: generada (se muestra UNA vez) o
     * manual (con confirmación). Siempre con hash; cierra sus sesiones abiertas.
     */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->assertManageable($request, $user);

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'Tu propia contraseña se cambia en Configuración › Seguridad.');
        }

        $data = $request->validate([
            'password_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'password' => ['required_if:password_mode,manual', 'nullable', 'string', 'min:8', 'max:72', 'confirmed'],
        ], [
            'password.required_if' => 'Escribe la nueva contraseña.',
            'password.confirmed' => 'La confirmación no coincide con la contraseña.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        $manual = ($data['password_mode'] ?? 'auto') === 'manual';
        $password = $manual ? $data['password'] : Str::password(14, symbols: false);

        $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
        $this->closeSessions($user);
        $this->audit->log($request->user(), UserAccessAuditLogger::TYPE_USER, $user->id, 'password_reset', null, null, null, $manual ? 'Contraseña manual' : 'Contraseña generada');

        if ($manual) {
            return back()->with('success', "Contraseña de {$user->name} actualizada. Sus sesiones abiertas se cerraron.");
        }

        return back()->with('reset_password', ['name' => $user->name, 'email' => $user->email, 'password' => $password]);
    }

    /**
     * Bloquea / desbloquea la CUENTA (`disabled_at`): no puede iniciar sesión en
     * ningún módulo. Candados: no a ti mismo y nunca al último administrador habilitado.
     */
    public function toggleDisabled(Request $request, User $user): RedirectResponse
    {
        $this->assertManageable($request, $user);

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'No puedes bloquear tu propia cuenta.');
        }

        if ($user->disabled_at === null) {
            if ($user->isAdmin() && User::query()->where('role', User::ROLE_ADMIN)->whereNull('disabled_at')->count() <= 1) {
                return back()->with('error', 'No puedes bloquear al único administrador habilitado: el sistema se quedaría sin nadie que lo administre.');
            }
            $user->forceFill(['disabled_at' => now(), 'remember_token' => Str::random(60)])->save();
            $this->closeSessions($user);
            $this->audit->log($request->user(), UserAccessAuditLogger::TYPE_USER, $user->id, 'account_blocked');

            return back()->with('success', "Cuenta de {$user->name} bloqueada. Sus sesiones abiertas se cerraron.");
        }

        $user->forceFill(['disabled_at' => null])->save();
        $this->audit->log($request->user(), UserAccessAuditLogger::TYPE_USER, $user->id, 'account_unblocked');

        return back()->with('success', "Cuenta de {$user->name} desbloqueada.");
    }

    /**
     * Cambia el rol de otro usuario. Candados: nunca el propio; nunca quitar al
     * último administrador habilitado; quien no es admin no asigna Administrador
     * ni un rol con más permisos que el suyo.
     */
    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $catalog = app(RoleCatalogService::class);
        $data = $request->validate([
            'role' => ['required', Rule::in($catalog->keys())],
        ], ['role.in' => 'Elige un rol válido.']);

        $this->assertManageable($request, $user);

        if ($user->id === $request->user()->id) {
            return back()->with('error', 'No puedes cambiar tu propio rol desde aquí.');
        }

        abort_unless($this->guard->canGrantRole($request->user(), $data['role']), 403, 'No puedes asignar un rol con más permisos que el tuyo.');

        if ($data['role'] !== User::ROLE_ADMIN && $user->isAdmin()) {
            $otherEnabledAdmins = User::query()->where('role', User::ROLE_ADMIN)->whereNull('disabled_at')->whereKeyNot($user->id)->count();
            if ($otherEnabledAdmins < 1) {
                return back()->with('error', 'No puedes quitar el único administrador habilitado del sistema.');
            }
        }

        $old = $user->role;
        $user->forceFill(['role' => $data['role']])->save();
        $this->audit->log($request->user(), UserAccessAuditLogger::TYPE_USER, $user->id, 'role_changed', 'role', $catalog->label($old), $catalog->label($data['role']));

        return back()->with('success', "Rol de {$user->name} actualizado a {$catalog->label($data['role'])}.");
    }

    /**
     * Vincula/desvincula el colaborador real de este usuario. Un colaborador no
     * puede quedar ligado a dos usuarios (también hay UNIQUE en BD).
     */
    public function linkEmployee(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        abort_unless($this->guard->canManageUser($request->user(), $user), 403, 'Solo un administrador puede modificar a otro administrador.');

        if ($data['employee_id'] !== null) {
            $alreadyLinked = User::query()->where('employee_id', $data['employee_id'])->where('id', '!=', $user->id)->exists();
            if ($alreadyLinked) {
                return back()->with('error', 'Ese colaborador ya está vinculado a otro usuario.');
            }
        }

        $old = $user->employee?->full_name;
        $user->forceFill(['employee_id' => $data['employee_id']])->save();
        $this->audit->log($request->user(), UserAccessAuditLogger::TYPE_USER, $user->id, 'employee_linked', 'employee', $old, $user->fresh()->employee?->full_name);

        return back()->with('success', $data['employee_id'] !== null
            ? "Colaborador vinculado a {$user->name}."
            : "Vínculo de colaborador removido para {$user->name}.");
    }

    private function assertManageable(Request $request, User $user): void
    {
        abort_unless($this->guard->canManageUser($request->user(), $user), 403, 'Solo un administrador puede modificar a otro administrador.');
    }

    private function closeSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }
}
