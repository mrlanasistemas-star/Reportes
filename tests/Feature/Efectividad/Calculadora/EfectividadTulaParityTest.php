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
 * PARIDAD contra el libro "PRUEBA TULA" (V6) con la metodología V7 aplicada: valores esperados = celdas
 * reales de las hojas EFECTIVIDAD y _AUDITORIA_EFECTIVIDAD del libro, nunca resultados
 * generados por PHP. Entradas = los 8 "Saldos Por Cliente" y el "Ingresos cobranza"
 * originales de Lendus, por el mismo flujo que usa la pantalla.
 *
 * Los archivos tienen datos personales y no se versionan: sin ellos, se omite.
 */
const TULA_DIR = 'docs/ejemplosCorte';
const TULA_LIBRO = 'docs/ejemplosCorte/MR_LANA_EFECTIVIDAD PRUEBA TULA.xlsm';

/** Libro de la macro V7 (MR_LANA_EFECTIVIDAD PRUEBA TULA(2).xlsm). Mientras no esté copiado, se valida contra V6 + invariantes V7. */
const TULA_LIBRO_V7 = 'docs/ejemplosCorte/MR_LANA_EFECTIVIDAD PRUEBA TULA(2).xlsm';

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


/**
 * V7 (nota técnica V6→V7): el libro local es la versión V6. Contra él, V7 debe coincidir
 * EXACTO en todo excepto Sano cobrado / Vigente con atraso cobrado (y sus %), que solo se
 * reclasifican entre sí: misma suma por fila, mismo total, vencido, saldos y % total.
 * Si se copia el libro V7 (TULA_LIBRO_V7), la paridad vuelve a ser exacta en los 180 valores.
 *
 * @return array{otros: list<string>, suma: list<string>, reclasificado: array<string,float>}
 */
