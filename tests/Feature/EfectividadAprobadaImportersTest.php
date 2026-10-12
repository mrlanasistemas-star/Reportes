<?php

use App\Enums\ReportUploadStatus;
use App\Models\DataSource;
use App\Models\Period;
use App\Models\Portfolio;
use App\Models\Recovery;
use App\Models\ReportUpload;
use App\Services\Imports\LendusIngresosCobranzaImportService;
use App\Services\Imports\LendusSaldosClienteImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

uses(RefreshDatabase::class);

/**
 * Fase 5 (Efectividad de Cobranza Aprobada) — confirma que los importadores
 * YA ACTUALIZADOS conservan las columnas nuevas (installment_due_date,
 * installment_number, contract_due_date, acreditado, office_name_raw para
 * Ingresos Cobranza; status/substatus/fechas/saldo_*_vencido/total_*_raw
 * para Saldos por Cliente) sin romper ni un campo histórico ya existente.
 */
function makeEfectAprobadaPeriodo(): Period
{
    return Period::query()->create([
        'name' => 'Periodo EfectAprobada', 'code' => 'M-EFECTAPR-2026-9', 'type' => 'monthly',
        'year' => 2026, 'month' => 9, 'sequence' => 1,
        'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'is_closed' => false,
    ]);
}

function writeXlsxFixture(array $headers, array $rows, string $prefix): string
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    foreach ($headers as $i => $h) {
        $sheet->setCellValueByColumnAndRow($i + 1, 1, $h);
    }
    foreach ($rows as $r => $data) {
        foreach ($data as $c => $value) {
            $sheet->setCellValueByColumnAndRow($c + 1, $r + 2, $value);
        }
    }

    $relativePath = "report_uploads/{$prefix}_" . uniqid() . '.xlsx';
    $absolutePath = Storage::disk('public')->path($relativePath);
    @mkdir(dirname($absolutePath), 0777, true);
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($absolutePath);

    return $relativePath;
}

function makeUploadFor(Period $period, string $sourceCode, string $relativePath, string $originalName): ReportUpload
{
    $absolutePath = Storage::disk('public')->path($relativePath);
    $source = DataSource::query()->firstOrCreate(
        ['code' => $sourceCode],
        ['name' => $sourceCode, 'description' => $sourceCode, 'is_active' => true],
    );

    return ReportUpload::query()->create([
        'period_id' => $period->id, 'data_source_id' => $source->id,
        'original_name' => $originalName, 'stored_path' => $relativePath,
        'mime_type' => 'application/vnd.ms-excel', 'file_size' => filesize($absolutePath), 'uploaded_at' => now(),
        'status' => ReportUploadStatus::Processed, 'notes' => null,
    ]);
}

it('Ingresos Cobranza: preserves fecha transaccion vs fecha cuota as DISTINCT columns and persists the new traceability fields', function () {
    $period = makeEfectAprobadaPeriodo();

    // Mismo layout real confirmado (14 cols) + columnas nuevas cuando el Excel SÍ las trae.
    $headers = [
        'Promotor', 'Contrato', 'Producto de crédito', 'Operación', 'Concepto', 'Transacción',
        'Capital', 'Interés', 'Impuesto', 'Cargos calendario', 'Cargos vencimiento', 'Excedente', 'Total',
        'Dias vencidos pago', 'Fecha transacción', 'Fecha cuota', 'Numero cuota', 'Fecha vencimiento', 'Acreditado',
    ];
    $rows = [
        [
            'ORI09247', '25COR1270278', 'CRECE', 'PAGO A CONTRATO', 'PRINCIPAL', 'PAGO',
            100, 20, 5, 0, 0, 0, 125,
            3, '2026-09-10', '2026-09-07', '14', '2027-01-01', 'JUAN PEREZ LOPEZ',
        ],
    ];

    $relativePath = writeXlsxFixture($headers, $rows, 'cobranza_efectaprobada');
    $upload = makeUploadFor($period, 'lendus_ingresos_cobranza', $relativePath, 'Cobranza.xlsx');

    app(LendusIngresosCobranzaImportService::class)->handle($upload);

    $recovery = Recovery::query()->where('report_upload_id', $upload->id)->first();

    expect($recovery)->not->toBeNull();
    // payment_date SIGUE siendo Fecha transacción — comportamiento histórico intacto.
    expect($recovery->payment_date->format('Y-m-d'))->toBe('2026-09-10');
    // installment_due_date es la columna NUEVA — Fecha cuota, nunca confundida con payment_date.
    expect($recovery->installment_due_date->format('Y-m-d'))->toBe('2026-09-07');
    expect($recovery->installment_number)->toBe('14');
    expect($recovery->contract_due_date->format('Y-m-d'))->toBe('2027-01-01');
    expect($recovery->acreditado)->toBe('JUAN PEREZ LOPEZ');
    expect((float) $recovery->capital)->toBe(100.0);
});

