<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suscripciones Web Push (23-nov-2026) — una fila por navegador/teléfono donde
 * el usuario activó las notificaciones. Mismo esquema que el paquete
 * laravel-notification-channels/webpush, con una diferencia deliberada:
 * `endpoint` es TEXT (los endpoints de FCM/Mozilla/Apple pasan de 500
 * caracteres) y la unicidad va sobre `endpoint_hash` (SHA-256), porque un
 * índice único sobre VARCHAR(500) utf8mb4 excede el límite de MyISAM.
 * El hash lo llena App\Models\PushSubscription al guardar.
 *
 * Migración solo aditiva: no toca ninguna tabla existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('push_subscriptions')) {
            return;
        }

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->morphs('subscribable');
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
