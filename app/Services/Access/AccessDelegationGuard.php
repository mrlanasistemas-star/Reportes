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
 *
 * Lo usan los controladores de Usuarios y Roles antes de crear/editar cuentas
 * o roles. No escribe en BD: solo responde si la operación está permitida.
 */
class AccessDelegationGuard
{
    /**
     * @param  ModuleAccessService  $modules  Matriz de módulos por rol (qué pantallas ve cada rol).
     * @param  ActionPermissionService  $actions  Matriz de acciones finas por rol y módulo.
     */
    public function __construct(
        private readonly ModuleAccessService $modules,
        private readonly ActionPermissionService $actions,
    ) {}

    /**
     * Indica si el actor puede administrar (editar, bloquear, cambiar contraseña o
     * rol) a la cuenta objetivo.
     *
     * Regla: el administrador siempre puede; un no-administrador nunca toca a un
     * administrador ni a alguien cuyo alcance por sucursal sea mayor que el suyo
     * (se delega en DataScopeService::contains()).
     *
     * @param  User  $actor  Usuario que intenta la acción.
     * @param  User  $target  Cuenta sobre la que se actuaría.
     * @return bool true si la administración está permitida.
     */
    public function canManageUser(User $actor, User $target): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        // Fase 6.4 — tampoco administra (contraseña, bloqueo, rol...) a alguien con
        // un alcance por sucursal MAYOR que el suyo: sería una forma de escalar.
        return ! $target->isAdmin() && app(DataScopeService::class)->contains($actor, $target);
    }

    /**
     * Indica si el actor puede asignar el rol indicado a un usuario.
     *
     * Regla: el administrador asigna cualquiera; nadie más asigna el rol
     * Administrador, y solo se asignan roles cuyos módulos y acciones estén
     * contenidos en los del propio actor (ver excess()).
     *
     * @param  User  $actor  Usuario que asigna el rol.
     * @param  string  $roleKey  Clave del rol a asignar (users.role).
     * @return bool true si puede otorgarlo.
     */
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
     * Compara los módulos y acciones solicitados contra los del propio actor y
     * devuelve las etiquetas en español de lo que sobra, para mostrarlas en el
     * mensaje de error. El administrador nunca tiene excedente.
     *
     * @param  User  $actor  Usuario que otorga.
     * @param  list<string>  $modules  Claves de módulo que se quieren otorgar.
     * @param  array<string, list<string>>  $actions  Acciones por módulo que se quieren otorgar.
     * @return list<string> Etiquetas únicas de los permisos que el actor no posee.
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
