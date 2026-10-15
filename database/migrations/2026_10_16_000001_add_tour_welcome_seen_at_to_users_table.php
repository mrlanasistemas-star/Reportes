<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 16-oct-2026 — la bienvenida al recorrido se mostraba en CADA inicio de sesión
 * (el "ya vista" vivía solo en localStorage: otro navegador, ventana privada o
 * la app de escritorio lo perdían). Ahora se guarda por usuario. Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'tour_welcome_seen_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('tour_welcome_seen_at')->nullable()->after('access_enabled_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'tour_welcome_seen_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('tour_welcome_seen_at');
            });
        }
    }
};
