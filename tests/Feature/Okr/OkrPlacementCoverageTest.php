<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\OkrPlacementUpload;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\Okr\OkrPlacementImportService;
use App\Services\Okr\OkrWeeklyPlacementResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

uses(RefreshDatabase::class);

/**
 * Cierre real OKR (04-oct-2026, punto 18) — un archivo de sucursal sin fila
 * para un gestor solo puede leerse como $0 real cuando el archivo es
 * EXHAUSTIVO (coverage_status=full, verificado contra el roster real de
 * EmployeeBranchAssignment del periodo mensual que contiene la semana). Si
 * no puede garantizarse la cobertura (archivo parcial o sin periodo mensual
 * generado todavía), la ausencia es un dato FALTANTE, nunca un 0 fingido —
 * así nunca se levanta un Warning falso por una fila que faltó en un
 * archivo parcial.
 */
function coverageXlsx(array $rows): UploadedFile
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
    $path = storage_path('app/testing-coverage-' . uniqid() . '.xlsx');
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

    return new UploadedFile($path, 'colocacion.xlsx', 'application/vnd.ms-excel', null, true);
}

function coverageScenario(): array
{
    $branch = Branch::query()->create(['code' => 'COV', 'name' => 'SUCURSAL COBERTURA ' . uniqid(), 'normalized_name' => 'sucursal cobertura ' . uniqid(), 'is_active' => true]);
    // normalized_name DEBE calzar exacto con lo que OkrPlacementImportService::normalize()
    // produce para el texto del archivo ("GESTOR COBERTURA A" -> "gestor cobertura a")
    // — nunca agregarle un sufijo único, o resolveEmployee() nunca encuentra al empleado.
    $suffix = substr(uniqid(), -6);
    $gestorA = Employee::query()->create(['full_name' => "GESTOR COBERTURA A {$suffix}", 'normalized_name' => 'gestor cobertura a ' . $suffix, 'is_active' => true]);
    $gestorB = Employee::query()->create(['full_name' => "GESTOR COBERTURA B {$suffix}", 'normalized_name' => 'gestor cobertura b ' . $suffix, 'is_active' => true]);
    $admin = User::factory()->create();

    $kpi = OkrKpi::query()->create([
        'name' => 'Colocación', 'code' => 'coverage_test_kpi_' . uniqid(), 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE,
        'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_AUTOMATIC,
        'provider_key' => 'reporteria.placement', 'is_active' => true,
    ]);

    $branchObjective = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Sucursal cobertura — colocación',
        'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->startOfDay(), 'end_date' => now()->addWeeks(4), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $branchObjective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'Colocación sucursal', 'baseline_value' => 0, 'target_value' => 1_000_000, 'weight' => 100, 'baseline_locked_at' => now()]);

    $childB = OkrObjective::query()->create([
        'parent_id' => $branchObjective->id, 'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $gestorB->id,
        'title' => 'Gestor B — colocación individual', 'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->startOfDay(), 'end_date' => now()->addWeeks(4), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $childB->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'Colocación B', 'baseline_value' => 0, 'target_value' => 400_000, 'weight' => 100, 'baseline_locked_at' => now()]);

    // Periodo MENSUAL generado que contiene la semana 1 del Objective — necesario
    // para que resolveCoverageStatus() pueda resolver el roster real.
    $monthStart = now()->startOfMonth()->toDateString();
    $monthEnd = now()->endOfMonth()->toDateString();
    $period = Period::query()->create([
        'name' => 'Mes cobertura', 'code' => 'COV-MONTH-' . uniqid(), 'type' => 'monthly',
        'year' => (int) now()->format('Y'), 'month' => (int) now()->format('n'), 'sequence' => 1,
        'start_date' => $monthStart, 'end_date' => $monthEnd,
    ]);
    PeriodSummary::query()->create(['period_id' => $period->id, 'status' => 'generated']);

    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $gestorA->id, 'branch_id' => $branch->id]);
    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $gestorB->id, 'branch_id' => $branch->id]);

    return [$branchObjective, $childB, $gestorA, $gestorB, $admin];
}

it('18 — a PARTIAL branch file (missing a rostered gestor entirely) marks coverage_status=partial, and that gestor resolves as MISSING data, never a fake 0', function () {
    [$branchObjective, $childB, $gestorA, $gestorB, $admin] = coverageScenario();

    // Gestor B (con asignación activa a la sucursal) NO aparece en el
    // archivo — ni siquiera con monto cero.
    $importer = app(OkrPlacementImportService::class);
    $upload = $importer->import($branchObjective, 1, coverageXlsx([[$gestorA->full_name, 500_000]]), $admin);

    expect($upload->coverage_status)->toBe(OkrPlacementUpload::COVERAGE_PARTIAL);

    $resolver = app(OkrWeeklyPlacementResolver::class);
    $result = $resolver->resolve($childB->fresh(), 1);
    expect($result['weekly'])->toBeNull()
        ->and($result['cumulative'])->toBeNull()
        ->and($result['missing_weeks'])->toBe([1]);
});

it('18 — an EXHAUSTIVE branch file (every rostered gestor mentioned, even at $0) marks coverage_status=full, and an absent movement row is a real 0', function () {
    [$branchObjective, $childB, $gestorA, $gestorB, $admin] = coverageScenario();

    // Gestor B SÍ aparece en el archivo, con $0 — el archivo es exhaustivo
    // aunque el monto de B se filtre de los movimientos (amount==0).
    $importer = app(OkrPlacementImportService::class);
    $upload = $importer->import($branchObjective, 1, coverageXlsx([
        [$gestorA->full_name, 500_000],
        [$gestorB->full_name, 0],
    ]), $admin);

    expect($upload->coverage_status)->toBe(OkrPlacementUpload::COVERAGE_FULL);

    $resolver = app(OkrWeeklyPlacementResolver::class);
    $result = $resolver->resolve($childB->fresh(), 1);
    expect($result['weekly'])->toBe(0.0)
        ->and($result['cumulative'])->toBe(0.0)
        ->and($result['missing_weeks'])->toBe([]);
});
