<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\OkrProgressSnapshot;
use App\Models\OkrWarning;
use App\Models\User;
use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Parte 3/10 del cierre (04-oct-2026) — Warning Rojo. Cubre TEST 10 del
 * pedido (Parte 19): usa el snapshot de LA SEMANA evaluada, nunca
 * current_value "de hoy". Mismo doble de Browsershot que
 * OkrCommitmentLetterTest (sin Node/Chrome configurados en este entorno).
 */
function fakeBrowsershotRendererForWarning(): void
{
    $renderer = Mockery::mock(BrowsershotPdfRenderer::class);
    $renderer->shouldReceive('renderViewToFile')
        ->andReturnUsing(function (string $view, array $data, string $outputPath) {
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, '%PDF-1.4 fake test pdf');
        });
    app()->instance(BrowsershotPdfRenderer::class, $renderer);
}

beforeEach(fn () => fakeBrowsershotRendererForWarning());

function okrWarningObjective(): array
{
    $branch = Branch::query()->create(['code' => 'WRN', 'name' => 'WARNING SUCURSAL ' . uniqid(), 'normalized_name' => 'warning sucursal ' . uniqid(), 'is_active' => true]);
    $employee = Employee::query()->create(['full_name' => 'GESTOR WARNING PRUEBA', 'normalized_name' => 'gestor warning prueba ' . uniqid(), 'is_active' => true]);
    $admin = User::factory()->create();
    $kpi = OkrKpi::query()->create([
        'name' => 'Colocación', 'code' => 'warning_test_kpi_' . uniqid(), 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE,
        'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_MANUAL, 'is_active' => true,
    ]);
    $objective = OkrObjective::query()->create([
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id,
        'title' => 'Objective para prueba de Warning', 'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->subWeeks(3)->startOfDay(), 'end_date' => now()->addWeek(), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $kr = $objective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR warning', 'baseline_value' => 0, 'target_value' => 1_000_000, 'weight' => 100, 'baseline_locked_at' => now()]);
    // 15-oct-2026: el Warning se emite con base en la Carta Compromiso VIGENTE de esta persona.
    \App\Models\OkrCommitmentLetter::query()->create([
        'folio' => 'CC-TEST-' . uniqid(), 'okr_objective_id' => $objective->id, 'subject_employee_id' => $employee->id, 'status' => 'issued',
        'snapshot' => ['collaborator_name' => $employee->full_name, 'position' => 'GESTOR DE CRÉDITO', 'immediate_boss' => 'SUBGERENTE PRUEBA'],
        'place' => 'Cuernavaca', 'generated_by' => $admin->id, 'generated_at' => now()->subWeeks(3), 'stored_path' => 'x.pdf', 'disk' => 'local',
    ]);

    return [$objective, $kr, $admin, $employee];
}

it('Part 10.1 — rejects generating a Warning for a week with NO data at all (never fakes a 0/non-compliance)', function () {
    [$objective, $kr, $admin] = okrWarningObjective();
    // Ninguna semana tiene snapshot todavía.

    $response = $this->actingAs($admin)->postJson(route('okr.warnings.store', $objective), [
        'week_number' => 2, 'corrective_actions' => 'Reforzar seguimiento diario.',
    ]);

    $response->assertStatus(422);
    expect(OkrWarning::query()->where('okr_objective_id', $objective->id)->exists())->toBeFalse();
});

it('TEST 10 — a Warning for week 3 uses THAT week\'s frozen snapshot, never today\'s current_value', function () {
    [$objective, $kr, $admin] = okrWarningObjective();

    // Semana 3: resultado real congelado hace tiempo = 400,000 (incumplido).
    OkrProgressSnapshot::query()->create([
        'okr_key_result_id' => $kr->id, 'week_number' => 3, 'snapshot_date' => now()->subWeek()->toDateString(),
        'actual_value' => 400_000, 'expected_value' => 750_000,
        'actual_progress_percentage' => 40.0, 'expected_progress_percentage' => 75.0, 'deviation_pp' => -35.0,
        'calculated_at' => now()->subWeek(),
    ]);

    // El KR "hoy" (current_value) ya avanzó a 950,000 — el Warning de la
    // semana 3 NUNCA debe usar este valor actual, solo el congelado de esa semana.
    $kr->update(['current_value' => 950_000]);

    $response = $this->actingAs($admin)->postJson(route('okr.warnings.store', $objective), [
        'week_number' => 3, 'corrective_actions' => 'Reunión semanal de seguimiento y plan de recuperación.',
        'observations' => 'Segunda llamada de atención del trimestre.', 'authorized' => true,
    ]);
    $response->assertOk();

    $warning = OkrWarning::query()->findOrFail($response->json('warning.id'));
    expect($warning->week_number)->toBe(3);
    $row = $warning->snapshot['rows'][0];
    expect((float) $row['actual_value'])->toBe(400000.0); // el de la semana 3, NUNCA 950,000
    expect((float) $row['actual_value'])->not->toBe(950000.0);
    expect((float) $row['target_value'])->toBe(1000000.0);
    // 15-oct-2026: la brecha se mide contra lo ESPERADO a esa semana (750,000), no contra la meta final.
    expect((float) $row['gap'])->toBe(350000.0);
    expect($warning->snapshot['collaborator_name'])->toBe('GESTOR WARNING PRUEBA');
    expect($warning->snapshot['position'])->toBe('GESTOR DE CRÉDITO');

    expect(file_exists(Storage::disk($warning->disk)->path($warning->stored_path)))->toBeTrue();
});

