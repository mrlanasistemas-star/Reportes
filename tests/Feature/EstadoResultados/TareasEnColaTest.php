<?php

use App\Jobs\RunEstadoResultadosTaskJob;
use App\Models\EstadoResultados\EstadoResultadosTask;
use App\Models\EstadoResultados\EstadoResultadosVersion;
use App\Models\User;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * 23-nov-2026 — «Procesar conciliación» y «Aprobar» corren en cola (RunEstadoResultadosTaskJob)
 * con estado consultable. Con worker real la petición responde 202 y la pantalla consulta
 * /tareas/{id}; el resultado es el MISMO que daba la petición síncrona.
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
    F::fakeRadiography();
    G::fakePdfRenderer();
});

it('procesar con worker: responde 202, un doble clic no encola otra tarea y al terminar se consulta el resultado', function () {
    Queue::fake();
    $period = F::period();
    G::import($period, F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO']]));
    $admin = G::admin();

    $res = $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/procesar")
        ->assertStatus(202)->assertJsonPath('queued', true)->assertJsonPath('task.status', 'queued');
    $taskId = (int) $res->json('task.id');

    // Doble clic / segunda pestaña: 409 con la MISMA tarea abierta (la pantalla se engancha a ella).
    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/procesar")
        ->assertStatus(409)->assertJsonPath('processing', true)->assertJsonPath('task.id', $taskId);
    expect(EstadoResultadosTask::query()->count())->toBe(1);
    Queue::assertPushed(RunEstadoResultadosTaskJob::class, 1);

    $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/tareas")->assertOk()->assertJsonPath('tasks.0.id', $taskId);

    app()->call([new RunEstadoResultadosTaskJob($taskId), 'handle']);

    $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/tareas/{$taskId}")->assertOk()
        ->assertJsonPath('task.finished', true)
        ->assertJsonPath('task.status', 'ok')
        ->assertJsonPath('task.http_status', 200)
        ->assertJsonPath('task.response.message', 'Conciliación procesada.')
        ->assertJsonPath('task.response.summary.movimientos', 1);

    // Re-entrega del mismo job: no vuelve a procesar.
    $runs = DB::table('estado_resultados_runs')->count();
    app()->call([new RunEstadoResultadosTaskJob($taskId), 'handle']);
    expect(DB::table('estado_resultados_runs')->count())->toBe($runs);
});

it('aprobar con worker: la versión aprobada en cola congela exactamente el dataset revisado', function () {
    Queue::fake();
    $period = G::readyPeriod();
    $admin = G::admin();
    $hash = app(EstadoResultadosDatasetBuilder::class)->build($period)['meta']['dataset_hash'];

    $taskId = (int) $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/aprobar", ['notes' => 'Cierre', 'expected_hash' => $hash])
        ->assertStatus(202)->json('task.id');
    expect(EstadoResultadosVersion::query()->count())->toBe(0);

    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/aprobar")
        ->assertStatus(409)->assertJsonPath('conflict', 'aprobacion_en_curso');

    app()->call([new RunEstadoResultadosTaskJob($taskId), 'handle']);

    $task = EstadoResultadosTask::query()->findOrFail($taskId);
    expect($task->status)->toBe('ok')
        ->and($task->http_status)->toBe(200)
        ->and($task->response['version']['version'])->toBe(1)
        ->and(EstadoResultadosVersion::query()->firstOrFail()->dataset_hash)->toBe($hash)
        ->and(EstadoResultadosVersion::query()->firstOrFail()->approval_notes)->toBe('Cierre');
});

it('un rechazo en cola conserva el código y el cuerpo de la respuesta síncrona (hash revisado → 409)', function () {
    Queue::fake();
    $period = G::readyPeriod();
    $taskId = (int) $this->actingAs(G::admin())->postJson("/estado-resultados/{$period->id}/aprobar", ['expected_hash' => str_repeat('a', 64)])
        ->assertStatus(202)->json('task.id');

    app()->call([new RunEstadoResultadosTaskJob($taskId), 'handle']);

    $this->actingAs(G::admin())->getJson("/estado-resultados/{$period->id}/tareas/{$taskId}")->assertOk()
        ->assertJsonPath('task.status', 'error')
        ->assertJsonPath('task.http_status', 409)
        ->assertJsonPath('task.response.conflict', 'hash_revisado');
    expect(EstadoResultadosVersion::query()->count())->toBe(0);
});

it('una tarea abandonada (worker caído) deja de bloquear y se cierra con aviso', function () {
    Queue::fake();
    $period = F::period();
    $old = EstadoResultadosTask::query()->create([
        'period_id' => $period->id, 'kind' => 'procesar', 'status' => 'running', 'queued_at' => now()->subHours(3), 'started_at' => now()->subHours(3),
    ]);

    $this->actingAs(G::admin())->postJson("/estado-resultados/{$period->id}/procesar")->assertStatus(202);

    $old->refresh();
    expect($old->status)->toBe('error')->and($old->http_status)->toBe(500)->and($old->finished_at)->not->toBeNull();
});

it('sin alcance general no se consulta el estado de las tareas', function () {
    $period = F::period();
    $task = EstadoResultadosTask::query()->create(['period_id' => $period->id, 'kind' => 'procesar', 'status' => 'queued', 'queued_at' => now()]);
    $user = G::userWith(['procesar']);
    $user->forceFill(['branch_scope' => 'branches'])->save();
    DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => F::branch('CORDOBA')->id, 'created_at' => now(), 'updated_at' => now()]);
    $user = User::query()->find($user->id);

    $this->actingAs($user)->getJson("/estado-resultados/{$period->id}/tareas/{$task->id}")->assertForbidden();
});
