<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoría 10-sep-2026 (punto 32) — bug real: `ResponsibleController::
     * enableAccess()` reutilizaba `email_verified_at` para representar "el
     * admin habilitó el acceso de este responsable", mezclando dos conceptos
     * distintos (verificación real del correo vs. habilitación administrativa
     * de acceso). Se agrega una columna explícita para lo segundo —
     * `email_verified_at` recupera su significado real (solo se pone cuando
     * el correo se verifica de verdad, cosa que este repo no hace todavía).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('access_enabled_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('access_enabled_at');
        });
    }
};
