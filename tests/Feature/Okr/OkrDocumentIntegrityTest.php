<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\OkrCommitmentLetter;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\OkrPlacementUpload;
use App\Models\OkrProgressSnapshot;
use App\Models\OkrWarning;
use App\Models\User;
use App\Services\Okr\OkrCommitmentLetterService;
use App\Services\Okr\OkrPlacementImportService;
use App\Services\Okr\OkrPlacementUploadConflictException;
use App\Services\Okr\OkrWarningService;
use App\Services\Pdf\BrowsershotPdfRenderer;
use App\Services\Pdf\PdfRenderException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * 4.8/4.9/Fase 5 (19-oct-2026) — integridad y concurrencia de documentos OKR:
 * nunca dos vigentes de la misma clase, nunca "emitido" sin PDF, sin archivos
 * huérfanos, anulación que conserva folio/archivo/motivo, copia firmada real
 * y mensajes específicos cuando falta un archivo.
 *
 * Este entorno no ejecuta Chrome: el motor se sustituye por dobles que
 * escriben un PDF mínimo, fallan, no escriben nada o escriben basura — los
 * cuatro desenlaces reales de Browsershot.
 */
function diRenderer(string $mode = 'ok', ?callable $during = null): void
{
    $renderer = Mockery::mock(BrowsershotPdfRenderer::class);
    $renderer->shouldReceive('renderViewToFile')->andReturnUsing(function (string $view, array $data, string $path) use ($mode, $during) {
        if ($during) {
            $during();
        }
        File::ensureDirectoryExists(dirname($path));
        match ($mode) {
            'ok' => File::put($path, "%PDF-1.4\n% documento de prueba\n"),
            'garbage' => File::put($path, '<html>no soy un pdf</html>'),
            'nothing' => null,
            'throw_after_partial' => (function () use ($path) {
                File::put($path, '%PDF-1.4 a medias');
                throw PdfRenderException::timeout(new RuntimeException('Chrome se cerró a la mitad'));
            })(),
            'throw' => throw PdfRenderException::htmlFailed(new RuntimeException('chrome murió')),
        };
    });
    app()->instance(BrowsershotPdfRenderer::class, $renderer);
}

function diObjective(bool $withLetter = false): array
{
    $branch = Branch::query()->create(['code' => 'DI' . random_int(10, 99), 'name' => 'INTEGRIDAD ' . uniqid(), 'normalized_name' => 'integridad ' . uniqid(), 'is_active' => true]);
    $employee = Employee::query()->create(['full_name' => 'RAFAEL PEREZ CHAO', 'normalized_name' => 'rafael perez chao ' . uniqid(), 'employee_code' => 'NOI-4819', 'is_active' => true]);
    $admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin Integridad']);
    $kpi = OkrKpi::query()->create([
        'name' => 'Colocación', 'code' => 'di_kpi_' . uniqid(), 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE,
        'direction' => OkrKpi::DIRECTION_INCREASE, 'automation' => OkrKpi::AUTOMATION_MANUAL, 'is_active' => true,
    ]);
    $objective = OkrObjective::query()->create([
        'scope_type' => 'employee', 'branch_id' => $branch->id, 'employee_id' => $employee->id,
        'title' => 'Incrementar la colocación de Rafael', 'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now()->subWeeks(3)->startOfDay(), 'end_date' => now()->addWeek(), 'duration_weeks' => 4,
        'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now()->subWeeks(3),
    ]);
    $kr = $objective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'KR', 'baseline_value' => 100_000, 'target_value' => 400_000, 'weight' => 100, 'baseline_locked_at' => now()]);
    if ($withLetter) {
        OkrCommitmentLetter::query()->create([
            'folio' => 'CC-DI-' . uniqid(), 'okr_objective_id' => $objective->id, 'subject_employee_id' => $employee->id, 'status' => 'issued',
            'snapshot' => ['collaborator_name' => $employee->full_name, 'employee_code' => 'ATLX04819', 'position' => 'GESTOR', 'immediate_boss' => 'SUBGERENTE ATLIXCO'],
            'place' => 'Cuernavaca', 'generated_by' => $admin->id, 'generated_at' => now()->subWeeks(3), 'stored_path' => 'x.pdf', 'disk' => 'local',
        ]);
    }

    return [$objective, $kr, $admin, $employee];
}

