<?php

use App\Enums\MatchType;
use App\Enums\SourceType;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\EmployeePeriodManualExpense;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\EmployeePeriodManualExpenseService;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Auditoría 07-sep-2026 (frente 4) — "Gasto general por gestor" persistente por
 * (period_id, employee_id). Fuente única para Web/Excel/PDF — ver
 * EmployeePeriodManualExpenseService y RadiographySnapshotBuilder::
 * buildEmployeeExpenseDetail().
 */
function makeManualExpPeriodo(): Period
{
    static $seq = 0;
    $seq++;
    $month = (($seq - 1) % 12) + 1;

    return Period::query()->create([
        'name' => "Periodo manual-exp {$seq}", 'code' => "M-MANEXP-2026-{$month}-{$seq}", 'type' => 'monthly',
        'year' => 2026, 'month' => $month, 'sequence' => 1,
        'start_date' => sprintf('2026-%02d-01', $month), 'end_date' => sprintf('2026-%02d-28', $month), 'is_closed' => false,
    ]);
}

function makeManualExpBranch(string $name): Branch
{
    return Branch::query()->firstOrCreate(
        ['normalized_name' => mb_strtolower($name)],
        ['code' => mb_substr(strtoupper($name), 0, 4), 'name' => strtoupper($name), 'is_active' => true],
    );
}

