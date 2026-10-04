<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parte 3 del cierre (04-oct-2026) — Warning Rojo. El sistema detecta
 * desviación y HABILITA "Generar Warning" — la emisión es una decisión
 * explícita de admin/gerencial (3.1, nunca automática). Basado en el
 * snapshot de LA SEMANA evaluada (3.3), nunca en el valor "de hoy".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('okr_warnings', function (Blueprint $table) {
            $table->id();
            $table->string('folio')->unique();
            $table->foreignId('okr_objective_id')->constrained('okr_objectives')->cascadeOnDelete();
            $table->unsignedInteger('week_number');
            $table->date('week_start');
            $table->date('week_end');
            $table->json('snapshot');
            $table->text('corrective_actions');
            $table->text('observations')->nullable();
            $table->foreignId('generated_by')->constrained('users');
            $table->timestamp('generated_at');
            $table->string('stored_path');
            $table->string('disk')->default('local');
            $table->string('signed_stored_path')->nullable();
            $table->string('signed_disk')->nullable();
            $table->foreignId('signed_uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_uploaded_at')->nullable();
            $table->timestamps();

            $table->index(['okr_objective_id', 'week_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_warnings');
    }
};
