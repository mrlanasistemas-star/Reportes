<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\Api\EmployeePeriodKpiService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/**
 * Módulo «Datos duros» (/datos-duros): proyecta EmployeePeriodKpiService (misma
 * fuente que el Excel de colaboradores) y aplica el alcance por sucursal del
 * usuario. El cálculo real usa SQL de MySQL, así que aquí se sustituye la fuente
 * por filas sintéticas con la MISMA forma.
 */
function hdBranch(string $name): Branch
{
    return Branch::query()->create(['code' => mb_substr($name, 0, 3).random_int(100, 999), 'name' => $name, 'normalized_name' => mb_strtolower($name), 'is_active' => true]);
}

function hdPeriod(int $month, int $year = 2026): Period
{
    $period = Period::query()->create([
        'name' => "Mes {$month} {$year}", 'code' => sprintf('HD-%d-%02d', $year, $month), 'type' => 'monthly', 'year' => $year, 'month' => $month,
        'sequence' => 1, 'start_date' => sprintf('%d-%02d-01', $year, $month), 'end_date' => sprintf('%d-%02d-28', $year, $month), 'is_closed' => false,
    ]);
    PeriodSummary::query()->create(['period_id' => $period->id, 'status' => 'generated', 'version' => 1, 'generated_at' => now()]);

    return $period;
}

function hdRow(int $employeeId, string $name, Branch $branch, float $ebitda): array
{
    return [
        'employee' => ['id' => $employeeId, 'employee_ids' => [$employeeId], 'full_name' => $name, 'report_name' => $name, 'employee_code' => (string) $employeeId, 'promoter_code' => null, 'position' => null],
        'branch_id' => $branch->id,
        'branch' => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name],
        'report_branch_name' => $branch->name,
        'region' => null,
        'routes' => [['route' => "{$branch->name} CENTRO"]],
        'employment' => ['operational_status' => 'ACTIVO', 'roster_status_label' => 'Activo confirmado'],
        'kpis' => [
            'placement' => ['amount' => 1000.0, 'operations' => 2, 'by_product' => []],
            'recovery' => ['total' => 2000.0, 'components' => ['capital' => 2000.0]],
            'portfolio' => ['total' => 5000.0, 'overdue' => 500.0, 'current' => 4500.0, 'mora_pct' => 10.0, 'buckets' => []],
            'payroll' => ['human_capital_total' => 300.0, 'noi_perceptions' => 300.0, 'noi_deductions' => 0.0, 'concepts' => ['sueldo' => 300.0]],
            'commissions' => 0.0,
            'opex' => ['total' => 50.0, 'items' => []],
            'ebitda' => ['income_base' => 900.0, 'total_expenses' => 350.0, 'ebitda' => $ebitda, 'margin_pct' => 61.1, 'category' => 'MANTENIDO'],
        ],
    ];
}

function hdFakeKpis(array $rows): void
{
    $fake = Mockery::mock(EmployeePeriodKpiService::class);
    $fake->shouldReceive('forPeriod')->andReturn([
        'status' => 'ok', 'message' => null, 'calculated_at' => now()->toIso8601String(), 'radiography_version' => 1, 'rows' => $rows, 'unidentified' => [],
    ]);
    app()->instance(EmployeePeriodKpiService::class, $fake);
}

it('shows the module page with the periods that have a current radiography', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $junio = hdPeriod(6);
    hdPeriod(1, 2099); // periodo de prueba con año futuro: nunca aparece

    $this->actingAs($admin)->get('/datos-duros')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('DatosDuros/Index')
            ->has('periods', 1)
            ->where('periods.0.id', $junio->id)
            ->where('initialPeriodId', $junio->id));
});