it('Ingresos Cobranza: when the real 14-column file has no Fecha cuota, installment_due_date stays null instead of being invented', function () {
    $period = makeEfectAprobadaPeriodo();

    // EXACTO layout real confirmado por inspección directa — 14 columnas, sin Fecha cuota.
    $headers = [
        'Promotor', 'Contrato', 'Producto de crédito', 'Operación', 'Concepto', 'Transacción',
        'Capital', 'Interés', 'Impuesto', 'Cargos calendario', 'Cargos vencimiento', 'Excedente', 'Total',
        'Dias vencidos pago',
    ];
    $rows = [
        ['ORI09247', '25COR1270278', 'CRECE', 'PAGO A CONTRATO', 'PRINCIPAL', 'PAGO', 100, 20, 5, 0, 0, 0, 125, 3],
    ];

    $relativePath = writeXlsxFixture($headers, $rows, 'cobranza_real_sin_fechas');
    $upload = makeUploadFor($period, 'lendus_ingresos_cobranza', $relativePath, 'Cobranza.xlsx');

    app(LendusIngresosCobranzaImportService::class)->handle($upload);

    $recovery = Recovery::query()->where('report_upload_id', $upload->id)->first();

    expect($recovery)->not->toBeNull();
    expect($recovery->installment_due_date)->toBeNull();
    expect($recovery->payment_date)->toBeNull();
    expect((float) $recovery->total_amount)->toBe(125.0);
});

it('Saldos por Cliente: "Saldo capital" keeps feeding the historical balance field AND is copied into saldo_capital_vencido without a second alias collision', function () {
    $period = makeEfectAprobadaPeriodo();

    $headers = [
        'Nombre promotor', 'Contrato', 'Producto de crédito', 'Dias Vencido',
        'Saldo capital', 'Capital', 'Capital atrasado',
        'Saldo interés', 'Saldo impuesto', 'Saldo cargos calendario', 'Saldo impuesto cargos calendario',
        'Estatus', 'Substatus', 'Fecha primer pago', 'Fecha siguiente pago', 'Fecha vencimiento',
        'Cuota total', 'Pagos atrasados', 'Total atrasado', 'Total pendiente', 'Pagos', 'Periodicidad',
        'Nombre del cliente',
    ];
    $rows = [
        [
            'LUIS GOMEZ', '25COR1270278', 'CRECE', 10,
            5000, 4800, 300,
            150, 30, 10, 2,
            'VIGENTE', 'AL DIA', '2026-01-05', '2026-10-05', '2027-01-01',
            250, 2, 500, 480, 40, 'SEMANAL',
            'MARIA LOPEZ',
        ],
    ];

    $relativePath = writeXlsxFixture($headers, $rows, 'saldos_efectaprobada');
    $upload = makeUploadFor($period, 'lendus_saldos_cliente', $relativePath, 'Saldos.xlsx');

    app(LendusSaldosClienteImportService::class)->handle($upload);

    $portfolio = Portfolio::query()->where('report_upload_id', $upload->id)->first();

    expect($portfolio)->not->toBeNull();
    // Comportamiento histórico intacto: balance sigue viniendo de "Saldo capital".
    expect((float) $portfolio->balance)->toBe(5000.0);
    // Capital atrasado (mora histórica) sigue siendo una columna DISTINTA de "Saldo capital".
    expect((float) $portfolio->capital_due)->toBe(300.0);
    // Nuevo: saldo_capital_vencido refleja el mismo "Saldo capital" sin pisar el alias de balance.
    expect((float) $portfolio->saldo_capital_vencido)->toBe(5000.0);
    expect((float) $portfolio->saldo_interes_vencido)->toBe(150.0);
    expect((float) $portfolio->saldo_impuesto_vencido)->toBe(30.0);
    expect((float) $portfolio->saldo_cargo_calendario_vencido)->toBe(10.0);
    expect((float) $portfolio->saldo_impuesto_cargo_calendario_vencido)->toBe(2.0);
    expect($portfolio->status)->toBe('VIGENTE');
    expect($portfolio->substatus)->toBe('AL DIA');
    expect($portfolio->first_payment_date->format('Y-m-d'))->toBe('2026-01-05');
    expect($portfolio->next_payment_date->format('Y-m-d'))->toBe('2026-10-05');
    expect($portfolio->contract_due_date->format('Y-m-d'))->toBe('2027-01-01');
    expect((float) $portfolio->installment_amount)->toBe(250.0);
    expect($portfolio->overdue_installments)->toBe(2);
    expect((float) $portfolio->total_overdue_raw)->toBe(500.0);
    expect((float) $portfolio->total_pending_raw)->toBe(480.0);
    // num_payments/periodicity ya existían en el esquema pero nunca se mapeaban — ahora sí.
    expect($portfolio->num_payments)->toBe(40);
    expect($portfolio->periodicity)->toBe('SEMANAL');
});

it('Saldos por Cliente: falls back to "Cliente" (client_code) as contract when there is no literal "Contrato" column', function () {
    $period = makeEfectAprobadaPeriodo();

    // Layout real confirmado: sin columna "Contrato" literal, solo "Cliente".
    $headers = ['Cliente', 'Nombre promotor', 'Producto de crédito', 'Dias Vencido', 'Saldo capital'];
    $rows = [
        ['25COR1270278', 'LUIS GOMEZ', 'CRECE', 10, 5000],
    ];

    $relativePath = writeXlsxFixture($headers, $rows, 'saldos_sin_contrato');
    $upload = makeUploadFor($period, 'lendus_saldos_cliente', $relativePath, 'Saldos.xlsx');

    app(LendusSaldosClienteImportService::class)->handle($upload);

    $portfolio = Portfolio::query()->where('report_upload_id', $upload->id)->first();

    expect($portfolio)->not->toBeNull();
    expect($portfolio->contract)->toBe('25COR1270278');
});
