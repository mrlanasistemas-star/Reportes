<?php

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Test punto 30 L / 39 H — email_verified_at nunca se finge, y ya no se reutiliza como "acceso habilitado". */
it('creating a responsible never marks their email as verified — that requires an explicit admin action', function () {
    $admin = User::factory()->create();

    $response = $this->actingAs($admin)->post('/okr/responsibles', [
        'name' => 'Nuevo Responsable', 'email' => 'nuevo.responsable@example.com', 'role' => 'colaborador',
    ]);

    $response->assertSessionHasNoErrors();
    $user = User::query()->where('email', 'nuevo.responsable@example.com')->firstOrFail();
    expect($user->email_verified_at)->toBeNull();
    expect($user->access_enabled_at)->toBeNull();
});

it('a "pending" responsible only becomes active after the admin explicitly enables access, via access_enabled_at — never email_verified_at', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin)->post('/okr/responsibles', ['name' => 'Ana Pendiente', 'email' => 'ana.pendiente@example.com', 'role' => 'colaborador']);
    $user = User::query()->where('email', 'ana.pendiente@example.com')->firstOrFail();
    expect($user->access_enabled_at)->toBeNull();

    $this->actingAs($admin)->post("/okr/responsibles/{$user->id}/enable-access")->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->access_enabled_at)->not->toBeNull();
    // Bug corregido punto 32 — habilitar acceso NUNCA debe fingir una
    // verificación de correo real que no ocurrió.
    expect($user->email_verified_at)->toBeNull();
});

it('a non-admin cannot enable access for a responsible', function () {
    $admin = User::factory()->create();
    $colaborador = User::factory()->create(['role' => 'colaborador']);
    $this->actingAs($admin)->post('/okr/responsibles', ['name' => 'Bloqueado', 'email' => 'bloqueado@example.com', 'role' => 'colaborador']);
    $user = User::query()->where('email', 'bloqueado@example.com')->firstOrFail();

    $this->actingAs($colaborador)->post("/okr/responsibles/{$user->id}/enable-access")->assertForbidden();
    expect($user->fresh()->access_enabled_at)->toBeNull();
});

/** Cierre 17-sep-2026, ronda 4 — "quitar acceso" (antes solo se podía habilitar, nunca revertir). */
it('an admin can disable access for an already-enabled responsible', function () {
    $admin = User::factory()->create();
    $colaborador = User::factory()->create(['role' => 'colaborador', 'access_enabled_at' => now()]);

    $this->actingAs($admin)->post("/okr/responsibles/{$colaborador->id}/disable-access")->assertSessionHasNoErrors();

    expect($colaborador->fresh()->access_enabled_at)->toBeNull();
});

// ── 05-oct-2026, a petición explícita del usuario — acceso y Employee.is_active van acoplados ──
it('disabling access ALSO marks the linked Employee as inactive', function () {
    $admin = User::factory()->create();
    $employee = Employee::query()->create(['full_name' => 'COLAB CON EMPLEADO', 'normalized_name' => 'colab con empleado', 'is_active' => true]);
    $colaborador = User::factory()->create(['role' => 'colaborador', 'access_enabled_at' => now(), 'employee_id' => $employee->id]);

    $this->actingAs($admin)->post("/okr/responsibles/{$colaborador->id}/disable-access")->assertSessionHasNoErrors();

    expect($colaborador->fresh()->access_enabled_at)->toBeNull();
    expect($employee->fresh()->is_active)->toBeFalse();
});

it('enabling access ALSO reactivates the linked Employee', function () {
    $admin = User::factory()->create();
    $employee = Employee::query()->create(['full_name' => 'COLAB DE BAJA', 'normalized_name' => 'colab de baja', 'is_active' => false]);
    $colaborador = User::factory()->create(['role' => 'colaborador', 'employee_id' => $employee->id]);

    $this->actingAs($admin)->post("/okr/responsibles/{$colaborador->id}/enable-access")->assertSessionHasNoErrors();

    expect($colaborador->fresh()->access_enabled_at)->not->toBeNull();
    expect($employee->fresh()->is_active)->toBeTrue();
});

it('enabling/disabling access for a user WITHOUT a linked Employee never errors and touches no Employee', function () {
    $admin = User::factory()->create();
    $colaborador = User::factory()->create(['role' => 'colaborador']); // sin employee_id

    $this->actingAs($admin)->post("/okr/responsibles/{$colaborador->id}/enable-access")->assertSessionHasNoErrors();
    expect($colaborador->fresh()->access_enabled_at)->not->toBeNull();

    $this->actingAs($admin)->post("/okr/responsibles/{$colaborador->id}/disable-access")->assertSessionHasNoErrors();
    expect($colaborador->fresh()->access_enabled_at)->toBeNull();
});

