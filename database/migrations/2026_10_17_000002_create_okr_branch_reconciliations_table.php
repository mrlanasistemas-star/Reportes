<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prioridad 5 del cierre OKR — conciliación AUTORIZADA de la sucursal de un
 * colaborador cuando dos fuentes igualmente vigentes (mismo periodo) la
 * contradicen. Tabla nueva (aditiva): solo la lee el módulo OKR; NUNCA modifica
 * employee_branch_assignments ni ninguna cifra de Reportería.
 *
 * Una conciliación vale para la evidencia hasta `evidence_date`: si después
 * llega una fuente más reciente, esa vuelve a mandar (y si contradice de nuevo,
 * se pide otra conciliación).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('okr_branch_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('normalized_name', 200)->nullable()->index();
            $table->foreignId('branch_id')->constrained('branches');
            $table->date('evidence_date')->nullable();
            $table->string('chosen_source', 60)->nullable();
            $table->json('sources')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'evidence_date'], 'okr_branch_reconciliations_employee_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_branch_reconciliations');
    }
};
