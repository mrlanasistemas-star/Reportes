<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\OkrPlacementUpload;
use App\Models\Period;
use App\Models\User;
use App\Services\Okr\OkrPlacementImportService;
use App\Services\Okr\OkrPlacementUploadConflictException;
use App\Services\Okr\OkrSnapshotService;
use App\Services\Okr\OkrWeeklyPlacementResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

uses(RefreshDatabase::class);

/**
 * Parte 6/7/8 del cierre (04-oct-2026) — colocación semanal real por
 * Objective. Cubre los TEST 3/4/6/7/8 del pedido (Parte 19).
 */
function okrPlacementXlsx(array $rows, array $headers = ['Colaborador', 'Colocación', 'Fecha']): UploadedFile
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    foreach ($headers as $i => $h) {
        $sheet->setCellValueByColumnAndRow($i + 1, 1, $h);
    }
    foreach ($rows as $r => $data) {
        foreach ($data as $c => $value) {
            $sheet->setCellValueByColumnAndRow($c + 1, $r + 2, $value);
        }
    }

    $absolutePath = storage_path('app/testing-placement-' . uniqid() . '.xlsx');
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($absolutePath);

    return new UploadedFile($absolutePath, 'colocacion.xlsx', 'application/vnd.ms-excel', null, true);
}

function okrPlacementKpi(): OkrKpi
{
    return OkrKpi::query()->firstOrCreate(['code' => 'placement_weekly_test'], [
        'name' => 'Colocación (prueba semanal)', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE,
        'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_AUTOMATIC,
        'provider_key' => 'reporteria.placement', 'is_active' => true,
    ]);
}

function okrPlacementObjective(array $attrs, float $target): OkrObjective
{
    $user = User::factory()->create();
    $objective = OkrObjective::query()->create(array_merge([
        'title' => 'Objective de colocación semanal de prueba',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'duration_weeks' => 4, 'lifecycle_status' => OkrObjective::STATUS_ACTIVE,
    ], $attrs));
    $objective->keyResults()->create([
        'kpi_id' => okrPlacementKpi()->id, 'description' => 'Colocación acumulada',
        'baseline_value' => 0, 'target_value' => $target, 'weight' => 100,
        'baseline_locked_at' => now(),
    ]);

    return $objective;
}

