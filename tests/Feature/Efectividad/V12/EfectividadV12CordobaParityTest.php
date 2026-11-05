<?php

use App\Services\Efectividad\V12\EfectividadV12Calculator;
use App\Services\Efectividad\V12\V12ColumnMap;
use App\Services\Efectividad\V12\V12Vba;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/**
 * PARIDAD V12.9.4 contra la hoja de referencia de Córdoba (semana 26/10/2026 – 01/11/2026).
 *
 * Entradas = las 8 fotografías de cartera y el historial de ingresos guardados en el propio
 * libro; valores esperados = celdas reales de EFECTIVIDAD, AMORTIZACIONES,
 * _CONCILIACION_CARTERA y _AUDITORIA_EFECTIVIDAD (nunca resultados generados por PHP).
 * El libro tiene datos de clientes reales y no se versiona: sin él, se omite.
 */
const CORDOBA_LIBRO = 'docs/efectividad/ultimaVersion/VERSIÓN ACTUALIZADA EFECTIVIDAD DE COBRANZA CÓRDOBA.xlsm';

/** @return array<string, list<list<mixed>>> */
function cordobaHojas(): array
{
    static $hojas = null;
    if ($hojas !== null) {
        return $hojas;
    }
    $hojas = [];
    $r = new Reader(new Options(SHOULD_FORMAT_DATES: false, SHOULD_PRESERVE_EMPTY_ROWS: true));
    $r->open(base_path(CORDOBA_LIBRO));
    foreach ($r->getSheetIterator() as $sh) {
        $filas = [];
        foreach ($sh->getRowIterator() as $row) {
            $filas[] = array_map(fn ($c) => ($v = $c->getValue()) instanceof DateTimeInterface ? $v->format('Y-m-d') : $v, $row->cells);
        }
        $hojas[$sh->getName()] = $filas;
    }
    $r->close();

    return $hojas;
}

/** Filas canónicas de una hoja (encabezado = fila con Contrato + Substatus / Fecha transacción). */
function cordobaCanon(array $filas, array $mapa): array
{
    foreach ($filas as $h => $f) {
        $n = array_map(fn ($v) => V12Vba::norm($v), $f);
        if (in_array('CONTRATO', $n, true) && (in_array('SUBSTATUS', $n, true) || in_array('FECHA TRANSACCION', $n, true))) {
            break;
        }
    }
    $m = V12ColumnMap::resolver($filas[$h], $mapa);
    $out = [];
    foreach (array_slice($filas, $h + 1) as $j => $f) {
        $fila = [];
        foreach ($m as $campo => $i) {
            $fila[$campo] = $f[$i] ?? null;
        }
        if (array_filter($fila, fn ($v) => $v !== null && $v !== '')) {
            $out[] = $fila + ['ref' => (string) ($h + 2 + $j)];
        }
    }

    return ['columnas' => array_keys($m), 'filas' => $out];
}

function cordobaCalcular(string $hojaHistorial = '_HIST_INGRESOS'): array
{
    static $cache = [];
    if (isset($cache[$hojaHistorial])) {
        return $cache[$hojaHistorial];
    }
    $h = cordobaHojas();
    $fotos = [];
    foreach (EfectividadV12Calculator::NOMBRES_FOTO as $i => $nombre) {
        $fotos[$i] = cordobaCanon($h[$nombre], V12ColumnMap::FOTO);
    }

    return $cache[$hojaHistorial] = (new EfectividadV12Calculator)->calculate(
        ['modo' => 'SEMANAL', 'domingo_base' => '2026-10-25', 'fecha_corte' => '2026-11-01'],
        $fotos, cordobaCanon($h['INGRESOS'], V12ColumnMap::INGRESOS), cordobaCanon($h[$hojaHistorial], V12ColumnMap::INGRESOS), true,
    );
}

beforeEach(function () {
    if (! is_file(base_path(CORDOBA_LIBRO))) {
        $this->markTestSkipped('No está la hoja de referencia V12.9.4 de Córdoba (local, no versionada).');
    }
});

it('EFECTIVIDAD: mismas filas, mismo orden y los 348 valores iguales a la hoja de referencia', function () {
    $r = cordobaCalcular();
    $esperado = [];
    foreach (array_slice(cordobaHojas()['EFECTIVIDAD'], 5) as $f) {
        $etiqueta = (string) ($f[0] ?? '');
        if ($etiqueta !== '' && ! str_starts_with($etiqueta, 'RUTA: ')) {
            $esperado[] = [$etiqueta, array_slice(array_pad($f, 13, null), 1, 12)];
        }
    }
    $campos = ['sano_pc', 'sano_cob', 'sano_pct', 'atraso_pc', 'atraso_cob', 'atraso_pct', 'vencido_pc', 'vencido_cob', 'vencido_pct', 'cobranza_total', 'pct_total', 'mora'];
    $difs = [];
    $iguales = 0;
    foreach ($r['filas'] as $i => $fila) {
        [$etiqueta, $valores] = $esperado[$i];
        expect($fila['promotor'])->toBe($etiqueta);
        foreach ($campos as $j => $c) {
            $y = $valores[$j] === '' ? null : $valores[$j];
            if ($fila[$c] === null && $y === null) {
                $iguales++;
            } elseif ($fila[$c] === null || $y === null || abs($fila[$c] - $y) > 1e-6) {
                $difs[] = "{$etiqueta} {$c}: ".json_encode($fila[$c]).' vs '.json_encode($y);
            } else {
                $iguales++;
            }
        }
    }
    expect(count($r['filas']))->toBe(count($esperado))->toBe(29)
        ->and($difs)->toBe([])
        ->and($iguales)->toBe(348);
});

