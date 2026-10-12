<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3/4 (Efectividad de Cobranza Aprobada) — columnas aditivas confirmadas
 * contra un archivo REAL de Saldos por Cliente (documentosEjemplo/Saldos Por
 * ClienteFiltros.xlsx, encabezado real en fila 5) y verificadas línea por
 * línea contra el VBA (`CargarCartera`, `ColumnaEncabezado`).
 *
 * IMPORTANTE — "Saldo capital"/"Saldo interés"/"Saldo impuesto"/"Saldo cargos
 * calendario"/"Saldo impuesto cargos calendario" son columnas DISTINTAS de
 * "Capital atrasado"/"Interés atrasado"/etc. (que ya pueblan `capital_due`/
 * `interes_atrasado`/... desde antes de esta sesión, para la mora histórica).
 * El VBA aprobado busca LITERALMENTE "Saldo capital" (verificado línea por
 * línea: `cSaldoCapital = ColumnaEncabezado(ws, "Saldo capital")`) — nunca se
 * reutilizó `capital_due` para esto, para no arriesgar ni un centavo del
 * cálculo histórico de mora que ya depende de esa columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fact_portfolios', function (Blueprint $table) {
            if (!Schema::hasColumn('fact_portfolios', 'status')) {
                $table->string('status', 60)->nullable()->after('branch_id');
            }
            if (!Schema::hasColumn('fact_portfolios', 'substatus')) {
                $table->string('substatus', 60)->nullable()->after('status');
            }
            if (!Schema::hasColumn('fact_portfolios', 'first_payment_date')) {
                $table->date('first_payment_date')->nullable()->after('substatus');
            }
            if (!Schema::hasColumn('fact_portfolios', 'next_payment_date')) {
                $table->date('next_payment_date')->nullable()->after('first_payment_date');
            }
            if (!Schema::hasColumn('fact_portfolios', 'contract_due_date')) {
                $table->date('contract_due_date')->nullable()->after('next_payment_date')->index();
            }
            if (!Schema::hasColumn('fact_portfolios', 'installment_amount')) {
                $table->decimal('installment_amount', 14, 2)->nullable()->after('contract_due_date');
            }
            if (!Schema::hasColumn('fact_portfolios', 'overdue_installments')) {
                $table->integer('overdue_installments')->nullable()->after('installment_amount');
            }
            if (!Schema::hasColumn('fact_portfolios', 'total_overdue_raw')) {
                $table->decimal('total_overdue_raw', 14, 2)->nullable()->after('overdue_installments');
            }
            if (!Schema::hasColumn('fact_portfolios', 'total_pending_raw')) {
                $table->decimal('total_pending_raw', 14, 2)->nullable()->after('total_overdue_raw');
            }
            // "Saldo X" genérico (VENCIDO) — distinto de "X atrasado" (VIGENTE-con-atraso, ya existente).
            if (!Schema::hasColumn('fact_portfolios', 'saldo_capital_vencido')) {
                $table->decimal('saldo_capital_vencido', 14, 2)->nullable()->after('total_pending_raw');
            }
            if (!Schema::hasColumn('fact_portfolios', 'saldo_interes_vencido')) {
                $table->decimal('saldo_interes_vencido', 14, 2)->nullable()->after('saldo_capital_vencido');
            }
            if (!Schema::hasColumn('fact_portfolios', 'saldo_impuesto_vencido')) {
                $table->decimal('saldo_impuesto_vencido', 14, 2)->nullable()->after('saldo_interes_vencido');
            }
            if (!Schema::hasColumn('fact_portfolios', 'saldo_cargo_calendario_vencido')) {
                $table->decimal('saldo_cargo_calendario_vencido', 14, 2)->nullable()->after('saldo_impuesto_vencido');
            }
            if (!Schema::hasColumn('fact_portfolios', 'saldo_impuesto_cargo_calendario_vencido')) {
                $table->decimal('saldo_impuesto_cargo_calendario_vencido', 14, 2)->nullable()->after('saldo_cargo_calendario_vencido');
            }
            if (!Schema::hasColumn('fact_portfolios', 'importer_version')) {
                $table->string('importer_version', 20)->nullable()->after('raw_payload');
            }
        });

        Schema::table('fact_portfolios', function (Blueprint $table) {
            $table->index(['contract', 'portfolio_date'], 'fact_portfolios_contract_fecha_idx');
            $table->index(['period_id', 'promoter_name'], 'fact_portfolios_period_promotor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fact_portfolios', function (Blueprint $table) {
            $table->dropIndex('fact_portfolios_contract_fecha_idx');
            $table->dropIndex('fact_portfolios_period_promotor_idx');

            $cols = [
                'status', 'substatus', 'first_payment_date', 'next_payment_date', 'contract_due_date',
                'installment_amount', 'overdue_installments', 'total_overdue_raw', 'total_pending_raw',
                'saldo_capital_vencido', 'saldo_interes_vencido', 'saldo_impuesto_vencido',
                'saldo_cargo_calendario_vencido', 'saldo_impuesto_cargo_calendario_vencido', 'importer_version',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('fact_portfolios', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
