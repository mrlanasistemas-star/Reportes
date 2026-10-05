<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre real OKR (05-oct-2026) — identidad persistente User↔Employee.
 * Hasta ahora App\Models\User no tenía vínculo real con Employee: un
 * Objective individual guarda employee_id (quién ES evaluado) Y
 * responsible_user_id (quién le da seguimiento administrativo) por
 * separado, y no son necesariamente la misma persona — sin este vínculo, un
 * colaborador podía quedar sin forma de ver/dar check-in a SU PROPIO
 * Objective si no era también el responsible_user_id.
 *
 * 1 User = 0/1 Employee (nullable, UNIQUE — un Employee no puede quedar
 * vinculado a dos Users a la vez). ADMIN/GERENCIAL típicamente quedan con
 * employee_id NULL (no son "evaluados" como colaboradores operativos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('role')
                ->constrained('employees')->nullOnDelete();
            $table->unique('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['employee_id']);
            $table->dropConstrainedForeignId('employee_id');
        });
    }
};