it('casos documentados: 20 DE NOVIEMBRE (95.1492 %) y CALZADAS (105.2227 %, sin tope; mora negativa)', function () {
    $filas = collect(cordobaCalcular()['filas'])->where('tipo', 'promotor')->keyBy('oficina');
    $nov = $filas['20 DE NOVIEMBRE'];
    expect(round($nov['sano_pc'], 2))->toBe(50785.48)
        ->and(round($nov['sano_cob'], 2))->toBe(45776.48)
        ->and(round($nov['sano_pct'] * 100, 10))->toBe(90.1369446543)
        ->and(round($nov['atraso_pct'] * 100, 10))->toBe(4.3181633806)
        ->and(round($nov['vencido_pct'] * 100, 10))->toBe(0.694096029)
        ->and(round($nov['pct_total'] * 100, 10))->toBe(95.1492040638)
        ->and(abs($nov['pct_total'] - ($nov['sano_pct'] + $nov['atraso_pct'] + $nov['vencido_pct'])))->toBeLessThan(1e-12)
        ->and(round($nov['mora'], 2))->toBe(2463.5)
        ->and(round($filas['CALZADAS']['pct_total'] * 100, 10))->toBe(105.2226592552)
        ->and(round($filas['CALZADAS']['mora'], 2))->toBe(-2218.5);
});

it('AMORTIZACIONES: 8,613 cuotas con el mismo neto, previo, histórico, inferencia y evidencia', function () {
    $traza = cordobaCalcular()['amortizaciones'];
    $hoja = array_slice(cordobaHojas()['AMORTIZACIONES'], 1);
    $difs = [];
    foreach ($hoja as $i => $a) {
        $t = $traza[$i];
        $pares = ['contrato' => [$a[0], $t['contrato']], 'numero' => [(string) $a[1], $t['numero']], 'fecha' => [$a[2], $t['fecha']],
            'cuota' => [$a[4], $t['cuota']], 'previo' => [$a[5], $t['previo']], 'historico' => [$a[8], $t['historico']],
            'estimado' => [$a[9], $t['estimado']], 'neto' => [$a[11], $t['neto']], 'movimientos' => [$a[12], $t['movimientos']],
            'evidencia' => [str_replace('DESAPARICER', 'DESAPARECER', (string) $a[10]), $t['evidencia']]];
        foreach ($pares as $campo => [$x, $y]) {
            $dif = is_numeric($x) && is_numeric($y) ? abs($x - $y) > 1e-6 : (string) $x !== (string) $y;
            if ($dif && count($difs) < 20) {
                $difs[] = "fila {$i} {$campo}: ".json_encode($x).' vs '.json_encode($y);
            }
        }
    }
    expect(count($traza))->toBe(count($hoja))->toBe(8613)->and($difs)->toBe([]);
});

it('conciliación por contrato y estado del reporte iguales a la hoja de referencia (PRELIMINAR)', function () {
    $r = cordobaCalcular();
    $hoja = array_slice(cordobaHojas()['_CONCILIACION_CARTERA'], 5);
    $difs = [];
    foreach ($hoja as $i => $a) {
        $t = $r['conciliacion'][$i];
        foreach (['contrato' => [$a[0], $t['contrato']], 'atraso_base' => [$a[2], $t['atraso_base']], 'exigible' => [$a[3], $t['exigible_neto']],
            'cobranza' => [$a[4], $t['cobranza_elegible']], 'cierre' => [$a[6], $t['atraso_cierre']], 'diferencia' => [$a[7], $t['diferencia']],
            'estatus' => [$a[8], $t['estatus']]] as $campo => [$x, $y]) {
            if ((is_numeric($x) && is_numeric($y) ? abs($x - $y) > 1e-6 : (string) $x !== (string) $y) && count($difs) < 20) {
                $difs[] = "{$a[0]} {$campo}: ".json_encode($x).' vs '.json_encode($y);
            }
        }
    }
    $a = $r['auditoria'];
    expect(count($r['conciliacion']))->toBe(1010)->and($difs)->toBe([])
        ->and($a['estado'])->toBe('PRELIMINAR')
        ->and(round($a['sano_pc'], 2))->toBe(767657.47)
        ->and(round($a['cobranza_total'], 2))->toBe(716622.29)
        ->and(round($a['mora'], 2))->toBe(51035.18)
        ->and($a['contratos_ajustados'])->toBe(135)
        ->and($a['cuotas_inferidas'])->toBe(135)
        ->and(round($a['importe_inferido'], 2))->toBe(9155.07)
        ->and($a['contratos_con_diferencia'])->toBe(1)
        ->and(round($a['diferencia_sin_asignar'], 2))->toBe(-0.16)
        ->and($a['desapariciones_sin_soporte'])->toBe(36)
        ->and(round($a['saldo_sin_soporte'], 2))->toBe(97918.83)
        ->and($a['cierres_refi_comprobados'])->toBe(52);
});

it('solo con los ingresos de la semana (sin historial previo) los anticipos de semanas anteriores no se descuentan: +$88.00 de meta', function () {
    $gt = collect(cordobaCalcular('INGRESOS')['filas'])->firstWhere('tipo', 'gran_total');
    expect(round($gt['sano_pc'], 2))->toBe(767745.47)
        ->and(round($gt['cobranza_total'], 2))->toBe(716622.29);
});
