<?php

use App\Services\Access\ActionPermissionService;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Classification\ClassificationService;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Support\Treatment as T;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * Permisos independientes del Estado de Resultados aplicados en BACKEND (403) y
 * redacción de nómina / Dirección General en dataset, historial y exportes.
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
    F::fakeRadiography();
    G::fakePdfRenderer();
    Storage::fake('local');
});

/** Todas las rutas de escritura/consulta restringida del ER con el permiso que exigen. */
function prEndpoints($period, string $fp): array
{
    $id = $period->id;

    return [
        'cargar' => ['post', "/estado-resultados/{$id}/cargas", ['slot' => 'administrativa']],
        'cargar ' => ['get', "/estado-resultados/{$id}/cargas/intentos", []],
        'procesar' => ['post', "/estado-resultados/{$id}/procesar", []],
        'clasificar' => ['post', "/estado-resultados/{$id}/clasificar", ['fingerprints' => [$fp], 'treatment' => 'capex', 'notes' => 'prueba']],
        'clasificar ' => ['post', "/estado-resultados/{$id}/aceptar-sugerencias", ['fingerprints' => [$fp]]],
        'ajustes' => ['put', "/estado-resultados/{$id}/ajustes", ['scope' => 'CONSOLIDADO', 'concept' => 'isr', 'state' => 'confirmado_cero']],
        'aprobar' => ['post', "/estado-resultados/{$id}/aprobar", []],
        'aprobar ' => ['post', "/estado-resultados/{$id}/nueva-version", ['reason' => 'Motivo suficientemente largo']],
        'exportar' => ['get', "/estado-resultados/{$id}/exportar/excel", []],
        'exportar ' => ['get', "/estado-resultados/{$id}/exportar/pdf", []],
        'ver_nomina' => ['get', "/estado-resultados/{$id}/detalle/nomina", []],
        'ver_dg' => ['get', "/estado-resultados/{$id}/detalle/dg", []],
    ];
}

it('cataloga los permisos independientes del ER conservando las claves guardadas', function () {
    $keys = array_keys(ActionPermissionService::ACTIONS['estado_resultados']);

    expect($keys)->toContain('ver_nomina', 'ver_dg', 'cargar', 'procesar', 'clasificar', 'ajustes', 'aprobar', 'exportar')
        ->and(ActionPermissionService::ACTIONS['estado_resultados']['procesar']['label'])->toContain('Conciliar')
        ->and(ActionPermissionService::ACTIONS['estado_resultados']['clasificar']['label'])->toBe('Reclasificar movimientos')
        ->and(ActionPermissionService::LEVEL_DEFAULTS['gerencial'])->not->toHaveKey('estado_resultados');
});

it('con sólo «consultar» (módulo) puede ver el reporte e historial, pero cada acción responde 403 en backend', function () {
    $period = G::readyPeriod();
    $fp = collect(app(EstadoResultadosDatasetBuilder::class)->build($period)['movements'])->first()['fingerprint'];
    $viewer = G::userWith([]);

    $this->actingAs($viewer)->getJson("/estado-resultados/{$period->id}/dataset")->assertOk();
    $this->actingAs($viewer)->getJson("/estado-resultados/{$period->id}/versiones")->assertOk();
    foreach (prEndpoints($period, $fp) as $perm => [$method, $url, $body]) {
        $res = $this->actingAs($viewer)->json(strtoupper($method), $url, $body);
        expect($res->getStatusCode())->toBe(403, "Sin permiso «{$perm}» debía ser 403: {$url}");
    }
});

