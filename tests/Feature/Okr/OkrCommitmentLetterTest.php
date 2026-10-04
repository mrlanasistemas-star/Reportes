<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\OkrCommitmentLetter;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\User;
use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Parte 2 del cierre (04-oct-2026) — Carta Compromiso automática. Cubre
 * TEST 9 del pedido (Parte 19): la carta individual usa Objective/meta
 * INDIVIDUAL, nunca la de sucursal.
 *
 * Este entorno no tiene Node/Chrome configurados para Browsershot (mismo
 * motivo por el que BrowsershotPdfRendererTest se salta su caso real) — se
 * sustituye el renderer por un doble que solo escribe un archivo no vacío en
 * la ruta pedida, igual que el PDF real haría. El punto de estos tests NO es
 * verificar el motor de PDF (ya cubierto aparte) sino el snapshot/folio/
 * idempotencia/scope del documento.
 */
function fakeBrowsershotRenderer(): void
{
    $renderer = Mockery::mock(BrowsershotPdfRenderer::class);
    $renderer->shouldReceive('renderViewToFile')
        ->andReturnUsing(function (string $view, array $data, string $outputPath) {
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, '%PDF-1.4 fake test pdf');
        });
    app()->instance(BrowsershotPdfRenderer::class, $renderer);
}

function okrLetterKpi(): OkrKpi
{
    return OkrKpi::query()->firstOrCreate(['code' => 'letter_test_kpi'], [
        'name' => 'Colocación', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE,
        'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_MANUAL, 'is_active' => true,
    ]);
}

beforeEach(fn () => fakeBrowsershotRenderer());

it('a DRAFT objective only gets a preview — generate() rejects it (2.1: la carta definitiva representa el Objective ACTIVADO)', function () {
    $branch = Branch::query()->create(['code' => 'LET', 'name' => 'LETRA SUCURSAL', 'normalized_name' => 'letra sucursal', 'is_active' => true]);
    $admin = User::factory()->create();
    $objective = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective draft para carta',
        'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_DRAFT,
    ]);
    $objective->keyResults()->create(['kpi_id' => okrLetterKpi()->id, 'description' => 'KR', 'baseline_value' => 0, 'target_value' => 100, 'weight' => 100]);

    $response = $this->actingAs($admin)->postJson(route('okr.commitment-letter.generate', $objective), ['place' => 'Ciudad de México']);
    $response->assertStatus(422);
    expect(OkrCommitmentLetter::query()->where('okr_objective_id', $objective->id)->exists())->toBeFalse();
});

