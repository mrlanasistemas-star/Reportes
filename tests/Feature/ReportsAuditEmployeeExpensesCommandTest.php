<?php

use App\Models\Period;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Auditoría 07-sep-2026 (cierre, punto 16/21-M) — reports:audit-employee-expenses.
 * Reutiliza los helpers globales de ReportsAuditExpenseAttributionCommandTest
 * (makeCmdPeriodo/makeCmdBranch/makeCmdRosterEmployee/makeCmdUpload/makeCmdExpense)
 * — Pest los carga como funciones globales para toda la suite.
 */
it('reports zero difference when the DB already matches what the matcher proposes', function () {
    $period = makeCmdPeriodo();
    $upload = makeCmdUpload($period);
    $branch = makeCmdBranch('Tepic');
    $employee = makeCmdRosterEmployee($period, 'LAURA PATRICIA GOMEZ RUIZ', $branch);

    // Ya correctamente atribuido — el matcher propondría lo mismo que ya hay en BD.
    makeCmdExpense($period, $upload, [
        'observations' => 'LAURA PATRICIA GOMEZ RUIZ', 'employee_id' => $employee->id, 'branch_id' => $branch->id,
    ]);

    $this->artisan('reports:audit-employee-expenses', ['period' => $period->id])
        ->expectsOutputToContain('CON DIFERENCIA: 0')
        ->assertExitCode(0);
});

it('reports a real difference (Bryan-shaped case) when a row is misattributed and lists its fact_expense_id', function () {
    $period = makeCmdPeriodo();
    $upload = makeCmdUpload($period);
    $branch = makeCmdBranch('Pachuca');
    $correct = makeCmdRosterEmployee($period, 'BRYAN ADAMS MEJIA', $branch);
    $wrong   = makeCmdRosterEmployee($period, 'SANTA DEL SAGRARIO CEBALLOS OCHOA', $branch);

    // Observación nombra a BRYAN pero employee_id (heredado del PDF) apunta a SANTA
    // (el "solicitante" administrativo) — el matcher debe proponer BRYAN.
    $misattributed = makeCmdExpense($period, $upload, [
        'observations' => 'BRYAN ADAMS MEJIA', 'employee_id' => $wrong->id, 'branch_id' => $branch->id,
        'amount' => 200.0, 'paid_amount' => 200.0,
    ]);

    // Una misatribución muestra diferencia en AMBOS lados: Bryan (le falta el gasto
    // que sí le pertenece) y Santa (tiene atribuido un gasto que no es suyo) — 2, no 1.
    $this->artisan('reports:audit-employee-expenses', ['period' => $period->id])
        ->expectsOutputToContain('CON DIFERENCIA: 2')
        ->assertExitCode(0);

    // Nunca escribe — el comando es dry-run puro.
    expect($misattributed->fresh()->employee_id)->toBe($wrong->id);
});

it('never writes to fact_expenses — dry-run only', function () {
    $period = makeCmdPeriodo();
    $upload = makeCmdUpload($period);
    $branch = makeCmdBranch('Celaya');
    $employee = makeCmdRosterEmployee($period, 'RAUL ANTONIO SOTO PEREZ', $branch);
    makeCmdExpense($period, $upload, ['observations' => 'RAUL ANTONIO SOTO PEREZ', 'branch_id' => $branch->id]);

    $countAntes = DB::table('fact_expenses')->where('period_id', $period->id)->count();
    $sumaAntes  = DB::table('fact_expenses')->where('period_id', $period->id)->sum('paid_amount');

    $this->artisan('reports:audit-employee-expenses', ['period' => $period->id])->assertExitCode(0);

    expect(DB::table('fact_expenses')->where('period_id', $period->id)->count())->toBe($countAntes);
    expect((float) DB::table('fact_expenses')->where('period_id', $period->id)->sum('paid_amount'))->toBe((float) $sumaAntes);
});

it('the --only-diff flag hides collaborators with zero difference', function () {
    $period = makeCmdPeriodo();
    $upload = makeCmdUpload($period);
    $branch = makeCmdBranch('Irapuato');
    $ok = makeCmdRosterEmployee($period, 'MONICA ELENA VARGAS DIAZ', $branch);
    // Ya correctamente atribuido — diferencia = 0, así que --only-diff debe ocultarla
    // de la tabla (aunque sigue contando en el resumen de abajo).
    makeCmdExpense($period, $upload, ['observations' => 'MONICA ELENA VARGAS DIAZ', 'employee_id' => $ok->id, 'branch_id' => $branch->id]);

    $this->artisan('reports:audit-employee-expenses', ['period' => $period->id, '--only-diff' => true])
        ->doesntExpectOutputToContain('MONICA')
        ->expectsOutputToContain('CON DIFERENCIA: 0')
        ->assertExitCode(0);
});
