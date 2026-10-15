<?php

namespace App\Services\Access;

use App\Models\User;

/**
 * Candados de delegación (prioridad 8/9): Usuarios y Roles pueden delegarse a
 * un rol que no es Administrador, pero NADIE puede otorgar más de lo que su
 * propio rol tiene — si no, bastaría con crear un rol "con todo" y asignárselo
 * a un usuario nuevo. El Administrador no tiene límites.
 *
 * Reglas para un actor que NO es administrador:
 *   - nunca crea, edita, bloquea ni cambia la contraseña de un Administrador;
 *   - nunca asigna el rol Administrador;
 *   - solo asigna roles cuyos módulos y acciones estén contenidos en los suyos;
 *   - solo edita roles (y solo les otorga permisos) contenidos en los suyos, y
 *     nunca su propio rol.
 */
class AccessDelegationGuard
{
    public function __construct(
        private readonly ModuleAccessService $modules,
        private readonly ActionPermissionService $actions,
    ) {
    }

    public function canManageUser(User $actor, User $target): bool
    {
        return $actor->isAdmin() || ! $target->isAdmin();
    }

    public function canGrantRole(User $actor, string $roleKey): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }
        if ($roleKey === User::ROLE_ADMIN) {
            return false;
        }

        $roleModules = $this->modules->matrix()[$roleKey] ?? [];
        $roleActions = $this->actions->matrix()[$roleKey] ?? [];

        return $this->excess($actor, $roleModules, $roleActions) === [];
    }

    /**
     * Permisos pedidos que el actor NO tiene (etiquetas legibles). Vacío = puede otorgarlos.
     *
     * @param list<string> $modules
     * @param array<string, list<string>> $actions
     * @return list<string>
     */
    public function excess(User $actor, array $modules, array $actions): array
    {
        if ($actor->isAdmin()) {
            return [];
        }

        $ownModules = $this->modules->modulesFor($actor);
        $ownActions = $this->actions->actionsFor($actor);
        $missing = [];

        foreach (array_diff($modules, $ownModules) as $module) {
            $missing[] = ModuleAccessService::MODULES[$module]['label'] ?? $module;
        }
        foreach ($actions as $module => $list) {
            foreach (array_diff((array) $list, $ownActions[$module] ?? []) as $action) {
                $missing[] = ActionPermissionService::ACTIONS[$module][$action]['label'] ?? "{$module}.{$action}";
            }
        }

        return array_values(array_unique($missing));
    }
}
