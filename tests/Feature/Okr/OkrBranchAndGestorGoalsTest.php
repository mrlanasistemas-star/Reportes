<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\OkrPlacementUpload;
use App\Models\Period;
use App\Models\PeriodEmployeeRoster;
use App\Models\User;
use App\Services\Okr\OkrPlacementImportService;
use App\Services\Okr\OkrWeeklyPlacementResolver;
use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

uses(RefreshDatabase::class);

/**
 * Corrección integral OKR 14-oct-2026 — metas de sucursal + metas por gestor
 * desde el mismo asistente, una sola carga de colocación para ambos niveles,
 * colaboradores vigentes según la nómina procesada, cascada de activación,
 * seguimiento por gestor y documentos por alcance real.
 */
function bgSetup(): array
{
    $period = Period::query()->create(['name' => 'Junio 2026', 'code' => 'BG-' . uniqid(), 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1, 'start_date' => '2026-05-25', 'end_date' => '2026-06-21']);
    $branch = Branch::query()->create(['code' => 'ATX', 'name' => 'ATLIXCO', 'normalized_name' => 'atlixco', 'is_active' => true]);
    $other = Branch::query()->create(['code' => 'TLX', 'name' => 'TLAXCALA', 'normalized_name' => 'tlaxcala', 'is_active' => true]);

    $make = function (string $name, Branch $b, bool $inRoster = true, bool $rosterActive = true) use ($period) {
        $e = Employee::query()->create(['full_name' => $name, 'normalized_name' => mb_strtolower($name), 'is_active' => true]);
        EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $e->id, 'branch_id' => $b->id]);
        if ($inRoster) {
            PeriodEmployeeRoster::query()->create([
                'period_id' => $period->id, 'employee_id' => $e->id, 'employee_key' => 'k' . $e->id, 'nombre_normalizado' => mb_strtolower($name),
                'branch_id' => $b->id, 'is_active_for_period' => $rosterActive, 'movement_type' => $rosterActive ? 'activo' : 'baja',
            ]);
        }

        return $e;
    };

    return [
        'period' => $period, 'branch' => $branch, 'other' => $other,
        'a' => $make('GESTOR A ATLIXCO', $branch),
        'b' => $make('GESTOR B ATLIXCO', $branch),
        'c' => $make('GESTOR C ATLIXCO', $branch),
        'gone' => $make('GESTOR QUE YA NO TRABAJA', $branch, inRoster: false),
        'baja' => $make('GESTOR BAJA EN NOMINA', $branch, rosterActive: false),
        'tlx' => $make('GESTOR DE TLAXCALA', $other),
        'admin' => User::factory()->create(['role' => 'admin']),
    ];
}

function bgPlacementKpi(): OkrKpi
{
    return OkrKpi::query()->firstOrCreate(['code' => 'bg_placement'], [
        'name' => 'Colocación', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE, 'direction' => OkrKpi::DIRECTION_INCREASE,
        'automation' => OkrKpi::AUTOMATION_AUTOMATIC, 'provider_key' => 'reporteria.placement', 'is_active' => true,
    ]);
}

function bgManualKpi(): OkrKpi
{
    return OkrKpi::query()->firstOrCreate(['code' => 'bg_manual'], [
        'name' => 'Clientes nuevos', 'unit' => 'integer', 'type' => OkrKpi::TYPE_CUMULATIVE, 'direction' => OkrKpi::DIRECTION_INCREASE,
        'automation' => OkrKpi::AUTOMATION_MANUAL, 'is_active' => true,
    ]);
}

function bgXlsx(array $rows, array $headers): UploadedFile
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
    $path = storage_path('app/testing-bg-' . uniqid() . '.xlsx');
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

    return new UploadedFile($path, 'lendus-colocacion.xlsx', 'application/vnd.ms-excel', null, true);
}

