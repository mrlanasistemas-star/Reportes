<?php

namespace App\Console\Commands;

use App\Services\Pdf\BrowsershotPdfRenderer;
use App\Services\Pdf\PdfRenderException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Diagnóstico end-to-end del motor de PDF (retoma 21-sep-2026, punto 3) — comprueba
 * cada eslabón de la cadena Browsershot/Puppeteer/Chrome por separado, en el MISMO
 * orden en que BrowsershotPdfRenderer los necesita, para que un fallo diga exactamente
 * dónde está roto (Node vs Chrome vs HTML vs gráficas vs disco) en vez de un genérico
 * "no se pudo generar el PDF".
 *
 *   php artisan reports:pdf-health
 */
class ReportsPdfHealthCommand extends Command
{
    protected $signature = 'reports:pdf-health';

    protected $description = 'Verifica Node, Puppeteer, Chrome, Browsershot, render HTML, render de gráficas y escritura en storage.';

    private const LABEL_WIDTH = 12;

    public function handle(BrowsershotPdfRenderer $renderer): int
    {
        $allOk = true;
        $results = [];

        $results['Node']      = $this->checkNode();
        $results['Chrome']    = $this->checkChrome();
        $results['Storage']   = $this->checkStorageWrite();
        $results['HTML PDF']  = $this->checkHtmlPdf($renderer);
        $results['Chart PDF'] = $this->checkChartPdf($renderer);
        $results['Browsershot'] = $results['HTML PDF']['ok'] && $results['Chart PDF']['ok']
            ? ['ok' => true, 'detail' => 'Render real vía Browsershot funcionando.']
            : ['ok' => false, 'detail' => 'Depende de HTML PDF / Chart PDF (ver arriba).'];

        // Orden de salida fijo — coincide con el pedido: Node, Puppeteer, Chrome,
        // Browsershot, HTML render, gráfica/SVG render, storage write.
        $order = ['Node' => 'Node', 'Puppeteer' => null, 'Chrome' => 'Chrome', 'Browsershot' => 'Browsershot', 'HTML PDF' => 'HTML PDF', 'Chart PDF' => 'Chart PDF', 'Storage' => 'Storage'];
        // Puppeteer no es un binario propio (Browsershot lo invoca vía Node); su salud
        // real ya la cubre Chrome + HTML PDF, se reporta como alias informativo.
        $results['Puppeteer'] = $results['Chrome']['ok']
            ? ['ok' => true, 'detail' => 'Puppeteer resuelve vía node_modules (ver Chrome/HTML PDF).']
            : ['ok' => false, 'detail' => 'No se puede verificar sin Chrome disponible.'];

        $rows = ['Node', 'Puppeteer', 'Chrome', 'Browsershot', 'HTML PDF', 'Chart PDF', 'Storage'];

        $this->newLine();
        foreach ($rows as $label) {
            $r = $results[$label];
            $allOk = $allOk && $r['ok'];
            $dots = str_pad('', max(3, self::LABEL_WIDTH - strlen($label)), '.');
            $status = $r['ok'] ? '<fg=green>OK</>' : '<fg=red>FALLO</>';
            $this->line(sprintf('%s %s %s', $label, $dots, $status));
            if (!$r['ok'] || $this->output->isVerbose()) {
                $this->line('  ' . $r['detail']);
            }
        }
        $this->newLine();

        if ($allOk) {
            $this->info('Todo el motor de PDF está operativo.');

            return self::SUCCESS;
        }

        $this->error('El motor de PDF tiene al menos un componente roto. Revisa el detalle arriba.');

        return self::FAILURE;
    }

