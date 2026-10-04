<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre real OKR (04-oct-2026, punto 18) — hasta ahora, un archivo de
 * SUCURSAL sin fila para un gestor se interpretaba SIEMPRE como $0 real
 * (ver OkrWeeklyPlacementResolver::weeklyValueFor(), comentario "el archivo
 * cubre a todos"). Eso solo es correcto si el archivo realmente es
 * exhaustivo. Este campo guarda, al momento de la carga, si el archivo de
 * sucursal cubrió a TODOS los empleados con asignación activa a esa
 * sucursal ('full') o no ('partial'/'unknown') — el resolver usa esto para
 * decidir si una ausencia es un 0 real o un dato faltante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('okr_placement_uploads', function (Blueprint $table) {
            $table->string('coverage_status', 20)->nullable()->after('rows_outside_week_range');
        });
    }

    public function down(): void
    {
        Schema::table('okr_placement_uploads', function (Blueprint $table) {
            $table->dropColumn('coverage_status');
        });
    }
};
