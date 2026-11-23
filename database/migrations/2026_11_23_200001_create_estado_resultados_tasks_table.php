<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tareas en segundo plano del Estado de Resultados (23-nov-2026): «Procesar conciliación»
 * y «Aprobar» ya no corren dentro de la petición web (memory_limit de 1-2 GB en PHP-FPM);
 * se encolan (RunEstadoResultadosTaskJob) y la pantalla consulta su estado.
 *
 * `response` guarda EXACTAMENTE el JSON (y `http_status` el código) que antes devolvía la
 * petición síncrona, para que la pantalla muestre el mismo resultado o el mismo error.
 * Tabla aditiva: no toca ninguna tabla existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('estado_resultados_tasks')) {
            return;
        }

        Schema::create('estado_resultados_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->string('kind', 20);            // procesar | aprobar
            $table->string('status', 20)->default('queued'); // queued | running | ok | error
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('input')->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('current_step', 160)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('response')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['period_id', 'kind', 'status'], 'er_tasks_period_kind_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estado_resultados_tasks');
    }
};
