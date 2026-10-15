<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Movimientos laborales con evidencia (18-oct-2026) — 100% ADITIVA.
 *
 * Problema: PeriodEmployeeRosterService marcaba movement_type='baja' para
 * cualquiera que dejaba de aparecer entre dos nóminas NOI. Ahora:
 *   - movement_type='baja' queda SOLO para bajas confirmadas con evidencia
 *     (directorio laboral INACTIVO, finiquito en NOI o conciliación autorizada);
 *     una ausencia sin evidencia se guarda como movement_type='ausencia'.
 *   - reconciliation_status (columna ya existente, 14-oct) guarda la categoría
 *     del movimiento (alta_confirmada, posible_baja, no_localizado, …).
 *   - movement_evidence (NUEVA) guarda qué fuentes sostienen la categoría.
 *   - requires_review (NUEVA) marca lo que está pendiente de conciliación.
 *   - period_employee_movement_reviews (NUEVA) es la bitácora de la
 *     conciliación autorizada (confirmar baja / descartar). El roster se
 *     reconstruye borrando e insertando, así que la decisión vive aparte y se
 *     vuelve a aplicar en cada reconstrucción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('period_employee_rosters', function (Blueprint $table) {
            $table->json('movement_evidence')->nullable()->after('reconciliation_note');
            $table->boolean('requires_review')->default(false)->after('movement_evidence');
            $table->index(['period_id', 'requires_review'], 'per_period_requires_review_idx');
        });

        Schema::create('period_employee_movement_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('period_id');
            $table->string('nombre_normalizado', 191);
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('decision', 30); // confirmar_baja | descartar_baja
            $table->string('status_before', 40)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamps();

            $table->index(['period_id', 'nombre_normalizado'], 'pemr_period_name_idx');
            $table->foreign('period_id')->references('id')->on('periods')->onDelete('cascade');
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_employee_movement_reviews');

        Schema::table('period_employee_rosters', function (Blueprint $table) {
            $table->dropIndex('per_period_requires_review_idx');
            $table->dropColumn(['movement_evidence', 'requires_review']);
        });
    }
};
