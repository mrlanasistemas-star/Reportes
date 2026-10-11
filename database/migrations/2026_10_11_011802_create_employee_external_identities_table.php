<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1.4 — capa de correspondencias para identidad externa de colaboradores
 * (p.ej. MR Lana People a futuro). Se auditó el repo antes de crear esta
 * tabla: no existe nada equivalente (employee_aliases es para variantes de
 * NOMBRE dentro de Reportería, no para IDs de sistemas externos). Aditiva,
 * nunca modifica `employees`. El vínculo es EXPLÍCITO (verified_at/
 * verification_method) — nunca se crea automáticamente por coincidencia de
 * nombre (ver App\Services\Api\EmployeeExternalIdentityService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_external_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('system_code', 40);
            $table->string('external_id', 150);
            $table->string('external_type', 30)->default('employee');
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_method')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            // Una identidad externa ACTIVA de un (system_code, external_id, external_type)
            // dado solo puede apuntar a UN employee a la vez — evita vincular la misma
            // identidad externa a dos personas distintas por error.
            $table->unique(['system_code', 'external_type', 'external_id', 'active'], 'ext_identity_active_unique');
            $table->index(['employee_id', 'system_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_external_identities');
    }
};
