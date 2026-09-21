<?php

use App\Enums\ReportUploadStatus;
use App\Jobs\GenerateRadiographyJob;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\Expense;
use App\Models\PeriodIncident;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\ReportUpload;
use App\Services\AttributionRequirementService;
use App\Services\GastosExcelBranchResolverService;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Retoma 21-sep-2026, Parte A — cubre el bug real reportado: 99 gastos del Excel de
 * Lendus sin par (monto+fecha) en el PDF detenían TODA la generación, incluso un
 * comparativo GENERAL cuyo OPEX ni siquiera lee ese Excel. Ver AttributionRequirementService
 * y GenerateRadiographyJob::resolveGastosExcelNonBlocking().
 */
/**
 * RefreshDatabase no revierte de forma fiable la conexión mysql_testing entre tests
 * (verificado: UniqueConstraintViolationException en el segundo test de este archivo
 * al reusar (type,year,month,sequence)) — cada llamada usa un $sequence distinto para
 * no colisionar, sin depender de que la transacción de test se revierta.
 */
function makeAttributionPeriodo(string $code = 'M-2026-09', int $sequence = 1): \App\Models\Period
{
    return \App\Models\Period::query()->create([
        'name' => 'Septiembre 2026', 'code' => $code, 'type' => 'monthly',
        'year' => 2026, 'month' => 9, 'sequence' => $sequence,
        'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'is_closed' => false,
    ]);
}

function makeAttributionUpload(\App\Models\Period $period, string $sourceCode): ReportUpload
{
    $source = DataSource::query()->firstOrCreate(
        ['code' => $sourceCode],
        ['name' => $sourceCode, 'description' => $sourceCode, 'is_active' => true],
    );

    return ReportUpload::query()->create([
        'period_id' => $period->id, 'data_source_id' => $source->id,
        'original_name' => "{$sourceCode}.xlsx", 'stored_path' => "report_uploads/{$sourceCode}.xlsx",
        'mime_type' => 'application/vnd.ms-excel', 'file_size' => 10,
        'uploaded_at' => now(), 'status' => ReportUploadStatus::Processed, 'notes' => null,
    ]);
}

it('AttributionRequirementService never requires branch attribution today (general/branch/employee, any report_type)', function () {
    $service = app(AttributionRequirementService::class);

    expect($service->requiresBranchAttribution('simple', 'general'))->toBeFalse();
    expect($service->requiresBranchAttribution('month_vs_month', 'general'))->toBeFalse();
    expect($service->requiresBranchAttribution('simple', 'branch', 5))->toBeFalse();
    expect($service->requiresBranchAttribution('simple', 'employee', null, 17))->toBeFalse();
});

it('GENERAL OPEX (gastos_operativos) is IDENTICAL whether or not the Excel-Lendus row has a PDF match — proves the 99-row block never protected any real total', function () {
    // buildBranches() calcula colocación vía JSON_EXTRACT/JSON_UNQUOTE (fact_placements) —
    // funciones que SQLite no soporta. Bajo la suite por defecto (sqlite :memory:) se omite;
    // corre real contra mysql_testing (`php artisan test --configuration=phpunit.integration.xml`).
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requiere el motor MySQL/MariaDB real (JSON_EXTRACT) — corre con phpunit.integration.xml.');
    }

    $period = makeAttributionPeriodo('M-2026-09-A', 2);
    $branch = Branch::query()->create(['code' => 'CUER', 'name' => 'Cuernavaca', 'normalized_name' => 'cuernavaca', 'is_active' => true]);

    $pdfUpload = makeAttributionUpload($period, 'gastos_lendus');
    Expense::query()->create([
        'period_id' => $period->id, 'report_upload_id' => $pdfUpload->id,
        'category' => 'Transportes', 'concept' => 'GASTOS POR TRANSPORTE', 'amount' => 500.00,
        'expense_date' => '2026-09-10', 'branch_id' => $branch->id, 'employee_id' => null,
    ]);

    $calc = app(BranchRadiographyCalculator::class);
    $opexFor = function () use ($calc, $period) {
        $result = $calc->buildBranches($period, [$period->id]);

        return $calc->sumGlobal($result['branches'], $result['unassigned'])['gastos_operativos'];
    };
    $opexBefore = $opexFor();

    // Fila gemela del Excel SIN par exacto en el PDF (monto/fecha distintos a propósito)
    // — exactamente el escenario reportado (fact_expenses.id=29273, Transportes, $7.73).
    $excelUpload = makeAttributionUpload($period, 'gastos_lendus_excel');
    $unmatched = Expense::query()->create([
        'period_id' => $period->id, 'report_upload_id' => $excelUpload->id,
        'category' => 'Transportes', 'concept' => 'GASTOS POR TRANSPORTE', 'amount' => 7.73,
        'expense_date' => '2026-08-27', 'branch_id' => null, 'employee_id' => null,
        'observations' => 'SIN IDENTIDAD RESOLUBLE',
    ]);

    $opexAfter = $opexFor();

    expect($opexAfter)->toBe($opexBefore);

    // Confirma que el resolver SÍ lo marca 'sin_resolver' (no oculta el problema, solo no bloquea).
    $resolver = app(GastosExcelBranchResolverService::class);
    $results  = $resolver->resolveForPeriod($period, [$period->id]);
    $unresolvedRow = collect($results)->firstWhere('fact_expense_id', $unmatched->id);
    expect($unresolvedRow)->not->toBeNull();
    expect($unresolvedRow['estado'])->toBe('sin_resolver');
});

it('GenerateRadiographyJob::resolveGastosExcelNonBlocking() never throws for unresolved rows and records an informational PeriodIncident + run metadata', function () {
    $period = makeAttributionPeriodo('M-2026-09-B', 3);
    $excelUpload = makeAttributionUpload($period, 'gastos_lendus_excel');

    Expense::query()->create([
        'period_id' => $period->id, 'report_upload_id' => $excelUpload->id,
        'category' => 'Transportes', 'concept' => 'GASTOS POR TRANSPORTE', 'amount' => 7.73,
        'expense_date' => '2026-08-27', 'branch_id' => null, 'employee_id' => null,
        'observations' => 'SIN IDENTIDAD RESOLUBLE',
    ]);

    $summary = PeriodSummary::query()->create([
        'period_id' => $period->id, 'status' => 'generated', 'generated_at' => now(),
        'global_metrics' => [], 'warnings' => [], 'version' => 1,
    ]);

    $run = PeriodRadiographyRun::query()->create([
        'period_id' => $period->id, 'period_summary_id' => $summary->id,
        'report_type' => 'month_vs_month', 'scope' => 'general',
        'status' => 'running', 'started_at' => now(),
    ]);

    $job = new GenerateRadiographyJob($period->id, null, $run->id, ['report_type' => 'month_vs_month', 'scope' => 'general']);

    $method = new ReflectionMethod($job, 'resolveGastosExcelNonBlocking');
    $method->setAccessible(true);

    $thrown = null;
    try {
        $method->invoke(
            $job,
            app(GastosExcelBranchResolverService::class),
            app(AttributionRequirementService::class),
            $period,
            $summary,
            [$period->id],
            'month_vs_month',
            'general',
            $run,
        );
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull();

    $incident = PeriodIncident::query()->where('period_summary_id', $summary->id)->where('type', 'gastos_excel_sin_par_pdf')->first();
    expect($incident)->not->toBeNull();
    expect($incident->severity)->toBe('warning');
    expect($incident->context['count'])->toBe(1);

    expect($run->fresh()->metadata['gastos_excel_sin_par_pdf']['count'] ?? null)->toBe(1);
});
