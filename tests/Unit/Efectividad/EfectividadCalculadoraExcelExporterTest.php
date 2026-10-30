<?php

use App\Services\Efectividad\Calculadora\EfectividadCalculadoraExcelExporter;
use App\Services\Efectividad\V4\EfectividadV4ExcelExporter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

uses(TestCase::class);

/**
 * El Excel de la calculadora sigue la plantilla del PDF/imagen: se genera el archivo,
 * se ABRE con PhpSpreadsheet y se validan logo, encabezado, KPI, bandas y colores.
 */
function xcFila(string $tipo, string $promotor, array $o = []): array
{
    return $o + [
        'tipo' => $tipo, 'promotor' => $promotor, 'oficina' => null, 'oficina_titulo' => null, 'sucursal' => null,
        'sano_pc' => 100.0, 'sano_cob' => 80.0, 'sano_pct' => 0.8, 'atraso_pc' => 50.0, 'atraso_cob' => 10.0, 'atraso_pct' => 0.2,
        'vencido_pc' => 40.0, 'vencido_cob' => 5.0, 'vencido_pct' => 0.125, 'cobranza_total' => 95.0, 'pct_total' => 0.95, 'mora' => 20.0,
    ];
}

function xcResultado(array $o = []): array
{
    $ruta = ['oficina' => 'RUTA A', 'oficina_titulo' => '=RUTA A', 'sucursal' => 'ACAMBAY'];

    return $o + [
        'titulo' => 'CORTE SEMANAL: 05/10/2026 AL 11/10/2026', 'modo' => 'SEMANAL', 'leyenda' => 'Leyenda', 'metodologia' => 'V7',
        'parametros' => ['modo' => 'SEMANAL', 'domingo_base' => '2026-10-04', 'fecha_corte' => '2026-10-11', 'inicio_ingresos' => '2026-10-05', 'fin_ingresos' => '2026-10-11'],
        'filas' => [
            xcFila('promotor', '+ANA', $ruta + ['pct_total' => 0.91]),
            xcFila('promotor', 'BETO', $ruta + ['pct_total' => 0.5, 'mora' => -3.0, 'cobranza_total' => -1.0]),
            xcFila('subtotal', 'TOTAL RUTA A'),
            xcFila('subtotal_sucursal', 'TOTAL SUCURSAL ACAMBAY'),
            xcFila('gran_total', 'GRAN TOTAL', ['cobranza_total' => 1234.5, 'mora' => 20.0]),
        ],
        'incidencias' => [['id' => 'I1', 'descripcion' => 'Pago duplicado', 'codigo' => 'DUP', 'contrato' => 'C1', 'fecha' => '2026-10-07', 'importe' => 30.0]],
        'notas' => ['Nota uno.'], 'avisos' => ['Falta una foto.'], 'generado' => '30/10/2026 10:00',
    ];
}

function xcExportar(array $r): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'efcalc').'.xlsx';
    (new EfectividadCalculadoraExcelExporter(new EfectividadV4ExcelExporter))->export($r, $path);
    $book = IOFactory::load($path);
    @unlink($path);

    return $book;
}

/** Fila cuya columna A contiene el texto (incluye celdas RichText). */
function xcFilaDe($ws, string $texto): int
{
    foreach (range(1, $ws->getHighestDataRow('A')) as $r) {
        if (str_contains((string) $ws->getCell("A{$r}")->getValue(), $texto)) {
            return $r;
        }
    }
    throw new RuntimeException("No se encontró: {$texto}");
}

it('incluye el logo de MR LANA en la esquina superior izquierda sin deformarlo', function () {
    $ws = xcExportar(xcResultado())->getSheetByName('EFECTIVIDAD');
    $logo = $ws->getDrawingCollection()[0];

    expect($ws->getDrawingCollection())->toHaveCount(1)
        ->and($logo->getCoordinates())->toBe('A1')
        ->and($logo->getHeight())->toBe(72)
        ->and($logo->getWidth() / $logo->getHeight())->toEqualWithDelta(508 / 192, 0.06);
});

