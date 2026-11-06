<?php

use App\Models\EstadoResultados\EstadoResultadosClassification;
use App\Models\EstadoResultados\EstadoResultadosVersion;
use App\Models\EstadoResultados\EstadoResultadosVersionLog;
use App\Models\EstadoResultados\EstadoResultadosVersionSnapshot;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Reporting\EstadoResultadosLockedException;
use App\Services\EstadoResultados\Reporting\ReportStatusEvaluator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * Cierre y versionado del Estado de Resultados: estados derivados, aprobación con
 * snapshot inmutable (HTTP 423), nueva versión con motivo y sustitución.
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
    F::fakeRadiography();
    G::fakePdfRenderer();
});

function apDataset($test, $user, $period, array $query = []): array
{
    $qs = $query ? '?'.http_build_query($query) : '';

    return $test->actingAs($user)->getJson("/estado-resultados/{$period->id}/dataset{$qs}")->assertOk()->json('dataset');
}

it('deriva el estado en vivo: sin datos obligatorios es Borrador y PRELIMINAR con motivos claros', function () {
    $period = F::period();
    $d = apDataset($this, G::admin(), $period);

    expect($d['meta']['report']['status'])->toBe('borrador')
        ->and($d['meta']['report']['status_label'])->toBe('Borrador')
        ->and($d['meta']['report']['source'])->toBe('en_vivo')
        ->and($d['meta']['report']['version'])->toBe(1)
        ->and($d['meta']['report']['preliminary'])->toBeTrue()
        ->and($d['meta']['report']['approvable'])->toBeFalse()
        ->and(implode(' | ', $d['meta']['report']['preliminary_reasons']))
        ->toContain('Falta el ERP de gastos')->toContain('Falta la nómina (NOI)')
        ->toContain('Falta el estado de cuenta')->toContain('Ajustes sin información');
});

it('periodo completo y sin pendientes queda «Listo para revisión» y no es preliminar', function () {
    $period = G::readyPeriod();
    $d = apDataset($this, G::admin(), $period);

    expect($d['meta']['report']['blockers'])->toBe([])
        ->and($d['meta']['report']['status'])->toBe('listo_revision')
        ->and($d['meta']['report']['status_label'])->toBe('Listo para revisión')
        ->and($d['meta']['report']['preliminary'])->toBeFalse()
        ->and($d['meta']['report']['approvable'])->toBeTrue();
});

it('bloquea la aprobación con partidas pendientes (Pendiente de conciliación) y explica el motivo', function () {
    $period = G::readyPeriod();
    // Abono sin regla ⇒ pendiente de clasificar.
    G::import($period, F::adminXlsx([
        ['2026-09-10', 214032.81, null, 'S/R', 'NOM QUINCENA 17', 'CORPORATIVO', 'EMPLEADO CONFIDENCIAL'],
        ['2026-09-12', null, 999.00, null, 'DEPOSITO DESCONOCIDO', null],
    ]));
    app(EstadoResultadosProcessor::class)->process($period);
    $admin = G::admin();

    expect(apDataset($this, $admin, $period)['meta']['report']['status'])->toBe('pendiente_conciliacion');

    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/aprobar")
        ->assertStatus(422)
        ->assertJsonPath('status', 'pendiente_conciliacion')
        ->assertJsonPath('blockers.0.code', 'pendientes')
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'No se puede aprobar') && str_contains($m, 'pendientes de clasificar'));

    expect(EstadoResultadosVersion::query()->count())->toBe(0);
});

it('bloquea la aprobación si una validación matemática no cuadra (Borrador)', function () {
    $period = G::readyPeriod();
    // Validación que no cuadra en el dataset (inyectada en la evaluación para no depender
    // de una regla concreta de conciliación): el builder la reporta con ok=false.
    app()->instance(ReportStatusEvaluator::class, new class extends ReportStatusEvaluator
    {
        public function evaluate(array $d): array
        {
            $d['checks'][] = ['key' => 'prueba', 'label' => 'Total prueba = Σ líneas', 'expected' => '1.00', 'actual' => '1.01', 'difference' => '0.01', 'ok' => false];

            return parent::evaluate($d);
        }
    });
    $admin = G::admin();

    $report = apDataset($this, $admin, $period)['meta']['report'];
    expect($report['status'])->toBe('borrador')
        ->and(collect($report['blockers'])->pluck('code')->all())->toContain('validaciones');

    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/aprobar")
        ->assertStatus(422)->assertJsonPath('blockers.0.code', 'validaciones');
    expect(EstadoResultadosVersion::query()->count())->toBe(0);
});

