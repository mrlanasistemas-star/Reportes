<?php

use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\Period;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Control\MovementControlService;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * Control total de la pestaña Validación (20-nov-2026).
 *
 * Desde el reparto por fecha, un archivo subido en un periodo aporta movimientos a otro. La
 * conciliación archivo ↔ importado es POR MOVIMIENTO REPARTIDO: cuadra a $0.00 cuando no se
 * perdió nada, y una fila leída que no se guardó SIGUE apareciendo (nunca se compensa).
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
    F::fakeRadiography();
    Storage::fake('local');
});

function cvImport(Period $period, string $path, string $slot = 'administrativa', ?string $name = null)
{
    return app(EstadoResultadosUploadService::class)->importPath($period, $path, $name ?? basename($path), $slot, null);
}

function cvBuild(Period $period): array
{
    app(EstadoResultadosProcessor::class)->process($period);

    return app(EstadoResultadosDatasetBuilder::class)->build($period, 'CONSOLIDADO');
}

function cvCheck(array $d, string $key): ?array
{
    return collect($d['checks'])->firstWhere('key', $key);
}

it('archivo subido en un periodo con movimientos en otro: la validación de AMBOS cuadra a $0.00', function () {
    $abril = F::period('2026-03-30', '2026-04-26');
    $mayo = F::period('2026-04-27', '2026-05-24');
    // Archivo de abril (calendario) subido en Abril: el 28 y 29 de abril son del periodo Mayo.
    $up = cvImport($abril, F::adminXlsx([
        ['2026-04-02', 100.00, null, 'S/R', 'ABRIL 2', 'CORPORATIVO'],
        ['2026-04-28', 250.00, null, 'S/R', 'ABRIL 28', 'CORPORATIVO'],
        ['2026-04-29', 30.50, null, 'S/R', 'ABRIL 29', 'CORPORATIVO'],
    ]), 'administrativa', 'abril.xlsx');

    $dm = cvBuild($mayo);
    $vm = $dm['validation'];
    // Antes: «Archivos del banco $0.00 · 0 movimientos» vs «Importado $280.50 · 2».
    expect($vm['totals']['source']['total'])->toBe('280.50')
        ->and($vm['totals']['source']['count'])->toBe(2)
        ->and($vm['totals']['imported']['total'])->toBe('280.50')
        ->and($vm['integrity']['import_matches_source'])->toBeTrue()
        ->and(cvCheck($dm, 'particion_bancos')['ok'])->toBeTrue()
        ->and(collect($vm['failed_checks'])->pluck('key')->all())->not->toContain('particion_bancos', 'import_matches_source');

    // Partición completa del archivo vista desde Mayo: entra aquí 2 + va a Abril 1, sin pérdida.
    $fc = collect($vm['file_control']['uploads'])->firstWhere('upload_id', $up->id);
    expect($fc['own'])->toBeFalse()
        ->and($fc['read_total'])->toBe('380.50')
        ->and($fc['saved_ok'])->toBeTrue()
        ->and($fc['not_saved']['count'])->toBe(0)
        ->and(collect($fc['parts'])->firstWhere('key', 'entra')['total'])->toBe('280.50')
        ->and(collect($fc['parts'])->firstWhere('key', 'otro_periodo')['total'])->toBe('100.00')
        ->and(collect($fc['parts'])->firstWhere('key', 'otro_periodo')['period_label'])->toBe($abril->label);

    // Y Abril (donde se subió) ya no infla el lado archivo con lo que se fue a Mayo.
    $da = cvBuild($abril);
    expect($da['validation']['totals']['source']['total'])->toBe('100.00')
        ->and($da['validation']['totals']['imported']['total'])->toBe('100.00')
        ->and($da['validation']['integrity']['import_matches_source'])->toBeTrue()
        ->and(cvCheck($da, 'particion_bancos')['ok'])->toBeTrue();

    // Las filas que se fueron a Mayo se pueden ver desde Abril (endpoint de control).
    $out = app(MovementControlService::class)->outsideRows($abril, null, null, 1, 100, true, true);
    expect($out['total'])->toBe(2)
        ->and(collect($out['data'])->pluck('concept')->sort()->values()->all())->toBe(['ABRIL 28', 'ABRIL 29'])
        ->and($out['data'][0]['part'])->toBe('otro_periodo')
        ->and($out['data'][0]['period_label'])->toBe($mayo->label)
        ->and($out['data'][0]['source_row'])->toBeGreaterThan(0);
});

