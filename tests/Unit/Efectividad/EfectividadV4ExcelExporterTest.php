<?php

use App\Services\Efectividad\V4\EfectividadV4Calculator;
use App\Services\Efectividad\V4\EfectividadV4ExcelExporter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Exportador XLSX del motor V4: se genera el archivo, se ABRE con PhpSpreadsheet
 * y se validan celdas (estructura A-M, valores, formatos, semáforos, control de
 * corte y bitácora _AUDITORIA_EFECTIVIDAD). Datos sintéticos, sin datos personales.
 */
function xv4Fila(array $o = []): array
{
    return $o + [
        'contrato' => 'C1', 'acreditado' => ' ', 'oficina' => 'RUTA A', 'nombre_promotor' => 'ANA', 'substatus' => 'VIGENTE',
        'estatus' => 'ACTIVO', 'producto' => 'S12', 'fecha_primer_pago' => '2026-10-05', 'fecha_vencimiento' => '2026-12-31',
        'cuota_total' => 100.0, 'periodicidad' => 'SEMANAL', 'pagos' => 12.0, 'total_atrasado' => 0.0, 'pagos_atrasados' => 0.0,
        'total_pendiente' => 1000.0, 'saldo_capital' => 900.0,
    ];
}

function xv4Pago(array $o = []): array
{
    return $o + [
        'oficina' => 'RUTA A', 'promotor' => 'ANA', 'contrato' => 'C1', 'acreditado' => 'X', 'operacion' => 'PAGO A CONTRATO',
        'transaccion' => 'PAGO', 'fecha_transaccion' => '2026-10-05', 'fecha_cuota' => '2026-10-05', 'capital' => 100.0,
        'interes' => 0.0, 'impuesto' => 0.0, 'cargos_calendario' => 0.0, 'producto' => 'S12', 'numero_cuota' => 1,
    ];
}

/** Dos oficinas (una con caracteres especiales y dos promotores) + incidencias NO-CARTERA y DUP. */
function xv4Escenario(): array
{
    $cols = ['contrato', 'acreditado', 'oficina', 'nombre_promotor', 'substatus', 'estatus', 'producto', 'fecha_primer_pago',
        'fecha_vencimiento', 'cuota_total', 'periodicidad', 'pagos', 'total_atrasado', 'pagos_atrasados', 'total_pendiente', 'saldo_capital'];
    $filas = [
        // RUTA A: ANA tiene más movimientos que BETO → responsable de TODA la ruta (regla de la macro): 300 por cobrar, 275 cobrado.
        xv4Fila(),
        xv4Fila(['contrato' => 'C2', 'nombre_promotor' => 'BETO', 'cuota_total' => 200.0]),
        xv4Fila(['contrato' => 'C3', 'oficina' => 'PEÑÓN & "NORTE"', 'nombre_promotor' => 'MARÍA O\'NEIL', 'cuota_total' => 50.0]),
        xv4Fila(['contrato' => 'C4', 'oficina' => '=HYPERLINK("x")', 'nombre_promotor' => '+CMD', 'cuota_total' => 10.0]),
    ];
    $pagos = [
        xv4Pago(),
        xv4Pago(['contrato' => 'C2', 'promotor' => 'BETO', 'capital' => 175.0]),
        xv4Pago(['contrato' => 'C4', 'oficina' => '=HYPERLINK("x")', 'promotor' => '+CMD', 'capital' => 10.0]),
        xv4Pago(['contrato' => 'C3', 'oficina' => 'PEÑÓN & "NORTE"', 'promotor' => 'MARÍA O\'NEIL', 'capital' => 30.0, 'fecha_transaccion' => '2026-10-07']),
        xv4Pago(['contrato' => 'C3', 'oficina' => 'PEÑÓN & "NORTE"', 'promotor' => 'MARÍA O\'NEIL', 'capital' => 30.0, 'fecha_transaccion' => '2026-10-07', 'ref' => '77']),
        xv4Pago(['contrato' => 'ZZ9', 'ref' => '91']),
    ];
    $fotos = [];
    for ($i = 0; $i <= 7; $i++) {
        $fotos[$i] = ['columnas' => $cols, 'filas' => $filas];
    }
    $ing = ['columnas' => ['oficina', 'promotor', 'contrato', 'acreditado', 'operacion', 'transaccion', 'fecha_transaccion', 'fecha_cuota', 'capital', 'interes', 'impuesto', 'cargos_calendario', 'producto', 'numero_cuota'], 'filas' => $pagos];

    return (new EfectividadV4Calculator)->calculate(['modo' => 'SEMANAL', 'domingo_base' => '2026-10-04', 'fecha_corte' => '2026-10-11'], $fotos, $ing, $ing);
}