it('aprobar guarda un snapshot inmutable con insumos, hashes, usuario y fecha', function () {
    $period = G::readyPeriod();
    $admin = G::admin();
    $live = apDataset($this, $admin, $period);

    $res = $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/aprobar", ['notes' => 'Cierre de septiembre', 'expected_hash' => $live['meta']['dataset_hash']])
        ->assertOk()->assertJsonPath('version.version', 1)->assertJsonPath('version.status', 'aprobado')->json('version');

    $v = EstadoResultadosVersion::query()->sole();
    expect($v->approved_by)->toBe($admin->id)
        ->and($v->approved_at)->not->toBeNull()
        ->and($v->dataset_hash)->toBe($live['meta']['dataset_hash'])
        ->and($v->rules_version)->toBe(config('estado_resultados.rules_version'))
        ->and(collect($v->sources['uploads'])->pluck('file_hash')->filter())->toHaveCount(2)
        ->and($v->sources['erp_signature'])->not->toBeEmpty()
        ->and(collect($v->adjustments)->where('scope', 'CONSOLIDADO'))->toHaveCount(count(AdjustmentService::CONCEPTS))
        ->and(EstadoResultadosVersionSnapshot::query()->where('version_id', $v->id)->pluck('scope_key')->sort()->values()->all())->toBe(['CONSOLIDADO', 'CORDOBA'])
        ->and($res['snapshot_hash'])->toHaveLength(64)
        ->and(EstadoResultadosVersionLog::query()->where('action', 'aprobado')->count())->toBe(1);

    $d = apDataset($this, $admin, $period);
    expect($d['meta']['report']['status'])->toBe('aprobado')
        ->and($d['meta']['report']['status_label'])->toBe('Aprobado')
        ->and($d['meta']['report']['source'])->toBe('snapshot')
        ->and($d['meta']['report']['locked'])->toBeTrue()
        ->and($d['meta']['report']['approved_by'])->toBe($admin->name)
        ->and($d['meta']['dataset_hash'])->toBe($live['meta']['dataset_hash'])
        ->and($d['results'])->toBe($live['results']);

    // El snapshot y la versión no se pueden alterar desde la aplicación.
    expect(fn () => EstadoResultadosVersionSnapshot::query()->first()->update(['dataset' => '{}']))->toThrow(LogicException::class)
        ->and(fn () => $v->fresh()->update(['dataset_hash' => str_repeat('0', 64)]))->toThrow(LogicException::class)
        ->and(fn () => $v->fresh()->delete())->toThrow(LogicException::class);
});

it('rechaza aprobar si las cifras cambiaron desde la revisión (hash esperado distinto)', function () {
    $period = G::readyPeriod();
    $this->actingAs(G::admin())->postJson("/estado-resultados/{$period->id}/aprobar", ['expected_hash' => str_repeat('a', 64)])
        ->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'Las cifras cambiaron'));
    expect(EstadoResultadosVersion::query()->count())->toBe(0);
});

