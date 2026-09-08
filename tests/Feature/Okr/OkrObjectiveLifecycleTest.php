<?php

use App\Models\Branch;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\User;
use App\Services\Okr\OkrWeightValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function okrBranch(string $name = 'Orizaba'): Branch
{
    return Branch::query()->firstOrCreate(['normalized_name' => mb_strtolower($name)], ['code' => mb_substr(strtoupper($name), 0, 4), 'name' => strtoupper($name), 'is_active' => true]);
}

function okrManualKpi(string $code = 'manual_test_kpi', string $direction = OkrKpi::DIRECTION_INCREASE): OkrKpi
{
    return OkrKpi::query()->firstOrCreate(['code' => $code], [
        'name' => 'KPI de prueba', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE,
        'direction' => $direction, 'automation' => OkrKpi::AUTOMATION_MANUAL, 'is_active' => true,
    ]);
}

function okrDraftObjective(User $user, Branch $branch, array $weights = [60.0, 40.0]): OkrObjective
{
    $objective = OkrObjective::query()->create([
        'scope_type' => OkrObjective::SCOPE_BRANCH, 'branch_id' => $branch->id, 'title' => 'Aumentar la colocación de préstamos activos',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(8)->toDateString(), 'duration_weeks' => 8,
        'lifecycle_status' => OkrObjective::STATUS_DRAFT,
    ]);
    $kpi = okrManualKpi();
    foreach ($weights as $w) {
        $objective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR de prueba', 'baseline_value' => 100.0, 'target_value' => 200.0, 'weight' => $w]);
    }

    return $objective;
}

// Test D — ponderación != 100 rechazada.
it('rejects activation when key result weights do not sum to 100', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch(), [60.0, 30.0]); // suma 90

    $validator = app(OkrWeightValidator::class);
    expect($validator->isValid($objective))->toBeFalse();
    $summary = $validator->summary($objective);
    expect($summary['total'])->toBe(90.0);
    expect($summary['remaining'])->toBe(10.0);

    $response = $this->actingAs($user)->post(route('okr.activate', $objective));
    $response->assertSessionHasErrors('activate');
    expect($objective->fresh()->lifecycle_status)->toBe(OkrObjective::STATUS_DRAFT);
});

// Test E — ponderación = 100 aceptada.
it('accepts activation when key result weights sum exactly to 100', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Cordoba'), [60.0, 40.0]);

    $validator = app(OkrWeightValidator::class);
    expect($validator->isValid($objective))->toBeTrue();

    $response = $this->actingAs($user)->post(route('okr.activate', $objective));
    $response->assertRedirect(route('okr.show', $objective));
    expect($objective->fresh()->lifecycle_status)->toBe(OkrObjective::STATUS_ACTIVE);
});

// Test F — baseline congelada después de activar.
it('freezes the baseline after activation — it never changes automatically afterward', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Tula'), [100.0]);
    $kr = $objective->keyResults()->first();
    expect($kr->baseline_locked_at)->toBeNull();

    $this->actingAs($user)->post(route('okr.activate', $objective));

    $kr->refresh();
    expect($kr->baseline_locked_at)->not->toBeNull();
    expect((float) $kr->baseline_value)->toBe(100.0);

    // Reactivar (ya activo) no debe re-congelar ni cambiar el valor.
    $lockedAt = $kr->baseline_locked_at;
    $this->actingAs($user)->post(route('okr.activate', $objective));
    $kr->refresh();
    expect($kr->baseline_locked_at->equalTo($lockedAt))->toBeTrue();
});

// Test U — meta modificada genera audit log (con motivo obligatorio).
it('modifying a key result target/weight after activation requires a reason and generates an audit log', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Huamantla'), [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));
    $kr = $objective->keyResults()->first();

    // Sin motivo — rechazado por validación.
    $this->actingAs($user)->put(route('okr.goal.update', $objective), [
        'key_result_id' => $kr->id, 'target_value' => 250.0,
    ])->assertSessionHasErrors('reason');

    // Con motivo — aceptado y auditado.
    $this->actingAs($user)->put(route('okr.goal.update', $objective), [
        'key_result_id' => $kr->id, 'target_value' => 250.0, 'reason' => 'Ajuste por revisión de dirección.',
    ])->assertSessionHasNoErrors();

    expect((float) $kr->fresh()->target_value)->toBe(250.0);
    $log = \App\Models\OkrAuditLog::query()->where('auditable_type', 'key_result')->where('auditable_id', $kr->id)->where('field', 'target_value')->first();
    expect($log)->not->toBeNull();
    expect($log->old_value)->toBe('200');
    expect($log->new_value)->toBe('250');
    expect($log->reason)->toBe('Ajuste por revisión de dirección.');
});

it('a KR cannot be added/removed once the objective is active — only via the audited goal-update endpoint', function () {
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Miacatlan'), [100.0]);
    $this->actingAs($user)->post(route('okr.activate', $objective));

    $kpi = okrManualKpi('manual_test_kpi_2');
    $this->actingAs($user)->post(route('okr.key-results.store', $objective), [
        'kpi_id' => $kpi->id, 'description' => 'Nuevo KR', 'target_value' => 100, 'weight' => 50,
    ])->assertStatus(422);
});