function xv4Exportar(array $res, array $fuentes = []): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'efv4').'.xlsx';
    (new EfectividadV4ExcelExporter)->export($res['filas'], $res['parametros'], $res['incidencias'], $path, $fuentes, $res['engine_version']);
    expect(filesize($path))->toBeGreaterThan(0);
    $book = IOFactory::load($path);
    @unlink($path);

    return $book;
}

it('genera un XLSX válido con las hojas EFECTIVIDAD, _AUDITORIA_EFECTIVIDAD y FUENTES', function () {
    $book = xv4Exportar(xv4Escenario());

    expect($book->getSheetNames())->toBe(['EFECTIVIDAD', '_AUDITORIA_EFECTIVIDAD', 'FUENTES'])
        ->and($book->getActiveSheet()->getTitle())->toBe('EFECTIVIDAD')
        ->and($book->getProperties()->getDescription())->toBe('Motor mrlana-v4-2026-10');
});

it('hoja EFECTIVIDAD: encabezados A-M, periodo y leyenda como la macro', function () {
    $ws = xv4Exportar(xv4Escenario())->getSheetByName('EFECTIVIDAD');

    expect($ws->getCell('A1')->getValue())->toBe('MR LANA | EFECTIVIDAD DE COBRANZA')
        ->and($ws->getCell('A2')->getValue())->toBe('PERIODO: 05/10/2026 AL 11/10/2026')
        ->and($ws->getCell('A3')->getValue())->toBe(EfectividadV4Calculator::LEYENDA)
        ->and($ws->getCell('A4')->getValue())->toBe('Oficina')
        ->and($ws->getCell('B4')->getValue())->toBe('SANO')
        ->and($ws->getCell('E4')->getValue())->toBe('VIGENTES CON ATRASO')
        ->and($ws->getCell('H4')->getValue())->toBe('VENCIDOS CON ATRASO')
        ->and($ws->getCell('K4')->getValue())->toBe('COBRANZA TOTAL')
        ->and($ws->getCell('M4')->getValue())->toBe('MORA');
    expect(array_map(fn ($c) => $ws->getCell($c.'5')->getValue(), range('A', 'L')))->toBe([
        'Promotor', 'Por cobrar', 'Cobrado', '% Cobrado', 'Por cobrar', 'Cobrado', '% Cobrado', 'Por cobrar', 'Cobrado', '% Cobrado', 'Cobrado', '% Cobrado',
    ]);
    expect($ws->getMergeCells())->toHaveKeys(['A1:M1', 'A2:M2', 'A3:M3', 'B4:D4', 'E4:G4', 'H4:J4', 'K4:L4'])
        ->and($ws->getFreezePane())->toBe('A6');
});

it('hoja EFECTIVIDAD: filas de oficina, promotores, TOTAL por oficina y GRAN TOTAL con los valores del motor', function () {
    $res = xv4Escenario();
    $ws = xv4Exportar($res)->getSheetByName('EFECTIVIDAD');

    // Recorre la hoja y reconstruye las filas exportadas (columna A + B..M).
    $leidas = [];
    for ($r = 6; $r <= $ws->getHighestDataRow('A'); $r++) {
        $leidas[] = [$r, $ws->getCell("A{$r}")->getValue(), $ws->getCell("B{$r}")->getValue(), isset($ws->getMergeCells()["A{$r}:M{$r}"])];
    }
    $titulos = array_values(array_map(fn ($x) => $x[1], array_filter($leidas, fn ($x) => $x[3])));
    $datos = array_values(array_filter($leidas, fn ($x) => ! $x[3]));

    // Una banda de título por oficina, en el orden de la macro (oficina|promotor normalizados).
    expect($titulos)->toBe(['=HYPERLINK("X")', 'PEÑÓN & "NORTE"', 'RUTA A'])
        ->and(count($datos))->toBe(count($res['filas']));

    $campos = ['sano_pc', 'sano_cob', 'sano_pct', 'atraso_pc', 'atraso_cob', 'atraso_pct', 'vencido_pc', 'vencido_cob', 'vencido_pct', 'cobranza_total', 'pct_total', 'mora'];
    foreach ($res['filas'] as $i => $fila) {
        $r = $datos[$i][0];
        expect($ws->getCell("A{$r}")->getValue())->toBe($fila['promotor']);
        foreach ($campos as $j => $campo) {
            $col = chr(ord('B') + $j);
            expect((float) $ws->getCell("{$col}{$r}")->getValue())->toBe((float) $fila[$campo]);
        }
    }

    $ultima = end($datos)[0];
    expect($ws->getCell("A{$ultima}")->getValue())->toBe('GRAN TOTAL')
        ->and($ws->getCell("B{$ultima}")->getValue())->toEqual(360.0)  // 300 + 50 + 10
        ->and($ws->getCell("K{$ultima}")->getValue())->toEqual(315.0)  // 275 + 30 (atraso; el duplicado no suma) + 10
        ->and($ws->getCell("M{$ultima}")->getValue())->toEqual(45.0);
    expect(collect($datos)->pluck(1)->all())->not->toContain('BETO');
    expect(collect($datos)->pluck(1)->filter(fn ($a) => str_starts_with((string) $a, 'TOTAL '))->values()->all())
        ->toBe(['TOTAL =HYPERLINK("X")', 'TOTAL PEÑÓN & "NORTE"', 'TOTAL RUTA A']);
});

