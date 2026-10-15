<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\RadiografiaExportService;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/**
 * Defecto 15-oct-2026: con alcance sucursal, sections.rotation seguía trayendo la
 * rotación de TODA la empresa (Junio 2026: plantilla 126, índice 3.97 %) y por_sucursal
 * con las 12 sucursales — la pestaña Rotación del Preview de un restringido a
 * CUERNAVACA mostraba el total global. Ahora los totales son la fila de la sucursal y
 * por_sucursal solo trae esa fila (la misma que leen API/OKR → mismo valor).
 *
 * build() es MySQL-only: se proyecta sobre un snapshot general sintético con la misma
 * forma que produce build()/buildRotationData() (igual que BranchProductPlacementScopeTest).
 */
function brsInvoke(RadiographySnapshotBuilder $builder, string $method, array $dataIds, array $args): array
{
    $prop = new ReflectionProperty($builder, 'dataIds');
    $prop->setAccessible(true);
    $prop->setValue($builder, $dataIds);
    $ref = new ReflectionMethod($builder, $method);
    $ref->setAccessible(true);

    return $ref->invoke($builder, ...$args);
}

function brsPeriod(string $code): Period
{
    return Period::query()->create(['name' => 'Junio 2026', 'code' => $code, 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => false]);
}

function brsBranch(string $name): Branch
{
    return Branch::query()->firstOrCreate(['normalized_name' => mb_strtolower($name)], ['code' => $name, 'name' => $name, 'is_active' => true]);
}

function brsRotRow(string $suc, int $altas, int $bajas, float $plantilla, float $anterior): array
{
    return ['sucursal' => $suc, 'altas' => $altas, 'bajas' => $bajas, 'promedio_personal' => $plantilla, 'plantilla_anterior' => $anterior,
        'variacion_plantilla' => round($plantilla - $anterior, 2), 'indice_rotacion' => $plantilla > 0 ? round($bajas / $plantilla * 100, 2) : 0.0, 'mes' => 'JUNIO 2026'];
}

/** Snapshot general sintético con rotación GLOBAL (126 / 3.97 %) y filas de varias sucursales. */
function brsGeneralSnapshot(int $periodId): array
{
    $branchRow = fn (string $name) => ['sucursal' => $name, 'recuperacion_total' => 500000.0, 'colocacion' => 100000.0, 'valor_cartera' => 900000.0, 'mora_0_30' => 0.0, 'mora_31_60' => 0.0, 'mora_61_90' => 0.0, 'mora_91_120' => 0.0, 'mora_120_plus' => 0.0, 'gastos_operativos' => 10000.0, 'nomina_total' => 20000.0, 'comisiones' => 0.0, 'bonos' => 0.0];
    $person = fn (string $suc, string $nombre) => ['sucursal' => $suc, 'clave' => '1', 'nombre' => $nombre, 'motivo' => 'x'];

    return [
        'period' => ['id' => $periodId, 'label' => 'Junio 2026'],
        'generated_at' => '15/10/2026 10:00',
        'summary' => ['placement_total' => 200000.0],
        'branch_radiography' => [
            'branches' => [$branchRow('CUERNAVACA'), $branchRow('ORIZABA'), $branchRow('TULA')],
            'global' => ['colocacion' => 300000.0], 'unassigned' => [],
        ],
        'sections' => [
            'rotation' => [
                'fuente' => 'derived_noi', 'mes' => 'JUNIO 2026', 'altas' => 7, 'bajas' => 5, 'promedio' => 126.0, 'indice' => 3.97,
                'current_count' => 126.0, 'prev_count' => 124.0, 'prev_mes' => 'Mayo 2026', 'variacion_neta' => 2.0,
                'por_sucursal' => [brsRotRow('CUERNAVACA', 3, 3, 8.0, 8.0), brsRotRow('ORIZABA', 4, 2, 118.0, 116.0)],
                'detalle_mensual' => [],
            ],
            'rotation_detail' => [
                'altas' => [$person('CUERNAVACA', 'ALTA CUERNAVACA'), $person('ORIZABA', 'ALTA ORIZABA')],
                'bajas' => [$person('CUERNAVACA', 'BAJA CUERNAVACA'), $person('ORIZABA', 'BAJA ORIZABA')],
                'bajas_pendientes' => [$person('CUERNAVACA', 'PENDIENTE CUERNAVACA 1'), $person('CUERNAVACA', 'PENDIENTE CUERNAVACA 2'), $person('ORIZABA', 'PENDIENTE ORIZABA')],
                'activos' => [], 'empleados_mes_actual' => [], 'empleados_mes_anterior' => [],
                'sin_sucursal' => [['clave' => '9', 'nombre' => 'PERSONA SIN SUCURSAL', 'fuente' => 'NOI', 'motivo' => 'x']],
                'mes_actual' => 'JUNIO 2026', 'mes_anterior' => 'MAYO 2026',
            ],
            'products' => [],
            'placement_by_branch_product' => [], 'portfolio_by_branch_product' => [],
            'employees_gestores' => [], 'mora_by_gestor' => [], 'active_loans' => [], 'mora_by_branch_product' => [], 'mora_by_branch' => [],
            'payroll_by_branch_concept' => ['data' => [], 'incidents' => []],
            'expenses_detail' => ['total' => 0, 'byBranch' => [], 'byEmployee' => [], 'byCategory' => [], 'byConcept' => [], 'bySource' => []],
        ],
        'charts' => [],
    ];
}