    /** @return array{ok: bool, detail: string} */
    private function checkNode(): array
    {
        $configured = config('pdf.browsershot.node_binary');
        if ($configured) {
            return is_file($configured)
                ? ['ok' => true, 'detail' => "BROWSERSHOT_NODE_BINARY: {$configured}"]
                : ['ok' => false, 'detail' => "BROWSERSHOT_NODE_BINARY apunta a una ruta que no existe: {$configured}"];
        }

        // Sin override en .env: Browsershot resuelve 'node' del PATH del sistema —
        // se prueba directo con Process, igual que hará Symfony\Process internamente.
        try {
            $process = new \Symfony\Component\Process\Process(['node', '--version']);
            $process->setTimeout(10);
            $process->run();

            return $process->isSuccessful()
                ? ['ok' => true, 'detail' => 'node --version (PATH del sistema): ' . trim($process->getOutput())]
                : ['ok' => false, 'detail' => "'node' no está en el PATH del sistema (define BROWSERSHOT_NODE_BINARY en .env)."];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'No se pudo invocar node: ' . $e->getMessage()];
        }
    }

    /** @return array{ok: bool, detail: string} */
    private function checkChrome(): array
    {
        $chromePath = config('pdf.browsershot.chrome_path');
        if (!$chromePath) {
            return ['ok' => false, 'detail' => 'BROWSERSHOT_CHROME_PATH / PUPPETEER_EXECUTABLE_PATH no está definido en .env.'];
        }

        return is_file($chromePath)
            ? ['ok' => true, 'detail' => "Chrome/Chromium: {$chromePath}"]
            : ['ok' => false, 'detail' => "La ruta configurada no existe: {$chromePath}"];
    }

    /** @return array{ok: bool, detail: string} */
    private function checkStorageWrite(): array
    {
        $directory = storage_path('app/radiografias');
        try {
            File::ensureDirectoryExists($directory);
            $probe = $directory . '/.pdf-health-probe';
            File::put($probe, (string) now()->timestamp);
            $ok = File::exists($probe);
            File::delete($probe);

            return $ok
                ? ['ok' => true, 'detail' => "Escritura verificada en {$directory}"]
                : ['ok' => false, 'detail' => "No se pudo confirmar la escritura en {$directory}"];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => "No se pudo escribir en {$directory}: " . $e->getMessage()];
        }
    }

    /** @return array{ok: bool, detail: string} */
    private function checkHtmlPdf(BrowsershotPdfRenderer $renderer): array
    {
        $probePath = storage_path('app/radiografias/.pdf-health-html-probe.pdf');
        try {
            $renderer->renderHtmlToFile(
                '<html><body style="font-family:Helvetica"><h1>reports:pdf-health</h1><p>Probe HTML ' . now()->toDateTimeString() . '</p></body></html>',
                $probePath,
                ['wait_for_ready' => false],
            );
            $ok = file_exists($probePath) && filesize($probePath) > 0;
            @unlink($probePath);

            return $ok
                ? ['ok' => true, 'detail' => 'Render HTML → PDF real completado.']
                : ['ok' => false, 'detail' => 'Browsershot no dejó un PDF válido en disco.'];
        } catch (PdfRenderException $e) {
            @unlink($probePath);

            return ['ok' => false, 'detail' => "[{$e->code()}] {$e->getMessage()}"];
        } catch (Throwable $e) {
            @unlink($probePath);

            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /** @return array{ok: bool, detail: string} */
    private function checkChartPdf(BrowsershotPdfRenderer $renderer): array
    {
        $chartJsPath = public_path('vendor/chartjs/chart.umd.js');
        if (!is_file($chartJsPath)) {
            return ['ok' => false, 'detail' => "No existe {$chartJsPath} (chart.umd.js) — necesario para las gráficas del PDF."];
        }

        $probePath = storage_path('app/radiografias/.pdf-health-chart-probe.pdf');
        $html = '<html><body>'
            . '<script>window.__PDF_READY__ = false;</script>'
            . '<canvas id="c" width="300" height="150"></canvas>'
            . '<script>' . file_get_contents($chartJsPath) . '</script>'
            . '<script>new Chart(document.getElementById("c"), {type:"bar", data:{labels:["A","B"], datasets:[{data:[1,2]}]}}); window.__PDF_READY__ = true;</script>'
            . '</body></html>';

        try {
            $renderer->renderHtmlToFile($html, $probePath, ['wait_for_ready' => true, 'ready_timeout' => 20000]);
            $ok = file_exists($probePath) && filesize($probePath) > 0;
            @unlink($probePath);

            return $ok
                ? ['ok' => true, 'detail' => 'Render de gráfica Chart.js (canvas) real completado.']
                : ['ok' => false, 'detail' => 'Browsershot no dejó un PDF válido en disco.'];
        } catch (PdfRenderException $e) {
            @unlink($probePath);

            return ['ok' => false, 'detail' => "[{$e->code()}] {$e->getMessage()}"];
        } catch (Throwable $e) {
            @unlink($probePath);

            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }
}
