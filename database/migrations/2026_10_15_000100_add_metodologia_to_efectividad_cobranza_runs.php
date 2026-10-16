<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versión de METODOLOGÍA financiera (p. ej. V7), independiente de la versión
 * TÉCNICA del motor (`engine_version`, p. ej. mrlana-v4-2026-10).
 *
 * Problema que resuelve: la metodología evolucionó V6→V7 DENTRO de la misma
 * clase de motor sin cambiar `engine_version`. Sin este sello, una ejecución
 * calculada con la metodología anterior era indistinguible de una V7 y la API
 * podía entregarla como vigente. Las filas existentes quedan en NULL (metodología
 * desconocida): se tratan como "regenerar", NUNCA se borran ni se reescriben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efectividad_cobranza_runs', function (Blueprint $table) {
            $table->string('metodologia', 16)->nullable()->after('engine_version')->index();
        });
    }

    public function down(): void
    {
        Schema::table('efectividad_cobranza_runs', function (Blueprint $table) {
            $table->dropIndex(['metodologia']);
            $table->dropColumn('metodologia');
        });
    }
};