/** TEST 3 — cuatro semanas individuales, acumulado 200/475/700/1000. */
it('TEST 3 — four weekly uploads for an employee objective produce the exact cumulative 200k/475k/700k/1M', function () {
    $branch = Branch::query()->create(['code' => 'CVA', 'name' => 'CUERNAVACA', 'normalized_name' => 'cuernavaca', 'is_active' => true]);
    $employee = Employee::query()->create(['full_name' => 'JUAN PEREZ GOMEZ', 'normalized_name' => 'juan perez gomez', 'is_active' => true]);
    EmployeeBranchAssignment::query()->create(['period_id' => Period::query()->create(['name' => 'P', 'code' => 'P-' . uniqid(), 'type' => 'weekly', 'year' => 2026, 'month' => 1, 'sequence' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-07'])->id, 'employee_id' => $employee->id, 'branch_id' => $branch->id]);

    $objective = okrPlacementObjective([
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id,
        'start_date' => now()->subWeeks(4)->startOfDay(), 'end_date' => now()->addDay(),
    ], target: 1_000_000);
    $user = User::factory()->create();

    $importer = app(OkrPlacementImportService::class);
    $weeks = [1 => 200000, 2 => 275000, 3 => 225000, 4 => 300000];
    foreach ($weeks as $week => $amount) {
        $importer->import($objective, $week, okrPlacementXlsx([['JUAN PEREZ GOMEZ', $amount]]), $user);
    }

    $resolver = app(OkrWeeklyPlacementResolver::class);
    expect($resolver->resolve($objective, 1)['cumulative'])->toBe(200000.0);
    expect($resolver->resolve($objective, 2)['cumulative'])->toBe(475000.0);
    expect($resolver->resolve($objective, 3)['cumulative'])->toBe(700000.0);
    expect($resolver->resolve($objective, 4)['cumulative'])->toBe(1000000.0);
    expect($resolver->resolve($objective, 2)['weekly'])->toBe(275000.0);

    app(OkrSnapshotService::class)->backfillMissingWeeks($objective);
    $kr = $objective->keyResults()->first();
    $snap4 = $kr->snapshots()->where('week_number', 4)->first();
    expect((float) $snap4->actual_value)->toBe(1000000.0);
    expect($snap4->source_granularity)->toBe('weekly');
    expect($snap4->source_quality)->toBe('exact');
    // Meta alcanzada exactamente al 100%.
    expect((float) $snap4->actual_progress_percentage)->toBe(100.0);
});

/** TEST 4 — sucursal: 3 gestores (200k/300k/100k) = 600k, desglose conservado. */
it('TEST 4 — a branch objective sums its gestores from ONE file (200k+300k+100k=600k) and keeps the per-gestor breakdown', function () {
    $branch = Branch::query()->create(['code' => 'ORZ', 'name' => 'ORIZABA', 'normalized_name' => 'orizaba', 'is_active' => true]);
    $a = Employee::query()->create(['full_name' => 'GESTOR A PRUEBA', 'normalized_name' => 'gestor a prueba', 'is_active' => true]);
    $b = Employee::query()->create(['full_name' => 'GESTOR B PRUEBA', 'normalized_name' => 'gestor b prueba', 'is_active' => true]);
    $c = Employee::query()->create(['full_name' => 'GESTOR C PRUEBA', 'normalized_name' => 'gestor c prueba', 'is_active' => true]);

    $objective = okrPlacementObjective([
        'scope_type' => 'branch', 'branch_id' => $branch->id,
        'start_date' => now()->subWeek()->startOfDay(), 'end_date' => now()->addWeeks(3),
    ], target: 3_000_000);
    $user = User::factory()->create();

    $upload = app(OkrPlacementImportService::class)->import($objective, 1, okrPlacementXlsx([
        ['GESTOR A PRUEBA', 200000], ['GESTOR B PRUEBA', 300000], ['GESTOR C PRUEBA', 100000],
    ]), $user);

    expect((float) $upload->total_amount)->toBe(600000.0);
    expect($upload->movements()->where('employee_id', $a->id)->sum('amount'))->toEqual(200000);
    expect($upload->movements()->where('employee_id', $b->id)->sum('amount'))->toEqual(300000);
    expect($upload->movements()->where('employee_id', $c->id)->sum('amount'))->toEqual(100000);

    $resolver = app(OkrWeeklyPlacementResolver::class);
    expect($resolver->resolve($objective, 1)['cumulative'])->toBe(600000.0);
});

/** TEST 6 — semana sin archivo: actual=null (nunca 0), nunca cae en la general. */
it('TEST 6 — a week with no placement upload yields a null actual (never 0), and no automatic warning is implied', function () {
    $branch = Branch::query()->create(['code' => 'TUL', 'name' => 'TULA', 'normalized_name' => 'tula', 'is_active' => true]);
    $employee = Employee::query()->create(['full_name' => 'GESTOR SIN SEMANA 2', 'normalized_name' => 'gestor sin semana 2', 'is_active' => true]);

    $objective = okrPlacementObjective([
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id,
        'start_date' => now()->subWeeks(3)->startOfDay(), 'end_date' => now()->addWeek(),
    ], target: 1_000_000);
    $user = User::factory()->create();
    $importer = app(OkrPlacementImportService::class);

    // Semana 1 y 3 SÍ tienen archivo — semana 2 NUNCA se cargó.
    $importer->import($objective, 1, okrPlacementXlsx([['GESTOR SIN SEMANA 2', 200000]]), $user);
    $importer->import($objective, 3, okrPlacementXlsx([['GESTOR SIN SEMANA 2', 225000]]), $user);

    $resolver = app(OkrWeeklyPlacementResolver::class);
    $week2 = $resolver->resolve($objective, 2);
    expect($week2['weekly'])->toBeNull();
    expect($week2['cumulative'])->toBeNull();
    expect($week2['missing_weeks'])->toBe([2]);

    // Semana 1 sola SÍ tiene dato real (no depende de la 2).
    expect($resolver->resolve($objective, 1)['cumulative'])->toBe(200000.0);

    app(OkrSnapshotService::class)->backfillMissingWeeks($objective);
    $kr = $objective->keyResults()->first();
    $snap2 = $kr->snapshots()->where('week_number', 2)->first();
    expect($snap2->actual_value)->toBeNull();
    expect($snap2->source_quality)->toBe(\App\Models\OkrProgressSnapshot::QUALITY_MISSING);
    expect($snap2->actual_progress_percentage)->toBeNull(); // nunca "0% de cumplimiento" disfrazado
});

/** TEST 7 — reemplazo controlado: subir W2 dos veces no duplica. */
it('TEST 7 — uploading the same week twice requires explicit confirmation and never duplicates the amount', function () {
    $branch = Branch::query()->create(['code' => 'PUE', 'name' => 'PUEBLA', 'normalized_name' => 'puebla', 'is_active' => true]);
    $employee = Employee::query()->create(['full_name' => 'GESTOR REEMPLAZO', 'normalized_name' => 'gestor reemplazo', 'is_active' => true]);

    $objective = okrPlacementObjective([
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id,
        'start_date' => now()->subWeek()->startOfDay(), 'end_date' => now()->addWeeks(3),
    ], target: 1_000_000);
    $user = User::factory()->create();
    $importer = app(OkrPlacementImportService::class);

    $first = $importer->import($objective, 1, okrPlacementXlsx([['GESTOR REEMPLAZO', 200000]]), $user);

    // Reintento SIN confirmar reemplazo — se rechaza con la excepción de conflicto.
    expect(fn () => $importer->import($objective, 1, okrPlacementXlsx([['GESTOR REEMPLAZO', 999999]]), $user))
        ->toThrow(OkrPlacementUploadConflictException::class);

    expect(OkrPlacementUpload::query()->where('okr_objective_id', $objective->id)->where('week_number', 1)->count())->toBe(1);

    // CON confirmación — reemplaza (versiona), nunca suma 200000+999999.
    $second = $importer->import($objective, 1, okrPlacementXlsx([['GESTOR REEMPLAZO', 350000]]), $user, confirmReplace: true);

    expect($first->fresh()->status)->toBe(OkrPlacementUpload::STATUS_SUPERSEDED);
    expect($second->status)->toBe(OkrPlacementUpload::STATUS_ACTIVE);
    expect($second->replaced_upload_id)->toBe($first->id);
    expect((float) $second->total_amount)->toBe(350000.0);

    $resolver = app(OkrWeeklyPlacementResolver::class);
    // El resultado vigente es SOLO el de la versión activa — nunca 200000+350000.
    expect($resolver->resolve($objective, 1)['cumulative'])->toBe(350000.0);
});

/** TEST 8 — scope correcto: archivo con otros gestores, el Objective individual solo suma lo suyo. */
it('TEST 8 — an employee objective only sums rows for THAT employee, ignoring other gestores in the same file', function () {
    $branch = Branch::query()->create(['code' => 'CRD', 'name' => 'CORDOBA', 'normalized_name' => 'cordoba', 'is_active' => true]);
    $target = Employee::query()->create(['full_name' => 'GESTOR CORDOBA OBJETIVO', 'normalized_name' => 'gestor cordoba objetivo', 'is_active' => true]);
    Employee::query()->create(['full_name' => 'OTRO GESTOR CORDOBA', 'normalized_name' => 'otro gestor cordoba', 'is_active' => true]);

    $objective = okrPlacementObjective([
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $target->id,
        'start_date' => now()->subWeek()->startOfDay(), 'end_date' => now()->addWeeks(3),
    ], target: 1_000_000);
    $user = User::factory()->create();

    // El archivo trae AMBOS gestores — el Objective individual debe sumar SOLO el suyo.
    $upload = app(OkrPlacementImportService::class)->import($objective, 1, okrPlacementXlsx([
        ['GESTOR CORDOBA OBJETIVO', 175000], ['OTRO GESTOR CORDOBA', 999999],
    ]), $user);

    expect((float) $upload->total_amount)->toBe(175000.0);
    expect($upload->movements()->count())->toBe(1);

    $resolver = app(OkrWeeklyPlacementResolver::class);
    expect($resolver->resolve($objective, 1)['cumulative'])->toBe(175000.0);
});

/** Idempotencia dentro del mismo archivo (6.8) — una fila duplicada no se cuenta dos veces. */
it('a duplicated row within the SAME file is counted only once (fingerprint-based idempotency)', function () {
    $branch = Branch::query()->create(['code' => 'ATL', 'name' => 'ATLACOMULCO', 'normalized_name' => 'atlacomulco', 'is_active' => true]);
    $employee = Employee::query()->create(['full_name' => 'GESTOR DUPLICADO', 'normalized_name' => 'gestor duplicado', 'is_active' => true]);

    $objective = okrPlacementObjective([
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id,
        'start_date' => now()->subWeek()->startOfDay(), 'end_date' => now()->addWeeks(3),
    ], target: 1_000_000);
    $user = User::factory()->create();

    $upload = app(OkrPlacementImportService::class)->import($objective, 1, okrPlacementXlsx([
        ['GESTOR DUPLICADO', 100000], ['GESTOR DUPLICADO', 100000],
    ]), $user);

    expect($upload->movements()->count())->toBe(1);
    expect((float) $upload->total_amount)->toBe(100000.0);
});

/**
 * Parte 13 del cierre (04-oct-2026) — bug real corregido: el check-in solo
 * refrescaba los KR que venían en `manual_results`; un KR AUTOMÁTICO (como
 * colocación) se quedaba con el current_value de la ÚLTIMA evaluación,
 * ignorando una carga semanal recién subida. Ahora el check-in refresca
 * TODOS los KR automáticos antes de congelar actual_value_snapshot.
 */
it('TEST 10 (Parte 13) — a check-in refreshes the automatic placement KR with the just-uploaded weekly snapshot, never a stale cached value', function () {
    $branch = Branch::query()->create(['code' => 'XAL', 'name' => 'XALAPA', 'normalized_name' => 'xalapa', 'is_active' => true]);
    $employee = Employee::query()->create(['full_name' => 'GESTOR CHECKIN REFRESH', 'normalized_name' => 'gestor checkin refresh', 'is_active' => true]);

    $objective = okrPlacementObjective([
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id,
        'start_date' => now()->startOfDay(), 'end_date' => now()->addWeeks(3),
        'responsible_user_id' => null,
    ], target: 1_000_000);
    $admin = User::factory()->create();
    $objective->update(['responsible_user_id' => $admin->id]);
    $kr = $objective->keyResults()->first();

    // Carga semanal recién subida — el KR automático todavía NUNCA se evaluó.
    app(OkrPlacementImportService::class)->import($objective, 1, okrPlacementXlsx([['GESTOR CHECKIN REFRESH', 180000]]), $admin);
    expect($kr->fresh()->current_value)->toBeNull(); // todavía no se ha evaluado ni una vez

    // Check-in SIN manual_results para este KR (es automático) — igual debe refrescarlo.
    $this->actingAs($admin)->post(route('okr.check-ins.store', $objective), [
        'main_blocker' => 'Prueba de refresco automático en check-in.',
    ])->assertSessionHasNoErrors();

    expect((float) $kr->fresh()->current_value)->toBe(180000.0);

    $checkIn = $objective->checkIns()->latest()->first();
    expect((float) $checkIn->actual_value_snapshot["kr_{$kr->id}"]['value'])->toBe(180000.0);
});

/** Flujo HTTP completo del controlador — éxito, conflicto, reemplazo confirmado y solo-lectura. */
it('the placement-uploads HTTP endpoint returns success, then a 409 conflict without confirm_replace, then succeeds with confirm_replace', function () {
    $branch = Branch::query()->create(['code' => 'MOR', 'name' => 'MORELIA', 'normalized_name' => 'morelia', 'is_active' => true]);
    $employee = Employee::query()->create(['full_name' => 'GESTOR HTTP PRUEBA', 'normalized_name' => 'gestor http prueba', 'is_active' => true]);

    $objective = okrPlacementObjective([
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id,
        'start_date' => now()->startOfDay(), 'end_date' => now()->addWeeks(3),
    ], target: 1_000_000);
    $admin = User::factory()->create();
    $objective->update(['responsible_user_id' => $admin->id]);

    $r1 = $this->actingAs($admin)->post(route('okr.placement-uploads.store', $objective), [
        'week_number' => 1, 'file' => okrPlacementXlsx([['GESTOR HTTP PRUEBA', 150000]]),
    ]);
    $r1->assertOk();
    expect($r1->json('success'))->toBeTrue();
    expect((float) $r1->json('upload.total_amount'))->toBe(150000.0);

    $r2 = $this->actingAs($admin)->post(route('okr.placement-uploads.store', $objective), [
        'week_number' => 1, 'file' => okrPlacementXlsx([['GESTOR HTTP PRUEBA', 999999]]),
    ]);
    $r2->assertStatus(409);
    expect($r2->json('conflict'))->toBeTrue();

    $r3 = $this->actingAs($admin)->post(route('okr.placement-uploads.store', $objective), [
        'week_number' => 1, 'file' => okrPlacementXlsx([['GESTOR HTTP PRUEBA', 220000]]), 'confirm_replace' => true,
    ]);
    $r3->assertOk();
    expect($r3->json('replaced'))->toBeTrue();
    expect((float) $r3->json('upload.total_amount'))->toBe(220000.0);
    expect(OkrPlacementUpload::query()->where('okr_objective_id', $objective->id)->count())->toBe(2);
});

it('rejects a placement upload on a closed/cancelled objective — read-only (Parte 12)', function () {
    $branch = Branch::query()->create(['code' => 'ZAC', 'name' => 'ZACATECAS', 'normalized_name' => 'zacatecas', 'is_active' => true]);
    $employee = Employee::query()->create(['full_name' => 'GESTOR CERRADO', 'normalized_name' => 'gestor cerrado', 'is_active' => true]);

    $objective = okrPlacementObjective([
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id,
        'start_date' => now()->startOfDay(), 'end_date' => now()->addWeeks(3),
        'lifecycle_status' => OkrObjective::STATUS_CLOSED,
    ], target: 1_000_000);
    $admin = User::factory()->create();
    $objective->update(['responsible_user_id' => $admin->id]);

    $response = $this->actingAs($admin)->post(route('okr.placement-uploads.store', $objective), [
        'week_number' => 1, 'file' => okrPlacementXlsx([['GESTOR CERRADO', 100000]]),
    ]);

    $response->assertStatus(422);
    expect(OkrPlacementUpload::query()->where('okr_objective_id', $objective->id)->exists())->toBeFalse();
});

afterEach(function () {
    Storage::disk('local')->deleteDirectory('okr-placement-uploads');
});