function bgPayload(array $s, array $individuals): array
{
    return [
        'scope_type' => 'branch', 'branch_id' => $s['branch']->id,
        'title' => 'Incrementar colocación de cartera en Atlixco',
        'responsible_user_id' => $s['admin']->id,
        'start_date' => now()->toDateString(), 'duration_weeks' => 4,
        'key_results' => [['kpi_id' => bgManualKpi()->id, 'description' => 'Colocación de la sucursal', 'baseline_value' => 800000, 'target_value' => 1000000, 'weight' => 100]],
        'individual_objectives' => $individuals,
    ];
}

it('creates the branch OKR plus ONLY the selected gestores, each with its OWN baseline, increment and goal', function () {
    $s = bgSetup();
    $kpi = bgManualKpi();

    $this->actingAs($s['admin'])->post(route('okr.store'), bgPayload($s, [
        ['employee_id' => $s['a']->id, 'title' => 'Colocación individual del gestor A', 'key_results' => [
            ['kpi_id' => $kpi->id, 'description' => 'Colocación gestor A', 'baseline_value' => 100000, 'target_value' => 130000, 'weight' => 100],
        ]],
        ['employee_id' => $s['b']->id, 'title' => 'Colocación individual del gestor B', 'key_results' => [
            ['kpi_id' => $kpi->id, 'description' => 'Colocación gestor B', 'baseline_value' => 180000, 'target_value' => 230000, 'weight' => 100],
        ]],
    ]))->assertSessionHasNoErrors();

    $parent = OkrObjective::query()->where('scope_type', 'branch')->firstOrFail();
    $children = OkrObjective::query()->where('parent_id', $parent->id)->get()->keyBy('employee_id');

    expect($children)->toHaveCount(2);
    expect($children->has($s['c']->id))->toBeFalse(); // no seleccionado → sin OKR

    $krA = $children[$s['a']->id]->keyResults()->firstOrFail();
    $krB = $children[$s['b']->id]->keyResults()->firstOrFail();
    expect([(float) $krA->baseline_value, (float) $krA->target_value])->toBe([100000.0, 130000.0]);
    expect([(float) $krB->baseline_value, (float) $krB->target_value])->toBe([180000.0, 230000.0]);
    // Nunca la base/meta de la sucursal copiada a los gestores.
    expect((float) $parent->keyResults()->first()->target_value)->toBe(1000000.0);
    foreach ($children as $child) {
        expect($child->lifecycle_status)->toBe(OkrObjective::STATUS_DRAFT);
        expect($child->keyResults()->count())->toBeGreaterThan(0);
    }
});

it('rejects an individual OKR without Key Results or whose weights do not add up to 100% — per OKR, not across gestores', function () {
    $s = bgSetup();
    $kpi = bgManualKpi();

    $this->actingAs($s['admin'])->post(route('okr.store'), bgPayload($s, [
        ['employee_id' => $s['a']->id, 'title' => 'Colocación individual del gestor A'],
    ]))->assertSessionHasErrors('individual_objectives.0.key_results');

    $this->actingAs($s['admin'])->post(route('okr.store'), bgPayload($s, [
        ['employee_id' => $s['a']->id, 'title' => 'Colocación individual del gestor A', 'key_results' => [
            ['kpi_id' => $kpi->id, 'description' => 'KR A', 'baseline_value' => 1, 'target_value' => 2, 'weight' => 60],
        ]],
        ['employee_id' => $s['b']->id, 'title' => 'Colocación individual del gestor B', 'key_results' => [
            ['kpi_id' => $kpi->id, 'description' => 'KR B', 'baseline_value' => 1, 'target_value' => 2, 'weight' => 40],
        ]],
    ]))->assertSessionHasErrors(['individual_objectives.0.key_results', 'individual_objectives.1.key_results']);

    expect(OkrObjective::query()->count())->toBe(0);
});