it('un periodo aprobado es inmutable: cargas, proceso, clasificación y ajustes devuelven 423', function () {
    $period = G::readyPeriod();
    $admin = G::admin();
    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/aprobar")->assertOk();
    $fp = collect(apDataset($this, $admin, $period)['movements'])->first()['fingerprint'];
    $file = new UploadedFile(F::adminXlsx([['2026-09-11', 10.00, null, 'S/R', 'PAGO NUEVO', 'CORPORATIVO']]), 'nuevo.xlsx', null, null, true);
    $before = EstadoResultadosClassification::query()->count();

    $calls = [
        fn () => $this->actingAs($admin)->post("/estado-resultados/{$period->id}/cargas", ['slot' => 'administrativa', 'file' => $file], ['Accept' => 'application/json']),
        fn () => $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/procesar"),
        fn () => $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/clasificar", ['fingerprints' => [$fp], 'treatment' => 'capex', 'notes' => 'cambio silencioso']),
        fn () => $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/aceptar-sugerencias", ['fingerprints' => [$fp]]),
        fn () => $this->actingAs($admin)->putJson("/estado-resultados/{$period->id}/ajustes", ['scope' => 'CONSOLIDADO', 'concept' => 'isr', 'state' => 'capturado', 'amount' => '100']),
        fn () => $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/aprobar"),
    ];
    foreach ($calls as $call) {
        $call()->assertStatus(423)
            ->assertJsonPath('locked', true)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'está aprobado (versión 1)') && str_contains($m, 'abrir una nueva versión'));
    }

    // También fuera de HTTP (servicios).
    expect(fn () => app(AdjustmentService::class)->save($period, 'CONSOLIDADO', 'isr', 'capturado', '1', null, null))->toThrow(EstadoResultadosLockedException::class)
        ->and(fn () => G::import($period, F::adminXlsx([['2026-09-11', 10.00, null, 'S/R', 'PAGO NUEVO', 'CORPORATIVO']])))->toThrow(EstadoResultadosLockedException::class)
        ->and(EstadoResultadosClassification::query()->count())->toBe($before);
});

it('después de aprobar, web, Excel y PDF siguen idénticos al snapshot aunque cambien los datos de origen', function () {
    $period = G::readyPeriod();
    $admin = G::admin();
    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/aprobar")->assertOk();
    $approved = apDataset($this, $admin, $period);

    // Cambian los datos de origen por fuera del ER: Radiografía, ERP y un ajuste escrito directo en BD.
    F::fakeRadiography(['interes_recuperado' => 1.00]);
    F::erp($period, 'CORPORATIVO', 'REQ-TARDE', '99999.00', '2026-09-20', 'Luz', 'LUZ EXTRA');
    DB::table('estado_resultados_adjustments')->where('period_id', $period->id)->where('concept', 'isr')->update(['state' => 'capturado', 'amount' => 5000]);
    $liveNow = app(EstadoResultadosDatasetBuilder::class)->build($period);
    expect($liveNow['meta']['dataset_hash'])->not->toBe($approved['meta']['dataset_hash']);

    $after = apDataset($this, $admin, $period);
    $excel = $this->actingAs($admin)->get("/estado-resultados/{$period->id}/exportar/excel")->assertOk();
    $pdf = $this->actingAs($admin)->get("/estado-resultados/{$period->id}/exportar/pdf")->assertOk();

    expect($after['meta']['dataset_hash'])->toBe($approved['meta']['dataset_hash'])
        ->and($after['results'])->toBe($approved['results'])
        ->and($after['statement'])->toBe($approved['statement'])
        ->and($excel->headers->get('X-Dataset-Hash'))->toBe($approved['meta']['dataset_hash'])
        ->and($excel->headers->get('X-Report-Status'))->toBe('aprobado')
        ->and($pdf->headers->get('X-Dataset-Hash'))->toBe($approved['meta']['dataset_hash'])
        ->and($pdf->headers->get('X-Report-Version'))->toBe('1');

    $book = IOFactory::load($excel->getFile()->getPathname());
    $control = $book->getSheetByName('FUENTES Y CONTROL');
    $values = [];
    foreach ($control->getRowIterator(6, 20) as $row) {
        $values[(string) $control->getCell('B'.$row->getRowIndex())->getValue()] = (string) $control->getCell('C'.$row->getRowIndex())->getValue();
    }
    expect($values['Hash del dataset (igual en web, Excel y PDF)'])->toBe($approved['meta']['dataset_hash'])
        ->and($values['Estado del reporte'])->toBe('Aprobado')
        ->and($values['Origen de las cifras'])->toContain('Snapshot aprobado')
        ->and($values['Versión del reporte'])->toBe('1')
        ->and($values['Criterio gerencial aplicado'])->toContain('Criterio gerencial');

    $ebitdaRow = null;
    $ws = $book->getSheetByName('ESTADO RESULTADOS');
    foreach ($ws->getRowIterator() as $row) {
        if ((string) $ws->getCell('B'.$row->getRowIndex())->getValue() === 'EBITDA FINAL') {
            $ebitdaRow = number_format((float) $ws->getCell('C'.$row->getRowIndex())->getValue(), 2, '.', '');
        }
    }
    $html = (string) file_get_contents($pdf->getFile()->getPathname());
    $money = fn ($v) => ((float) $v < 0 ? '-$' : '$').number_format(abs((float) $v), 2);
    expect($ebitdaRow)->toBe($approved['results']['ebitda_final'])
        ->and($html)->toContain($approved['meta']['dataset_hash'])
        ->and($html)->toContain($money($approved['results']['ebitda_final']))
        ->and($html)->toContain('Versión 1')
        ->and($html)->toContain('Aprobado')
        ->and($html)->not->toContain('PRELIMINAR');
});

