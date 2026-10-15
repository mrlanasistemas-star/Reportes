<?php

use App\Mail\UserAccessGrantedMail;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use App\Services\Access\UserInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

uses(RefreshDatabase::class);

// Las páginas nuevas aún no están en el manifest de Vite hasta el siguiente build.
beforeEach(fn () => $this->withoutVite());

/**
 * Prioridad 2/8/10 — módulo Usuarios: acceso ≠ situación laboral, contraseña
 * manual/generada/invitación, reenvío con la URL exacta que usa el frontend,
 * estado real de la invitación y protección del último administrador.
 */
function uaAdmin(): User
{
    return User::factory()->create(['role' => 'admin', 'access_enabled_at' => now()]);
}

/** Foto de todo lo laboral/colaborador que NUNCA debe cambiar al administrar accesos. */
function uaLaborSnapshot(): array
{
    $tables = ['employees', 'employee_branch_assignments', 'period_employee_rosters'];

    return collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])->all();
}

it('disabling, re-enabling, blocking and unblocking a user linked to an employee never touches employees or labor data', function () {
    $admin = uaAdmin();
    $branch = Branch::query()->create(['code' => 'ORI', 'name' => 'ORIZABA', 'normalized_name' => 'orizaba', 'is_active' => true]);
    $employee = Employee::query()->create(['full_name' => 'MARIA GESTORA', 'normalized_name' => 'maria gestora', 'is_active' => true]);
    $inactive = Employee::query()->create(['full_name' => 'PEDRO DE BAJA', 'normalized_name' => 'pedro de baja', 'is_active' => false]);
    $user = User::factory()->create(['role' => 'colaborador', 'access_enabled_at' => now(), 'employee_id' => $employee->id]);
    $other = User::factory()->create(['role' => 'colaborador', 'access_enabled_at' => null, 'employee_id' => $inactive->id]);
    expect($branch->id)->toBeInt();

    $before = uaLaborSnapshot();

    $this->actingAs($admin)->post(route('usuarios.disable-access', $user))->assertSessionHas('success');
    $this->actingAs($admin)->post(route('usuarios.enable-access', $user))->assertSessionHas('success');
    $this->actingAs($admin)->post(route('usuarios.toggle-disabled', $user))->assertSessionHas('success');
    $this->actingAs($admin)->post(route('usuarios.toggle-disabled', $user))->assertSessionHas('success');
    $this->actingAs($admin)->post(route('usuarios.enable-access', $other))->assertSessionHas('success');

    expect(uaLaborSnapshot())->toBe($before)
        ->and($employee->fresh()->is_active)->toBeTrue()
        ->and($inactive->fresh()->is_active)->toBeFalse()
        ->and($user->fresh()->access_enabled_at)->not->toBeNull()
        ->and($user->fresh()->disabled_at)->toBeNull()
        ->and($other->fresh()->access_enabled_at)->not->toBeNull();
});

it('creates a user with a MANUAL password: stored hashed, never in plain text, and the confirmation is required', function () {
    $admin = uaAdmin();
    Mail::fake();

    $this->actingAs($admin)->post(route('usuarios.store'), [
        'name' => 'Lucía', 'email' => 'lucia@example.test', 'role' => 'colaborador', 'password_mode' => 'manual',
        'password' => 'Segura-12345', 'password_confirmation' => 'otra-cosa', 'send_invitation' => false,
    ])->assertSessionHasErrors(['password' => 'La confirmación no coincide con la contraseña.']);
    expect(User::query()->where('email', 'lucia@example.test')->exists())->toBeFalse();

    $this->actingAs($admin)->post(route('usuarios.store'), [
        'name' => 'Lucía', 'email' => 'lucia@example.test', 'role' => 'colaborador', 'password_mode' => 'manual',
        'password' => 'Segura-12345', 'password_confirmation' => 'Segura-12345', 'send_invitation' => false,
    ])->assertSessionHasNoErrors()->assertSessionMissing('temp_password');

    $user = User::query()->where('email', 'lucia@example.test')->firstOrFail();
    $raw = DB::table('users')->where('id', $user->id)->value('password');
    expect($raw)->not->toBe('Segura-12345')
        ->and(Hash::check('Segura-12345', $raw))->toBeTrue()
        ->and($user->access_enabled_at)->toBeNull()
        ->and($user->email_verified_at)->toBeNull();
    Mail::assertNothingSent();
});