it('lists only collaborators active in the current payroll roster and rejects ex-employees sent directly by HTTP', function () {
    $s = bgSetup();

    $names = collect($this->actingAs($s['admin'])->getJson("/okr/employees-lookup?branch_id={$s['branch']->id}")->json('employees'))->pluck('full_name');
    expect($names)->toContain('GESTOR A ATLIXCO')->toContain('GESTOR B ATLIXCO')
        ->not->toContain('GESTOR QUE YA NO TRABAJA')
        ->not->toContain('GESTOR BAJA EN NOMINA')
        ->not->toContain('GESTOR DE TLAXCALA');

    $kpi = bgManualKpi();
    foreach (['gone', 'baja', 'tlx'] as $key) {
        $this->actingAs($s['admin'])->post(route('okr.store'), bgPayload($s, [
            ['employee_id' => $s[$key]->id, 'title' => 'Intento de OKR para alguien no elegible', 'key_results' => [
                ['kpi_id' => $kpi->id, 'description' => 'KR', 'baseline_value' => 1, 'target_value' => 2, 'weight' => 100],
            ]],
        ]))->assertSessionHasErrors('individual_objectives.0.employee_id');
    }
    expect(OkrObjective::query()->count())->toBe(0);
});

it('prevents a duplicated individual assignment overlapping an existing draft/active OKR of the same gestor', function () {
    $s = bgSetup();
    $kpi = bgManualKpi();
    $row = ['employee_id' => $s['a']->id, 'title' => 'Colocación individual del gestor A', 'key_results' => [
        ['kpi_id' => $kpi->id, 'description' => 'KR A', 'baseline_value' => 1, 'target_value' => 2, 'weight' => 100],
    ]];

    $this->actingAs($s['admin'])->post(route('okr.store'), bgPayload($s, [$row]))->assertSessionHasNoErrors();
    $this->actingAs($s['admin'])->post(route('okr.store'), bgPayload($s, [$row]))->assertSessionHasErrors('individual_objectives.0.employee_id');
});

it('accepts a manual baseline for an AUTOMATIC KPI only with a reason, and records it in the audit log', function () {
    $s = bgSetup();
    $kpi = bgPlacementKpi();
    $kr = fn (array $extra) => [['kpi_id' => $kpi->id, 'description' => 'Colocación gestor A', 'target_value' => 50000, 'weight' => 100] + $extra];

    // Sin motivo → rechazado.
    $this->actingAs($s['admin'])->post(route('okr.store'), bgPayload($s, [
        ['employee_id' => $s['a']->id, 'title' => 'Colocación individual del gestor A', 'key_results' => $kr(['baseline_value' => 20000])],
    ]))->assertSessionHasErrors('individual_objectives.0.key_results.0.baseline_value');

    // Con motivo y sin dato verificable en Reportería → aceptado y trazado.
    $this->actingAs($s['admin'])->post(route('okr.store'), bgPayload($s, [
        ['employee_id' => $s['a']->id, 'title' => 'Colocación individual del gestor A', 'key_results' => $kr(['baseline_value' => 20000, 'baseline_manual_reason' => 'Gestor de nuevo ingreso sin colocación histórica en Reportería'])],
    ]))->assertSessionHasNoErrors();

    $child = OkrObjective::query()->where('employee_id', $s['a']->id)->firstOrFail();
    $savedKr = $child->keyResults()->firstOrFail();
    expect((float) $savedKr->baseline_value)->toBe(20000.0);
    expect($savedKr->baseline_source)->toBe('manual_authorized');
    expect(\App\Models\OkrAuditLog::query()->where('auditable_type', 'key_result')->where('auditable_id', $savedKr->id)->where('action', 'baseline_manual_authorized')->value('reason'))
        ->toContain('nuevo ingreso');
});

