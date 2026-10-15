<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrección OKR 15-oct-2026 — separar CREADOR / RESPONSABLE ADMINISTRATIVO /
 * COLABORADOR EVALUADO y permitir anular documentos con trazabilidad.
 *
 * Solo AGREGA columnas nulas/con default y cambia el índice ÚNICO de
 * okr_commitment_letters.okr_objective_id por uno normal (una carta anulada
 * puede sustituirse por otra con folio NUEVO). Ninguna fila se borra ni se
 * modifica: las cartas/warnings existentes quedan con status='issued'.
 *
 *  - okr_objectives.accountable_employee_id: titular (colaborador real) que
 *    acepta la responsabilidad de un OKR de SUCURSAL — nunca el usuario que
 *    lo creó ni el responsable administrativo.
 *  - *.subject_employee_id: colaborador al que corresponde el documento.
 *  - *.status/voided_*: anulación auditada; el PDF original nunca se toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('okr_objectives', function (Blueprint $table) {
            $table->foreignId('accountable_employee_id')->nullable()->after('employee_id')->constrained('employees')->nullOnDelete();
        });

        Schema::table('okr_commitment_letters', function (Blueprint $table) {
            $table->foreignId('subject_employee_id')->nullable()->after('okr_objective_id')->constrained('employees')->nullOnDelete();
            $table->string('status', 20)->default('issued')->after('folio');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->foreignId('replaces_letter_id')->nullable()->constrained('okr_commitment_letters')->nullOnDelete();
            $table->index('okr_objective_id', 'okr_commitment_letters_objective_idx');
        });

        Schema::table('okr_commitment_letters', function (Blueprint $table) {
            $table->dropUnique(['okr_objective_id']);
        });

        Schema::table('okr_warnings', function (Blueprint $table) {
            $table->foreignId('subject_employee_id')->nullable()->after('okr_objective_id')->constrained('employees')->nullOnDelete();
            $table->string('status', 20)->default('issued')->after('folio');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('okr_warnings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropConstrainedForeignId('subject_employee_id');
            $table->dropColumn(['status', 'voided_at', 'void_reason']);
        });

        Schema::table('okr_commitment_letters', function (Blueprint $table) {
            $table->unique('okr_objective_id');
        });

        Schema::table('okr_commitment_letters', function (Blueprint $table) {
            $table->dropIndex('okr_commitment_letters_objective_idx');
            $table->dropConstrainedForeignId('replaces_letter_id');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropConstrainedForeignId('subject_employee_id');
            $table->dropColumn(['status', 'voided_at', 'void_reason']);
        });

        Schema::table('okr_objectives', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accountable_employee_id');
        });
    }
};