it('una fila leída del archivo que no se guardó SÍ se reporta (no se compensa) y se puede listar', function () {
    $abril = F::period('2026-03-30', '2026-04-26');
    $mayo = F::period('2026-04-27', '2026-05-24');
    $up = cvImport($abril, F::adminXlsx([
        ['2026-04-02', 100.00, null, 'S/R', 'ABRIL 2', 'CORPORATIVO'],
        ['2026-04-28', 250.00, null, 'S/R', 'ABRIL 28', 'CORPORATIVO'],
        ['2026-04-29', 30.50, null, 'S/R', 'SE PIERDE', 'CORPORATIVO'],
    ]), 'administrativa', 'abril.xlsx');
    $lost = EstadoResultadosMovement::query()->where('concept', 'SE PIERDE')->first();
    $lost->delete(); // simula una fila leída que no quedó guardada

    $d = cvBuild($mayo);
    $v = $d['validation'];
    expect($v['integrity']['import_matches_source'])->toBeFalse()
        ->and($v['totals']['source']['total'])->toBe('280.50')
        ->and($v['totals']['imported']['total'])->toBe('250.00')
        ->and(cvCheck($d, 'particion_bancos')['ok'])->toBeFalse()
        ->and(cvCheck($d, 'particion_bancos')['difference'])->toBe('-30.50')
        ->and($v['status'])->toBe('con_diferencias');

    // La validación fallida trae el archivo que la explica y la partida sin resolver también.
    $failed = collect($v['failed_checks'])->firstWhere('key', 'particion_bancos');
    expect($failed['files'][0]['upload_id'])->toBe($up->id)
        ->and($failed['files'][0]['count'])->toBe(1)
        ->and($failed['files'][0]['amount'])->toBe('30.50');
    $u = collect($v['unresolved'])->firstWhere('key', 'control_particion_bancos');
    expect($u['blocking'])->toBeTrue()->and($u['files'][0]['file'])->toBe('abril.xlsx');

    $fc = collect($v['file_control']['uploads'])->firstWhere('upload_id', $up->id);
    expect($fc['saved_ok'])->toBeFalse()
        ->and(collect($fc['parts'])->firstWhere('key', 'no_guardado')['total'])->toBe('30.50');

    // Volviendo a leer el archivo original se sabe EXACTAMENTE qué fila falta.
    $ns = app(MovementControlService::class)->notSavedRows($mayo, null);
    expect($ns['data'])->toHaveCount(1)
        ->and($ns['data'][0]['source_row'])->toBe((int) $lost->source_row)
        ->and($ns['data'][0]['concept'])->toBe('SE PIERDE')
        ->and($ns['data'][0]['abono'] + $ns['data'][0]['cargo'])->toEqual(30.50);

    // Y el endpoint devuelve lo mismo (con permiso del módulo).
    $admin = G::admin();
    $this->actingAs($admin)->getJson("/estado-resultados/{$mayo->id}/control-movimientos?vista=no_guardado")
        ->assertOk()->assertJsonPath('data.0.concept', 'SE PIERDE')->assertJsonPath('files.0.readable', true);
});

