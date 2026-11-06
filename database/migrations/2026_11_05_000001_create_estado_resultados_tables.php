<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Estado de Resultados — tablas PROPIAS. No altera ninguna tabla de
 * Radiografía (fact_*, period_summaries, etc.).
 *
 *  - estado_resultados_uploads:          estados de cuenta complementarios (historial, hash, totales de control).
 *  - estado_resultados_movements:        cada movimiento bancario normalizado (dato original + resultado de conciliación automática).
 *  - estado_resultados_classifications:  bitácora APPEND-ONLY de clasificaciones manuales (nunca se sobreescribe el origen).
 *  - estado_resultados_adjustments:      ajustes contables tipificados por periodo/alcance (sin información / $0 confirmado / capturado).
 *  - estado_resultados_adjustment_logs:  historial de cada cambio de ajuste.
 *  - estado_resultados_runs:             historial de procesamientos (conciliación + clasificación).
 *  - estado_resultados_exports:          historial de exportaciones (hash del dataset exportado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estado_resultados_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->string('account_slot', 30);           // administrativa | excedentes
            $table->string('account_label', 60)->nullable(); // p.ej. "BBVA ****6697"
            $table->string('layout', 30);                  // administrativa | excedentes (estructura detectada)
            $table->string('original_name');
            $table->char('file_hash', 64);                 // sha256 del binario
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('stored_path')->nullable();
            $table->string('status', 20)->default('cargado'); // cargado | procesado | reemplazado
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('movements_count')->default(0);
            $table->unsignedInteger('detail_lines_count')->default(0);
            $table->unsignedInteger('out_of_period_count')->default(0);
            $table->decimal('total_cargos', 16, 2)->default(0);
            $table->decimal('total_abonos', 16, 2)->default(0);
            $table->decimal('control_total_cargos', 16, 2)->nullable();
            $table->decimal('control_total_abonos', 16, 2)->nullable();
            $table->boolean('control_ok')->nullable();
            $table->date('first_movement_date')->nullable();
            $table->date('last_movement_date')->nullable();
            $table->json('warnings')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by_upload_id')->nullable();
            $table->timestamps();

            $table->index(['period_id', 'account_slot', 'status'], 'er_uploads_period_slot_idx');
            $table->index(['period_id', 'file_hash'], 'er_uploads_hash_idx');
        });

        Schema::create('estado_resultados_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_id')->constrained('estado_resultados_uploads')->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->string('account_slot', 30);
            $table->unsignedInteger('source_row');
            $table->char('fingerprint', 40);
            $table->date('movement_date');
            $table->text('bank_reference')->nullable();
            $table->decimal('cargo', 16, 2)->default(0);
            $table->decimal('abono', 16, 2)->default(0);
            $table->decimal('saldo', 16, 2)->nullable();
            $table->string('folio_raw', 60)->nullable();
            $table->string('folio_normalized', 40)->nullable();
            $table->string('concept', 255)->nullable();
            $table->string('sucursal_raw', 120)->nullable();
            $table->string('branch_name', 80)->nullable();   // sucursal oficial / CORPORATIVO / null
            $table->string('branch_kind', 20);               // operativa | corporativo | agrupado | desconocida | vacia
            $table->string('beneficiary', 255)->nullable();
            $table->text('observations')->nullable();
            $table->string('status_raw', 60)->nullable();
            $table->decimal('total_raw', 16, 2)->nullable();
            $table->string('concept_gasto', 120)->nullable();
            $table->string('uuid', 60)->nullable();
            $table->string('complemento_pago', 120)->nullable();
            $table->json('detail_lines')->nullable();
            $table->json('raw_payload');
            $table->boolean('in_period')->default(true);
            // Resultado del procesamiento automático (recalculable, determinista)
            $table->string('reconciliation_status', 40)->nullable();
            $table->json('reconciliation_detail')->nullable();
            $table->string('auto_treatment', 40)->nullable();
            $table->string('suggested_treatment', 40)->nullable();
            $table->string('rule_code', 60)->nullable();
            $table->text('reason')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('transfer_pair_key', 60)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['period_id', 'fingerprint'], 'er_mov_period_fp_unique');
            $table->index(['period_id', 'folio_normalized'], 'er_mov_folio_idx');
            $table->index(['period_id', 'uuid'], 'er_mov_uuid_idx');
            $table->index(['period_id', 'auto_treatment'], 'er_mov_treat_idx');
        });

        Schema::create('estado_resultados_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->string('item_fingerprint', 64);
            $table->string('item_source', 10);              // banco | erp
            $table->string('treatment', 40);
            $table->string('previous_treatment', 40)->nullable();
            $table->text('notes');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['period_id', 'item_fingerprint'], 'er_class_item_idx');
        });

        Schema::create('estado_resultados_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->string('scope_key', 80)->default('CONSOLIDADO');
            $table->string('concept', 40);
            $table->string('state', 20)->default('sin_informacion'); // sin_informacion | confirmado_cero | capturado
            $table->decimal('amount', 16, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['period_id', 'scope_key', 'concept'], 'er_adj_unique');
        });

        Schema::create('estado_resultados_adjustment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjustment_id')->constrained('estado_resultados_adjustments')->cascadeOnDelete();
            $table->string('old_state', 20)->nullable();
            $table->decimal('old_amount', 16, 2)->nullable();
            $table->string('new_state', 20);
            $table->decimal('new_amount', 16, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('estado_resultados_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20);                   // ok | error
            $table->string('rules_version', 30);
            $table->char('erp_signature', 40)->nullable();
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['period_id', 'id'], 'er_runs_period_idx');
        });

        Schema::create('estado_resultados_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->string('scope_key', 80);
            $table->string('format', 10);                   // pdf | xlsx
            $table->char('dataset_hash', 64);
            $table->string('file_name');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estado_resultados_exports');
        Schema::dropIfExists('estado_resultados_runs');
        Schema::dropIfExists('estado_resultados_adjustment_logs');
        Schema::dropIfExists('estado_resultados_adjustments');
        Schema::dropIfExists('estado_resultados_classifications');
        Schema::dropIfExists('estado_resultados_movements');
        Schema::dropIfExists('estado_resultados_uploads');
    }
};