it('tiene encabezado, KPI y grupos de columnas del PDF, con un filtro en el alcance', function () {
    $ws = xcExportar(xcResultado(['filtro' => ['oficina' => 'RUTA A']]))->getSheetByName('EFECTIVIDAD');

    expect($ws->getCell('B2')->getValue())->toBe('Efectividad de Cobranza')
        ->and((string) $ws->getCell('B3')->getValue())->toContain('CORTE SEMANAL')->toContain('Metodología V7')
        ->and((string) $ws->getCell('I1')->getValue())->toBe('Modalidad: Semanal')
        ->and((string) $ws->getCell('I2')->getValue())->toBe('Periodo de ingresos: 05/10/2026 al 11/10/2026')
        ->and((string) $ws->getCell('I3')->getValue())->toBe('Fecha de corte: 11/10/2026 · Domingo base: 04/10/2026')
        ->and((string) $ws->getCell('I4')->getValue())->toBe('Sucursal: ACAMBAY · Ruta: RUTA A')
        ->and($ws->getCell('A6')->getValue())->toBe('SANO POR COBRAR')
        ->and($ws->getCell('H7')->getValue())->toEqual(1234.5)
        ->and($ws->getCell('A8')->getValue())->toBe('Ruta / Promotor')
        ->and($ws->getCell('E8')->getValue())->toBe('Vigente con atraso')
        ->and($ws->getCell('M9')->getValue())->toBe('Mora')
        ->and($ws->getStyle('B8')->getFill()->getStartColor()->getRGB())->toBe('059669')
        ->and($ws->getStyle('E8')->getFill()->getStartColor()->getRGB())->toBe('FBBF24')
        ->and($ws->getStyle('H8')->getFill()->getStartColor()->getRGB())->toBe('DC2626')
        ->and($ws->getFreezePane())->toBe('A10');
});

it('pinta bandas de sucursal y ruta, totales y semáforo como el PDF, sin alterar los importes', function () {
    $r = xcResultado();
    $ws = xcExportar($r)->getSheetByName('EFECTIVIDAD');
    $color = fn (string $c) => $ws->getStyle($c)->getFill()->getStartColor()->getRGB();

    $sucursal = xcFilaDe($ws, 'SUCURSAL ACAMBAY');
    $ruta = xcFilaDe($ws, 'Ruta · =RUTA A');
    $ana = xcFilaDe($ws, '+ANA');
    $beto = xcFilaDe($ws, 'BETO');
    $gran = xcFilaDe($ws, 'GRAN TOTAL');

    expect([$sucursal, $ruta, $ana])->toBe([10, 11, 12])
        ->and($color("A{$sucursal}"))->toBe('1E293B')
        ->and($color("A{$ruta}"))->toBe('F1F5F9')
        ->and($color('A'.xcFilaDe($ws, 'TOTAL RUTA A')))->toBe('E2E8F0')
        ->and($color('A'.xcFilaDe($ws, 'TOTAL SUCURSAL')))->toBe('CBD5E1')
        ->and($color("A{$gran}"))->toBe('0F766E')
        ->and($ws->getCell("A{$ana}")->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($ws->getCell("K{$gran}")->getValue())->toEqual(1234.5)
        ->and($ws->getStyle("L{$ana}")->getFont()->getColor()->getRGB())->toBe('B45309')
        ->and($ws->getStyle("L{$beto}")->getFont()->getColor()->getRGB())->toBe('B91C1C')
        ->and($ws->getStyle("M{$beto}")->getFont()->getColor()->getRGB())->toBe('047857')
        ->and($ws->getStyle("K{$beto}")->getFont()->getColor()->getRGB())->toBe('B91C1C')
        ->and($ws->getStyle("L{$gran}")->getFont()->getColor()->getRGB())->toBe('FFFFFF')
        ->and($ws->getStyle("B{$ana}")->getNumberFormat()->getFormatCode())->toBe('$#,##0.00;-$#,##0.00')
        ->and($ws->getStyle("D{$ana}")->getNumberFormat()->getFormatCode())->toBe('0.00%');
});

it('agrega notas, control con avisos y la bitácora de incidencias en su propia hoja', function () {
    $book = xcExportar(xcResultado());
    $ws = $book->getSheetByName('EFECTIVIDAD');

    expect($book->getSheetNames())->toBe(['EFECTIVIDAD', '_AUDITORIA_EFECTIVIDAD'])
        ->and($book->getActiveSheet()->getTitle())->toBe('EFECTIVIDAD')
        ->and(xcFilaDe($ws, '• Nota uno.'))->toBeGreaterThan(xcFilaDe($ws, 'GRAN TOTAL'))
        ->and(xcFilaDe($ws, 'Incidencias de auditoría: 1'))->toBeGreaterThan(0)
        ->and(xcFilaDe($ws, 'DUP: 1'))->toBeGreaterThan(0)
        ->and(xcFilaDe($ws, '⚠ Falta una foto.'))->toBeGreaterThan(0)
        ->and($book->getSheetByName('_AUDITORIA_EFECTIVIDAD')->getCell('A6')->getValue())->toBe('I1');
});

it('sin movimientos conserva logo y encabezado con una leyenda explícita', function () {
    $ws = xcExportar(xcResultado(['filas' => [], 'incidencias' => [], 'avisos' => []]))->getSheetByName('EFECTIVIDAD');

    expect($ws->getDrawingCollection())->toHaveCount(1)
        ->and($ws->getCell('A10')->getValue())->toBe('Sin movimientos para el periodo')
        ->and((string) $ws->getCell('I4')->getValue())->toBe('Todas las sucursales');
});
