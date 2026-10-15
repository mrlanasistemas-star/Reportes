<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\OkrBranchReconciliation;
use App\Models\OkrKpi;
use App\Models\Period;
use App\Models\PeriodEmployeeRoster;
use App\Models\User;
use App\Services\Catalog\BranchRouteCatalog;
use App\Services\Okr\OkrEmployeeBranchResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Prioridad 5 del cierre OKR (15-oct-2026) — catálogo oficial único y
 * conflictos de sucursal: dos fuentes IGUALMENTE vigentes que contradicen la
 * sucursal nunca se desempatan en silencio.
 */
function bcPeriod(string $name, string $start, string $end): Period
{
    return Period::query()->create(['name' => $name, 'code' => 'BC-' . uniqid('', true), 'type' => 'monthly', 'year' => 2026, 'month' => (int) substr($end, 5, 2), 'sequence' => 1, 'start_date' => $start, 'end_date' => $end]);
}

function bcBranch(string $name): Branch
{
    return Branch::query()->firstOrCreate(['normalized_name' => mb_strtolower($name)], ['code' => 'BC' . strtoupper(substr(preg_replace('/\W/', '', $name), 0, 6)) . rand(10, 99), 'name' => $name, 'is_active' => true]);
}

function bcEmployee(string $name): Employee
{
    return Employee::query()->create(['full_name' => $name, 'normalized_name' => mb_strtolower($name), 'is_active' => true]);
}

function bcRoster(Period $p, Employee $e, Branch $b): void
{
    PeriodEmployeeRoster::query()->create([
        'period_id' => $p->id, 'employee_id' => $e->id, 'employee_key' => 'k' . $e->id . $p->id, 'nombre_normalizado' => $e->normalized_name,
        'branch_id' => $b->id, 'branch_name' => $b->name, 'is_active_for_period' => true, 'movement_type' => 'activo',
    ]);
}

