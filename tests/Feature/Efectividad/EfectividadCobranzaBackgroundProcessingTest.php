<?php

use App\Jobs\GenerateEfectividadCobranzaAprobadaJob;
use App\Mail\EfectividadCobranzaRunMail;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use App\Models\Portfolio;
use App\Models\Recovery;
use App\Models\ReportUpload;
use App\Models\User;
use App\Notifications\EfectividadCobranzaRunFinished;
use App\Services\Efectividad\V4\EfectividadV4Calculator;
use App\Services\Efectividad\EfectividadCobranzaIssue;
use App\Services\Efectividad\EfectividadCobranzaRunHealthService;
use App\Services\Efectividad\EfectividadCobranzaRunNotifier;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Support\EfectividadV4Fixtures as V4;

uses(RefreshDatabase::class);

/**
 * Procesamiento en segundo plano de Efectividad de Cobranza — recorrido real:
 * solicitar → BD → cola → salir → worker sin sesión → volver → estado
 * restaurado → etapas reales → Excel → historial → descarga → notificaciones,
 * más bloqueos por datos/columnas, fallas técnicas, SMTP caído, reintentos
 * idempotentes, trabajos atascados y permisos.
 *
 * Base de pruebas aislada (sqlite :memory:, ver phpunit.xml) y discos falsos:
 * nunca toca la BD real ni envía correos reales (Mail::fake / mailer `array`).
 */
beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

/** Motor V4: la efectividad se calcula por semana operativa (lunes-domingo). */
function bgPeriod(): Period
{
    return V4::week();
}

function bgBranch(): Branch
{
    return V4::branch();
}

/** 8 fotografías diarias + Ingresos de 4 días (ver Tests\Support\EfectividadV4Fixtures). */
function bgSeedValidData(Period $period, Branch $branch): void
{
    V4::seedValid($period, $branch);
}

/** Atributos de una ejecución V4 creada directamente (sin pasar por el controlador). */
function bgV4(array $attrs): array
{
    return $attrs + ['engine_version' => EfectividadV4Calculator::ENGINE_VERSION, 'mode' => 'SEMANAL', 'base_date' => '2026-10-04', 'cutoff_date' => '2026-10-11'];
}

/** Ejecuta el Job tal como lo haría el worker (sin sesión web, sin middleware HTTP). */
function bgRunJob(int $runId, int $attempt = 1): void
{
    $job = (new GenerateEfectividadCobranzaAprobadaJob($runId))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;
    app()->call([$job, 'handle']);
}

function bgUpload(Period $period, string $sourceCode, string $fileName, array $headers, array $rows = []): ReportUpload
{
    $source = DataSource::query()->firstOrCreate(['code' => $sourceCode], ['name' => $sourceCode]);

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Hoja1');
    $sheet->fromArray([$headers, ...$rows]);
    $path = "uploads/{$fileName}";
    Storage::disk('public')->makeDirectory('uploads');
    (new Xlsx($spreadsheet))->save(Storage::disk('public')->path($path));

    return ReportUpload::query()->create([
        'period_id' => $period->id, 'data_source_id' => $source->id,
        'original_name' => $fileName, 'stored_path' => $path,
        'uploaded_at' => now(), 'status' => 'processed',
    ]);
}

