<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADITIVA (Fase 6.4 — alcance por sucursal real). No modifica ni borra datos.
 *
 *   - users.branch_scope (nullable): 'all' = todas las sucursales (elección
 *     EXPLÍCITA de un administrador); 'branches' = solo las sucursales listadas
 *     en user_branch_scopes. NULL = usuario previo a esta migración: conserva el
 *     comportamiento anterior (nivel gerencial → todas; nivel colaborador → solo
 *     lo propio) hasta que un administrador lo restrinja. Ver
 *     App\Services\Access\DataScopeService.
 *   - user_branch_scopes: sucursales autorizadas por usuario (solo aplica con
 *     branch_scope = 'branches').
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'branch_scope')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('branch_scope', 16)->nullable();
            });
        }

        if (! Schema::hasTable('user_branch_scopes')) {
            Schema::create('user_branch_scopes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['user_id', 'branch_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_branch_scopes');

        if (Schema::hasColumn('users', 'branch_scope')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('branch_scope');
            });
        }
    }
};
