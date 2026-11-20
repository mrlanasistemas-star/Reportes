<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de conceptos del Estado de Resultados (20-nov-2026).
 *
 * Catálogo GLOBAL (no por periodo) administrado por el usuario: «cuando en el estado de
 * cuenta venga ESTE texto, el movimiento va a TAL concepto del reporte». Se aplica al
 * «Procesar conciliación» (MovementClassifier) y gana sobre las reglas automáticas por
 * texto, nunca sobre conciliación ERP, duplicados, fuera de periodo ni traspasos.
 *
 *  - estado_resultados_concept_rules: reglas (soft delete; quién creó/editó).
 *  - estado_resultados_concept_rule_logs: bitácora append-only de cada cambio.
 *  - estado_resultados_movements.concept_rule_id / concept_label: regla que clasificó el
 *    movimiento en el último procesamiento (el reporte agrupa por ese concepto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estado_resultados_concept_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);                         // concepto del reporte («Renta de oficinas»)
            $table->string('match_text', 200);                   // texto tal como lo capturó el usuario
            $table->string('match_normalized', 200);             // MAYÚSCULAS, sin acentos ni espacios dobles
            $table->string('match_mode', 20)->default('contiene'); // contiene | empieza | exacto
            $table->json('fields');                              // concept, observations, beneficiary, concept_gasto, bank_reference
            $table->string('account_slot', 30)->nullable();      // null = cualquier cuenta
            $table->string('direction', 20)->default('cualquiera'); // cargo | abono | cualquiera
            $table->string('treatment', 40);
            $table->integer('priority')->default(100);
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['active', 'priority'], 'er_concept_rules_active_idx');
        });

        Schema::create('estado_resultados_concept_rule_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rule_id');
            $table->string('action', 20);                        // creada | editada | activada | desactivada | borrada
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index('rule_id', 'er_concept_rule_logs_rule_idx');
        });

        Schema::table('estado_resultados_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('concept_rule_id')->nullable();
            $table->string('concept_label', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('estado_resultados_movements', function (Blueprint $table) {
            $table->dropColumn(['concept_rule_id', 'concept_label']);
        });
        Schema::dropIfExists('estado_resultados_concept_rule_logs');
        Schema::dropIfExists('estado_resultados_concept_rules');
    }
};
