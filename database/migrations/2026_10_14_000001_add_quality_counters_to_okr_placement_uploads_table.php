<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrección OKR 14-oct-2026 — trazabilidad de la carga ÚNICA de colocación
 * (archivo de Lendus con movimientos de varios gestores/sucursales). Solo
 * AGREGA contadores informativos con default 0: no toca filas existentes.
 *   - rows_duplicated: movimientos que ya venían en la carga activa de otra
 *     semana del mismo OKR (nunca se suman dos veces).
 *   - rows_other_branch: filas de otra sucursal (columna "Sucursal").
 *   - rows_cancelled: movimientos cancelados/revertidos (columna de tipo/estatus).
 *   - rows_out_of_objective_range: fechas fuera del plazo del OKR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('okr_placement_uploads', function (Blueprint $table) {
            $table->unsignedInteger('rows_duplicated')->default(0)->after('rows_outside_week_range');
            $table->unsignedInteger('rows_other_branch')->default(0)->after('rows_duplicated');
            $table->unsignedInteger('rows_cancelled')->default(0)->after('rows_other_branch');
            $table->unsignedInteger('rows_out_of_objective_range')->default(0)->after('rows_cancelled');
        });
    }

    public function down(): void
    {
        Schema::table('okr_placement_uploads', function (Blueprint $table) {
            $table->dropColumn(['rows_duplicated', 'rows_other_branch', 'rows_cancelled', 'rows_out_of_objective_range']);
        });
    }
};
