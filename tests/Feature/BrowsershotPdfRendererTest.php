<?php

use App\Services\Pdf\BrowsershotPdfRenderer;
use App\Services\Pdf\PdfRenderException;

/**
 * Motor central de PDF (migración a Browsershot, cierre 21-sep-2026). Los chequeos de
 * NODE_NOT_FOUND/CHROME_NOT_FOUND son pre-flight (is_file() sobre la ruta configurada,
 * ver BrowsershotPdfRenderer::assertBinariesConfigured()) — deliberadamente NO se prueban
 * parseando stderr de un subproceso real, porque ese texto es dependiente del idioma del
 * sistema operativo (verificado: Windows en español produce "El sistema no puede
 * encontrar la ruta especificada.", ningún substring en inglés lo detecta) y por eso
 * nunca es la fuente de verdad aquí.
 */
it('throws PDF_RENDER_NODE_NOT_FOUND when the configured node binary path does not exist', function () {
    config(['pdf.browsershot.node_binary' => 'C:\\this\\path\\does\\not\\exist\\node.exe']);
    config(['pdf.browsershot.chrome_path' => null]);

    $renderer = new BrowsershotPdfRenderer();

    try {
        $renderer->renderHtmlToFile('<html><body>test</body></html>', storage_path('app/radiografias/__unit_test_never_created.pdf'));
        $this->fail('Debió lanzar PdfRenderException.');
    } catch (PdfRenderException $e) {
        expect($e->code())->toBe(PdfRenderException::NODE_NOT_FOUND);
    }
});

it('throws PDF_RENDER_CHROME_NOT_FOUND when the configured chrome path does not exist', function () {
    config(['pdf.browsershot.node_binary' => null]);
    config(['pdf.browsershot.chrome_path' => 'C:\\this\\path\\does\\not\\exist\\chrome.exe']);

    $renderer = new BrowsershotPdfRenderer();

    try {
        $renderer->renderHtmlToFile('<html><body>test</body></html>', storage_path('app/radiografias/__unit_test_never_created.pdf'));
        $this->fail('Debió lanzar PdfRenderException.');
    } catch (PdfRenderException $e) {
        expect($e->code())->toBe(PdfRenderException::CHROME_NOT_FOUND);
    }
});

it('renders real HTML to a valid, non-empty PDF file when Node/Chrome are correctly configured', function () {
    $nodeBinary = config('pdf.browsershot.node_binary');
    $chromePath = config('pdf.browsershot.chrome_path');
    if (!is_file((string) $nodeBinary) || !is_file((string) $chromePath)) {
        $this->markTestSkipped('BROWSERSHOT_NODE_BINARY/BROWSERSHOT_CHROME_PATH no configurados con rutas absolutas válidas en este entorno.');
    }

    $renderer = new BrowsershotPdfRenderer();
    $path = storage_path('app/radiografias/__unit_test_' . uniqid() . '.pdf');

    $renderer->renderHtmlToFile('<html><head><title>t</title></head><body><h1>Hola</h1></body></html>', $path, [
        'wait_for_ready' => false,
        'footer_left'    => 'Test unitario',
    ]);

    expect(file_exists($path))->toBeTrue();
    expect(filesize($path))->toBeGreaterThan(0);

    @unlink($path);
});

it('throws PDF_RENDER_CHART_TIMEOUT when window.__PDF_READY__ never becomes true', function () {
    $nodeBinary = config('pdf.browsershot.node_binary');
    $chromePath = config('pdf.browsershot.chrome_path');
    if (!is_file((string) $nodeBinary) || !is_file((string) $chromePath)) {
        $this->markTestSkipped('BROWSERSHOT_NODE_BINARY/BROWSERSHOT_CHROME_PATH no configurados con rutas absolutas válidas en este entorno.');
    }

    $renderer = new BrowsershotPdfRenderer();
    $path = storage_path('app/radiografias/__unit_test_' . uniqid() . '.pdf');

    try {
        $renderer->renderHtmlToFile(
            '<html><head><script>window.__PDF_READY__ = false;</script></head><body>nunca listo</body></html>',
            $path,
            ['wait_for_ready' => true, 'ready_timeout' => 300],
        );
        $this->fail('Debió lanzar PdfRenderException por timeout.');
    } catch (PdfRenderException $e) {
        expect($e->code())->toBe(PdfRenderException::CHART_TIMEOUT);
    } finally {
        @unlink($path);
    }
});
