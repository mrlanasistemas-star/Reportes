<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cancelación real de ejecuciones de Efectividad de Cobranza (Fase 4).
 *
 * Solo AGREGA columnas nullable — no modifica ni borra datos existentes.
 *
 *  - cancel_requested_at: marca la SOLICITUD de cancelación. El Job la revisa
 *    en sus puntos de cancelación cooperativa y es quien, exclusivamente,
 *    transiciona el estado a `cancelled` (nunca el controlador: evita que una
 *    ejecución activa quede en un estado inconsistente por una carrera entre
 *    la petición HTTP y el worker).
 *  - cancelled_by: usuario que solicitó la cancelación.
 *  - cancelled_at: cuándo el Job efectivamente cerró la ejecución como cancelada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efectividad_cobranza_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'cancel_requested_at')) {
                $table->timestamp('cancel_requested_at')->nullable()->after('subscriber_ids');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancel_requested_at');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('efectividad_cobranza_runs', function (Blueprint $table) {
            foreach (['cancel_requested_at', 'cancelled_by', 'cancelled_at'] as $column) {
                if (Schema::hasColumn('efectividad_cobranza_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
