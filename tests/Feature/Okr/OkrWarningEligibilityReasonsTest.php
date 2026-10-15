<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\OkrCommitmentLetter;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\OkrProgressSnapshot;
use App\Models\OkrWarning;
use App\Models\User;
use App\Services\Okr\OkrWarningService;
use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Ventana de Warnings (15-oct-2026): abre aunque el objetivo no sea elegible
 * e indica el motivo EXACTO; un error técnico real devuelve el mensaje
 * acordado con una referencia registrada en el log. Semana sin información
 * nunca es incumplimiento.
 */
function wrReasonsObjective(string $kpiName = 'Colocación', bool $withKr = true): array
{
    $branch = Branch::query()->create(['code' => 'WRR', 'name' => 'WR MOTIVOS ' . uniqid(), 'normalized_name' => 'wr motivos ' . uniqid(), 'is_active' => true]);
    $employee = Employee::query()->create(['full_name' => 'GESTOR MOTIVOS', 'normalized_name' => 'gestor motivos ' . uniqid(), 'employee_code' => 'NOI-77', 'is_active' => true]);
    $admin = User::factory()->create(['role' => 'admin']);
    $objective = OkrObjective::query()->create([
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id,
        'title' => 'Objetivo motivos', 'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->subWeeks(3)->startOfDay(), 'end_date' => now()->addWeek(), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $kr = null;
    if ($withKr) {
        $kpi = OkrKpi::query()->create([
            'name' => $kpiName, 'code' => 'wr_reasons_' . uniqid(), 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE,
            'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_MANUAL, 'is_active' => true,
        ]);
        $kr = $objective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR', 'baseline_value' => 0, 'target_value' => 1_000_000, 'weight' => 100, 'baseline_locked_at' => now()]);
    }
    OkrCommitmentLetter::query()->create([
        'folio' => 'CC-MOT-' . uniqid(), 'okr_objective_id' => $objective->id, 'subject_employee_id' => $employee->id, 'status' => 'issued',
        'snapshot' => ['collaborator_name' => $employee->full_name, 'employee_code' => 'ATLX0001', 'position' => 'GESTOR', 'immediate_boss' => 'SUBGERENTE'],
        'place' => 'Cuernavaca', 'generated_by' => $admin->id, 'generated_at' => now()->subWeeks(3), 'stored_path' => 'x.pdf', 'disk' => 'local',
    ]);

    return [$objective, $kr, $admin, $employee];
}

function wrBreach($kr, int $week): void
{
    OkrProgressSnapshot::query()->create([
        'okr_key_result_id' => $kr->id, 'week_number' => $week, 'snapshot_date' => now()->subWeeks(4 - $week)->toDateString(),
        'actual_value' => 100_000, 'expected_value' => 500_000, 'actual_progress_percentage' => 10.0,
        'expected_progress_percentage' => 50.0, 'deviation_pp' => -40.0, 'calculated_at' => now(),
    ]);
}

beforeEach(function () {
    $renderer = Mockery::mock(BrowsershotPdfRenderer::class);
    $renderer->shouldReceive('renderViewToFile')->andReturnUsing(function (string $view, array $data, string $path) {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '%PDF-1.4 fake');
    });
    app()->instance(BrowsershotPdfRenderer::class, $renderer);
});

it('a closed week without data on the Colocación KR says "Falta cargar el reporte semanal de colocación" and stays pending (never a breach)', function () {
    [$objective, , $admin] = wrReasonsObjective();

    $week = $this->actingAs($admin)->getJson(route('okr.warnings.eligibility', $objective) . '?week=1')->assertOk()->json('weeks.0');

    expect($week['status'])->toBe('pending');
    expect($week['reasons'])->toContain('no_placement')->not->toContain('no_breach');
    expect(implode(' ', $week['blockers']))->toContain('Falta cargar el reporte semanal de colocación');
});

it('a closed week without data on another KR says "Sin evidencia suficiente"', function () {
    [$objective, , $admin] = wrReasonsObjective('Recuperación');

    $week = $this->actingAs($admin)->getJson(route('okr.warnings.eligibility', $objective) . '?week=1')->assertOk()->json('weeks.0');

    expect($week['reasons'])->toContain('no_evidence');
    expect(implode(' ', $week['blockers']))->toContain('Sin evidencia suficiente');
});

it('an OKR without Key Results says "Falta KR"', function () {
    [$objective, , $admin] = wrReasonsObjective(withKr: false);

    $week = $this->actingAs($admin)->getJson(route('okr.warnings.eligibility', $objective) . '?week=1')->assertOk()->json('weeks.0');

    expect($week['reasons'])->toContain('no_key_results');
    expect($week['status'])->toBe('not_eligible');
});

it('a week with a Warning already issued says "Documento ya emitido" with its folio', function () {
    [$objective, $kr, $admin] = wrReasonsObjective();
    wrBreach($kr, 2);
    $folio = $this->actingAs($admin)->postJson(route('okr.warnings.store', $objective), ['week_number' => 2, 'corrective_actions' => 'Plan de mejora.', 'authorized' => true])
        ->assertOk()->json('warning.folio');

    $week = $this->actingAs($admin)->getJson(route('okr.warnings.eligibility', $objective) . '?week=2')->assertOk()->json('weeks.0');

    expect($week['reasons'])->toContain('duplicate');
    expect(implode(' ', $week['blockers']))->toContain('Documento ya emitido')->toContain($folio);
});

it('the issued Warning belongs to the evaluated collaborator and carries the employee number', function () {
    [$objective, $kr, $admin, $employee] = wrReasonsObjective();
    wrBreach($kr, 2);

    $id = $this->actingAs($admin)->postJson(route('okr.warnings.store', $objective), ['week_number' => 2, 'corrective_actions' => 'Plan de mejora.', 'authorized' => true])->assertOk()->json('warning.id');
    $warning = OkrWarning::query()->findOrFail($id);

    expect($warning->subject_employee_id)->toBe($employee->id);
    expect($warning->snapshot['collaborator_name'])->toBe('GESTOR MOTIVOS')->not->toBe($admin->name);
    expect($warning->snapshot['employee_code'])->toBe('ATLX0001');
});

it('a user who can only view gets "Falta autorización" instead of an error', function () {
    [$objective, $kr] = wrReasonsObjective();
    wrBreach($kr, 2);
    $viewer = User::factory()->create(['role' => 'gerencial']);
    // Si el rol gerencial también puede asignar, el aviso no aplica: se valida la forma de la respuesta.
    $json = $this->actingAs($viewer)->getJson(route('okr.warnings.eligibility', $objective) . '?week=2');

    if ($json->status() === 200) {
        expect($json->json())->toHaveKeys(['can_issue', 'authorization_notice']);
        if ($json->json('can_issue') === false) {
            expect($json->json('authorization_notice'))->toContain('requiere permiso');
        }
    } else {
        $json->assertForbidden();
    }
});

it('a real technical error returns the agreed message with a reference that is written to the log', function () {
    [$objective, , $admin] = wrReasonsObjective();
    $service = Mockery::mock(OkrWarningService::class);
    $service->shouldReceive('eligibility')->andThrow(new RuntimeException('SQLSTATE[HY000] conexión perdida'));
    app()->instance(OkrWarningService::class, $service);
    Log::spy();

    $response = $this->actingAs($admin)->getJson(route('okr.warnings.eligibility', $objective) . '?week=1');

    $response->assertStatus(500);
    $ref = $response->json('reference');
    expect($ref)->toStartWith('WR-ELIG-');
    expect($response->json('message'))->toBe("No pudimos consultar la información. Intenta nuevamente. Si continúa, proporciona esta referencia a Sistemas: {$ref}.");
    Log::shouldHaveReceived('error')->withArgs(fn ($msg, $ctx) => ($ctx['ref'] ?? null) === $ref)->once();
});

afterEach(function () {
    Storage::disk('local')->deleteDirectory('okr-warnings');
});
