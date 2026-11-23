<?php

use App\Jobs\UpdatePeriodDatabaseJob;
use App\Models\Period;
use App\Models\PeriodDatabaseUpdateRun;
use App\Models\User;
use App\Services\DatabaseUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/**
 * Doble clic en «Actualizar BD»: el segundo envío no crea otro run ni encola otro
 * job; y una re-entrega del job (retry_after vencido) sobre un run que ya está en
 * 'running' no vuelve a procesar el periodo.
 */
function dbuPeriod(): Period
{
    return Period::query()->create([
        'name' => 'Mes doble envío', 'code' => 'M-DBL-01', 'type' => 'monthly', 'year' => 2026, 'month' => 6,
        'sequence' => 1, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => false,
    ]);
}

it('el doble envío de Actualizar BD crea un solo run y encola un solo job', function () {
    Queue::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $period = dbuPeriod();

    $this->actingAs($admin)->post(route('historico-general.update-database', $period))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $this->actingAs($admin)->post(route('historico-general.update-database', $period))
        ->assertSessionHasErrors(['period' => 'Ya hay una carga en proceso o en cola para este periodo.']);

    expect(PeriodDatabaseUpdateRun::query()->where('period_id', $period->id)->count())->toBe(1);
    Queue::assertPushed(UpdatePeriodDatabaseJob::class, 1);
});

it('una re-entrega del job sobre un run que ya está en running no lo procesa de nuevo', function () {
    Mail::fake();
    $period = dbuPeriod();
    $startedAt = now()->subMinutes(5)->startOfSecond();
    $run = PeriodDatabaseUpdateRun::query()->create([
        'period_id' => $period->id, 'status' => 'running', 'queued_at' => now()->subMinutes(6), 'started_at' => $startedAt,
        'log' => 'Procesando en otro worker.',
    ]);

    $this->mock(DatabaseUpdateService::class, fn ($mock) => $mock->shouldNotReceive('updateForPeriod'));

    app()->call([new UpdatePeriodDatabaseJob($period->id, $run->id, null), 'handle']);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe('running');
    expect($fresh->log)->toBe('Procesando en otro worker.');
    expect($fresh->started_at->equalTo($startedAt))->toBeTrue();
});
