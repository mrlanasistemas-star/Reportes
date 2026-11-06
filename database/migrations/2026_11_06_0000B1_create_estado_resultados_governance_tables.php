<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de Resultados — gobierno del reporte (06-nov-2026). Sólo tablas NUEVAS
 * (aditiva; no altera Radiografía ni las tablas de 2026_11_05_000001).
 *
 *  - estado_resultados_versions:          una fila por versión APROBADA de un periodo (cierre).
 *  - estado_resultados_version_snapshots: dataset canónico CONGELADO por alcance (inmutable).
 *  - estado_resultados_version_logs:      bitácora de aprobaciones / nuevas versiones / sustituciones.
 *  - estado_resultados_import_attempts:   cada intento de carga (exitoso, rechazado o duplicado).
 *  - estado_resultados_import_errors:     errores por archivo y fila de cada intento rechazado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estado_resultados_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('aprobado'); // aprobado | sustituido
            $table->string('rules_version', 30);
            $table->text('criterion')->nullable();
            $table->char('dataset_hash', 64);                    // hash de cifras del CONSOLIDADO
            $table->char('snapshot_hash', 64);                   // sha256 de todos los snapshots + insumos
            $table->json('scopes');
            $table->json('sources');                             // archivos + sha256, firma ERP, corrida
            $table->json('classifications');                     // bitácora completa de clasificaciones del periodo
            $table->json('adjustments');                         // ajustes vigentes de todos los alcances
            $table->json('evaluation')->nullable();              // estado/validaciones al aprobar
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at');
            $table->text('approval_notes')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reopen_reason')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by_version_id')->nullable();
            $table->timestamps();

            $table->unique(['period_id', 'version'], 'er_versions_period_version_unique');
        });

        Schema::create('estado_resultados_version_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('estado_resultados_versions')->cascadeOnDelete();
            $table->string('scope_key', 80);
            $table->string('scope_label', 120);
            $table->longText('dataset');
            $table->char('dataset_hash', 64);
            $table->char('payload_hash', 64);                    // sha256 del JSON guardado (integridad)
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['version_id', 'scope_key'], 'er_snapshots_version_scope_unique');
        });

        Schema::create('estado_resultados_version_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->foreignId('version_id')->nullable()->constrained('estado_resultados_versions')->nullOnDelete();
            $table->unsignedInteger('version_number')->nullable();
            $table->string('action', 30);                         // aprobado | nueva_version | sustituido
            $table->text('reason')->nullable();
            $table->json('details')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['period_id', 'id'], 'er_version_logs_period_idx');
        });

        Schema::create('estado_resultados_import_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->string('account_slot', 30);
            $table->string('original_name');
            $table->string('extension', 10)->nullable();
            $table->string('mime', 120)->nullable();
            $table->char('file_hash', 64)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('stored_path')->nullable();            // copia del original (también si se rechazó)
            $table->string('status', 20)->default('en_proceso');  // en_proceso | completado | rechazado | duplicado
            $table->foreignId('upload_id')->nullable()->constrained('estado_resultados_uploads')->nullOnDelete();
            $table->text('message')->nullable();
            $table->unsignedInteger('errors_count')->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['period_id', 'id'], 'er_attempts_period_idx');
        });

        Schema::create('estado_resultados_import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('estado_resultados_import_attempts')->cascadeOnDelete();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('column_name', 60)->nullable();
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estado_resultados_import_errors');
        Schema::dropIfExists('estado_resultados_import_attempts');
        Schema::dropIfExists('estado_resultados_version_logs');
        Schema::dropIfExists('estado_resultados_version_snapshots');
        Schema::dropIfExists('estado_resultados_versions');
    }
};
