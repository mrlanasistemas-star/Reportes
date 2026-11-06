<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de Resultados — endurecimiento (ADITIVA; no altera columnas existentes).
 *
 *  - estado_resultados_movements: dimensiones INDEPENDIENTES por movimiento
 *    (importación, conciliación, categoría financiera, tratamiento contable,
 *    reconocimiento, revisión, inclusión, fuente que reconoce), fechas separadas,
 *    importes aplicado/pendiente y vínculos de reverso/reembolso/NOI.
 *  - estado_resultados_runs: criterio de reconocimiento versionado y firma de entrada.
 *  - estado_resultados_reconciliation_links: vínculos movimiento ↔ ERP / NOI / banco
 *    con importe aplicado, tipo de emparejamiento y evidencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estado_resultados_movements', function (Blueprint $table) {
            $table->string('import_status', 30)->nullable();
            $table->string('reconciliation_state', 30)->nullable();
            $table->string('match_type', 10)->nullable();
            $table->string('match_group', 20)->nullable();
            $table->decimal('applied_amount', 16, 2)->default(0);
            $table->decimal('pending_amount', 16, 2)->default(0);
            $table->string('financial_category', 40)->nullable();
            $table->string('accounting_treatment', 40)->nullable();
            $table->string('review_status', 20)->nullable();
            $table->string('inclusion_status', 30)->nullable();
            $table->string('recognizing_source', 20)->nullable();
            $table->unsignedBigInteger('recognition_period_id')->nullable();
            $table->string('recognition_basis', 30)->nullable();
            $table->string('out_of_period_kind', 40)->nullable();
            $table->date('operation_date')->nullable();
            $table->date('erp_capture_date')->nullable();
            $table->date('erp_payment_date')->nullable();
            $table->date('accrual_date')->nullable();
            $table->string('reversal_pair_key', 20)->nullable();
            $table->string('refund_target_fingerprint', 64)->nullable();
            $table->string('refund_status', 20)->nullable();
            $table->unsignedBigInteger('noi_employee_id')->nullable();
            $table->string('dimensions_version', 30)->nullable();

            $table->index(['period_id', 'inclusion_status'], 'er_mov_inclusion_idx');
        });

        Schema::table('estado_resultados_runs', function (Blueprint $table) {
            $table->string('recognition_version', 30)->nullable();
            $table->json('recognition_criterion')->nullable();
            $table->char('input_signature', 64)->nullable();
        });

        Schema::create('estado_resultados_reconciliation_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->char('movement_fingerprint', 40);
            $table->string('target_type', 10);              // erp | noi | banco
            $table->string('target_fingerprint', 64);       // huella ERP / employee_id NOI / huella bancaria
            $table->string('link_kind', 20);                // conciliacion | evidencia_noi | reverso | reembolso | traspaso
            $table->string('match_type', 10)->nullable();   // 1:1 | 1:N | N:1 | N:M | parcial
            $table->string('group_key', 20)->nullable();
            $table->decimal('applied_amount', 16, 2)->default(0);
            $table->decimal('movement_amount', 16, 2)->default(0);
            $table->decimal('target_amount', 16, 2)->nullable();
            $table->decimal('movement_pending', 16, 2)->default(0);
            $table->decimal('target_pending', 16, 2)->default(0);
            $table->integer('delta_days')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->unique(['period_id', 'movement_fingerprint', 'target_type', 'target_fingerprint', 'link_kind'], 'er_links_unique');
            $table->index(['period_id', 'target_fingerprint'], 'er_links_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estado_resultados_reconciliation_links');

        Schema::table('estado_resultados_runs', function (Blueprint $table) {
            $table->dropColumn(['recognition_version', 'recognition_criterion', 'input_signature']);
        });

        Schema::table('estado_resultados_movements', function (Blueprint $table) {
            $table->dropIndex('er_mov_inclusion_idx');
            $table->dropColumn([
                'import_status', 'reconciliation_state', 'match_type', 'match_group', 'applied_amount', 'pending_amount',
                'financial_category', 'accounting_treatment', 'review_status', 'inclusion_status', 'recognizing_source',
                'recognition_period_id', 'recognition_basis', 'out_of_period_kind', 'operation_date', 'erp_capture_date',
                'erp_payment_date', 'accrual_date', 'reversal_pair_key', 'refund_target_fingerprint', 'refund_status',
                'noi_employee_id', 'dimensions_version',
            ]);
        });
    }
};
