<?php

namespace App\Services\Access;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Permisos por ACCIÓN dentro de un módulo (Fase 7 de la auditoría, 15-oct-2026)
 * — complementario a ModuleAccessService (que decide qué módulos VE cada rol).
 * Un rol puede ver un módulo pero no tener todas sus acciones destructivas o
 * de exportación — esta tabla decide eso. El admin siempre puede todo y no se
 * configura (igual que ModuleAccessService).
 *
 * Solo se catalogan acciones que de verdad son irreversibles o sensibles
 * (eliminar, exportar) — "ver"/"crear"/"editar" ya quedan cubiertos por el
 * gate de módulo existente, que es binario a propósito (abrir uno de estos
 * módulos siempre implica poder operarlo, salvo estas acciones puntuales).
 */
class ActionPermissionService
{
    /** module => [action => label] — solo acciones con permiso propio. */
    public const ACTIONS = [
        'carga' => ['eliminar' => 'Eliminar archivos cargados'],
        'periodos' => ['eliminar' => 'Eliminar periodos'],
        'reportes' => ['exportar' => 'Exportar Excel/PDF'],
        'colaboradores' => ['exportar' => 'Exportar historial de colaboradores'],
    ];

    public const CONFIGURABLE_ROLES = [User::ROLE_GERENCIAL, User::ROLE_COLABORADOR];

    // Por defecto, gerencial conserva el comportamiento que ya tenía (podía
    // hacer todo dentro de un módulo al que tuviera acceso) — nadie pierde
    // una capacidad que ya usaba el día que se active esta tabla. Colaborador
    // nunca tuvo estos módulos de todas formas (ver ModuleAccessService::DEFAULTS).
    private const DEFAULTS = [
        User::ROLE_GERENCIAL => [
            'carga' => ['eliminar'], 'periodos' => ['eliminar'],
            'reportes' => ['exportar'], 'colaboradores' => ['exportar'],
        ],
        User::ROLE_COLABORADOR => [],
    ];

    private const CACHE_KEY = 'role_action_permissions.v1';

    /** @return array<string, array<string, list<string>>> rol => módulo => acciones permitidas. */
    public function matrix(): array
    {
        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('role_action_permissions')
                ->get(['role', 'module', 'action'])
                ->groupBy('role')
                ->map(fn ($rows) => $rows->groupBy('module')->map(fn ($r) => $r->pluck('action')->all())->all())
                ->all());
        } catch (\Throwable) {
            return self::DEFAULTS;
        }

        $matrix = [];
        foreach ($this->configurableRoles() as $role) {
            $matrix[$role] = [];
            foreach (self::ACTIONS as $module => $actions) {
                $matrix[$role][$module] = array_values(array_intersect(array_keys($actions), $stored[$role][$module] ?? []));
            }
        }

        return $matrix;
    }

    /** Nunca confiar solo en la UI — el backend vuelve a comprobar esto en cada endpoint sensible. */
    public function canAction(?User $user, string $module, string $action): bool
    {
        if (!$user) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        $role = in_array($user->role, $this->configurableRoles(), true)
            ? $user->role
            : ($user->isGerencial() ? User::ROLE_GERENCIAL : User::ROLE_COLABORADOR);

        return in_array($action, $this->matrix()[$role][$module] ?? [], true);
    }

    /** 16-oct-2026 — roles del sistema + personalizados (todos menos admin). @return list<string> */
    private function configurableRoles(): array
    {
        return app(RoleCatalogService::class)->configurableKeys() ?: self::CONFIGURABLE_ROLES;
    }

    /** @param array<string, list<string>> $actions módulo => acciones */
    public function saveRole(string $role, array $actions): void
    {
        $this->save([$role => $actions]);
    }

    public function deleteRole(string $role): void
    {
        DB::table('role_action_permissions')->where('role', $role)->delete();
        Cache::forget(self::CACHE_KEY);
    }

    /** @param array<string, array<string, list<string>>> $matrix */
    public function save(array $matrix): void
    {
        DB::transaction(function () use ($matrix) {
            $now = now();
            // Solo los roles enviados (y configurables) — guardar uno no borra los demás.
            foreach (array_intersect(array_keys($matrix), $this->configurableRoles()) as $role) {
                DB::table('role_action_permissions')->where('role', $role)->delete();
                $rows = [];
                foreach (self::ACTIONS as $module => $actions) {
                    foreach (array_intersect(array_keys($actions), $matrix[$role][$module] ?? []) as $action) {
                        $rows[] = ['role' => $role, 'module' => $module, 'action' => $action, 'created_at' => $now, 'updated_at' => $now];
                    }
                }
                if ($rows !== []) {
                    DB::table('role_action_permissions')->insert($rows);
                }
            }
        });

        Cache::forget(self::CACHE_KEY);
    }

    /** Catálogo para la UI: lista plana de [module, action, label]. */
    public function catalog(): array
    {
        $out = [];
        foreach (self::ACTIONS as $module => $actions) {
            foreach ($actions as $action => $label) {
                $out[] = ['module' => $module, 'action' => $action, 'label' => $label];
            }
        }

        return $out;
    }
}
