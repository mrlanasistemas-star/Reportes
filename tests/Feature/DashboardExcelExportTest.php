<?php

use App\Models\Branch;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\Dashboard\DashboardExcelExporter;
use App\Services\Dashboard\DashboardPdfChartBuilder;
use App\Services\DashboardTrendService;
use App\Services\Pdf\BrowsershotPdfRenderer;
use App\Services\RadiografiaExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

/**
 * Fase 7.3 (15-oct-2026) — Excel del Dashboard: mismo alcance y mismos datos que la
 * pantalla/PDF (payloadForPeriod() + tendencia del alcance), resumen ejecutivo +
 * desglose, moneda MXN, gráficas nativas, mismo permiso de exportar que el PDF.
 */
function dashboardExcelData(?string $branchName = null): array
{
    $rows = $branchName
        ? [['sucursal' => $branchName, 'colocacion' => 900000.5, 'recuperacion' => 1100000.25]]
        : [['sucursal' => 'CORDOBA', 'colocacion' => 3266852.0, 'recuperacion' => 4012413.5], ['sucursal' => 'TULA', 'colocacion' => 1459182.0, 'recuperacion' => 1759653.37]];
    $cats = $branchName
        ? [['nombre' => $branchName, 'ebitda' => 250000.0, 'categoria' => 'JUNIOR']]
        : [['nombre' => 'CORDOBA', 'ebitda' => 629400.92, 'categoria' => 'MASTER'], ['nombre' => 'TULA', 'ebitda' => -1234.5, 'categoria' => 'MANTENIDO']];

    return [
        'kpis' => ['period_label' => 'Junio 2026', 'period_id' => 1, 'ebitda' => 1861873.28, 'margen_ebitda' => 34.56, 'opex' => 899937.33, 'colocacion' => 14151329.0,
            'recuperacion' => 17888526.74, 'cartera' => 41028032.5, 'mora_pct' => 37.49, 'colaboradores' => 65],
        'charts' => [
            'moraBuckets' => [['label' => 'Mora 1-30', 'valor' => 1200000.0], ['label' => 'Mora 120+', 'valor' => 11500000.0]],
            'categorias' => $cats,
            'categoriaLabels' => array_values(array_unique(array_column($cats, 'categoria'))),
            'categoriaCounts' => array_fill(0, count(array_unique(array_column($cats, 'categoria'))), 1),
            'categoriaColors' => ['#7C3AED', '#B91C1C'],
            'sucursalRows' => $rows,
        ],
        'trend' => [
            ['label' => 'Mayo 2026', 'period_id' => 1, 'ebitda' => 2000000.0, 'opex' => 800000.0, 'available' => true],
            ['label' => 'Junio 2026', 'period_id' => 2, 'ebitda' => 1861873.28, 'opex' => 899937.33, 'available' => true],
        ],
        'periodLabel' => 'Junio 2026',
        'branchName' => $branchName,
        'generatedAt' => Carbon::parse('2026-10-15 10:00'),
        'generatedBy' => 'Admin',
    ];
}

it('writes the executive summary and breakdown sheets with numeric MXN values and native charts', function () {
    $path = storage_path('app/tmp/test-dashboard-' . uniqid() . '.xlsx');
    app(DashboardExcelExporter::class)->export(dashboardExcelData(), $path);

    $book = IOFactory::createReader('Xlsx')->setIncludeCharts(true)->load($path);
    File::delete($path);

    expect($book->getSheetNames())->toBe(['Resumen ejecutivo', 'Desglose']);
    $sum = $book->getSheetByName('Resumen ejecutivo');
    $values = collect($sum->toArray(null, false, false, false))->mapWithKeys(fn ($r) => [(string) ($r[0] ?? '') => $r[1] ?? null]);
    // Las cifras viajan como NÚMEROS exactos (no texto) con formato moneda.
    expect($values['EBITDA'])->toBe(1861873.28)->and($values['OPEX'])->toBe(899937.33)->and($values['Margen EBITDA'])->toBe(34.56)
        ->and($values['Colaboradores activos'])->toBe(65)->and($values['Alcance'])->toBe('Todas las sucursales (consolidado)');
    $ebitdaRow = collect($sum->toArray(null, false, false, true))->search(fn ($r) => ($r['A'] ?? null) === 'EBITDA');
    expect($sum->getStyle("B{$ebitdaRow}")->getNumberFormat()->getFormatCode())->toBe('"$"#,##0.00');
    expect($sum->getChartCollection())->toHaveCount(1);

    $detail = $book->getSheetByName('Desglose');
    $flat = collect($detail->toArray(null, false, false, false))->flatten()->filter()->values()->all();
    expect($flat)->toContain('CORDOBA', 'TULA', 'Suma de sucursales', 3266852.0, -1234.5, 'MASTER')
        ->and($detail->getChartCollection())->toHaveCount(3); // mora, categorías, colocación vs recuperación
});

