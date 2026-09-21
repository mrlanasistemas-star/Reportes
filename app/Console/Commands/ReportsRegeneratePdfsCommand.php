<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\PeriodRadiographyExport;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Services\RadiografiaExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Regenera PDFs históricos con el motor Browsershot vigente, sin tocar Excel (retoma
 * 21-sep-2026, punto 4). Recorre PeriodRadiographyExport(file_type=pdf) — la MISMA
 * tabla que ya usa Histórico General — y reconstruye la configuración exacta de cada
 * PDF (general/sucursal/gestor/comparativo) desde su PeriodRadiographyRun asociado
 * (o desde el summary, para el PDF general sin run). Llama a los MISMOS métodos que
 * ya usan los controladores (RadiografiaExportService::exportPdf()/exportPdfWithConfig()),
 * nunca un camino de render paralelo.
 *
 * REGLA DE SEGURIDAD (obligatoria): el archivo nuevo se genera primero, en una ruta
 * nueva, y se valida (existe + tamaño > 0) ANTES de actualizar el puntero en BD. El
 * archivo viejo NUNCA se borra automáticamente — queda en disco como respaldo hasta
 * una limpieza manual explícita.
 *
 *   php artisan reports:regenerate-pdfs --period=21
 *   php artisan reports:regenerate-pdfs --run=145
 *   php artisan reports:regenerate-pdfs --all --dry-run
 *   php artisan reports:regenerate-pdfs --all --force
 */
class ReportsRegeneratePdfsCommand extends Command
{
    protected $signature = 'reports:regenerate-pdfs
        {--period= : Regenera todos los PDFs históricos de este periodo}
        {--run= : Regenera el PDF de un PeriodRadiographyRun específico}
        {--all : Regenera TODOS los PDFs históricos conocidos (todos los periodos)}
        {--dry-run : Solo lista qué se regeneraría, sin generar ni sobrescribir nada}
        {--force : Regenera aunque ya esté marcado con el motor Browsershot vigente}';

    protected $description = 'Regenera PDFs históricos con el motor Browsershot vigente, sin tocar Excel.';

    /** Marca de "ya regenerado con este motor" en PeriodRadiographyExport.template_version. */
    private const ENGINE_VERSION = 'browsershot-1';

