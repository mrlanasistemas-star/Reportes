<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Módulo OKR (08-sep-2026) — evidencias de seguimiento. Reutiliza el disco
     * de almacenamiento ya usado por ReportUploadController (Storage::disk
     * 'public') — nunca una integración externa nueva.
     */
    public function up(): void
    {
        Schema::create('okr_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('okr_objective_id')->constrained('okr_objectives')->cascadeOnDelete();
            $table->foreignId('okr_key_result_id')->nullable()->constrained('okr_key_results')->nullOnDelete();
            $table->unsignedInteger('week_number')->nullable();
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('uploaded_by');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->foreign('uploaded_by')->references('id')->on('users');
            $table->index('okr_objective_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_evidences');
    }
};
