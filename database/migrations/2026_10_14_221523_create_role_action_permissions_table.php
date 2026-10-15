<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fase 7 de la auditoría (15-oct-2026) — matriz de permisos por ACCIÓN
        // (Eliminar/Exportar/Editar), complementaria a `role_module_access` (que
        // ya controla qué MÓDULOS ve cada rol, enforced por EnsureModuleAccess).
        // Misma filosofía: tabla simple rol×módulo×acción, sin un motor de roles
        // nuevo — reutiliza los 3 roles existentes (User::ROLES). Admin nunca
        // necesita fila aquí (siempre puede todo, ver ActionPermissionService).
        Schema::create('role_action_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role', 40);
            $table->string('module', 40);
            $table->string('action', 40);
            $table->timestamps();
            $table->unique(['role', 'module', 'action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_action_permissions');
    }
};