it('creates a user with a GENERATED password shown once, and resets access with a manual password too', function () {
    $admin = uaAdmin();
    Mail::fake();

    $response = $this->actingAs($admin)->post(route('usuarios.store'), [
        'name' => 'Raúl', 'email' => 'raul@example.test', 'role' => 'gerencial', 'password_mode' => 'auto', 'send_invitation' => false,
    ])->assertSessionHasNoErrors();
    $generated = session('temp_password');
    $user = User::query()->where('email', 'raul@example.test')->firstOrFail();
    expect($generated['email'])->toBe('raul@example.test')
        ->and(Hash::check($generated['password'], $user->password))->toBeTrue();
    expect($response->status())->toBe(302);

    // Restablecer con contraseña manual (con confirmación).
    $this->actingAs($admin)->post(route('usuarios.reset-password', $user), ['password_mode' => 'manual', 'password' => 'Nueva-clave-99', 'password_confirmation' => 'Nueva-clave-99'])
        ->assertSessionHasNoErrors()->assertSessionMissing('reset_password');
    expect(Hash::check('Nueva-clave-99', $user->fresh()->password))->toBeTrue();

    // Restablecer generada: se muestra una vez.
    $this->actingAs($admin)->post(route('usuarios.reset-password', $user), ['password_mode' => 'auto'])->assertSessionHas('reset_password');
    expect(Hash::check(session('reset_password')['password'], $user->fresh()->password))->toBeTrue();
});

