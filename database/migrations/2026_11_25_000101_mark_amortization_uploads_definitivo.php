<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 25-nov-2026: las tablas LEROM/BBVA entregadas son las definitivas (no habrá tabla firmada).
 * Marca como «definitivo» las cargas vigentes y deja ese valor por omisión. Solo datos de
 * control: no toca importes ni movimientos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('estado_resultados_amortization_uploads', 'document_kind')) {
            return;
        }
        DB::table('estado_resultados_amortization_uploads')->where('document_kind', 'simulacion')->update(['document_kind' => 'definitivo']);
        Schema::table('estado_resultados_amortization_uploads', function ($table) {
            $table->string('document_kind', 20)->default('definitivo')->change();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('estado_resultados_amortization_uploads', 'document_kind')) {
            Schema::table('estado_resultados_amortization_uploads', function ($table) {
                $table->string('document_kind', 20)->default('simulacion')->change();
            });
        }
    }
};
