<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gastos financieros — comisiones y tipo de documento (24-nov-2026). ADITIVA.
 *
 * La tabla real de LEROM (simulador) NO trae interés: trae «Importe Comisión» (comisión por
 * apertura del 7 % prorrateada en 180 pagos diarios) + «IVA Comisión». Se guardan aparte del
 * interés y alimentan un concepto propio del ER (comisiones_financieras). El IVA nunca es gasto.
 *
 *  - amortization_rows: commission, commission_vat (nullable).
 *  - amortization_uploads: total_commission, total_commission_vat, document_kind (simulacion|definitivo).
 *  - credits: commission_concept (concepto de ajuste de la comisión; LEROM ⇒ comisiones_financieras).
 *  - Datos: LEROM paga en días hábiles ⇒ periodicity 'diaria' (sólo si seguía en el valor
 *    sembrado 'semanal'; nunca pisa un cambio hecho a mano).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estado_resultados_amortization_rows', function (Blueprint $table) {
            if (! Schema::hasColumn('estado_resultados_amortization_rows', 'commission')) {
                $table->decimal('commission', 16, 2)->nullable()->after('interest_vat');
                $table->decimal('commission_vat', 16, 2)->nullable()->after('commission');
            }
        });

        Schema::table('estado_resultados_amortization_uploads', function (Blueprint $table) {
            if (! Schema::hasColumn('estado_resultados_amortization_uploads', 'total_commission')) {
                $table->decimal('total_commission', 16, 2)->default(0)->after('total_interest_vat');
                $table->decimal('total_commission_vat', 16, 2)->default(0)->after('total_commission');
                $table->string('document_kind', 20)->default('simulacion')->after('status'); // simulacion | definitivo
            }
        });

        Schema::table('estado_resultados_credits', function (Blueprint $table) {
            if (! Schema::hasColumn('estado_resultados_credits', 'commission_concept')) {
                $table->string('commission_concept', 40)->nullable()->after('adjustment_concept');
            }
        });

        DB::table('estado_resultados_credits')->where('key', 'lerom')->whereNull('commission_concept')
            ->update(['commission_concept' => 'comisiones_financieras', 'updated_at' => now()]);
        DB::table('estado_resultados_credits')->where('key', 'lerom')->where('periodicity', 'semanal')
            ->update(['periodicity' => 'diaria', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('estado_resultados_credits')->where('key', 'lerom')->where('periodicity', 'diaria')
            ->update(['periodicity' => 'semanal']);
        Schema::table('estado_resultados_credits', function (Blueprint $table) {
            $table->dropColumn('commission_concept');
        });
        Schema::table('estado_resultados_amortization_uploads', function (Blueprint $table) {
            $table->dropColumn(['total_commission', 'total_commission_vat', 'document_kind']);
        });
        Schema::table('estado_resultados_amortization_rows', function (Blueprint $table) {
            $table->dropColumn(['commission', 'commission_vat']);
        });
    }
};
