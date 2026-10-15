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

/** 15-oct-2026: un OKR de sucursal necesita un TITULAR real para su carta (nunca el usuario creador). */
function okrLetterTitular(string $name = 'TITULAR GERENTE PRUEBA'): Employee
{
    return Employee::query()->firstOrCreate(['normalized_name' => mb_strtolower($name)], ['full_name' => $name, 'is_active' => true]);
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
        'place' => 'Ciudad de México', 'position' => 'Gestor de Crédito', 'immediate_boss' => 'SUBGERENTE DE PRUEBA',
    ]);
    $response->assertOk();
    $letterId = $response->json('letter.id');

    $letter = OkrCommitmentLetter::query()->findOrFail($letterId);
    expect($letter->okr_objective_id)->toBe($individualObjective->id);
    expect($letter->snapshot['collaborator_name'])->toBe('GESTOR CARTA PRUEBA');
    expect($letter->subject_employee_id)->toBe($employee->id);
    // Nunca el usuario que creó el OKR ni el responsable administrativo.
    expect($letter->snapshot['collaborator_name'])->not->toBe($admin->name);
    expect($letter->snapshot['immediate_boss'])->toBe('SUBGERENTE DE PRUEBA');
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
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective para idempotencia de carta', 'accountable_employee_id' => okrLetterTitular()->id,
        'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $kr = $objective->keyResults()->create(['kpi_id' => okrLetterKpi()->id, 'description' => 'KR', 'baseline_value' => 0, 'target_value' => 100_000, 'weight' => 100]);

    $r1 = $this->actingAs($admin)->postJson(route('okr.commitment-letter.generate', $objective), ['place' => 'CDMX', 'position' => 'Gerente de sucursal', 'immediate_boss' => 'Director Regional']);
    $r1->assertOk();
    $folio1 = $r1->json('letter.folio');

    // La meta cambia DESPUÉS de emitida la carta — nunca debe modificarla retroactivamente.
    $kr->update(['target_value' => 999_999]);

    $r2 = $this->actingAs($admin)->postJson(route('okr.commitment-letter.generate', $objective), ['place' => 'CDMX', 'position' => 'Gerente de sucursal', 'immediate_boss' => 'Director Regional']);
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
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective para firma de carta', 'accountable_employee_id' => okrLetterTitular()->id,
        'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $objective->keyResults()->create(['kpi_id' => okrLetterKpi()->id, 'description' => 'KR', 'baseline_value' => 0, 'target_value' => 100, 'weight' => 100]);

    $letterId = $this->actingAs($admin)->postJson(route('okr.commitment-letter.generate', $objective), ['place' => 'CDMX', 'position' => 'Gerente de sucursal', 'immediate_boss' => 'Director Regional'])->json('letter.id');
    $letter = OkrCommitmentLetter::query()->findOrFail($letterId);
    $originalPath = $letter->stored_path;

    // 19-oct-2026: la copia firmada debe ser un PDF real (cabecera %PDF), no solo la extensión.
    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('firmada.pdf', "%PDF-1.4\n% copia firmada escaneada\n");
    $this->actingAs($admin)->postJson(route('okr.commitment-letters.signed', $letter), ['file' => $file])->assertOk();

    $letter->refresh();
    expect($letter->stored_path)->toBe($originalPath); // original intacto
    expect($letter->signed_stored_path)->not->toBeNull();
    expect($letter->isSigned())->toBeTrue();
});

afterEach(function () {
    Storage::disk('local')->deleteDirectory('okr-commitment-letters');
});

it('never issues the definitive letter while an indispensable field is missing — the review endpoint lists it first (14-oct-2026)', function () {
    $renderer = Mockery::mock(\App\Services\Pdf\BrowsershotPdfRenderer::class);
    $renderer->shouldReceive('renderViewToFile')->never();
    app()->instance(\App\Services\Pdf\BrowsershotPdfRenderer::class, $renderer);

    $admin = User::factory()->create(['role' => 'admin']);
    $branch = okrBranch('Atlixco');
    $kpi = okrManualKpi('cc_missing_fields_kpi');
    $objective = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective de sucursal sin jefe inmediato capturado', 'accountable_employee_id' => okrLetterTitular()->id,
        'responsible_user_id' => $admin->id, 'created_by' => $admin->id, 'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(),
        'duration_weeks' => 4, 'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $objective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR', 'baseline_value' => 10, 'target_value' => 20, 'weight' => 100]);

    $review = $this->actingAs($admin)->getJson(route('okr.commitment-letter.review', $objective))->assertOk();
    expect($review->json('can_generate'))->toBeFalse();
    expect(array_keys($review->json('missing')))->toContain('immediate_boss')->toContain('position');

    $this->actingAs($admin)->postJson(route('okr.commitment-letter.generate', $objective), [])->assertStatus(422);
    expect($objective->fresh()->commitmentLetter)->toBeNull();

    $ready = $this->actingAs($admin)->getJson(route('okr.commitment-letter.review', $objective) . '?position=Gerente&immediate_boss=Director%20Regional')->assertOk();
    expect($ready->json('can_generate'))->toBeTrue();
});
