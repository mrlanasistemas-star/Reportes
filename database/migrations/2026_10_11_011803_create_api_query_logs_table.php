<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1.6 — auditoría de consultas sensibles a la API. Deliberadamente NUNCA
 * guarda valores financieros ni payload de respuesta — solo quién consultó
 * qué recurso/alcance y cuándo (ver App\Http\Middleware\Api\LogApiQuery).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_query_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_consumer_id')->constrained('api_consumers')->cascadeOnDelete();
            $table->string('endpoint');
            $table->string('method', 10);
            $table->unsignedSmallInteger('response_status');
            $table->string('scope', 30)->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('period_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['api_consumer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_query_logs');
    }
};
