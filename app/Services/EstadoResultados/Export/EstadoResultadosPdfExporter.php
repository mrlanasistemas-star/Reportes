<?php

namespace App\Services\EstadoResultados\Export;

use App\Services\Pdf\BrowsershotPdfRenderer;

/**
 * PDF ejecutivo del Estado de Resultados (Browsershot, misma infraestructura que
 * Dashboard/Efectividad). Recibe el dataset canónico; la vista sólo da formato.
 */
class EstadoResultadosPdfExporter
{
    public function __construct(private readonly BrowsershotPdfRenderer $renderer) {}

    public function html(array $dataset): string
    {
        return view('estado-resultados.pdf', ['d' => $dataset])->render();
    }

    public function export(array $dataset, string $outputPath): string
    {
        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->renderer->renderHtmlToFile($this->html($dataset), $outputPath, [
            'format' => 'Letter',
            'margins' => ['top' => 12, 'right' => 11, 'bottom' => 16, 'left' => 11],
            'footer_left' => 'MR LANA · Estado de Resultados · '.$dataset['meta']['period']['label'].' · '.$dataset['meta']['scope']['label'].' · dataset '.substr($dataset['meta']['dataset_hash'], 0, 12),
        ]);

        return $outputPath;
    }
}
