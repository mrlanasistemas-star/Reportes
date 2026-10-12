<?php

use App\Enums\ReportUploadStatus;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\Period;
use App\Models\Portfolio;
use App\Models\Recovery;
use App\Models\ReportUpload;
use App\Services\Efectividad\EfectividadCobranzaAprobadaReportService;
use App\Services\Imports\LendusIngresosCobranzaImportService;
use App\Services\ReportUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

uses(RefreshDatabase::class);

/**
 * Auditoría 2026-10-12 (Fase 7/8) — confirma, con pruebas reales, dos
 * preocupaciones explícitas del encargo:
 *
 *  - Fase 7: reimportar/reemplazar el Excel de Ingresos Cobranza del MISMO
 *    periodo NUNCA duplica movimientos en fact_recoveries — comportamiento
 *    YA EXISTENTE en ReportUploadService::store() + PeriodDerivedDataCleaner
 *    ::clearForUpload() (marca el upload viejo como Replaced y borra sus
 *    filas fact_* ANTES de que el nuevo se procese). No se tocó ese código:
 *    esta prueba solo lo certifica end-to-end para el flujo real de subida.
 *  - Fase 8: un periodo COMPUESTO (bimestral/trimestral/semestral/anual)
 *    nunca tiene su propia fila en fact_portfolios (solo los periodos
 *    `monthly` reciben Saldos por Cliente directamente) — el adaptador debe
 *    resolver CARTERA desde el ÚLTIMO mes componente, igual que ya hace el
 *    resto de Reportería (`Period::resolveLastMonthlyComponent()`), nunca
 *    reportar `sin_cartera` cuando SÍ existe un Saldos real para ese periodo.
 */
function makeIngresosFixtureFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $headers = ['Promotor', 'Contrato', 'Producto de crédito', 'Operación', 'Concepto', 'Transacción', 'Capital', 'Interés', 'Impuesto', 'Cargos calendario', 'Cargos vencimiento', 'Excedente', 'Total', 'Dias vencidos pago', 'Fecha transacción', 'Fecha cuota'];
    foreach ($headers as $i => $h) {
        $sheet->setCellValueByColumnAndRow($i + 1, 1, $h);
    }
    foreach ($rows as $r => $data) {
        foreach ($data as $c => $value) {
            $sheet->setCellValueByColumnAndRow($c + 1, $r + 2, $value);
        }
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'ingresos_fixture_') . '.xlsx';
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($tmpPath);

    return new UploadedFile($tmpPath, 'Ingresos cobranza.xlsx', 'application/vnd.ms-excel', null, true);
}

it('Fase 7: replacing the Ingresos Cobranza upload for the SAME period never duplicates fact_recoveries rows', function () {
    $period = Period::query()->create([
        'name' => 'Periodo Dedup Test', 'code' => 'M-DEDUP-2026-9', 'type' => 'monthly',
        'year' => 2026, 'month' => 9, 'sequence' => 1,
        'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'is_closed' => false,
    ]);
    $source = DataSource::query()->firstOrCreate(
        ['code' => 'lendus_ingresos_cobranza'],
        ['name' => 'lendus_ingresos_cobranza', 'description' => 'x', 'is_active' => true],
    );

    $firstFile = makeIngresosFixtureFile([
        ['ORI09247', '25COR1270278', 'CRECE', 'PAGO A CONTRATO', 'PRINCIPAL', 'PAGO', 100, 0, 0, 0, 0, 0, 100, 0, '2026-09-05', '2026-09-05'],
    ]);

    $uploadService = app(ReportUploadService::class);
    $importer = app(LendusIngresosCobranzaImportService::class);

    $firstUpload = $uploadService->store($period->id, [], $source->id, $firstFile, null);
    $importer->handle($firstUpload->fresh());

    expect(Recovery::query()->where('period_id', $period->id)->count())->toBe(1);

    // El usuario detecta un error y vuelve a subir un Excel CORREGIDO para el MISMO
    // periodo/fuente — esto es un reemplazo real, no un reprocesamiento del mismo upload.
    $secondFile = makeIngresosFixtureFile([
        ['ORI09247', '25COR1270278', 'CRECE', 'PAGO A CONTRATO', 'PRINCIPAL', 'PAGO', 150, 0, 0, 0, 0, 0, 150, 0, '2026-09-05', '2026-09-05'],
    ]);
    $secondUpload = $uploadService->store($period->id, [], $source->id, $secondFile, null);
    $importer->handle($secondUpload->fresh());

    // La fila del upload viejo YA fue borrada por ReportUploadService::store() antes de
    // que este segundo import corriera — nunca debe quedar una fila "fantasma" de $100
    // junto a la nueva de $150.
    expect(Recovery::query()->where('period_id', $period->id)->count())->toBe(1);
    expect((float) Recovery::query()->where('period_id', $period->id)->sum('total_amount'))->toBe(150.0);

    $firstUpload->refresh();
    expect($firstUpload->status)->toBe(ReportUploadStatus::Replaced);
});

