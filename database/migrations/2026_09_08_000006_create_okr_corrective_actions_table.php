<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Módulo OKR (08-sep-2026) — acciones correctivas generadas desde un check-in. */
    public function up(): void
    {
        Schema::create('okr_corrective_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('okr_objective_id')->constrained('okr_objectives')->cascadeOnDelete();
            $table->foreignId('okr_check_in_id')->nullable()->constrained('okr_check_ins')->nullOnDelete();
            $table->string('description');
            $table->unsignedBigInteger('responsible_user_id');
            $table->date('due_date');
            $table->string('status', 20)->default('pending'); // pending|done|overdue
            $table->date('completed_at')->nullable();
            $table->unsignedBigInteger('evidence_id')->nullable();
            $table->timestamps();

            $table->foreign('responsible_user_id')->references('id')->on('users');
            $table->index(['okr_objective_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_corrective_actions');
    }
};
