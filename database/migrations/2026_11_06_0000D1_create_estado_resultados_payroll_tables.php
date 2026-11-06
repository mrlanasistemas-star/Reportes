<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de Resultados — NOI de corporativo (nómina del personal de Corporativo). ADITIVA.
 *
 * Fuente PROPIA del Estado de Resultados: se sube en ER → Fuentes y vive sólo en estas
 * tablas. NUNCA se escribe en fact_noi_movements ni en report_uploads, así que Radiografía,
 * Efectividad y OKR no la ven ni cambian.
 *
 *  - estado_resultados_payroll_uploads: una carga por archivo (idempotente por sha256; una
 *    nueva carga del mismo tipo sustituye a la anterior del periodo y la deja en el historial).
 *  - estado_resultados_payroll_lines: renglones leídos del NOI (colaborador, concepto, importe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estado_resultados_payroll_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->string('kind', 30)->default('noi_corporativo');
            $table->string('original_name', 255);
            $table->string('file_hash', 64)->index();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('stored_path', 255)->nullable();
            $table->string('status', 20)->default('cargado'); // cargado | reemplazado
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('lines_count')->default(0);
            $table->unsignedInteger('employees_count')->default(0);
            $table->decimal('total_percepciones', 14, 2)->default(0);
            $table->decimal('total_deducciones', 14, 2)->default(0);
            $table->json('warnings')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by_upload_id')->nullable();
            $table->timestamps();
            $table->index(['period_id', 'kind', 'status'], 'er_payroll_uploads_period_kind_status');
        });

        Schema::create('estado_resultados_payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_id')->constrained('estado_resultados_payroll_uploads')->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->unsignedInteger('source_row');
            $table->string('employee_code', 30)->nullable();
            $table->string('employee_name', 255);
            $table->string('name_key', 191)->nullable()->index();
            $table->string('concept', 255);
            $table->string('concept_type', 20)->nullable(); // percepcion | deduccion | null
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->index(['period_id', 'upload_id'], 'er_payroll_lines_period_upload');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estado_resultados_payroll_lines');
        Schema::dropIfExists('estado_resultados_payroll_uploads');
    }
};