it('one single Lendus file feeds the branch total and each gestor, excludes other branches/cancelled rows and never double counts a movement repeated the next week', function () {
    $s = bgSetup();
    $kpi = bgPlacementKpi();
    $start = now()->subWeek()->startOfDay(); // hoy = semana 2

    $parent = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $s['branch']->id, 'title' => 'Colocación Atlixco', 'responsible_user_id' => $s['admin']->id, 'created_by' => $s['admin']->id,
        'start_date' => $start, 'end_date' => $start->copy()->addWeeks(4)->subDay(), 'duration_weeks' => 4, 'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $parent->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'Colocación sucursal', 'baseline_value' => 0, 'target_value' => 1000000, 'weight' => 100, 'baseline_locked_at' => now()]);

    $children = [];
    foreach (['a' => 130000, 'b' => 230000] as $key => $target) {
        $child = OkrObjective::query()->create([
            'parent_id' => $parent->id, 'scope_type' => 'employee', 'branch_id' => $s['branch']->id, 'employee_id' => $s[$key]->id,
            'title' => "Colocación {$key}", 'responsible_user_id' => $s['admin']->id, 'created_by' => $s['admin']->id,
            'start_date' => $start, 'end_date' => $start->copy()->addWeeks(4)->subDay(), 'duration_weeks' => 4, 'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
        ]);
        $child->keyResults()->create(['kpi_id' => $kpi->id, 'description' => "Colocación {$key}", 'baseline_value' => 0, 'target_value' => $target, 'weight' => 100, 'baseline_locked_at' => now()]);
        $children[$key] = $child;
    }

    $d1 = $start->copy()->addDays(1)->toDateString();
    $d2 = $start->copy()->addDays(8)->toDateString();
    $headers = ['Contrato', 'Fecha', 'Sucursal', 'Gestor', 'Monto', 'Estatus'];

    // Semana 1: A 40k + C 25k (sin OKR individual, sí cuenta a la sucursal) + fila de Tlaxcala + cancelado.
    $w1 = [
        ['CT-1', $d1, 'ATLIXCO', 'GESTOR A ATLIXCO', 40000, 'VIGENTE'],
        ['CT-2', $d1, 'ATLIXCO', 'GESTOR C ATLIXCO', 25000, 'VIGENTE'],
        ['CT-3', $d1, 'TLAXCALA', 'GESTOR DE TLAXCALA', 99000, 'VIGENTE'],
        ['CT-4', $d1, 'ATLIXCO', 'GESTOR B ATLIXCO', 70000, 'CANCELADO'],
    ];
    // Semana 2: el reporte vuelve a traer CT-1 (ya contado) + B 60k.
    $w2 = [
        ['CT-1', $d1, 'ATLIXCO', 'GESTOR A ATLIXCO', 40000, 'VIGENTE'],
        ['CT-5', $d2, 'ATLIXCO', 'GESTOR B ATLIXCO', 60000, 'VIGENTE'],
    ];

    $r1 = $this->actingAs($s['admin'])->post(route('okr.placement-uploads.store', $parent), ['week_number' => 1, 'file' => bgXlsx($w1, $headers), 'complete_report' => true])->assertOk();
    expect((float) $r1->json('upload.total_amount'))->toBe(65000.0);
    expect($r1->json('upload.rows_other_branch'))->toBe(1);
    expect($r1->json('upload.rows_cancelled'))->toBe(1);

    $r2 = $this->actingAs($s['admin'])->post(route('okr.placement-uploads.store', $parent), ['week_number' => 2, 'file' => bgXlsx($w2, $headers), 'complete_report' => true])->assertOk();
    expect($r2->json('upload.rows_duplicated'))->toBe(1);

    $resolver = app(OkrWeeklyPlacementResolver::class);
    $branch = $resolver->resolve($parent->fresh(), 2);
    expect($branch['cumulative'])->toBe(125000.0);        // 40k + 25k + 60k (CT-1 una sola vez)
    expect($branch['weeks'])->toBe([65000.0, 60000.0]);

    expect($resolver->resolve($children['a']->fresh(), 2)['cumulative'])->toBe(40000.0); // nunca 80k
    expect($resolver->resolve($children['b']->fresh(), 2)['cumulative'])->toBe(60000.0); // cancelado excluido

    // Los individuales se recalcularon con la MISMA carga (sin subir archivos por gestor).
    expect((float) $children['a']->fresh()->keyResults()->first()->current_value)->toBe(40000.0);
    expect((float) $children['b']->fresh()->keyResults()->first()->current_value)->toBe(60000.0);
    expect((float) $parent->fresh()->keyResults()->first()->current_value)->toBe(125000.0);
    // Histórico semanal del gestor reescrito para la semana 1.
    expect((float) $children['a']->fresh()->keyResults()->first()->snapshots()->where('week_number', 1)->value('actual_value'))->toBe(40000.0);

    // Detalle de la sucursal: seguimiento por gestor con datos propios.
    $tracking = $this->actingAs($s['admin'])->get(route('okr.show', $parent))->viewData('page')['props']['objective']['gestor_tracking'];
    $byName = collect($tracking)->keyBy('employee');
    expect($byName)->toHaveCount(2);
    expect((float) $byName['GESTOR A ATLIXCO']['key_results'][0]['target_value'])->toBe(130000.0);
    expect((float) $byName['GESTOR B ATLIXCO']['key_results'][0]['target_value'])->toBe(230000.0);
    expect((float) $byName['GESTOR A ATLIXCO']['key_results'][0]['current_value'])->toBe(40000.0);
    expect($byName['GESTOR A ATLIXCO']['employee_active'])->toBeTrue();
});

