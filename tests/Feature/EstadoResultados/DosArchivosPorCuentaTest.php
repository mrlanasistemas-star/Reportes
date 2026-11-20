<?php

use App\Models\EstadoResultados\EstadoResultadosImportAttempt;
use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\EstadoResultados\EstadoResultadosUpload;
use App\Models\Period;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use App\Services\EstadoResultados\Import\MovementPeriodAllocator;
use App\Services\EstadoResultados\Sources\SourceChecklist;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * 20-nov-2026: cada estado de cuenta admite «Archivo 1 (obligatorio)» y «Archivo 2 (opcional)».
 * El archivo 2 nunca se exige ni aparece como faltante; lo repetido entre ambos cuenta una vez.
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
    F::fakeRadiography();
    Storage::fake('local');
});

function daPost($test, $user, Period $period, string $path, int $part, string $slot = 'administrativa', string $name = 'estado.xlsx')
{
    return $test->actingAs($user)->post("/estado-resultados/{$period->id}/cargas",
        ['slot' => $slot, 'part' => $part, 'file' => new UploadedFile($path, $name, null, null, true)],
        ['Accept' => 'application/json']);
}

function daImport(Period $period, string $path, int $part, string $slot = 'administrativa'): EstadoResultadosUpload
{
    return app(EstadoResultadosUploadService::class)->importPath($period, $path, basename($path), $slot, null, null, $part);
}

function daDataset(Period $period, bool $process = true): array
{
    if ($process) {
        app(EstadoResultadosProcessor::class)->process($period);
    }

    return app(EstadoResultadosDatasetBuilder::class)->build($period, 'CONSOLIDADO');
}

function daAccount(array $d, string $slot = 'administrativa'): array
{
    return collect($d['sources']['cuentas'])->firstWhere('key', $slot);
}

function daChecklist(array $d, string $slot = 'administrativa'): array
{
    return collect($d['sources']['checklist']['items'])->firstWhere('key', $slot);
}

function daInPeriod(array $d): array
{
    return collect($d['movements'])->where('source', 'banco')->where('in_period', true)->pluck('concept')->sort()->values()->all();
}