it('returns the slim rows of a period for a general-scope user', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = hdPeriod(6);
    $ori = hdBranch('ORIZABA');
    $tula = hdBranch('TULA');
    hdFakeKpis([hdRow(9001, 'MARGARITA DOMINGUEZ', $ori, 1500.0), hdRow(2, 'ADRIAN DIAZ', $tula, -20.0)]);

    $this->actingAs($admin)->getJson("/datos-duros/periodos/{$period->id}")
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('scope.general', true)
        ->assertJsonCount(2, 'rows')
        ->assertJsonPath('rows.0.name', 'MARGARITA DOMINGUEZ')
        ->assertJsonPath('rows.0.branch', 'ORIZABA')
        ->assertJsonPath('rows.0.routes.0', 'ORIZABA CENTRO')
        ->assertJsonPath('rows.0.ebitda', 1500)
        ->assertJsonPath('rows.1.mora_pct', 10);
});

it('limits the rows to the branches a restricted user can see', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = hdPeriod(6);
    $ori = hdBranch('ORIZABA');
    $tula = hdBranch('TULA');

    $this->actingAs($admin)->post(route('roles.store'), [
        'name' => 'Gerente Orizaba', 'description' => 'Solo su sucursal', 'base_level' => 'gerencial',
        'modules' => ['reportes'], 'actions' => ['reportes' => ['ver_todos']],
    ])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('usuarios.store'), [
        'name' => 'Gerente Orizaba', 'email' => 'ori@example.com', 'role' => 'gerente_orizaba',
        'password_mode' => 'auto', 'send_invitation' => false, 'enable_access' => true,
        'branch_scope' => 'branches', 'branch_ids' => [$ori->id],
    ])->assertSessionHasNoErrors();
    $manager = User::query()->where('email', 'ori@example.com')->firstOrFail();

    $adrian = Employee::query()->create(['employee_code' => 'HD-2', 'full_name' => 'ADRIAN DIAZ', 'normalized_name' => 'adrian diaz', 'is_active' => true]);
    hdFakeKpis([hdRow(9001, 'MARGARITA DOMINGUEZ', $ori, 1500.0), hdRow($adrian->id, 'ADRIAN DIAZ', $tula, -20.0)]);

    $this->actingAs($manager)->getJson("/datos-duros/periodos/{$period->id}")
        ->assertOk()
        ->assertJsonPath('scope.general', false)
        ->assertJsonCount(1, 'rows')
        ->assertJsonPath('rows.0.branch', 'ORIZABA');

    // Su historial tampoco filtra a otra sucursal.
    $this->actingAs($manager)->getJson("/datos-duros/colaboradores/{$adrian->id}/historial")
        ->assertOk()
        ->assertJsonPath('history.0.present', false);

    // El admin (alcance general) sí lo ve.
    $this->actingAs($admin)->getJson("/datos-duros/colaboradores/{$adrian->id}/historial")
        ->assertOk()
        ->assertJsonPath('history.0.present', true)
        ->assertJsonPath('history.0.ebitda', -20);
});

it('rejects a period without a current radiography', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = Period::query()->create([
        'name' => 'Sin radiografía', 'code' => 'HD-NONE', 'type' => 'monthly', 'year' => 2026, 'month' => 3,
        'sequence' => 1, 'start_date' => '2026-03-01', 'end_date' => '2026-03-28', 'is_closed' => false,
    ]);

    $this->actingAs($admin)->getJson("/datos-duros/periodos/{$period->id}")->assertNotFound();
});

it('requires the reportes module', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin)->post(route('roles.store'), [
        'name' => 'Solo OKR', 'description' => 'Sin reportes', 'base_level' => 'gerencial', 'modules' => ['okr'], 'actions' => [],
    ])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('usuarios.store'), [
        'name' => 'Solo OKR', 'email' => 'okr@example.com', 'role' => 'solo_okr',
        'password_mode' => 'auto', 'send_invitation' => false, 'enable_access' => true, 'branch_scope' => 'all',
    ])->assertSessionHasNoErrors();
    $user = User::query()->where('email', 'okr@example.com')->firstOrFail();

    $this->actingAs($user)->get('/datos-duros')->assertForbidden();
});