it('matches movements stored under another historical Employee id of the same person (canonical identity)', function () {
    $s = bgSetup();
    $kpi = bgPlacementKpi();
    $start = now()->subWeek()->startOfDay();
    $parent = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $s['branch']->id, 'title' => 'Colocación Atlixco', 'responsible_user_id' => $s['admin']->id, 'created_by' => $s['admin']->id,
        'start_date' => $start, 'end_date' => $start->copy()->addWeeks(4)->subDay(), 'duration_weeks' => 4, 'lifecycle_status' => OkrObjective::STATUS_ACTIVE,
    ]);
    $parent->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'Colocación', 'baseline_value' => 0, 'target_value' => 1, 'weight' => 100]);
    $upload = app(OkrPlacementImportService::class)->import($parent, 1, bgXlsx([['GESTOR A ATLIXCO', 35000]], ['Gestor', 'Monto']), $s['admin']);

    // La persona tiene un segundo id histórico (mismo normalized_name) — el OKR individual quedó con ese id.
    $duplicate = Employee::query()->create(['full_name' => 'GESTOR A ATLIXCO', 'normalized_name' => 'gestor a atlixco', 'is_active' => true]);
    $child = OkrObjective::query()->create([
        'parent_id' => $parent->id, 'scope_type' => 'employee', 'branch_id' => $s['branch']->id, 'employee_id' => $duplicate->id,
        'title' => 'Colocación gestor A', 'responsible_user_id' => $s['admin']->id, 'created_by' => $s['admin']->id,
        'start_date' => $start, 'end_date' => $start->copy()->addWeeks(4)->subDay(), 'duration_weeks' => 4, 'lifecycle_status' => OkrObjective::STATUS_ACTIVE,
    ]);

    expect($upload->movements()->value('employee_id'))->toBe($s['a']->id);
    expect(app(OkrWeeklyPlacementResolver::class)->resolve($child, 1)['cumulative'])->toBe(35000.0);
});

