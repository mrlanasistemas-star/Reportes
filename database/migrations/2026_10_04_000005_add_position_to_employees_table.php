<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre real OKR (04-oct-2026, punto 7) — Employee no tenía puesto/cargo en
 * ninguna fuente del proyecto (auditado: NOI, roster, directorio, asignaciones
 * de sucursal — ninguna trae ese dato). Se agrega como campo persistente
 * mínimo gestionable desde Colaboradores/Usuarios; la Carta Compromiso lo
 * precarga una vez capturado, nunca se vuelve a escribir a mano por carta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('position', 120)->nullable()->after('full_name');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
