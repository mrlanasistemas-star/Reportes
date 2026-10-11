<?php

use App\Services\Efectividad\EfectividadCobranzaAprobadaCalculator;
use App\Services\Efectividad\EfectividadCobranzaAprobadaExcelExporter;
use PhpOffice\PhpSpreadsheet\IOFactory;

it('exports a valid, readable XLSX matching the real approved layout (columns A-M, headers, semaphore)', function () {
    $cartera = [[
        'oficina' => 'TULA', 'promotor' => 'JUAN PEREZ', 'contrato' => 'C-1',
        'estatus' => 'ACTIVO', 'substatus' => '', 'fecha_vencimiento' => '2026-12-31',
        'cuota' => 1000.0, 'periodicidad' => 'SEMANAL', 'pagos' => 1, 'fecha_primer_pago' => '2026-04-02',
    ]];
    $ingresos = [[
        'oficina' => 'TULA', 'promotor' => 'JUAN PEREZ', 'contrato' => 'C-1',
        'operacion' => 'PAGO A CONTRATO', 'transaccion' => 'PAGO',
        'fecha_transaccion' => '2026-04-02', 'fecha_cuota' => '2026-04-02',
        'capital' => 1000.0, 'interes' => 0.0, 'impuesto' => 0.0,
    ]];

    $filas = (new EfectividadCobranzaAprobadaCalculator())->calculate(
        $cartera, $ingresos, new DateTimeImmutable('2026-03-30'), new DateTimeImmutable('2026-04-05')
    );

    $path = sys_get_temp_dir() . '/efectividad_test_' . uniqid() . '.xlsx';
    (new EfectividadCobranzaAprobadaExcelExporter())->export($filas, 'Semana del 30/03/2026 al 05/04/2026', $path);

    expect(file_exists($path))->toBeTrue();
    expect(filesize($path))->toBeGreaterThan(0);

    $spreadsheet = IOFactory::load($path);
    $sheet = $spreadsheet->getActiveSheet();

    expect($sheet->getTitle())->toBe('EFECTIVIDAD');
    expect($sheet->getCell('A1')->getValue())->toContain('EFECTIVIDAD DE COBRANZA');
    expect($sheet->getCell('B4')->getValue())->toBe('SANO');
    expect($sheet->getCell('A5')->getValue())->toBe('Promotor');

    // Fila 6 = encabezado de oficina "TULA" (fusionada), fila 7 = el promotor con datos.
    expect($sheet->getCell('A6')->getValue())->toBe('TULA');
    expect($sheet->getCell('A7')->getValue())->toBe('JUAN PEREZ');
    expect((float) $sheet->getCell('B7')->getValue())->toBe(1000.0);
    expect((float) $sheet->getCell('C7')->getValue())->toBe(1000.0);

    @unlink($path);
});
