<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles personalizados (16-oct-2026): catálogo de roles que el administrador
 * crea y configura (módulos que ve + acciones). `users.role` sigue guardando la
 * CLAVE del rol, y role_module_access / role_action_permissions ya están
 * indexadas por esa clave — no se crea un sistema de permisos paralelo.
 * `base_level` define el comportamiento general (gerencial = ve y gestiona OKR
 * de otros; colaborador = solo lo propio). Aditiva: siembra los 3 roles del
 * sistema sin tocar usuarios existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('key', 40)->unique();
                $table->string('name', 80);
                $table->string('description', 255)->nullable();
                $table->string('base_level', 20)->default('colaborador'); // admin | gerencial | colaborador
                $table->boolean('is_system')->default(false);
                $table->timestamps();
            });
        }

        $now = now();
        foreach ([
            ['admin', 'Administrador', 'Acceso total al sistema. No se configura.', 'admin'],
            ['gerencial', 'Gerencial', 'Gestiona módulos operativos y los OKR de su equipo.', 'gerencial'],
            ['colaborador', 'Colaborador', 'Consulta y da seguimiento a sus propios OKR.', 'colaborador'],
        ] as [$key, $name, $description, $level]) {
            DB::table('roles')->updateOrInsert(['key' => $key], [
                'name' => $name, 'description' => $description, 'base_level' => $level, 'is_system' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
