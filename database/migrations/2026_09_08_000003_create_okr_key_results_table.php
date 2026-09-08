<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Módulo OKR (08-sep-2026) — Key Result. Los campos calculados
     * (current_value, expected_value, *_progress_percentage, deviation_pp,
     * projected_*, health_status) son SNAPSHOT/CACHE de la última evaluación
     * (OkrSnapshotService) — la fuente real sigue viviendo en Reportería
     * (RadiografiaExportService::buildSnapshot()), nunca al revés (ver
     * docs/OKR.md sección "OKR es consumidor").
     *
     * baseline_value/target_value quedan CONGELADOS tras activar el Objective
     * (baseline_locked_at) — cualquier cambio posterior pasa por
     * OkrAuditLogger con motivo obligatorio.
     */
    public function up(): void
    {
        Schema::create('okr_key_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('okr_objective_id')->constrained('okr_objectives')->cascadeOnDelete();
            $table->foreignId('kpi_id')->constrained('okr_kpis');
            $table->string('description');
            $table->decimal('baseline_value', 18, 4)->nullable();
            $table->string('baseline_source', 60)->nullable(); // provider_key usado, o 'manual'
            $table->date('baseline_period_date')->nullable();
            $table->timestamp('baseline_locked_at')->nullable();
            $table->decimal('target_value', 18, 4);
            $table->decimal('weight', 5, 2); // 0.00 .. 100.00

            // Snapshot/cache — nunca fuente de verdad, ver docblock de arriba.
            $table->decimal('current_value', 18, 4)->nullable();
            $table->decimal('expected_value', 18, 4)->nullable();
            $table->decimal('actual_progress_percentage', 7, 2)->nullable();
            $table->decimal('expected_progress_percentage', 7, 2)->nullable();
            $table->decimal('deviation_pp', 7, 2)->nullable();
            $table->decimal('projected_value', 18, 4)->nullable();
            $table->decimal('projected_compliance_percentage', 7, 2)->nullable();
            $table->string('health_status', 20)->nullable();
            $table->timestamp('last_evaluated_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('okr_objective_id');
            $table->index('kpi_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_key_results');
    }
};