it('creates a user by INVITATION: the mail transport accepts it and the state is persisted (never "received")', function () {
    $admin = uaAdmin();
    Mail::fake();

    $this->actingAs($admin)->post(route('usuarios.store'), ['name' => 'Inés', 'email' => 'ines@example.test', 'role' => 'colaborador', 'password_mode' => 'invite'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('invitation_result', ['sent' => true, 'message' => 'El servidor de correo aceptó la invitación para su envío.', 'email' => 'ines@example.test'])
        ->assertSessionMissing('temp_password');

    $user = User::query()->where('email', 'ines@example.test')->firstOrFail();
    Mail::assertSent(UserAccessGrantedMail::class, fn ($mail) => $mail->hasTo('ines@example.test') && str_contains($mail->setPasswordUrl, '/reset-password/'));
    expect($user->welcome_email_status)->toBe(UserInvitationService::STATUS_ACCEPTED)
        ->and($user->welcome_email_attempts)->toBe(1)
        ->and($user->welcome_email_sent_at)->not->toBeNull();
});

it('renders the business invitation template with name, button, link validity and support', function () {
    $user = User::factory()->make(['name' => 'Inés Pérez', 'email' => 'ines@example.test']);
    $html = (new UserAccessGrantedMail($user, 'https://reportes.test/reset-password/tok?email=ines', 60))->render();

    expect($html)->toContain('MR LANA')->toContain('Inés Pérez')->toContain('Establecer mi contraseña')
        ->toContain('https://reportes.test/reset-password/tok?email=ines')->toContain('1 hora')->toContain('soporte')
        ->not->toContain('Segura-');
});

it('a mail transport failure keeps the user, records the error and shows the exact retry message', function () {
    $admin = uaAdmin();
    Mail::shouldReceive('to')->andThrow(new TransportException('Connection could not be established with host "smtp.test:587"'));

    $this->actingAs($admin)->post(route('usuarios.store'), ['name' => 'Omar', 'email' => 'omar@example.test', 'role' => 'colaborador', 'password_mode' => 'invite'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('invitation_result', ['sent' => false, 'message' => 'No se pudo enviar la invitación. El usuario fue creado y puedes intentar enviarla nuevamente.', 'email' => 'omar@example.test']);

    $user = User::query()->where('email', 'omar@example.test')->firstOrFail();
    expect($user->welcome_email_status)->toBe('failed')
        ->and($user->welcome_email_sent_at)->toBeNull()
        // Mensaje humano: nunca el host/puerto/traza del servidor.
        ->and($user->welcome_email_error)->not->toContain('smtp.test')
        ->and($user->welcome_email_error)->toBe('No hubo conexión con el servidor de correo. Intenta de nuevo en unos minutos.');

    // Reintento por la URL que usa el frontend: sigue fallando → 422 con el mensaje exacto.
    $this->actingAs($admin)->postJson(route('usuarios.resend-invitation', $user))
        ->assertStatus(422)
        ->assertJson(['sent' => false, 'message' => 'No se pudo enviar la invitación. El usuario fue creado y puedes intentar enviarla nuevamente.', 'invitation' => ['status' => 'failed', 'attempts' => 2]]);
});

it('resends the invitation using EXACTLY the URL the Users screen gives the frontend (also for a blocked user) — never a 404', function () {
    $admin = uaAdmin();
    $blocked = User::factory()->create(['role' => 'colaborador', 'disabled_at' => now(), 'access_enabled_at' => null]);
    Mail::fake();

    $urls = [];
    $this->actingAs($admin)->get(route('usuarios.index'))->assertOk()
        ->assertInertia(function ($page) use (&$urls, $blocked) {
            $page->component('usuarios/Index');
            $row = collect($page->toArray()['props']['users'])->firstWhere('id', $blocked->id);
            $urls = $row['urls'];
        });

    expect($urls['resend_invitation'])->toBe(route('usuarios.resend-invitation', $blocked));

    // Igual que okrRequest(): POST + Accept: application/json (el CSRF lo cubre el XSRF-TOKEN en el navegador).
    $this->actingAs($admin)->postJson($urls['resend_invitation'])
        ->assertOk()
        ->assertJson(['sent' => true, 'message' => 'El servidor de correo aceptó la invitación para su envío.', 'invitation' => ['status' => 'accepted']]);
    Mail::assertSent(UserAccessGrantedMail::class, fn ($m) => $m->hasTo($blocked->email));

    // La ruta antigua sigue respondiendo (alias) con los mismos permisos.
    $this->actingAs($admin)->postJson("/okr/responsibles/{$blocked->id}/resend-welcome-email")->assertOk();
});

it('protects the last administrator and never lets a user lock themselves out', function () {
    $admin = uaAdmin();

    $this->actingAs($admin)->post(route('usuarios.toggle-disabled', $admin))->assertSessionHas('error', 'No puedes bloquear tu propia cuenta.');
    $this->actingAs($admin)->put(route('usuarios.update-role', $admin), ['role' => 'colaborador'])->assertSessionHas('error');
    $this->actingAs($admin)->post(route('usuarios.disable-access', $admin))->assertSessionHas('error');
    expect($admin->fresh()->role)->toBe('admin')->and($admin->fresh()->disabled_at)->toBeNull();

    // Con un segundo admin BLOQUEADO, el actual sigue siendo el único habilitado:
    // degradar al bloqueado está permitido, pero el sistema nunca queda sin admin habilitado.
    $second = User::factory()->create(['role' => 'admin', 'disabled_at' => now()]);
    $this->actingAs($admin)->put(route('usuarios.update-role', $second), ['role' => 'colaborador'])->assertSessionHas('success');
    expect(User::query()->where('role', 'admin')->whereNull('disabled_at')->count())->toBe(1);

    $this->actingAs($admin)->get(route('usuarios.index'))->assertInertia(fn ($page) => $page
        ->where('enabled_admin_count', 1)
        ->where('users', fn ($users) => collect($users)->firstWhere('id', $admin->id)['is_last_admin'] === true));
});

it('records every administrative action in the history and the last login', function () {
    $admin = User::factory()->create(['role' => 'admin', 'password' => bcrypt('password-test-123')]);
    $user = User::factory()->create(['role' => 'colaborador', 'access_enabled_at' => null]);

    $this->post('/login', ['email' => $admin->email, 'password' => 'password-test-123']);
    expect($admin->fresh()->last_login_at)->not->toBeNull();

    $this->actingAs($admin)->post(route('usuarios.enable-access', $user));
    $this->actingAs($admin)->put(route('usuarios.update-role', $user), ['role' => 'gerencial']);

    $this->actingAs($admin)->get(route('usuarios.index'))->assertInertia(fn ($page) => $page
        ->where('audit.0.label', 'Cambió el rol')
        ->where('audit.0.old_value', 'Colaborador')
        ->where('audit.0.new_value', 'Gerencial')
        ->where('audit.1.label', 'Activó el acceso')
        ->where('audit.1.target', $user->name));
});

it('a user without the Users module gets 403 on every user-admin URL', function () {
    $gerente = User::factory()->create(['role' => 'gerencial', 'access_enabled_at' => now()]);
    $target = User::factory()->create(['role' => 'colaborador']);

    $this->actingAs($gerente)->get(route('usuarios.index'))->assertForbidden();
    foreach (['enable-access', 'disable-access', 'toggle-disabled', 'reset-password', 'resend-invitation'] as $name) {
        $this->actingAs($gerente)->postJson(route("usuarios.{$name}", $target))->assertForbidden();
    }
    $this->actingAs($gerente)->putJson(route('usuarios.update-role', $target), ['role' => 'admin'])->assertForbidden();
    expect($target->fresh()->role)->toBe('colaborador');
});