it('1-12: solicitud persistente → cola → worker sin sesión → estado restaurado → etapas reales → Excel → historial → descarga → notificaciones', function () {
    Queue::fake();
    Mail::fake();

    $admin = User::factory()->create(['role' => 'admin', 'email' => 'gerencia@example.com']);
    $period = bgPeriod();
    $branch = bgBranch();
    bgSeedValidData($period, $branch);

    // 1-3. Se crea la ejecución, se guarda en BD y el Job queda en cola (no corre en HTTP).
    $response = $this->actingAs($admin)->postJson('/efectividad-cobranza/generar', ['period_id' => $period->id, 'branch_id' => $branch->id]);
    $response->assertOk()->assertJson(['accepted' => true, 'reused' => false, 'status' => 'queued']);
    $runId = $response->json('run_id');

    $run = EfectividadCobranzaRun::query()->findOrFail($runId);
    expect($run->status)->toBe('queued')
        ->and($run->created_by)->toBe($admin->id)
        ->and($run->queued_at)->not->toBeNull()
        ->and($run->started_at)->toBeNull();
    expect($response->json('run.id'))->toBe($runId)
        ->and($response->json('run.display_label'))->toBe('En cola')
        ->and($response->json('run.progress.determinate'))->toBeFalse(); // nunca un % inventado

    Queue::assertPushed(GenerateEfectividadCobranzaAprobadaJob::class, fn ($job) => $job->runId === $runId);

    // 4. El usuario sale del módulo y regresa SIN parámetros en la URL: se restaura.
    $this->actingAs($admin)->get('/okr')->assertSuccessful();
    $this->actingAs($admin)->get('/efectividad-cobranza')->assertOk()->assertInertia(fn ($page) => $page
        ->component('EfectividadCobranza/Index')
        ->where('focusRun.id', $runId)
        ->where('focusRun.status', 'queued')
        ->where('selected.period_id', $period->id)
        ->where('selected.branch_id', $branch->id)
        ->has('activeRuns', 1)
        ->where('activeRuns.0.id', $runId)
        ->where('backgroundActivity.active_count', 1)
    );

    // 5. El worker procesa SIN ninguna sesión web abierta.
    Auth::logout();
    $this->app['auth']->forgetGuards();
    bgRunJob($runId);

    $run->refresh();
    expect($run->status)->toBe('success')
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->heartbeat_at)->not->toBeNull()
        ->and($run->attempts)->toBe(1);

    // 8. Etapas reales registradas, en orden, todas completadas; las medibles con conteos.
    expect(array_keys($run->stages))->toBe([
        'validate_files', 'verify_columns', 'prepare_portfolio', 'process_income',
        'calculate', 'reconcile', 'audit', 'build_excel', 'finish',
    ]);
    expect(collect($run->stages)->pluck('status')->unique()->all())->toBe(['done']);
    // 8 fotografías × 4 contratos leídos; 4 movimientos de ingresos.
    expect($run->stages['prepare_portfolio']['total'])->toBe(32)
        ->and($run->stages['prepare_portfolio']['processed'])->toBe(32)
        ->and($run->stages['process_income']['total'])->toBe(4);
    expect($run->stages['reconcile']['details']['external'])->toBeFalse(); // nunca se afirma conciliación externa
    expect(collect($run->sources['fotos'])->pluck('estado')->unique()->all())->toBe(['disponible'])
        ->and($run->sources['fotos'])->toHaveCount(8)
        ->and($run->engine_version)->toBe(EfectividadV4Calculator::ENGINE_VERSION)
        ->and($run->mode)->toBe('SEMANAL')
        ->and($run->cutoff_date->format('Y-m-d'))->toBe('2026-10-11');
    // Resultado real del fixture: Sano 400 por cobrar, 100 cobrado; atraso cobrado 250.
    $gt = collect($run->result_rows)->firstWhere('tipo', 'gran_total');
    expect($gt['sano_pc'])->toEqual(400.0)->and($gt['sano_cob'])->toEqual(100.0)
        ->and($gt['atraso_cob'])->toEqual(250.0)->and($gt['pct_total'])->toEqual(0.875)->and($gt['mora'])->toEqual(50.0);

    // 6-7. Al volver, la interfaz muestra el resultado correcto.
    $this->actingAs($admin)->get('/efectividad-cobranza')->assertInertia(fn ($page) => $page
        ->where('focusRun.id', $runId)
        ->where('focusRun.status', 'success')
        ->where('focusRun.display_state', 'success')
        ->where('focusRun.has_excel', true)
        ->where('focusRun.stages.8.status', 'done')
        ->where('focusRun.is_current_engine', true)
        ->has('activeRuns', 0)
        // 10. El historial conserva la ejecución.
        ->where('history.data.0.id', $runId)
        ->where('history.data.0.display_label', 'Terminado')
    );

    // 9 + 11. Excel real en disco privado y descargable.
    expect(Storage::disk('local')->exists($run->output_excel_path))->toBeTrue();
    $this->actingAs($admin)->get("/efectividad-cobranza/runs/{$runId}/descargar")->assertOk();

    // 12. Notificación de éxito: correo (sin adjuntos, con enlace autenticado) + interna persistente.
    Mail::assertSent(EfectividadCobranzaRunMail::class, function (EfectividadCobranzaRunMail $mail) use ($admin, $runId) {
        return $mail->hasTo($admin->email)
            && $mail->kind === 'success'
            && $mail->envelope()->subject === 'Efectividad de Cobranza — Reporte disponible'
            && $mail->attachments === [] && $mail->rawAttachments === []
            && str_ends_with($mail->detailUrl, "/efectividad-cobranza/runs/{$runId}");
    });
    Mail::assertSentCount(1);
    expect($run->notification_status)->toBe('sent')->and($run->notified_at)->not->toBeNull();

    $notification = $admin->notifications()->first();
    expect($notification->type)->toBe(EfectividadCobranzaRunFinished::class)
        ->and($notification->data['title'])->toBe('Tu reporte de Efectividad de Cobranza está listo.')
        ->and($notification->data['url'])->toBe("/efectividad-cobranza/runs/{$runId}");

    // El enlace del correo abre el módulo protegido con el detalle de esa ejecución.
    $this->actingAs($admin)->get("/efectividad-cobranza/runs/{$runId}")->assertOk()->assertInertia(fn ($page) => $page
        ->where('focusRun.id', $runId)
        ->where('openDetail', true)
    );
    auth()->logout();
    $this->get("/efectividad-cobranza/runs/{$runId}")->assertRedirect('/login');
});

