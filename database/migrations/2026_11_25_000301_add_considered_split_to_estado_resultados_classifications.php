<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Ya considerado» (25-nov-2026): una clasificación manual puede apartar PARTE del importe de un
 * movimiento como ya considerado (Radiografía, otro periodo u otra fuente) y clasificar el resto.
 *
 *  - considered_amount: importe ya considerado (NULL = sin reparto; la fila aplica a todo el importe).
 *  - considered_reason: motivo breve (radiografia | otro_periodo | otra_fuente).
 *
 * Migración ADITIVA: columnas nulas, sin tocar filas existentes (bitácora append-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estado_resultados_classifications', function (Blueprint $table) {
            if (! Schema::hasColumn('estado_resultados_classifications', 'considered_amount')) {
                $table->decimal('considered_amount', 15, 2)->nullable()->after('previous_treatment');
            }
            if (! Schema::hasColumn('estado_resultados_classifications', 'considered_reason')) {
                $table->string('considered_reason', 40)->nullable()->after('considered_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('estado_resultados_classifications', function (Blueprint $table) {
            foreach (['considered_reason', 'considered_amount'] as $col) {
                if (Schema::hasColumn('estado_resultados_classifications', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
