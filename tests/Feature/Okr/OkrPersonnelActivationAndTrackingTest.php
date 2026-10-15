<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\OkrProgressSnapshot;
use App\Models\Period;
use App\Models\PeriodEmployeeRoster;
use App\Models\User;
use App\Services\Okr\OkrEmployeeBranchResolver;
use App\Services\Okr\OkrPersonnelResolver;
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
 * Corrección OKR 15-oct-2026 — datos laborales reales, revisión previa a la
 * activación, completar KR de individuales que nacieron vacíos, desglose
 * semanal y proyección financieramente coherente.
 */
function ptPeriod(string $name, string $start, string $end): Period
{
    return Period::query()->create(['name' => $name, 'code' => 'PT-' . uniqid('', true), 'type' => 'monthly', 'year' => 2026, 'month' => (int) substr($end, 5, 2), 'sequence' => 1, 'start_date' => $start, 'end_date' => $end]);
}

function ptDirectory(Period $period, array $rows): void
{
    $sourceId = DB::table('data_sources')->insertGetId(['code' => 'lendus_dir_' . uniqid(), 'name' => 'Directorio Lendus', 'created_at' => now(), 'updated_at' => now()]);
    $uploader = User::factory()->create();
    $uploadId = DB::table('report_uploads')->insertGetId([
        'period_id' => $period->id, 'data_source_id' => $sourceId, 'uploaded_by' => $uploader->id, 'original_name' => 'dir.xlsx',
        'stored_path' => 'x', 'status' => 'processed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($rows as [$name, $puesto, $estatus, $branchId]) {
        DB::table('lendus_employee_directory')->insert([
            'report_upload_id' => $uploadId, 'nombre' => $name, 'normalized_name' => mb_strtolower($name), 'puesto' => $puesto,
            'estatus' => $estatus, 'inferred_branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

function ptEmployee(string $name, Branch $branch, Period $period, bool $active = true): Employee
{
    $e = Employee::query()->create(['full_name' => $name, 'normalized_name' => mb_strtolower($name), 'is_active' => true]);
    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $e->id, 'branch_id' => $branch->id]);
    PeriodEmployeeRoster::query()->create([
        'period_id' => $period->id, 'employee_id' => $e->id, 'employee_key' => 'k' . $e->id, 'nombre_normalizado' => mb_strtolower($name),
        'branch_id' => $branch->id, 'is_active_for_period' => $active, 'movement_type' => $active ? 'activo' : 'baja', 'reconciliation_status' => $active ? null : 'baja_confirmada', // 18-oct-2026: fila 'baja' = baja CONFIRMADA
    ]);

    return $e;
}

it('uses the payroll roster of the most recent period BY DATE, never the highest period id', function () {
    $branch = Branch::query()->create(['code' => 'ATX', 'name' => 'ATLIXCO', 'normalized_name' => 'atlixco', 'is_active' => true]);
    $june = ptPeriod('Junio 2026', '2026-05-25', '2026-06-21');
    $may = ptPeriod('Mayo 2026', '2026-04-27', '2026-05-24'); // id MAYOR, fecha anterior

    $stillThere = ptEmployee('GESTOR VIGENTE JUNIO', $branch, $june);
    $leftInJune = ptEmployee('GESTOR BAJA JUNIO', $branch, $june, active: false);
    // En Mayo ambos estaban activos.
    PeriodEmployeeRoster::query()->create(['period_id' => $may->id, 'employee_id' => $leftInJune->id, 'employee_key' => 'm1', 'nombre_normalizado' => 'gestor baja junio', 'branch_id' => $branch->id, 'is_active_for_period' => true]);

    $resolver = app(OkrEmployeeBranchResolver::class);
    expect($resolver->currentRosterPeriodId())->toBe($june->id);
    expect($resolver->employeeIsActive($stillThere->id))->toBeTrue();
    expect($resolver->employeeIsActive($leftInJune->id))->toBeFalse();
    expect($resolver->rosterStatus()['label'])->not->toBeNull();
});

it('treats someone listed ONLY as INACTIVO in the current Lendus directory as a baja, and reads the real position', function () {
    $branch = Branch::query()->create(['code' => 'ATX', 'name' => 'ATLIXCO', 'normalized_name' => 'atlixco', 'is_active' => true]);
    $june = ptPeriod('Junio 2026', '2026-05-25', '2026-06-21');
    $david = ptEmployee('DAVID GAMBOA CANO', $branch, $june);
    $gone = ptEmployee('GESTOR INACTIVO LENDUS', $branch, $june);
    ptDirectory($june, [
        ['DAVID GAMBOA CANO', 'GESTOR DE CRÉDITO', 'ACTIVO', $branch->id],
        ['GESTOR INACTIVO LENDUS', 'GESTOR DE CRÉDITO', 'INACTIVO', $branch->id],
    ]);

    expect(app(OkrEmployeeBranchResolver::class)->employeeIsActive($gone->id))->toBeFalse();
    expect(app(OkrPersonnelResolver::class)->profileFor($david->id)['position'])->toBe('GESTOR DE CRÉDITO');
});

it('only SUGGESTS the immediate boss from the current branch structure, and refuses when it is ambiguous', function () {
    $branch = Branch::query()->create(['code' => 'ATX', 'name' => 'ATLIXCO', 'normalized_name' => 'atlixco', 'is_active' => true]);
    $june = ptPeriod('Junio 2026', '2026-05-25', '2026-06-21');
    $david = ptEmployee('DAVID GAMBOA CANO', $branch, $june);
    ptDirectory($june, [
        ['DAVID GAMBOA CANO', 'GESTOR DE CRÉDITO', 'ACTIVO', $branch->id],
        ['ROBERTO SUBGERENTE', 'SUBGERENTE', 'ACTIVO', $branch->id],
        ['LUIS GERENTE', 'GERENTE', 'ACTIVO', $branch->id],
        ['VIEJO SUBGERENTE', 'SUBGERENTE', 'INACTIVO', $branch->id],
    ]);

    $suggestion = app(OkrPersonnelResolver::class)->suggestImmediateBoss($david->id, $branch->id);
    expect($suggestion['name'])->toBe('ROBERTO SUBGERENTE');
    expect($suggestion['is_suggestion'])->toBeTrue();
    expect($suggestion['basis'])->toContain('Confírmalo');

    // Un segundo subgerente activo → ambigüedad: no se sugiere a nadie.
    DB::table('lendus_employee_directory')->insert(['report_upload_id' => DB::table('lendus_employee_directory')->value('report_upload_id'), 'nombre' => 'OTRO SUBGERENTE', 'normalized_name' => 'otro subgerente', 'puesto' => 'SUBGERENTE', 'estatus' => 'ACTIVO', 'inferred_branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    app()->forgetScopedInstances();
    expect(app(OkrPersonnelResolver::class)->suggestImmediateBoss($david->id, $branch->id)['name'])->toBeNull();
});

it('lists activation readiness per OKR and lets an admin complete an individual OKR born WITHOUT Key Results', function () {
    $branch = Branch::query()->create(['code' => 'ATX', 'name' => 'ATLIXCO', 'normalized_name' => 'atlixco', 'is_active' => true]);
    $june = ptPeriod('Junio 2026', '2026-05-25', '2026-06-21');
    $david = ptEmployee('DAVID GAMBOA CANO', $branch, $june);
    $admin = User::factory()->create(['role' => 'admin']);
    $kpi = OkrKpi::query()->create(['code' => 'pt_manual', 'name' => 'Clientes nuevos', 'unit' => 'integer', 'type' => OkrKpi::TYPE_CUMULATIVE, 'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_MANUAL, 'is_active' => true]);

    $parent = OkrObjective::query()->create(['scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'AUMENTAR EBITDA A $100,000.00', 'responsible_user_id' => $admin->id, 'created_by' => $admin->id, 'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(), 'duration_weeks' => 4, 'lifecycle_status' => 'draft']);
    $parent->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR sucursal', 'baseline_value' => 10, 'target_value' => 20, 'weight' => 100]);
    // OKR individual "viejo" que nació sin KR.
    $child = OkrObjective::query()->create(['parent_id' => $parent->id, 'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $david->id, 'title' => 'OKR de David sin KR', 'responsible_user_id' => $admin->id, 'created_by' => $admin->id, 'start_date' => now()->toDateString(), 'end_date' => now()->addWeeks(4)->toDateString(), 'duration_weeks' => 4, 'lifecycle_status' => 'draft']);

    $check = $this->actingAs($admin)->getJson(route('okr.activation-check', $parent))->assertOk()->json();
    expect($check['objective']['ready'])->toBeTrue();
    expect($check['children'][0]['ready'])->toBeFalse();
    expect($check['children'][0]['issues'][0])->toContain('Key Results');

    // Pesos que no suman 100 → rechazado.
    $this->actingAs($admin)->postJson(route('okr.key-results.complete', $child), ['key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR David', 'baseline_value' => 5, 'target_value' => 8, 'weight' => 60]]])->assertStatus(422);

    $this->actingAs($admin)->postJson(route('okr.key-results.complete', $child), ['key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR David', 'baseline_value' => 5, 'target_value' => 8, 'weight' => 100]]])->assertOk();
    expect((float) $child->keyResults()->first()->target_value)->toBe(8.0); // su meta, nunca la de la sucursal (20)
    expect($child->fresh()->parent_id)->toBe($parent->id);
    expect(\App\Models\OkrAuditLog::query()->where('action', 'key_result_completed')->exists())->toBeTrue();

    $this->actingAs($admin)->post(route('okr.activate', $parent), ['include_children' => true])->assertSessionHasNoErrors();
    expect($child->fresh()->lifecycle_status)->toBe('active');
});

it('never projects a cumulative KPI read from the MONTHLY total (no more base × weeks), and projects weekly placement from its real pace', function () {
    $branch = Branch::query()->create(['code' => 'ATX', 'name' => 'ATLIXCO', 'normalized_name' => 'atlixco', 'is_active' => true]);
    $admin = User::factory()->create(['role' => 'admin']);
    $kpi = OkrKpi::query()->create(['code' => 'pt_place', 'name' => 'Colocación', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE, 'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_AUTOMATIC, 'provider_key' => 'reporteria.placement', 'is_active' => true]);
    $start = now()->subWeek()->startOfDay();
    $objective = OkrObjective::query()->create(['scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Colocación Atlixco', 'responsible_user_id' => $admin->id, 'created_by' => $admin->id, 'start_date' => $start, 'end_date' => $start->copy()->addWeeks(12)->subDay(), 'duration_weeks' => 12, 'lifecycle_status' => 'active', 'activated_at' => now()]);
    $kr = $objective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'Colocación', 'baseline_value' => 638576, 'target_value' => 838576, 'weight' => 100, 'baseline_locked_at' => now()]);

    // Sin cargas semanales → proxy mensual (no hay radiografía en pruebas): nunca 638,576 × 12.
    app(OkrSnapshotService::class)->evaluateObjective($objective->fresh());
    expect($kr->fresh()->projected_value)->toBeNull();

    // Con colocación semanal real: S1 $70,000, S2 $70,000 → ritmo × 12 semanas = $840,000.
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([['Gestor', 'Monto'], ['SIN GESTOR', 70000]]);
    foreach ([1, 2] as $week) {
        $path = storage_path('app/testing-pt-' . uniqid() . '.xlsx');
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);
        app(OkrPlacementImportService::class)->import($objective, $week, new UploadedFile($path, 'c.xlsx', null, null, true), $admin);
        @unlink($path);
    }
    app(OkrSnapshotService::class)->evaluateObjective($objective->fresh());
    $kr = $kr->fresh();
    expect((float) $kr->current_value)->toBe(140000.0);
    expect((float) $kr->projected_value)->toBe(840000.0);
    // Esperado a la semana 2 = meta × 2/12 (nunca base + …): no hay "incumplimiento" artificial.
    expect(round((float) $kr->expected_value, 2))->toBe(round(838576 * 2 / 12, 2));
    expect(OkrProgressSnapshot::query()->where('okr_key_result_id', $kr->id)->latest('week_number')->value('source_granularity'))->toBe('weekly');

    // Desglose de la semana 1.
    $breakdown = $this->actingAs($admin)->getJson(route('okr.placement-weeks.breakdown', [$objective, 1]))->assertOk()->json();
    expect((float) $breakdown['upload']['total_amount'])->toBe(70000.0);
    expect($breakdown['rows'][0]['attributed'])->toBeFalse();

    Storage::disk('local')->deleteDirectory('okr-placement-uploads');
});

it('the historical collaborator filter keeps former participants, while the assignment list excludes them', function () {
    $branch = Branch::query()->create(['code' => 'ATX', 'name' => 'ATLIXCO', 'normalized_name' => 'atlixco', 'is_active' => true]);
    $june = ptPeriod('Junio 2026', '2026-05-25', '2026-06-21');
    $former = ptEmployee('EX GESTOR CON OKR', $branch, $june, active: false);
    $admin = User::factory()->create(['role' => 'admin']);
    OkrObjective::query()->create(['scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $former->id, 'title' => 'OKR histórico del ex gestor', 'responsible_user_id' => $admin->id, 'created_by' => $admin->id, 'start_date' => '2026-06-01', 'end_date' => '2026-06-28', 'duration_weeks' => 4, 'lifecycle_status' => 'closed']);

    $assign = collect($this->actingAs($admin)->getJson("/okr/employees-lookup?branch_id={$branch->id}")->json('employees'))->pluck('full_name');
    $history = collect($this->actingAs($admin)->getJson("/okr/employees-lookup?branch_id={$branch->id}&mode=history")->json('employees'))->pluck('full_name');

    expect($assign)->not->toContain('EX GESTOR CON OKR');
    expect($history)->toContain('EX GESTOR CON OKR (baja)');
});
