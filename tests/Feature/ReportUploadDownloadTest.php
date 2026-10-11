<?php

use App\Enums\ReportUploadStatus;
use App\Models\DataSource;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makeDownloadableUpload(string $storedPath, string $bytes): array
{
    $user = User::factory()->create();

    $period = Period::query()->create([
        'name' => 'Semana 1 - Abril 2026',
        'code' => 'W-2026-04-DL-' . uniqid(),
        'type' => 'weekly',
        'year' => 2026,
        'month' => 4,
        'sequence' => 1,
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-05',
        'is_closed' => false,
    ]);

    $source = DataSource::query()->create([
        'code' => 'noi_nomina_dl_' . uniqid(),
        'name' => 'NOI Nómina',
        'description' => 'Importación NOI',
        'is_active' => true,
    ]);

    Storage::disk('public')->put($storedPath, $bytes);

    $upload = ReportUpload::query()->create([
        'period_id' => $period->id,
        'data_source_id' => $source->id,
        'original_name' => 'Nómina Original.xlsx',
        'stored_path' => $storedPath,
        'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'file_size' => Storage::disk('public')->size($storedPath),
        'uploaded_by' => $user->id,
        'uploaded_at' => now(),
        'status' => ReportUploadStatus::Pending,
        'notes' => null,
    ]);

    return [$upload, $user];
}

it('downloads the original file bytes untouched, preserving the original name', function () {
    Storage::fake('public');

    $bytes = random_bytes(2048);
    $storedPath = 'report_uploads/original_download_test.xlsx';
    [$upload, $user] = makeDownloadableUpload($storedPath, $bytes);

    $response = $this->actingAs($user)->get("/historico-general/{$upload->id}/descargar");

    $response->assertOk();
    expect(hash('sha256', $response->streamedContent()))->toBe(hash('sha256', $bytes));
    $response->assertHeader('content-disposition');
});

it('returns a controlled 404 when the physical file no longer exists on disk', function () {
    Storage::fake('public');

    $storedPath = 'report_uploads/missing_physical_file.xlsx';
    [$upload, $user] = makeDownloadableUpload($storedPath, 'contenido');

    Storage::disk('public')->delete($storedPath);

    $this->actingAs($user)
        ->get("/historico-general/{$upload->id}/descargar")
        ->assertNotFound();
});

it('requires authentication to download an original file', function () {
    Storage::fake('public');

    $storedPath = 'report_uploads/auth_required.xlsx';
    [$upload] = makeDownloadableUpload($storedPath, 'contenido');

    $this->get("/historico-general/{$upload->id}/descargar")
        ->assertRedirect('/login');
});