function makeManualExpEmployee(Period $period, string $fullName, Branch $branch): Employee
{
    static $seq = 0;
    $seq++;
    $parts = explode(' ', $fullName);

    $employee = Employee::query()->create([
        'employee_code' => 'MANEXP' . $seq, 'full_name' => $fullName, 'normalized_name' => mb_strtolower($fullName),
        'first_name' => $parts[0] ?? $fullName, 'paternal_last_name' => $parts[1] ?? 'X',
        'is_active' => true, 'source_system' => 'noi',
    ]);

    DB::table('fact_noi_movements')->insert([
        'period_id' => $period->id, 'employee_id' => $employee->id, 'amount' => 1000, 'quantity' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    EmployeeBranchAssignment::query()->create([
        'employee_id' => $employee->id, 'period_id' => $period->id, 'branch_id' => $branch->id,
        'source_type' => SourceType::Manual, 'match_type' => MatchType::Manual,
    ]);

    return $employee;
}

// ── D) Guardar 2 veces el mismo period+employee: UNA fila, gana el último valor ──
it('upserting the same period+employee twice results in exactly one row with the latest amount', function () {
    $period = makeManualExpPeriodo();
    $branch = makeManualExpBranch('Tula');
    $employee = makeManualExpEmployee($period, 'GESTOR MANUAL UNO', $branch);

    $service = app(EmployeePeriodManualExpenseService::class);
    $service->upsert($period->id, $employee->id, 1500.0, 'Primera nota');
    $service->upsert($period->id, $employee->id, 1700.0, 'Nota actualizada');

    $rows = EmployeePeriodManualExpense::query()->where('period_id', $period->id)->where('employee_id', $employee->id)->get();

    expect($rows)->toHaveCount(1);
    expect((float) $rows->first()->amount)->toBe(1700.0);
    expect($rows->first()->notes)->toBe('Nota actualizada');

    $read = $service->getForPeriodEmployee($period->id, $employee->id);
    expect($read['amount'])->toBe(1700.0);
});

it('never creates a second row for a different employee in the same period', function () {
    $period = makeManualExpPeriodo();
    $branch = makeManualExpBranch('Cuernavaca');
    $e1 = makeManualExpEmployee($period, 'GESTOR MANUAL A', $branch);
    $e2 = makeManualExpEmployee($period, 'GESTOR MANUAL B', $branch);

    $service = app(EmployeePeriodManualExpenseService::class);
    $service->upsert($period->id, $e1->id, 1000.0, 'A');
    $service->upsert($period->id, $e2->id, 2000.0, 'B');

    expect(EmployeePeriodManualExpense::query()->where('period_id', $period->id)->count())->toBe(2);
    expect($service->getForPeriodEmployee($period->id, $e1->id)['amount'])->toBe(1000.0);
    expect($service->getForPeriodEmployee($period->id, $e2->id)['amount'])->toBe(2000.0);
});

// ── HTTP: GET carga el valor guardado, POST guarda y devuelve el detalle recalculado ──
it('the HTTP endpoint loads the saved value and store() persists + returns the recalculated detail', function () {
    $user = User::factory()->create();
    $period = makeManualExpPeriodo();
    $branch = makeManualExpBranch('Huamantla');
    $employee = makeManualExpEmployee($period, 'GESTOR HTTP UNO', $branch);

    $this->actingAs($user)
        ->getJson(route('historico-general.colaboradores.gasto-manual.show', [$period->id, $employee->id]))
        ->assertOk()
        ->assertJson(['amount' => 0, 'notes' => '']);

    $this->actingAs($user)
        ->postJson(route('historico-general.colaboradores.gasto-manual.store', [$period->id, $employee->id]), [
            'amount' => 1234.56, 'notes' => 'Viáticos de la zona',
        ])
        ->assertOk()
        ->assertJsonPath('saved.amount', 1234.56)
        ->assertJsonPath('detail.manual_total', 1234.56)
        ->assertJsonPath('detail.manual_notes', 'Viáticos de la zona');

    $this->actingAs($user)
        ->getJson(route('historico-general.colaboradores.gasto-manual.show', [$period->id, $employee->id]))
        ->assertOk()
        ->assertJson(['amount' => 1234.56, 'notes' => 'Viáticos de la zona']);

    expect(EmployeePeriodManualExpense::query()->where('period_id', $period->id)->where('employee_id', $employee->id)->count())->toBe(1);
});

// ── I) Caché: guardar invalida determinísticamente la única caché financiera del sistema ──
// NOTA DE COBERTURA (mismo motivo documentado en RadiographyScopeTest.php y
// PeriodIncidentLongMessageTest.php): la suite corre sobre sqlite, que no soporta
// REGEXP/JSON_EXTRACT/JSON_UNQUOTE de MySQL usadas en otros acumuladores de
// RadiographySnapshotBuilder::build() — así que no se puede invocar
// RadiografiaExportService::buildSnapshot() end-to-end aquí. Se prueba
// directamente el MECANISMO real de invalidación que usa: la key de
// buildSnapshotCached() incluye PeriodSummary->updated_at (ver
// RadiografiaExportService::buildSnapshotCached()), y upsert() debe modificar
// ese timestamp — si lo hace, Cache::remember() nunca puede servir el resultado
// viejo en la siguiente lectura, sin necesidad de Cache::forget() ni tocar el
// mecanismo de caché en sí.
it('saving a manual expense touches the PeriodSummary — the exact mechanism buildSnapshotCached() relies on to invalidate', function () {
    $period = makeManualExpPeriodo();
    $branch = makeManualExpBranch('Orizaba');
    $employee = makeManualExpEmployee($period, 'GESTOR CACHE UNO', $branch);

    $summary = PeriodSummary::query()->create([
        'period_id' => $period->id, 'status' => 'generated', 'generated_at' => now(),
        'global_metrics' => [], 'updated_at' => now()->subMinutes(10),
    ]);
    $timestampAntes = $summary->updated_at->timestamp;

    // Duerme un segundo real para que el timestamp (resolución de segundo en
    // MySQL/timestamps) cambie de forma observable.
    \Illuminate\Support\Carbon::setTestNow(now()->addSeconds(2));

    app(EmployeePeriodManualExpenseService::class)->upsert($period->id, $employee->id, 950.0, 'Gasolina y viáticos');

    \Illuminate\Support\Carbon::setTestNow();

    $timestampDespues = $summary->fresh()->updated_at->timestamp;
    expect($timestampDespues)->toBeGreaterThan($timestampAntes);
});

// Complementa la prueba anterior probando el efecto real de negocio (aunque no
// pase por Cache::remember en este entorno): dos lecturas de
// buildEmployeeExpenseDetail() — la fuente que consume Web/Excel/PDF — antes y
// después de guardar, sin limpiar nada manualmente, reflejan el valor nuevo.
it('two reads of buildEmployeeExpenseDetail — before and after saving — reflect the fresh value with no manual cache clear', function () {
    $period = makeManualExpPeriodo();
    $branch = makeManualExpBranch('Tlaxcala');
    $employee = makeManualExpEmployee($period, 'GESTOR CACHE DOS', $branch);

    $builder = app(RadiographySnapshotBuilder::class);
    $builder->findEmployeeGestorRowByEmployeeId($period, $employee->id);

    $before = $builder->buildEmployeeExpenseDetail([$employee->id], $period->id, $employee->id);
    expect($before['manual_total'])->toBe(0.0);

    app(EmployeePeriodManualExpenseService::class)->upsert($period->id, $employee->id, 950.0, 'Gasolina y viáticos');

    $after = $builder->buildEmployeeExpenseDetail([$employee->id], $period->id, $employee->id);
    expect($after['manual_total'])->toBe(950.0);
});
