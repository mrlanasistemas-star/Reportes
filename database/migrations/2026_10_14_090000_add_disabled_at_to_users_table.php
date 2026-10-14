<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADITIVA — `users.disabled_at`: cuenta deshabilitada por un administrador.
 * A diferencia de `access_enabled_at` (solo módulo OKR), esta SÍ bloquea el
 * inicio de sesión y cierra sesiones abiertas (ver EnsureUserNotDisabled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable()->after('access_enabled_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
    }
};
