<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prioridad 4 del cierre OKR — atribución segura de colocación. Solo AGREGA
 * columnas (nullable / default 0): las filas existentes no se tocan.
 *   - attribution_status: 'attributed' | 'pending' (null = carga anterior a este cambio).
 *   - attribution_method: cómo se identificó al gestor (employee_code, name_exact, ...).
 *   - attribution_note: explicación visible del motivo de un pendiente.
 *   - okr_placement_uploads.rows_pending_attribution: movimientos que no se
 *     suman a ningún gestor (sí al total de la sucursal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('okr_placement_movements', function (Blueprint $table) {
            $table->string('attribution_status', 20)->nullable()->after('employee_name_raw');
            $table->string('attribution_method', 40)->nullable()->after('attribution_status');
            $table->string('attribution_note', 600)->nullable()->after('attribution_method');
        });

        Schema::table('okr_placement_uploads', function (Blueprint $table) {
            $table->unsignedInteger('rows_pending_attribution')->default(0)->after('rows_out_of_objective_range');
        });
    }

    public function down(): void
    {
        Schema::table('okr_placement_movements', function (Blueprint $table) {
            $table->dropColumn(['attribution_status', 'attribution_method', 'attribution_note']);
        });
        Schema::table('okr_placement_uploads', function (Blueprint $table) {
            $table->dropColumn('rows_pending_attribution');
        });
    }
};
