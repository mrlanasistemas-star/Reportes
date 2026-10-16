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

function giFile(): UploadedFile
{
    $path = base_path('docs/usuarios/DIRECTORIO_GERENCIAS_MRLANA.xlsx');

    return new UploadedFile($path, 'DIRECTORIO_GERENCIAS_MRLANA.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function giAllEmails(): array
{
    // Las 25 personas del directorio (todas creables: 21 nuevas + 4 a revisar).
    return array_map(fn ($u) => $u . '@mr-lana.com', [
        'eliot.serrano', 'angelica.granados', 'luis.lopez', 'ramses.rojas', 'jaime.valverde', 'maribel.diaz',
        'ricardo.rocha', 'hesiquio.martinez', 'juan.gonzalez', 'rosalinda.cuellar', 'luis.garay', 'ana.sanchez',
        'jose.gonzalez', 'monica.ocampo', 'ivan.quevedo', 'lizette.aguilar', 'alejandra.rosas', 'hugo.herrera',
        'mercedes.rodriguez', 'isaias.farias', 'marlen.razo', 'maricela.flores', 'monica.garcia', 'lidia.jimenez', 'maria.godoy',
    ]);
}

it('previsualiza las 25 personas reales del directorio (ignora los contadores erróneos de la hoja)', function () {
    Storage::fake('local');
    giSeedBranches();
    $admin = User::factory()->create(['role' => 'admin']);

    $res = $this->actingAs($admin)->post(route('usuarios.import-preview'), ['file' => giFile()]);
    $res->assertSessionHasNoErrors()->assertSessionHas('import_preview');

    $preview = session('import_preview');
    expect($preview['summary'])->toMatchArray(['total' => 25, 'create' => 21, 'review' => 4, 'exists' => 0, 'error' => 0]);
    // Las 4 a revisar: Angélica (correo dudoso) + los 3 sin NOI.
    $review = collect($preview['rows'])->where('status', 'needs_review')->pluck('name')->all();
    expect($review)->toContain('ANGELICA AZUCENA GONZALEZ', 'JAIME VALVERDE ERIVES', 'HUGO SAUL HERRERA RAFFUL', 'ISAIAS FARIAS BONILLA');
});

it('crea las 25 cuentas con rol gerencial y alcance = su sucursal, sin contraseñas en claro y sin enviar correos', function () {
    Storage::fake('local');
    giSeedBranches();
    Mail::fake();
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post(route('usuarios.import-preview'), ['file' => giFile()])->assertSessionHas('import_preview');
    $this->actingAs($admin)->post(route('usuarios.import'), ['emails' => giAllEmails(), 'confirmed' => true])
        ->assertSessionHasNoErrors()->assertSessionHas('import_result');

    expect(User::query()->where('role', 'gerencial')->count())->toBe(25);
    Mail::assertNothingSent(); // por omisión NO se envían invitaciones (SMTP sin verificar)

    $u = User::query()->where('email', 'eliot.serrano@mr-lana.com')->first();
    expect($u)->not->toBeNull()
        ->and($u->role)->toBe('gerencial')
        ->and($u->access_enabled_at)->toBeNull()        // queda pendiente de activación
        ->and($u->branch_scope)->toBe('branches');
    // Alcance exactamente 1 sucursal (ATLACOMULCO), nunca «todas».
    $scopeCount = DB::table('user_branch_scopes')->where('user_id', $u->id)->count();
    expect($scopeCount)->toBe(1);
    // En BD solo vive un hash, nunca el claro.
    expect($u->password)->toStartWith('$')->and(mb_strlen($u->password))->toBeGreaterThan(50);

    // El reporte de credenciales quedó sellado para descarga única.
    expect(session('import_credentials'))->toBeString();
});

it('es idempotente: subir y confirmar el mismo archivo otra vez no crea cuentas ni toca las existentes', function () {
    Storage::fake('local');
    giSeedBranches();
    Mail::fake();
    $admin = User::factory()->create(['role' => 'admin']);

    // Primer lote.
    $this->actingAs($admin)->post(route('usuarios.import-preview'), ['file' => giFile()]);
    $this->actingAs($admin)->post(route('usuarios.import'), ['emails' => giAllEmails(), 'confirmed' => true]);
    expect(User::query()->where('role', 'gerencial')->count())->toBe(25);
    $hashBefore = User::query()->where('email', 'eliot.serrano@mr-lana.com')->value('password');

    // Segundo intento idéntico.
    $this->actingAs($admin)->post(route('usuarios.import-preview'), ['file' => giFile()])->assertSessionHas('import_preview');
    expect(session('import_preview')['summary'])->toMatchArray(['total' => 25, 'create' => 0, 'review' => 0, 'exists' => 25, 'error' => 0]);

    $this->actingAs($admin)->post(route('usuarios.import'), ['emails' => giAllEmails(), 'confirmed' => true])->assertSessionHas('import_result');
    expect(session('import_result')['summary'])->toMatchArray(['created' => 0, 'existing' => 25]);
    expect(User::query()->where('role', 'gerencial')->count())->toBe(25);
    // La contraseña de una cuenta existente no se tocó.
    expect(User::query()->where('email', 'eliot.serrano@mr-lana.com')->value('password'))->toBe($hashBefore);
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
