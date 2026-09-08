<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Módulo OKR (08-sep-2026) — catálogo de KPI. `code` es la llave técnica
     * estable (nunca el nombre visual) — ver docs/OKR.md. `provider_key`
     * identifica la entrada en OkrKpiProviderRegistry que sabe resolver el
     * valor real desde Reportería (RadiografiaExportService::buildSnapshot());
     * NULL cuando el KPI es manual (sin fuente canónica todavía — ver
     * docs/OKR.md "KPI sin fuente automática").
     */
    public function up(): void
    {
        Schema::create('okr_kpis', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit', 20); // currency|percentage|integer|decimal
            $table->string('type', 20); // cumulative|balance|percentage
            $table->string('direction', 20); // increase|decrease
            $table->string('automation', 10)->default('manual'); // automatic|manual|hybrid
            $table->string('provider_key', 60)->nullable();
            $table->json('scopes')->nullable(); // ej. ["general","branch","employee"] — restringe dónde aplica
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable(); // opcional: precisión, min/max, etc.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_kpis');
    }
};
