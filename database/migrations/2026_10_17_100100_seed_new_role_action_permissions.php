<?php

use App\Services\Access\ActionPermissionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADITIVA, solo INSERT (prioridad 9): al ampliar el catálogo de permisos por
 * acción, las operaciones que antes solo exigían el módulo ahora tienen un
 * permiso propio. Para no quitarle a nadie lo que hoy puede hacer, a cada rol
 * EXISTENTE (sistema y personalizados) se le insertan las acciones NUEVAS que
 * corresponden a su nivel base (ActionPermissionService::LEVEL_DEFAULTS).
 *
 * - Nunca toca las 4 acciones que ya existían (eliminar carga/periodos,
 *   exportar reportes/colaboradores): su configuración guardada se respeta.
 * - Nunca borra filas; insertOrIgnore respeta el UNIQUE(role,module,action).
 * - Usuarios/Roles y "administrar OKR de otros" no se otorgan a nadie.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('role_action_permissions')) {
            return;
        }

        $roles = Schema::hasTable('roles')
            ? DB::table('roles')->where('key', '!=', 'admin')->get(['key', 'base_level'])->map(fn ($r) => [$r->key, $r->base_level])->all()
            : [];
        if ($roles === []) {
            $roles = [['gerencial', 'gerencial'], ['colaborador', 'colaborador']];
        }

        $now = now();
        $rows = [];
        foreach ($roles as [$role, $level]) {
            $defaults = ActionPermissionService::LEVEL_DEFAULTS[$level === 'gerencial' ? 'gerencial' : 'colaborador'];
            foreach ($defaults as $module => $actions) {
                foreach ($actions as $action) {
                    if (in_array($action, ActionPermissionService::LEGACY_ACTIONS[$module] ?? [], true)) {
                        continue;
                    }
                    $rows[] = ['role' => $role, 'module' => $module, 'action' => $action, 'created_at' => $now, 'updated_at' => $now];
                }
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('role_action_permissions')->insertOrIgnore($chunk);
        }

        Cache::forget('role_action_permissions.v1');
    }

    public function down(): void
    {
        // Sin reversa destructiva: las filas pudieron editarse después desde Roles y permisos.
    }
};