it('sube archivo 1 y archivo 2 de la misma cuenta y ambos cuentan', function () {
    $period = F::period();
    $admin = G::admin();

    daPost($this, $admin, $period, F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO UNO', 'CORPORATIVO']]), 1)->assertOk();
    $res = daPost($this, $admin, $period, F::adminXlsx([['2026-09-11', 50.00, null, 'S/R', 'PAGO DOS', 'CORPORATIVO']]), 2, name: 'parte2.xlsx')->assertOk();

    expect($res->json('upload.part'))->toBe(2)
        ->and(EstadoResultadosUpload::query()->active()->count())->toBe(2)
        ->and(EstadoResultadosUpload::query()->active()->pluck('file_part')->sort()->values()->all())->toBe([1, 2])
        ->and(EstadoResultadosImportAttempt::query()->orderBy('id')->pluck('file_part')->all())->toBe([1, 2]);

    $d = daDataset($period);
    $acc = daAccount($d);
    expect(daInPeriod($d))->toBe(['PAGO DOS', 'PAGO UNO'])
        ->and($acc['upload']['part'])->toBe(1)
        ->and(collect($acc['parts'])->pluck('part')->all())->toBe([1, 2])
        ->and($acc['parts'][0]['required'])->toBeTrue()
        ->and($acc['parts'][1]['required'])->toBeFalse()
        ->and($acc['parts'][1]['upload']['name'])->toBe('parte2.xlsx')
        ->and($acc['parts'][1]['upload']['movements'])->toBe(1)
        ->and(daChecklist($d)['state'])->not->toBe('falta');

    $attempts = $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/cargas/intentos")->assertOk()->json('attempts');
    expect($attempts[0]['part'])->toBe(2)
        ->and($attempts[0]['slot_label'])->toContain('Archivo 2');
});

it('sustituir el archivo 2 sólo reemplaza el 2; el 1 sigue vigente', function () {
    $period = F::period();
    $admin = G::admin();
    daPost($this, $admin, $period, F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO UNO', 'CORPORATIVO']]), 1)->assertOk();
    $first2 = daPost($this, $admin, $period, F::adminXlsx([['2026-09-11', 50.00, null, 'S/R', 'PAGO DOS VIEJO', 'CORPORATIVO']]), 2)->assertOk()->json('upload.id');
    $new2 = daPost($this, $admin, $period, F::adminXlsx([['2026-09-12', 70.00, null, 'S/R', 'PAGO DOS NUEVO', 'CORPORATIVO']]), 2)->assertOk()->json('upload.id');

    $old = EstadoResultadosUpload::query()->findOrFail($first2);
    expect($old->status)->toBe(EstadoResultadosUpload::STATUS_REPLACED)
        ->and($old->superseded_by_upload_id)->toBe($new2)
        ->and(EstadoResultadosUpload::query()->active()->where('file_part', 1)->count())->toBe(1)
        ->and(EstadoResultadosMovement::query()->where('upload_id', $first2)->count())->toBe(0)
        ->and(daInPeriod(daDataset($period)))->toBe(['PAGO DOS NUEVO', 'PAGO UNO']);
});

it('el mismo archivo no puede ser el archivo 1 y el 2 de la cuenta', function () {
    $period = F::period();
    $admin = G::admin();
    $path = F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO UNO', 'CORPORATIVO']]);
    daPost($this, $admin, $period, $path, 1)->assertOk();

    $res = daPost($this, $admin, $period, $path, 2)->assertStatus(422);
    expect($res->json('message'))->toContain('Archivo 1')
        ->and(EstadoResultadosUpload::query()->active()->count())->toBe(1);

    // Mismo archivo, mismo archivo 1 → reintento idempotente (como hasta hoy).
    daPost($this, $admin, $period, $path, 1)->assertOk()->assertJson(['idempotent' => true]);
});

it('valida el archivo y la cuenta igual para el archivo 2', function () {
    $period = F::period();
    $admin = G::admin();

    daPost($this, $admin, $period, F::excedentesXlsx([['2026-09-01', null, 10.00, 'X', 'CORDOBA', null]]), 2)
        ->assertStatus(422)->assertJsonFragment(['message' => 'El archivo tiene la estructura de "Cuenta de excedentes", pero se eligió "Cuenta administrativa / corporativa".']);
    daPost($this, $admin, $period, F::adminXlsx([['2026-09-10', 1.00, null, 'S/R', 'X', 'CORPORATIVO']]), 3)->assertStatus(422);
    expect(EstadoResultadosUpload::query()->count())->toBe(0);
});

it('el candado es por periodo + cuenta + archivo', function () {
    $period = F::period();
    $admin = G::admin();
    $lock1 = Cache::lock('estado_resultados:carga:'.$period->id.':administrativa', 300);
    expect($lock1->get())->toBeTrue();
    // Con el archivo 1 en curso, el archivo 2 sí se puede cargar.
    daPost($this, $admin, $period, F::adminXlsx([['2026-09-11', 5.00, null, 'S/R', 'DOS', 'CORPORATIVO']]), 2)->assertOk();
    $lock1->release();

    $lock2 = Cache::lock('estado_resultados:carga:'.$period->id.':administrativa:2', 300);
    expect($lock2->get())->toBeTrue();
    daPost($this, $admin, $period, F::adminXlsx([['2026-09-12', 6.00, null, 'S/R', 'DOS B', 'CORPORATIVO']]), 2)
        ->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'Archivo 2'));
    $this->actingAs($admin)->deleteJson("/estado-resultados/{$period->id}/cargas/administrativa/archivo/2")->assertStatus(409);
    daPost($this, $admin, $period, F::adminXlsx([['2026-09-10', 1.00, null, 'S/R', 'UNO', 'CORPORATIVO']]), 1)->assertOk();
    $lock2->release();
});