it('explains a branch without radiography instead of writing empty tables', function () {
    $data = dashboardExcelData('TULA');
    $data['kpis'] = null;
    $data['charts'] = null;
    $data['trend'] = [];
    $book = app(DashboardExcelExporter::class)->build($data);

    $text = collect($book->getSheetByName('Resumen ejecutivo')->toArray())->flatten()->filter()->implode(' ');
    expect($text)->toContain('Sin datos de radiografía para Sucursal TULA en Junio 2026');
});

it('protects the Excel route with the same export permission as the PDF', function () {
    $pdf = Route::getRoutes()->getByName('dashboard.export-pdf')->gatherMiddleware();
    $xlsx = Route::getRoutes()->getByName('dashboard.export-excel')->gatherMiddleware();

    expect($xlsx)->toContain('permission:reportes,exportar')->and(array_values(array_intersect($pdf, $xlsx)))->toEqual(array_values($pdf));
});

it('exports the selected branch scope (KPIs, charts and branch trend) through the endpoint', function () {
    $period = Period::query()->create(['name' => 'Junio 2026', 'code' => 'M-2026-06', 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30']);
    PeriodSummary::query()->create(['period_id' => $period->id, 'status' => 'generated', 'generated_at' => now(), 'global_metrics' => [], 'warnings' => [], 'version' => 1]);
    $branch = Branch::query()->create(['name' => 'ATLIXCO', 'code' => 'ATL']);
    $data = dashboardExcelData('ATLIXCO');

    $export = Mockery::mock(RadiografiaExportService::class);
    $export->shouldReceive('buildSnapshot')->withArgs(fn ($p, $config) => $config === ['scope' => 'branch', 'branch_id' => $branch->id])->andReturn([
        'scope' => ['type' => 'branch', 'branch_id' => $branch->id, 'branch_name' => 'ATLIXCO', 'available' => true],
        'summary' => ['ebitda_final' => 250000.0, 'margen_ebitda' => 12.5, 'opex_total' => 90000.0, 'placement_total' => 900000.5, 'recovery_total' => 1100000.25, 'portfolio_total' => 3000000.0, 'mora_index' => 20.0],
    ]);
    $export->shouldReceive('buildExecutiveChartsData')->andReturn($data['charts']);
    app()->instance(RadiografiaExportService::class, $export);
    $trend = Mockery::mock(DashboardTrendService::class);
    $trend->shouldReceive('build')->with($branch->id)->twice()->andReturn([['label' => 'Junio 2026', 'period_id' => $period->id, 'ebitda' => 250000.0, 'opex' => 90000.0, 'available' => true]]);
    app()->instance(DashboardTrendService::class, $trend);
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get(route('dashboard.export-excel', ['period_id' => $period->id, 'branch_id' => $branch->id]));
    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('dashboard-junio-2026-atlixco.xlsx');
    $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
    $values = collect($book->getSheetByName('Resumen ejecutivo')->toArray(null, false, false, false))->mapWithKeys(fn ($r) => [(string) ($r[0] ?? '') => $r[1] ?? null]);
    expect($values['EBITDA'])->toBe(250000.0)->and($values['Alcance'])->toBe('Sucursal ATLIXCO');

    // El PDF del mismo alcance también recibe la tendencia de la sucursal (no la general).
    $captured = null;
    $renderer = Mockery::mock(BrowsershotPdfRenderer::class);
    $renderer->shouldReceive('renderViewToFile')->once()->andReturnUsing(function (string $view, array $vars, string $path) use (&$captured) {
        $captured = view($view, $vars)->render();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '%PDF-1.4 fake');
    });
    app()->instance(BrowsershotPdfRenderer::class, $renderer);
    app()->instance(DashboardPdfChartBuilder::class, new DashboardPdfChartBuilder());
    $this->actingAs($admin)->get(route('dashboard.export-pdf', ['period_id' => $period->id, 'branch_id' => $branch->id]))->assertOk();
    expect($captured)->toContain('Tendencia mensual · Sucursal ATLIXCO')->toContain('Categoría EBITDA de la sucursal')->toContain('JUNIOR');
});
