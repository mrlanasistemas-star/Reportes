<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Efectividad V4 — versión del motor, parámetros de corte e incidencias de cálculo.
 *
 * ADITIVA. Las ejecuciones existentes se etiquetan con la versión del motor que
 * realmente las calculó (`aprobada-2026-10`, la calculadora anterior) para que
 * nunca se presenten como resultado de la versión nueva. No se recalcula ni
 * borra ninguna ejecución histórica.
 *
 *  - `fact_recoveries.product_name_raw`: texto ORIGINAL de "Producto de crédito".
 *    `product_name` se sigue guardando normalizado (Reportería no cambia); la
 *    macro V4 excluye por el texto original (REESTRUCT/UNIFICAC/CONDONAC/COBERTURA).
 *  - `efectividad_cobranza_run_incidents`: bitácora _AUDITORIA_EFECTIVIDAD de
 *    cada ejecución (consultable, filtrable y exportable; no solo logs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efectividad_cobranza_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'engine_version')) {
                $table->string('engine_version', 40)->nullable()->after('branch_id')->index();
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'mode')) {
                $table->string('mode', 10)->nullable()->after('engine_version');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'base_date')) {
                $table->date('base_date')->nullable()->after('mode');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'cutoff_date')) {
                $table->date('cutoff_date')->nullable()->after('base_date');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'incidents_count')) {
                $table->unsignedInteger('incidents_count')->nullable()->after('cutoff_date');
            }
        });

        DB::table('efectividad_cobranza_runs')->whereNull('engine_version')->update(['engine_version' => 'aprobada-2026-10']);

        Schema::table('fact_recoveries', function (Blueprint $table) {
            if (! Schema::hasColumn('fact_recoveries', 'product_name_raw')) {
                $table->string('product_name_raw', 160)->nullable()->after('product_name');
            }
        });

        if (! Schema::hasTable('efectividad_cobranza_run_incidents')) {
            Schema::create('efectividad_cobranza_run_incidents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('efectividad_cobranza_run_id');
                $table->unsignedInteger('position');
                $table->string('code', 20)->index();
                $table->text('incident_key');
                $table->string('contract', 60)->nullable()->index();
                $table->string('reference', 120)->nullable();
                $table->date('occurred_on')->nullable();
                $table->double('amount')->nullable();
                $table->text('description');
                $table->string('treatment', 40);
                $table->timestamp('created_at')->nullable();
                $table->index('efectividad_cobranza_run_id', 'ec_run_incidents_run_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('efectividad_cobranza_run_incidents');
        if (Schema::hasColumn('fact_recoveries', 'product_name_raw')) {
            Schema::table('fact_recoveries', fn (Blueprint $t) => $t->dropColumn('product_name_raw'));
        }
        Schema::table('efectividad_cobranza_runs', function (Blueprint $table) {
            foreach (['incidents_count', 'cutoff_date', 'base_date', 'mode'] as $c) {
                if (Schema::hasColumn('efectividad_cobranza_runs', $c)) {
                    $table->dropColumn($c);
                }
            }
            if (Schema::hasColumn('efectividad_cobranza_runs', 'engine_version')) {
                $table->dropIndex(['engine_version']);
                $table->dropColumn('engine_version');
            }
        });
    }
};
