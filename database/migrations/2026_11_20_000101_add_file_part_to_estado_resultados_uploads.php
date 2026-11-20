<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos archivos por estado de cuenta (20-nov-2026). ADITIVA.
 *
 * Cada cuenta (administrativa / excedentes) admite el «Archivo 1» (obligatorio, como hasta
 * hoy) y un «Archivo 2» opcional. `file_part` dice a cuál de los dos corresponde la carga
 * o el intento; todo lo existente queda como archivo 1.
 *
 * No cambia ninguna llave única: la huella del movimiento sigue siendo sha1(cuenta|base),
 * así que un movimiento idéntico en el archivo 1 y en el 2 tiene la MISMA huella y el
 * reparto por fecha (MovementPeriodAllocator) lo cuenta una sola vez (la copia queda
 * como «duplicado» con dedup_key = id, sin chocar con la llave (period_id, fingerprint, dedup_key)).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estado_resultados_uploads', function (Blueprint $table) {
            $table->unsignedTinyInteger('file_part')->default(1)->after('account_slot');
            $table->index(['period_id', 'account_slot', 'file_part', 'status'], 'er_uploads_period_slot_part_idx');
        });

        Schema::table('estado_resultados_import_attempts', function (Blueprint $table) {
            $table->unsignedTinyInteger('file_part')->default(1)->after('account_slot');
        });
    }

    public function down(): void
    {
        Schema::table('estado_resultados_import_attempts', function (Blueprint $table) {
            $table->dropColumn('file_part');
        });
        Schema::table('estado_resultados_uploads', function (Blueprint $table) {
            $table->dropIndex('er_uploads_period_slot_part_idx');
            $table->dropColumn('file_part');
        });
    }
};
