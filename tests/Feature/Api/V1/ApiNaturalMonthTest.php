<?php

use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Models\Period;
use App\Models\PeriodSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Meses naturales (API v1, 23-nov-2026): del 1 al último día del mes por la FECHA de
 * cada movimiento. Los registros siguen ligados a su periodo de 4 semanas; un mes
 * natural toma filas de varios periodos sin duplicar ni perder ninguna.
 */
function nmToken(array $abilities, string $mode = ApiConsumer::BRANCH_ACCESS_GLOBAL, ?array $allowed = null): string
{
    static $seq = 0;
    $seq++;
    $consumer = ApiConsumer::query()->create([
        'name' => "NM {$seq}", 'system_code' => "nm-{$seq}", 'is_active' => true, 'abilities' => $abilities,
        'branch_access_mode' => $mode, 'allowed_branch_ids' => $allowed,
    ]);

    return $consumer->createToken('test', $abilities)->plainTextToken;
}

function nmPeriod(string $name, int $month, string $start, string $end, bool $summary = true): Period
{
    $p = Period::query()->create([
        'name' => $name, 'code' => sprintf('M-NM-%02d', $month), 'type' => 'monthly', 'year' => 2026, 'month' => $month, 'sequence' => 1,
        'start_date' => $start, 'end_date' => $end, 'is_closed' => false, 'component_period_ids' => [],
    ]);
    if ($summary) {
        PeriodSummary::query()->create(['period_id' => $p->id, 'status' => 'generated', 'generated_at' => now(), 'global_metrics' => []]);
    }

    return $p;
}

function nmBranch(string $name): Branch
{
    return Branch::query()->create(['code' => strtoupper($name), 'name' => strtoupper($name), 'normalized_name' => mb_strtolower($name), 'is_active' => true]);
}

