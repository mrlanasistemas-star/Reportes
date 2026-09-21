<?php

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\Pdf\PdfRenderException;
use App\Services\RadiografiaExportService;

uses(Tests\Integration\UsesRealDevDatabase::class);

/**
 * Migración a Browsershot como ÚNICO motor de PDF (cierre 21-sep-2026): la página de
 * gráficas ejecutivas (Chart.js) ya NO es un PDF aparte fusionado con FPDI — vive
 * DENTRO del mismo render de reports.radiography-pdf (ver
 * reports/partials/radiography-pdf-charts-section.blade.php). Con dompdf eliminado,
 * ya no existe un "PDF base garantizado" si Chrome falla: si Node/Chrome no están
 * disponibles, TODO el PDF general falla con un PdfRenderException clasificado — nunca
 * un degradado silencioso a un motor viejo que ya no existe.
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

it('generates a real general PDF with the executive charts page appended (single Browsershot render, no FPDI merge)', function () {
    $exportService = app(RadiografiaExportService::class);
    $path = $exportService->exportPdfWithConfig($this->period, ['scope' => 'general', 'report_type' => 'simple']);

    expect(file_exists($path))->toBeTrue();
    expect(filesize($path))->toBeGreaterThan(0);

    $text = shell_exec('pdftotext -layout ' . escapeshellarg($path) . ' - 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    if ($text !== null) {
        expect(str_contains($text, 'GRÁFICAS EJECUTIVAS') || str_contains($text, 'GR' . chr(0xC1) . 'FICAS EJECUTIVAS'))->toBeTrue();
    }

    @unlink($path);
})->skip(fn () => !is_file((string) config('pdf.browsershot.chrome_path')) && !is_file((string) config('pdf.browsershot.node_binary')), 'Node/Chrome no configurados con rutas absolutas en este entorno.');

it('throws a classified PdfRenderException (never a silent fallback) when Chrome is misconfigured', function () {
    config(['pdf.browsershot.chrome_path' => 'C:\\this\\path\\does\\not\\exist\\chrome.exe']);

    $exportService = app(RadiografiaExportService::class);

    expect(fn () => $exportService->exportPdfWithConfig($this->period, ['scope' => 'general', 'report_type' => 'simple']))
        ->toThrow(PdfRenderException::class);

    try {
        $exportService->exportPdfWithConfig($this->period, ['scope' => 'general', 'report_type' => 'simple']);
    } catch (PdfRenderException $e) {
        expect($e->code())->toBe(PdfRenderException::CHROME_NOT_FOUND);
    }
});

it('throws a classified PdfRenderException when Node is misconfigured, for branch/employee/comparative PDFs too', function () {
    config(['pdf.browsershot.node_binary' => 'C:\\this\\path\\does\\not\\exist\\node.exe']);

    $exportService = app(RadiografiaExportService::class);

    try {
        $exportService->exportPdf($this->period);
        $this->fail('Debió lanzar PdfRenderException.');
    } catch (PdfRenderException $e) {
        expect($e->code())->toBe(PdfRenderException::NODE_NOT_FOUND);
    }
});