it('cada permiso, otorgado por separado, habilita sólo su endpoint', function () {
    $period = G::readyPeriod();
    $fp = collect(app(EstadoResultadosDatasetBuilder::class)->build($period)['movements'])->first()['fingerprint'];
    $endpoints = prEndpoints($period, $fp);

    foreach (['cargar', 'procesar', 'clasificar', 'ajustes', 'aprobar', 'exportar', 'ver_nomina', 'ver_dg'] as $perm) {
        $user = G::userWith([$perm]);
        foreach ($endpoints as $label => [$method, $url, $body]) {
            $status = $this->actingAs($user)->json(strtoupper($method), $url, $body)->getStatusCode();
            if (trim($label) === $perm) {
                expect($status)->not->toBe(403, "Con «{$perm}» no debía ser 403: {$url}");
            } else {
                expect($status)->toBe(403, "Con sólo «{$perm}», {$url} debía ser 403");
            }
        }
    }
});

it('expone las capacidades del usuario a la UI (la autoridad sigue siendo el backend)', function () {
    $this->withoutVite();
    $user = G::userWith(['cargar', 'ver_nomina']);

    $this->actingAs($user)->get('/estado-resultados')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('can.consultar', true)
        ->where('can.cargar', true)
        ->where('can.ver_nomina', true)
        ->where('can.ver_dg', false)
        ->where('can.aprobar', false)
        ->where('can.conciliar', false)
        ->where('can.reclasificar', false)
        ->where('can.editar_ajustes', false)
        ->where('can.exportar', false)
        ->where('reportStatuses.3.key', 'aprobado')
        ->where('uploadRules.max_mb', 10)
        ->etc());
});

/** Modelo de EBITDA único: nómina y DG son categorías de la sección «opex». */
function prOpexCategory(array $d, string $key): ?array
{
    return collect(collect($d['statement'])->firstWhere('key', 'opex')['categories'] ?? [])->firstWhere('key', $key);
}

it('sin «ver_nomina» se redacta el detalle de nómina en dataset, historial, Excel y PDF; los totales no cambian', function () {
    $period = G::readyPeriod();
    $admin = G::admin();
    $user = G::userWith(['exportar', 'clasificar']);

    $full = $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/dataset")->json('dataset');
    $red = $this->actingAs($user)->getJson("/estado-resultados/{$period->id}/dataset")->json('dataset');

    $payroll = collect($full['movements'])->firstWhere('concept', 'NOM QUINCENA 17');
    expect($payroll)->not->toBeNull()
        ->and($red['results'])->toBe($full['results'])
        ->and($red['meta']['dataset_hash'])->toBe($full['meta']['dataset_hash'])
        ->and($red['meta']['redactions']['nomina'])->toBeTrue()
        ->and($red['meta']['redactions']['dg'])->toBeTrue()
        ->and($red['meta']['redactions']['hidden_movements']['nomina'])->toBeGreaterThanOrEqual(1)
        ->and(collect($red['movements'])->pluck('concept')->all())->not->toContain('NOM QUINCENA 17')
        ->and(json_encode($red))->not->toContain('EMPLEADO CONFIDENCIAL');

    foreach (['nomina_sucursales', 'nomina_corporativa'] as $key) {
        $sec = prOpexCategory($red, $key);
        $orig = prOpexCategory($full, $key);
        expect($sec['lines'])->toHaveCount(1)
            ->and($sec['lines'][0]['state'])->toBe('restringido')
            ->and($sec['lines'][0]['amount'])->toBe($orig['total']['amount'])
            ->and($sec['total'])->toBe($orig['total']);
    }
    expect(count(prOpexCategory($full, 'nomina_sucursales')['lines']))->toBeGreaterThan(1);

    // Historial y reclasificación de un movimiento de nómina: 403.
    $this->actingAs($user)->getJson("/estado-resultados/{$period->id}/historial-clasificacion?fingerprint={$payroll['fingerprint']}")->assertForbidden();
    $this->actingAs($user)->postJson("/estado-resultados/{$period->id}/clasificar", ['fingerprints' => [$payroll['fingerprint']], 'treatment' => 'capex', 'notes' => 'ocultar'])->assertForbidden();

    // Exportes del usuario restringido: sin el detalle, con los mismos totales y hash.
    $excel = $this->actingAs($user)->get("/estado-resultados/{$period->id}/exportar/excel")->assertOk()->assertHeader('X-Dataset-Hash', $full['meta']['dataset_hash']);
    $book = IOFactory::load($excel->getFile()->getPathname());
    $all = '';
    foreach ($book->getWorksheetIterator() as $ws) {
        foreach ($ws->toArray(null, false, false, false) as $row) {
            $all .= implode('|', array_map('strval', array_filter($row, fn ($v) => $v !== null))).PHP_EOL;
        }
    }
    expect($all)->not->toContain('NOM QUINCENA 17')->not->toContain('EMPLEADO CONFIDENCIAL')
        ->and($all)->toContain('El detalle de nómina está restringido');

    $html = (string) file_get_contents($this->actingAs($user)->get("/estado-resultados/{$period->id}/exportar/pdf")->assertOk()->getFile()->getPathname());
    expect($html)->toContain('Detalle restringido')->not->toContain('EMPLEADO CONFIDENCIAL');

    // Con «ver_nomina» sí lo ve (y el admin en el Excel también).
    $hr = G::userWith(['ver_nomina']);
    $seen = $this->actingAs($hr)->getJson("/estado-resultados/{$period->id}/dataset")->json('dataset');
    expect(collect($seen['movements'])->pluck('concept')->all())->toContain('NOM QUINCENA 17')
        ->and($seen['meta']['redactions']['nomina'])->toBeFalse();
    $detail = $this->actingAs($hr)->getJson("/estado-resultados/{$period->id}/detalle/nomina")->assertOk()->json();
    expect(collect($detail['movements'])->pluck('concept')->all())->toContain('NOM QUINCENA 17')
        ->and(collect($detail['sections'])->pluck('key')->all())->toBe(['nomina_sucursales', 'nomina_corporativa']);
});