it('an admin can promote a colaborador to admin, and demote an admin back to colaborador', function () {
    $admin = User::factory()->create();
    $colaborador = User::factory()->create(['role' => 'colaborador']);
    $otherAdmin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->put("/okr/responsibles/{$colaborador->id}/role", ['role' => 'admin'])->assertSessionHasNoErrors();
    expect($colaborador->fresh()->role)->toBe('admin');

    $this->actingAs($admin)->put("/okr/responsibles/{$otherAdmin->id}/role", ['role' => 'colaborador'])->assertSessionHasNoErrors();
    expect($otherAdmin->fresh()->role)->toBe('colaborador');
});

it('an admin cannot change their own role from this screen', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    User::factory()->create(['role' => 'admin']); // otro admin, para que no sea "el único"

    $this->actingAs($admin)->put("/okr/responsibles/{$admin->id}/role", ['role' => 'colaborador'])->assertSessionHasNoErrors();

    expect($admin->fresh()->role)->toBe('admin');
});

it('an admin can demote another admin as long as at least one admin remains afterward', function () {
    $adminA = User::factory()->create(['role' => 'admin']);
    $adminB = User::factory()->create(['role' => 'admin']);

    $this->actingAs($adminA)->put("/okr/responsibles/{$adminB->id}/role", ['role' => 'colaborador'])->assertSessionHasNoErrors();

    expect($adminB->fresh()->role)->toBe('colaborador');
    expect(User::where('role', 'admin')->count())->toBe(1);
});

/**
 * Candado de defensa en profundidad — en la práctica esto SIEMPRE coincide con una
 * auto-degradación (el único admin restante es quien está autenticado, porque
 * okr.admin exige role='admin' para llegar aquí), pero el conteo se revisa aparte
 * del chequeo "no puedes cambiar tu propio rol" para que siga protegido aunque ese
 * otro candado cambiara en el futuro.
 */
it('demoting the sole remaining admin is rejected — the system never ends up with zero admins', function () {
    $soleAdmin = User::factory()->create(['role' => 'admin']);
    User::factory()->create(['role' => 'colaborador']);

    $this->actingAs($soleAdmin)->put("/okr/responsibles/{$soleAdmin->id}/role", ['role' => 'colaborador']);

    expect($soleAdmin->fresh()->role)->toBe('admin');
    expect(User::where('role', 'admin')->count())->toBe(1);
});

// ── 1/2/23 del cierre (05-oct-2026) — vínculo User↔Employee ──
it('an admin can link a colaborador to their real Employee', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $colaborador = User::factory()->create(['role' => 'colaborador']);
    $employee = Employee::query()->create(['full_name' => 'JUAN VINCULABLE', 'normalized_name' => 'juan vinculable', 'is_active' => true]);

    $this->actingAs($admin)->put("/okr/responsibles/{$colaborador->id}/employee", ['employee_id' => $employee->id])
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->employee_id)->toBe($employee->id);
});

it('an admin can unlink a colaborador from their Employee (employee_id => null)', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $employee = Employee::query()->create(['full_name' => 'JUAN DESVINCULABLE', 'normalized_name' => 'juan desvinculable', 'is_active' => true]);
    $colaborador = User::factory()->create(['role' => 'colaborador', 'employee_id' => $employee->id]);

    $this->actingAs($admin)->put("/okr/responsibles/{$colaborador->id}/employee", ['employee_id' => null])
        ->assertSessionHasNoErrors();

    expect($colaborador->fresh()->employee_id)->toBeNull();
});

it('the backend rejects linking an Employee already linked to a DIFFERENT user — never two Users on the same Employee', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $employee = Employee::query()->create(['full_name' => 'EMPLEADO COMPARTIDO', 'normalized_name' => 'empleado compartido', 'is_active' => true]);
    $alreadyLinked = User::factory()->create(['role' => 'colaborador', 'employee_id' => $employee->id]);
    $other = User::factory()->create(['role' => 'colaborador']);

    $this->actingAs($admin)->put("/okr/responsibles/{$other->id}/employee", ['employee_id' => $employee->id]);

    expect($other->fresh()->employee_id)->toBeNull(); // rechazado, no se vinculó
    expect($alreadyLinked->fresh()->employee_id)->toBe($employee->id); // el vínculo original queda intacto
});

it('a non-admin cannot link employees', function () {
    $colaborador = User::factory()->create(['role' => 'colaborador']);
    $target = User::factory()->create(['role' => 'colaborador']);
    $employee = Employee::query()->create(['full_name' => 'NO AUTORIZADO', 'normalized_name' => 'no autorizado', 'is_active' => true]);

    $this->actingAs($colaborador)->put("/okr/responsibles/{$target->id}/employee", ['employee_id' => $employee->id])
        ->assertForbidden();

    expect($target->fresh()->employee_id)->toBeNull();
});
