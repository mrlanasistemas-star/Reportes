<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\Period;
use App\Models\PeriodEmployeeRoster;
use App\Models\User;
use App\Services\BranchResolverService;
use App\Services\Catalog\BranchRouteCatalog;
use App\Services\Okr\OkrBaselineGuard;
use App\Services\Okr\OkrEmployeeBranchResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Corrección definitiva OKR (16-oct-2026): catálogo oficial ruta → sucursal,
 * pertenencia vigente por número de empleado y periodo, línea base MANUAL por
 * gestor, elegibilidad sin vacantes/inactivos y Warnings explicados.
 */
function rcPeriod(string $name, string $start, string $end): Period
{
    return Period::query()->create(['name' => $name, 'code' => 'RC-' . uniqid('', true), 'type' => 'monthly', 'year' => 2026, 'month' => (int) substr($end, 5, 2), 'sequence' => 1, 'start_date' => $start, 'end_date' => $end]);
}

function rcBranch(string $name): Branch
{
    return Branch::query()->firstOrCreate(['normalized_name' => mb_strtolower($name)], ['code' => strtoupper(substr(preg_replace('/\W/', '', $name), 0, 8)) . rand(10, 99), 'name' => $name, 'is_active' => true]);
}

function rcEmployee(string $name): Employee
{
    return Employee::query()->create(['full_name' => $name, 'normalized_name' => mb_strtolower($name), 'is_active' => true]);
}

function rcRoster(Period $period, Employee $e, Branch $b, bool $active = true): void
{
    PeriodEmployeeRoster::query()->create([
        'period_id' => $period->id, 'employee_id' => $e->id, 'employee_key' => 'k' . $e->id . $period->id, 'nombre_normalizado' => $e->normalized_name,
        'branch_id' => $b->id, 'branch_name' => $b->name, 'is_active_for_period' => $active, 'movement_type' => $active ? 'activo' : 'baja',
    ]);
}