it('sin «ver_dg» se redacta el detalle de Dirección General; el EBITDA final es el mismo', function () {
    $period = F::period();
    G::import($period, F::adminXlsx([['2026-09-06', 6000.00, null, 'S/R', 'REQ VIATICOS DG', 'CORPORATIVO', 'HOTEL EJECUTIVO']]));
    app(EstadoResultadosProcessor::class)->process($period);
    $m = collect(app(EstadoResultadosDatasetBuilder::class)->build($period)['movements'])->firstWhere('concept', 'REQ VIATICOS DG');
    app(ClassificationService::class)->classify($period, [['fingerprint' => $m['fingerprint'], 'source' => 'banco', 'current' => $m['treatment']]], T::DG_REMUNERACION, 'Remuneración DG', null);
    $admin = G::admin();
    $user = G::userWith(['ver_nomina', 'exportar']);

    $full = $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/dataset")->json('dataset');
    $red = $this->actingAs($user)->getJson("/estado-resultados/{$period->id}/dataset")->json('dataset');
    $dg = prOpexCategory($red, 'dg');

    expect($full['controls']['dg'])->toBe('6000.00')
        ->and($red['results'])->toBe($full['results'])
        ->and($dg['lines'])->toHaveCount(1)
        ->and($dg['lines'][0]['amount'])->toBe('6000.00')
        ->and($red['meta']['redactions']['dg'])->toBeTrue()
        ->and($red['meta']['redactions']['nomina'])->toBeFalse()
        ->and(json_encode($red))->not->toContain('HOTEL EJECUTIVO');

    $excel = $this->actingAs($user)->get("/estado-resultados/{$period->id}/exportar/excel")->assertOk();
    $dgSheet = IOFactory::load($excel->getFile()->getPathname())->getSheetByName('Anexo DG');
    expect((string) $dgSheet->getCell('B6')->getValue())->toContain('restringido');
    $this->actingAs($user)->getJson("/estado-resultados/{$period->id}/historial-clasificacion?fingerprint={$m['fingerprint']}")->assertForbidden();
    $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/historial-clasificacion?fingerprint={$m['fingerprint']}")->assertOk()->assertJsonCount(1, 'history');
});