function diBreach($kr, int $week): void
{
    OkrProgressSnapshot::query()->create([
        'okr_key_result_id' => $kr->id, 'week_number' => $week, 'snapshot_date' => now()->subWeeks(4 - $week)->toDateString(),
        'actual_value' => 10_000, 'expected_value' => 150_000, 'actual_progress_percentage' => 5.0,
        'expected_progress_percentage' => 40.0, 'deviation_pp' => -35.0, 'calculated_at' => now(),
    ]);
}

/** Todos los PDF (incluidos temporales ocultos) bajo un directorio del disco local. */
function diFilesUnder(string $dir): array
{
    $abs = Storage::disk('local')->path($dir);

    return is_dir($abs) ? array_map(fn ($f) => $f->getFilename(), File::allFiles($abs, true)) : [];
}

beforeEach(function () {
    Storage::fake('local');
});

it('double click / two admins: issuing the letter twice leaves ONE current letter and returns the same folio', function () {
    diRenderer();
    [$objective, , $admin] = diObjective();
    $other = User::factory()->create(['role' => 'admin', 'name' => 'Segundo Admin']);
    $service = app(OkrCommitmentLetterService::class);

    // Instancia "vieja" del OKR: cargó la relación ANTES de que la otra solicitud emitiera.
    $stale = OkrObjective::query()->with('commitmentLetter')->findOrFail($objective->id);
    expect($stale->commitmentLetter)->toBeNull();

    $first = $service->generate($objective->fresh(), $admin, 'Cuernavaca', ['position' => 'GESTOR', 'immediate_boss' => 'SUBGERENTE ATLIXCO']);
    $second = $service->generate($stale, $other, 'Cuernavaca', ['position' => 'GESTOR', 'immediate_boss' => 'SUBGERENTE ATLIXCO']);

    expect($second->id)->toBe($first->id)->and($second->folio)->toBe($first->folio);
    expect(OkrCommitmentLetter::query()->where('okr_objective_id', $objective->id)->where('status', 'issued')->count())->toBe(1);
    expect(diFilesUnder('okr-commitment-letters/' . $objective->id))->toBe([$first->folio . '.pdf']);
});

it('the database itself refuses two current letters for the same OKR (unique guard), but keeps any number of voided ones', function () {
    [$objective, , $admin] = diObjective();
    $base = ['okr_objective_id' => $objective->id, 'snapshot' => [], 'place' => 'X', 'generated_by' => $admin->id, 'generated_at' => now(), 'stored_path' => 'a.pdf', 'disk' => 'local'];

    OkrCommitmentLetter::query()->create($base + ['folio' => 'CC-A', 'status' => 'voided']);
    OkrCommitmentLetter::query()->create($base + ['folio' => 'CC-B', 'status' => 'voided']);
    OkrCommitmentLetter::query()->create($base + ['folio' => 'CC-C', 'status' => 'issued']);

    expect(fn () => OkrCommitmentLetter::query()->create($base + ['folio' => 'CC-D', 'status' => 'issued']))->toThrow(QueryException::class);
    expect(OkrCommitmentLetter::query()->where('okr_objective_id', $objective->id)->count())->toBe(3);
});

