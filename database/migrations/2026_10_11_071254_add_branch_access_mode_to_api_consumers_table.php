<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2.5 (auditoría de seguridad 2026-10-11) — `allowed_branch_ids = null` y
 * `allowed_branch_ids = []` eran INDISTINGUIBLES en el código anterior (ambos
 * daban acceso global) — un bug real, no hipotético. Se agrega un estado
 * EXPLÍCITO de 3 valores que nunca depende de inferir intención a partir de un
 * array vacío:
 *
 *   'global'     → sin restricción, puede pedir scope=general y cualquier sucursal.
 *   'restricted' → solo las sucursales en allowed_branch_ids (si ese array queda
 *                  vacío por error, el modelo lo trata como 'none', nunca como global).
 *   'none'       → sin acceso a ninguna sucursal (nuevo estado, antes imposible de expresar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_consumers', function (Blueprint $table) {
            $table->string('branch_access_mode', 20)->default('global')->after('allowed_branch_ids');
        });

        // Backfill de consumidores ya existentes: preserva EXACTAMENTE el comportamiento
        // que tenían antes de esta migración. Bajo el código ANTERIOR, tanto NULL como
        // `[]` daban acceso GLOBAL (ese era precisamente el bug) — por eso aquí solo se
        // marca 'restricted' cuando el array tiene al menos un elemento real; todo lo
        // demás (NULL o `[]`) se queda en el default 'global' ya aplicado arriba. Se hace
        // en PHP (no `JSON_LENGTH`/`json_array_length`) para ser portable entre
        // MySQL/MariaDB (producción) y SQLite (pruebas) sin duplicar la migración.
        DB::table('api_consumers')->whereNotNull('allowed_branch_ids')->get(['id', 'allowed_branch_ids'])
            ->each(function ($row) {
                $decoded = json_decode((string) $row->allowed_branch_ids, true);
                if (is_array($decoded) && count($decoded) > 0) {
                    DB::table('api_consumers')->where('id', $row->id)->update(['branch_access_mode' => 'restricted']);
                }
            });
    }

    public function down(): void
    {
        Schema::table('api_consumers', function (Blueprint $table) {
            $table->dropColumn('branch_access_mode');
        });
    }
};
