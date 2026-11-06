<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de Resultados — fase 2 de seguridad de cargas (06-nov-2026). ADITIVA.
 *
 *  - Ciclo de vida del archivo (lifecycle): recibido → validado → procesado → conciliado,
 *    o rechazado / duplicado / reemplazado. Convive con `status` (cargado|procesado|reemplazado
 *    en cargas; en_proceso|completado|rechazado|duplicado en intentos), que NO cambia.
 *  - Avisos estructurados por severidad (informativa | bloqueante) en la carga.
 *
 * Las filas existentes se rellenan a partir de su `status` (no se borra nada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estado_resultados_uploads', function (Blueprint $table) {
            $table->string('lifecycle', 20)->nullable()->default('recibido')->after('status');
            $table->json('warnings_detail')->nullable()->after('warnings');
            $table->unsignedSmallInteger('blocking_warnings')->default(0)->after('warnings_detail');
            $table->boolean('has_macros')->default(false)->after('blocking_warnings');
            $table->timestamp('validated_at')->nullable()->after('has_macros');
            $table->timestamp('processed_at')->nullable()->after('validated_at');
        });

        Schema::table('estado_resultados_import_attempts', function (Blueprint $table) {
            $table->string('lifecycle', 20)->nullable()->default('recibido')->after('status');
            $table->timestamp('validated_at')->nullable()->after('started_at');
        });

        DB::table('estado_resultados_uploads')->where('status', 'reemplazado')->update(['lifecycle' => 'reemplazado']);
        DB::table('estado_resultados_uploads')->where('status', 'procesado')->update(['lifecycle' => 'procesado']);
        DB::table('estado_resultados_uploads')->where('status', 'cargado')->update(['lifecycle' => 'validado']);
        DB::table('estado_resultados_uploads')->where('control_ok', false)->update(['blocking_warnings' => 1]);

        DB::table('estado_resultados_import_attempts')->where('status', 'completado')->update(['lifecycle' => 'validado']);
        DB::table('estado_resultados_import_attempts')->where('status', 'rechazado')->update(['lifecycle' => 'rechazado']);
        DB::table('estado_resultados_import_attempts')->where('status', 'duplicado')->update(['lifecycle' => 'duplicado']);
    }

    public function down(): void
    {
        Schema::table('estado_resultados_import_attempts', function (Blueprint $table) {
            $table->dropColumn(['lifecycle', 'validated_at']);
        });
        Schema::table('estado_resultados_uploads', function (Blueprint $table) {
            $table->dropColumn(['lifecycle', 'warnings_detail', 'blocking_warnings', 'has_macros', 'validated_at', 'processed_at']);
        });
    }
};
