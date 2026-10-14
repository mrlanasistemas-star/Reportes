<?php

use App\Services\Efectividad\Calculadora\EfectividadCalculadoraService;
use App\Services\Efectividad\Calculadora\EfectividadWorkspace;
use App\Services\Efectividad\V4\EfectividadV4Calculator;
use App\Services\Efectividad\V4\EfectividadV4ParityComparator;
use App\Services\Efectividad\V4\EfectividadV4ReferenceWorkbook;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * PARIDAD contra la versión definitiva (V6, "PRUEBA TULA"): valores esperados = celdas
 * reales de las hojas EFECTIVIDAD y _AUDITORIA_EFECTIVIDAD del libro, nunca resultados
 * generados por PHP. Entradas = los 8 "Saldos Por Cliente" y el "Ingresos cobranza"
 * originales de Lendus, por el mismo flujo que usa la pantalla.
 *
 * Los archivos tienen datos personales y no se versionan: sin ellos, se omite.
 */
const TULA_DIR = 'docs/ejemplosCorte';
const TULA_LIBRO = 'docs/ejemplosCorte/MR_LANA_EFECTIVIDAD PRUEBA TULA.xlsm';

beforeEach(function () {
    if (! is_file(base_path(TULA_LIBRO)) || count(glob(base_path(TULA_DIR.'/*.xlsx'))) !== 9) {
        $this->markTestSkipped('No están los archivos de referencia de Tula en '.TULA_DIR.' (locales, no versionados).');
    }
    ini_set('memory_limit', '3G');
    $this->ref = (new EfectividadV4ReferenceWorkbook)->load(base_path(TULA_LIBRO), sys_get_temp_dir().'/reportes-efectividad-tula');
    $this->dir = sys_get_temp_dir().'/tula-'.uniqid();
    File::ensureDirectoryExists($this->dir);
});

afterEach(function () {
    if (isset($this->dir)) {
        File::deleteDirectory($this->dir);
    }
    app(EfectividadWorkspace::class)->purgarVencidos(0);
});

function tulaUploads(string $dir, array $extra = []): array
{
    return array_map(function ($p) use ($dir) {
        $c = $dir.'/up_'.uniqid().'_'.basename($p);
        copy($p, $c);

        return new UploadedFile($c, basename($p), null, null, true);
    }, array_merge($extra, glob(base_path(TULA_DIR.'/*.xlsx'))));
}

/** Ingresos de semanas previas (lo que el historial del libro tenía antes del 05/10), con formato Lendus. */
function tulaHistorialPrevio(array $ref, string $path): string
{
    $hist = $ref['crudo']['_HIST_INGRESOS'];
    $calc = new EfectividadV4Calculator;
    $col = array_search('Fecha transacción', $hist[0], true);
    $w = new Writer;
    $w->openToFile($path);
    foreach ([['MR LANA'], ['INGRESOS COBRANZA'], [''], $hist[0]] as $r) {
        $w->addRow(Row::fromValues($r));
    }
    for ($i = 1, $n = count($hist); $i < $n; $i++) {
        $row = array_map(fn ($v) => is_array($v) ? $v['__d'] : $v, $hist[$i]);
        $f = $calc->leerFecha($row[$col] ?? null);
        if ($f !== null && $calc->ymd($f) < '2026-10-05') {
            $w->addRow(Row::fromValues($row));
        }
    }
    $w->close();

    return $path;
}

it('motor: con las mismas entradas del libro reproduce EFECTIVIDAD (180 valores) y las 246 incidencias', function () {
    $c = $this->ref['control'];
    $res = (new EfectividadV4Calculator)->calculate(
        ['modo' => $c['modo'], 'domingo_base' => $c['domingo_base'], 'fecha_corte' => $c['fecha_corte']],
        $this->ref['fotos'], $this->ref['ingresos'], $this->ref['historial'],
    );
    $cmp = (new EfectividadV4ParityComparator)->compare($this->ref['esperado'], $res['filas'], $this->ref['incidencias_esperadas'], $res['incidencias']);

    expect($cmp['resumen']['valores_comparados'])->toBe(180)
        ->and($cmp['resumen']['valores_exactos'])->toBe(180)
        ->and($cmp['resumen']['mismo_orden'])->toBeTrue()
        ->and($cmp['incidencias']['excel'])->toBe(246)
        ->and($cmp['incidencias']['solo_excel'])->toBe([])
        ->and($cmp['incidencias']['solo_laravel'])->toBe([])
        ->and($cmp['incidencias']['texto_distinto'])->toBe([])
        ->and($cmp['incidencias']['mismo_orden'])->toBeTrue();
});

