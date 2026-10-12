<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría 2026-10-12, Fase 5/9 — linaje de fuentes y estados reales:
 *
 *  - `source_fingerprint`: huella barata (count+max(id)+max(updated_at) de
 *    fact_portfolios de este periodo + fact_recoveries global) calculada al
 *    generar. Permite detectar en lectura si el snapshot quedó obsoleto
 *    (se reimportó/reprocesó algo) SIN depender de interceptar cada punto del
 *    código que pudiera modificar esas tablas — se recalcula y compara, nunca
 *    se "marca" desde otro lugar (así nunca queda desincronizada).
 *  - `saldos_report_upload_id` / `ingresos_report_upload_id`: el upload de
 *    Saldos por Cliente de ESTE periodo (snapshot único) y el upload de
 *    Ingresos Cobranza más reciente de este periodo (el histórico completo
 *    usado por la macro abarca más uploads que ese; éste es solo el de
 *    referencia para mostrar en pantalla/auditoría, ver `source_fingerprint`
 *    para la invalidación real).
 *  - `status`: ya existía como string libre; esta migración no cambia su
 *    tipo (sigue siendo string), solo documenta que ahora distingue
 *    queued|running|success|blocked|failed (antes "blocked" se guardaba
 *    incorrectamente como "success").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efectividad_cobranza_runs', function (Blueprint $table) {
            if (!Schema::hasColumn('efectividad_cobranza_runs', 'source_fingerprint')) {
                $table->string('source_fingerprint', 64)->nullable()->after('coverage');
            }
            if (!Schema::hasColumn('efectividad_cobranza_runs', 'saldos_report_upload_id')) {
                $table->foreignId('saldos_report_upload_id')->nullable()->after('branch_id')
                    ->constrained('report_uploads')->nullOnDelete();
            }
            if (!Schema::hasColumn('efectividad_cobranza_runs', 'ingresos_report_upload_id')) {
                $table->foreignId('ingresos_report_upload_id')->nullable()->after('saldos_report_upload_id')
                    ->constrained('report_uploads')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('efectividad_cobranza_runs', function (Blueprint $table) {
            if (Schema::hasColumn('efectividad_cobranza_runs', 'ingresos_report_upload_id')) {
                $table->dropConstrainedForeignId('ingresos_report_upload_id');
            }
            if (Schema::hasColumn('efectividad_cobranza_runs', 'saldos_report_upload_id')) {
                $table->dropConstrainedForeignId('saldos_report_upload_id');
            }
            if (Schema::hasColumn('efectividad_cobranza_runs', 'source_fingerprint')) {
                $table->dropColumn('source_fingerprint');
            }
        });
    }
};
