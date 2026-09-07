<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoría 07-sep-2026 (frente 4) — "Gasto general por gestor" persistente
     * por (period_id, employee_id). Antes vivía SOLO en la request/config de cada
     * generación de reporte (extra_employee_expense_amount/notes), efímero y
     * distinto entre Web/Excel/PDF (la vista Web nunca lo recibía — ver
     * MonthlyReportController::scopedData()). Con esta tabla se vuelve la ÚNICA
     * fuente para RadiographySnapshotBuilder::buildEmployeeExpenseDetail(),
     * consumida igual por Web/Excel/PDF.
     *
     * updateOrCreate(['period_id','employee_id']) siempre — el UNIQUE evita
     * cualquier duplicado si se guarda dos veces el mismo period+employee.
     */
    public function up(): void
    {
        Schema::create('employee_period_manual_expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('period_id');
            $table->unsignedBigInteger('employee_id');
            $table->decimal('amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['period_id', 'employee_id']);
            $table->index('period_id');
            $table->foreign('period_id')->references('id')->on('periods')->onDelete('cascade');
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_period_manual_expenses');
    }
};
