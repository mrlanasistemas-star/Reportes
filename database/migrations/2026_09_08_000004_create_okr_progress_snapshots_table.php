<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Módulo OKR (08-sep-2026) — histórico semanal por Key Result (idempotente:
     * mismo key_result_id + week_number = update, NUNCA duplicado — ver UNIQUE
     * abajo y OkrSnapshotService). Alimenta la gráfica esperado-vs-real, la
     * proyección de cierre y la auditoría — nunca se borra al cerrar el OKR.
     */
    public function up(): void
    {
        Schema::create('okr_progress_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('okr_key_result_id')->constrained('okr_key_results')->cascadeOnDelete();
            $table->unsignedInteger('week_number');
            $table->date('snapshot_date');
            $table->decimal('actual_value', 18, 4)->nullable();
            $table->decimal('expected_value', 18, 4)->nullable();
            $table->decimal('actual_progress_percentage', 7, 2)->nullable();
            $table->decimal('expected_progress_percentage', 7, 2)->nullable();
            $table->decimal('deviation_pp', 7, 2)->nullable();
            $table->decimal('projected_value', 18, 4)->nullable();
            $table->decimal('projected_compliance_percentage', 7, 2)->nullable();
            $table->string('health_status', 20)->nullable();
            $table->string('source_reference', 120)->nullable(); // ej. "period:21" — trazabilidad
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['okr_key_result_id', 'week_number']);
            $table->index('snapshot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_progress_snapshots');
    }
};
