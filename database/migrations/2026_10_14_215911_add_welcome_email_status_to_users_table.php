<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Correo de bienvenida (sección 11 de la auditoría, 15-oct-2026): hasta
            // ahora ResponsibleController::store() creaba el usuario sin notificarlo
            // (ver docblock de la clase, decisión 09-sep-2026 — "este repo no tiene
            // envío de correo configurado"). Estos campos permiten que la UI muestre
            // el estado REAL del envío ("Invitación enviada" / "No se pudo enviar")
            // en vez de afirmar un envío que el servidor nunca confirmó, y habilitan
            // reintentar sin volver a crear el usuario.
            $table->timestamp('welcome_email_sent_at')->nullable()->after('access_enabled_at');
            $table->string('welcome_email_error', 500)->nullable()->after('welcome_email_sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['welcome_email_sent_at', 'welcome_email_error']);
        });
    }
};
