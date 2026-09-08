<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Módulo OKR (08-sep-2026) — check-in semanal por Objective. UNIQUE por
     * (objective_id, week_number, user_id): un mismo responsable no puede
     * duplicar el check-in de una semana (evita doble captura por doble clic o
     * dos pestañas). `actual_value_snapshot` es informativo (lo que el sistema
     * mostraba al momento del check-in) — NUNCA sobrescribe el dato automático
     * de Reportería (ver docs/OKR.md "KPI automático vs manual").
     */
    public function up(): void
    {
        Schema::create('okr_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('okr_objective_id')->constrained('okr_objectives')->cascadeOnDelete();
            $table->unsignedInteger('week_number');
            $table->date('check_in_date');
            $table->unsignedBigInteger('user_id');
            $table->text('main_blocker')->nullable();
            $table->text('corrective_action')->nullable();
            $table->json('actual_value_snapshot')->nullable(); // {kpi_id: value} informativo
            $table->timestamps();

            $table->unique(['okr_objective_id', 'week_number', 'user_id']);
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_check_ins');
    }
};
