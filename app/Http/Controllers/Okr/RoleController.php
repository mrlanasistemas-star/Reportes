<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Access\AccessDelegationGuard;
use App\Services\Access\ActionPermissionService;
use App\Services\Access\ModuleAccessService;
use App\Services\Access\RoleCatalogService;
use App\Services\Access\UserAccessAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Módulo ROLES Y PERMISOS (prioridad 8) — página propia en /roles, separada de
 * Usuarios. Un rol = nombre + descripción + nivel base + módulos que ve (Ver) +
 * acciones permitidas dentro de cada módulo. Los 3 roles del sistema no se
 * eliminan; Administrador tiene todo y nunca se configura (así nadie puede
 * dejarlo sin control). Acceso: `module:roles` + `permission:roles,*`
 * (routes/web.php); quien no es admin solo otorga lo que él mismo tiene
 * (AccessDelegationGuard).
 */
class RoleController extends Controller
{
    /**
     * @param  RoleCatalogService  $catalog  Catálogo de roles (tabla `roles`, con caché que se limpia con forget()).
     * @param  ModuleAccessService  $modules  Módulos visibles por rol.
     * @param  ActionPermissionService  $actions  Acciones permitidas por rol y módulo.
     * @param  AccessDelegationGuard  $guard  Impide otorgar permisos que el actor no tiene.
     * @param  UserAccessAuditLogger  $audit  Bitácora de cambios de acceso.
     */
    public function __construct(
        private readonly RoleCatalogService $catalog,
        private readonly ModuleAccessService $modules,
        private readonly ActionPermissionService $actions,
        private readonly AccessDelegationGuard $guard,
        private readonly UserAccessAuditLogger $audit,
    ) {}

