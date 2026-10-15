<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\OkrPlacementUpload;
use App\Models\User;
use App\Services\Okr\OkrWeeklyPlacementResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/**
 * 4.6 (19-oct-2026) — reporte semanal de colocación COMPLETO vs PARCIAL.
 *   - COMPLETO (declarado por quien lo carga): un gestor que no aparece tuvo
 *     $0 esa semana — es un dato real.
 *   - PARCIAL (no declarado completo y sin roster que pruebe cobertura): la
 *     ausencia es DATO FALTANTE; nunca se convierte en cero ni en incumplimiento.
 * Un mismo archivo alimenta sucursal + individuales; un movimiento repetido
 * en la semana siguiente se cuenta una sola vez; una fila sin gestor
 * identificable queda pendiente (suma a la sucursal, nunca a un gestor).
 */
function cpSetup(): array
{
    $branch = Branch::query()->create(['code' => 'ATX', 'name' => 'ATLIXCO', 'normalized_name' => 'atlixco', 'is_active' => true]);
    $admin = User::factory()->create(['role' => 'admin']);
    $david = Employee::query()->create(['full_name' => 'DAVID GAMBOA CANO', 'normalized_name' => 'david gamboa cano', 'is_active' => true]);
    $rafael = Employee::query()->create(['full_name' => 'RAFAEL PEREZ CHAO', 'normalized_name' => 'rafael perez chao', 'is_active' => true]);
    $kpi = OkrKpi::query()->create([
        'name' => 'Colocación', 'code' => 'cp_placement', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE, 'direction' => OkrKpi::DIRECTION_INCREASE,
        'automation' => OkrKpi::AUTOMATION_AUTOMATIC, 'provider_key' => 'reporteria.placement', 'is_active' => true,
    ]);
    $start = now()->subWeek()->startOfDay(); // hoy = semana 2
    $common = ['responsible_user_id' => $admin->id, 'created_by' => $admin->id, 'start_date' => $start, 'end_date' => $start->copy()->addWeeks(4)->subDay(),
        'duration_weeks' => 4, 'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(), 'branch_id' => $branch->id];

    $parent = OkrObjective::query()->create($common + ['scope_type' => 'branch', 'title' => 'Colocación ATLIXCO']);
    $parent->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'Sucursal', 'baseline_value' => 0, 'target_value' => 1_000_000, 'weight' => 100, 'baseline_locked_at' => now()]);
    $children = [];
    foreach (['david' => [$david, 150_000], 'rafael' => [$rafael, 90_000]] as $key => [$employee, $target]) {
        $child = OkrObjective::query()->create($common + ['scope_type' => 'employee', 'parent_id' => $parent->id, 'employee_id' => $employee->id, 'title' => "Colocación {$employee->full_name}"]);
        $child->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'Individual', 'baseline_value' => 0, 'target_value' => $target, 'weight' => 100, 'baseline_locked_at' => now()]);
        $children[$key] = $child;
    }

    return compact('branch', 'admin', 'parent', 'children', 'start');
}

function cpCsv(array $rows): UploadedFile
{
    $lines = ['Contrato,Fecha,Gestor,Monto'];
    foreach ($rows as $r) {
        $lines[] = implode(',', $r);
    }

    return UploadedFile::fake()->createWithContent('lendus-semana.csv', implode("\n", $lines));
}

it('PARTIAL report: an absent gestor stays as MISSING data (never $0), the present one counts, pending rows only feed the branch', function () {
    $s = cpSetup();
    $d1 = $s['start']->copy()->addDay()->toDateString();

    $res = $this->actingAs($s['admin'])->post(route('okr.placement-uploads.store', $s['parent']), [
        'week_number' => 1,
        'file' => cpCsv([['CT-1', $d1, 'DAVID GAMBOA CANO', 50000], ['CT-2', $d1, 'GESTOR DESCONOCIDO XYZ', 7000], ['CT-1', $d1, 'DAVID GAMBOA CANO', 50000]]),
        // sin complete_report → parcial
    ])->assertOk();

    expect($res->json('upload.declared_complete'))->toBeFalse();
    expect($res->json('upload.coverage_status'))->not->toBe(OkrPlacementUpload::COVERAGE_FULL);
    expect((float) $res->json('upload.total_amount'))->toBe(57000.0); // la fila repetida en el MISMO archivo se cuenta una vez
    expect($res->json('upload.rows_pending_attribution'))->toBe(1);

    $resolver = app(OkrWeeklyPlacementResolver::class);
    $david = $resolver->resolve($s['children']['david']->fresh(), 1);
    $rafael = $resolver->resolve($s['children']['rafael']->fresh(), 1);

    expect($david['weekly'])->toBe(50000.0);
    expect($rafael['weekly'])->toBeNull()->and($rafael['missing_weeks'])->toBe([1]); // ausencia ≠ 0
    expect($resolver->resolve($s['parent']->fresh(), 1)['weekly'])->toBe(57000.0);   // sucursal incluye pendiente

    // El KR de Rafael no se evaluó con un cero inventado.
    expect($s['children']['rafael']->fresh()->keyResults()->first()->snapshots()->where('week_number', 1)->value('actual_value'))->toBeNull();
});