it('datos incompletos ⇒ PRELIMINAR en dataset, Excel y PDF; nunca muestra un ajuste desconocido como $0', function () {
    $period = F::period();
    $admin = G::admin();
    G::import($period, F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO LIMPIEZA', 'CORPORATIVO']]));

    $d = $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/dataset")->json('dataset');
    expect($d['meta']['report']['preliminary'])->toBeTrue()
        ->and($d['meta']['report']['status'])->toBe('pendiente_conciliacion');

    $excel = $this->actingAs($admin)->get("/estado-resultados/{$period->id}/exportar/excel")->assertOk()->assertHeader('X-Report-Preliminary', '1');
    expect($excel->headers->get('content-disposition'))->toContain('_PRELIMINAR.xlsx');
    $book = IOFactory::load($excel->getFile()->getPathname());
    $ws = $book->getSheetByName('Estado de Resultados');
    $da = null;
    foreach ($ws->getRowIterator() as $row) {
        $r = $row->getRowIndex();
        if ((string) $ws->getCell("B{$r}")->getValue() === 'TOTAL D&A') {
            $da = $ws->getCell("C{$r}")->getValue();
        }
    }
    expect((string) $ws->getCell('G1')->getValue())->toBe('PRELIMINAR')
        ->and((string) $ws->getCell('C4')->getValue())->toContain('Versión 1')->toContain('PENDIENTE DE CONCILIACIÓN')
        ->and($da)->toBe('Sin información');
    $control = $book->getSheetByName('Conciliación y trazabilidad')->toArray(null, false, false, false);
    expect(collect($control)->pluck(1)->filter()->all())->toContain('Motivo PRELIMINAR', 'Criterio gerencial aplicado', 'Versión del reporte', 'Estado del reporte');

    $html = (string) file_get_contents($this->actingAs($admin)->get("/estado-resultados/{$period->id}/exportar/pdf")->assertOk()->getFile()->getPathname());
    expect($html)->toContain('class="watermark">PRELIMINAR')
        ->toContain('Pendiente de conciliación')
        ->toContain('Criterio gerencial aplicado')
        ->toContain('Sin información');
});

it('Web, Excel y PDF comparten cifras y hash tanto en vivo como aprobados', function () {
    $period = G::readyPeriod();
    $admin = G::admin();
    $check = function () use ($period, $admin) {
        $web = $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/dataset")->json('dataset');
        $x = $this->actingAs($admin)->get("/estado-resultados/{$period->id}/exportar/excel")->assertOk();
        $p = $this->actingAs($admin)->get("/estado-resultados/{$period->id}/exportar/pdf")->assertOk();
        $html = (string) file_get_contents($p->getFile()->getPathname());
        $money = fn ($v) => ((float) $v < 0 ? '-$' : '$').number_format(abs((float) $v), 2);
        expect($x->headers->get('X-Dataset-Hash'))->toBe($web['meta']['dataset_hash'])
            ->and($p->headers->get('X-Dataset-Hash'))->toBe($web['meta']['dataset_hash'])
            ->and($x->headers->get('X-Report-Status'))->toBe($web['meta']['report']['status'])
            ->and($html)->toContain($money($web['results']['ebitda']))
            ->and($html)->toContain($money($web['results']['utilidad_neta']));

        return $web;
    };

    $live = $check();
    expect($live['meta']['report']['status'])->toBe('listo_revision');
    $this->actingAs($admin)->postJson("/estado-resultados/{$period->id}/aprobar")->assertOk();
    $approved = $check();
    expect($approved['meta']['report']['status'])->toBe('aprobado')
        ->and($approved['meta']['dataset_hash'])->toBe($live['meta']['dataset_hash']);
});