function bcDirectory(Period $p, array $rows): void
{
    $sourceId = DB::table('data_sources')->insertGetId(['code' => 'bc_dir_' . uniqid(), 'name' => 'Directorio', 'created_at' => now(), 'updated_at' => now()]);
    $uploadId = DB::table('report_uploads')->insertGetId([
        'period_id' => $p->id, 'data_source_id' => $sourceId, 'uploaded_by' => User::factory()->create()->id, 'original_name' => 'dir.xlsx',
        'stored_path' => 'x', 'status' => 'processed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($rows as [$codigo, $nombre, $branchId]) {
        DB::table('lendus_employee_directory')->insert([
            'report_upload_id' => $uploadId, 'codigo' => $codigo, 'nombre' => $nombre, 'normalized_name' => mb_strtolower($nombre),
            'puesto' => 'GESTOR DE CRÉDITO', 'estatus' => 'ACTIVO', 'inferred_branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

function bcPortfolio(Period $p, string $code, string $route, ?Branch $b): void
{
    DB::table('fact_portfolios')->insert([
        'period_id' => $p->id, 'branch_id' => $b?->id, 'promoter_code' => $code, 'route_name' => $route,
        'client_name' => 'CLIENTE', 'balance' => 1000, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('keeps the official catalog as the single source: the requested routes map to their OKR branch', function () {
    expect(BranchRouteCatalog::branchForRoute('CHOL'))->toBe('ATLIXCO')
        ->and(BranchRouteCatalog::branchForRoute('TOCH'))->toBe('ATLIXCO')
        ->and(BranchRouteCatalog::branchForRoute('APIZ-CEN'))->toBe('HUAMANTLA')
        ->and(BranchRouteCatalog::branchForRoute('ACAMBAY'))->toBe('ATLACOMULCO')
        ->and(BranchRouteCatalog::branchForRoute('CENTRO B'))->toBe('ORIZABA')
        ->and(BranchRouteCatalog::branchForRoute('ATOTONILCO'))->toBe('TULA');

    expect(BranchRouteCatalog::OKR_BRANCHES)->toEqualCanonicalizing([
        'ATLACOMULCO', 'IXTLAHUACA', 'TULA', 'CUERNAVACA', 'MIACATLAN', 'ATLIXCO', 'SAN LUIS POTOSI', 'HUAMANTLA', 'TLAXCALA', 'CORDOBA', 'ORIZABA',
    ]);
    foreach (['MATRIZ', 'MATRIZ NORTE', 'REGION Q1', 'AGUASCALIENTES', 'TENANGO DEL VALLE'] as $nonOperative) {
        expect(BranchRouteCatalog::isOkrOperative($nonOperative))->toBeFalse();
    }
    // Históricos conservados: Tenango y las rutas inactivas siguen consultables.
    expect(BranchRouteCatalog::routesFor('TENANGO DEL VALLE'))->not->toBeEmpty()
        ->and(BranchRouteCatalog::routesFor('HUAMANTLA')['HUAM-CEN'])->toBeFalse()
        ->and(BranchRouteCatalog::branchForRoute('HUAM-CEN'))->toBe('HUAMANTLA');
});

it('an INACTIVE route never supports a new assignment but stays visible as history', function () {
    $huamantla = bcBranch('HUAMANTLA');
    $junio = bcPeriod('Junio 2026', '2026-05-25', '2026-06-21');
    $e = bcEmployee('GESTOR RUTA INACTIVA');
    bcDirectory($junio, [['HUAM800', 'GESTOR RUTA INACTIVA', null]]);
    bcPortfolio($junio, 'HUAM800', 'HUAM-CEN', $huamantla);

    $resolver = app(OkrEmployeeBranchResolver::class);
    $m = $resolver->membershipFor($e->id);

    expect($m['branch_id'])->toBeNull()
        ->and($m['inactive_route'])->toBe('HUAM-CEN')
        ->and(collect($resolver->statusFlags([$e->id])[$e->id])->pluck('code')->all())->toContain('inactive_route');
});

it('registers a CONFLICT (never an arbitrary pick) when two sources of the SAME period disagree on the branch', function () {
    $atlixco = bcBranch('ATLIXCO');
    $tula = bcBranch('TULA');
    $junio = bcPeriod('Junio 2026', '2026-05-25', '2026-06-21');
    $e = bcEmployee('GESTOR EN CONFLICTO');
    bcDirectory($junio, [['ATLX777', 'GESTOR EN CONFLICTO', $atlixco->id]]);
    bcPortfolio($junio, 'ATLX777', 'CHOL', $atlixco);   // cartera: ATLIXCO
    bcRoster($junio, $e, $tula);                         // nómina NOI del mismo periodo: TULA

    $resolver = app(OkrEmployeeBranchResolver::class);
    $m = $resolver->membershipFor($e->id);

    expect($m['branch_id'])->toBeNull()
        ->and($m['conflict']['message'])->toBe('Encontramos información diferente sobre la sucursal de este colaborador.')
        ->and($m['conflict']['branch_ids'])->toEqualCanonicalizing([$atlixco->id, $tula->id])
        ->and($m['conflict']['detail'])->toContain('ATLIXCO')->toContain('TULA')
        ->and(collect($m['conflict']['sources'])->pluck('source')->all())->toEqualCanonicalizing(['portfolio_route', 'noi_roster']);

    // No pertenece a ninguna hasta conciliar, pero se muestra en ambas listas.
    expect($resolver->employeeBelongsToBranch($e->id, $atlixco->id))->toBeFalse()
        ->and($resolver->employeeBelongsToBranch($e->id, $tula->id))->toBeFalse()
        ->and($resolver->employeesForBranch($atlixco->id)->pluck('id')->all())->toContain($e->id)
        ->and($resolver->employeesForBranch($tula->id)->pluck('id')->all())->toContain($e->id)
        ->and($resolver->countForBranch($atlixco->id))->toBe(0)
        ->and(collect($resolver->statusFlags([$e->id])[$e->id])->pluck('code')->all())->toContain('branch_conflict');

    // El lookup del asistente expone el conflicto; asignar un OKR se rechaza con el mensaje.
    $admin = User::factory()->create(['role' => 'admin']);
    $lookup = $this->actingAs($admin)->getJson(route('okr.employees.lookup', ['branch_id' => $atlixco->id]))->assertOk();
    $row = collect($lookup->json('employees'))->firstWhere('id', $e->id);
    expect($row['branch_conflict']['message'])->toBe('Encontramos información diferente sobre la sucursal de este colaborador.')
        ->and($lookup->json('can_reconcile_branch'))->toBeTrue();

    $kpi = OkrKpi::query()->firstOrCreate(['code' => 'bc_kpi'], ['name' => 'Colocación', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE, 'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_AUTOMATIC, 'provider_key' => 'reporteria.placement', 'is_active' => true]);
    $this->actingAs($admin)->post(route('okr.store'), [
        'scope_type' => 'employee', 'branch_id' => $atlixco->id, 'employee_id' => $e->id, 'title' => 'OKR individual en conflicto',
        'start_date' => now()->toDateString(), 'duration_weeks' => 4,
        'key_results' => [['kpi_id' => $kpi->id, 'description' => 'Colocación', 'baseline_value' => 1, 'target_value' => 10, 'weight' => 100]],
    ])->assertSessionHasErrors('employee_id');
    expect(session('errors')->first('employee_id'))
        ->toStartWith('Encontramos información diferente sobre la sucursal de este colaborador.')
        ->toContain('Nómina NOI Junio 2026: TULA')
        ->toContain('Concilia su sucursal');
});

it('newer evidence wins without conflict, and the directory code prefix alone never creates one', function () {
    $atlixco = bcBranch('ATLIXCO');
    $tula = bcBranch('TULA');
    $mayo = bcPeriod('Mayo 2026', '2026-04-27', '2026-05-24');
    $junio = bcPeriod('Junio 2026', '2026-05-25', '2026-06-21');
    $moved = bcEmployee('GESTOR CAMBIO SUCURSAL');
    EmployeeBranchAssignment::query()->create(['period_id' => $mayo->id, 'employee_id' => $moved->id, 'branch_id' => $tula->id]);
    bcRoster($junio, $moved, $atlixco);

    $prefix = bcEmployee('GESTOR TRANSFERIDO PREFIJO');
    bcDirectory($junio, [['ATLX888', 'GESTOR TRANSFERIDO PREFIJO', $atlixco->id]]); // prefijo ATLX…
    bcRoster($junio, $prefix, $tula);                                                // …pero su nómina es TULA

    $resolver = app(OkrEmployeeBranchResolver::class);
    expect($resolver->membershipFor($moved->id)['branch_id'])->toBe($atlixco->id)
        ->and($resolver->membershipFor($moved->id)['conflict'])->toBeNull()
        ->and($resolver->membershipFor($prefix->id)['branch_id'])->toBe($tula->id)
        ->and($resolver->membershipFor($prefix->id)['conflict'])->toBeNull();
});

it('lets ONLY an authorized user reconcile a conflict, to one of the conflicting branches, and newer evidence resumes afterwards', function () {
    $atlixco = bcBranch('ATLIXCO');
    $tula = bcBranch('TULA');
    $cordoba = bcBranch('CORDOBA');
    $junio = bcPeriod('Junio 2026', '2026-05-25', '2026-06-21');
    $e = bcEmployee('GESTOR A CONCILIAR');
    bcDirectory($junio, [['ATLX999', 'GESTOR A CONCILIAR', $atlixco->id]]);
    bcPortfolio($junio, 'ATLX999', 'TOCH', $atlixco);
    bcRoster($junio, $e, $tula);

    $gerencial = User::factory()->create(['role' => 'gerencial']);
    $admin = User::factory()->create(['role' => 'admin']);
    $url = route('okr.branch-reconciliations.store');

    $this->actingAs($gerencial)->postJson($url, ['employee_id' => $e->id, 'branch_id' => $atlixco->id])->assertForbidden();
    $this->actingAs($admin)->postJson($url, ['employee_id' => $e->id, 'branch_id' => $cordoba->id])->assertStatus(422)
        ->assertJsonPath('message', 'La sucursal elegida no corresponde a ninguna de las fuentes en conflicto.');
    $this->actingAs($admin)->postJson($url, ['employee_id' => $e->id, 'branch_id' => $atlixco->id, 'notes' => 'Confirmado con RH'])->assertOk()
        ->assertJsonPath('membership.branch_id', $atlixco->id)
        ->assertJsonPath('membership.source', 'okr_reconciliation');

    expect(OkrBranchReconciliation::query()->count())->toBe(1)
        ->and(app(OkrEmployeeBranchResolver::class)->employeeBelongsToBranch($e->id, $atlixco->id))->toBeTrue();
    // Nunca toca la asignación de Reportería.
    expect(EmployeeBranchAssignment::query()->where('employee_id', $e->id)->count())->toBe(0);

    // Sin conflicto ya no hay nada que conciliar.
    $this->actingAs($admin)->postJson($url, ['employee_id' => $e->id, 'branch_id' => $atlixco->id])->assertStatus(422);

    // Llega una fuente MÁS RECIENTE (Julio, nómina en TULA): vuelve a mandar la evidencia.
    $julio = bcPeriod('Julio 2026', '2026-06-22', '2026-07-19');
    bcRoster($julio, $e, $tula);
    expect(app(OkrEmployeeBranchResolver::class)->membershipFor($e->id)['branch_id'])->toBe($tula->id);
});

it('confirms the real Atlixco cases (DAVID/CHOL, RAFAEL/TOCH) have NO conflict when every source agrees', function () {
    $atlixco = bcBranch('ATLIXCO');
    $chol = bcBranch('CHOL');
    $junio = bcPeriod('Junio 2026', '2026-05-25', '2026-06-21');
    $david = bcEmployee('DAVID GAMBOA CANO');
    $rafael = bcEmployee('RAFAEL PEREZ CHAO');
    bcDirectory($junio, [['ATLX12513', 'DAVID GAMBOA CANO', $atlixco->id], ['ATLX04819', 'RAFAEL PEREZ CHAO', $atlixco->id]]);
    bcPortfolio($junio, 'ATLX12513', 'CHOL', $atlixco);
    bcPortfolio($junio, 'ATLX04819', 'TOCH', $atlixco);
    bcRoster($junio, $david, $atlixco);
    bcRoster($junio, $rafael, $atlixco);
    // Asignación de Reportería apuntando al registro de RUTA (dato real): se traduce, no contradice.
    EmployeeBranchAssignment::query()->create(['period_id' => $junio->id, 'employee_id' => $david->id, 'branch_id' => $chol->id]);

    $resolver = app(OkrEmployeeBranchResolver::class);
    foreach ([$david, $rafael] as $e) {
        expect($resolver->membershipFor($e->id)['branch_id'])->toBe($atlixco->id)
            ->and($resolver->membershipFor($e->id)['conflict'])->toBeNull();
    }
});
