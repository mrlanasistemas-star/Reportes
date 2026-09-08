<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Módulo OKR (08-sep-2026) — configuración administrable (umbrales de
     * semáforo, rangos de clasificación final) — key/value, nunca "mágicos" en
     * componentes Vue (ver OkrHealthService/OkrClosingService).
     */
    public function up(): void
    {
        Schema::create('okr_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_settings');
    }
};
