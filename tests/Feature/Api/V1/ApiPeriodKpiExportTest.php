<?php

use App\Enums\MatchType;
use App\Enums\ReportUploadStatus;
use App\Enums\SourceType;
use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Expense;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\ReportUpload;
use App\Models\Sucursal;
use App\Services\RadiografiaExportService;
use App\Services\Radiography\BranchRadiographyCalculator;
use App\Services\Radiography\EmployeesHistoricoExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

/**
 * Exportación de KPIs por periodo (API v1, 2026-10-27): colaboradores por
 * periodo con sucursal + ruta + estado laboral + KPIs, sucursales, corporativo,
 * consolidado, rango de periodos, CSV y autorización. Los números de
 * colaborador se comparan contra el Excel de colaboradores del reporte
 * (EmployeesHistoricoExportService) generado con los MISMOS datos; los de
 * sucursal/corporativo/consolidado contra las funciones canónicas de
 * BranchRadiographyCalculator sobre el MISMO snapshot.
 */
function pkeToken(array $abilities, string $mode = ApiConsumer::BRANCH_ACCESS_GLOBAL, ?array $allowed = null): string
{
    static $seq = 0;
    $seq++;
    $consumer = ApiConsumer::query()->create([
        'name' => "PKE {$seq}", 'system_code' => "pke-{$seq}", 'is_active' => true, 'abilities' => $abilities,
        'branch_access_mode' => $mode, 'allowed_branch_ids' => $allowed,
    ]);

    return $consumer->createToken('test', $abilities)->plainTextToken;
}

function pkePeriod(int $month): Period
{
    return Period::query()->create([
        'name' => "PKE Mes {$month} 2026", 'code' => sprintf('M-PKE-2026-%02d', $month), 'type' => 'monthly',
        'year' => 2026, 'month' => $month, 'sequence' => 1,
        'start_date' => sprintf('2026-%02d-01', $month), 'end_date' => sprintf('2026-%02d-28', $month), 'is_closed' => false,
    ]);
}

function pkeSummary(Period $period): PeriodSummary
{
    return PeriodSummary::query()->create([
        'period_id' => $period->id, 'status' => 'generated', 'generated_at' => now(), 'global_metrics' => [],
    ]);
}

function pkeBranch(string $name, ?Sucursal $sucursal = null): Branch
{
    return Branch::query()->firstOrCreate(
        ['normalized_name' => mb_strtolower($name)],
        ['code' => mb_substr(strtoupper(str_replace(' ', '', $name)), 0, 8), 'name' => strtoupper($name), 'is_active' => true, 'sucursal_id' => $sucursal?->id],
    );
}

function pkeSucursal(string $codigo, string $nombre): Sucursal
{
    return Sucursal::query()->firstOrCreate(['codigo' => $codigo], ['nombre' => $nombre, 'region' => 'Q1', 'activa' => true]);
}

function pkeEmployee(string $fullName, string $code): Employee
{
    $parts = explode(' ', $fullName);

    return Employee::query()->create([
        'employee_code' => $code, 'full_name' => $fullName, 'normalized_name' => mb_strtolower($fullName),
        'first_name' => $parts[0], 'paternal_last_name' => $parts[1] ?? 'X', 'is_active' => true,
        'source_system' => 'noi', 'position' => 'GESTOR',
    ]);
}