it('el duplicado entre archivos aparece en su lista con el archivo y la fila que sí cuentan', function () {
    $abril = F::period('2026-03-30', '2026-04-26');
    $mayo = F::period('2026-04-27', '2026-05-24');
    $row = ['2026-04-28', 40.00, null, 'S/R', 'PAGO REPETIDO', 'CORPORATIVO'];
    $first = cvImport($abril, F::adminXlsx([$row, ['2026-04-10', 5.00, null, 'S/R', 'OTRO ABRIL', 'CORPORATIVO']]), 'administrativa', 'abril.xlsx');
    $second = cvImport($mayo, F::adminXlsx([$row]), 'administrativa', 'mayo.xlsx');

    $da = cvBuild($abril);
    $dup = collect($da['movements'])->firstWhere('concept', 'PAGO REPETIDO');
    $winnerRow = EstadoResultadosMovement::query()->where('upload_id', $second->id)->value('source_row');
    expect($dup['control']['status'])->toBe(MovementControlService::DUPLICADO)
        ->and($dup['placement'])->toBe('duplicado')
        ->and($dup['duplicate_of']['upload_id'])->toBe($second->id)
        ->and($dup['duplicate_of']['file'])->toBe('mayo.xlsx')
        ->and($dup['duplicate_of']['source_row'])->toBe((int) $winnerRow)
        ->and($dup['duplicate_of']['period_label'])->toBe($mayo->label)
        ->and($dup['control']['explain'])->toContain('mayo.xlsx');

    // La partición del archivo de Abril dice con qué archivo choca y Abril sigue cuadrando a $0.00.
    $fc = collect($da['validation']['file_control']['uploads'])->firstWhere('upload_id', $first->id);
    $part = collect($fc['parts'])->firstWhere('key', 'duplicado');
    expect($part['total'])->toBe('40.00')
        ->and($part['against'][0]['file'])->toBe('mayo.xlsx')
        ->and($da['validation']['integrity']['import_matches_source'])->toBeTrue();

    // Mayo cuadra también y el ganador queda «completado» o «pendiente», nunca duplicado.
    $dm = cvBuild($mayo);
    $win = collect($dm['movements'])->where('concept', 'PAGO REPETIDO')->where('in_period', true)->first();
    expect($dm['validation']['integrity']['import_matches_source'])->toBeTrue()
        ->and($win['control']['status'])->toBeIn([MovementControlService::COMPLETADO, MovementControlService::PENDIENTE])
        ->and($win['duplicate_of'])->toBeNull();
});

it('cada partida sin resolver trae los movimientos que la componen', function () {
    $mayo = F::period('2026-04-27', '2026-05-24');
    cvImport($mayo, F::adminXlsx([
        ['2026-05-03', 70.00, null, 'S/R', 'PAGO SIN REQUISICION', 'CORPORATIVO'],
        ['2026-05-04', 15.00, null, 'S/R', 'OTRO SIN REQUISICION', 'CORPORATIVO'],
    ]));
    $d = cvBuild($mayo);
    $pend = collect($d['validation']['unresolved'])->firstWhere('key', 'pendientes');
    $pendingFps = collect($d['movements'])->where('control.status', MovementControlService::PENDIENTE)->pluck('fingerprint')->sort()->values()->all();

    expect($pend['count'])->toBe(2)
        ->and(collect($pend['items'])->pluck('fingerprint')->sort()->values()->all())->toBe($pendingFps)
        ->and(collect($pend['items'])->sum(fn ($i) => (float) $i['amount']))->toEqual(85.0);
    foreach ($d['movements'] as $m) {
        expect($m['control']['label'])->not->toBeEmpty()->and($m['control']['explain'])->not->toBeEmpty();
    }
});

it('el control de movimientos y su Excel respetan permisos', function () {
    $mayo = F::period('2026-04-27', '2026-05-24');
    cvImport($mayo, F::adminXlsx([['2026-05-03', 70.00, null, 'S/R', 'PAGO SIN REQUISICION', 'CORPORATIVO']]));
    cvBuild($mayo);

    $viewer = G::userWith([]);
    $this->actingAs($viewer)->getJson("/estado-resultados/{$mayo->id}/control-movimientos")->assertOk()->assertJsonPath('total', 0);
    $this->actingAs($viewer)->get("/estado-resultados/{$mayo->id}/control-movimientos/exportar?estado=pendiente")->assertForbidden();

    $res = $this->actingAs(G::admin())->get("/estado-resultados/{$mayo->id}/control-movimientos/exportar?estado=pendiente")->assertOk();
    expect($res->headers->get('content-type'))->toContain('spreadsheetml');
});