it('a Browsershot failure leaves no letter, no folio and no file — and the user gets a reference', function () {
    diRenderer('throw');
    [$objective, , $admin] = diObjective();

    $res = $this->actingAs($admin)->postJson(route('okr.commitment-letter.generate', $objective), ['position' => 'GESTOR', 'immediate_boss' => 'SUBGERENTE ATLIXCO'])->assertStatus(500);

    expect($res->json('message'))->toContain('no se emitió ningún folio')->toContain('CC-PDF-');
    expect(OkrCommitmentLetter::query()->where('okr_objective_id', $objective->id)->exists())->toBeFalse();
    expect(diFilesUnder('okr-commitment-letters'))->toBe([]);
});

it('an interrupted generation (partial file, then crash) leaves no orphan file and no issued letter', function () {
    diRenderer('throw_after_partial');
    [$objective, , $admin] = diObjective();

    $this->actingAs($admin)->postJson(route('okr.commitment-letter.generate', $objective), ['position' => 'GESTOR', 'immediate_boss' => 'SUBGERENTE ATLIXCO'])->assertStatus(500);

    expect(OkrCommitmentLetter::query()->count())->toBe(0);
    expect(diFilesUnder('okr-commitment-letters'))->toBe([]);
});

it('an engine that "finishes" without a valid PDF never produces an issued letter without PDF', function (string $mode) {
    diRenderer($mode);
    [$objective, , $admin] = diObjective();

    $res = $this->actingAs($admin)->postJson(route('okr.commitment-letter.generate', $objective), ['position' => 'GESTOR', 'immediate_boss' => 'SUBGERENTE ATLIXCO'])->assertStatus(500);

    expect($res->json('message'))->toContain('no se emitió ningún folio');
    expect(OkrCommitmentLetter::query()->count())->toBe(0);
    expect(diFilesUnder('okr-commitment-letters'))->toBe([]);
})->with(['sin archivo' => 'nothing', 'archivo que no es PDF' => 'garbage']);

it('voiding keeps folio, file and reason; the replacement gets a NEW folio and is the only current one', function () {
    diRenderer();
    [$objective, , $admin] = diObjective();
    $service = app(OkrCommitmentLetterService::class);

    $first = $service->generate($objective, $admin, 'Cuernavaca', ['position' => 'GESTOR', 'immediate_boss' => 'SUBGERENTE ATLIXCO']);
    $this->actingAs($admin)->postJson(route('okr.commitment-letters.void', $first), ['reason' => 'El puesto impreso era incorrecto.'])->assertOk();
    $second = $service->generate($objective->fresh(), $admin, 'Cuernavaca', ['position' => 'GESTOR SR', 'immediate_boss' => 'SUBGERENTE ATLIXCO']);

    $first->refresh();
    expect($first->status)->toBe('voided')->and($first->void_reason)->toBe('El puesto impreso era incorrecto.')->and($first->current_guard)->toBeNull();
    expect(Storage::disk('local')->exists($first->stored_path))->toBeTrue();
    expect($second->folio)->not->toBe($first->folio)->and($second->replaces_letter_id)->toBe($first->id);
    expect(OkrCommitmentLetter::query()->where('okr_objective_id', $objective->id)->where('status', 'issued')->pluck('id')->all())->toBe([$second->id]);
    // El documento emitido es un SNAPSHOT: el primero conserva su puesto aunque el segundo cambie.
    expect($first->snapshot['position'])->toBe('GESTOR')->and($second->snapshot['position'])->toBe('GESTOR SR');
});

it('two Warnings for the same week: the second is refused with the existing folio and nothing is duplicated', function () {
    diRenderer();
    [$objective, $kr, $admin] = diObjective(withLetter: true);
    diBreach($kr, 2);
    $service = app(OkrWarningService::class);

    $first = $service->generate($objective, 2, 'Plan de mejora semanal.', null, $admin);
    expect(fn () => $service->generate($objective->fresh(), 2, 'Segundo intento simultáneo.', null, $admin))
        ->toThrow(RuntimeException::class, $first->folio);

    expect(OkrWarning::query()->where('okr_objective_id', $objective->id)->where('week_number', 2)->count())->toBe(1);
    expect(diFilesUnder('okr-warnings/' . $objective->id))->toBe([$first->folio . '.pdf']);
});

