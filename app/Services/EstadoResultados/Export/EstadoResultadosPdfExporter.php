<?php

namespace App\Services\EstadoResultados\Export;

use App\Services\Pdf\BrowsershotPdfRenderer;
use App\Services\Pdf\PdfRenderException;

/**
 * PDF ejecutivo del Estado de Resultados con Browsershot (motor único de PDF del proyecto:
 * BrowsershotPdfRenderer). Recibe el dataset resuelto (el mismo de la web, ya redactado) y
 * las comparativas de ComparisonService; las gráficas son SVG generados en el servidor con
 * esas mismas series (no hay JavaScript que esperar: la vista marca __PDF_READY__ de inmediato).
 */
class EstadoResultadosPdfExporter
{
    public function __construct(
        private readonly BrowsershotPdfRenderer $renderer,
        private readonly EstadoResultadosExportContext $context,
    ) {}

    public function html(array $dataset, ?array $comparisons = null, ?string $comparisonsError = null): string
    {
        $x = $this->context->make($dataset, $comparisons, $comparisonsError);

        return view('estado-resultados.pdf', ['x' => $x] + $this->charts($x))->render();
    }

    /**
     * @throws PdfRenderException fallo/timeout de Chrome (el archivo parcial se elimina)
     */
    public function export(array $dataset, string $outputPath, ?array $comparisons = null, ?string $comparisonsError = null): string
    {
        $x = $this->context->make($dataset, $comparisons, $comparisonsError);
        $html = view('estado-resultados.pdf', ['x' => $x] + $this->charts($x))->render();
        $rep = $x['report'];
        try {
            $this->renderer->renderHtmlToFile($html, $outputPath, [
                'format' => 'Letter',
                'margins' => ['top' => 12, 'right' => 11, 'bottom' => 16, 'left' => 11],
                'ready_timeout' => 30000,
                'footer_left' => 'MR LANA · Estado de Resultados · '.$x['meta']['period']['label'].' · '.$x['meta']['scope']['label']
                    .' · v'.$rep['version'].' '.$rep['display_status'].' · dataset '.$x['meta']['hash_short'],
            ]);
        } catch (\Throwable $e) {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
            throw $e instanceof PdfRenderException ? $e : PdfRenderException::htmlFailed($e);
        }

        return $outputPath;
    }

    /** SVG de cada gráfica a partir de charts.* del contrato er-comparativas/1 (o del dataset si no hay comparativas). */
    private function charts(array $x): array
    {
        $charts = $x['comparisons']['charts'] ?? [];
        $color = fn (array $s) => $s + ['color' => ErSvgCharts::COLORS[$s['key']] ?? null];
        $evo = $charts['evolucion_resultados'] ?? null;
        $io = $charts['ingresos_vs_opex'] ?? null;
        $mg = $charts['margenes'] ?? null;
        $comp = $charts['composicion_opex'] ?? null;
        $suc = $charts['sucursales'] ?? null;
        $evoMode = $x['comparisons']['evolution'] ?? null;

        $items = $comp ? $comp['items'] : array_map(fn ($c) => ['key' => $c['key'], 'label' => $c['label'], 'amount' => $c['amount']], $x['opex_categories']);
        $branchRows = [];
        if ($suc) {
            $rows = array_values($suc['rows']);
            usort($rows, fn ($a, $b) => (float) ($b['ebitda'] ?? -INF) <=> (float) ($a['ebitda'] ?? -INF));
            $branchRows = array_map(fn ($r) => ['label' => $r['label'], 'value' => $r['ebitda']], $rows);
        }

        return [
            'svg' => [
                'evolucion' => $evo ? ErSvgCharts::line($evo['labels'], array_map($color, $evo['series'])) : ErSvgCharts::line([], []),
                'ingresos_opex' => $io ? ErSvgCharts::bars($io['labels'], array_map($color, $io['series'])) : ErSvgCharts::bars([], []),
                'margenes' => $mg ? ErSvgCharts::line($mg['labels'], array_map($color, $mg['series']), '%', 200) : ErSvgCharts::line([], [], '%'),
                'composicion' => ErSvgCharts::donut($items, $comp['total'] ?? $x['results']['opex_total']),
                'cascada' => ErSvgCharts::waterfall($x['cascade']),
                'sucursales' => $branchRows ? ErSvgCharts::hbars($branchRows) : null,
            ],
            'svgMeta' => [
                'evolucion' => $evoMode ? ($evoMode['label'].' · '.$evoMode['range']['from'].' a '.$evoMode['range']['to'].'. Meses sin datos: «Sin datos», nunca $0.00.') : 'Sin comparativas disponibles.',
            ],
        ];
    }
}