it('13: si los Ingresos no traen Fecha cuota, queda BLOQUEADO (dato obsoleto/incompleto) con acción concreta — nunca 0%', function () {
    Mail::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $period = bgPeriod();
    $branch = bgBranch();
    V4::snapshots($period, $branch);
    // Variante real de Ingresos sin Fecha cuota (o importada con un importador anterior).
    V4::ingresos($period, $branch, ['installment_due_date' => null]);

    $run = EfectividadCobranzaRun::query()->create(bgV4(['period_id' => $period->id, 'branch_id' => $branch->id, 'status' => 'queued', 'queued_at' => now(), 'created_by' => $admin->id]));
    bgRunJob($run->id);
    $run->refresh();

    expect($run->status)->toBe('blocked')
        ->and($run->output_excel_path)->toBeNull()
        ->and($run->result_rows)->toBe([])
        ->and($run->failure_kind)->toBe('stale');

    $issue = collect($run->issues)->firstWhere('code', 'EC-V4-INGRESOS-SIN-FECHA-CUOTA');
    expect($issue['severity'])->toBe('critical')
        ->and($issue['message'])->toContain('Fecha cuota')
        ->and($issue['record_count'])->toBe(4)
        ->and($issue['action'])->toContain('Vuelve a procesar');
    expect($run->stages['validate_files']['status'])->toBe('blocked');

    Mail::assertSent(EfectividadCobranzaRunMail::class, fn ($m) => $m->kind === 'blocked'
        && $m->envelope()->subject === 'Efectividad de Cobranza — Se requiere corregir información');
    expect($admin->notifications()->first()->data['title'])->toBe('No se pudo generar el reporte porque faltan datos obligatorios.');
});