it('Excel originales de Lendus + ingresos previos: paridad exacta de importes, agrupación e incidencias, sin BD', function () {
    $escrituras = 0;
    DB::listen(function ($q) use (&$escrituras) {
        $escrituras += preg_match('/^\s*(insert|update|delete|replace)/i', $q->sql);
    });
    $svc = app(EfectividadCalculadoraService::class);

    // Solo la semana: importes idénticos; faltan las NO-CARTERA que vienen del historial.
    $t1 = $svc->agregarArchivos(null, 1, tulaUploads($this->dir))['token'];
    $r1 = $svc->calcular($t1, 1, 'SEMANAL', '2026-10-11');
    $c1 = (new EfectividadV4ParityComparator)->compare($this->ref['esperado'], $r1['filas'], $this->ref['incidencias_esperadas'], $r1['incidencias']);
    expect($c1['resumen']['valores_exactos'])->toBe(180)
        ->and(collect($r1['incidencias'])->countBy('codigo')->all())->toBe(['FOTO-DUP' => 8, 'DUP' => 1])
        ->and($r1['avisos'])->not->toBeEmpty();

    // Con los ingresos de semanas previas: también las 246 incidencias, mismo id, texto y orden.
    $hist = tulaHistorialPrevio($this->ref, $this->dir.'/Ingresos cobranza previos.xlsx');
    $t2 = $svc->agregarArchivos(null, 1, tulaUploads($this->dir, [$hist]))['token'];
    $r2 = $svc->calcular($t2, 1, 'SEMANAL', '2026-10-11');
    $c2 = (new EfectividadV4ParityComparator)->compare($this->ref['esperado'], $r2['filas'], $this->ref['incidencias_esperadas'], $r2['incidencias']);
    $gt = collect($r2['filas'])->firstWhere('tipo', 'gran_total');

    expect($c2['resumen']['valores_exactos'])->toBe(180)
        ->and($c2['resumen']['mismo_orden'])->toBeTrue()
        ->and($c2['incidencias']['solo_excel'])->toBe([])
        ->and($c2['incidencias']['solo_laravel'])->toBe([])
        ->and($c2['incidencias']['mismo_orden'])->toBeTrue()
        ->and(round($gt['vencido_pc'], 2))->toBe(1271647.69)
        ->and(round($gt['pct_total'] * 100, 2))->toBe(91.64)
        ->and(collect($r2['filas'])->pluck('oficina')->filter()->contains(fn ($o) => str_contains($o, 'ATITALAQUIA')))->toBeFalse()
        ->and($r2['avisos'])->toBe([]);

    // Diario/Acumulado/Mensual corren con los mismos archivos; el mensual de una sola semana = semanal.
    foreach (['DIARIO' => '2026-10-07', 'ACUMULADO' => '2026-10-08'] as $modo => $dia) {
        expect($svc->calcular($t2, 1, $modo, $dia)['filas'])->not->toBeEmpty();
    }
    $mensual = collect($svc->calcular($t2, 1, 'MENSUAL', '2026-10')['filas'])->firstWhere('tipo', 'gran_total');
    expect($mensual['cobranza_total'])->toBe($gt['cobranza_total'])->and($mensual['vencido_pc'])->toBe($gt['vencido_pc']);

    expect($escrituras)->toBe(0);
});