it('a Warning whose PDF fails is not issued, keeps no file and can be retried cleanly', function () {
    diRenderer('throw');
    [$objective, $kr, $admin] = diObjective(withLetter: true);
    diBreach($kr, 2);

    $this->actingAs($admin)->postJson(route('okr.warnings.store', $objective), ['week_number' => 2, 'corrective_actions' => 'Plan de mejora.', 'authorized' => true])
        ->assertStatus(500)->assertJsonPath('message', fn ($m) => str_contains($m, 'no se emitió ningún folio'));
    expect(OkrWarning::query()->count())->toBe(0);
    expect(diFilesUnder('okr-warnings'))->toBe([]);

    diRenderer();
    $this->actingAs($admin)->postJson(route('okr.warnings.store', $objective), ['week_number' => 2, 'corrective_actions' => 'Plan de mejora.', 'authorized' => true])->assertOk();
    expect(OkrWarning::query()->where('status', 'issued')->count())->toBe(1);
});

it('the database refuses two current Warnings of the same week (unique guard)', function () {
    [$objective, , $admin] = diObjective();
    $base = ['okr_objective_id' => $objective->id, 'week_number' => 1, 'week_start' => now(), 'week_end' => now(), 'snapshot' => [], 'corrective_actions' => 'x',
        'generated_by' => $admin->id, 'generated_at' => now(), 'stored_path' => 'w.pdf', 'disk' => 'local'];
    OkrWarning::query()->create($base + ['folio' => 'WR-1', 'status' => 'issued']);
    OkrWarning::query()->create($base + ['folio' => 'WR-2', 'status' => 'voided']);
    OkrWarning::query()->create(array_merge($base, ['folio' => 'WR-3', 'week_number' => 2, 'status' => 'issued']));

    expect(fn () => OkrWarning::query()->create($base + ['folio' => 'WR-4', 'status' => 'issued']))->toThrow(QueryException::class);
});

it('signed copy: must be a real PDF, can be viewed in the system, and replacing it needs explicit confirmation (audited)', function () {
    diRenderer();
    [$objective, , $admin] = diObjective();
    $letter = app(OkrCommitmentLetterService::class)->generate($objective, $admin, 'Cuernavaca', ['position' => 'GESTOR', 'immediate_boss' => 'SUBGERENTE ATLIXCO']);
    $url = route('okr.commitment-letters.signed', $letter);

    $this->actingAs($admin)->postJson($url, ['file' => UploadedFile::fake()->createWithContent('firmada.pdf', 'no soy un pdf')])
        ->assertStatus(422)->assertJsonPath('message', 'El archivo no es un PDF válido. Escanea el documento firmado y guárdalo como PDF.');

    $this->actingAs($admin)->get(route('okr.commitment-letters.download', $letter) . '?view=1&signed=1')
        ->assertStatus(404)->assertSee('todavía no tiene copia firmada cargada', false);

    $this->actingAs($admin)->postJson($url, ['file' => UploadedFile::fake()->createWithContent('firmada.pdf', "%PDF-1.4\nfirmas\n")])->assertOk()->assertJsonPath('replaced', false);
    $letter->refresh();
    expect($letter->isSigned())->toBeTrue();

    $view = $this->actingAs($admin)->get(route('okr.commitment-letters.download', $letter) . '?view=1&signed=1')->assertOk();
    expect($view->headers->get('content-disposition'))->toStartWith('inline');
    $original = $this->actingAs($admin)->get(route('okr.commitment-letters.download', $letter) . '?view=1')->assertOk();
    expect($original->streamedContent())->toContain('documento de prueba'); // el emitido NO se tocó

    $this->actingAs($admin)->postJson($url, ['file' => UploadedFile::fake()->createWithContent('otra.pdf', "%PDF-1.4\notra\n")])
        ->assertStatus(409)->assertJsonPath('action', 'confirm_replace');
    $firstSigned = $letter->signed_stored_path;
    $this->actingAs($admin)->postJson($url, ['file' => UploadedFile::fake()->createWithContent('otra.pdf', "%PDF-1.4\notra\n"), 'replace' => true])->assertOk()->assertJsonPath('replaced', true);

    expect(Storage::disk('local')->exists($firstSigned))->toBeTrue(); // la anterior se conserva
    expect(\App\Models\OkrAuditLog::query()->where('auditable_id', $objective->id)->where('action', 'signed_copy_replaced')->where('old_value', $firstSigned)->exists())->toBeTrue();
});