it('14: si falta una fotografía (aunque sea un día intermedio) queda bloqueado, indica qué fotografía y fecha faltan, y NUNCA la sustituye', function () {
    Mail::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $period = bgPeriod();
    $branch = bgBranch();
    // Todas menos el miércoles 07/10.
    V4::snapshots($period, $branch, ['2026-10-04', '2026-10-05', '2026-10-06', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11']);
    V4::ingresos($period, $branch);

    $run = EfectividadCobranzaRun::query()->create(bgV4(['period_id' => $period->id, 'status' => 'queued', 'queued_at' => now(), 'created_by' => $admin->id]));
    bgRunJob($run->id);
    $run->refresh();

    expect($run->status)->toBe('blocked')->and($run->failure_kind)->toBe('missing_data');
    $faltante = collect($run->issues)->firstWhere('code', 'EC-V4-FOTO-FALTANTE');
    expect($faltante['message'])->toContain('CARTERA MIERCOLES')->toContain('07/10/2026')
        ->and($faltante['sheet'])->toBe('CARTERA MIERCOLES')
        ->and($faltante['action'])->toContain('No se sustituye con otra fotografía');
    expect(collect($run->sources['fotos'])->firstWhere('fecha', '2026-10-07')['estado'])->toBe('faltante');

    $this->actingAs($admin)->getJson("/efectividad-cobranza/runs/{$run->id}/progreso")
        ->assertOk()
        ->assertJson(['status' => 'blocked', 'display_label' => 'Bloqueado por datos', 'has_excel' => false, 'rows' => []]);
});

it('14b: sin ninguna fotografía queda bloqueado con las 8 fotografías faltantes', function () {
    Mail::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $period = bgPeriod();

    $run = EfectividadCobranzaRun::query()->create(bgV4(['period_id' => $period->id, 'status' => 'queued', 'queued_at' => now(), 'created_by' => $admin->id]));
    bgRunJob($run->id);
    $run->refresh();

    expect($run->status)->toBe('blocked')->and($run->failure_kind)->toBe('missing_data');
    expect(collect($run->issues)->where('code', 'EC-V4-FOTO-FALTANTE'))->toHaveCount(8);
});

it('14c: un periodo mensual no se calcula con la V4 (no se inventa una agregación) y queda bloqueado', function () {
    Mail::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $month = Period::query()->create(['name' => 'Octubre', 'code' => 'M-V4-10', 'type' => 'monthly', 'year' => 2026, 'month' => 10, 'sequence' => 1, 'start_date' => '2026-09-28', 'end_date' => '2026-10-25']);

    $this->actingAs($admin)->postJson('/efectividad-cobranza/generar', ['period_id' => $month->id])
        ->assertStatus(422)->assertJson(['accepted' => false, 'code' => 'EC-V4-PERIODO']);

    $run = EfectividadCobranzaRun::query()->create(bgV4(['period_id' => $month->id, 'status' => 'queued', 'queued_at' => now(), 'created_by' => $admin->id]));
    bgRunJob($run->id);
    expect($run->refresh()->status)->toBe('blocked')->and($run->issues[0]['code'])->toBe('EC-V4-PERIODO');
});

it('15 + 17 + 18: una falla técnica reintenta como "Reintentando" (sin correo), y solo el último intento termina en Error — nunca éxito falso ni detalles técnicos al usuario', function () {
    Mail::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $period = bgPeriod();

    bgSeedValidData($period, bgBranch());
    $this->mock(EfectividadV4Calculator::class, function ($mock) {
        $mock->shouldReceive('calculate')->andThrow(new RuntimeException('SQLSTATE[HY000] secreto interno host=db-prod'));
    });

    $run = EfectividadCobranzaRun::query()->create(bgV4(['period_id' => $period->id, 'status' => 'queued', 'queued_at' => now(), 'created_by' => $admin->id]));

    expect(fn () => bgRunJob($run->id, attempt: 1))->toThrow(RuntimeException::class);
    $run->refresh();
    expect($run->status)->toBe('retrying')->and($run->finished_at)->toBeNull();
    Mail::assertNothingSent();
    expect($admin->notifications()->count())->toBe(0);

    expect(fn () => bgRunJob($run->id, attempt: 2))->toThrow(RuntimeException::class);
    $run->refresh();
    expect($run->status)->toBe('failed')
        ->and($run->failure_kind)->toBe('technical')
        ->and($run->output_excel_path)->toBeNull()
        ->and($run->error_message)->not->toContain('SQLSTATE')
        ->and(json_encode($run->issues))->not->toContain('secreto');

    // Worker invoca failed() tras el último intento: idempotente, no duplica correo.
    (new GenerateEfectividadCobranzaAprobadaJob($run->id))->failed(new RuntimeException('x'));

    Mail::assertSentCount(1);
    Mail::assertSent(EfectividadCobranzaRunMail::class, fn ($m) => $m->kind === 'failed'
        && $m->envelope()->subject === 'Efectividad de Cobranza — No fue posible completar el reporte');
    expect($admin->notifications()->count())->toBe(1);

    // Un colaborador gerencial ve el mensaje comprensible; la referencia técnica solo admin.
    $gerente = User::factory()->create(['role' => 'gerencial']);
    $this->actingAs($gerente)->getJson("/efectividad-cobranza/runs/{$run->id}/progreso")
        ->assertJson(['status' => 'failed', 'technical_reference' => null]);
});

it('16 + 17: si el SMTP falla el reporte sigue disponible; el reintento envía UNA vez y no duplica', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email' => 'jefe@example.com']);
    $period = bgPeriod();
    $branch = bgBranch();
    bgSeedValidData($period, $branch);

    // Transporte real que rechaza el envío (equivale a SMTP caído), sin red.
    Mail::extend('failing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Connection refused smtp.example.com:587 user=secret');
        }

        public function __toString(): string
        {
            return 'failing://';
        }
    });
    config(['mail.default' => 'failing', 'mail.mailers.failing' => ['transport' => 'failing']]);

    $run = EfectividadCobranzaRun::query()->create(bgV4(['period_id' => $period->id, 'branch_id' => $branch->id, 'status' => 'queued', 'queued_at' => now(), 'created_by' => $admin->id]));
    bgRunJob($run->id);
    $run->refresh();

    expect($run->status)->toBe('success')
        ->and($run->notification_status)->toBe('failed')
        ->and($run->notified_at)->toBeNull()
        ->and($run->notification_error)->toContain('No se pudo entregar el correo')
        ->and($run->notification_error)->not->toContain('secret');
    $this->actingAs($admin)->get("/efectividad-cobranza/runs/{$run->id}/descargar")->assertOk();
    expect($admin->notifications()->count())->toBe(1); // la interna no depende del SMTP

    // SMTP restablecido → el comando reintenta y entrega una sola vez.
    Mail::clearResolvedInstances();
    Mail::fake();
    $this->artisan('efectividad:retry-notifications')->assertSuccessful();
    $this->artisan('efectividad:retry-notifications')->assertSuccessful();
    app(EfectividadCobranzaRunNotifier::class)->notify($run);

    Mail::assertSentCount(1);
    expect($run->refresh()->notification_status)->toBe('sent')->and($admin->notifications()->count())->toBe(1);
});

