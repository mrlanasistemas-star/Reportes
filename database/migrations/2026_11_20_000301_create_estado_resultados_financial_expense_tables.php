<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gastos financieros del Estado de Resultados (20-nov-2026).
 *
 * La empresa paga dos créditos (LEROM semanal, BBVA mensual). Cada pago trae capital +
 * interés: para el ER SÓLO el interés va entre EBIT y EBT; el capital es flujo de
 * financiamiento. El desglose sale de la TABLA DE AMORTIZACIÓN de cada crédito.
 *
 *  - estado_resultados_credits: catálogo de créditos y concepto de ajuste que alimentan.
 *  - estado_resultados_amortization_uploads: bitácora de cargas de la tabla (archivo
 *    original, hash, quién, cuándo). Sustituir = la carga anterior queda «sustituida».
 *  - estado_resultados_amortization_rows: renglones (pago, capital, interés, IVA, saldo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estado_resultados_credits', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();               // lerom | bbva | otros…
            $table->string('name', 120);
            $table->string('creditor', 120)->nullable();
            $table->string('periodicity', 20)->default('mensual'); // semanal | mensual
            $table->string('adjustment_concept', 40);           // intereses_lerom | intereses_bbva | otros_intereses
            $table->string('bank_pattern', 160)->nullable();    // regex del concepto bancario del pago
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('estado_resultados_amortization_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_id')->constrained('estado_resultados_credits')->cascadeOnDelete();
            $table->string('original_name');
            $table->char('file_hash', 64);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('stored_path')->nullable();
            $table->string('status', 20)->default('vigente');   // vigente | sustituida
            $table->unsignedInteger('rows_count')->default(0);
            $table->date('first_payment_date')->nullable();
            $table->date('last_payment_date')->nullable();
            $table->decimal('total_payment', 16, 2)->default(0);
            $table->decimal('total_principal', 16, 2)->default(0);
            $table->decimal('total_interest', 16, 2)->default(0);
            $table->decimal('total_interest_vat', 16, 2)->default(0);
            $table->json('column_map')->nullable();             // encabezado detectado por campo
            $table->json('warnings')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by_upload_id')->nullable();
            $table->timestamps();

            $table->index(['credit_id', 'status'], 'er_amort_up_credit_status_idx');
        });

        Schema::create('estado_resultados_amortization_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_id')->constrained('estado_resultados_credits')->cascadeOnDelete();
            $table->foreignId('upload_id')->constrained('estado_resultados_amortization_uploads')->cascadeOnDelete();
            $table->unsignedInteger('payment_number')->nullable();
            $table->date('payment_date');
            $table->decimal('payment_total', 16, 2)->nullable();
            $table->decimal('principal', 16, 2)->default(0);
            $table->decimal('interest', 16, 2)->default(0);
            $table->decimal('interest_vat', 16, 2)->nullable();
            $table->decimal('balance', 16, 2)->nullable();
            $table->string('origin', 20)->default('archivo');    // archivo | captura
            $table->unsignedInteger('source_row')->nullable();
            $table->timestamps();

            $table->index(['credit_id', 'payment_date'], 'er_amort_rows_credit_date_idx');
            $table->index(['upload_id'], 'er_amort_rows_upload_idx');
        });

        $now = now();
        DB::table('estado_resultados_credits')->insert([
            [
                'key' => 'lerom', 'name' => 'Crédito LEROM', 'creditor' => 'LEROM', 'periodicity' => 'semanal',
                'adjustment_concept' => 'intereses_lerom', 'bank_pattern' => 'CREDITO\s+LEROM', 'active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'key' => 'bbva', 'name' => 'Crédito BBVA', 'creditor' => 'BBVA', 'periodicity' => 'mensual',
                'adjustment_concept' => 'intereses_bbva', 'bank_pattern' => 'CREDITO\s+BBVA', 'active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('estado_resultados_amortization_rows');
        Schema::dropIfExists('estado_resultados_amortization_uploads');
        Schema::dropIfExists('estado_resultados_credits');
    }
};