it('nueva versión: exige permiso y motivo, queda en bitácora y al aprobarla la anterior pasa a «Sustituido»', function () {
    $period = G::readyPeriod();
    $admin = G::admin();
    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/aprobar")->assertOk();
    $v1 = apDataset($this, $admin, $period);

    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/nueva-version", [])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/nueva-version", ['reason' => 'corto'])->assertStatus(422);
    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/nueva-version", ['reason' => 'Se recibió el ISR definitivo del contador'])
        ->assertOk()->assertJsonPath('draft_version', 2)->assertJsonPath('previous.reopened.reason', 'Se recibió el ISR definitivo del contador');
    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/nueva-version", ['reason' => 'Se recibió el ISR definitivo del contador'])->assertStatus(422);

    // Desbloqueado: el borrador v2 se calcula en vivo y admite cambios.
    $draft = apDataset($this, $admin, $period);
    expect($draft['meta']['report']['source'])->toBe('en_vivo')
        ->and($draft['meta']['report']['version'])->toBe(2)
        ->and($draft['meta']['report']['previous_version']['version'])->toBe(1);
    $this->actingAs($admin)->putJson("/estado-resultados/{$period->id}/ajustes", ['scope' => 'CONSOLIDADO', 'concept' => 'isr', 'state' => 'capturado', 'amount' => '1234.56'])->assertOk();

    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/aprobar")->assertOk()->assertJsonPath('version.version', 2);

    $versions = $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/versiones")->assertOk()->json();
    expect($versions['locked'])->toBeTrue()
        ->and(collect($versions['versions'])->pluck('status', 'version')->all())->toBe([2 => 'aprobado', 1 => 'sustituido'])
        ->and(collect($versions['versions'])->firstWhere('version', 1)['status_label'])->toBe('Sustituido por nueva versión')
        ->and(collect($versions['logs'])->pluck('action')->all())->toBe(['aprobado', 'sustituido', 'nueva_version', 'aprobado'])
        ->and(collect($versions['logs'])->firstWhere('action', 'nueva_version')['reason'])->toBe('Se recibió el ISR definitivo del contador');

    $current = apDataset($this, $admin, $period);
    $old = apDataset($this, $admin, $period, ['version' => 1]);
    expect($current['results']['isr'])->toBe('1234.56')
        ->and($current['meta']['report']['version'])->toBe(2)
        ->and($old['meta']['report']['status'])->toBe('sustituido')
        ->and($old['meta']['dataset_hash'])->toBe($v1['meta']['dataset_hash'])
        ->and($old['results']['isr'])->toBe('0.00');

    $this->actingAs($admin)->get("/estado-resultados/{$period->id}/exportar/excel?version=1")->assertOk()
        ->assertHeader('X-Dataset-Hash', $v1['meta']['dataset_hash'])->assertHeader('X-Report-Status', 'sustituido');
    $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/dataset?version=9")->assertStatus(404);
});

it('sucursal fuera de la versión aprobada se informa con un error claro', function () {
    $period = G::readyPeriod();
    $admin = G::admin();
    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/aprobar")->assertOk();

    $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/dataset?alcance=NOEXISTE")
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'no forma parte de la versión aprobada'));
    $scopes = $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/dataset?alcance=CORDOBA")->assertOk()->json('scopes');
    expect(array_column($scopes, 'key'))->toBe(['CONSOLIDADO', 'CORDOBA']);
});