function nmPlacement(Period $p, ?Branch $b, float $amount, string $date): void
{
    DB::table('fact_placements')->insert([
        'period_id' => $p->id, 'branch_id' => $b?->id, 'promoter_name' => null, 'product_name' => 'CRECE',
        'amount' => $amount, 'operation_date' => $date, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function nmRecovery(Period $p, Branch $b, float $amount, string $date, string $transaction = 'PAGO'): void
{
    DB::table('fact_recoveries')->insert([
        'period_id' => $p->id, 'branch_id' => $b->id, 'promoter_name' => null, 'contract' => 'C-'.uniqid(),
        'days_past_due' => 0, 'is_savehearts' => 0, 'savehearts_crece_share' => 0, 'transaction' => $transaction,
        'capital' => $amount, 'interest' => 0, 'tax' => 0, 'charges_due' => 0, 'total_amount' => $amount, 'payment_date' => $date,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function nmExpense(Period $p, Branch $b, float $amount, string $date, string $category = 'RENTA'): void
{
    DB::table('fact_expenses')->insert([
        'period_id' => $p->id, 'branch_id' => $b->id, 'category' => $category, 'concept' => 'X',
        'amount' => $amount, 'paid_amount' => $amount, 'expense_date' => $date, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Calendario MR LANA: P04 = 30-mar → 26-abr, P05 = 27-abr → 24-may (abril natural toca ambos). */
function nmScenario(): array
{
    $p04 = nmPeriod('Abril 2026', 4, '2026-03-30', '2026-04-26');
    $p05 = nmPeriod('Mayo 2026', 5, '2026-04-27', '2026-05-24');
    $tula = nmBranch('Tula');
    $atlixco = nmBranch('Atlixco');

    nmPlacement($p04, $tula, 1000, '2026-03-31');   // marzo natural (aunque es del periodo de abril)
    nmPlacement($p04, $tula, 2000, '2026-04-01');
    nmPlacement($p04, $atlixco, 3000, '2026-04-26');
    nmPlacement($p05, $tula, 4000, '2026-04-28');   // abril natural, cargado en el periodo de mayo
    nmPlacement($p05, $atlixco, 5000, '2026-05-02'); // mayo natural

    nmRecovery($p04, $tula, 700, '2026-04-10');
    nmRecovery($p04, $tula, 999, '2026-04-10', 'CONDONACION'); // fuera por la regla única del reporte
    nmRecovery($p05, $atlixco, 300, '2026-04-30');
    nmRecovery($p05, $atlixco, 800, '2026-05-03');

    nmExpense($p04, $tula, 30000, '2026-04-05');
    nmExpense($p05, $tula, 30000, '2026-05-01');
    nmExpense($p04, $atlixco, 500, '2026-03-28', 'PAPELERIA'); // fuera de la ventana de su periodo

    return compact('p04', 'p05', 'tula', 'atlixco');
}

it('builds the natural month from every period by the date of each movement, without duplicating', function () {
    ['p04' => $p04, 'p05' => $p05, 'tula' => $tula] = nmScenario();
    $token = nmToken(['financial.read']);

    $res = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/calendar-months/2026-04');

    $res->assertOk()
        ->assertJsonPath('month', '2026-04')
        ->assertJsonPath('date_from', '2026-04-01')
        ->assertJsonPath('date_to', '2026-04-30')
        ->assertJsonPath('status', 'complete')
        ->assertJsonPath('coverage.covered_days', 30)
        ->assertJsonPath('totals.placement.amount', 9000)      // 2000 + 3000 + 4000
        ->assertJsonPath('totals.placement.operations', 3)
        ->assertJsonPath('totals.recovery.total', 1000)        // 700 + 300 (condonación fuera)
        ->assertJsonPath('totals.erp_expenses.amount', 30000)
        ->assertJsonPath('reconciliation.placement.branches_sum', 9000)
        ->assertJsonPath('reconciliation.placement.daily_sum', 9000)
        ->assertJsonMissingPath('employees');

    $sources = collect($res->json('source_periods'))->keyBy('id');
    expect($sources->keys()->sort()->values()->all())->toBe([$p04->id, $p05->id])
        ->and($sources[$p04->id]['contributed']['placement'])->toEqual(5000)
        ->and($sources[$p05->id]['contributed']['placement'])->toEqual(4000)
        ->and($sources[$p04->id]['overlap'])->toBe(['from' => '2026-04-01', 'to' => '2026-04-26', 'days' => 26]);

    $tulaRow = collect($res->json('branches.data'))->firstWhere('branch_id', $tula->id);
    expect($tulaRow['placement']['amount'])->toEqual(6000)->and($tulaRow['recovery']['total'])->toEqual(700);

    // Los registros siguen en su periodo: nada se movió.
    expect(DB::table('fact_placements')->where('period_id', $p04->id)->count())->toBe(3);
});

it('reports partial coverage and the missing days of a month not fully loaded', function () {
    nmScenario();
    $token = nmToken(['financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/calendar-months/2026-05')
        ->assertOk()
        ->assertJsonPath('status', 'partial')
        ->assertJsonPath('coverage.covered_days', 24)
        ->assertJsonPath('coverage.missing_ranges.0', ['from' => '2026-05-25', 'to' => '2026-05-31'])
        ->assertJsonPath('totals.placement.amount', 5000)
        ->assertJsonPath('totals.erp_expenses.amount', 30000);

    // Marzo: la fila del 28-mar cargada en P04 entra a marzo y se informa en auditoría.
    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/calendar-months/2026-03')
        ->assertOk()
        ->assertJsonPath('totals.placement.amount', 1000)
        ->assertJsonPath('totals.erp_expenses.amount', 500)
        ->assertJsonPath('audit.outside_own_period_window.erp_expenses.rows', 1);
});

it('lists the available natural months with their coverage', function () {
    nmScenario();
    $token = nmToken(['financial.read']);

    $res = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/calendar-months')->assertOk();
    $months = collect($res->json('data'))->keyBy('month');

    expect($months->keys()->all())->toBe(['2026-05', '2026-04', '2026-03'])
        ->and($months['2026-04']['status'])->toBe('complete')
        ->and($months['2026-05']['status'])->toBe('partial');
});

it('requires employees.read for the employees block and validates the month and include', function () {
    nmScenario();
    $token = nmToken(['financial.read']);

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/calendar-months/2026-04?include=employees')->assertForbidden();
    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/calendar-months/2026-13')->assertStatus(422);
    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/calendar-months/2026-04?include=nada')->assertStatus(422);
});

it('includes the employees block by default when the token has employees.read', function () {
    nmScenario();
    $full = nmToken(['financial.read', 'employees.read']);
    $this->withHeader('Authorization', "Bearer {$full}")->getJson('/api/v1/calendar-months/2026-04')
        ->assertOk()
        ->assertJsonStructure(['employees' => ['data', 'unidentified', 'missing_periods']]);
});

it('limits a branch-restricted consumer to its own branches', function () {
    ['tula' => $tula, 'atlixco' => $atlixco] = nmScenario();
    $token = nmToken(['financial.read'], ApiConsumer::BRANCH_ACCESS_RESTRICTED, [$tula->id]);

    $res = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/calendar-months/2026-04')->assertOk();

    expect(collect($res->json('branches.data'))->pluck('branch_id')->all())->toBe([$tula->id])
        ->and($res->json('totals.placement.amount'))->toEqual(6000)
        ->and($res->json('branches.unassigned'))->toBeNull()
        ->and($res->json('bank_movements'))->toBeNull()
        ->and($res->json('reconciliation'))->toBeNull()
        ->and($res->json('daily'))->toBeNull();
});

it('rejects tokens without financial.read', function () {
    $token = nmToken(['periods.read']);

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/calendar-months/2026-04')->assertForbidden();
});
