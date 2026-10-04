<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\User;
use App\Services\Okr\OkrPlacementImportService;
use App\Services\Okr\OkrSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

uses(RefreshDatabase::class);

/**
 * Parte 9/10 del cierre (04-oct-2026) — "Rendimiento por gestor" en la vista
 * de un Objective de sucursal: la cabecera sigue mostrando el total de la
 * sucursal, el desglose lo EXPLICA (quién produjo el resultado), nunca lo
 * sustituye. También Parte 9.2 (distribución) y Parte 9.5 (solo activos
 * aparecen como candidatos, pero el histórico de uno dado de baja se
 * conserva).
 */
function gestorPerfXlsx(array $rows): UploadedFile
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
    $absolutePath = storage_path('app/testing-gestor-perf-' . uniqid() . '.xlsx');
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($absolutePath);

    return new UploadedFile($absolutePath, 'colocacion.xlsx', 'application/vnd.ms-excel', null, true);
}

it('shows branch total + per-gestor breakdown (meta/semana/acumulado/cumplimiento/semáforo), flags a non-matching distribution, and marks a departed employee without hiding their historical row', function () {
    $branch = Branch::query()->create(['code' => 'CVC', 'name' => 'CUERNAVACA PRUEBA', 'normalized_name' => 'cuernavaca prueba', 'is_active' => true]);
    $juan = Employee::query()->create(['full_name' => 'JUAN GESTOR PERF', 'normalized_name' => 'juan gestor perf', 'is_active' => true]);
    $pedro = Employee::query()->create(['full_name' => 'PEDRO GESTOR PERF', 'normalized_name' => 'pedro gestor perf', 'is_active' => true]);
    $kpi = OkrKpi::query()->firstOrCreate(['code' => 'gestor_perf_placement'], [
        'name' => 'Colocación', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE,
        'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_AUTOMATIC,
        'provider_key' => 'reporteria.placement', 'is_active' => true,
    ]);
    $user = User::factory()->create();

    // Sucursal: meta 3,000,000. Solo se distribuyen 1,700,000 entre los dos
    // gestores (700k+1,000,000) — Σ individuales != meta sucursal a propósito.
    $branchObjective = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Cuernavaca — colocación trimestral',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => now()->startOfDay(), 'end_date' => now()->addWeeks(4), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE,
    ]);
    $branchKr = $branchObjective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'Colocación de sucursal', 'baseline_value' => 0, 'target_value' => 3_000_000, 'weight' => 100, 'baseline_locked_at' => now()]);

    $juanObjective = OkrObjective::query()->create([
        'parent_id' => $branchObjective->id, 'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $juan->id,
        'title' => 'Juan — colocación individual', 'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => now()->startOfDay(), 'end_date' => now()->addWeeks(4), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE,
    ]);
    $juanObjective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'Colocación de Juan', 'baseline_value' => 0, 'target_value' => 700_000, 'weight' => 100, 'baseline_locked_at' => now()]);

    $pedroObjective = OkrObjective::query()->create([
        'parent_id' => $branchObjective->id, 'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $pedro->id,
        'title' => 'Pedro — colocación individual', 'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => now()->startOfDay(), 'end_date' => now()->addWeeks(4), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE,
    ]);
    $pedroObjective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'Colocación de Pedro', 'baseline_value' => 0, 'target_value' => 1_000_000, 'weight' => 100, 'baseline_locked_at' => now()]);

    // Cada individual sube SU PROPIA semana 1 (evita duplicar con un archivo de sucursal).
    $importer = app(OkrPlacementImportService::class);
    $importer->import($juanObjective, 1, gestorPerfXlsx([['JUAN GESTOR PERF', 175000]]), $user);
    $importer->import($pedroObjective, 1, gestorPerfXlsx([['PEDRO GESTOR PERF', 250000]]), $user);

    app(OkrSnapshotService::class)->evaluateObjective($juanObjective->fresh());
    app(OkrSnapshotService::class)->evaluateObjective($pedroObjective->fresh());

    // Pedro causa baja DESPUÉS de producir su resultado — histórico se conserva.
    $pedro->update(['is_active' => false]);

    $this->actingAs($user)->get(route('okr.show', $branchObjective))
        ->assertOk()
        ->assertInertia(function ($page) {
            $objective = $page->toArray()['props']['objective'];
            $contributions = $objective['contributions'];
            expect($contributions)->toHaveCount(1);
            $c = $contributions[0];

            expect((float) $c['branch_target'])->toBe(3000000.0)
                ->and((float) $c['children_target_sum'])->toBe(1700000.0)
                ->and($c['distribution_mismatch'])->toBeTrue(); // 1,700,000 != 3,000,000 — warning visible

            $rows = collect($c['rows'])->keyBy('employee');
            expect((float) $rows['JUAN GESTOR PERF']['weekly_value'])->toBe(175000.0)
                ->and((float) $rows['JUAN GESTOR PERF']['current_value'])->toBe(175000.0)
                ->and($rows['JUAN GESTOR PERF']['employee_active'])->toBeTrue()
                ->and($rows['JUAN GESTOR PERF']['health_status'])->not->toBeNull();

            // Pedro sigue apareciendo en el desglose (histórico conservado) pero marcado "Baja".
            expect((float) $rows['PEDRO GESTOR PERF']['current_value'])->toBe(250000.0)
                ->and($rows['PEDRO GESTOR PERF']['employee_active'])->toBeFalse();

            return $page->component('Okr/Show');
        });
});

afterEach(function () {
    Storage::disk('local')->deleteDirectory('okr-placement-uploads');
});