it('quita el archivo 2: queda en el historial, sus movimientos dejan de contar y el periodo pide reprocesar', function () {
    $period = F::period();
    $admin = G::admin();
    daPost($this, $admin, $period, F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO UNO', 'CORPORATIVO']]), 1)->assertOk();
    $id2 = daPost($this, $admin, $period, F::adminXlsx([['2026-09-11', 50.00, null, 'S/R', 'PAGO DOS', 'CORPORATIVO']]), 2)->assertOk()->json('upload.id');
    expect(daInPeriod(daDataset($period)))->toBe(['PAGO DOS', 'PAGO UNO']);

    $this->actingAs($admin)->deleteJson("/estado-resultados/{$period->id}/cargas/administrativa/archivo/2")
        ->assertOk()->assertJsonPath('upload.id', $id2);

    $u2 = EstadoResultadosUpload::query()->findOrFail($id2);
    expect($u2->status)->toBe(EstadoResultadosUpload::STATUS_REPLACED)
        ->and($u2->superseded_at)->not->toBeNull()
        ->and(EstadoResultadosMovement::query()->where('upload_id', $id2)->count())->toBe(0);

    $d = daDataset($period, false);
    $acc = daAccount($d);
    expect(daInPeriod($d))->toBe(['PAGO UNO'])
        ->and($acc['parts'][1]['upload'])->toBeNull()
        ->and($acc['parts'][1]['status'])->toBe('opcional')
        ->and($acc['status'])->toBe('cargado') // falta volver a procesar
        ->and(collect($acc['history'])->firstWhere('id', $id2)['status'])->toBe('reemplazado');

    expect(daChecklist(daDataset($period))['state'])->not->toBe('falta');

    // Sin archivo 2 no hay nada que quitar; el archivo 1 nunca se quita.
    $this->actingAs($admin)->deleteJson("/estado-resultados/{$period->id}/cargas/administrativa/archivo/2")->assertStatus(404);
    $this->actingAs($admin)->deleteJson("/estado-resultados/{$period->id}/cargas/administrativa/archivo/1")->assertStatus(422);
    expect(EstadoResultadosUpload::query()->active()->count())->toBe(1);
});

it('quitar el archivo 2 exige el permiso de cargar y respeta el periodo aprobado', function () {
    $period = G::readyPeriod();
    $admin = G::admin();
    daImport($period, F::adminXlsx([['2026-09-15', 10.00, null, 'S/R', 'EXTRA', 'CORPORATIVO']]), 2);
    app(EstadoResultadosProcessor::class)->process($period);
    $fp = EstadoResultadosMovement::query()->where('concept', 'EXTRA')->value('fingerprint');
    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/clasificar", ['fingerprints' => [$fp], 'treatment' => 'capex', 'notes' => 'inversión'])->assertOk();

    $viewer = G::userWith(['ver']);
    $this->actingAs($viewer)->deleteJson("/estado-resultados/{$period->id}/cargas/administrativa/archivo/2")->assertForbidden();

    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/aprobar")->assertOk();
    $this->actingAs($admin)->deleteJson("/estado-resultados/{$period->id}/cargas/administrativa/archivo/2")->assertStatus(423);
    expect(EstadoResultadosUpload::query()->active()->where('file_part', 2)->count())->toBe(1);
});

it('el archivo 2 nunca aparece como fuente faltante', function () {
    $period = F::period();
    daImport($period, F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO UNO', 'CORPORATIVO']]), 1);
    daImport($period, F::excedentesXlsx([['2026-09-01', null, 10.00, 'RETIRO', 'CORDOBA', null]]), 1, 'excedentes');

    $d = daDataset($period);
    $reasons = implode(' | ', array_filter(array_column($d['sources']['checklist']['items'], 'reason')));
    expect(daChecklist($d)['state'])->not->toBe('falta')
        ->and(daChecklist($d, 'excedentes')['state'])->not->toBe('falta')
        ->and(daAccount($d)['parts'][1]['status'])->toBe('opcional')
        ->and($reasons)->not->toContain('Archivo 2')
        ->and($reasons)->not->toContain('archivo 2');

    // Sólo con el archivo 2 la cuenta sigue faltando (falta el 1, nunca el 2).
    $other = F::period('2026-10-01', '2026-10-31');
    daImport($other, F::adminXlsx([['2026-10-10', 5.00, null, 'S/R', 'SOLO DOS', 'CORPORATIVO']]), 2);
    $item = daChecklist(daDataset($other));
    expect($item['state'])->toBe('falta')
        ->and($item['message'])->not->toContain('Archivo 2');
});

it('un archivo 2 cargado con control que no cuadra o avisos bloqueantes sí se reporta', function () {
    $period = F::period();
    daImport($period, F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO UNO', 'CORPORATIVO']]), 1);
    $u2 = daImport($period, F::adminXlsx([['2026-09-11', 5.00, null, 'S/R', 'PAGO DOS', 'CORPORATIVO']]), 2);
    $u2->update(['control_ok' => false]);
    app(EstadoResultadosProcessor::class)->process($period);
    // Sin pendientes de revisión: lo único que queda es el control del archivo 2.
    $fps = EstadoResultadosMovement::query()->pluck('fingerprint')->all();
    $this->actingAs(G::admin())->postJson("/estado-resultados/{$period->id}/clasificar", ['fingerprints' => $fps, 'treatment' => 'capex', 'notes' => 'inversión'])->assertOk();

    $d = daDataset($period, false);
    $item = daChecklist($d);
    expect(daAccount($d)['parts'][1]['status'])->toBe('con_incidencias')
        ->and($item['state'])->toBe('con_problemas')
        ->and($item['message'])->toContain('archivo 2')
        ->and($item['action']['part'])->toBe(2);

    // Función pura: con el archivo 1 bien y el 2 con aviso bloqueante → con problemas; sin 2 → lista.
    $part1 = ['name' => 'uno.xlsx', 'movements' => 1, 'control_ok' => true, 'blocking_warnings' => []];
    $cuenta = fn (?array $u2) => ['key' => 'administrativa', 'label' => 'Cuenta administrativa / corporativa', 'status' => 'procesado', 'pending_review' => 0, 'upload' => $part1,
        'parts' => [['part' => 1, 'required' => true, 'upload' => $part1], ['part' => 2, 'required' => false, 'upload' => $u2]]];
    $build = fn (?array $u2) => collect(SourceChecklist::build('Sep', ['cuentas' => [$cuenta($u2)]], [], [])['items'])->firstWhere('key', 'administrativa');
    expect($build(null)['state'])->toBe('lista')
        ->and($build(['name' => 'dos.xlsx', 'control_ok' => true, 'blocking_warnings' => [['code' => 'x', 'severity' => 'bloqueante', 'message' => 'm']]])['state'])->toBe('con_problemas')
        ->and($build(['name' => 'dos.xlsx', 'control_ok' => true, 'blocking_warnings' => []])['state'])->toBe('lista');
});

it('un movimiento idéntico en el archivo 1 y en el 2 se cuenta una sola vez', function () {
    $period = F::period();
    $row = ['2026-09-10', 100.00, null, 'S/R', 'PAGO REPETIDO', 'CORPORATIVO'];
    $u1 = daImport($period, F::adminXlsx([$row, ['2026-09-12', 1.00, null, 'S/R', 'SOLO UNO', 'CORPORATIVO']]), 1);
    $u2 = daImport($period, F::adminXlsx([$row, ['2026-09-13', 2.00, null, 'S/R', 'SOLO DOS', 'CORPORATIVO']]), 2);

    $copies = EstadoResultadosMovement::query()->where('concept', 'PAGO REPETIDO');
    expect((clone $copies)->count())->toBe(2)
        ->and((clone $copies)->where('in_period', true)->value('upload_id'))->toBe($u1->id)
        ->and((clone $copies)->where('placement', MovementPeriodAllocator::DUPLICADO)->value('upload_id'))->toBe($u2->id)
        ->and(implode(' ', $u2->fresh()->warnings))->toContain('se cuentan una sola vez');

    $d = daDataset($period);
    expect(daInPeriod($d))->toBe(['PAGO REPETIDO', 'SOLO DOS', 'SOLO UNO'])
        ->and(collect($d['movements'])->where('concept', 'PAGO REPETIDO')->where('in_period', true)->count())->toBe(1)
        // La copia repetida no debe romper la validación de huella única ni la partición.
        ->and(collect($d['validation']['failed_checks'] ?? [])->pluck('key')->all())->not->toContain('huella_unica');

    // Al quitar el archivo 2 el movimiento sigue contando una vez (la copia del archivo 1).
    app(EstadoResultadosUploadService::class)->removePart($period, 'administrativa', 2, null);
    expect(daInPeriod(daDataset($period)))->toBe(['PAGO REPETIDO', 'SOLO UNO']);
});

it('si el archivo 1 es legado, la copia idéntica del archivo 2 queda como duplicado sin chocar con la llave única', function () {
    $period = F::period();
    $row = ['2026-09-10', 100.00, null, 'S/R', 'PAGO REPETIDO', 'CORPORATIVO'];
    daImport($period, F::adminXlsx([$row]), 1);
    EstadoResultadosMovement::query()->update(['placement' => MovementPeriodAllocator::LEGADO]);

    $u2 = daImport($period, F::adminXlsx([$row, ['2026-09-13', 2.00, null, 'S/R', 'SOLO DOS', 'CORPORATIVO']]), 2);

    expect(EstadoResultadosMovement::query()->where('upload_id', $u2->id)->where('concept', 'PAGO REPETIDO')->value('placement'))->toBe(MovementPeriodAllocator::DUPLICADO)
        ->and(daInPeriod(daDataset($period)))->toBe(['PAGO REPETIDO', 'SOLO DOS']);
});
