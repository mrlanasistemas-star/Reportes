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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

uses(RefreshDatabase::class);

/**
 * Parte 14 del cierre (04-oct-2026) — "Rendimiento por gestor" debe resolver
 * la colocación semanal de TODOS los gestores en una consulta/agregación por
 * lote (OkrWeeklyPlacementResolver::resolveMany()), nunca una consulta de
 * colocación POR gestor. Prueba real: 15 gestores individuales, cada uno con
 * su propia carga semanal — el número de queries de /okr/{objective} no debe
 * crecer linealmente con la cantidad de gestores.
 */
function perfXlsx(string $name, float $amount): UploadedFile
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValueByColumnAndRow(1, 1, 'Colaborador');
    $sheet->setCellValueByColumnAndRow(2, 1, 'Colocación');
    $sheet->setCellValueByColumnAndRow(1, 2, $name);
    $sheet->setCellValueByColumnAndRow(2, 2, $amount);
    $path = storage_path('app/testing-perf-' . uniqid() . '.xlsx');
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

    return new UploadedFile($path, 'colocacion.xlsx', 'application/vnd.ms-excel', null, true);
}

it('resolves per-gestor weekly placement for 15 individual children with a BOUNDED number of queries, not one per gestor', function () {
    $branch = Branch::query()->create(['code' => 'PERF', 'name' => 'PERFORMANCE SUCURSAL', 'normalized_name' => 'performance sucursal', 'is_active' => true]);
    $admin = User::factory()->create();
    $kpi = OkrKpi::query()->create([
        'name' => 'Colocación', 'code' => 'perf_test_kpi', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE,
        'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_AUTOMATIC,
        'provider_key' => 'reporteria.placement', 'is_active' => true,
    ]);

    $branchObjective = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Sucursal performance — 15 gestores',
        'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->startOfDay(), 'end_date' => now()->addWeeks(4), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE,
    ]);
    $branchObjective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'Colocación sucursal', 'baseline_value' => 0, 'target_value' => 15_000_000, 'weight' => 100, 'baseline_locked_at' => now()]);

    $importer = app(OkrPlacementImportService::class);
    foreach (range(1, 15) as $i) {
        $employee = Employee::query()->create(['full_name' => "GESTOR PERF {$i}", 'normalized_name' => "gestor perf {$i}", 'is_active' => true]);
        $child = OkrObjective::query()->create([
            'parent_id' => $branchObjective->id, 'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id,
            'title' => "Gestor Perf {$i} — individual", 'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
            'start_date' => now()->startOfDay(), 'end_date' => now()->addWeeks(4), 'duration_weeks' => 4,
            'lifecycle_status' => OkrObjective::STATUS_ACTIVE,
        ]);
        $child->keyResults()->create(['kpi_id' => $kpi->id, 'description' => "Colocación de gestor {$i}", 'baseline_value' => 0, 'target_value' => 1_000_000, 'weight' => 100, 'baseline_locked_at' => now()]);
        $importer->import($child, 1, perfXlsx("GESTOR PERF {$i}", 100_000 * $i), $admin);
        app(OkrSnapshotService::class)->evaluateObjective($child->fresh());
    }

    DB::enableQueryLog();
    $this->actingAs($admin)->get(route('okr.show', $branchObjective))->assertOk();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Cota generosa (medido real: ~25) pero que SÍ reprueba un patrón N+1: 15
    // gestores con 1-2 queries de colocación cada uno ya pasarían de 30-45
    // solo en esa parte, encima del resto de la página (KRs, snapshots, etc.).
    expect($queryCount)->toBeLessThan(50);
});

afterEach(function () {
    Storage::disk('local')->deleteDirectory('okr-placement-uploads');
});
