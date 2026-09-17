<?php

use App\Models\Period;
use App\Models\User;

uses(Tests\Integration\UsesRealDevDatabase::class);

/**
 * Cierre 17-sep-2026, ronda 4 — bug real confirmado por inspección directa contra la
 * BD real: "Asignación de sucursales" (ahora también expuesta como "Colaboradores")
 * calculaba altas/bajas comparando employee_id CRUDOS entre "el periodo anterior por
 * fecha" (sin filtrar type='monthly') — para Junio 2026 esto comparaba contra una
 * SEMANA de Mayo (un universo de asignaciones totalmente distinto), mostrando 132
 * "altas" y 0 "bajas" en vez de las 5/5 reales que ya calcula el roster canónico
 * (period_employee_rosters, misma fuente que el Índice de Rotación de OKR).
 *
 * Este test ancla que la pantalla ahora usa esa MISMA fuente canónica — nunca vuelve
 * a calcular su propio diff crudo de employee_id.
 */
beforeEach(function () {
    $this->useRealDevDatabaseOrSkip();

    $this->user = User::query()->first();
    if (!$this->user) {
        $this->markTestSkipped('No hay ningún usuario en la BD de desarrollo para autenticar la request.');
    }

    $this->period = Period::find(21) ?? Period::query()->where('type', 'monthly')->orderByDesc('id')->first();
    if (!$this->period) {
        $this->markTestSkipped('No hay periodos mensuales en esta BD.');
    }

    $hasRoster = DB::table('period_employee_rosters')->where('period_id', $this->period->id)->exists();
    if (!$hasRoster) {
        $this->markTestSkipped("El periodo {$this->period->label} no tiene roster calculado (period_employee_rosters vacío) — corre Actualizar BD primero.");
    }
});

it('reports the SAME hires/leavers count as period_employee_rosters — never its own raw employee_id diff', function () {
    $expectedHires  = DB::table('period_employee_rosters')->where('period_id', $this->period->id)->where('movement_type', 'alta')->count();
    $expectedLeavers = DB::table('period_employee_rosters')->where('period_id', $this->period->id)->where('movement_type', 'baja')->count();
    $expectedPlantilla = DB::table('period_employee_rosters')->where('period_id', $this->period->id)->where('is_active_for_period', true)->count();

    $response = $this->actingAs($this->user)->get(route('asignaciones-empleado-sucursal.index', ['period_id' => $this->period->id]));

    $response->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['summary']['hires'])->toBe($expectedHires);
    expect($props['summary']['leavers'])->toBe($expectedLeavers);
    expect($props['summary']['plantilla'])->toBe($expectedPlantilla);
    expect($props['summary']['roster_calculado'])->toBeTrue();

    // Nunca cientos de "altas" falsas por comparar contra un periodo semanal —
    // el bug real producía 132 para Junio 2026 en este mismo dev DB.
    expect($props['summary']['hires'])->toBeLessThan(50);
});

it('resolves the previous period as the previous MONTHLY period, never a weekly period by raw date', function () {
    $allMonthly = Period::where('type', 'monthly')->get(['id', 'name', 'code', 'type', 'year', 'month', 'sequence', 'start_date', 'end_date']);
    $expectedPrev = $this->period->previousMonthly($allMonthly);

    if (!$expectedPrev) {
        $this->markTestSkipped("No hay un periodo mensual anterior a {$this->period->label} en esta BD.");
    }

    $response = $this->actingAs($this->user)->get(route('asignaciones-empleado-sucursal.index', ['period_id' => $this->period->id]));
    $response->assertOk();
    $props = $response->viewData('page')['props'];

    $leavers = collect($props['leavers']);
    if ($leavers->isNotEmpty()) {
        expect($leavers->first()['period_label'])->toBe($expectedPrev->label);
    }
});
