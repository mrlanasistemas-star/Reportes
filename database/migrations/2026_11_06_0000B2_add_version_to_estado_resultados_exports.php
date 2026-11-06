<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Aditiva: el historial de exportaciones registra qué versión/estado se exportó. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estado_resultados_exports', function (Blueprint $table) {
            $table->unsignedInteger('version_number')->nullable()->after('dataset_hash');
            $table->string('report_status', 30)->nullable()->after('version_number');
            $table->boolean('preliminary')->default(false)->after('report_status');
        });
    }

    public function down(): void
    {
        Schema::table('estado_resultados_exports', function (Blueprint $table) {
            $table->dropColumn(['version_number', 'report_status', 'preliminary']);
        });
    }
};
