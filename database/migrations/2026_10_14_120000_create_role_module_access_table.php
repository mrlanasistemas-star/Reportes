<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADITIVA — módulos que puede ver cada rol (14-oct-2026). Se administra desde
 * "Usuarios y accesos". El rol admin NO tiene filas: siempre ve todo (ver
 * ModuleAccessService). Se siembra con el comportamiento previo para no
 * cambiar el acceso de nadie al migrar: gerencial = todo Reportería + OKR,
 * colaborador = solo OKR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_module_access', function (Blueprint $table) {
            $table->id();
            $table->string('role', 32);
            $table->string('module', 48);
            $table->timestamps();
            $table->unique(['role', 'module']);
        });

        $now = now();
        $defaults = [
            'gerencial' => ['dashboard', 'carga', 'periodos', 'colaboradores', 'reportes', 'efectividad', 'okr'],
            'colaborador' => ['okr'],
        ];

        foreach ($defaults as $role => $modules) {
            DB::table('role_module_access')->insert(array_map(
                fn ($module) => ['role' => $role, 'module' => $module, 'created_at' => $now, 'updated_at' => $now],
                $modules,
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_module_access');
    }
};