it('TEST 9 — an INDIVIDUAL commitment letter uses the employee Objective and its INDIVIDUAL goal, never the branch goal', function () {
    $branch = Branch::query()->create(['code' => 'LET2', 'name' => 'LETRA SUCURSAL DOS', 'normalized_name' => 'letra sucursal dos', 'is_active' => true]);
    $employee = Employee::query()->create(['full_name' => 'GESTOR CARTA PRUEBA', 'normalized_name' => 'gestor carta prueba', 'is_active' => true]);
    $admin = User::factory()->create();

    // Sucursal con meta MUY distinta ($3,000,000) — nunca debe aparecer en la carta individual.
    $branchObjective = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective de sucursal (no debe aparecer en la carta individual)',
        'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $branchObjective->keyResults()->create(['kpi_id' => okrLetterKpi()->id, 'description' => 'KR sucursal', 'baseline_value' => 0, 'target_value' => 3_000_000, 'weight' => 100]);

    $individualObjective = OkrObjective::query()->create([
        'parent_id' => $branchObjective->id, 'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id,
        'title' => 'Objective individual de Gestor Carta Prueba', 'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $individualObjective->keyResults()->create(['kpi_id' => okrLetterKpi()->id, 'description' => 'KR individual', 'baseline_value' => 50_000, 'target_value' => 700_000, 'weight' => 100]);

    $response = $this->actingAs($admin)->postJson(route('okr.commitment-letter.generate', $individualObjective), [
        'place' => 'Ciudad de México', 'position' => 'Gestor de Crédito',
    ]);
    $response->assertOk();
    $letterId = $response->json('letter.id');

    $letter = OkrCommitmentLetter::query()->findOrFail($letterId);
    expect($letter->okr_objective_id)->toBe($individualObjective->id);
    expect($letter->snapshot['employee_name'])->toBe('GESTOR CARTA PRUEBA');
    expect($letter->snapshot['objective_title'])->toBe('Objective individual de Gestor Carta Prueba');
    expect((float) $letter->snapshot['key_results'][0]['target_value'])->toBe(700000.0);
    // NUNCA la meta de 3,000,000 de la sucursal.
    expect((float) $letter->snapshot['key_results'][0]['target_value'])->not->toBe(3000000.0);

    expect(file_exists(Storage::disk($letter->disk)->path($letter->stored_path)))->toBeTrue();
});

it('generate() is idempotent — calling it twice returns the SAME letter, never a second one, even if the goal changes after', function () {
    $branch = Branch::query()->create(['code' => 'LET3', 'name' => 'LETRA SUCURSAL TRES', 'normalized_name' => 'letra sucursal tres', 'is_active' => true]);
    $admin = User::factory()->create();
    $objective = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective para idempotencia de carta',
        'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $kr = $objective->keyResults()->create(['kpi_id' => okrLetterKpi()->id, 'description' => 'KR', 'baseline_value' => 0, 'target_value' => 100_000, 'weight' => 100]);

    $r1 = $this->actingAs($admin)->postJson(route('okr.commitment-letter.generate', $objective), ['place' => 'CDMX']);
    $r1->assertOk();
    $folio1 = $r1->json('letter.folio');

    // La meta cambia DESPUÉS de emitida la carta — nunca debe modificarla retroactivamente.
    $kr->update(['target_value' => 999_999]);

    $r2 = $this->actingAs($admin)->postJson(route('okr.commitment-letter.generate', $objective), ['place' => 'CDMX']);
    $r2->assertOk();
    expect($r2->json('letter.folio'))->toBe($folio1);
    expect(OkrCommitmentLetter::query()->where('okr_objective_id', $objective->id)->count())->toBe(1);

    $letter = OkrCommitmentLetter::query()->where('okr_objective_id', $objective->id)->first();
    expect((float) $letter->snapshot['key_results'][0]['target_value'])->toBe(100000.0); // congelada, NUNCA 999999
});

it('uploading a signed copy never replaces the original snapshot/PDF', function () {
    $branch = Branch::query()->create(['code' => 'LET4', 'name' => 'LETRA SUCURSAL CUATRO', 'normalized_name' => 'letra sucursal cuatro', 'is_active' => true]);
    $admin = User::factory()->create();
    $objective = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective para firma de carta',
        'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $objective->keyResults()->create(['kpi_id' => okrLetterKpi()->id, 'description' => 'KR', 'baseline_value' => 0, 'target_value' => 100, 'weight' => 100]);

    $letterId = $this->actingAs($admin)->postJson(route('okr.commitment-letter.generate', $objective), ['place' => 'CDMX'])->json('letter.id');
    $letter = OkrCommitmentLetter::query()->findOrFail($letterId);
    $originalPath = $letter->stored_path;

    $file = \Illuminate\Http\UploadedFile::fake()->create('firmada.pdf', 10, 'application/pdf');
    $this->actingAs($admin)->postJson(route('okr.commitment-letters.signed', $letter), ['file' => $file])->assertOk();

    $letter->refresh();
    expect($letter->stored_path)->toBe($originalPath); // original intacto
    expect($letter->signed_stored_path)->not->toBeNull();
    expect($letter->isSigned())->toBeTrue();
});

afterEach(function () {
    Storage::disk('local')->deleteDirectory('okr-commitment-letters');
});
