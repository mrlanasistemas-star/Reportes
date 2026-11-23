<?php

use App\Jobs\GenerateRadiographyJob;
use App\Jobs\UpdatePeriodDatabaseJob;
use App\Mail\DatabaseUpdateFailedMail;
use App\Mail\ReportGenerationFailedMail;
use App\Models\Period;
use App\Models\PeriodDatabaseUpdateRun;
use App\Models\PeriodRadiographyRun;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\Reporteria\MonthlyPeriodCreated;
use App\Notifications\Reporteria\PeriodDatabaseUpdateFinished;
use App\Notifications\Reporteria\RadiographyGenerationFinished;
use App\Services\DatabaseUpdateService;
use App\Services\Notifications\ReporteriaNotificationService;
use App\Services\PeriodRadiographyService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Notificaciones de Reportería (23-nov-2026): campana + Web Push (+ correo en
 * el nuevo periodo) al terminar la lectura de archivos, al terminar la
 * Radiografía y al crear un nuevo mes operativo. Ver docs/MANUAL-DESARROLLADOR.md.
 */
function notifPeriod(string $code = 'M-NOTIF'): Period
{
    return Period::query()->create([
        'name' => 'Junio 2026', 'code' => $code, 'type' => 'monthly',
        'year' => 2026, 'month' => 6, 'sequence' => 1,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => false,
    ]);
}

function withVapid(): void
{
    config([
        'webpush.vapid.public_key' => 'BPublicKeyDePruebaxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'webpush.vapid.private_key' => 'PrivateKeyDePruebaxxxxxxxxxxxxxxxxxxxxxx',
    ]);
}

// ── 1. Lectura de archivos (UpdatePeriodDatabaseJob) ───────────────────────

it('notifica al usuario cuando la lectura de archivos termina con error, sin romper el flujo del job', function () {
    Notification::fake();
    Mail::fake();
    $user = User::factory()->create();
    $period = notifPeriod();
    $run = PeriodDatabaseUpdateRun::query()->create(['period_id' => $period->id, 'status' => 'queued', 'queued_at' => now()]);

    $this->mock(DatabaseUpdateService::class, fn ($mock) => $mock->shouldReceive('updateForPeriod')->once()->andThrow(new RuntimeException('Archivo NOI ilegible')));

    expect(fn () => app()->call([new UpdatePeriodDatabaseJob($period->id, $run->id, $user->id), 'handle']))
        ->toThrow(RuntimeException::class, 'Archivo NOI ilegible');

    expect($run->fresh()->status)->toBe('failed');
    Notification::assertSentTo($user, PeriodDatabaseUpdateFinished::class, function (PeriodDatabaseUpdateFinished $n, array $channels) use ($run, $user) {
        $data = $n->toArray($user);

        return $n->runId === $run->id
            && $n->success === false
            && $data['kind'] === 'failed'
            && str_contains($data['url'], 'historico-general')
            && $channels === ['database'];
    });
    Mail::assertSent(DatabaseUpdateFailedMail::class);
});

it('respeta la preferencia: sin correo de carga si el usuario lo desactivó (la campana sí se registra)', function () {
    Notification::fake();
    Mail::fake();
    $user = User::factory()->create();
    UserNotificationPreference::query()->create(['user_id' => $user->id, 'type' => UserNotificationPreference::TYPE_CARGA, 'mail_enabled' => false, 'push_enabled' => true]);
    $period = notifPeriod();
    $run = PeriodDatabaseUpdateRun::query()->create(['period_id' => $period->id, 'status' => 'queued', 'queued_at' => now()]);

    $this->mock(DatabaseUpdateService::class, fn ($mock) => $mock->shouldReceive('updateForPeriod')->andThrow(new RuntimeException('falla')));

    try {
        app()->call([new UpdatePeriodDatabaseJob($period->id, $run->id, $user->id), 'handle']);
    } catch (RuntimeException) {
    }

    Mail::assertNotSent(DatabaseUpdateFailedMail::class);
    Notification::assertSentTo($user, PeriodDatabaseUpdateFinished::class);
    expect($run->fresh()->log)->toContain('Correo desactivado');
});

it('arma el aviso de lectura exitosa y con fuentes pendientes', function () {
    Notification::fake();
    $user = User::factory()->create();
    $period = notifPeriod();
    $run = PeriodDatabaseUpdateRun::query()->create(['period_id' => $period->id, 'status' => 'success']);
    $service = app(ReporteriaNotificationService::class);

    $service->databaseUpdateFinished($user->id, $period, $run, true);
    $service->databaseUpdateFinished($user->id, $period, $run, true, ['Gastos Lendus']);

    $sent = Notification::sent($user, PeriodDatabaseUpdateFinished::class);
    expect($sent)->toHaveCount(2)
        ->and($sent[0]->toArray($user)['kind'])->toBe('success')
        ->and($sent[1]->toArray($user)['kind'])->toBe('blocked')
        ->and($sent[1]->toArray($user)['body'])->toContain('Gastos Lendus');
});