it('activating the branch OKR can activate its individual OKRs in cascade, each with its own frozen baseline', function () {
    $s = bgSetup();
    $kpi = bgManualKpi();
    $this->actingAs($s['admin'])->post(route('okr.store'), bgPayload($s, [
        ['employee_id' => $s['a']->id, 'title' => 'Colocación individual del gestor A', 'key_results' => [
            ['kpi_id' => $kpi->id, 'description' => 'KR A', 'baseline_value' => 100000, 'target_value' => 130000, 'weight' => 100],
        ]],
    ]))->assertSessionHasNoErrors();
    $parent = OkrObjective::query()->where('scope_type', 'branch')->firstOrFail();

    $this->actingAs($s['admin'])->post(route('okr.activate', $parent), ['include_children' => true])->assertSessionHasNoErrors();

    $child = $parent->children()->firstOrFail();
    expect($parent->fresh()->lifecycle_status)->toBe(OkrObjective::STATUS_ACTIVE);
    expect($child->lifecycle_status)->toBe(OkrObjective::STATUS_ACTIVE);
    $kr = $child->keyResults()->firstOrFail();
    expect((float) $kr->baseline_value)->toBe(100000.0);
    expect($kr->baseline_locked_at)->not->toBeNull();
});

it('generates distinct commitment letters for the branch, gestor A and gestor B — each with its own goal', function () {
    $renderer = Mockery::mock(BrowsershotPdfRenderer::class);
    $renderer->shouldReceive('renderViewToFile')->andReturnUsing(function (string $view, array $data, string $outputPath) {
        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, '%PDF-fake');
    });
    app()->instance(BrowsershotPdfRenderer::class, $renderer);

    $s = bgSetup();
    $kpi = bgManualKpi();
    $this->actingAs($s['admin'])->post(route('okr.store'), bgPayload($s, [
        ['employee_id' => $s['a']->id, 'title' => 'Colocación individual del gestor A', 'key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR A', 'baseline_value' => 100000, 'target_value' => 130000, 'weight' => 100]]],
        ['employee_id' => $s['b']->id, 'title' => 'Colocación individual del gestor B', 'key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR B', 'baseline_value' => 180000, 'target_value' => 230000, 'weight' => 100]]],
    ]))->assertSessionHasNoErrors();
    $parent = OkrObjective::query()->where('scope_type', 'branch')->firstOrFail();
    $this->actingAs($s['admin'])->post(route('okr.activate', $parent), ['include_children' => true]);

    $fields = ['position' => 'Gestor', 'immediate_boss' => 'Gerente de Atlixco'];
    // Titular real del OKR de sucursal (15-oct-2026) — nunca el usuario que lo creó.
    $this->actingAs($s['admin'])->putJson(route('okr.accountable.update', $parent), ['employee_id' => $s['c']->id])->assertOk();
    $targets = [];
    foreach ([$parent, ...$parent->children()->get()] as $objective) {
        $id = $this->actingAs($s['admin'])->postJson(route('okr.commitment-letter.generate', $objective), $fields)->assertOk()->json('letter.id');
        $targets[$objective->id] = (float) \App\Models\OkrCommitmentLetter::query()->findOrFail($id)->snapshot['key_results'][0]['target_value'];
    }

    expect(array_values($targets))->toBe([1000000.0, 130000.0, 230000.0]);
    $names = \App\Models\OkrCommitmentLetter::query()->orderBy('id')->get()->map(fn ($l) => $l->snapshot['collaborator_name'])->all();
    expect($names)->toBe(['GESTOR C ATLIXCO', 'GESTOR A ATLIXCO', 'GESTOR B ATLIXCO']);
    expect($names)->not->toContain($s['admin']->name);
});

