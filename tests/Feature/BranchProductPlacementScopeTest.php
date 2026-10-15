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
 * Defecto 15-oct-2026: en Reportes mensuales → Preview con alcance CUERNAVACA la tabla
 * "Colocación por producto" mostraba el desglose de TODA la empresa (s12 = $6,458,000)
 * mientras el total de colocación de la sucursal era $410,390.00 — 'sections.products'
 * quedaba sin tocar en applyBranchScope(). Estas pruebas garantizan que bajo alcance
 * sucursal SUM(products.colocacion) == colocación de la sucursal y que el desglose
 * global (ni ninguna otra sección agregada a nivel empresa) nunca llega a un restringido.
 *
 * build() completo no corre en SQLite (REGEXP/JSON_EXTRACT de MySQL) — igual que en
 * RadiographyScopeTest se proyecta el alcance sobre un snapshot general sintético con la
 * MISMA forma que produce build().
 */
function bppInvoke(RadiographySnapshotBuilder $builder, string $method, array $dataIds, array $args): array
{
    $prop = new ReflectionProperty($builder, 'dataIds');
    $prop->setAccessible(true);
    $prop->setValue($builder, $dataIds);
    $ref = new ReflectionMethod($builder, $method);
    $ref->setAccessible(true);

    return $ref->invoke($builder, ...$args);
}

function bppPeriod(string $code): Period
{
    return Period::query()->create(['name' => 'Junio 2026', 'code' => $code, 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => false]);
}

function bppBranch(string $name): Branch
{
    return Branch::query()->firstOrCreate(['normalized_name' => mb_strtolower($name)], ['code' => $name, 'name' => $name, 'is_active' => true]);
}

/** Snapshot general sintético: desglose GLOBAL de productos (13.7M) + filas por sucursal. */
function bppGeneralSnapshot(int $periodId, float $cuernavacaColocacion = 410390.0): array
{
    $branchRow = fn (string $name, float $col) => ['sucursal' => $name, 'recuperacion_total' => 500000.0, 'colocacion' => $col, 'valor_cartera' => 900000.0, 'mora_0_30' => 0.0, 'mora_31_60' => 0.0, 'mora_61_90' => 0.0, 'mora_91_120' => 0.0, 'mora_120_plus' => 0.0, 'gastos_operativos' => 10000.0, 'nomina_total' => 20000.0, 'comisiones' => 0.0, 'bonos' => 0.0];

    return [
        'period' => ['id' => $periodId, 'label' => 'Junio 2026'],
        'generated_at' => '15/10/2026 10:00',
        'summary' => ['placement_total' => 14151329.0, 'recovery_total' => 17000000.0, 'ebitda_final' => 1861873.28],
        'branch_radiography' => [
            'branches' => [$branchRow('CUERNAVACA', $cuernavacaColocacion), $branchRow('ORIZABA', 5416793.0)],
            'global' => ['colocacion' => 14151329.0], 'unassigned' => [],
        ],
        'sections' => [
            'products' => [
                ['producto' => 's12', 'tipo' => 'operativo', 'operaciones' => 900, 'colocacion' => 6458000.0, 'recuperacion' => 1.0, 'cartera' => 1.0, 'contratos' => 1, 'pct' => 47.1],
                ['producto' => 'i30', 'tipo' => 'operativo', 'operaciones' => 500, 'colocacion' => 3979000.0, 'recuperacion' => 1.0, 'cartera' => 1.0, 'contratos' => 1, 'pct' => 29.0],
            ],
            'placement_by_branch_product' => [
                // Dos filas del MISMO producto para CUERNAVACA (sucursal + ruta mapeada) — se agrupan.
                ['branch' => 'CUERNAVACA', 'product' => 's12', 'creditos' => 20, 'monto' => 100000.0, 'apertura' => 0.0],
                ['branch' => 'CUERNAVACA', 'product' => 's12', 'creditos' => 9, 'monto' => 45000.0, 'apertura' => 0.0],
                ['branch' => 'CUERNAVACA', 'product' => 'A LA MEDIDA', 'creditos' => 10, 'monto' => 100000.0, 'apertura' => 0.0],
                ['branch' => 'CUERNAVACA', 'product' => 'A LA MEDIDA (INSOLUTOS)', 'creditos' => 8, 'monto' => 80000.0, 'apertura' => 0.0],
                ['branch' => 'CUERNAVACA', 'product' => 'CRECE12', 'creditos' => 3, 'monto' => 85390.0, 'apertura' => 0.0],
                ['branch' => 'ORIZABA', 'product' => 's12', 'creditos' => 700, 'monto' => 5416793.0, 'apertura' => 0.0],
            ],
            'portfolio_by_branch_product' => [
                ['branch' => 'CUERNAVACA', 'product' => 's12', 'contratos' => 40, 'cartera' => 300000.0, 'vencida' => 0.0],
                ['branch' => 'ORIZABA', 'product' => 's12', 'contratos' => 900, 'cartera' => 9000000.0, 'vencida' => 0.0],
            ],
            'employees_gestores' => [], 'mora_by_gestor' => [], 'active_loans' => [], 'mora_by_branch_product' => [], 'mora_by_branch' => [],
            'payroll_by_branch_concept' => ['data' => [], 'incidents' => []],
            'expenses_detail' => ['total' => 0, 'byBranch' => [], 'byEmployee' => [], 'byCategory' => [], 'byConcept' => [], 'bySource' => []],
            'recovery_by_product' => ['rows' => [['product' => 's12', 'total' => 9000000.0]], 'total' => 9000000.0],
            // Agregados a nivel EMPRESA que no deben viajar en un snapshot con alcance.
            'branches' => [['sucursal' => 'ORIZABA', 'colocacion' => 5416793.0], ['sucursal' => 'CUERNAVACA', 'colocacion' => 410390.0]],
            'employees' => [['name' => 'OTRA PERSONA DE ORIZABA']],
            'promoters' => [['name' => 'PROMOTOR ORIZABA']],
            'payroll' => ['pagos' => 999999.0, 'bonos' => 1.0, 'neto' => 1.0, 'source' => 'noi'],
            'expenses_matrix' => ['branches' => ['ORIZABA', 'CUERNAVACA'], 'matrix' => []],
            'recovery_detail' => ['by_branch' => [['branch' => 'ORIZABA']]],
            'mora_by_product' => [['product' => 's12', 'cartera' => 38000000.0]],
            'incidents' => [['type' => 'x', 'message' => 'incidencia de ORIZABA']],
            'interbranch_loans' => ['total' => 1.0], 'corporate_funding' => ['total' => 1.0], 'fondeo_detalle' => ['total' => 1.0],
        ],
        'charts' => ['placement_by_product' => [['label' => 's12', 'value' => 6458000.0, 'pct' => 100.0]]],
    ];
}

