<?php

use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Tests AT#3/AT#4 — bug corregido 08-sep-2026: activar() ya NO permite que un
 * Key Result quede con baseline_value=null (y baseline_locked_at puesto de
 * todos modos). Un KPI automático sin dato real disponible en Reportería
 * BLOQUEA la activación completa (nunca activa "a medias").
 */
function okrAutomaticKpi(string $providerKey = 'reporteria.ebitda'): OkrKpi
{
    return OkrKpi::query()->firstOrCreate(['code' => 'auto_' . str_replace('.', '_', $providerKey)], [
        'name' => 'KPI automático de prueba', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE,
        'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_AUTOMATIC,
        'provider_key' => $providerKey, 'is_active' => true,
    ]);
}

it('rejects activation when an automatic KPI has no real baseline available — never activates with baseline null', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Cordoba');
    $kpi = okrAutomaticKpi();

    // Sin periodos con radiografía generada en la BD (RefreshDatabase parte de
    // cero) — el resolver de periodo/valor no tiene de dónde sacar el dato.
    $objective = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Aumentar EBITDA de la sucursal',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(8)->toDateString(), 'duration_weeks' => 8,
        'lifecycle_status' => 'draft',
    ]);
    $objective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR automático sin baseline', 'target_value' => 750000, 'weight' => 100]);

    $response = $this->actingAs($user)->post(route('okr.activate', $objective));

    $response->assertSessionHasErrors('activate');
    $objective->refresh();
    expect($objective->lifecycle_status)->toBe(OkrObjective::STATUS_DRAFT);
    $kr = $objective->keyResults()->first();
    expect($kr->baseline_value)->toBeNull();
    expect($kr->baseline_locked_at)->toBeNull(); // nunca se congela una baseline inexistente
});

it('rejects activation when a manual KPI key result was never given a baseline value', function () {
    $user = User::factory()->create();
    $branch = okrBranch('Atlixco');
    $kpi = okrManualKpi('manual_no_baseline');

    $objective = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective con KR manual sin línea base',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(), 'duration_weeks' => 4,
        'lifecycle_status' => 'draft',
    ]);
    $objective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR manual sin baseline', 'baseline_value' => null, 'target_value' => 100, 'weight' => 100]);

    $this->actingAs($user)->post(route('okr.activate', $objective))->assertSessionHasErrors('activate');
    expect($objective->fresh()->lifecycle_status)->toBe(OkrObjective::STATUS_DRAFT);
});
