<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoría 09-sep-2026 (punto 4 — backfill/trazabilidad de fuente): antes
     * un snapshot solo guardaba `source_reference` como texto libre
     * ("period:21"). Ahora se guarda de forma explícita y consultable qué
     * periodo/fecha/granularidad/calidad de dato originó cada snapshot —
     * necesario para que la UI pueda decir "Fuente mensual" vs "Último cierre
     * disponible" en vez de fingir granularidad semanal real que no existe.
     */
    public function up(): void
    {
        Schema::table('okr_progress_snapshots', function (Blueprint $table) {
            $table->foreignId('source_period_id')->nullable()->after('source_reference')->constrained('periods')->nullOnDelete();
            $table->string('source_period_code', 80)->nullable()->after('source_period_id');
            $table->date('source_date')->nullable()->after('source_period_code');
            $table->string('source_granularity', 20)->nullable()->after('source_date'); // monthly|weekly|manual
            $table->string('source_quality', 20)->nullable()->after('source_granularity'); // exact|monthly_proxy|last_available|manual_checkin
        });
    }

    public function down(): void
    {
        Schema::table('okr_progress_snapshots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_period_id');
            $table->dropColumn(['source_period_code', 'source_date', 'source_granularity', 'source_quality']);
        });
    }
};
