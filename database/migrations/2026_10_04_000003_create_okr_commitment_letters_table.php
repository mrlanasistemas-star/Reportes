<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parte 2 del cierre (04-oct-2026) — Carta Compromiso automática. Se genera
 * UNA sola vez por Objective, en el momento en que éste pasa a ACTIVE —
 * snapshot documental INMUTABLE (2.4: si después cambia la meta, la carta
 * emitida nunca se modifica retroactivamente). `snapshot` congela todo lo
 * que el documento necesita mostrar (colaborador/sucursal/KRs/metas) tal
 * como estaba al generarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('okr_commitment_letters', function (Blueprint $table) {
            $table->id();
            $table->string('folio')->unique();
            $table->foreignId('okr_objective_id')->constrained('okr_objectives')->cascadeOnDelete();
            $table->json('snapshot');
            $table->string('place');
            $table->foreignId('generated_by')->constrained('users');
            $table->timestamp('generated_at');
            $table->string('stored_path');
            $table->string('disk')->default('local');
            $table->string('signed_stored_path')->nullable();
            $table->string('signed_disk')->nullable();
            $table->foreignId('signed_uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_uploaded_at')->nullable();
            $table->timestamps();

            $table->unique('okr_objective_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_commitment_letters');
    }
};
