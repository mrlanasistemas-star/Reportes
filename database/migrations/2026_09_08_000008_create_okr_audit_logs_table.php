<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Módulo OKR (08-sep-2026) — bitácora de auditoría. Toda modificación de
     * meta/peso/plazo/KPI/responsable pasa por aquí con motivo — "UNA META NO
     * SE PUEDE MODIFICAR SILENCIOSAMENTE" (ver OkrAuditLogger).
     */
    public function up(): void
    {
        Schema::create('okr_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('auditable_type', 60); // 'objective'|'key_result'
            $table->unsignedBigInteger('auditable_id');
            $table->unsignedBigInteger('user_id');
            $table->string('action', 40); // created|activated|field_changed|closed|cancelled
            $table->string('field', 60)->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users');
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_audit_logs');
    }
};