it('SMTP sin configurar: no se afirma un envío; queda en failed con motivo y el resultado intacto', function () {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '']);
    $admin = User::factory()->create(['role' => 'admin']);
    $period = bgPeriod();
    $branch = bgBranch();
    bgSeedValidData($period, $branch);

    $run = EfectividadCobranzaRun::query()->create(bgV4(['period_id' => $period->id, 'branch_id' => $branch->id, 'status' => 'queued', 'queued_at' => now(), 'created_by' => $admin->id]));
    bgRunJob($run->id);
    $run->refresh();

    expect($run->status)->toBe('success')
        ->and($run->notification_status)->toBe('failed')
        ->and($run->notification_error)->toContain('no está configurado')
        ->and($run->notified_at)->toBeNull();
});

it('18: reintentos y Jobs duplicados no regeneran una ejecución cerrada ni duplican Excel o notificaciones', function () {
    Mail::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $period = bgPeriod();
    $branch = bgBranch();
    bgSeedValidData($period, $branch);

    $run = EfectividadCobranzaRun::query()->create(bgV4(['period_id' => $period->id, 'branch_id' => $branch->id, 'status' => 'queued', 'queued_at' => now(), 'created_by' => $admin->id]));
    bgRunJob($run->id);
    $path = $run->refresh()->output_excel_path;
    $finishedAt = $run->finished_at;

    $this->travel(5)->minutes();
    bgRunJob($run->id, attempt: 2); // Job duplicado / reintento tardío

    $run->refresh();
    expect($run->output_excel_path)->toBe($path)
        ->and($run->finished_at->equalTo($finishedAt))->toBeTrue()
        ->and(Storage::disk('local')->files('efectividad_cobranza'))->toHaveCount(1);
    Mail::assertSentCount(1);
    expect($admin->notifications()->count())->toBe(1);
});

it('18: dos usuarios solicitando lo mismo mientras está en cola reutilizan UNA ejecución; ambos quedan suscritos y notificados', function () {
    Queue::fake();
    Mail::fake();
    $a = User::factory()->create(['role' => 'admin']);
    $b = User::factory()->create(['role' => 'gerencial']);
    $period = bgPeriod();
    $branch = bgBranch();
    bgSeedValidData($period, $branch);

    $first = $this->actingAs($a)->postJson('/efectividad-cobranza/generar', ['period_id' => $period->id, 'branch_id' => $branch->id])->json();
    $second = $this->actingAs($b)->postJson('/efectividad-cobranza/generar', ['period_id' => $period->id, 'branch_id' => $branch->id])->json();

    expect($second['run_id'])->toBe($first['run_id'])->and($second['reused'])->toBeTrue();
    expect(EfectividadCobranzaRun::query()->count())->toBe(1);
    Queue::assertPushed(GenerateEfectividadCobranzaAprobadaJob::class, 1);

    // El gerencial la ve como propia al volver al módulo.
    $this->actingAs($b)->get('/efectividad-cobranza')->assertInertia(fn ($page) => $page->where('focusRun.id', $first['run_id'])->has('activeRuns', 1));

    bgRunJob($first['run_id']);
    Mail::assertSentCount(2);
    expect($a->notifications()->count())->toBe(1)->and($b->notifications()->count())->toBe(1);
});

