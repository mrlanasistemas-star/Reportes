<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\Period;
use App\Models\PeriodEmployeeRoster;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\Okr\OkrBaselineGuard;
use App\Services\Okr\OkrKpiValueResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Prioridad 6 — flujo ATLIXCO completo: objetivo general (base automática de
 * Reportería, congelada al activar) + David Gamboa Cano (CHOL) y Rafael Pérez
 * Chao (TOCH) con bases MANUALES distintas. Nunca se copia la base de la
 * sucursal a un gestor ni se consulta Reportería para un gestor.
 */
function axPeriod(): Period
{
    $p = Period::query()->create(['name' => 'Septiembre 2026', 'code' => 'AX-'.uniqid(), 'type' => 'monthly', 'year' => 2026, 'month' => 9, 'sequence' => 1,
        'start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDays(5)->toDateString()]);
    // Radiografía generada: periodo del que Reportería entrega la base general.
    PeriodSummary::query()->create(['period_id' => $p->id, 'status' => 'generated']);

    return $p;
}

function axEmployee(string $name, Period $p, Branch $b): Employee
{
    $e = Employee::query()->create(['full_name' => $name, 'normalized_name' => mb_strtolower($name), 'is_active' => true]);
    PeriodEmployeeRoster::query()->create([
        'period_id' => $p->id, 'employee_id' => $e->id, 'employee_key' => 'ax'.$e->id, 'nombre_normalizado' => $e->normalized_name,
        'branch_id' => $b->id, 'branch_name' => $b->name, 'is_active_for_period' => true, 'movement_type' => 'activo',
    ]);

    return $e;
}

function axKr(OkrKpi $kpi, ?float $base, float $target): array
{
    return ['kpi_id' => $kpi->id, 'description' => 'Colocación de la ruta', 'baseline_value' => $base, 'target_value' => $target, 'weight' => 100];
}

beforeEach(function () {
    $this->period = axPeriod();
    $this->atlixco = Branch::query()->create(['code' => 'ATLX', 'name' => 'ATLIXCO', 'normalized_name' => 'atlixco', 'is_active' => true]);
    $this->david = axEmployee('DAVID GAMBOA CANO', $this->period, $this->atlixco);
    $this->rafael = axEmployee('RAFAEL PEREZ CHAO', $this->period, $this->atlixco);
    $this->kpi = OkrKpi::query()->create(['code' => 'ax_placement', 'name' => 'Colocación', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE,
        'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_AUTOMATIC, 'provider_key' => 'reporteria.placement', 'is_active' => true]);
    $this->admin = User::factory()->create(['role' => 'admin']);

    // Reportería: solo existe dato para la SUCURSAL. El avance semanal (OkrSnapshotService)
    // sí consulta al gestor; una consulta de LÍNEA BASE por gestor sería un error.
    $this->consultasGestor = 0;
    $this->mock(OkrKpiValueResolver::class, function ($m) {
        $m->shouldReceive('getValue')->andReturnUsing(function ($kpi, $scope) {
            $deSeguimiento = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20))
                ->contains(fn ($f) => ($f['class'] ?? null) === \App\Services\Okr\OkrSnapshotService::class);
            if ($scope !== OkrObjective::SCOPE_BRANCH && ! $deSeguimiento) {
                $this->consultasGestor++;
            }

            return $scope === OkrObjective::SCOPE_BRANCH ? 638576.0 : null;
        });
    });
});

it('crea general + David + Rafael con bases manuales distintas, activa los tres y congela cada base sin copiar la de la sucursal', function () {
    $payload = [
        'scope_type' => OkrObjective::SCOPE_BRANCH, 'branch_id' => $this->atlixco->id, 'title' => 'Crecer la colocación de Atlixco',
        'start_date' => now()->toDateString(), 'duration_weeks' => 8,
        'key_results' => [axKr($this->kpi, null, 838576.0)],
        'individual_objectives' => [
            ['employee_id' => $this->david->id, 'title' => 'Colocación de David en CHOL', 'key_results' => [axKr($this->kpi, 120000.0, 150000.0)]],
            ['employee_id' => $this->rafael->id, 'title' => 'Colocación de Rafael en TOCH', 'key_results' => [axKr($this->kpi, 95000.0, 120000.0)]],
        ],
    ];
    $this->actingAs($this->admin)->post(route('okr.store'), $payload)->assertSessionHasNoErrors();

    $general = OkrObjective::query()->where('scope_type', OkrObjective::SCOPE_BRANCH)->sole();
    $david = OkrObjective::query()->where('employee_id', $this->david->id)->sole();
    $rafael = OkrObjective::query()->where('employee_id', $this->rafael->id)->sole();

    // Revisión previa: los tres listos, sin objetivos individuales vacíos.
    $check = $this->actingAs($this->admin)->getJson(route('okr.activation-check', $general))->assertOk()->json();
    expect($check['objective']['ready'])->toBeTrue()
        ->and(collect($check['children'])->pluck('ready')->all())->toBe([true, true])
        ->and($david->keyResults()->count())->toBe(1)->and($rafael->keyResults()->count())->toBe(1);

    $this->actingAs($this->admin)->post(route('okr.activate', $general), ['include_children' => true])->assertSessionHasNoErrors();

    $krG = $general->keyResults()->sole();
    $krD = $david->keyResults()->sole();
    $krR = $rafael->keyResults()->sole();
    expect([$general->fresh()->lifecycle_status, $david->fresh()->lifecycle_status, $rafael->fresh()->lifecycle_status])->toBe(['active', 'active', 'active'])
        ->and((float) $krG->baseline_value)->toBe(638576.0)->and((float) $krG->target_value)->toBe(838576.0)
        ->and($krG->baseline_source)->toBe('reporteria.placement')
        ->and((float) $krD->baseline_value)->toBe(120000.0)->and($krD->baseline_source)->toBe(OkrBaselineGuard::SOURCE_MANUAL_INDIVIDUAL)
        ->and((float) $krR->baseline_value)->toBe(95000.0)->and($krR->baseline_source)->toBe(OkrBaselineGuard::SOURCE_MANUAL_INDIVIDUAL)
        ->and($krG->baseline_locked_at)->not->toBeNull()->and($krD->baseline_locked_at)->not->toBeNull()->and($krR->baseline_locked_at)->not->toBeNull()
        ->and($this->consultasGestor)->toBe(0);

    // Segunda activación: no vuelve a congelar ni cambia la base.
    $this->actingAs($this->admin)->post(route('okr.activate', $general))->assertSessionHasErrors('activate');
    expect((float) $general->keyResults()->sole()->baseline_value)->toBe(638576.0);
});

it('no crea el OKR individual de David sin su línea base: el asistente lo rechaza antes de guardar', function () {
    $payload = [
        'scope_type' => OkrObjective::SCOPE_BRANCH, 'branch_id' => $this->atlixco->id, 'title' => 'Crecer la colocación de Atlixco',
        'start_date' => now()->toDateString(), 'duration_weeks' => 8, 'save_incomplete' => true,
        'key_results' => [axKr($this->kpi, null, 838576.0)],
        'individual_objectives' => [
            ['employee_id' => $this->david->id, 'title' => 'Colocación de David en CHOL', 'key_results' => [axKr($this->kpi, null, 150000.0)]],
        ],
    ];
    $this->actingAs($this->admin)->post(route('okr.store'), $payload)->assertSessionHasErrors('individual_objectives.0.key_results.0.baseline_value');
    expect(OkrObjective::query()->count())->toBe(0);
});

it('borrador heredado de David sin base: la sucursal y Rafael se activan y David queda en borrador con el motivo exacto', function () {
    $mk = fn (array $o) => OkrObjective::query()->create($o + ['branch_id' => $this->atlixco->id, 'responsible_user_id' => $this->admin->id, 'created_by' => $this->admin->id,
        'start_date' => now(), 'end_date' => now()->addWeeks(8)->subDay(), 'duration_weeks' => 8, 'lifecycle_status' => OkrObjective::STATUS_DRAFT]);
    $general = $mk(['scope_type' => OkrObjective::SCOPE_BRANCH, 'title' => 'Crecer la colocación de Atlixco']);
    $general->keyResults()->create(['kpi_id' => $this->kpi->id, 'description' => 'Colocación', 'target_value' => 838576, 'weight' => 100]);
    $david = $mk(['scope_type' => OkrObjective::SCOPE_EMPLOYEE, 'parent_id' => $general->id, 'employee_id' => $this->david->id, 'title' => 'Colocación de David en CHOL']);
    $david->keyResults()->create(['kpi_id' => $this->kpi->id, 'description' => 'Colocación', 'target_value' => 150000, 'weight' => 100]);
    $rafael = $mk(['scope_type' => OkrObjective::SCOPE_EMPLOYEE, 'parent_id' => $general->id, 'employee_id' => $this->rafael->id, 'title' => 'Colocación de Rafael en TOCH']);
    $rafael->keyResults()->create(['kpi_id' => $this->kpi->id, 'description' => 'Colocación', 'baseline_value' => 95000, 'target_value' => 120000, 'weight' => 100]);

    $davidCheck = collect($this->actingAs($this->admin)->getJson(route('okr.activation-check', $general))->json('children'))->firstWhere('id', $david->id);
    expect($davidCheck['ready'])->toBeFalse()
        ->and($davidCheck['items'][0]['message'])->toStartWith('Falta capturar la línea base de David Gamboa Cano')
        ->and($davidCheck['items'][0]['action_label'])->toBe('Completar línea base');

    $this->actingAs($this->admin)->post(route('okr.activate', $general), ['include_children' => true])
        ->assertSessionHas('warning', fn ($w) => str_contains($w, 'David Gamboa Cano') && str_contains($w, 'falta capturar su línea base'));
    expect($general->fresh()->lifecycle_status)->toBe('active')
        ->and($david->fresh()->lifecycle_status)->toBe('draft')
        ->and($david->keyResults()->sole()->baseline_locked_at)->toBeNull()
        ->and($david->keyResults()->sole()->baseline_value)->toBeNull()
        ->and($rafael->fresh()->lifecycle_status)->toBe('active')
        ->and((float) $rafael->keyResults()->sole()->baseline_value)->toBe(95000.0)
        ->and($this->consultasGestor)->toBe(0);
});
