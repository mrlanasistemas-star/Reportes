<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procesamiento en segundo plano persistente (Efectividad de Cobranza).
 *
 * Solo AGREGA columnas nullable / con default — no modifica ni borra datos de
 * ejecuciones históricas. Las filas previas quedan con estos campos en NULL y
 * la UI las presenta con la información que sí tienen.
 *
 *  - current_stage / stage_started_at / heartbeat_at: etapa real y última
 *    actividad registrada por el worker (detección de trabajos atascados).
 *  - progress_current / progress_total: avance MEDIBLE de la etapa actual
 *    (registros procesados / registros totales). NULL = etapa no cuantificable.
 *  - stages: línea de tiempo de etapas (inicio, fin, conteos, resumen).
 *  - sources: archivos de origen utilizados (upload, periodo, fecha, filas).
 *  - issues: incidencias estructuradas (código estable, severidad, categoría…).
 *  - failure_kind: categoría del bloqueo/error (técnico, columnas faltantes…).
 *  - attempts / queue_job_id / queue_job_uuid: estado real del Job en la cola.
 *  - subscriber_ids: usuarios que solicitaron la MISMA identidad mientras ya
 *    estaba activa (reutilizada) — también reciben la notificación.
 *  - notification_*: estado del correo independiente del resultado financiero.
 *  - in_app_notified_at: idempotencia de la notificación interna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efectividad_cobranza_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'current_stage')) {
                $table->string('current_stage', 40)->nullable()->after('status');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'stage_started_at')) {
                $table->timestamp('stage_started_at')->nullable()->after('current_stage');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'heartbeat_at')) {
                $table->timestamp('heartbeat_at')->nullable()->after('stage_started_at');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'progress_current')) {
                $table->unsignedBigInteger('progress_current')->nullable()->after('heartbeat_at');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'progress_total')) {
                $table->unsignedBigInteger('progress_total')->nullable()->after('progress_current');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'stages')) {
                $table->json('stages')->nullable()->after('progress_total');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'sources')) {
                $table->json('sources')->nullable()->after('stages');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'issues')) {
                $table->json('issues')->nullable()->after('sources');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'failure_kind')) {
                $table->string('failure_kind', 40)->nullable()->after('error_message');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'attempts')) {
                $table->unsignedSmallInteger('attempts')->default(0)->after('failure_kind');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'queue_job_id')) {
                $table->string('queue_job_id', 64)->nullable()->after('attempts');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'queue_job_uuid')) {
                $table->string('queue_job_uuid', 64)->nullable()->after('queue_job_id');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'subscriber_ids')) {
                $table->json('subscriber_ids')->nullable()->after('created_by');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'notification_status')) {
                $table->string('notification_status', 20)->nullable()->after('finished_at');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'notification_attempts')) {
                $table->unsignedSmallInteger('notification_attempts')->default(0)->after('notification_status');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'notification_error')) {
                $table->string('notification_error', 500)->nullable()->after('notification_attempts');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'notified_at')) {
                $table->timestamp('notified_at')->nullable()->after('notification_error');
            }
            if (! Schema::hasColumn('efectividad_cobranza_runs', 'in_app_notified_at')) {
                $table->timestamp('in_app_notified_at')->nullable()->after('notified_at');
            }
        });

        Schema::table('efectividad_cobranza_runs', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'ecr_status_created_idx');
            $table->index(['created_by', 'status'], 'ecr_creator_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('efectividad_cobranza_runs', function (Blueprint $table) {
            $table->dropIndex('ecr_status_created_idx');
            $table->dropIndex('ecr_creator_status_idx');
        });

        Schema::table('efectividad_cobranza_runs', function (Blueprint $table) {
            foreach ([
                'current_stage', 'stage_started_at', 'heartbeat_at', 'progress_current', 'progress_total',
                'stages', 'sources', 'issues', 'failure_kind', 'attempts', 'queue_job_id', 'queue_job_uuid',
                'subscriber_ids', 'notification_status', 'notification_attempts', 'notification_error',
                'notified_at', 'in_app_notified_at',
            ] as $column) {
                if (Schema::hasColumn('efectividad_cobranza_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
