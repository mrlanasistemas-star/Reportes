<?php

use App\Enums\ReportUploadStatus;
use App\Models\DataSource;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\ReportUploadService;
use App\Support\ReportUploadStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

function privateStorageFixture(): array
{
    $user = User::factory()->create();

    $period = Period::query()->create([
        'name' => 'Semana 1 - Abril 2026',
        'code' => 'W-2026-04-PRIV-'.uniqid(),
        'type' => 'weekly',
        'year' => 2026,
        'month' => 4,
        'sequence' => 1,
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-05',
        'is_closed' => false,
    ]);

    $source = DataSource::query()->create([
        'code' => 'noi_nomina_priv_'.uniqid(),
        'name' => 'NOI Nómina',
        'description' => 'Importación NOI',
        'is_active' => true,
    ]);

    return [$user, $period, $source];
}

function privateStorageUpload(Period $period, DataSource $source, User $user, string $storedPath): ReportUpload
{
    return ReportUpload::query()->create([
        'period_id' => $period->id,
        'data_source_id' => $source->id,
        'original_name' => 'Original.xlsx',
        'stored_path' => $storedPath,
        'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'file_size' => 9,
        'uploaded_by' => $user->id,
        'uploaded_at' => now(),
        'status' => ReportUploadStatus::Pending,
    ]);
}

it('guarda las cargas nuevas en el disco privado, nunca en el público', function () {
    [$user, $period, $source] = privateStorageFixture();
    $this->actingAs($user);

    $upload = app(ReportUploadService::class)->store(
        $period->id, [$period->id], $source->id, UploadedFile::fake()->create('nomina.xlsx', 4), null
    );

    expect($upload->stored_path)->toStartWith('reports/');
    Storage::disk('local')->assertExists($upload->stored_path);
    Storage::disk('public')->assertMissing($upload->stored_path);
});

it('lee primero del privado y, por compatibilidad, del público heredado', function () {
    Storage::disk('public')->put('reports/viejo.xlsx', 'heredado');
    Storage::disk('local')->put('reports/nuevo.xlsx', 'privado');

    expect(ReportUploadStorage::exists('reports/viejo.xlsx'))->toBeTrue()
        ->and(ReportUploadStorage::path('reports/viejo.xlsx'))->toBe(Storage::disk('public')->path('reports/viejo.xlsx'))
        ->and(ReportUploadStorage::path('reports/nuevo.xlsx'))->toBe(Storage::disk('local')->path('reports/nuevo.xlsx'))
        ->and(ReportUploadStorage::exists('reports/no-existe.xlsx'))->toBeFalse()
        ->and(ReportUploadStorage::exists(null))->toBeFalse();
});

it('descarga el original tanto del disco privado como del público heredado', function () {
    [$user, $period, $source] = privateStorageFixture();

    Storage::disk('local')->put('reports/privado.xlsx', 'bytes-priv');
    Storage::disk('public')->put('reports/publico.xlsx', 'bytes-pub');
    $private = privateStorageUpload($period, $source, $user, 'reports/privado.xlsx');
    $legacy = privateStorageUpload($period, $source, $user, 'reports/publico.xlsx');

    $r1 = $this->actingAs($user)->get("/historico-general/{$private->id}/descargar");
    $r1->assertOk();
    expect($r1->streamedContent())->toBe('bytes-priv');

    $r2 = $this->actingAs($user)->get("/historico-general/{$legacy->id}/descargar");
    $r2->assertOk();
    expect($r2->streamedContent())->toBe('bytes-pub');
});

it('mueve las cargas del público al privado conservando la ruta, con simulación e idempotencia', function () {
    [$user, $period, $source] = privateStorageFixture();

    Storage::disk('public')->put('reports/a.xlsx', 'contenido-a');
    Storage::disk('public')->put('report_uploads/b.csv', 'contenido-b');
    Storage::disk('local')->put('reports/c.xlsx', 'ya-privado');
    privateStorageUpload($period, $source, $user, 'reports/a.xlsx');
    privateStorageUpload($period, $source, $user, 'report_uploads/b.csv');
    privateStorageUpload($period, $source, $user, 'reports/c.xlsx');
    privateStorageUpload($period, $source, $user, 'reports/sin-archivo.xlsx');

    // Simulación: no toca nada.
    $this->artisan('reportes:mover-cargas-a-privado', ['--dry-run' => true])->assertSuccessful();
    Storage::disk('public')->assertExists(['reports/a.xlsx', 'report_uploads/b.csv']);
    Storage::disk('local')->assertMissing('reports/a.xlsx');

    // Real: mueve con la misma ruta relativa y deja la BD intacta.
    $this->artisan('reportes:mover-cargas-a-privado')->assertSuccessful();
    expect(Storage::disk('local')->get('reports/a.xlsx'))->toBe('contenido-a')
        ->and(Storage::disk('local')->get('report_uploads/b.csv'))->toBe('contenido-b')
        ->and(Storage::disk('local')->get('reports/c.xlsx'))->toBe('ya-privado');
    Storage::disk('public')->assertMissing(['reports/a.xlsx', 'report_uploads/b.csv']);
    expect(ReportUpload::query()->pluck('stored_path')->all())
        ->toContain('reports/a.xlsx', 'report_uploads/b.csv');

    // Segunda corrida: no hay nada que mover y no falla.
    $this->artisan('reportes:mover-cargas-a-privado')->assertSuccessful();
    expect(Storage::disk('local')->get('reports/a.xlsx'))->toBe('contenido-a');
});

it('no sobrescribe si el archivo existe en ambos discos con contenido distinto', function () {
    [$user, $period, $source] = privateStorageFixture();

    Storage::disk('public')->put('reports/dup.xlsx', 'version-publica');
    Storage::disk('local')->put('reports/dup.xlsx', 'version-privada');
    Storage::disk('public')->put('reports/igual.xlsx', 'mismo');
    Storage::disk('local')->put('reports/igual.xlsx', 'mismo');
    privateStorageUpload($period, $source, $user, 'reports/dup.xlsx');
    privateStorageUpload($period, $source, $user, 'reports/igual.xlsx');

    $this->artisan('reportes:mover-cargas-a-privado')->assertFailed();

    expect(Storage::disk('local')->get('reports/dup.xlsx'))->toBe('version-privada')
        ->and(Storage::disk('public')->get('reports/dup.xlsx'))->toBe('version-publica');
    // La copia pública idéntica sí se limpia.
    Storage::disk('public')->assertMissing('reports/igual.xlsx');
    Storage::disk('local')->assertExists('reports/igual.xlsx');
});

it('elimina el archivo de ambos discos al borrar la carga', function () {
    Storage::disk('local')->put('reports/x.xlsx', '1');
    Storage::disk('public')->put('reports/x.xlsx', '1');

    ReportUploadStorage::delete('reports/x.xlsx');

    Storage::disk('local')->assertMissing('reports/x.xlsx');
    Storage::disk('public')->assertMissing('reports/x.xlsx');
});