    public function handle(RadiografiaExportService $service): int
    {
        $periodOpt = $this->option('period');
        $runOpt    = $this->option('run');
        $all       = (bool) $this->option('all');
        $dryRun    = (bool) $this->option('dry-run');
        $force     = (bool) $this->option('force');

        if (!$periodOpt && !$runOpt && !$all) {
            $this->error('Especifica --period=<id>, --run=<id> o --all.');

            return self::FAILURE;
        }

        $targets = $this->collectTargets($periodOpt, $runOpt, $all);

        $orphans = $targets->filter(fn ($t) => $t === null)->count();
        $targets = $targets->filter(fn ($t) => $t !== null)->values();

        if ($targets->isEmpty()) {
            $this->warn('No se encontraron PDFs históricos que coincidan con el filtro.');

            return self::SUCCESS;
        }

        if (!$force) {
            $before = $targets->count();
            $targets = $targets->reject(fn ($t) => $t['export']->template_version === self::ENGINE_VERSION)->values();
            $skipped = $before - $targets->count();
            if ($skipped > 0) {
                $this->line("Omitidos {$skipped} PDF(s) ya regenerados con el motor vigente (usa --force para repetirlos).");
            }
        }

        if ($orphans > 0) {
            $this->warn("{$orphans} export(s) de Histórico no se pudieron vincular a un periodo válido — omitidos.");
        }

        if ($targets->isEmpty()) {
            $this->info('Nada que regenerar.');

            return self::SUCCESS;
        }

        $this->table(
            ['Export ID', 'Periodo', 'Tipo', 'Alcance', 'Archivo actual'],
            $targets->map(fn ($t) => [
                $t['export']->id,
                $t['period']->label,
                $t['run']->report_type ?? 'simple',
                $this->scopeLabel($t['run']),
                $t['export']->export_path,
            ])->all(),
        );

        if ($dryRun) {
            $this->info('--dry-run: no se generó ni sustituyó ningún archivo. ' . $targets->count() . ' PDF(s) serían regenerados.');

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($targets as $target) {
            $label = $target['period']->label . ' · ' . ($target['run']->report_type ?? 'simple') . '/' . $this->scopeLabel($target['run']);

            try {
                $newPath = $this->regenerateOne($service, $target);

                if (!file_exists($newPath) || filesize($newPath) === 0) {
                    throw new \RuntimeException('El PDF regenerado quedó vacío o no se escribió en disco.');
                }

                $oldPath = $target['export']->export_path;

                $target['export']->update([
                    'export_path'      => $newPath,
                    'template_version' => self::ENGINE_VERSION,
                    'exported_at'      => now(),
                ]);

                if ($target['run'] && $target['run']->output_pdf_path) {
                    $target['run']->update(['output_pdf_path' => $newPath]);
                }

                $this->info("OK  {$label} → {$newPath} (archivo anterior conservado: {$oldPath})");
                $ok++;
            } catch (Throwable $e) {
                Log::error('reports:regenerate-pdfs: fallo regenerando un PDF histórico.', [
                    'export_id' => $target['export']->id,
                    'period_id' => $target['period']->id,
                    'exception' => get_class($e),
                    'message'   => $e->getMessage(),
                ]);
                $this->error("FALLO {$label}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->line("Regenerados: {$ok}  ·  Fallidos: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function scopeLabel(?PeriodRadiographyRun $run): string
    {
        if (!$run || !$run->scope || $run->scope === 'general') {
            return 'general';
        }

        return $run->scope . ($run->branch_id ? "#{$run->branch_id}" : ($run->employee_id ? "#{$run->employee_id}" : ''));
    }

    private function regenerateOne(RadiografiaExportService $service, array $target): string
    {
        $run = $target['run'];

        if (!$run) {
            return $service->exportPdf($target['period']);
        }

        $config = array_filter([
            'scope'              => $run->scope ?: 'general',
            'report_type'        => $run->report_type ?: 'simple',
            'branch_id'          => $run->branch_id,
            'employee_id'        => $run->employee_id,
            'compare_period_id'  => $run->comparison_period_id,
        ], fn ($v) => $v !== null);

        return $service->exportPdfWithConfig($target['period'], $config);
    }

    /** @return Collection<int, array{export: PeriodRadiographyExport, run: ?PeriodRadiographyRun, period: Period}|null> */
    private function collectTargets(?string $periodOpt, ?string $runOpt, bool $all): Collection
    {
        if ($runOpt) {
            $run = PeriodRadiographyRun::find((int) $runOpt);
            if (!$run) {
                $this->error("No existe el run #{$runOpt}.");

                return collect();
            }
            $export = PeriodRadiographyExport::where('run_id', $run->id)->where('file_type', 'pdf')->latest('id')->first();
            if (!$export) {
                $this->error("El run #{$run->id} no tiene un PDF registrado en Histórico.");

                return collect();
            }

            return collect([$this->buildTarget($export, $run)]);
        }

        if ($periodOpt) {
            $periodId = (int) $periodOpt;
            $summaryIds = PeriodSummary::where('period_id', $periodId)->pluck('id');

            $general = PeriodRadiographyExport::where('file_type', 'pdf')->whereNull('run_id')->whereIn('period_summary_id', $summaryIds)->get();
            $runIds  = PeriodRadiographyRun::where('period_id', $periodId)->pluck('id');
            $filtered = PeriodRadiographyExport::where('file_type', 'pdf')->whereIn('run_id', $runIds)->get();

            return $general->map(fn ($e) => $this->buildTarget($e, null))
                ->concat($filtered->map(fn ($e) => $this->buildTarget($e, PeriodRadiographyRun::find($e->run_id))));
        }

        // --all
        $general  = PeriodRadiographyExport::where('file_type', 'pdf')->whereNull('run_id')->get();
        $filtered = PeriodRadiographyExport::where('file_type', 'pdf')->whereNotNull('run_id')->get();

        return $general->map(fn ($e) => $this->buildTarget($e, null))
            ->concat($filtered->map(fn ($e) => $this->buildTarget($e, PeriodRadiographyRun::find($e->run_id))));
    }

    /** @return array{export: PeriodRadiographyExport, run: ?PeriodRadiographyRun, period: Period}|null */
    private function buildTarget(PeriodRadiographyExport $export, ?PeriodRadiographyRun $run): ?array
    {
        $period = null;

        if ($run) {
            $period = $run->period;
        }

        if (!$period && $export->period_summary_id) {
            $period = PeriodSummary::find($export->period_summary_id)?->period;
        }

        if (!$period && isset($export->metadata['period_id'])) {
            $period = Period::find($export->metadata['period_id']);
        }

        if (!$period) {
            return null;
        }

        return ['export' => $export, 'run' => $run, 'period' => $period];
    }
}
