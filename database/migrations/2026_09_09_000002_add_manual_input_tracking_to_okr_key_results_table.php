<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoría 09-sep-2026 (punto 1 — KPI manual capturable en el check-in):
     * quién y cuándo capturó el último resultado manual — trazabilidad
     * mínima, nunca un historial completo (eso ya lo cubre OkrProgressSnapshot
     * por semana + OkrAuditLog para metas/pesos).
     */
    public function up(): void
    {
        Schema::table('okr_key_results', function (Blueprint $table) {
            $table->foreignId('last_manual_input_by')->nullable()->after('current_value')->constrained('users')->nullOnDelete();
            $table->timestamp('last_manual_input_at')->nullable()->after('last_manual_input_by');
        });
    }

    public function down(): void
    {
        Schema::table('okr_key_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_manual_input_by');
            $table->dropColumn('last_manual_input_at');
        });
    }
};
