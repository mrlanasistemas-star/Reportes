<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 (API general de reportería) — un "consumidor" es un SISTEMA externo
 * (Auditoría Operativa, futuros sistemas), nunca un usuario humano. Separado
 * deliberadamente de `users` (que son sesiones web con password) — los tokens
 * Sanctum de un consumidor se emiten vía HasApiTokens en este modelo, no en
 * User. `allowed_branch_ids` nulo = sin restricción de sucursal (acceso
 * general); con valores = el consumidor NUNCA puede pedir scope=general ni
 * otra sucursal (ver App\Http\Middleware\Api\EnsureBranchScope).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_consumers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('system_code')->unique();
            $table->text('description')->nullable();
            $table->json('abilities')->nullable();
            $table->json('allowed_branch_ids')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_consumers');
    }
};