it('alcance sucursal: rotación = fila de CUERNAVACA (totales y por_sucursal), nunca el total de la empresa', function () {
    $period = brsPeriod('M-BRS-1');
    $cuer = brsBranch('CUERNAVACA');

    $general = brsGeneralSnapshot($period->id);
    $result = brsInvoke(app(RadiographySnapshotBuilder::class), 'applyBranchScope', [$period->id], [$general, $cuer->id, $period]);
    $rot = $result['sections']['rotation'];

    expect($rot['altas'])->toBe(3)
        ->and($rot['bajas'])->toBe(3)
        ->and($rot['bajas_confirmadas'])->toBe(3)
        ->and($rot['bajas_pendientes'])->toBe(2)
        ->and($rot['current_count'])->toBe(8.0)
        ->and($rot['promedio'])->toBe(8.0)
        ->and($rot['prev_count'])->toBe(8.0)
        ->and($rot['indice'])->toBe(37.5)
        ->and($rot['por_sucursal'])->toHaveCount(1)
        ->and($rot['por_sucursal'][0])->toBe($general['sections']['rotation']['por_sucursal'][0]) // fila intacta (API/OKR)
        ->and($rot['indice'])->not->toBe(3.97)
        ->and($rot['current_count'])->not->toBe(126.0);

    expect($result['sections']['rotation_detail']['sin_sucursal'])->toBe([])
        ->and(json_encode($result['sections']['rotation'] + ['d' => $result['sections']['rotation_detail']]))->not->toContain('ORIZABA');
});

it('alcance sucursal sin fila de rotación: not_attributable con motivo, nunca el total global', function () {
    $period = brsPeriod('M-BRS-2');
    $tula = brsBranch('TULA');

    $result = brsInvoke(app(RadiographySnapshotBuilder::class), 'applyBranchScope', [$period->id], [brsGeneralSnapshot($period->id), $tula->id, $period]);

    expect($result['sections']['rotation']['not_attributable'] ?? false)->toBeTrue()
        ->and($result['sections']['rotation']['reason'])->toContain('TULA')
        ->and($result['sections']['rotation'])->not->toHaveKey('indice')
        ->and($result['sections']['rotation'])->not->toHaveKey('por_sucursal');
});

