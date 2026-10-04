<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\OkrCommitmentLetter;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\OkrProgressSnapshot;
use App\Models\OkrWarning;
use App\Models\Period;
use App\Models\User;
use App\Services\Okr\OkrPlacementImportService;
use App\Services\Okr\OkrSnapshotService;
use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

uses(RefreshDatabase::class);

/**
 * Parte 20 del cierre (04-oct-2026) — PRUEBA REAL de extremo a extremo, no
 * solo unit tests: una sucursal real, 3 gestores ACTIVOS, un Objective de
 * colocación de 4 semanas, individualizado con metas DISTINTAS, 4 archivos
 * semanales (W1-W4), Carta Compromiso, y un Warning basado en el snapshot
 * congelado de una semana pasada — verificando sucursal total, cada gestor,
 * acumulados, cumplimiento, brechas, y los documentos generados.
 */
function closureXlsx(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    foreach (['Colaborador', 'Colocación'] as $i => $h) {
        $sheet->setCellValueByColumnAndRow($i + 1, 1, $h);
    }
    foreach ($rows as $r => $data) {
        foreach ($data as $c => $value) {
            $sheet->setCellValueByColumnAndRow($c + 1, $r + 2, $value);
        }
    }
    $path = storage_path('app/testing-closure-' . uniqid() . '.xlsx');
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

    return new UploadedFile($path, 'colocacion.xlsx', 'application/vnd.ms-excel', null, true);
}