it('a voided letter refuses a signed copy with a specific message', function () {
    diRenderer();
    [$objective, , $admin] = diObjective();
    $letter = app(OkrCommitmentLetterService::class)->generate($objective, $admin, 'Cuernavaca', ['position' => 'GESTOR', 'immediate_boss' => 'SUBGERENTE ATLIXCO']);
    app(OkrCommitmentLetterService::class)->void($letter, $admin, 'Datos incorrectos en el documento.');

    $this->actingAs($admin)->postJson(route('okr.commitment-letters.signed', $letter), ['file' => UploadedFile::fake()->createWithContent('f.pdf', "%PDF-1.4\n")])
        ->assertStatus(422)->assertJsonPath('message', "La carta {$letter->folio} está anulada: no admite copia firmada. Sube la firma a la carta vigente.");
});

it('a missing PDF answers with a specific, readable message (viewer and JSON), never a framework error', function () {
    diRenderer();
    [$objective, , $admin] = diObjective();
    $letter = app(OkrCommitmentLetterService::class)->generate($objective, $admin, 'Cuernavaca', ['position' => 'GESTOR', 'immediate_boss' => 'SUBGERENTE ATLIXCO']);
    Storage::disk('local')->delete($letter->stored_path);

    $html = $this->actingAs($admin)->get(route('okr.commitment-letters.download', $letter) . '?view=1')->assertStatus(404);
    expect($html->headers->get('content-type'))->toContain('text/html');
    $html->assertSee("No encontramos el PDF de {$letter->folio} en el almacenamiento.", false)->assertSee('no vuelvas a emitir el documento', false);

    $this->actingAs($admin)->getJson(route('okr.commitment-letters.download', $letter))->assertStatus(404)
        ->assertJsonPath('message', fn ($m) => str_contains($m, $letter->folio));

    $review = $this->actingAs($admin)->getJson(route('okr.commitment-letter.review', $objective))->assertOk();
    expect($review->json('letters.0.file_available'))->toBeFalse();
});

function diPlacementCsv(array $rows): UploadedFile
{
    $lines = ["Gestor,Monto,Fecha"];
    foreach ($rows as $r) {
        $lines[] = implode(',', $r);
    }

    return UploadedFile::fake()->createWithContent('colocacion.csv', implode("\n", $lines));
}

it('a placement file that cannot be parsed is not left orphaned in storage', function () {
    [$objective, , $admin] = diObjective();
    $branchObjective = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $objective->branch_id, 'title' => 'Sucursal', 'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => $objective->start_date, 'end_date' => $objective->end_date, 'duration_weeks' => 4, 'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $bad = UploadedFile::fake()->createWithContent('colocacion.csv', "Columna,Otra\nA,B\n");

    expect(fn () => app(OkrPlacementImportService::class)->import($branchObjective, 1, $bad, $admin))->toThrow(RuntimeException::class);
    expect(diFilesUnder('okr-placement-uploads'))->toBe([]);
    expect(OkrPlacementUpload::query()->count())->toBe(0);
});

