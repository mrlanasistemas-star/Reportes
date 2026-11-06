<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de Resultados — garantías de aprobación a nivel BD (fase 2.2). ADITIVA.
 *
 *  - current_period_id: = period_id mientras la versión es la aprobada VIGENTE
 *    (status = 'aprobado'); NULL cuando queda sustituida. Con UNIQUE: la base de
 *    datos impide dos versiones vigentes del mismo periodo aunque dos procesos
 *    llegaran a escribir a la vez (MySQL/MariaDB/SQLite permiten varios NULL en un
 *    índice único; MySQL no tiene índices parciales, de ahí la columna espejo).
 *  - input_signature: firma de los insumos con la que se validó la aprobación.
 *  - (period_id, version) ya es único desde 2026_11_06_0000B1.
 *
 * Backfill: sólo marca como vigente la versión 'aprobado' MÁS ALTA de cada periodo
 * (si hubiera más de una, las demás quedan con NULL y se reportan en el log de la
 * migración; no se borra ni se cambia ningún otro dato).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estado_resultados_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('current_period_id')->nullable()->after('status');
            $table->char('input_signature', 64)->nullable()->after('snapshot_hash');
        });

        $latest = DB::table('estado_resultados_versions')->where('status', 'aprobado')
            ->selectRaw('period_id, MAX(version) AS v, COUNT(*) AS n')->groupBy('period_id')->get();
        foreach ($latest as $row) {
            DB::table('estado_resultados_versions')->where('period_id', $row->period_id)->where('version', $row->v)
                ->update(['current_period_id' => $row->period_id]);
            if ((int) $row->n > 1) {
                logger()->warning('estado_resultados.versions_backfill_multiple_current', ['period_id' => $row->period_id, 'aprobadas' => (int) $row->n]);
            }
        }

        Schema::table('estado_resultados_versions', function (Blueprint $table) {
            $table->unique('current_period_id', 'er_versions_one_current_per_period');
        });
    }

    public function down(): void
    {
        Schema::table('estado_resultados_versions', function (Blueprint $table) {
            $table->dropUnique('er_versions_one_current_per_period');
        });
        Schema::table('estado_resultados_versions', function (Blueprint $table) {
            $table->dropColumn(['current_period_id', 'input_signature']);
        });
    }
};
