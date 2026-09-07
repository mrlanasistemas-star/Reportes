<?php

use App\Enums\ReportUploadStatus;
use App\Models\DataSource;
use App\Models\Period;
use App\Models\PeriodBranchSummary;
use App\Models\PeriodIncident;
use App\Models\PeriodSummary;
use App\Models\ReportUpload;
use App\Services\Imports\GastosLendusExcelImportService;
use App\Services\PeriodRadiographyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

uses(RefreshDatabase::class);

/**
 * Auditoría 07-sep-2026 (frente 1) — reintento debe ser IDEMPOTENTE: reprocesar
 * el mismo upload / regenerar el mismo periodo dos o tres veces NUNCA debe
 * duplicar gastos ni summaries. Verificación explícita del patrón "delete +
 * insert" scoped por report_upload_id (importadores) y "updateOrCreate/delete +
 * recreate" scoped por period_summary_id (PeriodRadiographyService::generate())
 * que YA existen en el código — este test los confirma, no los reescribe.
 */
function makeIdempotencyPeriodo(): Period
{
    return Period::query()->create([
        'name' => 'Periodo idempotencia', 'code' => 'M-IDEMP-2026-8', 'type' => 'monthly',
        'year' => 2026, 'month' => 8, 'sequence' => 1,
        'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'is_closed' => false,
    ]);
}

function buildMinimalGastosLendusUpload(Period $period): ReportUpload
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $headers = ['Empleado', 'Categoria', 'Concepto', 'Estatus', 'Fecha Creacion', 'Monto Gasto'];
    foreach ($headers as $i => $h) {
        $sheet->setCellValueByColumnAndRow($i + 1, 1, $h);
    }
    $rows = [
        ['ANA LOPEZ GARCIA', 'Recargas Telefónicas', 'RECARGAS TELEFONICAS', 'Aprobado', '01/08/2026', 200],
        ['LUIS PEREZ TORRES', 'Recargas Telefónicas', 'RECARGAS TELEFONICAS', 'Aprobado', '01/08/2026', 150],
        ['MARIA RUIZ SANCHEZ', 'Recargas Telefónicas', 'RECARGAS TELEFONICAS', 'Aprobado', '01/08/2026', 75],
    ];
    foreach ($rows as $r => $data) {
        foreach ($data as $c => $value) {
            $sheet->setCellValueByColumnAndRow($c + 1, $r + 2, $value);
        }
    }

    $relativePath = 'report_uploads/idempotencia_gastos_' . uniqid() . '.xlsx';
    $absolutePath = Storage::disk('public')->path($relativePath);
    @mkdir(dirname($absolutePath), 0777, true);
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($absolutePath);

    $source = DataSource::query()->firstOrCreate(
        ['code' => 'gastos_lendus_excel'],
        ['name' => 'gastos_lendus_excel', 'description' => 'gastos_lendus_excel', 'is_active' => true],
    );

    return ReportUpload::query()->create([
        'period_id' => $period->id, 'data_source_id' => $source->id,
        'original_name' => 'Gastos.xlsx', 'stored_path' => $relativePath,
        'mime_type' => 'application/vnd.ms-excel', 'file_size' => filesize($absolutePath), 'uploaded_at' => now(),
        'status' => ReportUploadStatus::Processed, 'notes' => null,
    ]);
}

it('running the Gastos Lendus Excel import twice (retry) never duplicates rows or amounts', function () {
    $period = makeIdempotencyPeriodo();
    $upload = buildMinimalGastosLendusUpload($period);

    $service = app(GastosLendusExcelImportService::class);

    $first  = $service->handle($upload);
    $countAfterFirst = DB::table('fact_expenses')->where('report_upload_id', $upload->id)->count();
    $sumAfterFirst   = (float) DB::table('fact_expenses')->where('report_upload_id', $upload->id)->sum('amount');

    // Reintento — mismo upload, mismo archivo, sin volver a subir nada.
    $second = $service->handle($upload);
    $countAfterSecond = DB::table('fact_expenses')->where('report_upload_id', $upload->id)->count();
    $sumAfterSecond   = (float) DB::table('fact_expenses')->where('report_upload_id', $upload->id)->sum('amount');

    // Un tercer reintento por si acaso.
    $service->handle($upload);
    $countAfterThird = DB::table('fact_expenses')->where('report_upload_id', $upload->id)->count();
    $sumAfterThird   = (float) DB::table('fact_expenses')->where('report_upload_id', $upload->id)->sum('amount');

    expect($first['rows_inserted'])->toBe(3);
    expect($countAfterFirst)->toBe(3);
    expect($sumAfterFirst)->toBe(425.0);

    expect($countAfterSecond)->toBe($countAfterFirst);
    expect($sumAfterSecond)->toBe($sumAfterFirst);
    expect($countAfterThird)->toBe($countAfterFirst);
    expect($sumAfterThird)->toBe($sumAfterFirst);
});

it('regenerating the same period summary twice (retry) never duplicates PeriodSummary/PeriodBranchSummary/PeriodIncident rows', function () {
    $period = makeIdempotencyPeriodo();

    $service = app(PeriodRadiographyService::class);

    $summary1 = $service->generate($period, null, []);
    $summaryCountAfter1 = PeriodSummary::query()->where('period_id', $period->id)->count();
    $branchSummaryCountAfter1 = PeriodBranchSummary::query()->where('period_summary_id', $summary1->id)->count();
    $incidentCountAfter1 = PeriodIncident::query()->where('period_summary_id', $summary1->id)->count();

    // Reintento — mismo periodo, sin volver a importar nada.
    $summary2 = $service->generate($period, null, []);
    $summaryCountAfter2 = PeriodSummary::query()->where('period_id', $period->id)->count();

    // Reintento 2.
    $summary3 = $service->generate($period, null, []);
    $summaryCountAfter3 = PeriodSummary::query()->where('period_id', $period->id)->count();

    // Nunca más de UN PeriodSummary por periodo (unique('period_id') + updateOrCreate).
    expect($summaryCountAfter1)->toBe(1);
    expect($summaryCountAfter2)->toBe(1);
    expect($summaryCountAfter3)->toBe(1);
    expect($summary1->id)->toBe($summary2->id);
    expect($summary2->id)->toBe($summary3->id);

    // PeriodBranchSummary/PeriodIncident se borran y recrean (delete+insert) por
    // period_summary_id — nunca se acumulan entre corridas.
    $branchSummaryCountAfter3 = PeriodBranchSummary::query()->where('period_summary_id', $summary3->id)->count();
    $incidentCountAfter3 = PeriodIncident::query()->where('period_summary_id', $summary3->id)->count();
    expect($branchSummaryCountAfter3)->toBe($branchSummaryCountAfter1);
    expect($incidentCountAfter3)->toBe($incidentCountAfter1);
});
