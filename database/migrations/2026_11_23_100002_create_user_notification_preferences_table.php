<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferencias de notificación por usuario y tipo de evento (23-nov-2026).
 * Sin fila = todo activado (correo y push). La campana interna (canal
 * `database`) siempre se registra: es el historial del usuario.
 *
 * Migración solo aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_notification_preferences')) {
            return;
        }

        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->boolean('mail_enabled')->default(true);
            $table->boolean('push_enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_preferences');
    }
};