it('a second upload of the same week that lost the race becomes a conflict, never a second active upload (and leaves no file)', function () {
    [$objective, , $admin] = diObjective();
    $branchObjective = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $objective->branch_id, 'title' => 'Sucursal', 'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => $objective->start_date, 'end_date' => $objective->end_date, 'duration_weeks' => 4, 'lifecycle_status' => OkrObjective::STATUS_ACTIVE, 'activated_at' => now(),
    ]);
    $day = $objective->start_date->copy()->addDay()->toDateString();
    app(OkrPlacementImportService::class)->import($branchObjective, 1, diPlacementCsv([['GESTOR X', 1000, $day]]), $admin, false, true);

    // Sin confirmar reemplazo: conflicto explícito, ningún archivo extra.
    expect(fn () => app(OkrPlacementImportService::class)->import($branchObjective, 1, diPlacementCsv([['GESTOR X', 2000, $day]]), $admin))
        ->toThrow(OkrPlacementUploadConflictException::class);
    expect(OkrPlacementUpload::query()->where('okr_objective_id', $branchObjective->id)->where('status', 'active')->count())->toBe(1);
    expect(count(diFilesUnder('okr-placement-uploads/' . $branchObjective->id)))->toBe(1);

    // La BD tampoco admite dos activas de la misma semana.
    $active = OkrPlacementUpload::query()->where('okr_objective_id', $branchObjective->id)->firstOrFail();
    expect(fn () => OkrPlacementUpload::query()->create(array_merge($active->only(['okr_objective_id', 'week_number', 'week_start', 'week_end', 'original_filename', 'stored_path', 'disk', 'uploaded_by', 'total_amount', 'rows_count']), ['status' => 'active'])))
        ->toThrow(QueryException::class);
    expect($active->declared_complete)->toBeTrue();
});

it('MyISAM safety net: a provisional row of a failed issuance is removed explicitly, a confirmed document never', function () {
    [$objective, , $admin] = diObjective();
    $base = ['okr_objective_id' => $objective->id, 'snapshot' => [], 'place' => 'X', 'generated_by' => $admin->id, 'generated_at' => now(), 'stored_path' => '', 'disk' => 'local', 'status' => 'issued'];
    $provisional = OkrCommitmentLetter::query()->create($base + ['folio' => 'PENDIENTE-abc']);
    $issuer = app(\App\Services\Okr\OkrDocumentIssuer::class);

    $issuer->forgetUnconfirmed(OkrCommitmentLetter::class, $provisional->id);
    expect(OkrCommitmentLetter::query()->find($provisional->id))->toBeNull();

    $confirmed = OkrCommitmentLetter::query()->create(array_merge($base, ['folio' => 'CC-2026-000999', 'stored_path' => 'a.pdf']));
    $issuer->forgetUnconfirmed(OkrCommitmentLetter::class, $confirmed->id);
    expect(OkrCommitmentLetter::query()->find($confirmed->id))->not->toBeNull();
});

it('while another issuance of the same OKR holds the lock, a second one waits and then explains instead of duplicating', function () {
    diRenderer();
    [$objective, , $admin] = diObjective();
    $lock = \Illuminate\Support\Facades\Cache::lock('okr-documents:' . $objective->id, 30);
    expect($lock->get())->toBeTrue();

    $issuer = new class extends \App\Services\Okr\OkrDocumentIssuer {
        public function exclusively(string $key, callable $callback, int $waitSeconds = 45): mixed
        {
            return parent::exclusively($key, $callback, 1);
        }
    };
    app()->instance(\App\Services\Okr\OkrDocumentIssuer::class, $issuer);

    expect(fn () => app(OkrCommitmentLetterService::class)->generate($objective, $admin, 'Cuernavaca', ['position' => 'GESTOR', 'immediate_boss' => 'SUBGERENTE ATLIXCO']))
        ->toThrow(RuntimeException::class, 'Otra emisión de un documento de este OKR sigue en curso');
    expect(OkrCommitmentLetter::query()->count())->toBe(0);
    $lock->release();
});
