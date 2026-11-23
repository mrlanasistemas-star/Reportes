<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nombre manual de un periodo (23-nov-2026).
 *
 * Solo agrega una columna NULLABLE: no toca `id`, `code`, `name`, fechas ni ninguna
 * relación. Todo lo que cuelga del periodo (fact_*, estado de resultados, cargas,
 * radiografías…) sigue ligado por `period_id`, así que renombrar nunca mueve datos.
 *
 * `name` sigue siendo el nombre del sistema (lo reescribe PeriodGenerationService y
 * algunos procesos lo leen); `custom_name` es el nombre que captura el usuario y, si
 * existe, es el que se muestra (Period::label). Vacío = vuelve al nombre del sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('periods', function (Blueprint $table) {
            $table->string('custom_name', 120)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('periods', function (Blueprint $table) {
            $table->dropColumn('custom_name');
        });
    }
};
