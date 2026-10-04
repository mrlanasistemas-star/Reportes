<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parte 6/7 del cierre (04-oct-2026) — colocación semanal real por Objective.
 * Un Objective de N semanas recibe hasta N archivos (uno por semana), cada
 * uno preservado como su propia fila — NUNCA se sobrescribe la semana
 * anterior (6.1). `status` distingue la versión vigente de las reemplazadas
 * (6.7: reemplazo controlado, nunca duplicado silencioso).
 *
 * Para scope_type=branch, el archivo trae TODOS los gestores de esa sucursal
 * (ver okr_placement_movements.employee_id) — UNA sola fuente canónica por
 * semana, nunca archivo de sucursal + archivos individuales sumados aparte
 * (6.5, evita duplicar colocación).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('okr_placement_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('okr_objective_id')->constrained('okr_objectives')->cascadeOnDelete();
            $table->unsignedInteger('week_number');
            $table->date('week_start');
            $table->date('week_end');
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('disk')->default('local');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('status')->default('active'); // active|superseded
            $table->foreignId('replaced_upload_id')->nullable()->constrained('okr_placement_uploads')->nullOnDelete();
            $table->decimal('total_amount', 16, 2)->default(0);
            $table->unsignedInteger('rows_count')->default(0);
            $table->decimal('unattributed_amount', 16, 2)->nullable();
            // Filas con fecha fuera del rango semana_inicio/semana_fin (6.6) — se
            // advierte, nunca se mueven silenciosamente de semana.
            $table->unsignedInteger('rows_outside_week_range')->default(0);
            $table->timestamps();

            $table->index(['okr_objective_id', 'week_number', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_placement_uploads');
    }
};
