<?php

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\Dashboard\DashboardPdfChartBuilder;
use App\Services\DashboardTrendService;
use App\Services\Pdf\BrowsershotPdfRenderer;
use App\Services\RadiografiaExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/**
 * PDF del Dashboard (15-oct-2026). Antes "Exportar PDF" descargaba la
 * Radiografía completa (otro reporte); ahora /dashboard/exportar.pdf genera
 * los MISMOS KPI y gráficas del periodo/sucursal elegidos. Sin Chrome: el
 * renderer se sustituye y se inspecciona la vista y los datos que recibe.
 */
function dashboardPdfFixtures(): array
{
    $period = Period::query()->create([
        'name' => 'Junio 2026', 'code' => 'M-2026-06', 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => true,
    ]);
    PeriodSummary::query()->create(['period_id' => $period->id, 'status' => 'generated', 'generated_at' => now(), 'global_metrics' => [], 'warnings' => [], 'version' => 1]);

    $export = Mockery::mock(RadiografiaExportService::class);
    $export->shouldReceive('buildSnapshot')->andReturn([
        'scope' => ['type' => 'general', 'branch_id' => null, 'branch_name' => null, 'available' => true],
        'summary' => ['ebitda_final' => 1861873.28, 'margen_ebitda' => 34.56, 'opex_total' => 899937.33, 'placement_total' => 14151329, 'recovery_total' => 17888526.74, 'portfolio_total' => 41028032.5, 'mora_index' => 37.49],
    ]);
    $export->shouldReceive('buildExecutiveChartsData')->andReturn([
        'moraBuckets' => [['label' => 'Mora 1-30', 'valor' => 1200000.0], ['label' => 'Mora 120+', 'valor' => 11500000.0]],
        'categorias' => [['nombre' => 'CORDOBA', 'ebitda' => 629400.92, 'categoria' => 'MASTER'], ['nombre' => 'TULA', 'ebitda' => -1234.5, 'categoria' => 'MANTENIDO']],
        'categoriaLabels' => ['MASTER', 'MANTENIDO'], 'categoriaCounts' => [1, 1], 'categoriaColors' => ['#7C3AED', '#B91C1C'],
        'sucursalRows' => [['sucursal' => 'CORDOBA', 'colocacion' => 3266852.0, 'recuperacion' => 4012413.5], ['sucursal' => 'TULA', 'colocacion' => 1459182.0, 'recuperacion' => 1759653.37]],
    ]);
    app()->instance(RadiografiaExportService::class, $export);

    $trend = Mockery::mock(DashboardTrendService::class);
    $trend->shouldReceive('build')->andReturn([['label' => 'Mayo 2026', 'ebitda' => 2000000.0, 'opex' => 800000.0], ['label' => 'Junio 2026', 'ebitda' => 1861873.28, 'opex' => 899937.33]]);
    app()->instance(DashboardTrendService::class, $trend);

    return [$period, User::factory()->create(['role' => 'admin'])];
}

it('exports the dashboard KPIs and charts of the selected period (not the full radiography)', function () {
    [$period, $admin] = dashboardPdfFixtures();
    $captured = [];
    $renderer = Mockery::mock(BrowsershotPdfRenderer::class);
    $renderer->shouldReceive('renderViewToFile')->once()->andReturnUsing(function (string $view, array $data, string $path) use (&$captured) {
        $captured = ['view' => $view, 'data' => $data, 'html' => view($view, $data)->render()];
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '%PDF-1.4 fake');
    });
    app()->instance(BrowsershotPdfRenderer::class, $renderer);

    $response = $this->actingAs($admin)->get(route('dashboard.export-pdf', ['period_id' => $period->id]));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('dashboard-junio-2026-todas-las-sucursales.pdf');
    expect($captured['view'])->toBe('reports.dashboard-pdf');
    expect($captured['data']['kpis']['ebitda'])->toBe(1861873.28);

    $html = $captured['html'];
    // Los 8 KPI del Dashboard con formato $1,234,567.89.
    expect($html)->toContain('$1,861,873.28')->toContain('34.56%')->toContain('$899,937.33')->toContain('$14,151,329.00')
        ->toContain('$17,888,526.74')->toContain('$41,028,032.50')->toContain('37.49%')->toContain('Todas las sucursales');
    // Las 4 gráficas como SVG del servidor (nunca dependen de JS) + tabla de cifras exactas.
    expect(substr_count($html, '<svg'))->toBe(4);
    expect($html)->toContain('EBITDA vs OPEX')->toContain('Categoría EBITDA por sucursal')->toContain('Cartera vencida por antigüedad')
        ->toContain('Colocación vs. recuperación')->toContain('$3,266,852.00')->toContain('-$1,234.50');
    // Montserrat local embebida, sin Google Fonts.
    expect($html)->toContain("font-family:'Montserrat'")->toContain('data:font/woff2;base64,')->not->toContain('fonts.googleapis.com');
});

it('answers with a clear message (not a blank PDF) when the period has no generated radiography', function () {
    [, $admin] = dashboardPdfFixtures();
    $renderer = Mockery::mock(BrowsershotPdfRenderer::class);
    $renderer->shouldReceive('renderViewToFile')->never();
    app()->instance(BrowsershotPdfRenderer::class, $renderer);

    $this->actingAs($admin)->get(route('dashboard.export-pdf', ['period_id' => 999999]))
        ->assertNotFound()->assertSee('No hay radiografía generada para el periodo solicitado.');
});

it('renders the dashboard PDF empty state for a branch without radiography', function () {
    $html = view('reports.dashboard-pdf', [
        'kpis' => null, 'charts' => null, 'trend' => [], 'scope' => ['type' => 'branch', 'available' => false],
        'periodLabel' => 'Junio 2026', 'branchName' => 'TULA', 'generatedAt' => Carbon::parse('2026-10-15 10:00'), 'generatedBy' => 'Admin',
        'chart' => new DashboardPdfChartBuilder(),
    ])->render();

    expect($html)->toContain('Sin datos de radiografía para Sucursal TULA en Junio 2026')->not->toContain('<svg');
});

it('uses round axis ticks in the chart builder (never misleading labels like $2.3M)', function () {
    $svg = (new DashboardPdfChartBuilder())->trend([['label' => 'A', 'ebitda' => 2250000, 'opex' => 700000], ['label' => 'B', 'ebitda' => 1860000, 'opex' => 900000]]);

    expect($svg)->toContain('$500K')->toContain('$1M')->toContain('$1.5M')->toContain('$2.5M')->not->toContain('$2.3M');
});