it('alcance colaborador: rotación agregada not_attributable con motivo específico', function () {
    $period = brsPeriod('M-BRS-3');
    brsBranch('CUERNAVACA');
    $employee = Employee::query()->create(['full_name' => 'GESTOR CUERNAVACA ROT', 'normalized_name' => 'gestor cuernavaca rot', 'is_active' => true, 'source_system' => 'noi']);
    $empRow = ['name' => 'GESTOR CUERNAVACA ROT', 'branch' => 'CUERNAVACA', 'colocacion' => 0.0, 'recuperacion' => 0.0, 'cartera' => 0.0, 'vencida' => 0.0, 'gastos' => 0.0,
        '_employee_ids' => [$employee->id], '_placements_by_product' => []];
    $snap = brsGeneralSnapshot($period->id);
    $snap['sections']['employees_gestores'] = [$empRow];

    $emp = brsInvoke(app(RadiographySnapshotBuilder::class), 'applyEmployeeScope', [$period->id], [$snap, $employee->id, [$empRow], $period]);

    expect($emp['sections']['rotation']['not_attributable'] ?? false)->toBeTrue()
        ->and($emp['sections']['rotation']['reason'])->toContain('no aplica a un colaborador')
        ->and($emp['sections']['rotation_detail']['sin_sucursal'])->toBe([]);
});

it('usuario restringido a CUERNAVACA: scoped-data no trae filas de rotación de otra sucursal ni el total global', function () {
    $period = brsPeriod('M-BRS-4');
    PeriodSummary::query()->create(['period_id' => $period->id, 'status' => 'generated', 'generated_at' => now(), 'global_metrics' => [], 'warnings' => [], 'version' => 1]);
    $cuer = brsBranch('CUERNAVACA');
    $ori = brsBranch('ORIZABA');

    $admin = User::factory()->create(['role' => 'admin']);
    if (! DB::table('roles')->where('key', 'gerente_sucursal_brs')->exists()) {
        $this->actingAs($admin)->post(route('roles.store'), ['name' => 'Gerente Sucursal BRS', 'description' => 'Prueba', 'base_level' => 'gerencial', 'modules' => ['reportes'], 'actions' => []])->assertSessionHasNoErrors();
    }
    $this->actingAs($admin)->post(route('usuarios.store'), [
        'name' => 'Gerente Cuernavaca Rot', 'email' => 'brs-gerente@example.test', 'role' => 'gerente_sucursal_brs', 'password_mode' => 'auto',
        'send_invitation' => false, 'enable_access' => true, 'branch_scope' => 'branches', 'branch_ids' => [$cuer->id],
    ])->assertSessionHasNoErrors();
    $user = User::query()->where('email', 'brs-gerente@example.test')->firstOrFail();

    // Solo se sustituye el paso pesado (build() MySQL-only); la proyección es la real.
    $export = Mockery::mock(RadiografiaExportService::class);
    $export->shouldReceive('buildSnapshot')->andReturnUsing(function ($p, array $config) use ($period) {
        $general = brsGeneralSnapshot($period->id);
        if (($config['scope'] ?? 'general') !== 'branch') {
            return $general;
        }

        return brsInvoke(app(RadiographySnapshotBuilder::class), 'applyBranchScope', [$period->id], [$general, (int) $config['branch_id'], $period]);
    });
    app()->instance(RadiografiaExportService::class, $export);

    $res = $this->actingAs($user)->getJson(route('reportes-mensuales.scoped-data', ['period' => $period->id, 'scope' => 'branch', 'branch_id' => $cuer->id]));
    $res->assertOk();

    $rot = $res->json('snapshot.sections.rotation');
    expect(collect($rot['por_sucursal'])->pluck('sucursal')->all())->toBe(['CUERNAVACA'])
        ->and((float) $rot['current_count'])->toBe(8.0)
        ->and((float) $rot['indice'])->toBe(37.5)
        ->and((int) $rot['bajas_pendientes'])->toBe(2);

    $rotJson = json_encode([$res->json('snapshot.sections.rotation'), $res->json('snapshot.sections.rotation_detail')]);
    expect($rotJson)->not->toContain('ORIZABA')
        ->and($rotJson)->not->toContain('3.97')
        ->and($rotJson)->not->toContain('126')
        ->and($rotJson)->not->toContain('PERSONA SIN SUCURSAL');

    $this->actingAs($user)->getJson(route('reportes-mensuales.scoped-data', ['period' => $period->id, 'scope' => 'general']))->assertForbidden();
    $this->actingAs($user)->getJson(route('reportes-mensuales.scoped-data', ['period' => $period->id, 'scope' => 'branch', 'branch_id' => $ori->id]))->assertForbidden();
});
