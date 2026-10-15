<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('period_employee_rosters', function (Blueprint $table) {
            // Sección 6 de la auditoría (15-oct-2026) — "no aparecer en la nómina de
            // un periodo no demuestra por sí solo una baja laboral". Hasta ahora
            // movement_type='baja' se marcaba igual sin importar la causa: baja
            // real, archivo de NOI incompleto ese mes, o fallo de coincidencia de
            // nombre entre dos archivos. Estas columnas son ADITIVAS — no alteran
            // is_active_for_period/movement_type (de ahí siguen leyendo
            // RotacionDerivedFromNoiService y cualquier reporte ya correcto) — solo
            // clasifican la CONFIANZA de cada baja para que la pantalla de
            // Colaboradores pueda explicarla en vez de darla por hecho.
            $table->string('reconciliation_status', 40)->nullable()->after('movement_type');
            $table->text('reconciliation_note')->nullable()->after('reconciliation_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('period_employee_rosters', function (Blueprint $table) {
            $table->dropColumn(['reconciliation_status', 'reconciliation_note']);
        });
    }
};