function tulaContraV6(array $esperadoV6, array $filas): array
{
    $v7 = [];
    foreach ($filas as $f) {
        $v7[$f['tipo'].'|'.mb_strtoupper((string) ($f['oficina'] ?? '')).'|'.$f['promotor']] = $f;
    }
    $otros = $suma = [];
    $reclasificado = [];
    foreach ($esperadoV6 as $e) {
        $k = $e['tipo'].'|'.mb_strtoupper((string) ($e['oficina'] ?? '')).'|'.$e['promotor'];
        $c = $v7[$k] ?? null;
        if ($c === null) {
            $otros[] = "$k: falta";

            continue;
        }
        foreach (['sano_pc', 'atraso_pc', 'vencido_pc', 'vencido_cob', 'vencido_pct', 'cobranza_total', 'pct_total', 'mora'] as $campo) {
            $tol = str_ends_with($campo, 'pct') || $campo === 'pct_total' ? EfectividadV4ParityComparator::TOL_PCT : EfectividadV4ParityComparator::TOL_MONTO;
            if (abs((float) $c[$campo] - (float) $e[$campo]) > $tol) {
                $otros[] = "$k: $campo";
            }
        }
        if (abs(($c['sano_cob'] + $c['atraso_cob']) - ($e['sano_cob'] + $e['atraso_cob'])) > 0.000001) {
            $suma[] = $k;
        }
        $reclasificado[$k] = round($c['sano_cob'] - $e['sano_cob'], 2);
        if (abs(round($e['atraso_cob'] - $c['atraso_cob'], 2) - $reclasificado[$k]) > 0.001 || $reclasificado[$k] < 0) {
            $suma[] = "$k: atraso no baja lo que sube sano";
        }
    }

    return ['otros' => $otros, 'suma' => $suma, 'reclasificado' => $reclasificado];
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

it('motor V7: mismas entradas del libro → 246 incidencias iguales; contra V6 solo se reclasifica atraso→sano (57 cobros, $48,505.99)', function () {
    $c = $this->ref['control'];
    $res = (new EfectividadV4Calculator)->calculate(
        ['modo' => $c['modo'], 'domingo_base' => $c['domingo_base'], 'fecha_corte' => $c['fecha_corte']],
        $this->ref['fotos'], $this->ref['ingresos'], $this->ref['historial'], true,
    );
    $cmp = (new EfectividadV4ParityComparator)->compare($this->ref['esperado'], $res['filas'], $this->ref['incidencias_esperadas'], $res['incidencias']);
    $v6 = tulaContraV6($this->ref['esperado'], $res['filas']);

    // Reclasificación derivada de la traza, independiente de las filas: cobros puntuales (sano)
    // de contratos con atraso en la foto del día = exactamente lo que V6 contaba como atraso.
    $movs = collect($res['movimientos'])->filter(fn ($m) => $m['categoria'] === 'sano' && $m['atraso_foto'] > 0.001);
    $gt = 'gran_total||GRAN TOTAL';

    expect($res['metodologia'])->toBe('V7')
        ->and($cmp['resumen']['valores_comparados'])->toBe(180)
        ->and($cmp['resumen']['mismo_orden'])->toBeTrue()
        ->and($v6['otros'])->toBe([])
        ->and($v6['suma'])->toBe([])
        ->and($movs->count())->toBe(57)
        ->and(round($movs->sum('importe'), 2))->toBe(48505.99)
        ->and($v6['reclasificado'][$gt])->toBe(48505.99)
        // Por promotor: lo reclasificado en cada fila = suma de sus cobros reclasificados en la traza.
        ->and(collect($v6['reclasificado'])->filter(fn ($v, $k) => str_starts_with($k, 'promotor|'))->values()->sort()->values()->all())
        ->toBe($movs->groupBy('grupo')->map(fn ($g) => round($g->sum('importe'), 2))->values()->sort()->values()->all())
        ->and(collect($res['filas'])->every(fn ($f) => abs($f['sano_cob'] + $f['atraso_cob'] + $f['vencido_cob'] - $f['cobranza_total']) < 0.000001))->toBeTrue()
        ->and($cmp['incidencias']['excel'])->toBe(246)
        ->and($cmp['incidencias']['solo_excel'])->toBe([])
        ->and($cmp['incidencias']['solo_laravel'])->toBe([])
        ->and($cmp['incidencias']['texto_distinto'])->toBe([])
        ->and($cmp['incidencias']['mismo_orden'])->toBeTrue();
});

it('macro V7 (PRUEBA TULA(2).xlsm): paridad exacta de los 180 valores y las incidencias del libro actualizado', function () {
    if (! is_file(base_path(TULA_LIBRO_V7))) {
        $this->markTestSkipped('Falta copiar el libro V7 en '.TULA_LIBRO_V7.': la paridad exacta contra la macro V7 queda pendiente.');
    }
    $ref = (new EfectividadV4ReferenceWorkbook)->load(base_path(TULA_LIBRO_V7), sys_get_temp_dir().'/reportes-efectividad-tula-v7');
    $c = $ref['control'];
    $res = (new EfectividadV4Calculator)->calculate(
        ['modo' => $c['modo'], 'domingo_base' => $c['domingo_base'], 'fecha_corte' => $c['fecha_corte']],
        $ref['fotos'], $ref['ingresos'], $ref['historial'],
    );
    $cmp = (new EfectividadV4ParityComparator)->compare($ref['esperado'], $res['filas'], $ref['incidencias_esperadas'], $res['incidencias']);

    expect(collect($cmp['filas'])->where('estado', 'DIFERENTE')->values()->all())->toBe([])
        ->and($cmp['resumen']['paridad'])->toBeTrue()
        ->and($cmp['incidencias']['solo_excel'])->toBe([])
        ->and($cmp['incidencias']['solo_laravel'])->toBe([])
        ->and($cmp['incidencias']['texto_distinto'])->toBe([]);
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
    expect(tulaContraV6($this->ref['esperado'], $r1['filas'])['otros'])->toBe([])
        ->and(tulaContraV6($this->ref['esperado'], $r1['filas'])['suma'])->toBe([])
        ->and(collect($r1['incidencias'])->countBy('codigo')->all())->toBe(['FOTO-DUP' => 8, 'DUP' => 1])
        ->and($r1['avisos'])->not->toBeEmpty();

    // Con los ingresos de semanas previas: también las 246 incidencias, mismo id, texto y orden.
    $hist = tulaHistorialPrevio($this->ref, $this->dir.'/Ingresos cobranza previos.xlsx');
    $t2 = $svc->agregarArchivos(null, 1, tulaUploads($this->dir, [$hist]))['token'];
    $r2 = $svc->calcular($t2, 1, 'SEMANAL', '2026-10-11');
    $c2 = (new EfectividadV4ParityComparator)->compare($this->ref['esperado'], $r2['filas'], $this->ref['incidencias_esperadas'], $r2['incidencias']);
    $gt = collect($r2['filas'])->firstWhere('tipo', 'gran_total');

    $v6 = tulaContraV6($this->ref['esperado'], $r2['filas']);
    expect($v6['otros'])->toBe([])->and($v6['suma'])->toBe([])
        ->and($v6['reclasificado']['gran_total||GRAN TOTAL'])->toBe(48505.99)
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
    expect($mensual['cobranza_total'])->toBe($gt['cobranza_total'])->and($mensual['vencido_pc'])->toBe($gt['vencido_pc'])
        // El mensual suma semanas V7: hereda la reclasificación sin cálculo propio.
        ->and($mensual['sano_cob'])->toBe($gt['sano_cob'])->and($mensual['atraso_cob'])->toBe($gt['atraso_cob']);

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
    $v6 = tulaContraV6($this->ref['esperado'], $sem['filas']);
    expect($v6['otros'])->toBe([])->and($v6['suma'])->toBe([])
        ->and($v6['reclasificado']['gran_total||GRAN TOTAL'])->toBe(48505.99)
        ->and($cmp['resumen']['mismo_orden'])->toBeTrue();

    // 6-7. Cambiar de modalidad reutiliza los mismos archivos (diario del lunes sigue igual).
    expect($svc->calcular($t, 1, 'DIARIO', '2026-10-05')['filas'])->toEqual($d05['filas']);

    expect($escrituras)->toBe(0);
});
