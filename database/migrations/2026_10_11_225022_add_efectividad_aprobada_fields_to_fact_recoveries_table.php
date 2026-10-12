<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2/4 (Efectividad de Cobranza Aprobada) — columnas aditivas confirmadas
 * contra la hoja real `_HIST_INGRESOS` del Excel aprobado y contra el VBA real
 * (`ColumnaEncabezado`). Ninguna sustituye una columna existente:
 *
 *   `payment_date` (ya existente) sigue siendo "Fecha transacción" — se
 *   usaba así antes de esta sesión para la efectividad histórica y el resto
 *   del sistema; NUNCA se tocó su significado ni su población.
 *
 *   `installment_due_date` es la columna NUEVA para "Fecha cuota" — antes de
 *   esta migración, `payment_date` conflaba ambas fechas en el mapeador de
 *   encabezados (un bug real, corregido en ColumnMapService en esta misma
 *   sesión) — nunca se reconstruye, solo se deja de perder si el Excel la trae.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fact_recoveries', function (Blueprint $table) {
            if (!Schema::hasColumn('fact_recoveries', 'installment_due_date')) {
                $table->date('installment_due_date')->nullable()->after('payment_date')->index();
            }
            if (!Schema::hasColumn('fact_recoveries', 'installment_number')) {
                $table->string('installment_number', 30)->nullable()->after('installment_due_date');
            }
            if (!Schema::hasColumn('fact_recoveries', 'contract_due_date')) {
                $table->date('contract_due_date')->nullable()->after('installment_number');
            }
            if (!Schema::hasColumn('fact_recoveries', 'acreditado')) {
                $table->string('acreditado', 180)->nullable()->after('client_name');
            }
            if (!Schema::hasColumn('fact_recoveries', 'office_name_raw')) {
                $table->string('office_name_raw', 180)->nullable()->after('branch_id');
            }
            if (!Schema::hasColumn('fact_recoveries', 'importer_version')) {
                $table->string('importer_version', 20)->nullable()->after('raw_payload');
            }
        });

        Schema::table('fact_recoveries', function (Blueprint $table) {
            $table->index(['contract', 'installment_due_date'], 'fact_recoveries_contract_cuota_idx');
            $table->index(['period_id', 'payment_date'], 'fact_recoveries_period_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fact_recoveries', function (Blueprint $table) {
            $table->dropIndex('fact_recoveries_contract_cuota_idx');
            $table->dropIndex('fact_recoveries_period_fecha_idx');

            $cols = ['installment_due_date', 'installment_number', 'contract_due_date', 'acreditado', 'office_name_raw', 'importer_version'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('fact_recoveries', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