it('la cola no acepta el trabajo → 503, sin confirmación falsa, ejecución marcada como error técnico', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = bgPeriod();

    $this->mock(Dispatcher::class, fn ($m) => $m->shouldReceive('dispatch')->andThrow(new RuntimeException('jobs table locked')));

    $response = $this->actingAs($admin)->postJson('/efectividad-cobranza/generar', ['period_id' => $period->id]);
    $response->assertStatus(503)->assertJson(['accepted' => false]);
    expect($response->json('error'))->toContain('NO se está generando');

    $run = EfectividadCobranzaRun::query()->firstOrFail();
    expect($run->status)->toBe('failed')->and($run->issues[0]['code'])->toBe('EC-TEC-COLA-NO-DISPONIBLE');
});

it('6: detecta trabajos atascados usando la cola real — retrasado si el Job sigue en cola, sin actividad si desapareció; nunca por tiempo solamente', function () {
    config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database']);
    $health = app(EfectividadCobranzaRunHealthService::class);
    $period = bgPeriod();

    $jobId = DB::table('jobs')->insertGetId(['queue' => 'default', 'payload' => '{"uuid":"abc-123"}', 'attempts' => 1, 'reserved_at' => now()->getTimestamp(), 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);

    // Procesando un Excel grande: 40 min sin heartbeat pero el worker sigue reteniendo el Job.
    $slow = EfectividadCobranzaRun::query()->create([
        'period_id' => $period->id, 'status' => 'running', 'queued_at' => now()->subHour(),
        'started_at' => now()->subMinutes(45), 'heartbeat_at' => now()->subMinutes(40), 'queue_job_id' => (string) $jobId,
    ]);
    expect($health->assess($slow)['state'])->toBe('delayed')->and($health->assess($slow)['queue_state'])->toBe('reserved');

    // Reciente: sigue "Procesando" aunque tarde (umbral configurable).
    $recent = EfectividadCobranzaRun::query()->create([
        'period_id' => $period->id, 'status' => 'running', 'started_at' => now()->subMinutes(20), 'heartbeat_at' => now()->subMinutes(2), 'queue_job_id' => '999',
    ]);
    expect($health->assess($recent)['state'])->toBe('running');

    // En cola 10 min con el Job pendiente y ningún worker → Retrasado (verifica el worker).
    DB::table('jobs')->where('id', $jobId)->update(['reserved_at' => null]);
    $waiting = EfectividadCobranzaRun::query()->create(['period_id' => $period->id, 'status' => 'queued', 'queued_at' => now()->subMinutes(10), 'queue_job_id' => (string) $jobId]);
    expect($health->assess($waiting)['state'])->toBe('delayed')->and($health->assess($waiting)['message'])->toContain('queue:work');

    // Huérfano: sin actividad y el Job ya no existe en la cola.
    $orphan = EfectividadCobranzaRun::query()->create([
        'period_id' => $period->id, 'status' => 'running', 'started_at' => now()->subMinutes(50), 'heartbeat_at' => now()->subMinutes(30), 'queue_job_id' => '424242',
    ]);
    expect($health->assess($orphan)['state'])->toBe('stalled')->and($health->assess($orphan)['label'])->toBe('Sin actividad del worker');

    // Ejecución anterior a este cambio (sin id de cola): no se afirma que murió.
    $legacy = EfectividadCobranzaRun::query()->create(['period_id' => $period->id, 'status' => 'running', 'started_at' => now()->subHours(3), 'heartbeat_at' => now()->subHours(3)]);
    expect($health->assess($legacy)['state'])->toBe('delayed');
});

it('6: recuperación segura — diagnóstico por defecto, --apply cierra solo huérfanos y --requeue reutiliza la MISMA ejecución', function () {
    config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database']);
    Mail::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $period = bgPeriod();

    $orphan = EfectividadCobranzaRun::query()->create([
        'period_id' => $period->id, 'status' => 'running', 'created_by' => $admin->id,
        'started_at' => now()->subHour(), 'heartbeat_at' => now()->subMinutes(30), 'queue_job_id' => '5555',
    ]);

    $this->artisan('efectividad:recover-stalled')->assertSuccessful();
    expect($orphan->refresh()->status)->toBe('running'); // sin --apply no modifica

    $this->artisan('efectividad:recover-stalled --apply')->assertSuccessful();
    $orphan->refresh();
    expect($orphan->status)->toBe('failed')
        ->and($orphan->failure_kind)->toBe('worker_stalled')
        ->and(collect($orphan->issues)->pluck('category'))->toContain('worker_stalled');
    Mail::assertSent(EfectividadCobranzaRunMail::class, fn ($m) => $m->kind === 'failed');

    $second = EfectividadCobranzaRun::query()->create([
        'period_id' => $period->id, 'branch_id' => null, 'status' => 'queued', 'created_by' => $admin->id,
        'queued_at' => now()->subMinutes(30), 'queue_job_id' => '7777',
    ]);
    Queue::fake();
    $this->artisan('efectividad:recover-stalled --apply --requeue')->assertSuccessful();

    expect($second->refresh()->status)->toBe('queued')
        ->and($second->metadata['recovered_at'] ?? null)->not->toBeNull()
        ->and(EfectividadCobranzaRun::query()->count())->toBe(2);
    Queue::assertPushed(GenerateEfectividadCobranzaAprobadaJob::class, fn ($job) => $job->runId === $second->id);
});

it('19: un colaborador no consulta ejecuciones, progreso, descargas ni actividad financiera; sí sus propias notificaciones', function () {
    $colaborador = User::factory()->create(['role' => 'colaborador']);
    $admin = User::factory()->create(['role' => 'admin']);
    $period = bgPeriod();
    $run = EfectividadCobranzaRun::query()->create(['period_id' => $period->id, 'status' => 'success', 'created_by' => $admin->id]);

    $this->actingAs($colaborador)->get('/efectividad-cobranza')->assertForbidden();
    $this->actingAs($colaborador)->get("/efectividad-cobranza/runs/{$run->id}")->assertForbidden();
    $this->actingAs($colaborador)->getJson("/efectividad-cobranza/runs/{$run->id}/progreso")->assertForbidden();
    $this->actingAs($colaborador)->get("/efectividad-cobranza/runs/{$run->id}/descargar")->assertForbidden();
    $this->actingAs($colaborador)->getJson('/efectividad-cobranza/actividad')->assertForbidden();
    $this->actingAs($colaborador)->postJson('/efectividad-cobranza/generar', ['period_id' => $period->id])->assertForbidden();

    $this->actingAs($colaborador)->getJson('/notificaciones')->assertOk()->assertJson(['active' => [], 'unread_count' => 0]);
});

it('notificaciones internas: solo las propias, se marcan como leídas y persisten entre recargas', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $other = User::factory()->create(['role' => 'admin']);
    $period = bgPeriod();
    $run = EfectividadCobranzaRun::query()->create(['period_id' => $period->id, 'status' => 'success', 'created_by' => $admin->id]);
    $admin->notify(new EfectividadCobranzaRunFinished($run));

    $this->actingAs($admin)->getJson('/notificaciones')->assertJson(['unread_count' => 1])->assertJsonPath('items.0.data.run_id', $run->id);
    $this->actingAs($admin)->get('/efectividad-cobranza')->assertInertia(fn ($page) => $page->where('backgroundActivity.unread_count', 1));

    $id = $admin->notifications()->first()->id;
    $this->actingAs($other)->postJson("/notificaciones/{$id}/leida")->assertNotFound(); // no puede tocar ajenas
    $this->actingAs($admin)->postJson("/notificaciones/{$id}/leida")->assertOk();
    $this->actingAs($admin)->getJson('/notificaciones')->assertJson(['unread_count' => 0]);
});

