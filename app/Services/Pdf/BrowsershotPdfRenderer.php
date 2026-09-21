<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;
use Throwable;

/**
 * Motor central de PDF — ÚNICA capa de render para toda la aplicación (migración
 * completa de DomPDF/FPDF/FPDI a Browsershot/Puppeteer/Chrome, cierre 21-sep-2026).
 * Ningún servicio/controlador debe instanciar Browsershot directamente ni leer
 * config('pdf.*') por su cuenta — todos pasan por renderViewToFile()/renderHtmlToFile().
 *
 * Señal de "listo para imprimir" (sección 12 del pedido): toda vista PDF debe dejar
 * `window.__PDF_READY__ = true` en algún momento (por defecto ya lo hace, ver el
 * bootstrap inline en cada blade); páginas con gráficas JS lo ponen en false al
 * empezar a dibujar y en true al terminar. Este renderer SIEMPRE espera esa señal
 * en vez de un sleep() fijo — más lento en el peor caso, nunca frágil.
 */
class BrowsershotPdfRenderer
{
    public function renderViewToFile(string $view, array $data, string $outputPath, array $options = []): void
    {
        $html = view($view, $data)->render();
        $this->renderHtmlToFile($html, $outputPath, $options);
    }

    /**
     * @param array{
     *     format?: string,
     *     margins?: array{top: float, right: float, bottom: float, left: float},
     *     footer_left?: string,
     *     footer_right?: string,
     *     wait_for_ready?: bool,
     *     ready_timeout?: int,
     * } $options
     */
    public function renderHtmlToFile(string $html, string $outputPath, array $options = []): void
    {
        $this->assertBinariesConfigured();

        File::ensureDirectoryExists(dirname($outputPath));

        $margins = $options['margins'] ?? ['top' => 18, 'right' => 14, 'bottom' => 22, 'left' => 14];
        $format  = $options['format'] ?? config('pdf.defaults.format', 'Letter');
        $waitForReady = $options['wait_for_ready'] ?? true;
        $readyTimeout = $options['ready_timeout'] ?? config('pdf.defaults.pdf_ready_timeout', 20000);

        $shot = Browsershot::html($html)
            ->format($format)
            ->margins($margins['top'], $margins['right'], $margins['bottom'], $margins['left'])
            ->waitUntilNetworkIdle()
            ->timeout((int) config('pdf.browsershot.timeout', 180));

        if (config('pdf.defaults.print_background', true)) {
            $shot->showBackground();
        }

        $nodeBinary = config('pdf.browsershot.node_binary');
        $chromePath = config('pdf.browsershot.chrome_path');
        $npmBinary  = config('pdf.browsershot.npm_binary');
        if ($nodeBinary) {
            $shot->setNodeBinary($nodeBinary);
        }
        if ($npmBinary) {
            $shot->setNpmBinary($npmBinary);
        }
        if ($chromePath) {
            $shot->setChromePath($chromePath);
        }
        if (config('pdf.browsershot.no_sandbox')) {
            $shot->noSandbox();
        }

        if ($waitForReady) {
            $shot->waitForFunction('window.__PDF_READY__ === true', timeout: (int) $readyTimeout);
        }

        $shot->showBrowserHeaderAndFooter()
            ->hideHeader()
            ->footerHtml($this->footerTemplate(
                $options['footer_left'] ?? config('pdf.branding.app_name'),
                $options['footer_right'] ?? null,
            ));

        try {
            $shot->savePdf($outputPath);
        } catch (Throwable $exception) {
            throw $this->translate($exception, $waitForReady);
        }

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            throw PdfRenderException::htmlFailed(new \RuntimeException('Browsershot no produjo un archivo PDF válido.'));
        }
    }

    /**
     * Valida ANTES de invocar el proceso Node/Chrome, en vez de adivinar a partir del
     * stderr del subproceso — verificado empíricamente que ese stderr es dependiente
     * del idioma del sistema operativo (en este entorno, Windows en español, un
     * executablePath inválido produce "El sistema no puede encontrar la ruta
     * especificada." — ningún substring en inglés lo detecta) y del propio Symfony
     * Process (mismo tipo de excepción para node roto y chrome roto, sin distinción
     * fiable en el mensaje). is_file() sobre la ruta configurada es determinista y no
     * depende de idioma/versión — mismo chequeo que usa `reports:pdf-health`.
     */
    private function assertBinariesConfigured(): void
    {
        $nodeBinary = config('pdf.browsershot.node_binary');
        if ($nodeBinary && !is_file($nodeBinary)) {
            throw PdfRenderException::nodeNotFound(new \RuntimeException(
                "BROWSERSHOT_NODE_BINARY apunta a una ruta que no existe: {$nodeBinary}"
            ));
        }

        $chromePath = config('pdf.browsershot.chrome_path');
        if ($chromePath && !is_file($chromePath)) {
            throw PdfRenderException::chromeNotFound(new \RuntimeException(
                "BROWSERSHOT_CHROME_PATH apunta a una ruta que no existe: {$chromePath}"
            ));
        }
    }

    private function footerTemplate(string $left, ?string $right): string
    {
        $right ??= 'Página <span class="pageNumber"></span> de <span class="totalPages"></span>';

        return '<div style="width:100%;font-family:Helvetica,Arial,sans-serif;font-size:6.8pt;color:#94a3b8;'
            . 'display:flex;justify-content:space-between;padding:0 14mm;">'
            . '<span>' . e($left) . '</span>'
            . '<span>' . $right . '</span>'
            . '</div>';
    }

    /**
     * Browsershot (v5.4) NO envuelve todos sus fallos en CouldNotTakeBrowsershot —
     * un binario/executablePath inválido revienta como
     * Symfony\Component\Process\Exception\ProcessFailedException directo desde el
     * proceso hijo (verificado empíricamente ejecutando esta clase contra un
     * chrome_path inexistente) — por eso este método acepta cualquier Throwable,
     * nunca un tipo concreto de excepción.
     */
    private function translate(Throwable $exception, bool $wasWaitingForReady): PdfRenderException
    {
        $message = $exception->getMessage();

        Log::error('BrowsershotPdfRenderer: fallo generando PDF.', [
            'exception' => get_class($exception),
            'message'   => $message,
        ]);

        $lower = mb_strtolower($message);

        // Frases textuales de Puppeteer/Node (en inglés, vienen del runtime JS, no del
        // sistema operativo) — a diferencia de "executablePath" o "chrome.exe", que
        // SIEMPRE aparecen en el mensaje (son parte del volcado JSON del comando
        // invocado, presente incluso cuando el fallo real es un timeout u otra cosa),
        // así que nunca se usan solas como señal de qué falló.
        if (str_contains($lower, 'timeouterror') || str_contains($lower, 'waiting failed') || str_contains($lower, 'waiting for function failed') || str_contains($lower, 'timed out')) {
            return $wasWaitingForReady
                ? PdfRenderException::chartTimeout($exception)
                : PdfRenderException::timeout($exception);
        }

        if (str_contains($lower, 'browser was not found') || str_contains($lower, 'failed to launch') || str_contains($lower, 'no usable sandbox')) {
            return PdfRenderException::chromeNotFound($exception);
        }

        if (str_contains($lower, "'node' is not recognized") || str_contains($lower, 'sh: node:') || str_contains($lower, 'sh: 1: node:')) {
            return PdfRenderException::nodeNotFound($exception);
        }

        return PdfRenderException::htmlFailed($exception);
    }
}
