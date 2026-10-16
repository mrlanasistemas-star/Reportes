<?php

use App\Http\Controllers\Okr\ResponsibleController;
use App\Models\Branch;
use App\Models\User;
use App\Services\Access\DataScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/** Asignación masiva de contraseñas y Excel de descarga única (Nombre, Usuario, Contraseña, Correo, Sucursal). */
it('asigna contraseñas nuevas a los seleccionados, omite tu propia cuenta y deja el Excel listo para una sola descarga', function () {
    $admin = User::factory()->create(['role' => 'admin', 'access_enabled_at' => now()]);
    $branch = Branch::query()->create(['code' => 'ORI', 'name' => 'ORIZABA', 'normalized_name' => 'orizaba', 'is_active' => true]);
    $a = User::factory()->create(['name' => 'ANA GERENTE', 'role' => 'gerencial', 'password' => Hash::make('vieja-clave-1')]);
    $b = User::factory()->create(['name' => 'BETO GERENTE', 'role' => 'gerencial', 'password' => Hash::make('vieja-clave-2')]);
    $untouched = User::factory()->create(['role' => 'gerencial', 'password' => Hash::make('no-cambia-3')]);
    app(DataScopeService::class)->save($a, DataScopeService::MODE_BRANCHES, [$branch->id], $admin);

    $this->actingAs($admin)->post(route('usuarios.bulk-reset-passwords'), ['user_ids' => [$a->id, $b->id, $admin->id]])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('bulk_password_result', ['updated' => 2, 'skipped' => 1]);

    $rows = collect(ResponsibleController::revealSecret(session('bulk_password_credentials')));
    expect($rows)->toHaveCount(2);
    $rowA = $rows->firstWhere('email', $a->email);
    expect($rowA)->toMatchArray(['name' => 'ANA GERENTE', 'username' => $a->email, 'branch' => 'ORIZABA'])
        ->and($rowA['password'])->not->toBeEmpty()
        ->and(Hash::check($rowA['password'], $a->fresh()->password))->toBeTrue()
        ->and(Hash::check($rows->firstWhere('email', $b->email)['password'], $b->fresh()->password))->toBeTrue()
        ->and(Hash::check('no-cambia-3', $untouched->fresh()->password))->toBeTrue();

    $response = $this->actingAs($admin)->get(route('usuarios.bulk-passwords-download'))->assertOk();
    $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($response->getFile()->getPathname())->getActiveSheet();
    expect($sheet->rangeToArray('A1:E1')[0])->toBe(['Nombre', 'Usuario', 'Contraseña', 'Correo', 'Sucursal'])
        ->and($sheet->getHighestRow())->toBe(3)
        ->and($sheet->getCell('C2')->getValue())->toBe($rowA['password']);

    // Descarga única.
    $this->actingAs($admin)->get(route('usuarios.bulk-passwords-download'))->assertRedirect();
});

it('no permite la asignación masiva sin el permiso de restablecer ni a un no administrador sobre un administrador', function () {
    $admin = User::factory()->create(['role' => 'admin', 'access_enabled_at' => now()]);
    $viewer = User::factory()->create(['role' => 'gerencial', 'access_enabled_at' => now()]);
    $target = User::factory()->create(['role' => 'gerencial', 'password' => Hash::make('intacta-1')]);

    $this->actingAs($viewer)->post(route('usuarios.bulk-reset-passwords'), ['user_ids' => [$target->id, $admin->id]])
        ->assertForbidden();
    expect(Hash::check('intacta-1', $target->fresh()->password))->toBeTrue();
});
