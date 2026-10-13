<?php

use App\Jobs\GenerateEfectividadCobranzaAprobadaJob;
use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EfectividadV4Fixtures as V4;

uses(RefreshDatabase::class);

/**
 * Fase 4 (rediseño premium) — cancelación real de Efectividad de Cobranza.
 *
 * El botón "Cancelar generación" nunca existió antes de esta sesión: un Job
 * atascado no tenía forma de detenerse. Esto prueba las dos mitades del
 * contrato: (1) la petición HTTP solo marca la solicitud de forma atómica y
 * autorizada, nunca cambia el estado ella misma; (2) el propio Job, en sus
 * puntos de cancelación cooperativa, es quien cierra la ejecución.
 */
function cancellationTestPeriodo(): Period
{
    return V4::week();
}

function activeRunFor(Period $period, string $status = EfectividadCobranzaRun::STATUS_RUNNING, ?int $createdBy = null): EfectividadCobranzaRun
{
    return EfectividadCobranzaRun::query()->create([
        'period_id' => $period->id, 'branch_id' => null, 'status' => $status,
        'engine_version' => 'mrlana-v4-2026-10', 'mode' => 'SEMANAL',
        'base_date' => '2026-10-04', 'cutoff_date' => '2026-10-11',
        'queued_at' => now(), 'started_at' => $status === EfectividadCobranzaRun::STATUS_QUEUED ? null : now(),
        'heartbeat_at' => now(), 'log' => 'Procesando…', 'created_by' => $createdBy,
    ]);
}

it('the requester can cancel an active run: only marks the request, does not change status itself', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = cancellationTestPeriodo();
    $run = activeRunFor($period, EfectividadCobranzaRun::STATUS_RUNNING, $admin->id);

    $response = $this->actingAs($admin)->postJson("/efectividad-cobranza/runs/{$run->id}/cancelar");

    $response->assertOk();
    expect($response->json('message'))->toContain('Se solicitó detener la generación');
    expect($response->json('run.can_cancel'))->toBeFalse();
    expect($response->json('run.display_state'))->toBe('cancelling');

    $run->refresh();
    expect($run->status)->toBe(EfectividadCobranzaRun::STATUS_RUNNING) // el Job, no el controlador, cierra el estado
        ->and($run->cancel_requested_at)->not->toBeNull()
        ->and($run->cancelled_by)->toBe($admin->id)
        ->and($run->isActive())->toBeTrue();
});

it('cancelling twice is idempotent and never errors nor overwrites the original requester/timestamp', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $other = User::factory()->create(['role' => 'admin']);
    $period = cancellationTestPeriodo();
    $run = activeRunFor($period, EfectividadCobranzaRun::STATUS_RUNNING, $admin->id);

    $this->actingAs($admin)->postJson("/efectividad-cobranza/runs/{$run->id}/cancelar")->assertOk();
    $firstRequestedAt = $run->refresh()->cancel_requested_at;

    $second = $this->actingAs($other)->postJson("/efectividad-cobranza/runs/{$run->id}/cancelar");
    $second->assertOk();

    $run->refresh();
    expect($run->cancelled_by)->toBe($admin->id) // el primero, no se pisa
        ->and($run->cancel_requested_at->equalTo($firstRequestedAt))->toBeTrue();
});

it('cancelling a run that already finished returns 409 and changes nothing', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = cancellationTestPeriodo();
    $run = activeRunFor($period, EfectividadCobranzaRun::STATUS_SUCCESS, $admin->id);

    $response = $this->actingAs($admin)->postJson("/efectividad-cobranza/runs/{$run->id}/cancelar");

    $response->assertStatus(409);
    expect($run->refresh()->cancel_requested_at)->toBeNull();
});

