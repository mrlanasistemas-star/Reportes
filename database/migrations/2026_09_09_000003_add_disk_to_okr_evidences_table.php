<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoría 09-sep-2026 (punto 8 — evidencias privadas): las evidencias
     * OKR se guardaban en el disco 'public' (accesible directamente por URL
     * si alguien adivina la ruta) — nunca deberían serlo, son documentos
     * internos de seguimiento. Las NUEVAS evidencias van al disco 'local'
     * (storage/app/private, jamás expuesto por URL — descarga SOLO vía
     * EvidenceController::download() con autorización). Las existentes en
     * 'public' se dejan tal cual — se marcan explícitamente con
     * disk='public' (default de esta columna) para que download() sepa de
     * cuál disco leer cada una — sin esto se romperían las evidencias ya
     * subidas.
     */
    public function up(): void
    {
        Schema::table('okr_evidences', function (Blueprint $table) {
            $table->string('disk', 20)->default('public')->after('stored_path');
        });
    }

    public function down(): void
    {
        Schema::table('okr_evidences', function (Blueprint $table) {
            $table->dropColumn('disk');
        });
    }
};