const BPP_COMPANY_WIDE = ['branches', 'employees', 'promoters', 'payroll', 'expenses_matrix', 'recovery_detail', 'mora_by_product', 'incidents'];

it('alcance sucursal: colocación por producto es la de ESA sucursal y suma exactamente su total de colocación (nunca el desglose global)', function () {
    $period = bppPeriod('M-BPP-1');
    $cuer = bppBranch('CUERNAVACA');
    bppBranch('ORIZABA');

    $result = bppInvoke(app(RadiographySnapshotBuilder::class), 'applyBranchScope', [$period->id], [bppGeneralSnapshot($period->id), $cuer->id, $period]);
    $products = $result['sections']['products'];

    expect($products)->toBeArray()->and(array_is_list($products))->toBeTrue()
        ->and(round(array_sum(array_column($products, 'colocacion')), 2))->toBe(410390.0)
        ->and(round(array_sum(array_column($products, 'colocacion')), 2))->toBe(round((float) $result['summary']['placement_total'], 2))
        ->and(collect($products)->pluck('producto')->all())->toEqualCanonicalizing(['s12', 'A LA MEDIDA', 'A LA MEDIDA (INSOLUTOS)', 'CRECE12']);

    $s12 = collect($products)->firstWhere('producto', 's12');
    expect($s12['colocacion'])->toBe(145000.0)          // 100,000 + 45,000 (sucursal + ruta), nunca 6,458,000 global
        ->and($s12['operaciones'])->toBe(29)
        ->and($s12['cartera'])->toBe(300000.0)          // cartera de CUERNAVACA, no la de ORIZABA
        ->and($s12['recuperacion'])->toBeNull();        // sin desglose reconciliado por sucursal → nunca $0 falso

    expect(collect($products)->pluck('colocacion')->max())->toBeLessThan(6458000.0);
});

it('alcance sucursal: si el desglose no reconcilia contra el total, se marca no disponible en vez de mostrar el global', function () {
    $period = bppPeriod('M-BPP-2');
    $cuer = bppBranch('CUERNAVACA');

    $result = bppInvoke(app(RadiographySnapshotBuilder::class), 'applyBranchScope', [$period->id], [bppGeneralSnapshot($period->id, 999999.0), $cuer->id, $period]);

    expect($result['sections']['products']['not_attributable'] ?? false)->toBeTrue()
        ->and($result['sections']['products']['reason'])->toContain('no reconcilia');
});

