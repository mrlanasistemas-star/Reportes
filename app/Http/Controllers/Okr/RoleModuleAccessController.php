<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Services\Access\ModuleAccessService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Guarda qué módulos ve cada rol (14-oct-2026) — tarjeta "Módulos por rol" de
 * "Usuarios y accesos". Solo admin; el rol admin no se configura (ve todo).
 */
class RoleModuleAccessController extends Controller
{
    use AuthorizesRequests;

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
}
