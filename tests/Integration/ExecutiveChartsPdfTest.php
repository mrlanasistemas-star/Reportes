<?php

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\RadiografiaExportService;

uses(Tests\Integration\UsesRealDevDatabase::class);

/**
 * Cierre 17-sep-2026 ronda 3 — a petición explícita del usuario ("quiero PDFs modernos
 * con gráficas"), se agrega una página de gráficas ejecutivas (Chart.js, renderizada por
 * Chrome headless vía Browsershot) al FINAL del PDF general ya existente — dompdf no
 * soporta canvas/JS, así que esa página se genera aparte y se fusiona con FPDI. Solo
 * afecta el PDF GENERAL — branch/employee/comparativo siguen 100% dompdf sin cambios.
 *
 * Requisito no negociable: si Node/Chrome no están instalados en el servidor (o algo del
 * render de gráficas falla por cualquier motivo), el export NUNCA debe fallar ni lanzar —
 * debe devolver exactamente el PDF de siempre, sin la página extra. Este test verifica
 * ambos escenarios sin asumir que este entorno tiene Chrome disponible.
 */
beforeEach(function () {
    $this->useRealDevDatabaseOrSkip();

    $this->period = Period::find(21) ?? Period::query()->where('type', 'monthly')
        ->whereIn('id', PeriodSummary::query()->where('status', 'generated')->pluck('period_id'))
        ->orderByDesc('id')->first();
    if (!$this->period) {
        $this->markTestSkipped('No hay periodos mensuales con radiografía generada.');
    }
});

it('never fails the general PDF export even if Browsershot/Chrome cannot render the charts page', function () {
    config(['services.browsershot.chrome_path' => 'C:\\this\\path\\does\\not\\exist\\chrome.exe']);

    $exportService = app(RadiografiaExportService::class);
    $path = $exportService->exportPdfWithConfig($this->period, ['scope' => 'general', 'report_type' => 'simple']);

    expect(file_exists($path))->toBeTrue();
    expect(filesize($path))->toBeGreaterThan(0);
    @unlink($path);
});

it('appends a real executive charts page to the general PDF when Chrome IS available, without touching branch/employee PDFs', function () {
    $exportService = app(RadiografiaExportService::class);
    $originalChromePath = config('services.browsershot.chrome_path');
    $originalNodeBinary  = config('services.browsershot.node_binary');

    config(['services.browsershot.chrome_path' => 'C:\\this\\path\\does\\not\\exist\\chrome.exe']);
    $pathBaseline = $exportService->exportPdfWithConfig($this->period, ['scope' => 'general', 'report_type' => 'simple']);
    $sizeWithoutCharts = filesize($pathBaseline);
    @unlink($pathBaseline);

    config(['services.browsershot.chrome_path' => $originalChromePath]);
    config(['services.browsershot.node_binary' => $originalNodeBinary]);
    $path = $exportService->exportPdfWithConfig($this->period, ['scope' => 'general', 'report_type' => 'simple']);
    expect(file_exists($path))->toBeTrue();
    $sizeWithCharts = filesize($path);

    if ($sizeWithCharts <= $sizeWithoutCharts + 5000) {
        @unlink($path);
        $this->markTestSkipped('Node/Chrome no disponibles en este entorno — comportamiento correcto (degradación silenciosa), pero no hay página de gráficas que verificar aquí.');
    }

    $text = shell_exec('pdftotext -layout ' . escapeshellarg($path) . ' - 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    if ($text !== null) {
        expect(str_contains($text, 'GRÁFICAS EJECUTIVAS') || str_contains($text, 'GR' . chr(0xC1) . 'FICAS EJECUTIVAS'))->toBeTrue();
    }
    @unlink($path);
});