it('historial: filtros por periodo, sucursal, estado, fecha y solicitante (solo admin); paginado; versiones previas marcadas como obsoletas', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $gerente = User::factory()->create(['role' => 'gerencial']);
    $period = bgPeriod();
    $branch = bgBranch();

    $old = EfectividadCobranzaRun::query()->create(bgV4(['period_id' => $period->id, 'branch_id' => $branch->id, 'status' => 'success', 'created_by' => $gerente->id]));
    $new = EfectividadCobranzaRun::query()->create(bgV4(['period_id' => $period->id, 'branch_id' => $branch->id, 'status' => 'success', 'created_by' => $admin->id]));
    $blocked = EfectividadCobranzaRun::query()->create(['period_id' => $period->id, 'status' => 'blocked', 'created_by' => $gerente->id]);
    foreach (range(1, 11) as $_) {
        EfectividadCobranzaRun::query()->create(['period_id' => $period->id, 'status' => 'failed', 'created_by' => $admin->id]);
    }

    $this->actingAs($admin)->get('/efectividad-cobranza?f_status=success')->assertInertia(fn ($page) => $page
        ->has('history.data', 2)
        ->where('history.data.0.id', $new->id)
        ->where('history.data.1.id', $old->id)
        ->where('history.data.1.display_state', 'obsolete')
        ->where('history.data.1.superseded_by_id', $new->id)
        ->where('history.data.0.display_state', 'success')
        ->where('history.data.0.is_current_engine', true)
    );

    // Un resultado de la calculadora anterior nunca se presenta como vigente.
    $legacy = EfectividadCobranzaRun::query()->create(['period_id' => $period->id, 'branch_id' => $branch->id, 'engine_version' => 'aprobada-2026-10', 'status' => 'success', 'created_by' => $admin->id]);
    $legacyJson = $this->actingAs($admin)->getJson("/efectividad-cobranza/runs/{$legacy->id}/progreso")->json();
    expect($legacyJson['display_state'])->toBe('obsolete')
        ->and($legacyJson['is_current_engine'])->toBeFalse()
        ->and($legacyJson['health_message'])->toContain('calculadora anterior');
    $legacy->delete();
    $this->actingAs($admin)->get('/efectividad-cobranza?f_branch=general&f_status=blocked')->assertInertia(fn ($page) => $page->has('history.data', 1)->where('history.data.0.id', $blocked->id));
    $this->actingAs($admin)->get("/efectividad-cobranza?f_user={$gerente->id}")->assertInertia(fn ($page) => $page->has('history.data', 2)->where('canFilterByUser', true));
    $this->actingAs($admin)->get('/efectividad-cobranza')->assertInertia(fn ($page) => $page->where('history.meta.total', 14)->where('history.meta.last_page', 2)->has('history.data', 10));
    $this->actingAs($admin)->get('/efectividad-cobranza?f_from=2000-01-01&f_to=2000-01-02')->assertInertia(fn ($page) => $page->has('history.data', 0));

    // El gerencial ve solo lo que solicitó; el filtro por solicitante no aplica para él.
    $this->actingAs($gerente)->get("/efectividad-cobranza?f_user={$admin->id}")->assertInertia(fn ($page) => $page
        ->where('history.meta.total', 2)
        ->where('canFilterByUser', false)
    );
});