// ── 2. Generación de Radiografía (GenerateRadiographyJob) ───────────────────

it('notifica al usuario cuando la generación del reporte falla', function () {
    Notification::fake();
    Mail::fake();
    $user = User::factory()->create();
    $period = notifPeriod();
    $run = PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'report_type' => 'simple', 'scope' => 'general', 'status' => 'queued', 'created_by' => $user->id,
    ]);

    $this->mock(PeriodRadiographyService::class, fn ($mock) => $mock->shouldReceive('generate')->andThrow(new RuntimeException('Fallo de cálculo')));

    try {
        app()->call([new GenerateRadiographyJob($period->id, $user->id, $run->id), 'handle']);
    } catch (RuntimeException) {
    }

    expect($run->fresh()->status)->toBe('failed');
    Notification::assertSentTo($user, RadiographyGenerationFinished::class, function (RadiographyGenerationFinished $n) use ($run, $user) {
        $data = $n->toArray($user);

        return $n->runId === $run->id && $n->success === false && $data['kind'] === 'failed' && str_starts_with($data['period_label'], 'Junio 2026');
    });
    Mail::assertSent(ReportGenerationFailedMail::class);
});

it('el aviso de reporte listo lleva a la vista del reporte generado (URL relativa)', function () {
    Notification::fake();
    $user = User::factory()->create();
    $period = notifPeriod();
    $run = PeriodRadiographyRun::query()->create(['period_id' => $period->id, 'report_type' => 'simple', 'scope' => 'general', 'status' => 'success']);

    app(ReporteriaNotificationService::class)->radiographyFinished($user->id, $period, $run, true, route('reportes-mensuales.preview', $period->id));

    Notification::assertSentTo($user, RadiographyGenerationFinished::class, function (RadiographyGenerationFinished $n) use ($user, $period) {
        $data = $n->toArray($user);

        return $data['kind'] === 'success'
            && $data['url'] === route('reportes-mensuales.preview', $period->id, false)
            && $n->toWebPush($user)->toArray()['data']['url'] === $data['url'];
    });
});

// ── 3. Nuevo periodo mensual ────────────────────────────────────────────────

it('notifica un NUEVO mes operativo al creador y a quien tiene acceso a Carga/Periodos (no a colaboradores)', function () {
    Notification::fake();
    $admin = User::factory()->create();
    $gerencial = User::factory()->create(['role' => User::ROLE_GERENCIAL]);
    $colaborador = User::factory()->create(['role' => User::ROLE_COLABORADOR]);
    $deshabilitado = User::factory()->create(['role' => User::ROLE_GERENCIAL, 'disabled_at' => now()]);

    $this->actingAs($admin)->post('/periodos', ['type' => 'weekly', 'year' => 2026, 'month' => 6])->assertRedirect();
    Notification::assertNothingSent();

    $this->actingAs($admin)->post('/periodos', ['type' => 'monthly', 'year' => 2026, 'month' => 6])->assertRedirect();

    Notification::assertSentTo($admin, MonthlyPeriodCreated::class, fn (MonthlyPeriodCreated $n, array $channels) => in_array('mail', $channels, true) && in_array('database', $channels, true));
    Notification::assertSentTo($gerencial, MonthlyPeriodCreated::class);
    Notification::assertNotSentTo($colaborador, MonthlyPeriodCreated::class);
    Notification::assertNotSentTo($deshabilitado, MonthlyPeriodCreated::class);
    Notification::assertSentTimes(MonthlyPeriodCreated::class, 2);

    // Regenerar el mismo mes NO es un periodo nuevo: no vuelve a notificar.
    $this->actingAs($admin)->post('/periodos', ['type' => 'monthly', 'year' => 2026, 'month' => 6])->assertRedirect();
    Notification::assertSentTimes(MonthlyPeriodCreated::class, 2);
});

it('el aviso de nuevo periodo respeta las preferencias de correo y push', function () {
    withVapid();
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'p256', 'auth', 'aes128gcm');
    $notification = new MonthlyPeriodCreated(1, 'Junio 2026', 'Admin');

    expect($notification->via($user))->toBe(['database', 'mail', WebPushChannel::class]);

    UserNotificationPreference::query()->create(['user_id' => $user->id, 'type' => UserNotificationPreference::TYPE_PERIODO, 'mail_enabled' => false, 'push_enabled' => false]);

    expect($notification->via($user))->toBe(['database']);
});

