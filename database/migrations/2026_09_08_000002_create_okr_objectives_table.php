<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Módulo OKR (08-sep-2026) — Objective. FASE ACTUAL: solo dos niveles reales
     * (Sucursal → Gestor) — `parent_id`/`scope_type` dejan la puerta abierta a
     * niveles futuros (Corporativo/Regional/Gerente) SIN desarrollarlos ahora
     * (ver docs/OKR.md). `scope_type`='branch' requiere branch_id;
     * `scope_type`='employee' requiere employee_id + normalmente parent_id
     * apuntando al Objective de sucursal que agrupa (opcional).
     *
     * `baseline_locked_at`/`activated_at` marcan el momento en que la línea
     * base de cada KR queda congelada — NUNCA se recalcula automáticamente
     * después de eso (ver OkrKeyResult).
     */
    public function up(): void
    {
        Schema::create('okr_objectives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('scope_type', 20); // branch|employee
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('title');
            $table->unsignedBigInteger('responsible_user_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('duration_weeks');
            $table->string('lifecycle_status', 20)->default('draft'); // draft|active|closed|cancelled
            $table->string('health_status', 20)->nullable(); // ahead|on_track|risk|off_track
            $table->string('final_status', 30)->nullable(); // completed|partially_completed|not_completed
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['scope_type', 'branch_id']);
            $table->index(['scope_type', 'employee_id']);
            $table->index('lifecycle_status');
            $table->index('parent_id');
            $table->foreign('parent_id')->references('id')->on('okr_objectives')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('responsible_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_objectives');
    }
};
