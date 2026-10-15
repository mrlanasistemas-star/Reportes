<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Concurrencia de documentos OKR (4.9, 19-oct-2026) — ADITIVA.
 *
 * Un documento "vigente" se marca con `current_guard = 1`; uno anulado o
 * sustituido lleva NULL. El índice único (objetivo[, semana], current_guard)
 * hace IMPOSIBLE que dos solicitudes simultáneas (doble clic, dos admins)
 * dejen dos documentos vigentes de la misma clase: los NULL no chocan entre sí
 * (MySQL y SQLite), así que el historial de anulados se conserva completo.
 *
 * El modelo sincroniza la columna desde `status` en cada guardado (ver
 * OkrCommitmentLetter/OkrWarning/OkrPlacementUpload::booted()).
 *
 * `declared_complete` (4.6): lo que DECLARÓ quien cargó el reporte de
 * sucursal — completo (ausencia = $0) o parcial (ausencia = dato faltante).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('okr_commitment_letters', function (Blueprint $table) {
            $table->unsignedTinyInteger('current_guard')->nullable()->after('status');
        });
        Schema::table('okr_warnings', function (Blueprint $table) {
            $table->unsignedTinyInteger('current_guard')->nullable()->after('status');
        });
        Schema::table('okr_placement_uploads', function (Blueprint $table) {
            $table->unsignedTinyInteger('current_guard')->nullable()->after('status');
            $table->boolean('declared_complete')->nullable()->after('coverage_status');
        });

        // Backfill: solo el MÁS RECIENTE vigente de cada clase queda marcado.
        $latest = fn (string $table, array $group, string $status) => DB::table($table)
            ->where('status', $status)
            ->selectRaw('MAX(id) as id')
            ->groupBy($group)
            ->pluck('id')
            ->all();

        foreach (array_chunk($latest('okr_commitment_letters', ['okr_objective_id'], 'issued'), 500) as $ids) {
            DB::table('okr_commitment_letters')->whereIn('id', $ids)->update(['current_guard' => 1]);
        }
        foreach (array_chunk($latest('okr_warnings', ['okr_objective_id', 'week_number'], 'issued'), 500) as $ids) {
            DB::table('okr_warnings')->whereIn('id', $ids)->update(['current_guard' => 1]);
        }
        foreach (array_chunk($latest('okr_placement_uploads', ['okr_objective_id', 'week_number'], 'active'), 500) as $ids) {
            DB::table('okr_placement_uploads')->whereIn('id', $ids)->update(['current_guard' => 1]);
        }

        Schema::table('okr_commitment_letters', function (Blueprint $table) {
            $table->unique(['okr_objective_id', 'current_guard'], 'okr_letters_one_current');
        });
        Schema::table('okr_warnings', function (Blueprint $table) {
            $table->unique(['okr_objective_id', 'week_number', 'current_guard'], 'okr_warnings_one_current');
        });
        Schema::table('okr_placement_uploads', function (Blueprint $table) {
            $table->unique(['okr_objective_id', 'week_number', 'current_guard'], 'okr_uploads_one_current');
        });
    }

    public function down(): void
    {
        Schema::table('okr_placement_uploads', function (Blueprint $table) {
            $table->dropUnique('okr_uploads_one_current');
            $table->dropColumn(['current_guard', 'declared_complete']);
        });
        Schema::table('okr_warnings', function (Blueprint $table) {
            $table->dropUnique('okr_warnings_one_current');
            $table->dropColumn('current_guard');
        });
        Schema::table('okr_commitment_letters', function (Blueprint $table) {
            $table->dropUnique('okr_letters_one_current');
            $table->dropColumn('current_guard');
        });
    }
};