function pkeNoi(Period $period, Employee $employee, string $type, string $concept, float $amount): void
{
    DB::table('fact_noi_movements')->insert([
        'period_id' => $period->id, 'employee_id' => $employee->id, 'concept_type' => $type, 'concept' => $concept,
        'amount' => $amount, 'quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function pkeAssign(Period $period, Employee $employee, Branch $branch): void
{
    EmployeeBranchAssignment::query()->create([
        'employee_id' => $employee->id, 'period_id' => $period->id, 'branch_id' => $branch->id,
        'source_type' => SourceType::Manual, 'match_type' => MatchType::Manual,
    ]);
}

function pkeExpense(Period $period, Employee $employee, Branch $branch, float $amount): void
{
    $source = DataSource::query()->firstOrCreate(['code' => 'gastos_lendus_excel'], ['name' => 'gastos_lendus_excel', 'description' => 'x', 'is_active' => true]);
    $upload = ReportUpload::query()->create([
        'period_id' => $period->id, 'data_source_id' => $source->id, 'original_name' => 'Gastos.xlsx', 'stored_path' => 'x',
        'mime_type' => 'x', 'file_size' => 10, 'uploaded_at' => now(), 'status' => ReportUploadStatus::Processed, 'notes' => null,
    ]);
    Expense::query()->create([
        'period_id' => $period->id, 'report_upload_id' => $upload->id, 'category' => 'Recargas Telefónicas', 'concept' => 'RECARGAS TELEFONICAS',
        'amount' => $amount, 'paid_amount' => $amount, 'expense_date' => now()->format('Y-m-d'), 'branch_id' => $branch->id, 'employee_id' => $employee->id,
    ]);
}

function pkePlacement(Period $period, Branch $branch, string $promoter, float $amount): void
{
    DB::table('fact_placements')->insert([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'promoter_name' => $promoter, 'product_name' => 'CRECE',
        'amount' => $amount, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function pkePortfolio(Period $period, Branch $branch, string $promoter, string $route, float $balance, int $dpd, float $capitalDue): void
{
    DB::table('fact_portfolios')->insert([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'promoter_name' => $promoter, 'route_name' => $route,
        'balance' => $balance, 'past_due_balance' => 0, 'days_past_due' => $dpd, 'capital_due' => $capitalDue,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Escenario realista: 2 colaboradores en TULA (ruta TULA CENTRO) y ATLIXCO. */
function pkeScenario(Period $period): array
{
    $tula = pkeBranch('Tula', pkeSucursal('tula', 'TULA'));
    $atlixco = pkeBranch('Atlixco', pkeSucursal('atlixco', 'ATLIXCO'));
    $route = pkeBranch('Tula Centro', Sucursal::query()->where('codigo', 'tula')->first());

    $ana = pkeEmployee('ANA PEREZ LOPEZ', 'N001');
    $beto = pkeEmployee('BETO RUIZ DIAZ', 'N002');

    pkeNoi($period, $ana, 'percepcion', 'P001 SUELDO', 6000);
    pkeNoi($period, $ana, 'percepcion', 'P002 COMISIONES', 1500);
    pkeNoi($period, $ana, 'deduccion', 'D001 ISR', 700);
    pkeNoi($period, $beto, 'percepcion', 'P001 SUELDO', 5000);

    pkeAssign($period, $ana, $route);
    pkeAssign($period, $beto, $atlixco);

    pkeExpense($period, $ana, $route, 450);
    pkePlacement($period, $route, 'ANA PEREZ LOPEZ', 20000);
    pkePortfolio($period, $route, 'ANA PEREZ LOPEZ', 'TULA CENTRO', 50000, 0, 0);
    pkePortfolio($period, $route, 'ANA PEREZ LOPEZ', 'TULA CENTRO', 10000, 15, 2500);

    return compact('tula', 'atlixco', 'route', 'ana', 'beto');
}

function pkeExcelRows(Period $period): array
{
    $spreadsheet = app(EmployeesHistoricoExportService::class)->build($period, []);
    $tmp = tempnam(sys_get_temp_dir(), 'pke').'.xlsx';
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($tmp);
    $sheet = IOFactory::load($tmp)->getSheetByName('Colaboradores');
    @unlink($tmp);
    $rows = [];
    $header = null;
    foreach ($sheet->toArray(null, true, false, false) as $i => $line) {
        if ($i === 0) {
            $header = $line;

            continue;
        }
        if ($line[0] !== null) {
            $rows[$line[2]] = array_combine($header, $line);
        }
    }

    return $rows;
}

/** Snapshot general sintético con la MISMA forma que build() (sucursales + sin sucursal + global). */
function pkeSnapshotFixture(Period $period): array
{
    $calc = app(BranchRadiographyCalculator::class);
    $tula = array_merge($calc->emptyBranchSummary('TULA'), [
        'valor_cartera' => 1_000_000.0, 'contratos' => 300, 'colocacion' => 400_000.0, 'creditos_colocados' => 40,
        'recuperacion_total' => 500_000.0, 'capital_recuperado' => 380_000.0, 'interes_recuperado' => 90_000.0,
        'impuesto_recuperado' => 14_400.0, 'charges' => 5_000.0, 'comision_apertura' => 6_000.0, 'cargos_adicionales' => 1_000.0,
        'excedente_recuperado' => 600.0, 'seguro_crece_reconocido' => 3_000.0,
        'mora_0_30' => 40_000.0, 'mora_31_60' => 20_000.0, 'mora_120_plus' => 10_000.0, 'cartera_vencida' => 70_000.0,
        'gastos_operativos' => 30_000.0, 'gastos_erp_total' => 18_000.0, 'gastos_lendus_total' => 12_000.0,
        'gastos_detalle' => ['Gasolina' => 10_000.0, 'Renta' => 20_000.0],
        'nomina_total' => 45_000.0, 'comisiones' => 8_000.0, 'bonos' => 2_000.0, 'imss_patronal' => 6_000.0,
        'prestamos_fondea' => 15_000.0, 'excedentes' => 25_000.0, 'seguros_lendus_puente' => 900.0,
    ]);
    $atlixco = array_merge($calc->emptyBranchSummary('ATLIXCO'), [
        'valor_cartera' => 600_000.0, 'colocacion' => 200_000.0, 'recuperacion_total' => 250_000.0,
        'interes_recuperado' => 40_000.0, 'impuesto_recuperado' => 6_400.0, 'mora_61_90' => 30_000.0, 'cartera_vencida' => 30_000.0,
        'gastos_operativos' => 22_000.0, 'nomina_total' => 30_000.0, 'comisiones' => 3_000.0,
    ]);
    $unassigned = array_merge((new ReflectionMethod($calc, 'emptyUnassigned'))->invoke($calc), [
        'nomina_total' => 35_000.0, 'imss_patronal' => 4_000.0,
        'empleados' => [['nombre' => 'DIRECTOR GENERAL', 'emp_id' => 999, 'fuente' => 'noi_nomina', 'p001' => 35_000.0, 'p002' => 0.0, 'bonos' => 0.0]],
        'imss_excluido' => ['CORPORATIVO' => 4_500.0],
    ]);
    $global = $calc->sumGlobal([$tula, $atlixco], $unassigned);

    return [
        'generated_at' => '27/10/2026 09:00',
        'saldo_inicial_caja' => 100_000.0, 'saldo_final_caja' => null,
        'scope' => ['type' => 'general', 'available' => true],
        'branch_radiography' => ['branches' => [$tula, $atlixco], 'global' => $global, 'unassigned' => $unassigned],
        'summary' => [
            'employees_count' => 3,
            'recovery_total' => (float) $global['recuperacion_total'], 'placement_total' => (float) $global['colocacion'],
            'portfolio_total' => 1_700_000.0, 'overdue_portfolio' => 100_000.0, 'mora_index' => 5.88,
            'opex_total' => (float) $global['gastos_operativos'], 'expenses_erp' => 18_000.0, 'expenses_lendus' => 12_000.0,
            'seguros_lendus_puente' => 900.0, 'excedentes_total' => 25_000.0, 'fondeo_total' => 15_000.0,
            'nomina_capital_humano_total' => BranchRadiographyCalculator::nominaTotalFor($global),
            'noi_percepciones' => 1.0, 'noi_deducciones' => 0.0, 'noi_neto_pagado' => 1.0,
            'ingreso_ebitda_base' => BranchRadiographyCalculator::ingresoEbitdaBaseFor($global),
            'gastos_totales' => BranchRadiographyCalculator::gastosTotalesFor($global),
            'ebitda_final' => BranchRadiographyCalculator::ebitdaFinalFor($global),
            'margen_ebitda' => BranchRadiographyCalculator::margenEbitdaFor($global),
            'ebitda_categoria' => 'MANTENIDO',
        ],
        'sections' => [
            'rotation' => ['indice' => 4.5, 'por_sucursal' => [['sucursal' => 'TULA', 'indice_rotacion' => 3.2]]],
            'corporate_funding' => ['total' => 25_000.0, 'by_branch' => [['branch' => 'TULA', 'total' => 25_000.0]]],
            'interbranch_loans' => ['total' => 15_000.0],
            'efectividad_cobranza' => ['efectividad' => ['efectividad_pct' => 61.5, 'recuperado_de_mora' => 12_300.0, 'cartera_mora_periodo_anterior' => 20_000.0]],
        ],
    ];
}

function pkeMockSnapshot(): void
{
    $mock = Mockery::mock(RadiografiaExportService::class);
    $mock->shouldReceive('buildSnapshot')->andReturnUsing(fn (Period $p) => pkeSnapshotFixture($p));
    app()->instance(RadiografiaExportService::class, $mock);
}

// ── Colaboradores por periodo ────────────────────────────────────────────────

it('exports every collaborator of the period with canonical identity, branch, sucursal, route, roster and KPIs', function () {
    $period = pkePeriod(6);
    pkeSummary($period);
    $s = pkeScenario($period);
    DB::table('period_employee_rosters')->insert([
        'period_id' => $period->id, 'employee_id' => $s['ana']->id, 'employee_key' => 'k', 'nombre_normalizado' => 'ana perez lopez',
        'nombre_original' => 'ANA PEREZ LOPEZ', 'branch_id' => $s['route']->id, 'is_active_for_period' => true, 'movement_type' => 'alta',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $token = pkeToken(['employees.read', 'financial.read']);
    $res = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$period->id}/employees");

    $res->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('meta.total', 2);
    $ana = collect($res->json('data'))->firstWhere('employee.full_name', 'ANA PEREZ LOPEZ');

    expect($ana['employee']['id'])->toBe($s['ana']->id)
        ->and($ana['employee']['employee_code'])->toBe('N001')
        ->and($ana['employee']['position'])->toBe('GESTOR')
        ->and($ana['branch']['id'])->toBe($s['route']->id)
        ->and($ana['branch_status'])->toBe('known')
        ->and($ana['sucursal']['codigo'])->toBe('tula')
        ->and($ana['employment']['roster_status'])->toBe('alta_confirmada')
        ->and($ana['employment']['operational_status'])->toBe('ACTIVO');

    $routes = collect($ana['routes']);
    expect($routes->pluck('catalog_route')->all())->toContain('TULA CENTRO');
    $tulaCentro = $routes->firstWhere('catalog_route', 'TULA CENTRO');
    expect($tulaCentro['catalog_branch'])->toBe('TULA')
        ->and($tulaCentro['sources'])->toContain('assignment')
        ->and($tulaCentro['sources'])->toContain('portfolio')
        ->and($tulaCentro['contracts'])->toBe(2);

    expect($ana['kpis']['commissions'])->toEqual(1500.0)
        ->and($ana['kpis']['payroll']['concepts']['sueldo'])->toEqual(6000.0)
        ->and($ana['kpis']['payroll']['noi_deductions'])->toEqual(700.0)
        ->and($ana['kpis']['opex']['automatic_total'])->toEqual(450.0)
        ->and($ana['kpis']['opex']['manual'])->toEqual(0.0)
        ->and($ana['kpis']['portfolio']['total'])->toEqual(60000.0)
        ->and($ana['kpis']['portfolio']['overdue'])->toEqual(2500.0)
        ->and($ana['kpis']['placement']['amount'])->toEqual(20000.0);

    $beto = collect($res->json('data'))->firstWhere('employee.full_name', 'BETO RUIZ DIAZ');
    expect($beto['sucursal']['codigo'])->toBe('atlixco')
        ->and($beto['employment']['roster_available'])->toBeFalse()
        ->and($beto['routes'])->toBe([]);
});

it('collaborator KPIs match EXACTLY the report Excel of collaborators built from the same data', function () {
    $period = pkePeriod(7);
    pkeSummary($period);
    pkeScenario($period);

    $excel = pkeExcelRows($period);
    $token = pkeToken(['employees.read', 'financial.read']);
    $api = collect($this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$period->id}/employees")->json('data'))
        ->keyBy('employee.report_name');

    expect(count($excel))->toBe(2)
        ->and($api->count())->toBe(count($excel));
    foreach ($excel as $name => $x) {
        $row = $api[$name];
        expect($row['kpis']['recovery']['total'])->toEqual((float) $x['RECUPERACIÓN'])
            ->and($row['kpis']['placement']['amount'])->toEqual((float) $x['COLOCACIÓN'])
            ->and($row['kpis']['portfolio']['total'])->toEqual((float) $x['VALOR CARTERA'])
            ->and($row['kpis']['portfolio']['overdue'])->toEqual((float) $x['CARTERA VENCIDA'])
            ->and($row['kpis']['portfolio']['mora_pct'])->toEqual((float) $x['MORA %'])
            ->and($row['kpis']['opex']['automatic_total'])->toEqual((float) $x['OPEX AUTOMÁTICO'])
            ->and($row['kpis']['opex']['total'])->toEqual((float) $x['OPEX TOTAL'])
            ->and($row['kpis']['payroll']['human_capital_total'])->toEqual((float) $x['NÓMINA / CAPITAL HUMANO'])
            ->and($row['kpis']['payroll']['noi_perceptions'])->toEqual((float) $x['PERCEPCIONES'])
            ->and($row['kpis']['payroll']['noi_deductions'])->toEqual((float) $x['DEDUCCIONES'])
            ->and($row['kpis']['payroll']['noi_net_paid'])->toEqual((float) $x['NETO PAGADO'])
            ->and($row['kpis']['ebitda']['income_base'])->toEqual((float) $x['UTILIDAD BRUTA'])
            ->and($row['kpis']['ebitda']['ebitda'])->toEqual((float) $x['EBITDA'])
            ->and($row['kpis']['ebitda']['margin_pct'])->toEqual((float) $x['MARGEN EBITDA %'])
            ->and($row['employment']['operational_status'])->toBe($x['ESTADO ACTIVO/BAJA']);
    }
});

it('returns not_available (never zeros) for a period without a generated radiography', function () {
    $period = pkePeriod(8);
    pkeScenario($period);
    $token = pkeToken(['employees.read', 'financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$period->id}/employees")
        ->assertOk()->assertJsonPath('status', 'not_available')->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
});

it('filters collaborators by branch_id / sucursal_id and paginates', function () {
    $period = pkePeriod(9);
    pkeSummary($period);
    $s = pkeScenario($period);
    $token = pkeToken(['employees.read', 'financial.read']);
    $h = ['Authorization' => "Bearer {$token}"];

    $byBranch = $this->withHeaders($h)->getJson("/api/v1/periods/{$period->id}/employees?branch_id={$s['atlixco']->id}");
    expect(collect($byBranch->json('data'))->pluck('employee.full_name')->all())->toBe(['BETO RUIZ DIAZ']);

    $tulaId = Sucursal::query()->where('codigo', 'tula')->value('id');
    $bySucursal = $this->withHeaders($h)->getJson("/api/v1/periods/{$period->id}/employees?sucursal_id={$tulaId}");
    expect(collect($bySucursal->json('data'))->pluck('employee.full_name')->all())->toBe(['ANA PEREZ LOPEZ']);

    $page2 = $this->withHeaders($h)->getJson("/api/v1/periods/{$period->id}/employees?per_page=1&page=2");
    $page2->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.current_page', 2);
    expect($page2->json('data'))->toHaveCount(1);
});

it('a branch-restricted consumer only sees collaborators whose HISTORICAL branch of that period is allowed', function () {
    $period = pkePeriod(10);
    pkeSummary($period);
    $s = pkeScenario($period);
    $token = pkeToken(['employees.read', 'financial.read'], ApiConsumer::BRANCH_ACCESS_RESTRICTED, [$s['atlixco']->id]);
    $h = ['Authorization' => "Bearer {$token}"];

    $res = $this->withHeaders($h)->getJson("/api/v1/periods/{$period->id}/employees");
    expect(collect($res->json('data'))->pluck('employee.full_name')->all())->toBe(['BETO RUIZ DIAZ'])
        ->and($res->json('meta.unidentified_count'))->toBeNull();

    $this->withHeaders($h)->getJson("/api/v1/periods/{$period->id}/employees?branch_id={$s['route']->id}")->assertForbidden();
});

it('exports collaborators as CSV when format=csv', function () {
    $period = pkePeriod(11);
    pkeSummary($period);
    pkeScenario($period);
    $token = pkeToken(['employees.read', 'financial.read']);

    $res = $this->withHeader('Authorization', "Bearer {$token}")->get("/api/v1/periods/{$period->id}/employees?format=csv");
    $res->assertOk();
    expect($res->headers->get('Content-Type'))->toContain('text/csv');
    $csv = $res->streamedContent();
    expect($csv)->toContain('employee.full_name')->toContain('kpis.ebitda.ebitda')->toContain('ANA PEREZ LOPEZ')->toContain('sucursal.codigo');
});

// ── Colaborador a lo largo de varios periodos ───────────────────────────────

it('returns one collaborator across a period range with the branch/route of EACH period', function () {
    $june = pkePeriod(4);
    $july = pkePeriod(5);
    pkeSummary($june);
    pkeSummary($july);
    $s = pkeScenario($june);
    // En el segundo mes ANA se mueve a ATLIXCO (sin cartera, solo nómina).
    pkeNoi($july, $s['ana'], 'percepcion', 'P001 SUELDO', 6100);
    pkeAssign($july, $s['ana'], $s['atlixco']);

    $token = pkeToken(['employees.read', 'financial.read']);
    $res = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/employees/{$s['ana']->id}/periods?from_period_id={$june->id}&to_period_id={$july->id}");

    $res->assertOk()->assertJsonPath('meta.periods_count', 2);
    [$first, $second] = $res->json('data');
    expect($first['period']['id'])->toBe($june->id)
        ->and($first['status'])->toBe('ok')
        ->and($first['sucursal']['codigo'])->toBe('tula')
        ->and(collect($first['routes'])->pluck('catalog_route')->all())->toContain('TULA CENTRO')
        ->and($first['kpis']['collections_effectiveness'])->toHaveKey('effectiveness_pct')
        ->and($second['period']['id'])->toBe($july->id)
        ->and($second['sucursal']['codigo'])->toBe('atlixco')
        ->and($second['kpis']['payroll']['concepts']['sueldo'])->toEqual(6100.0);
});

it('marks periods outside the restricted consumer scope as forbidden and 403s when none is allowed', function () {
    $june = pkePeriod(1);
    $july = pkePeriod(2);
    pkeSummary($june);
    pkeSummary($july);
    $s = pkeScenario($june);
    pkeNoi($july, $s['ana'], 'percepcion', 'P001 SUELDO', 6100);
    pkeAssign($july, $s['ana'], $s['atlixco']);

    $atlixcoOnly = pkeToken(['employees.read', 'financial.read'], ApiConsumer::BRANCH_ACCESS_RESTRICTED, [$s['atlixco']->id]);
    $res = $this->withHeader('Authorization', "Bearer {$atlixcoOnly}")
        ->getJson("/api/v1/employees/{$s['ana']->id}/periods?period_ids={$june->id},{$july->id}&include_effectiveness=0");
    $res->assertOk();
    expect(collect($res->json('data'))->pluck('status')->all())->toBe(['forbidden', 'ok']);

    $otherBranch = pkeBranch('Orizaba');
    app('auth')->forgetGuards();
    $none = pkeToken(['employees.read', 'financial.read'], ApiConsumer::BRANCH_ACCESS_RESTRICTED, [$otherBranch->id]);
    $this->withHeader('Authorization', "Bearer {$none}")
        ->getJson("/api/v1/employees/{$s['ana']->id}/periods?period_ids={$june->id},{$july->id}")
        ->assertForbidden();
});

it('validates the period range parameters', function () {
    $june = pkePeriod(3);
    $weekly = Period::query()->create([
        'name' => 'Semana PKE', 'code' => 'W-PKE-1', 'type' => 'weekly', 'year' => 2026, 'month' => 3, 'sequence' => 1,
        'start_date' => '2026-03-02', 'end_date' => '2026-03-08', 'is_closed' => false,
    ]);
    $e = pkeEmployee('CARLA SOTO MENA', 'N009');
    $token = pkeToken(['employees.read', 'financial.read']);
    $h = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($h)->getJson("/api/v1/employees/{$e->id}/periods")->assertStatus(422);
    $this->withHeaders($h)->getJson("/api/v1/employees/{$e->id}/periods?from_period_id={$june->id}&to_period_id={$weekly->id}")->assertStatus(422);
    $this->withHeaders($h)->getJson("/api/v1/employees/{$e->id}/periods?period_ids=abc")->assertStatus(422);
    $this->withHeaders($h)->getJson("/api/v1/employees/{$e->id}/periods?period_ids={$june->id}&from_period_id={$june->id}")->assertStatus(422);
});

// ── Sucursales, corporativo y consolidado ───────────────────────────────────

it('exports every branch with its KPIs plus CORPORATE and CONSOLIDATED blocks matching the report snapshot', function () {
    $period = pkePeriod(6);
    $tula = pkeBranch('Tula', pkeSucursal('tula', 'TULA'));
    pkeBranch('Atlixco', pkeSucursal('atlixco', 'ATLIXCO'));
    pkeMockSnapshot();
    $fixture = pkeSnapshotFixture($period);
    [$tulaRow] = $fixture['branch_radiography']['branches'];

    $token = pkeToken(['financial.read']);
    $res = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$period->id}/branches");

    $res->assertOk()->assertJsonPath('status', 'ok');
    $t = collect($res->json('data'))->firstWhere('name', 'TULA');
    expect($t['branch_id'])->toBe($tula->id)
        ->and($t['sucursal']['codigo'])->toBe('tula')
        ->and(collect($t['routes'])->pluck('route')->all())->toContain('TULA CENTRO')
        ->and($t['kpis']['ebitda']['ebitda'])->toEqual(round(BranchRadiographyCalculator::ebitdaFinalFor($tulaRow), 2))
        ->and($t['kpis']['ebitda']['margin_pct'])->toEqual(BranchRadiographyCalculator::margenEbitdaFor($tulaRow))
        ->and($t['kpis']['payroll']['human_capital_total'])->toEqual(round(BranchRadiographyCalculator::nominaTotalFor($tulaRow), 2))
        ->and($t['kpis']['expenses']['opex_total'])->toEqual(30000.0)
        ->and($t['kpis']['expenses']['erp'])->toEqual(18000.0)
        ->and($t['kpis']['expenses']['lendus'])->toEqual(12000.0)
        ->and($t['kpis']['portfolio']['overdue'])->toEqual(70000.0)
        ->and($t['kpis']['portfolio']['mora_pct'])->toEqual(7.0)
        ->and($t['kpis']['funding']['interbranch_loans'])->toEqual(15000.0)
        ->and($t['kpis']['insurance']['lendus_bridge'])->toEqual(900.0)
        ->and($t['kpis']['turnover_pct'])->toEqual(3.2);

    $corporate = $res->json('corporate');
    expect($corporate['payroll']['human_capital_total'])->toEqual(39000.0)
        ->and($corporate['ebitda_contribution'])->toEqual(-39000.0)
        ->and($corporate['payroll']['employees'][0]['name'])->toBe('DIRECTOR GENERAL')
        ->and($corporate['surplus_received']['total'])->toEqual(25000.0)
        ->and($corporate['imss_excluded_informative']['CORPORATIVO'])->toEqual(4500.0);

    $consolidated = $res->json('consolidated');
    expect($consolidated['kpis']['ebitda']['ebitda'])->toEqual(round($fixture['summary']['ebitda_final'], 2))
        ->and($consolidated['kpis']['portfolio']['total'])->toEqual(1700000.0)
        ->and($consolidated['kpis']['payroll']['human_capital_total'])->toEqual(round($fixture['summary']['nomina_capital_humano_total'], 2))
        ->and($consolidated['kpis']['collections_effectiveness']['effectiveness_pct'])->toEqual(61.5)
        ->and($consolidated['kpis']['turnover_pct'])->toEqual(4.5);

    // El consolidado reconcilia EXACTO: Σ sucursales + aporte corporativo.
    expect($res->json('reconciliation.ebitda.difference'))->toEqual(0.0);
});

it('a branch-restricted consumer only gets its branches and never the corporate/consolidated totals', function () {
    $period = pkePeriod(7);
    $tula = pkeBranch('Tula', pkeSucursal('tula', 'TULA'));
    pkeBranch('Atlixco', pkeSucursal('atlixco', 'ATLIXCO'));
    pkeMockSnapshot();

    $token = pkeToken(['financial.read'], ApiConsumer::BRANCH_ACCESS_RESTRICTED, [$tula->id]);
    $res = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$period->id}/branches");

    $res->assertOk();
    expect(collect($res->json('data'))->pluck('name')->all())->toBe(['TULA'])
        ->and($res->json('corporate.status'))->toBe('not_authorized')
        ->and($res->json('consolidated.status'))->toBe('not_authorized')
        ->and($res->json('reconciliation'))->toBeNull();
});

it('branches endpoint reports not_available without radiography and supports CSV', function () {
    $period = pkePeriod(8);
    $token = pkeToken(['financial.read']);
    $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$period->id}/branches")
        ->assertOk()->assertJsonPath('status', 'not_available')->assertJsonPath('consolidated', null);

    pkeBranch('Tula', pkeSucursal('tula', 'TULA'));
    pkeMockSnapshot();
    $csv = $this->withHeader('Authorization', "Bearer {$token}")->get("/api/v1/periods/{$period->id}/branches?format=csv");
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('block')->toContain('corporate')->toContain('consolidated')->toContain('kpis.ebitda.ebitda');
});

// ── Exportación masiva ──────────────────────────────────────────────────────

it('exports a whole period (branches + corporate + consolidated + collaborators) in a single call', function () {
    $period = pkePeriod(6);
    pkeSummary($period);
    pkeScenario($period);
    pkeMockSnapshot();
    $token = pkeToken(['employees.read', 'financial.read']);

    $res = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$period->id}/export");

    $res->assertOk()
        ->assertJsonPath('period.id', $period->id)
        ->assertJsonPath('branches.status', 'ok')
        ->assertJsonPath('employees.status', 'ok')
        ->assertJsonPath('employees.total', 2);
    expect($res->json('branches.corporate.ebitda_contribution'))->toEqual(-39000.0)
        ->and($res->json('branches.consolidated.name'))->toBe('CONSOLIDADO')
        ->and($res->json('employees.unidentified'))->toBeArray();
});

it('exports a period range and validates abilities for the collaborators block', function () {
    $may = pkePeriod(5);
    $june = pkePeriod(6);
    pkeSummary($may);
    pkeSummary($june);
    pkeScenario($may);
    pkeMockSnapshot();

    $full = pkeToken(['employees.read', 'financial.read']);
    $res = $this->withHeader('Authorization', "Bearer {$full}")
        ->getJson("/api/v1/periods/export?from_period_id={$may->id}&to_period_id={$june->id}");
    $res->assertOk()->assertJsonPath('meta.periods_count', 2);
    expect(collect($res->json('periods'))->pluck('period.id')->all())->toBe([$may->id, $june->id])
        ->and($res->json('periods.0.employees.total'))->toBe(2)
        ->and($res->json('periods.1.employees.total'))->toBe(0);

    $csv = $this->withHeader('Authorization', "Bearer {$full}")
        ->get("/api/v1/periods/export?period_ids={$may->id},{$june->id}&format=csv&dataset=employees");
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('period_id')->toContain('ANA PEREZ LOPEZ');
    $this->withHeader('Authorization', "Bearer {$full}")
        ->getJson("/api/v1/periods/export?period_ids={$may->id}&format=csv")->assertStatus(422);

    // financial.read sin employees.read: solo sucursales.
    app('auth')->forgetGuards();
    $financialOnly = pkeToken(['financial.read']);
    $this->withHeader('Authorization', "Bearer {$financialOnly}")
        ->getJson("/api/v1/periods/{$may->id}/export")->assertForbidden();
    $this->withHeader('Authorization', "Bearer {$financialOnly}")
        ->getJson("/api/v1/periods/{$may->id}/export?include=branches")
        ->assertOk()->assertJsonMissingPath('employees');
});

// ── Autenticación / abilities / capabilities ────────────────────────────────

it('requires authentication and the right abilities on every new endpoint', function () {
    $period = pkePeriod(12);
    $e = pkeEmployee('DORA LUNA PAZ', 'N010');

    foreach ([
        "/api/v1/periods/{$period->id}/employees",
        "/api/v1/periods/{$period->id}/branches",
        "/api/v1/periods/{$period->id}/export",
        "/api/v1/periods/export?period_ids={$period->id}",
        "/api/v1/employees/{$e->id}/periods?period_ids={$period->id}",
    ] as $url) {
        $this->getJson($url)->assertUnauthorized();
    }

    $employeesOnly = pkeToken(['employees.read']);
    $this->withHeader('Authorization', "Bearer {$employeesOnly}")->getJson("/api/v1/periods/{$period->id}/employees")->assertForbidden();
    $this->withHeader('Authorization', "Bearer {$employeesOnly}")->getJson("/api/v1/employees/{$e->id}/periods?period_ids={$period->id}")->assertForbidden();
    $this->withHeader('Authorization', "Bearer {$employeesOnly}")->getJson("/api/v1/periods/{$period->id}/branches")->assertForbidden();
    $this->withHeader('Authorization', "Bearer {$employeesOnly}")->getJson("/api/v1/periods/{$period->id}/export")->assertForbidden();

    $this->withHeader('Authorization', "Bearer {$employeesOnly}")->getJson('/api/v1/periods/999999/employees')->assertForbidden();
    app('auth')->forgetGuards();
    $both = pkeToken(['employees.read', 'financial.read']);
    $this->withHeader('Authorization', "Bearer {$both}")->getJson('/api/v1/periods/999999/employees')->assertNotFound();
});

it('lists the new export resources in capabilities with their required abilities', function () {
    $token = pkeToken(['financial.read']);
    $res = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/capabilities');

    $res->assertOk()->assertJsonPath('features.period_kpi_export', true);
    expect($res->json('resources.period_branches_kpis.permitted'))->toBeTrue()
        ->and($res->json('resources.period_employees_kpis.permitted'))->toBeFalse()
        ->and($res->json('resources.period_employees_kpis.required_abilities'))->toBe(['employees.read', 'financial.read'])
        ->and($res->json('resources.period_export.endpoints'))->toContain('GET /api/v1/periods/export');
});

it('computes collaborator KPIs in batch: the number of queries does not grow with the number of collaborators (no N+1)', function () {
    $countQueries = function (Period $period, string $token): int {
        app('auth')->forgetGuards();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$period->id}/employees")->assertOk();
        // Solo lecturas: last_used_at/auditoría se escriben con throttle y no dependen de los datos.
        $n = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with(strtolower(ltrim($q['query'])), 'select'))->count();
        DB::disableQueryLog();

        return $n;
    };

    $small = pkePeriod(1);
    pkeSummary($small);
    $branch = pkeBranch('Tula', pkeSucursal('tula', 'TULA'));
    foreach (['UNO', 'DOS'] as $i => $n) {
        $e = pkeEmployee("GESTOR {$n} A", "S{$i}");
        pkeNoi($small, $e, 'percepcion', 'P001 SUELDO', 1000);
        pkeAssign($small, $e, $branch);
        pkeExpense($small, $e, $branch, 100);
    }

    $big = pkePeriod(2);
    pkeSummary($big);
    foreach (['UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO'] as $i => $n) {
        $e = pkeEmployee("GESTOR {$n} B", "B{$i}");
        pkeNoi($big, $e, 'percepcion', 'P001 SUELDO', 1000);
        pkeAssign($big, $e, $branch);
        pkeExpense($big, $e, $branch, 100);
    }

    $token = pkeToken(['employees.read', 'financial.read']);
    $smallCount = $countQueries($small, $token);
    $bigCount = $countQueries($big, $token);

    expect($bigCount)->toBe($smallCount);
});
