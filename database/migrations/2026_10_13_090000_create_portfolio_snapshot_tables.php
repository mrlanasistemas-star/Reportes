<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Efectividad V4 — fotografías DIARIAS de cartera (Saldos por Cliente con fecha).
 *
 * Migración ADITIVA: no modifica `fact_portfolios` (cartera mensual de la
 * radiografía, con sus propias reglas de exclusión) ni borra nada.
 *
 *  - `report_uploads.snapshot_date`: fecha REAL de la fotografía. Solo las
 *    cargas de la fuente `lendus_saldos_cliente_foto` la llenan; el resto de
 *    cargas queda en NULL (sin cambio de semántica).
 *  - `portfolio_snapshots`: una fila por archivo de fotografía importado
 *    (fecha, sucursales, encabezados presentes, estado active/replaced/failed).
 *    Una fotografía nueva de la MISMA fecha y sucursal deja a la anterior como
 *    `replaced` (nunca se suman dos fotografías de un mismo día).
 *  - `fact_portfolio_snapshot_rows`: todas las filas del archivo, SIN filtrar
 *    (la macro V4 usa también contratos cerrados, saldos negativos y productos
 *    excluidos para clasificar). Montos en DOUBLE para conservar el valor
 *    binario exacto del Excel (paridad con la macro, que usa Double).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_uploads', function (Blueprint $table) {
            if (! Schema::hasColumn('report_uploads', 'snapshot_date')) {
                $table->date('snapshot_date')->nullable()->after('covered_period_ids');
                $table->index('snapshot_date');
            }
        });

        if (! Schema::hasTable('portfolio_snapshots')) {
            Schema::create('portfolio_snapshots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('report_upload_id')->index();
                $table->unsignedBigInteger('period_id')->nullable()->index();
                $table->date('snapshot_date')->index();
                $table->string('status', 20)->default('active')->index();
                $table->unsignedInteger('rows_count')->default(0);
                $table->json('branch_ids')->nullable();
                $table->json('offices')->nullable();
                $table->json('headers')->nullable();
                $table->unsignedInteger('header_row')->nullable();
                $table->unsignedInteger('source_columns')->nullable();
                $table->string('file_sha1', 40)->nullable();
                $table->string('importer_version', 30)->nullable();
                $table->unsignedBigInteger('replaced_by_id')->nullable();
                $table->timestamp('imported_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('fact_portfolio_snapshot_rows')) {
            Schema::create('fact_portfolio_snapshot_rows', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('portfolio_snapshot_id')->index();
                $table->unsignedBigInteger('report_upload_id')->index();
                $table->date('snapshot_date')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedInteger('source_row');
                $table->string('contract', 60)->nullable()->index();
                $table->string('acreditado', 120)->nullable();
                $table->string('client_code', 60)->nullable();
                $table->string('office_name_raw', 120)->nullable();
                $table->string('promoter_code', 60)->nullable();
                $table->string('promoter_name', 160)->nullable();
                $table->string('status', 60)->nullable();
                $table->string('substatus', 60)->nullable();
                $table->string('product_name_raw', 160)->nullable();
                $table->string('origin', 60)->nullable();
                $table->string('periodicity', 40)->nullable();
                $table->date('first_payment_date')->nullable();
                $table->date('contract_due_date')->nullable();
                $table->date('next_payment_date')->nullable();
                $table->double('installment_total')->nullable();
                $table->double('num_payments')->nullable();
                $table->double('total_overdue')->nullable();
                $table->double('overdue_installments')->nullable();
                $table->double('total_pending')->nullable();
                $table->double('saldo_capital')->nullable();
                $table->double('saldo_interes')->nullable();
                $table->double('saldo_impuesto')->nullable();
                $table->double('saldo_cargos_calendario')->nullable();
                $table->double('saldo_impuesto_cargos_calendario')->nullable();
                $table->double('current_balance')->nullable();
                $table->integer('days_overdue')->nullable();
                $table->integer('days_past_due')->nullable();
                $table->timestamps();
                $table->index(['snapshot_date', 'branch_id']);
            });
        }

        // Fuente de datos (mismo catálogo que el módulo de carga). No es requerida
        // para la base de datos ni para la radiografía: no altera su flujo.
        if (Schema::hasTable('data_sources') && ! DB::table('data_sources')->where('code', 'lendus_saldos_cliente_foto')->exists()) {
            DB::table('data_sources')->insert([
                'code' => 'lendus_saldos_cliente_foto',
                'name' => 'Lendus Saldos por Cliente — fotografía diaria (Efectividad)',
                'description' => 'Mismo reporte Saldos por Cliente de Lendus, identificado con la fecha de la fotografía. Lo usa exclusivamente la Efectividad de Cobranza V4 (domingo base + lunes a domingo).',
                'is_active' => true,
                'is_required_for_bd' => false,
                'is_required_for_report' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_portfolio_snapshot_rows');
        Schema::dropIfExists('portfolio_snapshots');
        if (Schema::hasColumn('report_uploads', 'snapshot_date')) {
            Schema::table('report_uploads', function (Blueprint $table) {
                $table->dropIndex(['snapshot_date']);
                $table->dropColumn('snapshot_date');
            });
        }
        DB::table('data_sources')->where('code', 'lendus_saldos_cliente_foto')->delete();
    }
};