it('COMPLETE report: the absent gestor had a real $0, and a movement repeated next week is counted once for branch and gestor', function () {
    $s = cpSetup();
    $d1 = $s['start']->copy()->addDay()->toDateString();
    $d2 = $s['start']->copy()->addDays(8)->toDateString();

    $this->actingAs($s['admin'])->post(route('okr.placement-uploads.store', $s['parent']), [
        'week_number' => 1, 'complete_report' => '1',
        'file' => cpCsv([['CT-1', $d1, 'DAVID GAMBOA CANO', 50000]]),
    ])->assertOk()->assertJsonPath('upload.declared_complete', true)->assertJsonPath('upload.coverage_status', OkrPlacementUpload::COVERAGE_FULL);

    $this->actingAs($s['admin'])->post(route('okr.placement-uploads.store', $s['parent']), [
        'week_number' => 2, 'complete_report' => '1',
        'file' => cpCsv([['CT-1', $d1, 'DAVID GAMBOA CANO', 50000], ['CT-9', $d2, 'RAFAEL PEREZ CHAO', 30000]]),
    ])->assertOk()->assertJsonPath('upload.rows_duplicated', 1);

    $resolver = app(OkrWeeklyPlacementResolver::class);
    $rafael = $resolver->resolve($s['children']['rafael']->fresh(), 2);
    $david = $resolver->resolve($s['children']['david']->fresh(), 2);

    expect($rafael['weeks'])->toBe([0.0, 30000.0])->and($rafael['cumulative'])->toBe(30000.0); // semana 1 = $0 real
    expect($david['weeks'])->toBe([50000.0, 0.0])->and($david['cumulative'])->toBe(50000.0);  // CT-1 una sola vez
    expect($resolver->resolve($s['parent']->fresh(), 2)['cumulative'])->toBe(80000.0);
});

it('replacing a PARTIAL week with the COMPLETE report turns the missing week of the absent gestor into $0 (with confirmation)', function () {
    $s = cpSetup();
    $d1 = $s['start']->copy()->addDay()->toDateString();
    $url = route('okr.placement-uploads.store', $s['parent']);

    $this->actingAs($s['admin'])->post($url, ['week_number' => 1, 'file' => cpCsv([['CT-1', $d1, 'DAVID GAMBOA CANO', 50000]])])->assertOk();
    expect(app(OkrWeeklyPlacementResolver::class)->resolve($s['children']['rafael']->fresh(), 1)['weekly'])->toBeNull();

    // Sin confirmar → conflicto explícito (nunca reemplaza en silencio).
    $this->actingAs($s['admin'])->post($url, ['week_number' => 1, 'complete_report' => '1', 'file' => cpCsv([['CT-1', $d1, 'DAVID GAMBOA CANO', 50000]])])->assertStatus(409);
    $this->actingAs($s['admin'])->post($url, ['week_number' => 1, 'complete_report' => '1', 'confirm_replace' => '1', 'file' => cpCsv([['CT-1', $d1, 'DAVID GAMBOA CANO', 50000]])])
        ->assertOk()->assertJsonPath('replaced', true);

    expect(app(OkrWeeklyPlacementResolver::class)->resolve($s['children']['rafael']->fresh(), 1)['weekly'])->toBe(0.0);
    expect(OkrPlacementUpload::query()->where('okr_objective_id', $s['parent']->id)->where('week_number', 1)->where('status', 'active')->count())->toBe(1);
    expect(OkrPlacementUpload::query()->where('okr_objective_id', $s['parent']->id)->where('week_number', 1)->where('status', 'superseded')->count())->toBe(1);
});

it('the weekly breakdown of a gestor says whether the source report was complete or partial', function () {
    $s = cpSetup();
    $d1 = $s['start']->copy()->addDay()->toDateString();
    $this->actingAs($s['admin'])->post(route('okr.placement-uploads.store', $s['parent']), ['week_number' => 1, 'file' => cpCsv([['CT-1', $d1, 'DAVID GAMBOA CANO', 50000]])])->assertOk();

    $json = $this->actingAs($s['admin'])->getJson(route('okr.placement-weeks.breakdown', [$s['children']['rafael'], 1]))->assertOk();

    expect($json->json('upload.from_branch_file'))->toBeTrue();
    expect($json->json('upload.declared_complete'))->toBeFalse();
    expect($json->json('rows'))->toBe([]); // Rafael no aparece; la UI lo muestra como "pendiente", nunca $0
});
