<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Access\ActionPermissionService;
use App\Services\Access\ModuleAccessService;
use App\Services\Access\RoleCatalogService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Roles personalizados (16-oct-2026) — el administrador crea un rol, define qué
 * módulos ve y qué acciones sensibles puede hacer, y después crea usuarios con
 * ese rol. Los 3 roles del sistema no se eliminan; admin no se configura.
 */
class RoleController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RoleCatalogService $catalog,
        private readonly ModuleAccessService $modules,
        private readonly ActionPermissionService $actions,
    ) {
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('okr.admin');
        $data = $this->validated($request);
        $key = $this->catalog->makeKey($data['name']);

        DB::transaction(function () use ($data, $key) {
            DB::table('roles')->insert([
                'key' => $key, 'name' => $data['name'], 'description' => $data['description'] ?? null,
                'base_level' => $data['base_level'], 'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->catalog->forget();
            $this->modules->saveRole($key, $data['modules'] ?? []);
            $this->actions->saveRole($key, $data['actions'] ?? []);
        });
        $this->catalog->forget();

        return back()->with('success', "Rol «{$data['name']}» creado. Ya puedes asignarlo al crear o editar usuarios.");
    }

    public function update(Request $request, string $role): RedirectResponse
    {
        $this->authorize('okr.admin');
        $current = $this->findEditable($role);
        $data = $this->validated($request, $current);

        DB::transaction(function () use ($data, $current) {
            DB::table('roles')->where('key', $current->key)->update([
                'name' => $data['name'], 'description' => $data['description'] ?? null,
                // El nivel base de un rol del sistema no cambia (definen el comportamiento estándar).
                'base_level' => $current->is_system ? $current->base_level : $data['base_level'],
                'updated_at' => now(),
            ]);
            $this->catalog->forget();
            $this->modules->saveRole($current->key, $data['modules'] ?? []);
            $this->actions->saveRole($current->key, $data['actions'] ?? []);
        });
        $this->catalog->forget();

        return back()->with('success', "Rol «{$data['name']}» actualizado.");
    }

    public function destroy(string $role): RedirectResponse
    {
        $this->authorize('okr.admin');
        $current = $this->findEditable($role);

        if ($current->is_system) {
            return back()->with('error', 'Los roles del sistema no se pueden eliminar.');
        }

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

        return back()->with('success', "Rol «{$current->name}» eliminado.");
    }

    private function findEditable(string $key): object
    {
        $role = DB::table('roles')->where('key', $key)->first();
        abort_if($role === null, 404, 'El rol ya no existe. Recarga la página.');
        abort_if($role->key === User::ROLE_ADMIN, 422, 'El rol Administrador tiene acceso total y no se configura.');

        return $role;
    }

    /** @return array{name:string, description:?string, base_level:string, modules?:array, actions?:array} */
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
            'base_level.required' => 'Elige el nivel base del rol.',
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

        return $data;
    }
}
