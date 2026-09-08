<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Módulo OKR (08-sep-2026) — alertas INTERNAS (nunca WhatsApp/SMS/correo/push
     * externo en esta fase). `dedupe_key` evita duplicados del mismo tipo de
     * alerta para el mismo objective en la misma ventana (ver OkrAlertService).
     */
    public function up(): void
    {
        Schema::create('okr_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('okr_objective_id')->constrained('okr_objectives')->cascadeOnDelete();
            $table->string('type', 40); // risk|checkin_pending|deadline_near|off_track
            $table->text('message');
            $table->string('dedupe_key', 190);
            $table->timestamp('read_at')->nullable();
            $table->unsignedBigInteger('read_by')->nullable();
            $table->timestamps();

            $table->unique('dedupe_key');
            $table->foreign('read_by')->references('id')->on('users')->nullOnDelete();
            $table->index('okr_objective_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_alerts');
    }
};