it('a colaborador (non-managerial role) gets 403, same as the rest of the module', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $colaborador = User::factory()->create(['role' => 'colaborador']);
    $period = cancellationTestPeriodo();
    $run = activeRunFor($period, EfectividadCobranzaRun::STATUS_RUNNING, $admin->id);

    $this->actingAs($colaborador)->postJson("/efectividad-cobranza/runs/{$run->id}/cancelar")->assertForbidden();
    expect($run->refresh()->cancel_requested_at)->toBeNull();
});

it('a gerencial user who neither requested nor is subscribed gets 404 on someone else\'s run', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $gerencial = User::factory()->create(['role' => 'gerencial']);
    $period = cancellationTestPeriodo();
    $run = activeRunFor($period, EfectividadCobranzaRun::STATUS_RUNNING, $admin->id);

    $this->actingAs($gerencial)->postJson("/efectividad-cobranza/runs/{$run->id}/cancelar")->assertNotFound();
    expect($run->refresh()->cancel_requested_at)->toBeNull();
});

it('the Job closes a run as cancelled immediately when cancellation was requested before it started', function () {
    $period = cancellationTestPeriodo();
    $run = activeRunFor($period, EfectividadCobranzaRun::STATUS_QUEUED);
    $run->forceFill(['cancel_requested_at' => now(), 'cancelled_by' => 1])->save();

    app()->call([new GenerateEfectividadCobranzaAprobadaJob($run->id), 'handle']);

    $run->refresh();
    expect($run->status)->toBe(EfectividadCobranzaRun::STATUS_CANCELLED)
        ->and($run->cancelled_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->output_excel_path)->toBeNull()
        ->and($run->result_rows)->toBe([]);
});

it('the Job honors a cancellation requested mid-run at its next cooperative checkpoint (never a retry, never success)', function () {
    $period = cancellationTestPeriodo();
    V4::branch();
    V4::seedValid($period, V4::branch());
    $run = activeRunFor($period, EfectividadCobranzaRun::STATUS_RUNNING);

    // Simula: la solicitud llegó mientras el worker ya estaba trabajando (después
    // de "validate_files"/"verify_columns", antes del siguiente punto seguro).
    $run->forceFill(['cancel_requested_at' => now()->subSecond(), 'cancelled_by' => 1])->save();

    app()->call([new GenerateEfectividadCobranzaAprobadaJob($run->id), 'handle']);

    $run->refresh();
    expect($run->status)->toBe(EfectividadCobranzaRun::STATUS_CANCELLED)
        ->and($run->status)->not->toBe(EfectividadCobranzaRun::STATUS_RETRYING)
        ->and($run->status)->not->toBe(EfectividadCobranzaRun::STATUS_SUCCESS)
        ->and($run->output_excel_path)->toBeNull();
});

it('failed() closes the run as cancelled (not failed) when cancellation was already requested', function () {
    $period = cancellationTestPeriodo();
    $run = activeRunFor($period, EfectividadCobranzaRun::STATUS_RUNNING);
    $run->forceFill(['cancel_requested_at' => now(), 'cancelled_by' => 1])->save();

    $job = new GenerateEfectividadCobranzaAprobadaJob($run->id);
    $job->failed(new RuntimeException('El worker se quedó sin memoria.'));

    $run->refresh();
    expect($run->status)->toBe(EfectividadCobranzaRun::STATUS_CANCELLED)
        ->and($run->failure_kind)->toBeNull()
        ->and($run->error_message)->toBeNull();
});

it('a cancelled run shows up in the history filter and never as success/failed', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = cancellationTestPeriodo();
    $run = activeRunFor($period, EfectividadCobranzaRun::STATUS_RUNNING, $admin->id);
    $run->forceFill([
        'status' => EfectividadCobranzaRun::STATUS_CANCELLED,
        'cancel_requested_at' => now()->subMinute(),
        'cancelled_at' => now(),
        'finished_at' => now(),
    ])->save();

    $response = $this->actingAs($admin)->get('/efectividad-cobranza?f_status=cancelled');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('history.data.0.id', $run->id)
        ->where('history.data.0.status', 'cancelled')
        ->where('history.data.0.display_state', 'cancelled'));
});
