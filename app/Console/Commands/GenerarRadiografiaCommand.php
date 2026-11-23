<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\PeriodRadiographyExport;
use App\Services\EmployeeBranchAutoMatchService;
use App\Services\FinanciamientoMotosAssignmentService;
use App\Services\ImssFromNoiFiscalService;
use App\Services\PeriodConsolidationService;
use App\Services\PeriodDerivedDataCleaner;
use App\Services\PeriodRadiographyService;
use App\Services\RadiografiaExportService;
use App\Services\Radiography\RadiographySnapshotBuilder;
use App\Services\RotacionDerivedFromNoiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comando `reportes:generar-radiografia` — genera la Radiografía de un periodo de forma
 * SÍNCRONA (sin cola), con los mismos pasos que GenerateRadiographyJob: 1) limpia reportes
 * generados previos, 2) genera el PeriodSummary, 3) auto-asigna sucursales a empleados (solo si
 * falta alguna asignación o con `--force-automatch`), 3b) resuelve Financiamiento de
 * Motos/Cascos (falla si queda algo sin asignar), 4) consolida empleados, 4b) deriva Rotación e
 * IMSS desde NOI, 5) exporta Excel, 6) exporta PDF (no crítico) y 7) registra las exportaciones
 * para habilitar las descargas en el preview.
 *
 * Cuándo usarlo: para regenerar un periodo desde consola o cuando la cola no está disponible.
 * Escribe en BD: SÍ (period_summaries, employee_branch_assignments, fact_expenses de motos,
 * consolidación, fact_rotacion, fact_imss, period_radiography_exports) y archivos Excel/PDF.
 * Puede tardar varios minutos; sube memory_limit a 2 GB.
 *
 * Ejemplos:
 *   php artisan reportes:generar-radiografia 21
 *   php artisan reportes:generar-radiografia 21 --force-automatch
 */
class GenerarRadiografiaCommand extends Command
{
    protected $signature = 'reportes:generar-radiografia
                                {period_id : ID del periodo mensual}
                                {--force-automatch : Forzar auto-asignación aunque ya existan asignaciones}';

    protected $description = 'Genera la radiografía de un periodo de forma sincrónica (sin queue): summary, asignación, consolidación, Excel y PDF.';

    /**
     * @param  PeriodRadiographyService  $radiographyService  Genera el PeriodSummary.
     * @param  RadiografiaExportService  $exportService  Exporta Excel y PDF.
     * @param  PeriodConsolidationService  $consolidationService  Consolida empleados del periodo.
     * @param  PeriodDerivedDataCleaner  $cleaner  Limpia reportes generados previos.
     * @param  EmployeeBranchAutoMatchService  $branchAutoMatch  Asignación automática empleado → sucursal.
     * @param  FinanciamientoMotosAssignmentService  $motosAssignment  Asigna gastos de motos/cascos.
     * @param  RadiographySnapshotBuilder  $snapshotBuilder  Resuelve los periodos de datos.
     * @param  RotacionDerivedFromNoiService  $rotacionDerivedService  Deriva la rotación desde NOI.
     * @param  ImssFromNoiFiscalService  $imssDerivedService  Deriva el IMSS desde NOI Fiscal.
     */
    public function __construct(
        private readonly PeriodRadiographyService $radiographyService,
        private readonly RadiografiaExportService $exportService,
        private readonly PeriodConsolidationService $consolidationService,
        private readonly PeriodDerivedDataCleaner $cleaner,
        private readonly EmployeeBranchAutoMatchService $branchAutoMatch,
        private readonly FinanciamientoMotosAssignmentService $motosAssignment,
        private readonly RadiographySnapshotBuilder $snapshotBuilder,
        private readonly RotacionDerivedFromNoiService $rotacionDerivedService,
        private readonly ImssFromNoiFiscalService $imssDerivedService,
    ) {
        parent::__construct();
    }

