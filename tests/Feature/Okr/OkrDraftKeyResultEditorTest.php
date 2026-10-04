<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\Period;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Parte 5.2/7 del cierre (04-oct-2026) — bug real encontrado durante la
 * auditoría: un Objective individual creado por el wizard de
 * individualización ("Agregar OKR individuales por vendedor") nace EN
 * BORRADOR y SIN Key Results propios a propósito — pero no existía NINGUNA
 * pantalla que llamara a key-results.store/destroy (ya existían en el
 * backend, probados, pero sin ningún caller en el frontend), así que esos
 * Objectives quedaban atascados en borrador para siempre, sin poder
 * completarse ni activarse. Ahora Show.vue expone un editor de Key Results
 * mientras el Objective está en DRAFT (ver props `kpis`).
 */
it('an individual Objective created by the wizard (draft, zero Key Results) can get a KR added via key-results.store and then be activated', function () {
    $branch = Branch::query()->create(['code' => 'EDT', 'name' => 'EDITOR SUCURSAL', 'normalized_name' => 'editor sucursal', 'is_active' => true]);
    $employee = Employee::query()->create(['full_name' => 'GESTOR EDITOR KR', 'normalized_name' => 'gestor editor kr', 'is_active' => true]);
    $period = Period::query()->create(['name' => 'P editor', 'code' => 'EDITOR-KR-' . uniqid(), 'type' => 'weekly', 'year' => 2026, 'month' => 1, 'sequence' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-07']);
    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $employee->id, 'branch_id' => $branch->id]);
    $admin = User::factory()->create();
    $kpi = OkrKpi::query()->firstOrCreate(['code' => 'draft_kr_editor_kpi'], [
        'name' => 'KPI de prueba editor', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE,
        'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_MANUAL, 'is_active' => true,
    ]);

    // Mismo estado exacto en que el wizard deja a un Objective individual:
    // DRAFT, con parent_id, SIN ningún Key Result.
    $individual = OkrObjective::query()->create([
        'parent_id' => null, 'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id,
        'title' => 'Gestor Editor KR — colocación individual', 'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_DRAFT,
    ]);
    expect($individual->keyResults()->count())->toBe(0);

    // La pantalla Show() debe exponer el catálogo de KPI para poder armar el editor.
    $this->actingAs($admin)->get(route('okr.show', $individual))
        ->assertOk()
        ->assertInertia(fn ($page) => collect($page->toArray()['props']['kpis'])->pluck('id')->contains($kpi->id) && $page->component('Okr/Show'));

    // Antes del fix: no había NINGÚN caller de esta ruta — ahora Show.vue la usa.
    $this->actingAs($admin)->post(route('okr.key-results.store', $individual), [
        'kpi_id' => $kpi->id, 'description' => 'Colocación individual de Gestor Editor KR',
        'baseline_value' => 50_000, 'target_value' => 700_000, 'weight' => 100,
    ])->assertSessionHasNoErrors();

    expect($individual->keyResults()->count())->toBe(1);

    $this->actingAs($admin)->post(route('okr.activate', $individual))->assertSessionHasNoErrors();
    expect($individual->fresh()->lifecycle_status)->toBe(OkrObjective::STATUS_ACTIVE);
});