it('caracteres especiales y textos que parecen fórmula se guardan como TEXTO (sin inyección)', function () {
    $ws = xv4Exportar(xv4Escenario())->getSheetByName('EFECTIVIDAD');

    $celdas = [];
    for ($r = 6; $r <= $ws->getHighestDataRow('A'); $r++) {
        $c = $ws->getCell("A{$r}");
        $celdas[(string) $c->getValue()] = $c->getDataType();
    }
    expect($celdas)->toHaveKey('=HYPERLINK("X")')
        ->and($celdas['=HYPERLINK("X")'])->toBe(DataType::TYPE_STRING)
        ->and($celdas['+CMD'])->toBe(DataType::TYPE_STRING)
        ->and($celdas)->toHaveKey("MARÍA O'NEIL")
        ->and($celdas)->toHaveKey('PEÑÓN & "NORTE"');
});

it('formatos: moneda en montos, porcentaje en % y semáforo/mora con los colores de la macro', function () {
    $ws = xv4Exportar(xv4Escenario())->getSheetByName('EFECTIVIDAD');
    $fila = fn (string $promotor) => collect(range(6, $ws->getHighestDataRow('A')))->first(fn ($r) => $ws->getCell("A{$r}")->getValue() === $promotor);

    $cmd = $fila('+CMD');       // 100% → verde, mora 0
    $ana = $fila('ANA');        // 91.67% → amarillo
    $maria = $fila("MARÍA O'NEIL"); // 60% → rojo, mora > 0
    foreach (['B', 'C', 'E', 'F', 'H', 'I', 'K', 'M'] as $c) {
        expect($ws->getStyle("{$c}{$ana}")->getNumberFormat()->getFormatCode())->toBe('$#,##0.00;-$#,##0.00');
    }
    foreach (['D', 'G', 'J', 'L'] as $c) {
        expect($ws->getStyle("{$c}{$ana}")->getNumberFormat()->getFormatCode())->toBe('0.00%');
    }
    expect($ws->getCell("L{$ana}")->getValue())->toEqual(275 / 300)
        ->and($ws->getStyle("L{$cmd}")->getFill()->getStartColor()->getRGB())->toBe('C6EFCE')
        ->and($ws->getStyle("L{$ana}")->getFill()->getStartColor()->getRGB())->toBe('FFEB9C')
        ->and($ws->getStyle("L{$maria}")->getFill()->getStartColor()->getRGB())->toBe('FFC7CE')
        ->and($ws->getStyle("M{$maria}")->getFont()->getColor()->getRGB())->toBe('C00000')
        ->and($ws->getStyle("M{$cmd}")->getFont()->getColor()->getRGB())->toBe('000000');
});

it('control de corte (P-Q): modo, domingo base, fecha de corte, rango de ingresos y versión del motor', function () {
    $ws = xv4Exportar(xv4Escenario())->getSheetByName('EFECTIVIDAD');
    $fecha = fn (string $c) => ExcelDate::excelToDateTimeObject($ws->getCell($c)->getValue())->format('Y-m-d');

    expect($ws->getCell('P1')->getValue())->toBe('CONTROL DE CORTE AUTOMATICO | MR LANA')
        ->and($ws->getCell('P2')->getValue())->toBe('MODO')->and($ws->getCell('Q2')->getValue())->toBe('SEMANAL')
        ->and($ws->getCell('P3')->getValue())->toBe('DOMINGO BASE')->and($fecha('Q3'))->toBe('2026-10-04')
        ->and($ws->getCell('P4')->getValue())->toBe('FECHA DE CORTE')->and($fecha('Q4'))->toBe('2026-10-11')
        ->and($fecha('Q5'))->toBe('2026-10-05')->and($fecha('Q6'))->toBe('2026-10-11')
        ->and($ws->getStyle('Q3')->getNumberFormat()->getFormatCode())->toBe('dd/mm/yyyy')
        ->and($ws->getCell('Q7')->getValue())->toBe('mrlana-v4-2026-10');
});

