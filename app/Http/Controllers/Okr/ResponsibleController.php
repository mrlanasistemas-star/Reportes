<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\OkrObjective;
use App\Models\User;
use App\Services\Access\AccessDelegationGuard;
use App\Services\Access\DataScopeService;
use App\Services\Access\ActionPermissionService;
use App\Services\Access\ModuleAccessService;
use App\Services\Access\RoleCatalogService;
use App\Services\Access\UserAccessAuditLogger;
use App\Services\Access\GerenciasDirectoryImportService;
use App\Services\Access\UserInvitationService;
use App\Services\CanonicalEmployeeResolver;
use App\Services\Okr\OkrEmployeeBranchResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
        // UNA instancia por petición (antes `app(DataScopeService::class)` creaba
        // una nueva en cada fila y el memo de alcance no servía de nada).
        $scope = app(DataScopeService::class);
        $enabledAdmins = User::query()->where('role', User::ROLE_ADMIN)->whereNull('disabled_at')->count();

        // Rendimiento (27-oct-2026): las props pesadas son closures — Inertia solo
        // las evalúa si la visita las pide (p. ej. router.reload({ only: ['users'] })
        // ya no recalcula colaboradores ni roles). `employees` es DIFERIDA: la
        // página se pinta sin ella y el frontend la recibe en una segunda petición
        // automática (solo la usan los diálogos de alta y de vincular colaborador).
        return Inertia::render('usuarios/Index', [
            'users' => fn () => $this->userRows($actor, $scope, $branchResolver, $catalog, $enabledAdmins),
            'employees' => Inertia::defer(fn () => $this->linkableEmployees($branchResolver)),
            'roles' => fn () => collect($catalog->all())->map(fn ($r) => [
                'key' => $r['key'], 'name' => $r['name'], 'description' => $r['description'], 'base_level' => $r['base_level'], 'is_system' => $r['is_system'],
                'modules_count' => $r['key'] === User::ROLE_ADMIN ? count(ModuleAccessService::MODULES) : count($moduleAccess->matrix()[$r['key']] ?? []),
                'assignable' => $r['key'] !== User::ROLE_ADMIN && $this->guard->canGrantRole($actor, $r['key']),
                'grantable' => $this->guard->canGrantRole($actor, $r['key']),
            ])->values()->all(),
            'audit' => fn () => $this->audit->recent(),
            'current_user_id' => $actor->id,
            'enabled_admin_count' => $enabledAdmins,
            'invitation_expires_minutes' => $this->invitations->expiresInMinutes(),
            'store_url' => route('usuarios.store'),
            // Fase 6.4 — sucursales que ESTE actor puede conceder (nunca más que las suyas).
            'scope_branches' => fn () => $scope->filterBranchOptions($actor, $scope->assignableBranches()),
            'actor_scope' => fn () => $scope->describe($actor),
            'roles_url' => $moduleAccess->allows($actor, 'roles') ? route('roles.index') : null,
            'environment' => app()->environment(),
            // Importación masiva del directorio de gerencias (solo administradores).
            'import_urls' => $actor->isAdmin() ? [
                'preview' => route('usuarios.import-preview'),
                'confirm' => route('usuarios.import'),
                'credentials' => route('usuarios.import-credentials'),
            ] : null,
            'can' => [
                'create' => $this->actions->canAction($actor, 'usuarios', 'crear'),
                'invite' => $this->actions->canAction($actor, 'usuarios', 'invitar'),
                'manage_access' => $this->actions->canAction($actor, 'usuarios', 'gestionar_acceso'),
                'import' => $actor->isAdmin() && $this->actions->canAction($actor, 'usuarios', 'crear'),
                'bulk_reset_password' => $this->actions->canAction($actor, 'usuarios', 'restablecer'),
            ],
            'bulk_password_urls' => [
                'assign' => route('usuarios.bulk-reset-passwords'),
                'download' => route('usuarios.bulk-passwords-download'),
            ],
            // Solo el resumen (cuántas se asignaron); las contraseñas viajan selladas para la descarga.
            'bulk_password_result' => session()->pull('bulk_password_result'),
            // Se muestran UNA SOLA VEZ (session()->pull() las lee y las borra).
            'temp_password' => self::revealSecret(session()->pull('temp_password')),
            'reset_password' => self::revealSecret(session()->pull('reset_password')),
            'invitation_result' => session()->pull('invitation_result'),
            'import_preview' => session()->pull('import_preview'),
            'import_result' => session()->pull('import_result'),
        ]);
    }

    /**
     * Filas de la tabla de Usuarios con un número FIJO de consultas (antes ~20
     * por usuario): sucursal vigente de todos los colaboradores vinculados en
     * una sola pasada (membershipMap), alcance por sucursal precargado en lote
     * y permisos del actor (constantes) calculados una vez. Las reglas de
     * autorización no cambian: se usan los mismos servicios y el backend
     * vuelve a validar cada acción.
     */
    private function userRows(User $actor, DataScopeService $scope, OkrEmployeeBranchResolver $branchResolver, RoleCatalogService $catalog, int $enabledAdmins): array
    {
        $usageCounts = OkrObjective::query()
            ->whereNotNull('responsible_user_id')
            ->selectRaw('responsible_user_id, count(*) as total')
            ->groupBy('responsible_user_id')
            ->pluck('total', 'responsible_user_id');

        $branchNames = Branch::query()->pluck('name', 'id');
        $users = User::query()->with('employee:id,full_name,is_active')->orderBy('name')->get();

        $memberships = $branchResolver->membershipMap($users->pluck('employee_id')->filter()->map(fn ($id) => (int) $id)->all());
        $scope->preload($users->concat([$actor]), $branchResolver);

        // Permisos del ACTOR: no dependen de la fila.
        $actorCan = collect(['gestionar_acceso', 'restablecer', 'invitar', 'asignar_rol', 'editar'])
            ->mapWithKeys(fn ($a) => [$a => $this->actions->canAction($actor, 'usuarios', $a)])->all();

        return $this->withRequestScope($scope, $branchResolver, fn () => $users->map(function (User $u) use ($usageCounts, $memberships, $branchNames, $actor, $catalog, $enabledAdmins, $scope, $actorCan) {
                $branchId = $u->employee_id ? ($memberships[(int) $u->employee_id]['branch_id'] ?? null) : null;
                $canManage = $this->guard->canManageUser($actor, $u);
                $manageable = $u->id !== $actor->id && $canManage;
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
                    // Fase 6.4 — alcance por sucursal (general o lista) para la tabla y el editor.
                    'data_scope' => $scope->describe($u),
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
                        'update_branch_scope' => route('usuarios.update-branch-scope', $u),
                    ],
                    'can' => [
                        'manage_access' => $manageable && ! $isLastAdmin && $actorCan['gestionar_acceso'],
                        'reset_password' => $manageable && $actorCan['restablecer'],
                        'invite' => $canManage && $actorCan['invitar'],
                        'change_role' => $manageable && ! ($u->isAdmin() && $isLastAdmin) && $actorCan['asignar_rol'],
                        'link_employee' => $canManage && $actorCan['editar'],
                        'change_branch_scope' => ! $u->isAdmin() && $manageable && $actorCan['asignar_rol'],
                    ],
                ];
            })->values()->all());
    }

    /**
     * Colaboradores vinculables: UNA entrada por PERSONA (identidad canónica),
     * no una fila por cada registro histórico de `employees`. El mismo
     * colaborador tiene varios `Employee.id` (NOI vs NOI Fiscal, varios
     * periodos) que nunca se unificaron y hacían aparecer el mismo nombre
     * repetido muchas veces en el selector (p. ej. ADRIAN DIAZ MENA). Se
     * colapsa por `normalized_name` con la MISMA regla que ya usa Reportería
     * y OKR (CanonicalEmployeeResolver), respetando homónimos reales distintos
     * (employee_match_rejections). NO se borra ni modifica ningún registro
     * histórico: solo se consolida la VISTA del buscador. El id expuesto es el
     * canónico (menor del grupo), estable entre llamadas. La sucursal vigente
     * de todas las personas se resuelve en UNA pasada (membershipMap), no una
     * por persona.
     */
    private function linkableEmployees(OkrEmployeeBranchResolver $branchResolver): array
    {
        $branchNames = Branch::query()->pluck('name', 'id');
        $employeeRows = Employee::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereHas('user'))
            ->with('user:id,employee_id')
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'normalized_name', 'position', 'employee_code', 'is_active']);

        $rowsById = $employeeRows->keyBy('id');
        $linkedUserByEmployeeId = $employeeRows->filter(fn ($e) => $e->user)->mapWithKeys(fn ($e) => [$e->id => $e->user->id]);

        $people = app(CanonicalEmployeeResolver::class)->canonicalize($employeeRows);
        $memberships = $branchResolver->membershipMap($people->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $people
            ->map(function ($person) use ($rowsById, $linkedUserByEmployeeId, $memberships, $branchNames) {
                // Representante del grupo: se prefiere un registro activo; si no, el canónico.
                $rep = collect($person->employee_ids)
                    ->map(fn ($id) => $rowsById->get($id))->filter()
                    ->sortByDesc(fn ($e) => $e->is_active ? 1 : 0)->first() ?? $rowsById->get($person->id);
                // Si CUALQUIER registro del grupo ya tiene cuenta, la persona está vinculada:
                // así una identidad laboral nunca se ofrece para una segunda cuenta activa.
                $linkedUserId = collect($person->employee_ids)
                    ->map(fn ($id) => $linkedUserByEmployeeId->get($id))->filter()->first();
                $branchId = $memberships[(int) $person->id]['branch_id'] ?? null;

                return [
                    'id' => $person->id,
                    'full_name' => $rep?->full_name ?? $person->full_name,
                    'employee_code' => $rep?->employee_code,
                    'position' => $rep?->position,
                    'is_active' => (bool) ($rep?->is_active ?? false),
                    'current_branch' => $branchId ? ($branchNames[$branchId] ?? null) : null,
                    'linked_user_id' => $linkedUserId ? (int) $linkedUserId : null,
                    // Nº de registros históricos que representa (para señalar consolidaciones).
                    'records' => count($person->employee_ids),
                ];
            })
            ->sortBy('full_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Mientras se arman las filas, `app(DataScopeService::class)` (que usa
     * AccessDelegationGuard por dentro) devuelve ESTA instancia ya precargada
     * en vez de una nueva sin memo por cada fila. Se restaura al terminar: no
     * queda caché de alcance para otras peticiones.
     */
    private function withRequestScope(DataScopeService $scope, OkrEmployeeBranchResolver $resolver, \Closure $fn): mixed
    {
        $bound = [];
        foreach ([DataScopeService::class => $scope, OkrEmployeeBranchResolver::class => $resolver] as $abstract => $instance) {
            if (! app()->isShared($abstract)) {
                app()->instance($abstract, $instance);
                $bound[] = $abstract;
            }
        }

        try {
            return $fn();
        } finally {
            foreach ($bound as $abstract) {
                app()->forgetInstance($abstract);
            }
        }
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
            // Fase 6.4 — alcance por sucursal del usuario nuevo.
            'branch_scope' => ['nullable', Rule::in([DataScopeService::MODE_ALL, DataScopeService::MODE_BRANCHES])],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ], [
            'branch_scope.in' => 'Elige «Todas las sucursales» o «Sucursales específicas».',
            'branch_ids.*.exists' => 'Una de las sucursales elegidas no existe.',
            'role.in' => 'Elige un rol válido.',
            'email.unique' => 'Ya existe un usuario con ese correo.',
            'employee_id.unique' => 'Ese colaborador ya está vinculado a otro usuario.',
            'password.required_if' => 'Escribe la contraseña o elige otra opción.',
            'password.confirmed' => 'La confirmación no coincide con la contraseña.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        $role = $data['role'] ?? User::ROLE_COLABORADOR;
        abort_unless($this->guard->canGrantRole($actor, $role), 403, 'No puedes asignar un rol con más permisos que el tuyo.');

        // Una identidad laboral (persona) no puede quedar ligada a dos cuentas:
        // se revisa el grupo canónico completo, no solo el employee_id exacto.
        if (($data['employee_id'] ?? null) !== null && $this->employeeIdentityLinkedUserId((int) $data['employee_id']) !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'employee_id' => 'Esa identidad de colaborador ya está vinculada a otra cuenta.',
            ]);
        }

        // Fase 6.4 — alcance: el elegido; si no se eligió y quien da el alta tiene alcance
        // restringido, hereda SUS sucursales (nunca queda con «todas» por omisión).
        $dataScope = app(DataScopeService::class);
        $scopeMode = $data['branch_scope'] ?? null;
        $scopeIds = array_map('intval', $data['branch_ids'] ?? []);
        if ($scopeMode === null && $dataScope->isRestricted($actor)) {
            $scopeMode = DataScopeService::MODE_BRANCHES;
            $scopeIds = $dataScope->allowedBranchIds($actor) ?? [];
        }
        // Un usuario NUEVO de nivel gerencial sin alcance elegido quedaría con la regla
        // de usuarios previos a la Fase 6.4 (NULL → todas las sucursales): acceso general
        // por omisión. Se exige la elección explícita. (Nivel colaborador sin elección
        // conserva «solo lo propio», que nunca amplía su acceso.)
        if ($scopeMode === null && $this->isManagerialRole($role)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['branch_scope' => 'Elige el alcance por sucursal del usuario: «Todas las sucursales» o «Sucursales específicas».']);
        }
        if ($scopeMode === DataScopeService::MODE_BRANCHES && $scopeIds === []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['branch_ids' => 'Elige al menos una sucursal o marca «Todas las sucursales».']);
        }
        abort_if($scopeMode !== null && ! $dataScope->canGrant($actor, $scopeMode, $scopeIds), 403, 'No puedes conceder un alcance mayor al tuyo: solo puedes asignar sucursales que tú mismo tienes autorizadas.');

        $mode = $data['password_mode'] ?? 'auto';
        // 'invite' y 'auto' generan una contraseña temporal legible (16 caracteres);
        // 'manual' la escribe quien da el alta. En todos los casos se guarda SOLO su
        // hash; el claro solo viaja (una vez) en el correo y/o en la pantalla.
        $tempPassword = $mode === 'manual' ? null : Str::password(16);
        $finalPassword = $mode === 'manual' ? $data['password'] : $tempPassword;
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

        if ($scopeMode !== null) {
            $label = $dataScope->save($user, $scopeMode, $scopeIds, $actor);
            $this->audit->log($actor, UserAccessAuditLogger::TYPE_USER, $user->id, 'branch_scope_changed', 'branch_scope', null, $label);
        }

        $this->audit->log($actor, UserAccessAuditLogger::TYPE_USER, $user->id, 'user_created', 'role', null, $role, match ($mode) {
            'manual' => 'Contraseña manual', 'auto' => 'Contraseña generada', default => 'Por invitación',
        });

        $response = back()->with('success', "Usuario {$user->name} creado.");

        $invitationSent = null;
        if ($sendInvitation) {
            // Se le entrega al correo la contraseña temporal real recién generada
            // (nunca un enlace): el usuario entra con ella y la cambia en Seguridad.
            $result = $this->invitations->send($user, $finalPassword);
            $invitationSent = $result['sent'];
            $this->audit->log($actor, UserAccessAuditLogger::TYPE_USER, $user->id, $result['sent'] ? 'invitation_accepted' : 'invitation_failed');
            $response->with('invitation_result', ['sent' => $result['sent'], 'message' => $result['message'], 'email' => $user->email]);
        }

        // La contraseña temporal se muestra en pantalla (una sola vez) cuando:
        //  - modo 'auto': el administrador la entrega él mismo; o
        //  - modo 'invite' pero el correo NO se pudo enviar: así Sistemas puede
        //    entregarla por un canal seguro (spec §5) sin dar por enviada la
        //    invitación. Si el correo se aceptó, la credencial ya viajó en él.
        $reveal = $tempPassword !== null && ($mode === 'auto' || ($mode === 'invite' && $invitationSent === false));

        return $reveal
            ? $response->with('temp_password', self::sealSecret(['name' => $user->name, 'email' => $user->email, 'password' => $tempPassword]))
            : $response;
    }

    /**
     * Reenvía el correo de accesos sin recrear al usuario. Como nunca guardamos
     * la contraseña en claro, el reenvío entrega una contraseña temporal NUEVA
     * (solo se aplica si el correo se acepta; si el envío falla, la contraseña
     * anterior sigue intacta — ver UserInvitationService::send()).
     */
    public function resendWelcomeEmail(Request $request, User $user): RedirectResponse|JsonResponse
    {
        abort_unless($this->guard->canManageUser($request->user(), $user), 403, 'Solo un administrador puede reenviar la invitación de otro administrador.');

        $result = $this->invitations->send($user);
        $this->audit->log($request->user(), UserAccessAuditLogger::TYPE_USER, $user->id, $result['sent'] ? 'invitation_accepted' : 'invitation_failed', null, null, null, $result['sent'] ? 'Correo con nueva contraseña temporal' : null);

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

        return back()->with('reset_password', self::sealSecret(['name' => $user->name, 'email' => $user->email, 'password' => $password]));
    }

    /**
     * Asignación MASIVA de contraseñas a los usuarios seleccionados: a cada uno se
     * le genera una contraseña nueva (la anterior deja de funcionar y se cierran
     * sus sesiones). Se omiten tu propia cuenta y las que tu rol no puede
     * administrar. El listado con las contraseñas se sella cifrado en la sesión
     * para UNA sola descarga en Excel (nunca va a la pantalla ni a los logs).
     */
    public function bulkResetPasswords(Request $request, OkrEmployeeBranchResolver $branchResolver, DataScopeService $dataScope): RedirectResponse
    {
        $actor = $request->user();
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['integer'],
        ], [
            'user_ids.required' => 'Selecciona al menos un usuario.',
        ]);

        $branchNames = Branch::query()->pluck('name', 'id');
        $rows = [];
        $skipped = 0;

        $users = User::query()->whereIn('id', array_unique($data['user_ids']))->orderBy('name')->get();
        foreach ($users as $user) {
            if ($user->id === $actor->id || ! $this->guard->canManageUser($actor, $user)) {
                $skipped++;

                continue;
            }

            $password = Str::password(14, symbols: false);
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            $this->closeSessions($user);
            $this->audit->log($actor, UserAccessAuditLogger::TYPE_USER, $user->id, 'password_reset', null, null, null, 'Contraseña generada (asignación masiva)');

            $rows[] = [
                'name' => $user->name,
                'username' => $user->email,
                'password' => $password,
                'email' => $user->email,
                'branch' => $this->branchLabelFor($user, $branchResolver, $branchNames, $dataScope),
            ];
        }
        $skipped += count(array_unique($data['user_ids'])) - $users->count();

        if ($rows === []) {
            return back()->with('error', 'No se asignó ninguna contraseña: los usuarios seleccionados no se pueden administrar con tu rol.');
        }

        session()->put('bulk_password_credentials', self::sealSecret($rows));

        return back()->with('bulk_password_result', ['updated' => count($rows), 'skipped' => $skipped]);
    }

    /** Descarga ÚNICA del Excel de la última asignación masiva (Nombre, Usuario, Contraseña, Correo, Sucursal). */
    public function downloadBulkPasswords(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse|RedirectResponse
    {
        $rows = self::revealSecret(session()->pull('bulk_password_credentials'));
        if (! is_array($rows) || $rows === []) {
            return back()->with('error', 'No hay contraseñas disponibles para descargar (ya se descargaron o expiraron). Vuelve a asignarlas si las necesitas.');
        }

        return $this->downloadSpreadsheet(
            ['Nombre', 'Usuario', 'Contraseña', 'Correo', 'Sucursal'],
            array_map(fn ($r) => [$r['name'], $r['username'], $r['password'], $r['email'], $r['branch']], $rows),
            'Contraseñas',
            'contrasenas_usuarios',
        );
    }

    /** Sucursal para el Excel: la laboral del colaborador vinculado; si no, la(s) de su alcance. */
    private function branchLabelFor(User $user, OkrEmployeeBranchResolver $branchResolver, $branchNames, DataScopeService $dataScope): string
    {
        $branchId = $user->employee_id ? $branchResolver->currentBranchIdFor($user->employee_id) : null;
        if ($branchId && isset($branchNames[$branchId])) {
            return $branchNames[$branchId];
        }

        $scope = $dataScope->describe($user);
        if ($scope['general']) {
            return 'Todas las sucursales';
        }
        $names = collect($scope['branch_ids'])->map(fn ($id) => $branchNames[$id] ?? null)->filter()->sort()->values();

        return $names->isEmpty() ? '—' : $names->implode(', ');
    }

    /**
     * Arma y envía un .xlsx temporal (encabezados en negritas, columnas autoajustadas)
     * y lo elimina tras enviarlo.
     *
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    private function downloadSpreadsheet(array $headers, array $rows, string $sheetTitle, string $fileBase): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetTitle);
        $sheet->fromArray($headers, null, 'A1');
        $r = 2;
        foreach ($rows as $row) {
            // Como texto explícito: una contraseña o un correo nunca se reinterpretan como número/fórmula.
            foreach (array_values($row) as $i => $value) {
                $sheet->setCellValueExplicit([$i + 1, $r], (string) ($value ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $r++;
        }
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        foreach (range(1, count($headers)) as $i) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);

        $dir = storage_path('app/user-imports');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($dir);
        $outputPath = $dir . '/' . $fileBase . '_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($outputPath);
        $spreadsheet->disconnectWorksheets();

        return response()->download($outputPath, $fileBase . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ])->deleteFileAfterSend(true);
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

        // Fase 6.4 — un usuario sin alcance explícito (previo a la fase) obtiene su
        // alcance por defecto del NIVEL de su rol: subirlo a gerencial lo dejaría con
        // «todas». Si quien cambia el rol tiene alcance restringido, se congela antes
        // el alcance actual del usuario (que ya cabe en el suyo): nunca se escala.
        // Lo mismo aplica aunque quien lo cambie sea un administrador: subir de nivel a un
        // usuario sin alcance explícito NUNCA le concede «todas» por arrastre; su alcance
        // actual (restringido) se congela y el administrador lo amplía de forma explícita.
        $dataScope = app(DataScopeService::class);
        $frozenEmpty = false;
        if ($dataScope->modeOf($user) === null && $dataScope->isRestricted($user)
            && ($dataScope->isRestricted($request->user()) || $this->isManagerialRole($data['role']))) {
            $frozen = $dataScope->allowedBranchIds($user) ?? [];
            $dataScope->save($user, DataScopeService::MODE_BRANCHES, $frozen, $request->user());
            $this->audit->log($request->user(), UserAccessAuditLogger::TYPE_USER, $user->id, 'branch_scope_changed', 'branch_scope', null, $dataScope->label($user->fresh()));
            $frozenEmpty = $frozen === [];
        }

        $old = $user->role;
        $user->forceFill(['role' => $data['role']])->save();
        $this->audit->log($request->user(), UserAccessAuditLogger::TYPE_USER, $user->id, 'role_changed', 'role', $catalog->label($old), $catalog->label($data['role']));

        return back()->with('success', "Rol de {$user->name} actualizado a {$catalog->label($data['role'])}."
            . ($frozenEmpty ? ' Todavía no tiene sucursales autorizadas: asígnaselas en «Alcance por sucursal».' : ''));
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
            $linkedTo = $this->employeeIdentityLinkedUserId((int) $data['employee_id'], $user->id);
            if ($linkedTo !== null) {
                return back()->with('error', 'Esa identidad de colaborador ya está vinculada a otra cuenta: una persona no puede quedar ligada a dos cuentas.');
            }
        }

        $old = $user->employee?->full_name;
        $user->forceFill(['employee_id' => $data['employee_id']])->save();
        $this->audit->log($request->user(), UserAccessAuditLogger::TYPE_USER, $user->id, 'employee_linked', 'employee', $old, $user->fresh()->employee?->full_name);

        return back()->with('success', $data['employee_id'] !== null
            ? "Colaborador vinculado a {$user->name}."
            : "Vínculo de colaborador removido para {$user->name}.");
    }

    /**
     * Fase 6.4 — asigna el alcance por sucursal de un usuario: «todas» o una lista.
     * Nadie concede un alcance mayor al propio (DataScopeService::canGrant) ni
     * administra a quien tiene más alcance que él (AccessDelegationGuard). El
     * cambio aplica en la siguiente petición del usuario (sin caché residual).
     */
    public function updateBranchScope(Request $request, User $user, DataScopeService $dataScope): RedirectResponse
    {
        $data = $request->validate([
            'branch_scope' => ['required', Rule::in([DataScopeService::MODE_ALL, DataScopeService::MODE_BRANCHES])],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ], [
            'branch_scope.required' => 'Elige «Todas las sucursales» o «Sucursales específicas».',
            'branch_scope.in' => 'Elige «Todas las sucursales» o «Sucursales específicas».',
            'branch_ids.*.exists' => 'Una de las sucursales elegidas no existe.',
        ]);

        $this->assertManageable($request, $user);

        if ($user->isAdmin()) {
            return back()->with('error', 'El Administrador siempre tiene alcance general; no se configura.');
        }
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'No puedes cambiar tu propio alcance por sucursal.');
        }

        $ids = array_map('intval', $data['branch_ids'] ?? []);
        if ($data['branch_scope'] === DataScopeService::MODE_BRANCHES && $ids === []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['branch_ids' => 'Elige al menos una sucursal o marca «Todas las sucursales».']);
        }

        $old = $dataScope->label($user);
        $new = $dataScope->save($user, $data['branch_scope'], $ids, $request->user());
        $this->audit->log($request->user(), UserAccessAuditLogger::TYPE_USER, $user->id, 'branch_scope_changed', 'branch_scope', $old, $new);

        return back()->with('success', "Alcance de {$user->name} actualizado: {$new}.");
    }

    /**
     * Usuarios → Importar usuarios (paso 1): sube el directorio de gerencias,
     * lo valida y devuelve la VISTA PREVIA. No crea nada. El archivo se guarda
     * temporalmente para re-leerlo al confirmar (idempotencia autoritativa).
     */
    public function importPreview(Request $request, GerenciasDirectoryImportService $service): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->isAdmin(), 403, 'La importación masiva es exclusiva de administradores.');

        $request->validate(
            ['file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls']],
            ['file.required' => 'Selecciona el archivo del directorio.', 'file.mimes' => 'El archivo debe ser .xlsx o .xls.', 'file.max' => 'El archivo no debe superar 10 MB.'],
        );

        $path = Storage::disk('local')->putFile('user-imports', $request->file('file'));
        $preview = $service->preview(Storage::disk('local')->path($path));

        if (! $preview['sheet_found'] || $preview['summary']['total'] === 0) {
            Storage::disk('local')->delete($path);

            return back()->with('error', 'No se encontró la hoja «Directorio» o no tiene filas de colaboradores. Revisa el archivo.');
        }

        // El archivo se conserva (no flash) hasta confirmar o cancelar.
        session()->put('import_file_path', $path);

        return back()->with('import_preview', [
            'environment' => app()->environment(),
            'original_name' => $request->file('file')->getClientOriginalName(),
            'summary' => $preview['summary'],
            'rows' => $preview['rows'],
        ]);
    }

    /**
     * Usuarios → Importar usuarios (paso 2): crea SOLO las cuentas seleccionadas
     * y válidas. Idempotente: una cuenta existente (por correo) nunca se recrea
     * ni se le toca contraseña/permisos. Cada cuenta nueva recibe su propia
     * contraseña temporal (hash en BD; el claro solo va al reporte de entrega).
     * Por omisión NO envía invitaciones por correo (SMTP puede no estar
     * verificado); se puede activar explícitamente con `send_invitations`.
     */
    public function importConfirm(Request $request, GerenciasDirectoryImportService $service, DataScopeService $dataScope): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->isAdmin(), 403, 'La importación masiva es exclusiva de administradores.');

        $data = $request->validate([
            'emails' => ['required', 'array', 'min:1'],
            'emails.*' => ['string'],
            'confirmed' => ['accepted'],
            'send_invitations' => ['nullable', 'boolean'],
        ], [
            'emails.required' => 'Selecciona al menos una cuenta para crear.',
            'confirmed.accepted' => 'Debes confirmar el lote antes de crear las cuentas.',
        ]);

        $path = session('import_file_path');
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 422, 'La sesión de importación expiró. Vuelve a subir el archivo.');

        // Se re-lee el archivo (fuente autoritativa) y se re-evalúa contra la BD
        // actual: así la idempotencia se verifica EN VIVO al momento de crear.
        $preview = $service->preview(Storage::disk('local')->path($path));
        $wanted = collect($data['emails'])->map(fn ($e) => mb_strtolower(trim($e)))->flip();
        $sendInvitations = ($data['send_invitations'] ?? false) && $this->actions->canAction($actor, 'usuarios', 'invitar');

        $report = [];
        $created = 0;
        $skippedExisting = 0;
        $skippedInvalid = 0;

        foreach ($preview['rows'] as $row) {
            if (! $wanted->has(mb_strtolower(trim($row['email'])))) {
                continue;
            }

            $base = [
                'name' => $row['name'], 'branch' => $row['branch_name'], 'position' => $row['position'],
                'role' => 'Gerencial', 'email' => $row['email'], 'temporary_password' => null,
                'observations' => implode(' ', $row['observations']),
            ];

            // No creamos filas con error, ni las que ya existen (idempotencia).
            if ($row['status'] === GerenciasDirectoryImportService::STATUS_ERROR || $row['branch_id'] === null) {
                $skippedInvalid++;
                $report[] = $base + ['creation' => 'No creada (datos inválidos)', 'access' => '—', 'invitation' => '—'];

                continue;
            }
            if ($row['existing_user_id'] || User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($row['email']))])->exists()) {
                $skippedExisting++;
                $report[] = $base + ['creation' => 'Ya existía (sin cambios)', 'access' => 'Sin cambios', 'invitation' => 'Sin cambios'];

                continue;
            }

            try {
                // array_merge (no `+`): `+` conserva la clave de $base y descartaba la
                // contraseña temporal generada (el reporte salía con la columna vacía).
                $report[] = array_merge($base, $this->createImportedUser($row, $actor, $dataScope, $sendInvitations));
                $created++;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Importación de gerencias: fallo al crear una cuenta.', ['email' => $row['email'], 'error' => $e->getMessage()]);
                $report[] = array_merge($base, ['creation' => 'Error al crear', 'access' => '—', 'invitation' => '—', 'observations' => trim($base['observations'] . ' ' . 'No se pudo crear; intenta de nuevo.')]);
            }
        }

        // Limpieza: el archivo temporal no se conserva.
        Storage::disk('local')->delete($path);
        session()->forget('import_file_path');

        $summary = ['created' => $created, 'existing' => $skippedExisting, 'invalid' => $skippedInvalid, 'processed' => count($report)];
        $this->audit->log($actor, UserAccessAuditLogger::TYPE_USER, $actor->id, 'user_created', 'role', null, 'gerencial', "Importación de gerencias: {$created} creadas, {$skippedExisting} existentes");

        $response = back()->with('import_result', [
            'summary' => $summary,
            // En pantalla NUNCA van las contraseñas: solo estados y observaciones.
            'rows' => array_map(fn ($r) => ['name' => $r['name'], 'branch' => $r['branch'], 'position' => $r['position'], 'email' => $r['email'], 'creation' => $r['creation'], 'access' => $r['access'], 'invitation' => $r['invitation'], 'observations' => $r['observations']], $report),
            'has_credentials' => $created > 0,
        ]);

        // El reporte CON contraseñas temporales se sella cifrado en la sesión para
        // una ÚNICA descarga protegida; nunca se escribe en logs ni props de pantalla.
        if ($created > 0) {
            session()->put('import_credentials', self::sealSecret($report));
        }

        return $response;
    }

    /** Crea una cuenta importada (rol gerencial, alcance = su sucursal). */
    private function createImportedUser(array $row, User $actor, DataScopeService $dataScope, bool $sendInvitations): array
    {
        $tempPassword = Str::password(16);

        // Vínculo laboral solo si la identidad está libre (nunca dos cuentas por persona).
        $employeeId = null;
        if ($row['employee_id'] && $this->employeeIdentityLinkedUserId((int) $row['employee_id']) === null) {
            $employeeId = (int) $row['employee_id'];
        }

        $user = User::query()->create([
            'name' => $row['name'],
            'email' => $row['email'],
            'password' => Hash::make($tempPassword),
            'role' => GerenciasDirectoryImportService::ROLE,
            'employee_id' => $employeeId,
        ]);
        $user->forceFill([
            'email_verified_at' => null,
            'access_enabled_at' => null, // queda "pendiente de activación" hasta confirmar identidad
            'welcome_email_status' => $sendInvitations ? UserInvitationService::STATUS_PENDING : null,
        ])->save();

        // Alcance = SU sucursal (nunca «todas» por ser gerencial).
        $dataScope->save($user, DataScopeService::MODE_BRANCHES, [(int) $row['branch_id']], $actor);
        $this->audit->log($actor, UserAccessAuditLogger::TYPE_USER, $user->id, 'user_created', 'role', null, GerenciasDirectoryImportService::ROLE, 'Importación de gerencias');

        $invitation = 'No enviada (entrega por reporte de credenciales)';
        if ($sendInvitations) {
            $result = $this->invitations->send($user, $tempPassword);
            $this->audit->log($actor, UserAccessAuditLogger::TYPE_USER, $user->id, $result['sent'] ? 'invitation_accepted' : 'invitation_failed');
            $invitation = $result['sent'] ? 'Correo aceptado por el servidor' : 'Falló el envío (usa el reporte de credenciales)';
        }

        return [
            'temporary_password' => $tempPassword,
            'creation' => 'Creada',
            'access' => 'Pendiente de activación',
            'invitation' => $invitation,
        ];
    }

    /**
     * Descarga ÚNICA y protegida del reporte de entrega de credenciales de la
     * última importación (incluye las contraseñas temporales de las cuentas
     * realmente creadas). El sellado vive en la sesión y se CONSUME aquí
     * (session()->pull): una sola descarga. El archivo temporal se elimina tras
     * enviarse; nada se persiste en logs ni exportaciones ordinarias. Si se
     * pierde, hay que restablecer la contraseña (nunca se recupera el valor).
     */
    public function downloadImportCredentials(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse|RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Solo un administrador puede descargar las credenciales.');

        $report = self::revealSecret(session()->pull('import_credentials'));
        if (! is_array($report) || $report === []) {
            return back()->with('error', 'No hay un reporte de credenciales disponible (ya se descargó o expiró).');
        }

        return $this->downloadSpreadsheet(
            ['Nombre completo', 'Sucursal', 'Puesto laboral', 'Rol asignado', 'Correo / usuario', 'Contraseña temporal', 'Estado de creación', 'Estado de acceso', 'Estado de invitación', 'Observaciones'],
            array_map(fn ($row) => [
                $row['name'], $row['branch'], $row['position'], $row['role'], $row['email'],
                $row['temporary_password'] ?? '', $row['creation'], $row['access'], $row['invitation'], $row['observations'],
            ], $report),
            'Credenciales',
            'credenciales_gerencias',
        );
    }

    /**
     * Contraseña generada que se muestra UNA vez: viaja cifrada en la sesión (el
     * driver es `database` sin SESSION_ENCRYPT), así nunca queda en texto plano en
     * la tabla `sessions` entre la redirección y la pantalla que la muestra.
     */
    public static function sealSecret(array $secret): string
    {
        return \Illuminate\Support\Facades\Crypt::encrypt($secret);
    }

    /** @return array<string, string>|null */
    public static function revealSecret(mixed $sealed): ?array
    {
        if (! is_string($sealed) || $sealed === '') {
            return null;
        }
        try {
            $value = \Illuminate\Support\Facades\Crypt::decrypt($sealed);
        } catch (\Throwable) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    /** Rol (del sistema o personalizado) de nivel gerencial — sin alcance explícito vería todas las sucursales. */
    private function isManagerialRole(string $role): bool
    {
        return $role === User::ROLE_GERENCIAL
            || ($role !== User::ROLE_ADMIN && app(RoleCatalogService::class)->baseLevel($role) === User::ROLE_GERENCIAL);
    }

    private function assertManageable(Request $request, User $user): void
    {
        abort_unless($this->guard->canManageUser($request->user(), $user), 403, 'Solo un administrador puede modificar a otro administrador.');
    }

    /**
     * ¿La IDENTIDAD canónica de este colaborador ya está vinculada a una cuenta?
     * No basta con revisar el `employee_id` exacto: la misma persona tiene varios
     * registros históricos (NOI vs NOI Fiscal, periodos) y un vínculo pudo
     * hacerse sobre otro de sus ids. Se resuelve el grupo canónico (mismo
     * normalized_name, respetando homónimos reales) y se revisa TODO el grupo,
     * así una identidad laboral nunca queda ligada a dos cuentas distintas.
     *
     * @return int|null id del usuario que ya tiene vinculada esa identidad (o null).
     */
    private function employeeIdentityLinkedUserId(int $employeeId, ?int $exceptUserId = null): ?int
    {
        $target = Employee::query()->find($employeeId, ['id', 'normalized_name']);
        $norm = trim((string) ($target?->normalized_name ?? ''));

        if ($target === null || $norm === '') {
            $groupIds = [$employeeId];
        } else {
            $rows = Employee::query()->where('normalized_name', $norm)->get(['id', 'normalized_name', 'full_name']);
            $group = app(CanonicalEmployeeResolver::class)->canonicalize($rows)
                ->first(fn ($p) => in_array($employeeId, $p->employee_ids, true));
            $groupIds = $group?->employee_ids ?? [$employeeId];
        }

        $query = User::query()->whereIn('employee_id', $groupIds);
        if ($exceptUserId !== null) {
            $query->whereKeyNot($exceptUserId);
        }

        return $query->value('id');
    }

    private function closeSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }
}
