<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADITIVA (atribución estable ruta/sucursal → API) — no modifica ni borra datos
 * existentes.
 *
 * `sucursales` es el catálogo INSTITUCIONAL estable de las 11 sucursales
 * operativas de OKR (App\Services\Catalog\BranchRouteCatalog::OKR_BRANCHES). A
 * diferencia de `branches` —cuyo `code` se genera por máquina y puede recibir
 * sufijos ante colisión—, `sucursales.codigo` es un identificador fijo, acuñado
 * a partir del nombre oficial del catálogo (slug mayúsculas con guión bajo). Es
 * la clave que el contrato de la API usará para atribuir cada branch a su
 * sucursal; por eso es UNIQUE y nunca cambia.
 *
 *   - codigo : identificador institucional estable (UNIQUE).
 *   - nombre : nombre oficial de la sucursal (coincide con branches.name).
 *   - region : agrupación trimestral del propietario (Q1/Q3); NULL permitido.
 *   - activa : bandera operativa de la sucursal (no es el estado derivado por
 *              ruta; ese se calcula en Branch::derivedActive()).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sucursales')) {
            Schema::create('sucursales', function (Blueprint $table) {
                $table->id();
                $table->string('codigo', 60)->unique();
                $table->string('nombre', 160);
                $table->string('region', 10)->nullable();
                $table->boolean('activa')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sucursales');
    }
};