it('PARTE 20 — real end-to-end scenario: branch with 3 individualized gestores, 4 weekly uploads, commitment letters and a Warning based on a frozen week', function () {
    // Browsershot real no está disponible en este entorno (sin Node/Chrome
    // configurados) — mismo doble que OkrCommitmentLetterTest/OkrWarningTest;
    // el resto del escenario (colocación, individualización, activos/baja,
    // acumulados, cumplimiento, brechas) corre 100% real contra la BD.
    $renderer = Mockery::mock(BrowsershotPdfRenderer::class);
    $renderer->shouldReceive('renderViewToFile')->andReturnUsing(function (string $view, array $data, string $outputPath) {
        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, '%PDF-1.4 fake test pdf');
    });
    app()->instance(BrowsershotPdfRenderer::class, $renderer);

    $admin = User::factory()->create(['role' => 'admin']);
    $branch = Branch::query()->create(['code' => 'REAL', 'name' => 'SUCURSAL REAL CIERRE', 'normalized_name' => 'sucursal real cierre', 'is_active' => true]);

    // 3 gestores ACTIVOS, asignados realmente a la sucursal.
    $period = Period::query()->create(['name' => 'Semana real cierre', 'code' => 'REAL-CLOSURE-' . uniqid(), 'type' => 'weekly', 'year' => 2026, 'month' => 1, 'sequence' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-07']);
    $juan = Employee::query()->create(['full_name' => 'JUAN REAL GESTOR', 'normalized_name' => 'juan real gestor', 'is_active' => true]);
    $pedro = Employee::query()->create(['full_name' => 'PEDRO REAL GESTOR', 'normalized_name' => 'pedro real gestor', 'is_active' => true]);
    $maria = Employee::query()->create(['full_name' => 'MARIA REAL GESTORA', 'normalized_name' => 'maria real gestora', 'is_active' => true]);
    foreach ([$juan, $pedro, $maria] as $e) {
        EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $e->id, 'branch_id' => $branch->id]);
    }

    // Un cuarto empleado que YA está de baja — nunca debe ser seleccionable
    // para un Objective NUEVO (Parte 1.1 / Test 5), verificado aparte vía
    // employeesLookup (ya cubierto en OkrEmployeeBranchValidationTest); aquí
    // solo se confirma que el buscador del wizard no lo ofrece.
    $bajaEmployee = Employee::query()->create(['full_name' => 'GESTOR YA EN BAJA REAL', 'normalized_name' => 'gestor ya en baja real', 'is_active' => false]);
    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $bajaEmployee->id, 'branch_id' => $branch->id]);
    $lookup = $this->actingAs($admin)->getJson("/okr/employees-lookup?branch_id={$branch->id}")->json('employees');
    expect(collect($lookup)->pluck('full_name'))->not->toContain('GESTOR YA EN BAJA REAL');

    $kpi = OkrKpi::query()->create([
        'name' => 'Colocación', 'code' => 'real_closure_kpi', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE,
        'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_AUTOMATIC,
        'provider_key' => 'reporteria.placement', 'is_active' => true,
    ]);

    // Metas DISTINTAS por gestor — Σ = meta de sucursal ($3,000,000), a propósito EXACTA (sin warning de distribución).
    $branchObjective = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Sucursal Real — colocación trimestral individualizada',
        'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->startOfDay(), 'end_date' => now()->addWeeks(4), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $branchObjective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'Colocación de la sucursal', 'baseline_value' => 0, 'target_value' => 3_000_000, 'weight' => 100, 'baseline_locked_at' => now()]);

    $goals = ['JUAN REAL GESTOR' => [$juan, 700_000], 'PEDRO REAL GESTOR' => [$pedro, 1_000_000], 'MARIA REAL GESTORA' => [$maria, 1_300_000]];
    $children = [];
    foreach ($goals as $name => [$employee, $target]) {
        $child = OkrObjective::query()->create([
            'parent_id' => $branchObjective->id, 'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id,
            'title' => "{$name} — colocación individual", 'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
            'start_date' => now()->startOfDay(), 'end_date' => now()->addWeeks(4), 'duration_weeks' => 4,
            'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
        ]);
        $child->keyResults()->create(['kpi_id' => $kpi->id, 'description' => "Colocación de {$name}", 'baseline_value' => 0, 'target_value' => $target, 'weight' => 100, 'baseline_locked_at' => now()]);
        $children[$name] = $child;
    }

    // W1..W4 — cada gestor sube SU PROPIO archivo cada semana (nunca duplica
    // contra un archivo de sucursal consolidado, Parte 6.5).
    $weeklyAmounts = [
        'JUAN REAL GESTOR'   => [1 => 175_000, 2 => 175_000, 3 => 175_000, 4 => 175_000],   // 700,000 total
        'PEDRO REAL GESTOR'  => [1 => 300_000, 2 => 300_000, 3 => 200_000, 4 => 200_000],   // 1,000,000 total
        'MARIA REAL GESTORA' => [1 => 400_000, 2 => 300_000, 3 => 300_000, 4 => 300_000],   // 1,300,000 total
    ];
    // Avanza el reloj a mitad de la semana 4 — importar un archivo no
    // depende de "ahora" (usa start_date del Objective), pero evaluar el
    // progreso (currentWeekNumber()) sí, y las 4 semanas ya transcurrieron
    // en un escenario real de 4 semanas de seguimiento.
    $this->travelTo(now()->addDays(24));

    $importer = app(OkrPlacementImportService::class);
    foreach ($weeklyAmounts as $name => $weeks) {
        foreach ($weeks as $week => $amount) {
            $importer->import($children[$name], $week, closureXlsx([[$name, $amount]]), $admin);
        }
        $snapshotService = app(OkrSnapshotService::class);
        $snapshotService->evaluateObjective($children[$name]->fresh());
        // Semanas pasadas (1-3) también quedan con snapshot histórico real —
        // necesario para que el Warning pueda usar el de la semana 3 (3.3/10.1).
        $snapshotService->backfillMissingWeeks($children[$name]->fresh());
    }

    // ── Verificación por gestor: acumulado final = meta exacta, 100% cumplimiento ──
    foreach ($goals as $name => [$employee, $target]) {
        $kr = $children[$name]->fresh()->keyResults()->first();
        expect((float) $kr->current_value)->toBe((float) $target);
        expect((float) $kr->actual_progress_percentage)->toBe(100.0);
    }

    // ── Vista de sucursal: total, desglose por gestor, Σ individuales == meta ──
    $this->actingAs($admin)->get(route('okr.show', $branchObjective))
        ->assertOk()
        ->assertInertia(function ($page) {
            $objective = $page->toArray()['props']['objective'];
            $c = $objective['contributions'][0];
            expect((float) $c['branch_target'])->toBe(3000000.0)
                ->and((float) $c['children_target_sum'])->toBe(3000000.0)
                ->and($c['distribution_mismatch'])->toBeFalse() // bien distribuido, sin warning
                ->and((float) $c['children_current_sum'])->toBe(3000000.0)
                ->and((float) $c['coverage_percentage'])->toBe(100.0);

            $rows = collect($c['rows'])->keyBy('employee');
            expect((float) $rows['JUAN REAL GESTOR']['current_value'])->toBe(700000.0)
                ->and((float) $rows['PEDRO REAL GESTOR']['current_value'])->toBe(1000000.0)
                ->and((float) $rows['MARIA REAL GESTORA']['current_value'])->toBe(1300000.0)
                ->and($rows['JUAN REAL GESTOR']['employee_active'])->toBeTrue();

            return $page->component('Okr/Show');
        });

    // ── Carta Compromiso — Juan y la sucursal, cada uno con SU propia meta ──
    $letterJuan = $this->actingAs($admin)->postJson(route('okr.commitment-letter.generate', $children['JUAN REAL GESTOR']), ['place' => 'Cuernavaca'])->json('letter');
    $letterBranch = $this->actingAs($admin)->postJson(route('okr.commitment-letter.generate', $branchObjective), ['place' => 'Cuernavaca'])->json('letter');
    expect($letterJuan['id'])->not->toBe($letterBranch['id']); // cartas DISTINTAS, nunca mezcladas

    $juanLetter = OkrCommitmentLetter::query()->findOrFail($letterJuan['id']);
    expect((float) $juanLetter->snapshot['key_results'][0]['target_value'])->toBe(700000.0); // meta INDIVIDUAL, no 3,000,000
    $branchLetter = OkrCommitmentLetter::query()->findOrFail($letterBranch['id']);
    expect((float) $branchLetter->snapshot['key_results'][0]['target_value'])->toBe(3000000.0);

    // ── Warning para Pedro, semana 3 (bajó de 300k/sem a 200k/sem) — usa el
    //    snapshot CONGELADO de esa semana, no el current_value final de hoy ──
    $pedroKr = $children['PEDRO REAL GESTOR']->fresh()->keyResults()->first();
    $week3Snapshot = OkrProgressSnapshot::query()->where('okr_key_result_id', $pedroKr->id)->where('week_number', 3)->first();
    expect((float) $week3Snapshot->actual_value)->toBe(800000.0); // 300k+300k+200k acumulado a la semana 3

    $warningResponse = $this->actingAs($admin)->postJson(route('okr.warnings.store', $children['PEDRO REAL GESTOR']), [
        'week_number' => 3, 'corrective_actions' => 'Reunión de seguimiento con Pedro — reforzar colocación semanal.',
    ]);
    $warningResponse->assertOk();
    $warning = OkrWarning::query()->findOrFail($warningResponse->json('warning.id'));
    expect((float) $warning->snapshot['rows'][0]['actual_value'])->toBe(800000.0)
        ->and((float) $warning->snapshot['rows'][0]['actual_value'])->not->toBe(1000000.0); // nunca el acumulado final de hoy
    expect(file_exists(Storage::disk($warning->disk)->path($warning->stored_path)))->toBeTrue();

    // ── Documentos quedan visibles en Show() ──
    $this->actingAs($admin)->get(route('okr.show', $children['PEDRO REAL GESTOR']))
        ->assertOk()
        ->assertInertia(fn ($page) => count($page->toArray()['props']['warnings']) === 1 && $page->component('Okr/Show'));
});

afterEach(function () {
    Storage::disk('local')->deleteDirectory('okr-placement-uploads');
    Storage::disk('local')->deleteDirectory('okr-commitment-letters');
    Storage::disk('local')->deleteDirectory('okr-warnings');
});