it('nunca rompe el proceso si el envío del aviso falla', function () {
    Log::spy();
    $period = notifPeriod();
    Notification::shouldReceive('send')->andThrow(new RuntimeException('cola caída'));

    app(ReporteriaNotificationService::class)->monthlyPeriodCreated($period, User::factory()->create());

    Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains($msg, 'nuevo periodo'))->once();
});

it('el push solo se usa con claves VAPID y al menos un dispositivo suscrito', function () {
    $user = User::factory()->create();
    $notification = new PeriodDatabaseUpdateFinished(1, 1, 'Junio 2026', true);

    config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/xyz', 'p256', 'auth');
    expect($notification->via($user))->toBe(['database']);

    withVapid();
    expect($notification->via($user))->toBe(['database', WebPushChannel::class]);

    $user->pushSubscriptions()->delete();
    expect($notification->via($user))->toBe(['database']);
});

// ── 4. Suscripción del dispositivo y preferencias ───────────────────────────

it('registra, actualiza y elimina la suscripción push del dispositivo', function () {
    $user = User::factory()->create();
    $endpoint = 'https://fcm.googleapis.com/fcm/send/'.str_repeat('a', 700);

    $this->actingAs($user)->postJson(route('notification-settings.subscribe'), [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => 'clave-publica', 'auth' => 'token-auth'],
        'content_encoding' => 'aes128gcm',
    ])->assertOk()->assertJson(['ok' => true, 'devices_count' => 1]);

    $sub = PushSubscription::query()->sole();
    expect($sub->endpoint)->toBe($endpoint)
        ->and($sub->endpoint_hash)->toBe(hash('sha256', $endpoint))
        ->and($sub->subscribable_id)->toBe($user->id);

    // Mismo dispositivo otra vez: actualiza, no duplica.
    $this->actingAs($user)->postJson(route('notification-settings.subscribe'), [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => 'clave-nueva', 'auth' => 'token-nuevo'],
    ])->assertOk()->assertJson(['devices_count' => 1]);
    expect(PushSubscription::query()->sole()->public_key)->toBe('clave-nueva');

    $this->actingAs($user)->deleteJson(route('notification-settings.unsubscribe'), ['endpoint' => $endpoint])
        ->assertOk()->assertJson(['ok' => true, 'devices_count' => 0]);
    expect(PushSubscription::query()->count())->toBe(0);
});

it('valida la suscripción y exige sesión', function () {
    $this->postJson(route('notification-settings.subscribe'), [])->assertUnauthorized();

    $user = User::factory()->create();
    $this->actingAs($user)->postJson(route('notification-settings.subscribe'), ['endpoint' => 'no-es-url'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
});

it('un usuario no puede borrar la suscripción de otro', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $owner->updatePushSubscription('https://fcm.googleapis.com/fcm/send/owner', 'k', 't');

    $this->actingAs($other)->deleteJson(route('notification-settings.unsubscribe'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/owner'])->assertOk();

    expect($owner->pushSubscriptions()->count())->toBe(1);
});

it('guarda las preferencias por tipo y muestra la página de configuración', function () {
    $this->withoutVite();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('notification-settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/Notifications')
            ->has('preferences', count(UserNotificationPreference::TYPES))
            ->where('push.configured', false));

    $this->actingAs($user)->patch(route('notification-settings.update'), [
        'preferences' => [
            ['type' => UserNotificationPreference::TYPE_REPORTE, 'mail' => false, 'push' => true],
        ],
    ])->assertRedirect();

    expect(UserNotificationPreference::allows($user, UserNotificationPreference::TYPE_REPORTE, 'mail'))->toBeFalse()
        ->and(UserNotificationPreference::allows($user, UserNotificationPreference::TYPE_REPORTE, 'push'))->toBeTrue()
        ->and(UserNotificationPreference::allows($user, UserNotificationPreference::TYPE_CARGA, 'mail'))->toBeTrue();

    $this->actingAs($user)->patch(route('notification-settings.update'), [
        'preferences' => [['type' => 'inventado', 'mail' => true, 'push' => true]],
    ])->assertSessionHasErrors('preferences.0.type');
});

it('la prueba de push explica qué falta antes de enviar', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('notification-settings.test'))->assertStatus(422)->assertJson(['ok' => false]);

    withVapid();
    $this->actingAs($user)->postJson(route('notification-settings.test'))
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'Primero activa las notificaciones en este dispositivo.']);
});