it('Fase 8: a compound (bimonthly) period resolves CARTERA from its last monthly component, never reporting sin_cartera when Saldos data really exists', function () {
    $monthA = Period::query()->create([
        'name' => 'Mes A', 'code' => 'M-COMPOUND-A', 'type' => 'monthly',
        'year' => 2026, 'month' => 8, 'sequence' => 1,
        'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'is_closed' => true,
    ]);
    $monthB = Period::query()->create([
        'name' => 'Mes B (más reciente)', 'code' => 'M-COMPOUND-B', 'type' => 'monthly',
        'year' => 2026, 'month' => 9, 'sequence' => 1,
        'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'is_closed' => true,
    ]);
    $bimestre = Period::query()->create([
        'name' => 'Bimestre Ago-Sep 2026', 'code' => 'B-COMPOUND-2026', 'type' => 'bimonthly',
        'year' => 2026, 'month' => 9, 'sequence' => 1,
        'start_date' => '2026-08-01', 'end_date' => '2026-09-30', 'is_closed' => false,
        'component_period_ids' => [$monthA->id, $monthB->id],
    ]);

    $branch = Branch::query()->create(['code' => 'COR', 'name' => 'CORDOBA', 'normalized_name' => 'cordoba', 'is_active' => true]);

    // Saldos por Cliente SOLO existe bajo el mes B (el de cierre más reciente) — igual
    // que en producción real, nunca bajo el bimestre compuesto.
    Portfolio::query()->create([
        'period_id' => $monthB->id, 'branch_id' => $branch->id,
        'contract' => '25COR1270278', 'promoter_name' => 'LUIS GOMEZ', 'route_name' => 'CORDOBA',
        'status' => 'VIGENTE', 'substatus' => 'VIGENTE',
        'installment_amount' => 100, 'periodicity' => 'SEMANAL', 'num_payments' => 10,
        'first_payment_date' => '2026-09-05',
    ]);
    Recovery::query()->create([
        'period_id' => $monthB->id, 'branch_id' => $branch->id,
        'contract' => '25COR1270278', 'promoter_name' => 'LUIS GOMEZ', 'office_name_raw' => 'CORDOBA',
        'operation' => 'PAGO A CONTRATO', 'transaction' => 'PAGO',
        'capital' => 100, 'interest' => 0, 'tax' => 0, 'total_amount' => 100,
        'payment_date' => '2026-09-05', 'installment_due_date' => '2026-09-05',
    ]);

    $result = app(EfectividadCobranzaAprobadaReportService::class)->generate($bimestre, $branch->id);

    expect($result['coverage']['status'])->toBe('ok');
    expect($result['coverage']['cartera_period_id'])->toBe($monthB->id);
    expect($result['is_available'])->toBeTrue();

    $granTotal = collect($result['rows'])->firstWhere('promotor', 'GRAN TOTAL');
    expect((float) $granTotal['sano_cob'])->toBe(100.0);
});

it('Fase 8: a weekly (base) period honestly reports sin_cartera instead of inventing a monthly snapshot to borrow from', function () {
    $weekly = Period::query()->create([
        'name' => 'Semana suelta', 'code' => 'W-NOCARTERA-2026', 'type' => 'weekly',
        'year' => 2026, 'month' => 9, 'sequence' => 1,
        'start_date' => '2026-09-01', 'end_date' => '2026-09-07', 'is_closed' => false,
    ]);

    $result = app(EfectividadCobranzaAprobadaReportService::class)->generate($weekly, null);

    expect($result['coverage']['status'])->toBe('sin_datos');
    expect($result['coverage']['cartera_period_id'])->toBeNull();
    expect($result['is_available'])->toBeFalse();
});
