<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADITIVA, solo INSERT (27-oct-2026): nuevas acciones de alcance por AUTOR en
 * Reportes mensuales — `ver_propios` y `ver_todos`. Hasta hoy, cualquier rol con
 * el módulo Reportes listaba y abría los reportes de TODOS los usuarios (dentro
 * de su alcance por sucursal). Para no quitarle a nadie lo que hoy puede ver, a
 * cada rol EXISTENTE que no es Administrador (sistema y personalizados) se le
 * insertan ambas. Los roles creados después eligen explícitamente.
 *
 * Nunca borra filas; insertOrIgnore respeta el UNIQUE(role,module,action).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('role_action_permissions')) {
            return;
        }

        $roles = Schema::hasTable('roles')
            ? DB::table('roles')->where('key', '!=', 'admin')->pluck('key')->all()
            : [];
        if ($roles === []) {
            $roles = ['gerencial', 'colaborador'];
        }

        $now = now();
        $rows = [];
        foreach ($roles as $role) {
            foreach (['ver_propios', 'ver_todos'] as $action) {
                $rows[] = ['role' => $role, 'module' => 'reportes', 'action' => $action, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('role_action_permissions')->insertOrIgnore($chunk);
        }

        Cache::forget('role_action_permissions.v1');
    }

    public function down(): void
    {
        if (Schema::hasTable('role_action_permissions')) {
            DB::table('role_action_permissions')->where('module', 'reportes')->whereIn('action', ['ver_propios', 'ver_todos'])->delete();
            Cache::forget('role_action_permissions.v1');
        }
    }
};
