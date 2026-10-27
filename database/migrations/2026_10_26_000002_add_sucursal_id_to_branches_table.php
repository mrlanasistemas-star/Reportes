<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADITIVA — solo agrega `branches.sucursal_id` (FK nullable → sucursales).
 * NO cambia, renombra ni elimina columnas existentes de `branches`.
 *
 * El valor lo materializa SucursalSeeder a partir del catálogo oficial
 * (BranchRouteCatalog); los branches que no corresponden a ninguna de las 11
 * sucursales operativas quedan con sucursal_id = NULL (nunca se inventa una
 * sucursal). La resolución es de una sola vez, revisada; en tiempo de ejecución
 * la API lee esta FK y jamás vuelve a adivinar por parecido.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('branches', 'sucursal_id')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->foreignId('sucursal_id')
                    ->nullable()
                    ->after('is_active')
                    ->constrained('sucursales')
                    ->nullOnDelete();
                // constrained() ya crea el índice de la FK sobre sucursal_id.
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('branches', 'sucursal_id')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropConstrainedForeignId('sucursal_id');
            });
        }
    }
};
