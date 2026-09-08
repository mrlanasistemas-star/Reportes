<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// Test T — evidencia valida MIME/permisos.
// CORRECCIÓN 09-sep-2026 (punto 8 de la auditoría): las evidencias NUEVAS se
// guardan en el disco 'local' (storage/app/private — nunca expuesto por URL
// pública), ya no en 'public'.
it('accepts a valid evidence file (pdf) and stores it via the private local disk, never public', function () {
    Storage::fake('local');
    Storage::fake('public');
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Cordoba'), [100.0]);

    $file = UploadedFile::fake()->create('evidencia.pdf', 500, 'application/pdf');
    $this->actingAs($user)->post(route('okr.evidences.store', $objective), [
        'file' => $file, 'comment' => 'Reporte de cobranza semana 1',
    ])->assertSessionHasNoErrors();

    expect($objective->evidences()->count())->toBe(1);
    $evidence = $objective->evidences()->first();
    expect($evidence->disk)->toBe('local');
    Storage::disk('local')->assertExists($evidence->stored_path);
    Storage::disk('public')->assertMissing($evidence->stored_path);
});

it('still downloads a legacy evidence stored on the public disk before this correction', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Tenango del Valle'), [100.0]);
    Storage::disk('public')->put('okr-evidences/legacy.pdf', 'contenido');
    $evidence = $objective->evidences()->create([
        'original_name' => 'legacy.pdf', 'stored_path' => 'okr-evidences/legacy.pdf', 'disk' => 'public',
        'mime_type' => 'application/pdf', 'size_bytes' => 9, 'uploaded_by' => $user->id,
    ]);

    $this->actingAs($user)->get(route('okr.evidences.download', $evidence))->assertOk();
});

it('rejects a file type outside the allowed MIME list', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Miacatlan'), [100.0]);

    $file = UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload');
    $this->actingAs($user)->post(route('okr.evidences.store', $objective), ['file' => $file])
        ->assertSessionHasErrors('file');

    expect($objective->evidences()->count())->toBe(0);
});

it('rejects a file over the size limit', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Huamantla'), [100.0]);

    $file = UploadedFile::fake()->create('evidencia.pdf', 20000, 'application/pdf'); // 20MB > 10MB
    $this->actingAs($user)->post(route('okr.evidences.store', $objective), ['file' => $file])
        ->assertSessionHasErrors('file');
});

it('an unauthenticated user cannot download an evidence file', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Tula'), [100.0]);
    $evidence = $objective->evidences()->create([
        'original_name' => 'x.pdf', 'stored_path' => 'okr-evidences/x.pdf', 'mime_type' => 'application/pdf',
        'size_bytes' => 10, 'uploaded_by' => $user->id,
    ]);

    $this->get(route('okr.evidences.download', $evidence))->assertRedirect(route('login'));
});