it('alcance sucursal y colaborador: ninguna sección agregada a nivel empresa viaja en el snapshot', function () {
    $period = bppPeriod('M-BPP-3');
    $cuer = bppBranch('CUERNAVACA');
    $builder = app(RadiographySnapshotBuilder::class);

    $branch = bppInvoke($builder, 'applyBranchScope', [$period->id], [bppGeneralSnapshot($period->id), $cuer->id, $period]);
    foreach (BPP_COMPANY_WIDE as $k) {
        expect($branch['sections'][$k]['not_attributable'] ?? false)->toBeTrue("sections.{$k} (sucursal)");
    }
    expect($branch['sections']['recovery_by_product']['not_attributable'] ?? false)->toBeTrue();

    $employee = Employee::query()->create(['full_name' => 'GESTOR CUERNAVACA PRUEBA', 'normalized_name' => 'gestor cuernavaca prueba', 'is_active' => true, 'source_system' => 'noi']);
    $empRow = ['name' => 'GESTOR CUERNAVACA PRUEBA', 'branch' => 'CUERNAVACA', 'colocacion' => 145000.0, 'recuperacion' => 0.0, 'cartera' => 0.0, 'vencida' => 0.0, 'gastos' => 0.0,
        '_employee_ids' => [$employee->id], '_placements_by_product' => [['producto' => 's12', 'operaciones' => 29, 'colocacion' => 145000.0]]];
    $snap = bppGeneralSnapshot($period->id);
    $snap['sections']['employees_gestores'] = [$empRow];

    $emp = bppInvoke($builder, 'applyEmployeeScope', [$period->id], [$snap, $employee->id, [$empRow], $period]);
    expect($emp['sections']['products'])->toBe([['producto' => 's12', 'operaciones' => 29, 'colocacion' => 145000.0]]);
    foreach (BPP_COMPANY_WIDE as $k) {
        expect($emp['sections'][$k]['not_attributable'] ?? false)->toBeTrue("sections.{$k} (colaborador)");
    }
});

it('usuario restringido a CUERNAVACA: scoped-data entrega el desglose por producto de su sucursal (suma = total) y nunca el global', function () {
    $period = bppPeriod('M-BPP-4');
    PeriodSummary::query()->create(['period_id' => $period->id, 'status' => 'generated', 'generated_at' => now(), 'global_metrics' => [], 'warnings' => [], 'version' => 1]);
    $cuer = bppBranch('CUERNAVACA');
    $ori = bppBranch('ORIZABA');

    $admin = User::factory()->create(['role' => 'admin']);
    if (! DB::table('roles')->where('key', 'gerente_sucursal_bpp')->exists()) {
        $this->actingAs($admin)->post(route('roles.store'), ['name' => 'Gerente Sucursal BPP', 'description' => 'Prueba', 'base_level' => 'gerencial', 'modules' => ['reportes'], 'actions' => []])->assertSessionHasNoErrors();
    }
    $this->actingAs($admin)->post(route('usuarios.store'), [
        'name' => 'Gerente Cuernavaca', 'email' => 'bpp-gerente@example.test', 'role' => 'gerente_sucursal_bpp', 'password_mode' => 'auto',
        'send_invitation' => false, 'enable_access' => true, 'branch_scope' => 'branches', 'branch_ids' => [$cuer->id],
    ])->assertSessionHasNoErrors();
    $user = User::query()->where('email', 'bpp-gerente@example.test')->firstOrFail();

    // Export service real reemplazado solo en el paso pesado (build() es MySQL-only): la
    // proyección de alcance SÍ es la real de RadiographySnapshotBuilder::applyBranchScope().
    $export = Mockery::mock(RadiografiaExportService::class);
    $export->shouldReceive('buildSnapshot')->andReturnUsing(function ($p, array $config) use ($period) {
        $general = bppGeneralSnapshot($period->id);
        if (($config['scope'] ?? 'general') !== 'branch') {
            return $general;
        }

        return bppInvoke(app(RadiographySnapshotBuilder::class), 'applyBranchScope', [$period->id], [$general, (int) $config['branch_id'], $period]);
    });
    app()->instance(RadiografiaExportService::class, $export);

    $res = $this->actingAs($user)->getJson(route('reportes-mensuales.scoped-data', ['period' => $period->id, 'scope' => 'branch', 'branch_id' => $cuer->id]));
    $res->assertOk();

    $products = $res->json('snapshot.sections.products');
    expect(round(array_sum(array_column($products, 'colocacion')), 2))->toBe(410390.0)
        ->and(round((float) $res->json('snapshot.summary.placement_total'), 2))->toBe(410390.0)
        ->and(collect($products)->pluck('colocacion')->all())->not->toContain(6458000.0)
        ->and($res->getContent())->not->toContain('6458000')
        ->and($res->getContent())->not->toContain('OTRA PERSONA DE ORIZABA')
        ->and($res->getContent())->not->toContain('5416793');

    // Nunca puede pedir el general ni otra sucursal.
    $this->actingAs($user)->getJson(route('reportes-mensuales.scoped-data', ['period' => $period->id, 'scope' => 'general']))->assertForbidden();
    $this->actingAs($user)->getJson(route('reportes-mensuales.scoped-data', ['period' => $period->id, 'scope' => 'branch', 'branch_id' => $ori->id]))->assertForbidden();
});
