<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Services\Access\ActionPermissionService;
use App\Services\Access\ModuleAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Guarda qué módulos ve cada rol (14-oct-2026) — tarjeta "Módulos por rol" de
 * "Usuarios y accesos". Solo admin; el rol admin no se configura (ve todo).
 */
class RoleModuleAccessController extends Controller
{
    use AuthorizesRequests;

    /**
     * Guarda la matriz "rol → módulos visibles".
     *
     * Valida que cada rol configurable traiga un arreglo (puede ir vacío) de
     * claves existentes en ModuleAccessService::MODULES y lo persiste vía
     * ModuleAccessService::save().
     *
     * @param  Request  $request  Campo `access[rol][]` con claves de módulo.
     * @param  ModuleAccessService  $access  Servicio que persiste el acceso por rol.
     * @return RedirectResponse Regresa con mensaje de éxito.
     *
     * @throws AuthorizationException Si no tiene `okr.admin`.
     * @throws ValidationException Si la matriz no es válida.
     */
    public function update(Request $request, ModuleAccessService $access): RedirectResponse
    {
        $this->authorize('okr.admin');

        $rules = ['access' => ['required', 'array']];
        foreach (ModuleAccessService::CONFIGURABLE_ROLES as $role) {
            $rules["access.{$role}"] = ['present', 'array'];
            $rules["access.{$role}.*"] = ['string', Rule::in(array_keys(ModuleAccessService::MODULES))];
        }
        $data = $request->validate($rules);

        $access->save($data['access']);

        return back()->with('success', 'Módulos por rol actualizados.');
    }

    /**
     * Guarda la matriz de permisos por ACCIÓN (Fase 7 de la auditoría, 15-oct-2026)
     * — eliminar/exportar dentro de cada módulo, complementaria a update() arriba.
     *
     * @param  Request  $request  Campo `actions[rol][modulo][]` con claves de ActionPermissionService::ACTIONS.
     * @param  ActionPermissionService  $permissions  Servicio que persiste los permisos por acción.
     * @return RedirectResponse Regresa con mensaje de éxito.
     *
     * @throws AuthorizationException Si no tiene `okr.admin`.
     * @throws ValidationException Si la matriz no es válida.
     */
    public function updateActions(Request $request, ActionPermissionService $permissions): RedirectResponse
    {
        $this->authorize('okr.admin');

        $rules = ['actions' => ['required', 'array']];
        foreach (ActionPermissionService::CONFIGURABLE_ROLES as $role) {
            $rules["actions.{$role}"] = ['present', 'array'];
            foreach (ActionPermissionService::ACTIONS as $module => $moduleActions) {
                $rules["actions.{$role}.{$module}"] = ['sometimes', 'array'];
                $rules["actions.{$role}.{$module}.*"] = ['string', Rule::in(array_keys($moduleActions))];
            }
        }
        $data = $request->validate($rules);

        $permissions->save($data['actions']);

        return back()->with('success', 'Permisos por acción actualizados.');
    }
}
