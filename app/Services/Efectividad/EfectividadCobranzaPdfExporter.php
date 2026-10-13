<?php

namespace App\Services\Efectividad;

use App\Models\EfectividadCobranzaRun;
use App\Services\Efectividad\V4\EfectividadV4Calculator;
use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Support\Facades\Storage;

/**
 * PDF de Efectividad de Cobranza — resumen ejecutivo (KPIs + tabla por oficina
 * y promotor) a partir de los MISMOS datos que ya produjo el Excel
 * (`result_rows`). Nunca recalcula nada: es una vista alterna del mismo
 * resultado ya cerrado (`status=success`).
 *
 * Generado a demanda (bajo la petición de descarga) y cacheado en disco por
 * ejecución — nunca dentro del Job de generación (mantiene los Jobs V4/
 * Periódica, ya probados contra la paridad financiera, sin tocar).
 */
class EfectividadCobranzaPdfExporter
{
    public function __construct(
        private readonly BrowsershotPdfRenderer $renderer,
    ) {}

    /** Ruta RELATIVA (disco privado `local`) del PDF ya generado, o null si no existe. */
    public function existingRelativePath(EfectividadCobranzaRun $run): ?string
    {
        $path = $this->relativePath($run);

        return Storage::disk('local')->exists($path) ? $path : null;
    }

    /** Genera (o regenera) el PDF y devuelve su ruta RELATIVA en el disco privado `local`. */
    public function export(EfectividadCobranzaRun $run): string
    {
        $run->loadMissing(['period', 'branch']);

        $relativePath = $this->relativePath($run);
        Storage::disk('local')->makeDirectory('efectividad_cobranza');

        $this->renderer->renderViewToFile('efectividad.efectividad-pdf', [
            'run' => $run,
            'rows' => $run->result_rows ?? [],
            'engineLabel' => $run->engine_version === EfectividadV4Calculator::ENGINE_VERSION
                ? 'Motor V4 — cortes según Excel (fotografías diarias)'
                : 'Motor Aprobado — corte único (tus archivos de siempre)',
            'isPeriodica' => $run->isPeriodicaEngine(),
        ], Storage::disk('local')->path($relativePath));

        return $relativePath;
    }

    private function relativePath(EfectividadCobranzaRun $run): string
    {
        return "efectividad_cobranza/efectividad_run{$run->id}.pdf";
    }
}
