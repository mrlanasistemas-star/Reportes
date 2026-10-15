<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\OkrCommitmentLetter;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\User;
use App\Services\Okr\OkrCommitmentLetterService;
use App\Services\Pdf\BrowsershotPdfRenderer;
use App\Services\Pdf\PdfRenderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/**
 * Jefe inmediato CONFIRMADO en la Carta Compromiso (15-oct-2026): sin jefe
 * confirmado no hay emisión definitiva (mensaje acordado + "Completar
 * información"); nunca se acepta como jefe al propio evaluado ni al
 * admin/creador del OKR sin respaldo del directorio laboral; un fallo del
 * motor PDF no deja folio emitido y devuelve una referencia para Sistemas.
 */
function bossTestObjective(): array
{
    $branch = Branch::query()->create(['code' => 'BOS', 'name' => 'JEFE SUCURSAL ' . uniqid(), 'normalized_name' => 'jefe sucursal ' . uniqid(), 'is_active' => true]);
    $employee = Employee::query()->create(['full_name' => 'GESTOR JEFE PRUEBA', 'normalized_name' => 'gestor jefe prueba ' . uniqid(), 'is_active' => true]);
    $admin = User::factory()->create(['role' => 'admin', 'name' => 'Administrador Sistema']);
    $kpi = OkrKpi::query()->create([
        'name' => 'Colocación', 'code' => 'boss_kpi_' . uniqid(), 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE,
        'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_MANUAL, 'is_active' => true,
    ]);
    $objective = OkrObjective::query()->create([
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id,
        'title' => 'Objetivo individual jefe', 'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $objective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR', 'baseline_value' => 100, 'target_value' => 200, 'weight' => 100, 'baseline_locked_at' => now()]);

    return [$objective, $admin, $employee];
}

function bossTestRenderer(bool $fail = false): void
{
    $renderer = Mockery::mock(BrowsershotPdfRenderer::class);
    $renderer->shouldReceive('renderViewToFile')->andReturnUsing(function (string $view, array $data, string $path) use ($fail) {
        if ($fail) {
            throw PdfRenderException::htmlFailed(new RuntimeException('chrome murió'));
        }
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '%PDF-1.4 fake');
    });
    app()->instance(BrowsershotPdfRenderer::class, $renderer);
}

it('blocks the definitive letter without a confirmed immediate boss, with the agreed message and the "Completar información" action', function () {
    bossTestRenderer();
    [$objective, $admin] = bossTestObjective();

    $response = $this->actingAs($admin)->postJson(route('okr.commitment-letter.generate', $objective), ['position' => 'GESTOR']);

    $response->assertStatus(422)
        ->assertJsonPath('action', 'complete_information')
        ->assertJsonPath('action_label', 'Completar información');
    expect($response->json('message'))->toStartWith(OkrCommitmentLetterService::BOSS_MISSING_MESSAGE)
        ->and(OkrCommitmentLetterService::BOSS_MISSING_MESSAGE)->toBe('No es posible emitir la Carta Compromiso porque falta confirmar el jefe inmediato.');
    expect(array_keys($response->json('missing')))->toContain('immediate_boss');
    expect(OkrCommitmentLetter::query()->where('okr_objective_id', $objective->id)->exists())->toBeFalse();
});

it('never accepts the admin/creator of the OKR as immediate boss without directory backing', function () {
    bossTestRenderer();
    [$objective, $admin] = bossTestObjective();

    $review = $this->actingAs($admin)->getJson(route('okr.commitment-letter.review', $objective) . '?position=GESTOR&immediate_boss=' . urlencode('administrador  sistema'))->assertOk();
    expect($review->json('can_generate'))->toBeFalse();
    expect($review->json('snapshot.immediate_boss'))->toBeNull();
    expect($review->json('missing.immediate_boss'))->toContain('directorio laboral no lo respalda');

    $this->actingAs($admin)->postJson(route('okr.commitment-letter.generate', $objective), ['position' => 'GESTOR', 'immediate_boss' => 'Administrador Sistema'])
        ->assertStatus(422)->assertJsonPath('action', 'complete_information');
    expect(OkrCommitmentLetter::query()->where('okr_objective_id', $objective->id)->exists())->toBeFalse();
});

it('never accepts the evaluated collaborator as their own immediate boss', function () {
    bossTestRenderer();
    [$objective, $admin] = bossTestObjective();

    $review = $this->actingAs($admin)->getJson(route('okr.commitment-letter.review', $objective) . '?position=GESTOR&immediate_boss=' . urlencode('Gestor Jefe Prueba'))->assertOk();
    expect($review->json('can_generate'))->toBeFalse();
    expect($review->json('missing.immediate_boss'))->toContain('mismo colaborador');
});

it('issues the letter in the evaluated person\'s name once a real boss is confirmed', function () {
    bossTestRenderer();
    [$objective, $admin, $employee] = bossTestObjective();

    $response = $this->actingAs($admin)->postJson(route('okr.commitment-letter.generate', $objective), ['position' => 'GESTOR', 'immediate_boss' => 'SUBGERENTE REAL'])->assertOk();
    $letter = OkrCommitmentLetter::query()->findOrFail($response->json('letter.id'));

    expect($letter->snapshot['collaborator_name'])->toBe($employee->full_name)->not->toBe($admin->name);
    expect($letter->snapshot['immediate_boss'])->toBe('SUBGERENTE REAL');
    expect($letter->folio)->toStartWith('CC');
});

it('a PDF engine failure leaves no issued folio and returns a reference for Sistemas', function () {
    bossTestRenderer(fail: true);
    [$objective, $admin] = bossTestObjective();

    $response = $this->actingAs($admin)->postJson(route('okr.commitment-letter.generate', $objective), ['position' => 'GESTOR', 'immediate_boss' => 'SUBGERENTE REAL']);

    $response->assertStatus(500);
    expect($response->json('message'))->toContain('proporciona esta referencia a Sistemas: CC-PDF-');
    expect(OkrCommitmentLetter::query()->where('okr_objective_id', $objective->id)->exists())->toBeFalse();
});

it('the preview never issues a folio nor stores a document, even with missing data', function () {
    bossTestRenderer();
    [$objective, $admin] = bossTestObjective();

    $this->actingAs($admin)->get(route('okr.commitment-letter.preview', $objective))->assertOk();

    expect(OkrCommitmentLetter::query()->where('okr_objective_id', $objective->id)->exists())->toBeFalse();
});
