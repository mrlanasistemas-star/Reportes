<?php

use App\Jobs\GenerateRadiographyJob;
use App\Models\Period;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\EmployeeBranchAutoMatchService;
use App\Services\ExpenseObservationAttributionService;
use App\Services\FinanciamientoMotosAssignmentService;
use App\Services\GastosExcelBranchResolverService;
use App\Services\PeriodConsolidationService;
use App\Services\PeriodRadiographyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regeneración rápida (23-nov-2026): «Actualizar reporte con clasificaciones» recalcula
 * un reporte YA generado con las decisiones de la revisión de gastos, sin repetir la
 * revisión de asignaciones ni las atribuciones automáticas (los registros no cambiaron).
 */
function qrPeriod(): Period
{
    return Period::query()->create([
        'name' => 'Abril 2026', 'code' => 'M-QR-04', 'type' => 'monthly', 'year' => 2026, 'month' => 4, 'sequence' => 1,
        'start_date' => '2026-03-30', 'end_date' => '2026-04-26', 'is_closed' => false, 'component_period_ids' => [],
    ]);
}

it('rejects the quick update when the report was never generated (and queues nothing)', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $period = qrPeriod();

    $this->actingAs($user)
        ->post("/historico-general/{$period->id}/generar-radiografia", [
            'config' => ['report_type' => 'simple', 'scope' => 'general', 'regeneracion_rapida' => true],
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(PeriodRadiographyRun::query()->where('period_id', $period->id)->count())->toBe(0);
});

it('skips branch auto-match and automatic attributions in quick mode, and never passes the flag to the calculation', function () {
    $period = qrPeriod();
    $run = PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'report_type' => 'simple', 'scope' => 'general', 'status' => 'queued', 'queued_at' => now(),
        'metadata' => ['config' => ['report_type' => 'simple', 'scope' => 'general', 'regeneracion_rapida' => true]],
    ]);
    $summary = PeriodSummary::query()->create(['period_id' => $period->id, 'status' => 'generated', 'generated_at' => now(), 'global_metrics' => []]);

    $radiography = Mockery::mock(PeriodRadiographyService::class);
    $radiography->shouldReceive('generate')->once()
        ->withArgs(fn ($p, $u, array $config) => ! array_key_exists('regeneracion_rapida', $config))
        ->andReturn($summary);
    app()->instance(PeriodRadiographyService::class, $radiography);

    foreach ([EmployeeBranchAutoMatchService::class, GastosExcelBranchResolverService::class, FinanciamientoMotosAssignmentService::class, ExpenseObservationAttributionService::class] as $class) {
        $mock = Mockery::mock($class);
        $mock->shouldNotReceive('handle', 'resolveForPeriod', 'assignForPeriod', 'attributeForPeriod');
        app()->instance($class, $mock);
    }

    // Se detiene a propósito en la consolidación: lo que importa es que llegó hasta aquí
    // sin pasar por las asignaciones/atribuciones automáticas.
    $consolidation = Mockery::mock(PeriodConsolidationService::class);
    $consolidation->shouldReceive('consolidate')->once()->andThrow(new RuntimeException('alto de prueba'));
    app()->instance(PeriodConsolidationService::class, $consolidation);

    $job = new GenerateRadiographyJob($period->id, null, $run->id, $run->metadata['config']);
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'alto de prueba');

    expect($job->config)->not->toHaveKey('regeneracion_rapida')
        ->and($run->fresh()->status)->toBe('failed');
});