    /**
     * Ejecuta los pasos 1–7 de la generación e imprime el avance de cada uno.
     *
     * @return int SUCCESS al terminar; FAILURE si el periodo no existe.
     */
    public function handle(): int
    {
        $periodId = (int) $this->argument('period_id');
        $period = Period::find($periodId);

        if (! $period) {
            $this->error("Periodo #{$periodId} no encontrado.");

            return self::FAILURE;
        }

        @ini_set('memory_limit', '2048M');
        @set_time_limit(0);

        $this->line('');
        $this->info("══════ GENERAR RADIOGRAFÍA — {$period->label} (ID #{$periodId}) ══════");
        $this->line('');

        $this->line('  1. Limpiando archivos y exports previos...');
        $this->cleaner->clearGeneratedReports($period);
        $this->line('  <fg=green>✓ Limpieza lista.</>');

        $this->line('  2. Generando summary (métricas de hecho)...');
        $summary = $this->radiographyService->generate($period, null);
        $this->line("  <fg=green>✓ Summary ID={$summary->id} generado.</>");

        $this->line('  3. Auto-asignando sucursales a empleados...');
        if ($this->shouldRunAutomatch($period->id)) {
            $this->branchAutoMatch->handle($period->id);
            $this->line('  <fg=green>✓ Auto-asignación ejecutada.</>');
        } else {
            $this->line('  <fg=green>✓ Auto-asignación omitida — todos los empleados ya tienen asignación. Usa --force-automatch para forzar.<//>');
        }

        $this->line('  3b. Resolviendo Financiamiento de Motos/Cascos (empleado → sucursal)...');
        $dataIds = $this->snapshotBuilder->resolveDataIdsPublic($period);
        $motosResults = $this->motosAssignment->assignForPeriodOrFail($period, $dataIds);
        $motosResueltos = count(array_filter($motosResults, fn (array $r) => $r['estado'] !== 'ya_asignado'));
        $this->line("  <fg=green>✓ {$motosResueltos} registro(s) nuevos resueltos (".count($motosResults).' en total, 0 sin asignar).</>');

        $this->line('  4. Consolidando empleados...');
        $this->consolidationService->consolidate($period);
        $this->line('  <fg=green>✓ Consolidación lista.</>');

        $this->line('  4b. Derivando Rotación e IMSS desde NOI (normal + fiscal)...');
        $rotationDerived = $this->rotacionDerivedService->deriveForPeriod($period);
        $imssDerived = $this->imssDerivedService->deriveForPeriod($period);
        $this->line(sprintf(
            '  <fg=green>✓ Rotación: %d altas / %d bajas / %d plantilla (%.2f%%). IMSS: %d colaboradores ($%s).</>',
            $rotationDerived['altas'],
            $rotationDerived['bajas'],
            $rotationDerived['plantilla'],
            $rotationDerived['indice'],
            $imssDerived['collaborators_included'],
            number_format($imssDerived['amount_included'], 2),
        ));

        $this->line('  5. Exportando Excel...');
        $path = $this->exportService->export($period);
        $this->line("  <fg=green>✓ Excel: {$path}</>");

        $this->line('  6. Exportando PDF...');
        try {
            $pdfPath = $this->exportService->exportPdf($period);
            $this->line("  <fg=green>✓ PDF: {$pdfPath}</>");
        } catch (\Throwable $e) {
            $this->warn('  ⚠ PDF falló (no crítico): '.mb_strimwidth($e->getMessage(), 0, 120));
            $pdfPath = null;
        }

        // ── 7. Registrar exportaciones (habilita los botones de descarga en el preview) ──
        // Sin este registro, MonthlyReportController::previewPage() nunca encuentra un
        // PeriodRadiographyExport y los botones de Excel/PDF quedan deshabilitados aunque
        // el archivo ya exista en disco — igual que hace GenerateRadiographyJob.
        $this->line('  7. Registrando exportaciones...');
        PeriodRadiographyExport::query()->create([
            'period_summary_id' => $summary->id,
            'export_path' => $path,
            'file_type' => 'excel',
            'template_version' => config('app.version'),
            'metadata' => ['period_id' => $period->id, 'period_label' => $period->label],
            'exported_at' => now(),
        ]);
        if ($pdfPath !== null) {
            PeriodRadiographyExport::query()->create([
                'period_summary_id' => $summary->id,
                'export_path' => $pdfPath,
                'file_type' => 'pdf',
                'template_version' => config('app.version'),
                'metadata' => ['period_id' => $period->id, 'period_label' => $period->label],
                'exported_at' => now(),
            ]);
        }
        $this->line('  <fg=green>✓ Exportaciones registradas — botones de descarga habilitados en el preview.</>');

        $this->line('');
        $this->info('══════ RADIOGRAFÍA GENERADA ══════');
        $this->line('  Ahora valida con: php artisan reportes:debug-radiografia '.$periodId);
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Indica si hace falta correr el automatch: true con `--force-automatch` o cuando hay empleados
     * en el NOI del periodo sin fila de asignación para ese periodo. Evita correr cientos de
     * consultas pesadas cuando todos ya están asignados (antes la generación se quedaba detenida
     * más de 20 minutos).
     *
     * @param  int  $periodId  ID del periodo (filtra NOI y asignaciones por este ID exacto).
     * @return bool true si debe ejecutarse la asignación automática.
     */
    private function shouldRunAutomatch(int $periodId): bool
    {
        if ($this->option('force-automatch')) {
            return true;
        }

        // Employees that appear in the period's NOI movements
        $periodEmployeeIds = DB::table('fact_noi_movements')
            ->where('period_id', $periodId)
            ->whereNotNull('employee_id')
            ->distinct()
            ->pluck('employee_id');

        if ($periodEmployeeIds->isEmpty()) {
            return false;
        }

        // Check how many of those employees lack an assignment for this period
        $unassigned = DB::table('employee_branch_assignments')
            ->where('period_id', $periodId)
            ->whereIn('employee_id', $periodEmployeeIds)
            ->count();

        // If every relevant employee already has an assignment row, skip automatch
        return $unassigned < $periodEmployeeIds->count();
    }
}
