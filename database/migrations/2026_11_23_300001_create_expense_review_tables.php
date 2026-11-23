<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisión manual de gastos (23-nov-2026) — capa OPCIONAL de decisiones del usuario
 * sobre fact_expenses, previa a generar la radiografía. Solo aditiva:
 *
 *  - expense_review_decisions: decisión por gasto (empleado / sucursal), con llave
 *    estable `fingerprint` (huella del gasto: fuente+fecha+concepto+monto+observaciones+
 *    ocurrencia). Los importadores borran y reinsertan fact_expenses — la huella permite
 *    volver a aplicar la decisión al registro reimportado (ver ExpenseReviewService).
 *  - expense_concept_rules: destino (categoría) elegido por el usuario para un concepto;
 *    la siguiente carga ya lo reconoce.
 *  - expense_review_logs: bitácora append-only (usuario, fecha, antes/después).
 *  - fact_expenses: tres columnas de trazabilidad (nulas = sin revisión manual, que es
 *    el estado de TODO registro hoy — por eso las cifras no cambian sin decisiones).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_review_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('source_code', 60)->nullable();
            $table->date('expense_date')->nullable();
            $table->string('concept')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->text('observations')->nullable();
            // 'asignado' | 'sin_empleado' | null (sin decisión de empleado)
            $table->string('employee_decision', 20)->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            // 'asignada' | 'corporativo' | 'sin_sucursal' | null (sin decisión de sucursal)
            $table->string('branch_decision', 20)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            // Valores automáticos previos a la decisión (para poder quitarla).
            $table->unsignedBigInteger('auto_employee_id')->nullable();
            $table->unsignedBigInteger('auto_branch_id')->nullable();
            $table->boolean('auto_captured')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['period_id', 'fingerprint']);
        });

        Schema::create('expense_concept_rules', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 10); // lendus | erp
            $table->string('normalized_concept', 191);
            $table->string('concept_label')->nullable();
            $table->string('category', 120);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_type', 'normalized_concept']);
        });

        Schema::create('expense_review_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->nullable()->constrained('periods')->nullOnDelete();
            $table->string('subject', 20); // gasto | concepto
            $table->string('subject_key', 191);
            $table->unsignedBigInteger('fact_expense_id')->nullable();
            $table->string('action', 40);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['period_id', 'subject']);
        });

        Schema::table('fact_expenses', function (Blueprint $table) {
            $table->string('employee_review_status', 20)->nullable()->after('attribution_needs_review');
            $table->string('branch_review_status', 20)->nullable()->after('employee_review_status');
            $table->string('category_before_rule', 120)->nullable()->after('branch_review_status');
        });
    }

    public function down(): void
    {
        Schema::table('fact_expenses', function (Blueprint $table) {
            $table->dropColumn(['employee_review_status', 'branch_review_status', 'category_before_rule']);
        });
        Schema::dropIfExists('expense_review_logs');
        Schema::dropIfExists('expense_concept_rules');
        Schema::dropIfExists('expense_review_decisions');
    }
};
