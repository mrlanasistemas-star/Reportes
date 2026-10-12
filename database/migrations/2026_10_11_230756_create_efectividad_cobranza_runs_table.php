<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 8/10 (Efectividad de Cobranza Aprobada) — tabla NUEVA, independiente de
 * `period_radiography_runs` (nunca reutiliza esa tabla: identidades distintas,
 * ciclo de vida distinto, no debe mezclarse con los runs de Radiografía).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('efectividad_cobranza_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('status', 20)->default('queued'); // queued|running|success|failed
            $table->string('log')->nullable();
            $table->json('metadata')->nullable();
            $table->json('coverage')->nullable();
            $table->longText('result_rows')->nullable();
            $table->string('output_excel_path')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['period_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('efectividad_cobranza_runs');
    }
};