it('Tula paso a paso: domingo+lunes sin ingresos → solo faltan ingresos; luego diario, acumulado y semanal en la misma sesión', function () {
    $escrituras = 0;
    DB::listen(function ($q) use (&$escrituras) {
        $escrituras += preg_match('/^\s*(insert|update|delete|replace)/i', $q->sql);
    });
    $svc = app(EfectividadCalculadoraService::class);
    $up = function (array $nombres) {
        return array_map(function ($n) {
            $c = $this->dir.'/up_'.uniqid().'.xlsx';
            copy(base_path(TULA_DIR.'/'.$n), $c);

            return new UploadedFile($c, $n, null, null, true);
        }, $nombres);
    };
    $cartera = fn (int $n) => "Saldos Por Cliente ($n).xlsx";
    $corte = fn ($r, $modo, $f) => collect($r['resumen']['cortes'][$modo])->firstWhere('valor', $f);

    // 1. Domingo 04 + lunes 05, sin ingresos.
    $r = $svc->agregarArchivos(null, 1, $up([$cartera(12), $cartera(13)]));
    $t = $r['token'];
    $lunes = $corte($r, 'DIARIO', '2026-10-05');
    expect($lunes['titulo'])->toBe('Corte diario: lunes 05/10/2026')
        ->and(collect($lunes['requisitos'])->map(fn ($q) => [$q['etiqueta'], $q['disponible']])->all())->toBe([
            ['Cartera base (domingo) 04/10', true], ['Cartera del lunes 05/10', true], ['Ingresos Cobranza del lunes 05/10', false],
        ])
        ->and($lunes['falta'])->toBe('Solo falta cargar Ingresos Cobranza del lunes 05/10 para calcular.');

    // 2. + Ingresos de la semana → calcula el lunes con solo sus movimientos del día.
    $r = $svc->agregarArchivos($t, 1, $up(['Ingresos cobranza 2026-10-05 A 2026-10-11 (3).xlsx']));
    expect($corte($r, 'DIARIO', '2026-10-05')['listo'])->toBeTrue();
    $d05 = $svc->calcular($t, 1, 'DIARIO', '2026-10-05');
    expect($d05['parametros']['inicio_ingresos'])->toBe('2026-10-05')->and($d05['parametros']['fin_ingresos'])->toBe('2026-10-05')
        ->and(collect($d05['filas'])->firstWhere('tipo', 'gran_total')['sano_pc'])->toBeGreaterThan(0);

    // 3. + martes → diario del martes.  4. + miércoles → acumulado al miércoles (lunes a miércoles).
    $svc->agregarArchivos($t, 1, $up([$cartera(14)]));
    expect($svc->calcular($t, 1, 'DIARIO', '2026-10-06')['titulo'])->toBe('CORTE DIARIO: 06/10/2026');
    $r = $svc->agregarArchivos($t, 1, $up([$cartera(15)]));
    expect(collect($corte($r, 'ACUMULADO', '2026-10-07')['requisitos'])->where('tipo', 'cartera')->count())->toBe(4)
        ->and($corte($r, 'SEMANAL', '2026-10-11')['estado'])->toBe('faltan_carteras');
    $acu = $svc->calcular($t, 1, 'ACUMULADO', '2026-10-07');
    expect($acu['titulo'])->toBe('ACUMULADO: 05/10/2026 AL 07/10/2026')
        ->and([$acu['parametros']['inicio_ingresos'], $acu['parametros']['fin_ingresos']])->toBe(['2026-10-05', '2026-10-07']);

    // 5. Resto de la semana → SEMANAL idéntico a las cifras aprobadas (importes; sin ingresos previos).
    $svc->agregarArchivos($t, 1, $up([$cartera(16), $cartera(17), $cartera(18), $cartera(19)]));
    $sem = $svc->calcular($t, 1, 'SEMANAL', '2026-10-11');
    $cmp = (new EfectividadV4ParityComparator)->compare($this->ref['esperado'], $sem['filas'], [], []);
    expect($cmp['resumen']['valores_exactos'])->toBe(180)->and($cmp['resumen']['mismo_orden'])->toBeTrue();

    // 6-7. Cambiar de modalidad reutiliza los mismos archivos (diario del lunes sigue igual).
    expect($svc->calcular($t, 1, 'DIARIO', '2026-10-05')['filas'])->toEqual($d05['filas']);

    expect($escrituras)->toBe(0);
});