it('15-oct — an INTENTIONAL incomplete draft keeps the pending gestor flagged (no KR), and "Editar metas" replaces a draft KR with audit', function () {
    $s = bgSetup();
    $kpi = bgManualKpi();

    // Sin save_incomplete, un gestor sin KR se rechaza.
    $this->actingAs($s['admin'])->post(route('okr.store'), bgPayload($s, [
        ['employee_id' => $s['a']->id, 'title' => 'Colocación individual del gestor A', 'key_results' => []],
    ]))->assertSessionHasErrors('individual_objectives.0.key_results');

    // Con save_incomplete (decisión explícita), se guarda y queda pendiente.
    $this->actingAs($s['admin'])->post(route('okr.store'), bgPayload($s, [
        ['employee_id' => $s['a']->id, 'title' => 'Colocación individual del gestor A', 'key_results' => []],
        ['employee_id' => $s['b']->id, 'title' => 'Colocación individual del gestor B', 'key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR B', 'baseline_value' => 180000, 'target_value' => 230000, 'weight' => 100]]],
    ]) + ['save_incomplete' => true])->assertSessionHasNoErrors();

    $parent = OkrObjective::query()->where('scope_type', 'branch')->firstOrFail();
    $childA = $parent->children()->where('employee_id', $s['a']->id)->firstOrFail();
    $childB = $parent->children()->where('employee_id', $s['b']->id)->firstOrFail();
    expect($childA->keyResults()->count())->toBe(0);
    $check = $this->actingAs($s['admin'])->getJson(route('okr.activation-check', $parent))->json('children');
    expect(collect($check)->firstWhere('id', $childA->id)['ready'])->toBeFalse();
    expect(collect($check)->firstWhere('id', $childB->id)['ready'])->toBeTrue();

    // "Editar metas" del gestor B en borrador.
    $this->actingAs($s['admin'])->postJson(route('okr.key-results.complete', $childB), ['key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR B corregido', 'baseline_value' => 180000, 'target_value' => 250000, 'weight' => 100]]])->assertStatus(422);
    $this->actingAs($s['admin'])->postJson(route('okr.key-results.complete', $childB), ['replace' => true, 'key_results' => [['kpi_id' => $kpi->id, 'description' => 'KR B corregido', 'baseline_value' => 180000, 'target_value' => 250000, 'weight' => 100]]])->assertOk();
    expect($childB->keyResults()->count())->toBe(1);
    expect((float) $childB->keyResults()->first()->target_value)->toBe(250000.0);
    expect(\App\Models\OkrAuditLog::query()->where('action', 'key_result_removed')->exists())->toBeTrue();
});

it('15-oct — a Lendus ROUTE (ATLIX-GTE) belongs to ATLIXCO; an unknown route is never attributed; Oficina = Sucursal', function () {
    $s = bgSetup();
    $kpi = bgPlacementKpi();
    $start = now()->subWeek()->startOfDay();
    $parent = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $s['branch']->id, 'title' => 'Colocación Atlixco', 'responsible_user_id' => $s['admin']->id, 'created_by' => $s['admin']->id,
        'start_date' => $start, 'end_date' => $start->copy()->addWeeks(4)->subDay(), 'duration_weeks' => 4, 'lifecycle_status' => OkrObjective::STATUS_ACTIVE,
    ]);
    $parent->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'Colocación', 'baseline_value' => 0, 'target_value' => 1, 'weight' => 100]);

    $byRoute = app(OkrPlacementImportService::class)->import($parent, 1, bgXlsx([
        ['GESTOR A ATLIXCO', 10000, 'ATLIX-GTE'], ['GESTOR B ATLIXCO', 20000, 'ATLIX-NOR'], ['GESTOR C ATLIXCO', 5000, 'RUTA DESCONOCIDA'], ['GESTOR DE TLAXCALA', 9000, 'TLAXCALA'],
    ], ['Gestor', 'Monto', 'Ruta']), $s['admin']);
    expect((float) $byRoute->total_amount)->toBe(30000.0);
    expect($byRoute->rows_other_branch)->toBe(2);

    $byOffice = app(OkrPlacementImportService::class)->import($parent, 2, bgXlsx([
        ['GESTOR A ATLIXCO', 7000, 'ATLIXCO'], ['GESTOR DE TLAXCALA', 9000, 'TLAXCALA'],
    ], ['Gestor', 'Monto', 'Oficina']), $s['admin']);
    expect((float) $byOffice->total_amount)->toBe(7000.0);
    \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory('okr-placement-uploads/' . $parent->id);
});
