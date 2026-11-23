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
    /**
     * Renderiza una vista Blade a HTML y la imprime a PDF en disco.
     *
     * @param  string  $view  Nombre de la vista Blade.
     * @param  array<string, mixed>  $data  Datos de la vista.
     * @param  string  $outputPath  Ruta absoluta del PDF a escribir.
     * @param  array<string, mixed>  $options  Mismas opciones que renderHtmlToFile().
     *
     * @throws PdfRenderException
     */
    public function renderViewToFile(string $view, array $data, string $outputPath, array $options = []): void
    {
        $html = view($view, $data)->render();
        $this->renderHtmlToFile($html, $outputPath, $options);
    }

    /**
     * Imprime HTML a PDF con Chrome headless (Browsershot) y lo guarda en disco.
     *
     * Valida binarios antes de lanzar el proceso, crea el directorio destino,
     * aplica formato/márgenes/orientación (márgenes 0 y sin pie en full_bleed),
     * espera la señal window.__PDF_READY__ (salvo wait_for_ready=false) y pone
     * un pie con nombre de la app y "Página X de Y". Cualquier fallo se traduce
     * a PdfRenderException con código rastreable y queda en el log.
     *
     * @param  string  $html  Documento HTML completo.
     * @param  string  $outputPath  Ruta absoluta del PDF a escribir.
     * @param array{
     *     format?: string,
     *     margins?: array{top: float, right: float, bottom: float, left: float},
     *     footer_left?: string,
     *     footer_right?: string,
     *     wait_for_ready?: bool,
     *     ready_timeout?: int,
     *     full_bleed?: bool,
     *     landscape?: bool,
     *     outline?: bool,
     * } $options
     *
     * @throws PdfRenderException Si faltan binarios, hay timeout o el archivo resultante está vacío.
     */
    public function renderHtmlToFile(string $html, string $outputPath, array $options = []): void
    {
        $this->assertBinariesConfigured();

        File::ensureDirectoryExists(dirname($outputPath));

        // full_bleed (sección 1/3 — machotes CARTA COMPROMISO y WARNING ROJO):
        // esas dos vistas traen su propio fondo = la página original escaneada a
        // 612x792pt exactos; cualquier margen o footer inyectado por Puppeteer
        // desalinearía el overlay y pintaría un pie de página ajeno al machote.
        $fullBleed = $options['full_bleed'] ?? false;
        $margins = $options['margins'] ?? ($fullBleed
            ? ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0]
            : ['top' => 18, 'right' => 14, 'bottom' => 22, 'left' => 14]);
        $format = $options['format'] ?? config('pdf.defaults.format', 'Letter');
        $waitForReady = $options['wait_for_ready'] ?? true;
        $readyTimeout = $options['ready_timeout'] ?? config('pdf.defaults.pdf_ready_timeout', 20000);

        $shot = Browsershot::html($html)
            ->format($format)
            ->margins($margins['top'], $margins['right'], $margins['bottom'], $margins['left'])
            ->waitUntilNetworkIdle()
            ->timeout((int) config('pdf.browsershot.timeout', 180));

        if ($options['landscape'] ?? false) {
            $shot->landscape();
        }

        // Opcional (apagado por defecto: el resto de los PDF no cambia): marcadores / esquema del
        // documento generados por Chrome a partir de los encabezados h1–h6 (requiere PDF etiquetado).
        if ($options['outline'] ?? false) {
            $shot->taggedPdf()->setOption('outline', true);
        }

        if (config('pdf.defaults.print_background', true)) {
            $shot->showBackground();
        }

        $this->configureBinaries($shot);

        if ($waitForReady) {
            $shot->waitForFunction('window.__PDF_READY__ === true', timeout: (int) $readyTimeout);
        }

        if (! $fullBleed) {
            $shot->showBrowserHeaderAndFooter()
                ->hideHeader()
                ->footerHtml($this->footerTemplate(
                    $options['footer_left'] ?? config('pdf.branding.app_name'),
                    $options['footer_right'] ?? null,
                ));
        }

        try {
            $shot->savePdf($outputPath);
        } catch (Throwable $exception) {
            throw $this->translate($exception, $waitForReady);
        }

        if (! file_exists($outputPath) || filesize($outputPath) === 0) {
            throw PdfRenderException::htmlFailed(new \RuntimeException('Browsershot no produjo un archivo PDF válido.'));
        }
    }

    /**
     * PNG de alta resolución (mismo HTML/CSS que el PDF). `width` en px CSS; el alto
     * es el de la página completa; `scale` = deviceScaleFactor (2 = nitidez retina).
     *
     * Siempre espera la señal window.__PDF_READY__.
     *
     * @param  string  $html  Documento HTML completo.
     * @param  string  $outputPath  Ruta absoluta del PNG a escribir.
     * @param  array{width?: int, scale?: int, ready_timeout?: int}  $options
     *
     * @throws PdfRenderException Si faltan binarios, hay timeout o la imagen está vacía.
     */
    public function renderHtmlToImage(string $html, string $outputPath, array $options = []): void
    {
        $this->assertBinariesConfigured();
        File::ensureDirectoryExists(dirname($outputPath));

        $shot = Browsershot::html($html)
            ->windowSize($options['width'] ?? 1400, 900)
            ->deviceScaleFactor($options['scale'] ?? 2)
            ->fullPage()
            ->waitUntilNetworkIdle()
            ->timeout((int) config('pdf.browsershot.timeout', 180))
            ->waitForFunction('window.__PDF_READY__ === true', timeout: (int) ($options['ready_timeout'] ?? config('pdf.defaults.pdf_ready_timeout', 20000)));
        $this->configureBinaries($shot);

        try {
            $shot->save($outputPath);
        } catch (Throwable $exception) {
            throw $this->translate($exception, true);
        }

        if (! file_exists($outputPath) || filesize($outputPath) === 0) {
            throw PdfRenderException::htmlFailed(new \RuntimeException('Browsershot no produjo una imagen válida.'));
        }
    }

    /**
     * Aplica al Browsershot las rutas de Node, npm y Chrome y la opción
     * no-sandbox configuradas en config/pdf.php (solo las que estén definidas).
     *
     * @param  Browsershot  $shot  Instancia a configurar (se modifica en sitio).
     */
    private function configureBinaries(Browsershot $shot): void
    {
        $nodeBinary = config('pdf.browsershot.node_binary');
        $chromePath = config('pdf.browsershot.chrome_path');
        $npmBinary = config('pdf.browsershot.npm_binary');
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
     *
     * Si una ruta no está configurada no se valida (Browsershot usa la del PATH).
     *
     * @throws PdfRenderException NODE_NOT_FOUND o CHROME_NOT_FOUND.
     */
    private function assertBinariesConfigured(): void
    {
        $nodeBinary = config('pdf.browsershot.node_binary');
        if ($nodeBinary && ! is_file($nodeBinary)) {
            throw PdfRenderException::nodeNotFound(new \RuntimeException(
                "BROWSERSHOT_NODE_BINARY apunta a una ruta que no existe: {$nodeBinary}"
            ));
        }

        $chromePath = config('pdf.browsershot.chrome_path');
        if ($chromePath && ! is_file($chromePath)) {
            throw PdfRenderException::chromeNotFound(new \RuntimeException(
                "BROWSERSHOT_CHROME_PATH apunta a una ruta que no existe: {$chromePath}"
            ));
        }
    }

    /**
     * Plantilla HTML del pie de página que inyecta Chrome.
     *
     * @param  string  $left  Texto izquierdo (se escapa).
     * @param  string|null  $right  HTML derecho (NO se escapa); por defecto "Página X de Y" con las clases de Chrome.
     * @return string HTML del pie.
     */
    private function footerTemplate(string $left, ?string $right): string
    {
        $right ??= 'Página <span class="pageNumber"></span> de <span class="totalPages"></span>';

        return '<div style="width:100%;font-family:Helvetica,Arial,sans-serif;font-size:6.8pt;color:#94a3b8;'
            .'display:flex;justify-content:space-between;padding:0 14mm;">'
            .'<span>'.e($left).'</span>'
            .'<span>'.$right.'</span>'
            .'</div>';
    }

    /**
     * Browsershot (v5.4) NO envuelve todos sus fallos en CouldNotTakeBrowsershot —
     * un binario/executablePath inválido revienta como
     * Symfony\Component\Process\Exception\ProcessFailedException directo desde el
     * proceso hijo (verificado empíricamente ejecutando esta clase contra un
     * chrome_path inexistente) — por eso este método acepta cualquier Throwable,
     * nunca un tipo concreto de excepción.
     *
     * Registra el error en el log y clasifica por frases de Puppeteer/Node:
     * timeout (chartTimeout si se esperaba la señal de listo), Chrome no
     * encontrado, Node no encontrado o fallo genérico.
     *
     * @param  Throwable  $exception  Excepción original del proceso.
     * @param  bool  $wasWaitingForReady  Si se esperaba window.__PDF_READY__.
     * @return PdfRenderException Excepción traducida (no se lanza aquí).
     */
    private function translate(Throwable $exception, bool $wasWaitingForReady): PdfRenderException
    {
        $message = $exception->getMessage();

        Log::error('BrowsershotPdfRenderer: fallo generando PDF.', [
            'exception' => get_class($exception),
            'message' => $message,
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
