<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\OkrCommitmentLetter;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Parte 17 del cierre (04-oct-2026) — php artisan okr:health. */
it('reports overdue-but-active objectives and unsigned commitment letters', function () {
    $branch = Branch::query()->create(['code' => 'HLT', 'name' => 'HEALTH SUCURSAL', 'normalized_name' => 'health sucursal', 'is_active' => true]);
    Employee::query()->create(['full_name' => 'GESTOR HEALTH', 'normalized_name' => 'gestor health', 'is_active' => true]);
    $admin = User::factory()->create();
    $kpi = OkrKpi::query()->firstOrCreate(['code' => 'health_test_kpi'], [
        'name' => 'KPI de prueba', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE,
        'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_MANUAL, 'is_active' => true,
    ]);

    // Objective ACTIVO pero con end_date ya pasada — simula que close-due no corrió.
    $overdue = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective vencido aún activo',
        'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->subWeeks(10), 'end_date' => now()->subWeek(), 'duration_weeks' => 8,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now()->subWeeks(10),
    ]);
    $overdue->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR', 'baseline_value' => 0, 'target_value' => 100, 'weight' => 100]);

    OkrCommitmentLetter::query()->create([
        'folio' => 'CC-TEST-1', 'okr_objective_id' => $overdue->id, 'snapshot' => [], 'place' => 'CDMX',
        'generated_by' => $admin->id, 'generated_at' => now(), 'stored_path' => 'x', 'disk' => 'local',
    ]);

    $this->artisan('okr:health')
        ->expectsOutputToContain('Objectives activos: 1')
        ->expectsOutputToContain('Objectives vencidos aún activos: 1')
        ->expectsOutputToContain('Colaboradores activos elegibles para nuevo OKR: 1')
        ->expectsOutputToContain('Cartas Compromiso sin firma subida: 1')
        ->assertExitCode(0);
});