    /**
     * Página Inertia `roles/Index`: cada rol con sus módulos, acciones, usuarios
     * asignados, resumen legible y si el actor puede editarlo/eliminarlo; además
     * los catálogos de módulos/acciones y lo que el actor puede otorgar.
     *
     * Un no admin solo puede editar roles distintos al suyo y que no excedan sus
     * propios permisos. No escribe en BD.
     *
     * @param  Request  $request  Usuario autenticado (actor).
     * @return Response Página de roles y permisos.
     */
    public function index(Request $request): Response
    {
        $actor = $request->user();
        $moduleMatrix = $this->modules->matrix();
        $actionMatrix = $this->actions->matrix();
        $usersByRole = User::query()->orderBy('name')->get(['id', 'name', 'email', 'role', 'disabled_at'])->groupBy('role');

        $roles = collect($this->catalog->all())->map(function ($r) use ($actor, $moduleMatrix, $actionMatrix, $usersByRole) {
            $isAdmin = $r['key'] === User::ROLE_ADMIN;
            $modules = $isAdmin ? array_keys(ModuleAccessService::MODULES) : ($moduleMatrix[$r['key']] ?? []);
            $actions = $isAdmin ? array_map(fn ($a) => array_keys($a), ActionPermissionService::ACTIONS) : ($actionMatrix[$r['key']] ?? []);
            $editable = ! $isAdmin && ($actor->isAdmin() || ($actor->role !== $r['key'] && $this->guard->excess($actor, $modules, $actions) === []));

            return $r + [
                'modules' => $modules,
                'actions' => (object) $actions,
                'users' => ($usersByRole[$r['key']] ?? collect())->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'blocked' => $u->disabled_at !== null])->values()->all(),
                'summary' => $this->summary($r['name'], $modules, $actions),
                'can_edit' => $editable && $this->actions->canAction($actor, 'roles', 'editar'),
                'can_delete' => ! $r['is_system'] && $editable && $this->actions->canAction($actor, 'roles', 'eliminar'),
                'update_url' => $isAdmin ? null : route('roles.update', $r['key']),
                'destroy_url' => $r['is_system'] ? null : route('roles.destroy', $r['key']),
            ];
        })->values()->all();

        return Inertia::render('roles/Index', [
            'roles' => $roles,
            'module_catalog' => $this->modules->catalog(),
            'action_catalog' => $this->actions->catalog(),
            'columns' => ActionPermissionService::COLUMNS,
            'store_url' => route('roles.store'),
            'users_url' => $this->modules->allows($actor, 'usuarios') ? route('usuarios.index') : null,
            'can' => ['create' => $this->actions->canAction($actor, 'roles', 'crear')],
            // Lo que el actor puede otorgar (admin: todo) — el asistente deshabilita lo demás.
            'grantable' => [
                'modules' => $this->modules->modulesFor($actor),
                'actions' => (object) $this->actions->actionsFor($actor),
            ],
        ]);
    }

    /**
     * Crea un rol personalizado (no del sistema).
     *
     * En una transacción inserta la fila en `roles` (clave generada del nombre)
     * y guarda sus módulos y acciones; limpia la caché del catálogo y registra
     * `role_created` en la bitácora de accesos.
     *
     * @param  Request  $request  Nombre, descripción, nivel base, módulos y acciones.
     * @return RedirectResponse Regresa con el resumen del rol creado.
     *
     * @throws ValidationException Si los datos no son válidos, el nombre está repetido o se otorga más de lo que tiene el actor.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->assertGrantable($request->user(), $data);
        $key = $this->catalog->makeKey($data['name']);

        DB::transaction(function () use ($data, $key) {
            DB::table('roles')->insert([
                'key' => $key, 'name' => $data['name'], 'description' => $data['description'] ?? null,
                'base_level' => $data['base_level'], 'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->catalog->forget();
            $this->modules->saveRole($key, $data['modules']);
            $this->actions->replaceRole($key, $data['actions']);
        });
        $this->catalog->forget();

        $id = (int) DB::table('roles')->where('key', $key)->value('id');
        $summary = $this->summary($data['name'], $data['modules'], $data['actions']);
        $this->audit->log($request->user(), UserAccessAuditLogger::TYPE_ROLE, $id, 'role_created', null, $data['name'], null, $summary);

        return back()->with('success', "Rol «{$data['name']}» creado. {$summary}");
    }

    /**
     * Actualiza nombre, descripción, nivel base (solo roles no del sistema),
     * módulos y acciones de un rol. Las acciones se reemplazan completas (sin
     * privilegios residuales). Limpia la caché del catálogo y registra
     * `role_updated`.
     *
     * Un no admin no puede editar su propio rol ni uno con permisos que él no tiene.
     *
     * @param  Request  $request  Datos del rol.
     * @param  string  $role  Clave (`key`) del rol.
     * @return RedirectResponse Regresa con el resumen actualizado.
     *
     * @throws HttpException 403/404/422 según findEditable() y las reglas de delegación.
     * @throws ValidationException Si los datos no son válidos.
     */
    public function update(Request $request, string $role): RedirectResponse
    {
        $current = $this->findEditable($role);
        $actor = $request->user();

        if (! $actor->isAdmin()) {
            abort_if($actor->role === $current->key, 403, 'No puedes modificar tu propio rol.');
            $currentModules = $this->modules->matrix()[$current->key] ?? [];
            $currentActions = $this->actions->matrix()[$current->key] ?? [];
            abort_if($this->guard->excess($actor, $currentModules, $currentActions) !== [], 403, 'Este rol tiene permisos que el tuyo no tiene; solo un administrador puede modificarlo.');
        }

        $data = $this->validated($request, $current);
        $this->assertGrantable($actor, $data);

        DB::transaction(function () use ($data, $current) {
            DB::table('roles')->where('key', $current->key)->update([
                'name' => $data['name'], 'description' => $data['description'] ?? null,
                // El nivel base de un rol del sistema no cambia (definen el comportamiento estándar).
                'base_level' => $current->is_system ? $current->base_level : $data['base_level'],
                'updated_at' => now(),
            ]);
            $this->catalog->forget();
            $this->modules->saveRole($current->key, $data['modules']);
            // replaceRole: cualquier acción de un módulo que se quitó desaparece (sin privilegios residuales).
            $this->actions->replaceRole($current->key, $data['actions']);
        });
        $this->catalog->forget();

        $summary = $this->summary($data['name'], $data['modules'], $data['actions']);
        $this->audit->log($actor, UserAccessAuditLogger::TYPE_ROLE, (int) $current->id, 'role_updated', null, $current->name, $data['name'], $summary);

        return back()->with('success', "Rol «{$data['name']}» actualizado. {$summary}");
    }

    /**
     * Elimina un rol personalizado sin usuarios asignados: borra sus módulos,
     * acciones y la fila en `roles` (transacción), limpia la caché y registra
     * `role_deleted`. Los roles del sistema no se eliminan.
     *
     * @param  Request  $request  Usuario autenticado.
     * @param  string  $role  Clave (`key`) del rol.
     * @return RedirectResponse Regresa con éxito o con el error de negocio.
     *
     * @throws HttpException 403 si es su propio rol; 404/422 desde findEditable().
     */
    public function destroy(Request $request, string $role): RedirectResponse
    {
        $current = $this->findEditable($role);

        if ($current->is_system) {
            return back()->with('error', 'Los roles del sistema no se pueden eliminar.');
        }
        abort_if(! $request->user()->isAdmin() && $request->user()->role === $current->key, 403, 'No puedes eliminar tu propio rol.');

        $users = User::query()->where('role', $current->key)->count();
        if ($users > 0) {
            return back()->with('error', "No se puede eliminar «{$current->name}»: {$users} usuario(s) lo tienen asignado. Cámbialos de rol primero.");
        }

        DB::transaction(function () use ($current) {
            $this->modules->deleteRole($current->key);
            $this->actions->deleteRole($current->key);
            DB::table('roles')->where('key', $current->key)->delete();
        });
        $this->catalog->forget();
        $this->audit->log($request->user(), UserAccessAuditLogger::TYPE_ROLE, (int) $current->id, 'role_deleted', null, $current->name);

        return back()->with('success', "Rol «{$current->name}» eliminado.");
    }

    /**
     * Resumen legible del rol: "Gerente de Sucursal puede utilizar 4 módulos y
     * tiene 12 acciones permitidas." Solo cuenta acciones de módulos asignados.
     *
     * @param  string  $name  Nombre del rol.
     * @param  array  $modules  Claves de módulos.
     * @param  array  $actions  Mapa módulo → acciones.
     */
    private function summary(string $name, array $modules, array $actions): string
    {
        $m = count($modules);
        $a = 0;
        foreach ($actions as $module => $list) {
            if (in_array($module, $modules, true)) {
                $a += count($list);
            }
        }

        return sprintf('%s puede utilizar %d %s y tiene %d %s.', $name, $m, $m === 1 ? 'módulo' : 'módulos', $a, $a === 1 ? 'acción permitida' : 'acciones permitidas');
    }

    /**
     * Verifica con AccessDelegationGuard que el actor no otorgue módulos o
     * acciones que su propio rol no tiene.
     *
     * @param  User  $actor  Usuario que crea/edita el rol.
     * @param  array  $data  Datos validados (`modules`, `actions`).
     *
     * @throws ValidationException Con la lista de permisos excedentes.
     */
    private function assertGrantable(User $actor, array $data): void
    {
        $missing = $this->guard->excess($actor, $data['modules'], $data['actions']);
        if ($missing !== []) {
            throw ValidationException::withMessages(['modules' => 'No puedes otorgar permisos que tu propio rol no tiene: '.implode(', ', $missing).'.']);
        }
    }

    /**
     * Busca un rol configurable por su clave en la tabla `roles`.
     *
     * @param  string  $key  Clave del rol.
     * @return object Fila cruda de `roles`.
     *
     * @throws HttpException 404 si no existe; 422 si es Administrador.
     */
    private function findEditable(string $key): object
    {
        $role = DB::table('roles')->where('key', $key)->first();
        abort_if($role === null, 404, 'Ese rol ya no existe: otra persona lo eliminó o cambió mientras tenías abierta esta pantalla. Vuelve a abrir Roles y permisos para ver la lista vigente.');
        abort_if($role->key === User::ROLE_ADMIN, 422, 'El rol Administrador tiene acceso total y no se configura.');

        return $role;
    }

    /**
     * Valida y normaliza los datos de un rol: nombre único (sin distinguir
     * mayúsculas, excluyendo el rol actual), nivel base (obligatorio salvo en
     * roles del sistema), módulos sin duplicados y acciones saneadas y
     * limitadas a los módulos elegidos.
     *
     * @param  Request  $request  Datos enviados.
     * @param  object|null  $current  Rol que se edita (null al crear).
     * @return array{name:string, description:?string, base_level:string, modules:list<string>, actions:array<string,list<string>>}
     *
     * @throws ValidationException Si los datos no son válidos o el nombre ya existe.
     */
    private function validated(Request $request, ?object $current = null): array
    {
        $actionRules = [];
        foreach (ActionPermissionService::ACTIONS as $module => $acts) {
            $actionRules["actions.{$module}"] = ['nullable', 'array'];
            $actionRules["actions.{$module}.*"] = ['string', Rule::in(array_keys($acts))];
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'base_level' => [$current?->is_system ? 'nullable' : 'required', Rule::in(RoleCatalogService::LEVELS)],
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', Rule::in(array_keys(ModuleAccessService::MODULES))],
            'actions' => ['nullable', 'array'],
        ] + $actionRules, [
            'name.required' => 'Escribe el nombre del rol.',
            'name.min' => 'El nombre debe tener al menos 3 caracteres.',
            'base_level.required' => 'Elige el nivel base del rol.',
            'modules.*.in' => 'Uno de los módulos elegidos no existe.',
            'actions.*.*.in' => 'Una de las acciones elegidas no existe.',
        ]);

        $duplicate = DB::table('roles')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])
            ->when($current, fn ($q) => $q->where('key', '!=', $current->key))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'Ya existe un rol con ese nombre.']);
        }

        $data['name'] = trim($data['name']);
        $data['base_level'] ??= $current?->base_level ?? User::ROLE_COLABORADOR;
        $data['modules'] = array_values(array_unique($data['modules'] ?? []));
        // Sin módulo no hay acciones dentro de él — nunca se guardan acciones "ocultas".
        $data['actions'] = array_intersect_key($this->actions->sanitize($data['actions'] ?? []), array_flip($data['modules']));

        return $data;
    }
}
