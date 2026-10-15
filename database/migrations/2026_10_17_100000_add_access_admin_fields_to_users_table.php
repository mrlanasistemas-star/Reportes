<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADITIVA (prioridad 8/10 — módulo Usuarios): solo agrega columnas nullable o
 * con default a `users`; no cambia ni borra datos existentes.
 *
 *   - last_login_at: último inicio de sesión (se actualiza en el evento Login).
 *   - welcome_email_status: estado REAL de la invitación — pending | accepted
 *     (el servidor de correo la aceptó para su envío; NUNCA significa que el
 *     destinatario la recibió) | failed.
 *   - welcome_email_attempts / welcome_email_last_attempt_at: reintentos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'welcome_email_status')) {
                $table->string('welcome_email_status', 20)->nullable();
            }
            if (! Schema::hasColumn('users', 'welcome_email_attempts')) {
                $table->unsignedSmallInteger('welcome_email_attempts')->default(0);
            }
            if (! Schema::hasColumn('users', 'welcome_email_last_attempt_at')) {
                $table->timestamp('welcome_email_last_attempt_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['last_login_at', 'welcome_email_status', 'welcome_email_attempts', 'welcome_email_last_attempt_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
