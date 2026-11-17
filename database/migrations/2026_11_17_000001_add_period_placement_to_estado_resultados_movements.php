<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asignación de movimientos bancarios al periodo por FECHA (17-nov-2026).
 *
 * Los estados de cuenta se suben por mes calendario (1 al 30/31) pero los periodos
 * operativos son de 4 semanas. Cada movimiento se asigna al periodo mensual que
 * contiene su fecha, sin importar en qué periodo se subió el archivo:
 *   - placement: periodo | sin_periodo | duplicado | periodo_cerrado
 *   - dedup_key: 0 = movimiento vigente del periodo; = id cuando queda "estacionado"
 *     (duplicado de otro archivo, sin periodo mensual o destino aprobado). Permite que
 *     la llave única (period_id, fingerprint) siga impidiendo duplicados vigentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estado_resultados_movements', function (Blueprint $table) {
            $table->string('placement', 20)->default('periodo')->after('in_period');
            $table->unsignedBigInteger('dedup_key')->default(0)->after('placement');
        });

        // Primero el índice nuevo (empieza por period_id: sigue sirviendo a la llave foránea)…
        Schema::table('estado_resultados_movements', function (Blueprint $table) {
            $table->unique(['period_id', 'fingerprint', 'dedup_key'], 'er_mov_period_fp_dedup_unique');
        });
        // …y después se quita el anterior.
        Schema::table('estado_resultados_movements', function (Blueprint $table) {
            $table->dropUnique('er_mov_period_fp_unique');
        });
    }

    public function down(): void
    {
        Schema::table('estado_resultados_movements', function (Blueprint $table) {
            $table->unique(['period_id', 'fingerprint'], 'er_mov_period_fp_unique');
        });
        Schema::table('estado_resultados_movements', function (Blueprint $table) {
            $table->dropUnique('er_mov_period_fp_dedup_unique');
            $table->dropColumn(['placement', 'dedup_key']);
        });
    }
};
