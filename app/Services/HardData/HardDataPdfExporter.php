<?php

namespace App\Services\HardData;

use App\Services\Pdf\BrowsershotPdfRenderer;
use App\Services\Pdf\PdfRenderException;

/**
 * PDF ejecutivo del módulo «Datos duros» con Browsershot (motor único:
 * BrowsershotPdfRenderer). Dos formatos con la MISMA vista:
 *  - Lista (todos / lo filtrado): horizontal, KPIs, resumen por sucursal y una
 *    tabla compacta por colaborador que corre de corrido (sin saltos forzados).
 *  - Ficha (un colaborador): vertical, una sola hoja con todo su detalle.
 * Solo da formato a las filas de HardDataService::dataset().
 */
class HardDataPdfExporter
{
    public function __construct(private readonly BrowsershotPdfRenderer $renderer) {}

    /** HTML de la vista ($history = HardDataService::history() del colaborador, solo para la ficha). */
    public function html(array $dataset, ?array $single = null, array $history = []): string
    {
        return view('datos-duros.pdf', ['d' => $dataset, 'single' => $single, 'history' => $history, 'totals' => $this->totals($dataset['rows'])])->render();
    }

    /**
     * @throws PdfRenderException
     */
    public function export(array $dataset, string $outputPath, ?array $single = null, array $history = []): string
    {
        $label = $dataset['period']['name'].($single ? ' · '.$single['name'] : ' · '.$dataset['scope']['label']);

        try {
            $this->renderer->renderHtmlToFile($this->html($dataset, $single, $history), $outputPath, [
                'format' => 'Letter',
                'landscape' => $single === null,
                'margins' => ['top' => 8, 'right' => 8, 'bottom' => 12, 'left' => 8],
                'footer_left' => 'MR LANA · Datos duros · '.$label,
            ]);
        } catch (\Throwable $e) {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
            throw $e instanceof PdfRenderException ? $e : PdfRenderException::htmlFailed($e);
        }

        return $outputPath;
    }

    /** Mismos totales que la franja de la pantalla (useHardDataFilters.totals). */
    public function totals(array $rows): array
    {
        $c = collect($rows);
        $portfolio = (float) $c->sum('portfolio');
        $income = (float) $c->sum('income_base');

        return [
            'count' => $c->count(),
            'placement' => (float) $c->sum('placement'),
            'operations' => (int) $c->sum('operations'),
            'recovery' => (float) $c->sum('recovery'),
            'portfolio' => $portfolio,
            'overdue' => (float) $c->sum('overdue'),
            'mora_pct' => $portfolio > 0 ? $c->sum('overdue') / $portfolio * 100 : 0.0,
            'payroll' => (float) $c->sum('payroll'),
            'opex' => (float) $c->sum('opex'),
            'income' => $income,
            'ebitda' => (float) $c->sum('ebitda'),
            'margin_pct' => $income > 0 ? $c->sum('ebitda') / $income * 100 : 0.0,
        ];
    }
}
