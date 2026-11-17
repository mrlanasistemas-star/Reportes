<?php

use App\Enums\MatchType;
use App\Enums\SourceType;
use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Models\PeriodSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Actividad semanal de un mes (API v1, 2026-11-17 — Seguimiento de MR. LANA PEOPLE):
 * los movimientos del MES (fact_* etiquetados con el periodo mensual) se reparten en
 * sus semanas componentes por fecha. Σ semanas + fuera de semana debe ser EXACTAMENTE
 * la cifra oficial del mes del colaborador (/periods/{id}/employees).
 */
function pwaToken(array $abilities, string $mode = ApiConsumer::BRANCH_ACCESS_GLOBAL, ?array $allowed = null): string
{
    static $seq = 0;
    $seq++;
    $consumer = ApiConsumer::query()->create([
        'name' => "PWA {$seq}", 'system_code' => "pwa-{$seq}", 'is_active' => true, 'abilities' => $abilities,
        'branch_access_mode' => $mode, 'allowed_branch_ids' => $allowed,
    ]);

    return $consumer->createToken('test', $abilities)->plainTextToken;
}

/** Mes de 2 semanas (la 2ª se crea primero para probar el orden por fecha). */
function pwaMonth(bool $withSummary = true): array
{
    $w2 = Period::query()->create([
        'name' => 'Semana 11 - Febrero 2026', 'code' => 'W-PWA-11', 'type' => 'weekly', 'year' => 2026, 'month' => 2, 'sequence' => 11,
        'start_date' => '2026-03-09', 'end_date' => '2026-03-15', 'is_closed' => false,
    ]);
    $w1 = Period::query()->create([
        'name' => 'Semana 10 - Marzo 2026', 'code' => 'W-PWA-10', 'type' => 'weekly', 'year' => 2026, 'month' => 3, 'sequence' => 10,
        'start_date' => '2026-03-02', 'end_date' => '2026-03-08', 'is_closed' => false,
    ]);
    $month = Period::query()->create([
        'name' => 'Marzo 2026', 'code' => 'M-PWA-03', 'type' => 'monthly', 'year' => 2026, 'month' => 3, 'sequence' => 1,
        'start_date' => '2026-03-02', 'end_date' => '2026-03-15', 'is_closed' => false, 'component_period_ids' => [$w2->id, $w1->id],
    ]);
    if ($withSummary) {
        PeriodSummary::query()->create(['period_id' => $month->id, 'status' => 'generated', 'generated_at' => now(), 'global_metrics' => []]);
    }

    return compact('w1', 'w2', 'month');
}

function pwaBranch(string $name): Branch
{
    return Branch::query()->create(['code' => strtoupper(str_replace(' ', '_', $name)), 'name' => strtoupper($name), 'normalized_name' => mb_strtolower($name), 'is_active' => true]);
}

function pwaEmployee(string $fullName, string $code): Employee
{
    $parts = explode(' ', $fullName);

    return Employee::query()->create([
        'employee_code' => $code, 'full_name' => $fullName, 'normalized_name' => mb_strtolower($fullName),
        'first_name' => $parts[0], 'paternal_last_name' => $parts[1] ?? 'X', 'is_active' => true, 'source_system' => 'noi', 'position' => 'GESTOR',
    ]);
}