function rcDirectory(Period $period, array $rows): void
{
    $sourceId = DB::table('data_sources')->insertGetId(['code' => 'lendus_dir_' . uniqid(), 'name' => 'Directorio Lendus', 'created_at' => now(), 'updated_at' => now()]);
    $uploadId = DB::table('report_uploads')->insertGetId([
        'period_id' => $period->id, 'data_source_id' => $sourceId, 'uploaded_by' => User::factory()->create()->id, 'original_name' => 'dir.xlsx',
        'stored_path' => 'x', 'status' => 'processed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($rows as [$codigo, $nombre, $estatus, $branchId]) {
        DB::table('lendus_employee_directory')->insert([
            'report_upload_id' => $uploadId, 'codigo' => $codigo, 'nombre' => $nombre, 'normalized_name' => mb_strtolower($nombre),
            'puesto' => 'GESTOR DE CRÉDITO', 'estatus' => $estatus, 'inferred_branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

function rcPortfolio(Period $period, string $promoterCode, string $route, ?Branch $branch, int $rows = 3): void
{
    for ($i = 0; $i < $rows; $i++) {
        DB::table('fact_portfolios')->insert([
            'period_id' => $period->id, 'branch_id' => $branch?->id, 'promoter_code' => $promoterCode, 'route_name' => $route,
            'client_name' => "CLIENTE {$i}", 'balance' => 1000, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

function rcKpi(): OkrKpi
{
    return OkrKpi::query()->firstOrCreate(['code' => 'rc_placement'], [
        'name' => 'Colocación', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE, 'direction' => OkrKpi::DIRECTION_INCREASE,
        'automation' => OkrKpi::AUTOMATION_AUTOMATIC, 'provider_key' => 'reporteria.placement', 'is_active' => true,
    ]);
}

it('maps every official route to its branch, flags inactive routes and never guesses unknown ones', function () {
    expect(BranchRouteCatalog::branchForRoute('CHOL'))->toBe('ATLIXCO')
        ->and(BranchRouteCatalog::branchForRoute('toch'))->toBe('ATLIXCO')
        ->and(BranchRouteCatalog::branchForRoute('TLAX'))->toBe('ATLIXCO')
        ->and(BranchRouteCatalog::branchForRoute('APIZ-CEN'))->toBe('HUAMANTLA')
        ->and(BranchRouteCatalog::branchForRoute('APIZ CEN'))->toBe('HUAMANTLA')
        ->and(BranchRouteCatalog::branchForRoute('CENTRO C'))->toBe('ORIZABA')
        ->and(BranchRouteCatalog::branchForRoute('Fortín'))->toBe('CORDOBA')
        ->and(BranchRouteCatalog::branchForRoute('XOXOCOTLA  - JOJUTLA'))->toBe('MIACATLAN')
        ->and(BranchRouteCatalog::branchForRoute('IXT-SANTA ANA'))->toBe('IXTLAHUACA')
        ->and(BranchRouteCatalog::branchForRoute('Muñoz'))->toBe('SAN LUIS POTOSI')
        ->and(BranchRouteCatalog::branchForRoute('SAN BARTOLO MORELOS'))->toBe('ATLACOMULCO')
        ->and(BranchRouteCatalog::branchForRoute('VILLA ALTA'))->toBe('TLAXCALA')
        ->and(BranchRouteCatalog::branchForRoute('RUTA INVENTADA'))->toBeNull();

    expect(BranchRouteCatalog::isInactiveRoute('HUAM-CEN (INACTIVA)'))->toBeTrue()
        ->and(BranchRouteCatalog::isInactiveRoute('ZACATEPEC'))->toBeTrue()
        ->and(BranchRouteCatalog::isInactiveRoute('METEPEC-1'))->toBeTrue()
        ->and(BranchRouteCatalog::isInactiveRoute('CHOL'))->toBeFalse();

    expect(BranchRouteCatalog::OKR_BRANCHES)->toHaveCount(11)
        ->and(BranchRouteCatalog::isOkrOperative('Córdoba'))->toBeTrue()
        ->and(BranchRouteCatalog::isOkrOperative('SAN LUIS POTOSÍ'))->toBeTrue()
        ->and(BranchRouteCatalog::isOkrOperative('TENANGO DEL VALLE'))->toBeFalse()
        ->and(BranchRouteCatalog::isOkrOperative('AGUASCALIENTES'))->toBeFalse()
        ->and(BranchRouteCatalog::isOkrOperative('MATRIZ'))->toBeFalse();

    // El resolver global de Reportería usa el MISMO catálogo (sin versiones contradictorias).
    $resolver = app(BranchResolverService::class);
    expect($resolver->resolveRealBranchFromRoute('APIZ-CEN'))->toBe('HUAMANTLA')
        ->and($resolver->resolveRealBranchFromRoute('CENTRO B'))->toBe('ORIZABA')
        ->and($resolver->resolveRealBranchFromRoute('PNT-IXTLA'))->toBe('MIACATLAN')
        ->and($resolver->resolveRealBranchFromRoute('SANTIAGO'))->toBe('TENANGO DEL VALLE')
        ->and($resolver->resolveRealBranchFromRoute('SJR II'))->toBe('SAN JUAN DEL RÍO');
});

it('resolves DAVID (CHOL) and RAFAEL (TOCH) to ATLIXCO by employee number even when their latest assignment points to the ROUTE record', function () {
    $atlixco = rcBranch('ATLIXCO');
    $cholRow = rcBranch('CHOL');   // rutas registradas como filas de branches (dato real)
    $tochRow = rcBranch('TOCH');
    $junio = rcPeriod('Junio 2026', '2026-05-25', '2026-06-21');

    $david = rcEmployee('DAVID GAMBOA CANO');
    $rafael = rcEmployee('RAFAEL PEREZ CHAO');
    EmployeeBranchAssignment::query()->create(['period_id' => $junio->id, 'employee_id' => $david->id, 'branch_id' => $cholRow->id]);
    EmployeeBranchAssignment::query()->create(['period_id' => $junio->id, 'employee_id' => $rafael->id, 'branch_id' => $tochRow->id]);
    rcDirectory($junio, [['ATLX12513', 'DAVID GAMBOA CANO', 'ACTIVO', $atlixco->id], ['ATLX04819', 'RAFAEL PEREZ CHAO', 'ACTIVO', $atlixco->id]]);
    rcPortfolio($junio, 'ATLX12513', 'CHOL', $atlixco);
    rcPortfolio($junio, 'ATLX04819', 'TOCH', $atlixco);

    $resolver = app(OkrEmployeeBranchResolver::class);
    expect($resolver->employeeBelongsToBranch($david->id, $atlixco->id))->toBeTrue()
        ->and($resolver->employeeBelongsToBranch($rafael->id, $atlixco->id))->toBeTrue()
        ->and($resolver->membershipFor($david->id)['route'])->toBe('CHOL')
        ->and($resolver->membershipFor($rafael->id)['route'])->toBe('TOCH');

    expect($resolver->employeesForBranch($atlixco->id)->pluck('id')->all())->toEqualCanonicalizing([$david->id, $rafael->id]);
});

it('lets David and Rafael be assigned with DIFFERENT manual baselines and goals, and activates them freezing those baselines', function () {
    $atlixco = rcBranch('ATLIXCO');
    $junio = rcPeriod('Junio 2026', '2026-05-25', '2026-06-21');
    $david = rcEmployee('DAVID GAMBOA CANO');
    $rafael = rcEmployee('RAFAEL PEREZ CHAO');
    rcDirectory($junio, [['ATLX12513', 'DAVID GAMBOA CANO', 'ACTIVO', $atlixco->id], ['ATLX04819', 'RAFAEL PEREZ CHAO', 'ACTIVO', $atlixco->id]]);
    rcPortfolio($junio, 'ATLX12513', 'CHOL', $atlixco);
    rcPortfolio($junio, 'ATLX04819', 'TOCH', $atlixco);
    rcRoster($junio, $david, $atlixco);
    rcRoster($junio, $rafael, $atlixco);
    $kpi = rcKpi();
    $admin = User::factory()->create(['role' => 'admin']);

    $kr = fn (float $base, float $target) => [['kpi_id' => $kpi->id, 'description' => 'Colocación individual', 'baseline_value' => $base, 'target_value' => $target, 'weight' => 100]];
    $this->actingAs($admin)->post(route('okr.store'), [
        'scope_type' => 'branch', 'branch_id' => $atlixco->id, 'title' => 'Incrementar colocación Atlixco',
        'start_date' => now()->toDateString(), 'duration_weeks' => 8,
        'key_results' => [['kpi_id' => $kpi->id, 'description' => 'Colocación sucursal', 'target_value' => 838576, 'weight' => 100]],
        'individual_objectives' => [
            ['employee_id' => $david->id, 'title' => 'Colocación individual de David', 'key_results' => $kr(100000, 130000)],
            ['employee_id' => $rafael->id, 'title' => 'Colocación individual de Rafael', 'key_results' => $kr(180000, 230000)],
        ],
    ])->assertSessionHasNoErrors();

    $davidOkr = OkrObjective::query()->where('employee_id', $david->id)->firstOrFail();
    $rafaelOkr = OkrObjective::query()->where('employee_id', $rafael->id)->firstOrFail();

    foreach ([$davidOkr, $rafaelOkr] as $okr) {
        $this->actingAs($admin)->post(route('okr.activate', $okr))->assertSessionHasNoErrors();
    }

    $davidKr = $davidOkr->fresh()->keyResults()->firstOrFail();
    $rafaelKr = $rafaelOkr->fresh()->keyResults()->firstOrFail();
    expect($davidOkr->fresh()->lifecycle_status)->toBe(OkrObjective::STATUS_ACTIVE)
        ->and((float) $davidKr->baseline_value)->toBe(100000.0)->and((float) $davidKr->target_value)->toBe(130000.0)
        ->and((float) $rafaelKr->baseline_value)->toBe(180000.0)->and((float) $rafaelKr->target_value)->toBe(230000.0)
        ->and($davidKr->baseline_locked_at)->not->toBeNull()
        ->and($davidKr->baseline_source)->toBe(OkrBaselineGuard::SOURCE_MANUAL_INDIVIDUAL);

    // Congelada: un borrador ya no existe, "Configurar KR" se rechaza.
    $this->actingAs($admin)->postJson(route('okr.key-results.complete', $davidOkr), ['key_results' => $kr(1, 2), 'replace' => true])->assertStatus(422);
});

it('reports the exact missing field when an individual OKR has no manual baseline, instead of an automatic lookup failure', function () {
    $atlixco = rcBranch('ATLIXCO');
    $junio = rcPeriod('Junio 2026', '2026-05-25', '2026-06-21');
    $david = rcEmployee('DAVID GAMBOA CANO');
    rcRoster($junio, $david, $atlixco);
    $kpi = rcKpi();
    $admin = User::factory()->create(['role' => 'admin']);

    $okr = OkrObjective::query()->create([
        'scope_type' => 'employee', 'branch_id' => $atlixco->id, 'employee_id' => $david->id, 'title' => 'OKR heredado sin base',
        'responsible_user_id' => $admin->id, 'created_by' => $admin->id, 'start_date' => now(), 'end_date' => now()->addWeeks(8)->subDay(),
        'duration_weeks' => 8, 'lifecycle_status' => OkrObjective::STATUS_DRAFT,
    ]);
    $okr->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'Colocación', 'target_value' => 1000, 'weight' => 100]);

    $check = $this->actingAs($admin)->getJson(route('okr.activation-check', $okr))->assertOk()->json('objective');
    expect($check['ready'])->toBeFalse()
        ->and(implode(' ', $check['issues']))->toContain('captura la línea base del gestor')
        ->and(implode(' ', $check['issues']))->not->toContain('automática');

    $this->actingAs($admin)->post(route('okr.activate', $okr))->assertSessionHasErrors('activate');
    expect($okr->fresh()->lifecycle_status)->toBe(OkrObjective::STATUS_DRAFT);
});

it('follows the most recent PERIOD when a collaborator changes branch, without rewriting older periods', function () {
    $atlixco = rcBranch('ATLIXCO');
    $tula = rcBranch('TULA');
    // Junio se crea ANTES (id menor) que Mayo — como en la BD real: manda la FECHA, nunca el id.
    $junio = rcPeriod('Junio 2026', '2026-05-25', '2026-06-21');
    $mayo = rcPeriod('Mayo 2026', '2026-04-27', '2026-05-24');
    $e = rcEmployee('GESTOR TRANSFERIDO');

    EmployeeBranchAssignment::query()->create(['period_id' => $mayo->id, 'employee_id' => $e->id, 'branch_id' => $atlixco->id]);
    rcRoster($mayo, $e, $atlixco);
    rcRoster($junio, $e, $tula);

    $resolver = app(OkrEmployeeBranchResolver::class);
    expect($resolver->employeeBelongsToBranch($e->id, $tula->id))->toBeTrue()
        ->and($resolver->employeeBelongsToBranch($e->id, $atlixco->id))->toBeFalse();
    expect(EmployeeBranchAssignment::query()->where('employee_id', $e->id)->value('branch_id'))->toBe($atlixco->id); // histórico intacto
});

it('never uses an INACTIVE route as evidence for a new assignment and flags it', function () {
    $huamantla = rcBranch('HUAMANTLA');
    $tlaxcala = rcBranch('TLAXCALA');
    $junio = rcPeriod('Junio 2026', '2026-05-25', '2026-06-21');
    $e = rcEmployee('GESTOR RUTA INACTIVA');
    rcDirectory($junio, [['HUA99999', 'GESTOR RUTA INACTIVA', 'ACTIVO', null]]);
    rcPortfolio($junio, 'HUA99999', 'HUAM-CEN', $huamantla);
    rcRoster($junio, $e, $tlaxcala);

    $resolver = app(OkrEmployeeBranchResolver::class);
    $m = $resolver->membershipFor($e->id);
    expect($m['inactive_route'])->toBe('HUAM-CEN')
        ->and($m['branch_id'])->toBe($tlaxcala->id); // la ruta inactiva no respalda pertenencia
    expect(collect($resolver->statusFlags([$e->id])[$e->id])->pluck('code')->all())->toContain('inactive_route');
});

it('excludes vacancies, explicit INACTIVO and confirmed bajas, but keeps (flagged) an ACTIVO collaborator missing from the NOI roster', function () {
    $atlixco = rcBranch('ATLIXCO');
    $junio = rcPeriod('Junio 2026', '2026-05-25', '2026-06-21');
    $producing = rcEmployee('GESTOR PRODUCTIVO');
    $noNoi = rcEmployee('GESTOR SIN NOMINA');
    $inactive = rcEmployee('GESTOR INACTIVO');
    $baja = rcEmployee('GESTOR BAJA CONFIRMADA');
    $vacante = rcEmployee('VACANTE VOLANTE ATLIXCO');
    foreach ([$producing, $noNoi, $inactive, $baja, $vacante] as $e) {
        EmployeeBranchAssignment::query()->create(['period_id' => $junio->id, 'employee_id' => $e->id, 'branch_id' => $atlixco->id]);
    }
    rcRoster($junio, $producing, $atlixco);
    rcRoster($junio, $baja, $atlixco, active: false);
    rcDirectory($junio, [
        ['ATLX1', 'GESTOR PRODUCTIVO', 'ACTIVO', $atlixco->id], ['ATLX2', 'GESTOR SIN NOMINA', 'ACTIVO', $atlixco->id],
        ['ATLX3', 'GESTOR INACTIVO', 'INACTIVO', $atlixco->id], ['ATLX4', 'GESTOR BAJA CONFIRMADA', 'ACTIVO', $atlixco->id],
        [null, 'VACANTE VOLANTE ATLIXCO', 'ACTIVO', $atlixco->id],
    ]);
    rcPortfolio($junio, 'ATLX1', 'CHOL', $atlixco);

    $resolver = app(OkrEmployeeBranchResolver::class);
    $ids = $resolver->employeesForBranch($atlixco->id)->pluck('id')->all();
    expect($ids)->toEqualCanonicalizing([$producing->id, $noNoi->id]);

    $flags = $resolver->statusFlags([$producing->id, $noNoi->id]);
    expect($flags[$producing->id])->toBe([])
        ->and(collect($flags[$noNoi->id])->pluck('code')->all())->toContain('no_noi')
        ->and(collect($flags[$noNoi->id])->pluck('code')->all())->toContain('no_activity');
});

it('rejects a new OKR on a non-operative branch (TENANGO, AGUASCALIENTES)', function () {
    $tenango = rcBranch('TENANGO DEL VALLE');
    $kpi = rcKpi();
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post(route('okr.store'), [
        'scope_type' => 'branch', 'branch_id' => $tenango->id, 'title' => 'Colocación Tenango del Valle',
        'start_date' => now()->toDateString(), 'duration_weeks' => 8,
        'key_results' => [['kpi_id' => $kpi->id, 'description' => 'Colocación sucursal', 'target_value' => 1000, 'weight' => 100]],
    ])->assertSessionHasErrors('branch_id');
});

it('opens the Warning panel for a draft OKR with an explained notice instead of an error', function () {
    $atlixco = rcBranch('ATLIXCO');
    $admin = User::factory()->create(['role' => 'admin']);
    $okr = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $atlixco->id, 'title' => 'OKR en borrador', 'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->subWeeks(3), 'end_date' => now()->addWeeks(5), 'duration_weeks' => 8, 'lifecycle_status' => OkrObjective::STATUS_DRAFT,
    ]);

    $this->actingAs($admin)->getJson(route('okr.warnings.eligibility', $okr))
        ->assertOk()
        ->assertJsonPath('notice.code', 'not_active')
        ->assertJsonPath('weeks', []);
});
