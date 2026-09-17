<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D10 del cierre, 17-sep-2026 — bug real: el textarea de OkrAssignDialog.vue
 * permite escribir hasta 500 caracteres (`maxlength="500"`), pero
 * `okr_objectives.title` se creó como `$table->string('title')` (varchar(255)
 * por defecto de Laravel) y StoreObjectiveRequest validaba `max:191` —
 * cualquier título entre 192 y 500 caracteres tronaba con /500 en el request,
 * y uno entre 256-500 habría truncado silenciosamente en BD si la validación
 * no lo hubiera bloqueado antes. Se ensancha a varchar(500) — sin pérdida de
 * datos, los títulos existentes (todos ≤255) caben sin cambio.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('okr_objectives', function (Blueprint $table) {
            $table->string('title', 500)->change();
        });
    }

    public function down(): void
    {
        Schema::table('okr_objectives', function (Blueprint $table) {
            $table->string('title', 255)->change();
        });
    }
};
