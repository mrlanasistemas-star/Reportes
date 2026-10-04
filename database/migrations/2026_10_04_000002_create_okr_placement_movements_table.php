<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Detalle normalizado de cada fila del archivo de colocación semanal (Parte
 * 6/7 del cierre, 04-oct-2026). `fingerprint` (6.8) es la identidad estable
 * de la fila dentro de SU upload — evita contar dos veces una fila
 * duplicada en el mismo archivo (nunca depende solo del nombre del archivo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('okr_placement_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('okr_placement_upload_id')->constrained('okr_placement_uploads')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('employee_name_raw')->nullable();
            $table->decimal('amount', 14, 2);
            $table->date('operation_date')->nullable();
            $table->string('fingerprint', 64);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['okr_placement_upload_id', 'fingerprint'], 'okr_placement_movements_upload_fingerprint_unique');
            $table->index(['okr_placement_upload_id', 'employee_id'], 'okr_placement_movements_upload_employee_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_placement_movements');
    }
};