it('a non-admin, non-gerencial user cannot generate a Warning — it is a managerial decision (3.1), never automatic', function () {
    [$objective, $kr] = okrWarningObjective();
    $colaborador = User::factory()->create(['role' => 'colaborador']);
    OkrProgressSnapshot::query()->create([
        'okr_key_result_id' => $kr->id, 'week_number' => 1, 'snapshot_date' => now()->toDateString(),
        'actual_value' => 100_000, 'calculated_at' => now(),
    ]);

    $this->actingAs($colaborador)->postJson(route('okr.warnings.store', $objective), [
        'week_number' => 1, 'corrective_actions' => 'Intento no autorizado.',
    ])->assertForbidden();

    expect(OkrWarning::query()->where('okr_objective_id', $objective->id)->exists())->toBeFalse();
});

it('a gerencial user CAN generate a Warning, same as admin', function () {
    [$objective, $kr] = okrWarningObjective();
    $gerencial = User::factory()->create(['role' => 'gerencial']);
    OkrProgressSnapshot::query()->create([
        'okr_key_result_id' => $kr->id, 'week_number' => 1, 'snapshot_date' => now()->toDateString(),
        // 12/13: generate() ahora también exige incumplimiento REAL (deviation_pp
        // negativo), no solo datos — este fixture simula una desviación real.
        'actual_value' => 100_000, 'expected_value' => 250_000,
        'actual_progress_percentage' => 10.0, 'expected_progress_percentage' => 25.0, 'deviation_pp' => -15.0,
        'calculated_at' => now(),
    ]);

    $this->actingAs($gerencial)->postJson(route('okr.warnings.store', $objective), [
        'week_number' => 1, 'corrective_actions' => 'Seguimiento reforzado por gerencia.', 'authorized' => true,
    ])->assertOk();

    expect(OkrWarning::query()->where('okr_objective_id', $objective->id)->exists())->toBeTrue();
});

it('15-oct — never allows a Warning for a week that is still OPEN, even with data below expectations', function () {
    [$objective, $kr, $admin] = okrWarningObjective();
    // Semana 4 (actual): todavía abierta.
    OkrProgressSnapshot::query()->create([
        'okr_key_result_id' => $kr->id, 'week_number' => 4, 'snapshot_date' => now()->toDateString(),
        'actual_value' => 100_000, 'expected_value' => 1_000_000, 'actual_progress_percentage' => 10.0,
        'expected_progress_percentage' => 100.0, 'deviation_pp' => -90.0, 'calculated_at' => now(),
    ]);

    $check = $this->actingAs($admin)->getJson(route('okr.warnings.eligibility', $objective) . '?week=4')->assertOk()->json('weeks.0');
    expect($check['status'])->toBe('pending');
    expect(implode(' ', $check['blockers']))->toContain('La semana todavía no termina. Podrás evaluar su cumplimiento después del cierre.');

    $this->actingAs($admin)->postJson(route('okr.warnings.store', $objective), [
        'week_number' => 4, 'corrective_actions' => 'Intento prematuro.', 'authorized' => true,
    ])->assertStatus(422);
    expect(OkrWarning::query()->where('okr_objective_id', $objective->id)->exists())->toBeFalse();
});

it('15-oct — a branch OKR without a formally assigned titular never produces a Warning in someone else\'s name', function () {
    [$objective, $kr, $admin] = okrWarningObjective();
    $objective->update(['scope_type' => 'branch', 'employee_id' => null, 'accountable_employee_id' => null]);
    OkrProgressSnapshot::query()->create([
        'okr_key_result_id' => $kr->id, 'week_number' => 1, 'snapshot_date' => now()->subWeeks(2)->toDateString(),
        'actual_value' => 50_000, 'expected_value' => 250_000, 'actual_progress_percentage' => 5.0,
        'expected_progress_percentage' => 25.0, 'deviation_pp' => -20.0, 'calculated_at' => now(), 'source_quality' => 'exact',
    ]);

    $response = $this->actingAs($admin)->postJson(route('okr.warnings.store', $objective), [
        'week_number' => 1, 'corrective_actions' => 'Plan de mejora.', 'authorized' => true,
    ]);
    $response->assertStatus(422);
    expect($response->json('message'))->toContain('titular');
});

it('15-oct — requires explicit authorization and allows an audited void that keeps the folio', function () {
    [$objective, $kr, $admin] = okrWarningObjective();
    OkrProgressSnapshot::query()->create([
        'okr_key_result_id' => $kr->id, 'week_number' => 2, 'snapshot_date' => now()->subWeek()->toDateString(),
        'actual_value' => 100_000, 'expected_value' => 500_000, 'actual_progress_percentage' => 10.0,
        'expected_progress_percentage' => 50.0, 'deviation_pp' => -40.0, 'calculated_at' => now(),
    ]);

    $this->actingAs($admin)->postJson(route('okr.warnings.store', $objective), ['week_number' => 2, 'corrective_actions' => 'Plan de mejora.'])
        ->assertStatus(422)->assertJsonValidationErrors('authorized');

    $id = $this->actingAs($admin)->postJson(route('okr.warnings.store', $objective), ['week_number' => 2, 'corrective_actions' => 'Plan de mejora.', 'authorized' => true])
        ->assertOk()->json('warning.id');
    $folio = OkrWarning::query()->findOrFail($id)->folio;

    $this->actingAs($admin)->postJson(route('okr.warnings.void', $id), ['reason' => 'Emitido con datos de prueba incorrectos.'])->assertOk();
    $warning = OkrWarning::query()->findOrFail($id);
    expect($warning->status)->toBe('voided');
    expect($warning->folio)->toBe($folio);
    expect(\App\Models\OkrAuditLog::query()->where('action', 'warning_voided')->exists())->toBeTrue();
});

afterEach(function () {
    Storage::disk('local')->deleteDirectory('okr-warnings');
});