it('modo DIARIO: periodo de un día y control de corte DIARIO', function () {
    $res = xv4Escenario();
    $cols = array_keys(xv4Fila());
    $fotos = [];
    for ($i = 0; $i <= 3; $i++) {
        $fotos[$i] = ['columnas' => $cols, 'filas' => [xv4Fila()]];
    }
    $ing = ['columnas' => array_keys(xv4Pago()), 'filas' => [xv4Pago(['fecha_transaccion' => '2026-10-07', 'fecha_cuota' => '2026-10-07'])]];
    $diario = (new EfectividadV4Calculator)->calculate(['modo' => 'DIARIO', 'domingo_base' => '2026-10-04', 'fecha_corte' => '2026-10-07'], $fotos, $ing, $ing);
    $ws = xv4Exportar($diario)->getSheetByName('EFECTIVIDAD');

    expect($ws->getCell('A2')->getValue())->toBe('PERIODO: 07/10/2026 AL 07/10/2026')
        ->and($ws->getCell('Q2')->getValue())->toBe('DIARIO');
    unset($res);
});

it('_AUDITORIA_EFECTIVIDAD: mismas incidencias, mismo orden, contrato como texto y tratamiento', function () {
    $res = xv4Escenario();
    $ws = xv4Exportar($res)->getSheetByName('_AUDITORIA_EFECTIVIDAD');

    expect($ws->getCell('A1')->getValue())->toBe('INCIDENCIAS DE CALCULO | MR LANA')
        ->and(array_map(fn ($c) => $ws->getCell($c.'5')->getValue(), range('A', 'G')))
        ->toBe(['ID', 'DETALLE / MOTIVO DE EXCLUSION', 'CODIGO', 'CONTRATO', 'FECHA RECONOCIMIENTO', 'IMPORTE', 'TRATAMIENTO']);

    expect(array_column($res['incidencias'], 'codigo'))->toBe(['DUP', 'NO-CARTERA']);
    foreach ($res['incidencias'] as $i => $inc) {
        $r = 6 + $i;
        expect($ws->getCell("A{$r}")->getValue())->toBe($inc['id'])
            ->and($ws->getCell("B{$r}")->getValue())->toBe($inc['descripcion'])
            ->and($ws->getCell("C{$r}")->getValue())->toBe($inc['codigo'])
            ->and($ws->getCell("D{$r}")->getValue())->toBe($inc['contrato'])
            ->and($ws->getCell("D{$r}")->getDataType())->toBe(DataType::TYPE_STRING)
            ->and((float) $ws->getCell("F{$r}")->getValue())->toBe((float) $inc['importe']);
    }
    expect($ws->getCell('G7')->getValue())->toBe('Pago excluido del cálculo')
        ->and($ws->getCell('A8')->getValue())->toBeNull();
});

it('sin movimientos ni incidencias: hoja con leyenda explícita (nunca un 0% inventado)', function () {
    $vacio = ['filas' => [], 'incidencias' => [], 'engine_version' => 'mrlana-v4-2026-10',
        'parametros' => ['modo' => 'SEMANAL', 'domingo_base' => '2026-10-04', 'fecha_corte' => '2026-10-11', 'inicio_ingresos' => '2026-10-05', 'fin_ingresos' => '2026-10-11']];
    $book = xv4Exportar($vacio);

    expect($book->getSheetByName('EFECTIVIDAD')->getCell('A6')->getValue())->toBe('SIN MOVIMIENTOS PARA EL PERIODO')
        ->and($book->getSheetByName('EFECTIVIDAD')->getCell('B6')->getValue())->toBeNull()
        ->and($book->getSheetByName('_AUDITORIA_EFECTIVIDAD')->getCell('A6')->getValue())->toBe('SIN INCIDENCIAS');
});

it('hoja FUENTES: motor, modo, fechas y fotografías usadas', function () {
    $fuentes = ['fotos' => [['nombre' => 'CARTERA DOMINGO', 'fecha' => '2026-10-04', 'filas' => 4], ['nombre' => 'CARTERA LUNES', 'fecha' => '2026-10-05', 'filas' => 5]],
        'ingresos_corte' => 12, 'ingresos_historial' => 20, 'fechas_con_cargas_solapadas' => ['2026-10-06' => 2]];
    $ws = xv4Exportar(xv4Escenario(), $fuentes)->getSheetByName('FUENTES');

    expect($ws->getCell('B3')->getValue())->toBe('mrlana-v4-2026-10')
        ->and($ws->getCell('B4')->getValue())->toBe('SEMANAL')
        ->and($ws->getCell('B5')->getValue())->toBe('04/10/2026')
        ->and($ws->getCell('B6')->getValue())->toBe('11/10/2026')
        ->and($ws->getCell('A9')->getValue())->toBe('CARTERA DOMINGO')
        ->and($ws->getCell('C10')->getValue())->toEqual(5)
        ->and($ws->getCell('B12')->getValue())->toEqual(12)
        ->and($ws->getCell('B14')->getValue())->toEqual(1);
});