function pwaPlacement(Period $period, ?Branch $branch, ?string $promoter, float $amount, ?string $date): void
{
    DB::table('fact_placements')->insert([
        'period_id' => $period->id, 'branch_id' => $branch?->id, 'promoter_name' => $promoter, 'product_name' => 'CRECE',
        'amount' => $amount, 'operation_date' => $date, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function pwaRecovery(Period $period, Branch $branch, string $promoter, float $amount, string $date, string $transaction = 'PAGO', int $savehearts = 0, float $creceShare = 0): void
{
    DB::table('fact_recoveries')->insert([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'promoter_name' => $promoter, 'contract' => 'C-'.uniqid(),
        'days_past_due' => 0, 'is_savehearts' => $savehearts, 'savehearts_crece_share' => $creceShare, 'transaction' => $transaction,
        'capital' => $amount, 'interest' => 0, 'tax' => 0, 'charges_due' => 0, 'total_amount' => $amount, 'payment_date' => $date,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** ANA (Tula) con variante de escritura en Lendus + BETO (Atlixco) + movimientos sin gestor/sucursal. */
function pwaScenario(Period $month): array
{
    $tula = pwaBranch('Tula');
    $atlixco = pwaBranch('Atlixco');
    $ana = pwaEmployee('ANA MARIA PEREZ LOPEZ', 'N001');
    $beto = pwaEmployee('BETO RUIZ DIAZ', 'N002');

    foreach ([[$ana, $tula], [$beto, $atlixco]] as [$e, $b]) {
        DB::table('fact_noi_movements')->insert([
            'period_id' => $month->id, 'employee_id' => $e->id, 'concept_type' => 'percepcion', 'concept' => 'P001 SUELDO',
            'amount' => 5000, 'quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        EmployeeBranchAssignment::query()->create([
            'employee_id' => $e->id, 'period_id' => $month->id, 'branch_id' => $b->id,
            'source_type' => SourceType::Manual, 'match_type' => MatchType::Manual,
        ]);
    }

    pwaPlacement($month, $tula, 'ANA MARIA PEREZ LOPEZ', 10000, '2026-03-03');
    pwaPlacement($month, $tula, 'ANA MARIA PEREZ LOPEZ', 5000, '2026-03-10');
    pwaPlacement($month, $tula, 'ANA MARIA PERES LOPEZ', 1000, '2026-03-11'); // variante: se fusiona igual que el reporte
    pwaPlacement($month, $tula, 'ANA MARIA PEREZ LOPEZ', 2000, '2026-03-20'); // fuera de las semanas del mes
    pwaPlacement($month, $atlixco, 'BETO RUIZ DIAZ', 4000, '2026-03-14');
    pwaPlacement($month, null, null, 700, '2026-03-04'); // sin gestor ni sucursal

    pwaRecovery($month, $tula, 'ANA MARIA PEREZ LOPEZ', 3000, '2026-03-05');
    pwaRecovery($month, $tula, 'ANA MARIA PEREZ LOPEZ', 1000, '2026-03-12');
    pwaRecovery($month, $tula, 'ANA MARIA PEREZ LOPEZ', 999, '2026-03-12', 'CONDONACION'); // excluida por la regla única
    pwaRecovery($month, $tula, 'ANA MARIA PEREZ LOPEZ', 100, '2026-03-13', 'PAGO', 1, 30); // CRECE: solo el 30 reconocido
    pwaRecovery($month, $atlixco, 'BETO RUIZ DIAZ', 2500, '2026-03-02');

    return compact('tula', 'atlixco', 'ana', 'beto');
}

it('splits the month into its component weeks (ordered by date) and reconciles exactly with the official monthly KPIs', function () {
    ['w1' => $w1, 'w2' => $w2, 'month' => $month] = pwaMonth();
    $s = pwaScenario($month);
    $token = pwaToken(['employees.read', 'financial.read']);

    $res = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$month->id}/weekly-activity");

    $res->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('source', 'reporteria.weekly_activity')
        ->assertJsonPath('granularity.data_tagging', 'monthly')
        ->assertJsonPath('weeks.0.id', $w1->id)
        ->assertJsonPath('weeks.0.index', 1)
        ->assertJsonPath('weeks.1.id', $w2->id)
        ->assertJsonPath('branches.attribution', 'fact_branch_id');

    $ana = collect($res->json('employees.data'))->firstWhere('employee.id', $s['ana']->id);
    expect($ana['employee']['employee_code'])->toBe('N001')
        ->and($ana['assigned_branch_id'])->toBe($s['tula']->id)
        ->and($ana['weeks'][0]['period_id'])->toBe($w1->id)
        ->and($ana['weeks'][0]['placement'])->toEqual(['amount' => 10000.0, 'operations' => 1])
        ->and($ana['weeks'][1]['placement'])->toEqual(['amount' => 6000.0, 'operations' => 2])
        ->and($ana['outside_weeks']['placement'])->toEqual(['amount' => 2000.0, 'operations' => 1])
        ->and($ana['weeks'][0]['recovery']['total'])->toEqual(3000.0)
        ->and($ana['weeks'][1]['recovery']['total'])->toEqual(1030.0);

    // Paridad con /periods/{id}/employees: Σ semanas + fuera de semana == oficial del mes.
    $official = collect($this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$month->id}/employees")->json('data'))
        ->keyBy('employee.id');
    foreach ($res->json('employees.data') as $row) {
        $o = $official[$row['employee']['id']];
        $placement = array_sum(array_column(array_column($row['weeks'], 'placement'), 'amount')) + $row['outside_weeks']['placement']['amount'];
        $ops = array_sum(array_column(array_column($row['weeks'], 'placement'), 'operations')) + $row['outside_weeks']['placement']['operations'];
        $recovery = array_sum(array_column(array_column($row['weeks'], 'recovery'), 'total')) + $row['outside_weeks']['recovery']['total'];
        expect(round($placement, 2))->toEqual((float) $o['kpis']['placement']['amount'])
            ->and($ops)->toBe((int) $o['kpis']['placement']['operations'])
            ->and(round($recovery, 2))->toEqual((float) $o['kpis']['recovery']['total'])
            ->and($row['month_official']['placement']['amount'])->toEqual((float) $o['kpis']['placement']['amount'])
            ->and($row['month_total']['recovery']['total'])->toEqual($row['month_official']['recovery']['total']);
    }

    // Conciliación global: todos los movimientos (con y sin gestor/sucursal).
    expect($res->json('reconciliation.placement'))->toEqual(['weeks_sum' => 20700.0, 'outside_weeks' => 2000.0, 'month_total' => 22700.0])
        ->and($res->json('reconciliation.recovery'))->toEqual(['weeks_sum' => 6530.0, 'outside_weeks' => 0.0, 'month_total' => 6530.0])
        ->and($res->json('branches.unassigned.month_total.placement.amount'))->toEqual(700.0);

    $tula = collect($res->json('branches.data'))->firstWhere('branch_id', $s['tula']->id);
    expect($tula['branch']['code'])->toBe('TULA')
        ->and($tula['month_total']['placement']['amount'])->toEqual(18000.0)
        ->and($tula['month_total']['recovery']['total'])->toEqual(4030.0);
});

it('rejects weekly periods and months without component weeks with 422', function () {
    ['w1' => $w1] = pwaMonth();
    $bare = Period::query()->create([
        'name' => 'Mes sin semanas', 'code' => 'M-PWA-BARE', 'type' => 'monthly', 'year' => 2026, 'month' => 4, 'sequence' => 1,
        'start_date' => '2026-04-01', 'end_date' => '2026-04-28', 'is_closed' => false,
    ]);
    $token = pwaToken(['employees.read', 'financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$w1->id}/weekly-activity")->assertStatus(422);
    $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$bare->id}/weekly-activity")->assertStatus(422);
});

it('returns not_available (never zeros) without a current radiography, still listing the weeks', function () {
    ['month' => $month] = pwaMonth(withSummary: false);
    pwaScenario($month);
    $token = pwaToken(['employees.read', 'financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$month->id}/weekly-activity")
        ->assertOk()
        ->assertJsonPath('status', 'not_available')
        ->assertJsonCount(2, 'weeks')
        ->assertJsonPath('employees.data', [])
        ->assertJsonPath('branches.data', [])
        ->assertJsonPath('reconciliation', null);
});

it('requires employees.read for the employees block and allows include=branches with financial.read only', function () {
    ['month' => $month] = pwaMonth();
    pwaScenario($month);
    $token = pwaToken(['financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$month->id}/weekly-activity")->assertForbidden();
    $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$month->id}/weekly-activity?include=branches")
        ->assertOk()->assertJsonMissingPath('employees');
    $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$month->id}/weekly-activity?include=nope")->assertStatus(422);

    $noFinancial = pwaToken(['employees.read']);
    $this->withHeader('Authorization', "Bearer {$noFinancial}")->getJson("/api/v1/periods/{$month->id}/weekly-activity")->assertForbidden();
});

it('restricts a branch-scoped consumer to its branches and hides global-only blocks', function () {
    ['month' => $month] = pwaMonth();
    $s = pwaScenario($month);
    $token = pwaToken(['employees.read', 'financial.read'], ApiConsumer::BRANCH_ACCESS_RESTRICTED, [$s['tula']->id]);

    $res = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$month->id}/weekly-activity")->assertOk();

    expect(collect($res->json('employees.data'))->pluck('employee.id')->all())->toBe([$s['ana']->id])
        ->and(collect($res->json('branches.data'))->pluck('branch_id')->all())->toBe([$s['tula']->id])
        ->and($res->json('branches.unassigned'))->toBeNull()
        ->and($res->json('employees.unidentified'))->toBeNull()
        ->and($res->json('reconciliation'))->toBeNull();
});

it('announces weekly_activity in capabilities and exposes component_period_ids in /periods', function () {
    ['w1' => $w1, 'w2' => $w2, 'month' => $month] = pwaMonth();
    $token = pwaToken(['periods.read', 'financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/capabilities')
        ->assertOk()
        ->assertJsonPath('features.weekly_activity', true)
        ->assertJsonPath('resources.period_weekly_activity.permitted', true)
        ->assertJsonPath('resources.period_weekly_activity.endpoints.0', 'GET /api/v1/periods/{id}/weekly-activity');

    $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$month->id}")
        ->assertOk()->assertJsonPath('component_period_ids', [$w2->id, $w1->id]);
    $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$w1->id}")
        ->assertOk()->assertJsonPath('component_period_ids', []);
});
