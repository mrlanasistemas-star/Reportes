<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/** Sucursales operativas que aparecen en el directorio (nombres EXACTOS de BD). */
function giSeedBranches(): void
{
    foreach (['ATLACOMULCO', 'ATLIXCO', 'CORDOBA', 'CUERNAVACA', 'HUAMANTLA', 'IXTLAHUACA', 'MIACATLAN', 'ORIZABA', 'SAN LUIS POTOSI', 'TLAXCALA', 'TULA'] as $i => $name) {
        Branch::query()->firstOrCreate(['normalized_name' => mb_strtolower($name)], ['code' => 'B' . (100 + $i), 'name' => $name, 'is_active' => true]);
    }
}

/**
 * Directorio SINTÉTICO con la misma estructura que el de gerencias: hoja
 * "Directorio", encabezado "NOMBRE DEL COLABORADOR", bandas "▎ …" y sección de
 * notas. Personas ficticias; el directorio real nunca se usa en pruebas.
 *  - 4 creables (con NOI y correo coherente)
 *  - 1 a revisar porque el correo no corresponde al nombre
 *  - 1 a revisar porque no tiene NOI
 */
function giFile(): UploadedFile
{
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
    $ws = $book->getActiveSheet()->setTitle('Directorio');
    $ws->fromArray([
        ['DIRECTORIO DE GERENCIAS (EJEMPLO SINTÉTICO)'],
        ['Empresa de prueba · documento ficticio'],
        [null],
        ['NO. NOI', 'NOMBRE DEL COLABORADOR', 'SUCURSAL', 'PUESTO', 'CORREO INSTITUCIONAL'],
        ['▎ SUCURSALES OPERATIVAS'],
        ['9001', 'ANA PRUEBA LOPEZ', 'CORDOBA', 'GERENTE DE SUCURSAL', 'ana.prueba@ejemplo.test'],
        ['9002', 'BRUNO FICTICIO MENDEZ', 'TULA', 'GERENTE DE SUCURSAL', 'bruno.ficticio@ejemplo.test'],
        ['9003', 'CARLA SINTETICA RUIZ', 'ORIZABA', 'GERENTE DE SUCURSAL', 'carla.sintetica@ejemplo.test'],
        ['9004', 'DIEGO MODELO PEREZ', 'TLAXCALA', 'GERENTE DE SUCURSAL', 'diego.modelo@ejemplo.test'],
        ['9005', 'ELENA DEMO SOTO', 'CUERNAVACA', 'GERENTE DE SUCURSAL', 'elena.ajeno@ejemplo.test'],
        [null, 'FABIAN ENSAYO VEGA', 'ATLIXCO', 'GERENTE DE SUCURSAL', 'fabian.ensayo@ejemplo.test'],
        [null],
        ['NOTAS Y OBSERVACIONES'],
        ['9999', 'FILA POSTERIOR A LAS NOTAS', 'CORDOBA', 'NO DEBE LEERSE', 'no.leer@ejemplo.test'],
    ], null, 'A1');

    $dir = storage_path('framework/testing');
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $path = $dir.'/directorio_sintetico_'.uniqid().'.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

    return new UploadedFile($path, 'DIRECTORIO_SINTETICO.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function giAllEmails(): array
{
    return array_map(fn ($u) => $u.'@ejemplo.test', [
        'ana.prueba', 'bruno.ficticio', 'carla.sintetica', 'diego.modelo', 'elena.ajeno', 'fabian.ensayo',
    ]);
}

it('previsualiza las personas del directorio sintético (ignora bandas y lo posterior a las notas)', function () {
    Storage::fake('local');
    giSeedBranches();
    $admin = User::factory()->create(['role' => 'admin']);

    $res = $this->actingAs($admin)->post(route('usuarios.import-preview'), ['file' => giFile()]);
    $res->assertSessionHasNoErrors()->assertSessionHas('import_preview');

    $preview = session('import_preview');
    expect($preview['summary'])->toMatchArray(['total' => 6, 'create' => 4, 'review' => 2, 'exists' => 0, 'error' => 0]);
    // Las 2 a revisar: correo que no corresponde al nombre + persona sin NOI.
    $review = collect($preview['rows'])->where('status', 'needs_review')->pluck('name')->all();
    expect($review)->toContain('ELENA DEMO SOTO', 'FABIAN ENSAYO VEGA');
});

it('crea las 6 cuentas con rol gerencial y alcance = su sucursal, sin contraseñas en claro y sin enviar correos', function () {
    Storage::fake('local');
    giSeedBranches();
    Mail::fake();
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post(route('usuarios.import-preview'), ['file' => giFile()])->assertSessionHas('import_preview');
    $this->actingAs($admin)->post(route('usuarios.import'), ['emails' => giAllEmails(), 'confirmed' => true])
        ->assertSessionHasNoErrors()->assertSessionHas('import_result');

    expect(User::query()->where('role', 'gerencial')->count())->toBe(6);
    Mail::assertNothingSent(); // por omisión NO se envían invitaciones (SMTP sin verificar)

    $u = User::query()->where('email', 'ana.prueba@ejemplo.test')->first();
    expect($u)->not->toBeNull()
        ->and($u->role)->toBe('gerencial')
        ->and($u->access_enabled_at)->toBeNull()        // queda pendiente de activación
        ->and($u->branch_scope)->toBe('branches');
    // Alcance exactamente 1 sucursal (CORDOBA), nunca «todas».
    $scopeCount = DB::table('user_branch_scopes')->where('user_id', $u->id)->count();
    expect($scopeCount)->toBe(1);
    // En BD solo vive un hash, nunca el claro.
    expect($u->password)->toStartWith('$')->and(mb_strlen($u->password))->toBeGreaterThan(50);

    // El reporte de credenciales quedó sellado para descarga única y CADA cuenta
    // creada trae su contraseña temporal, que realmente abre la cuenta.
    expect(session('import_credentials'))->toBeString();
    $report = \App\Http\Controllers\Okr\ResponsibleController::revealSecret(session('import_credentials'));
    $created = collect($report)->where('creation', 'Creada');
    expect($created)->toHaveCount(6);
    $created->each(fn ($row) => expect($row['temporary_password'])->toBeString()->not->toBeEmpty());
    $ana = $created->firstWhere('email', 'ana.prueba@ejemplo.test');
    expect(\Illuminate\Support\Facades\Hash::check($ana['temporary_password'], $u->password))->toBeTrue();
});

it('es idempotente: subir y confirmar el mismo archivo otra vez no crea cuentas ni toca las existentes', function () {
    Storage::fake('local');
    giSeedBranches();
    Mail::fake();
    $admin = User::factory()->create(['role' => 'admin']);

    // Primer lote.
    $this->actingAs($admin)->post(route('usuarios.import-preview'), ['file' => giFile()]);
    $this->actingAs($admin)->post(route('usuarios.import'), ['emails' => giAllEmails(), 'confirmed' => true]);
    expect(User::query()->where('role', 'gerencial')->count())->toBe(6);
    $hashBefore = User::query()->where('email', 'ana.prueba@ejemplo.test')->value('password');

    // Segundo intento idéntico.
    $this->actingAs($admin)->post(route('usuarios.import-preview'), ['file' => giFile()])->assertSessionHas('import_preview');
    expect(session('import_preview')['summary'])->toMatchArray(['total' => 6, 'create' => 0, 'review' => 0, 'exists' => 6, 'error' => 0]);

    $this->actingAs($admin)->post(route('usuarios.import'), ['emails' => giAllEmails(), 'confirmed' => true])->assertSessionHas('import_result');
    expect(session('import_result')['summary'])->toMatchArray(['created' => 0, 'existing' => 6]);
    expect(User::query()->where('role', 'gerencial')->count())->toBe(6);
    // La contraseña de una cuenta existente no se tocó.
    expect(User::query()->where('email', 'ana.prueba@ejemplo.test')->value('password'))->toBe($hashBefore);
});

it('descarga el reporte de credenciales una sola vez y exige confirmación y entorno', function () {
    Storage::fake('local');
    giSeedBranches();
    Mail::fake();
    $admin = User::factory()->create(['role' => 'admin']);

    // Sin confirmar → error de validación.
    $this->actingAs($admin)->post(route('usuarios.import-preview'), ['file' => giFile()]);
    $this->actingAs($admin)->post(route('usuarios.import'), ['emails' => giAllEmails()])
        ->assertSessionHasErrors('confirmed');

    // Confirmado → crea y deja el reporte disponible.
    $this->actingAs($admin)->post(route('usuarios.import-preview'), ['file' => giFile()]);
    $this->actingAs($admin)->post(route('usuarios.import'), ['emails' => giAllEmails(), 'confirmed' => true])->assertSessionHas('import_result');

    $this->actingAs($admin)->get(route('usuarios.import-credentials'))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    // Segunda descarga: ya se consumió.
    $this->actingAs($admin)->get(route('usuarios.import-credentials'))->assertRedirect();
});

it('solo un administrador puede importar', function () {
    Storage::fake('local');
    giSeedBranches();
    $gerencial = User::factory()->create(['role' => 'gerencial']);

    $this->actingAs($gerencial)->post(route('usuarios.import-preview'), ['file' => giFile()])->assertForbidden();
});