it('los tres correos se renderizan con periodo, sucursal, estado y enlace autenticado — sin trazas ni detalles técnicos', function () {
    $admin = User::factory()->create(['role' => 'admin', 'name' => 'Ana Admin']);
    $period = bgPeriod();
    $branch = bgBranch();

    foreach (['success' => 'success', 'blocked' => 'blocked', 'failed' => 'failed'] as $status => $kind) {
        $run = EfectividadCobranzaRun::query()->create([
            'period_id' => $period->id, 'branch_id' => $branch->id, 'status' => $status, 'created_by' => $admin->id,
            'queued_at' => now()->subMinutes(5), 'finished_at' => now(),
            'error_message' => 'Se produjo un error durante la generación.',
            'issues' => $status === 'blocked' ? [EfectividadCobranzaIssue::make(
                'EC-COL-FALTANTE', 'critical', 'missing_columns',
                'El archivo Ingresos Cobranza "x.xlsx" no contiene Fecha cuota.', 'Vuelve a exportar el archivo.',
                ['expected_column' => 'Fecha cuota', 'file' => 'x.xlsx'],
            )] : [],
            'metadata' => ['last_error_reference' => 'QueryException'],
        ]);

        $url = route('efectividad-cobranza.runs.show', $run);
        $html = (new EfectividadCobranzaRunMail($run, $admin, $kind, $url))->render();

        expect($html)->toContain($period->label)
            ->toContain('CORDOBA')
            ->toContain("#{$run->id}")
            ->toContain($url)
            ->not->toContain('QueryException')
            ->not->toContain('Stack');

        if ($kind === 'blocked') {
            expect($html)->toContain('no contiene Fecha cuota')->toContain('Fecha cuota');
        }
    }
});
