<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\LendusEmployeeDirectory;
use App\Models\MonthlyEmployeeSummary;
use App\Models\NoiMovement;
use App\Models\Period;
use App\Models\PeriodBranchSummary;
use App\Models\PeriodCorporateSummary;
use App\Models\PeriodIncident;
use App\Models\PeriodRadiographyExport;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\Placement;
use App\Models\Portfolio;
use App\Models\Recovery;
use App\Models\ReportUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Limpia los datos derivados de un periodo o de una carga para poder reprocesar sin
 * duplicar: registros importados (fact_*), resúmenes consolidados y reportes generados
 * (Excel/PDF). Lo usan ReportUploadService (al reemplazar un archivo), el reproceso de
 * periodos (DatabaseUpdateService / --force-reset) y GenerateRadiographyJob.
 */
class PeriodDerivedDataCleaner
{
    /**
     * Borra TODOS los datos derivados de un periodo para reprocesarlo desde cero.
     *
     * Borra: movimientos NOI, colocación, recuperación, cartera, gastos, fact_rotacion,
     * resúmenes mensuales por colaborador, reportes generados (archivos y registros) y
     * los PeriodSummary con sus hijos (sucursal, corporativo, incidencias). Después pone
     * TODAS las cargas del periodo en status 'pending' para que se vuelvan a procesar.
     * NO toca: colaboradores, sucursales, usuarios, catálogos, corridas de auditoría ni
     * los archivos subidos. Escribe en el log.
     *
     * @param  Period  $period  Periodo a limpiar.
     */
    public function clearForPeriod(Period $period): void
    {
        Log::info('PeriodDerivedDataCleaner: clearForPeriod start', ['period_id' => $period->id]);

        // ── 1. Fact tables (raw imported data) ──────────────────────────────
        NoiMovement::query()->where('period_id', $period->id)->delete();
        Placement::query()->where('period_id', $period->id)->delete();
        Recovery::query()->where('period_id', $period->id)->delete();
        Portfolio::query()->where('period_id', $period->id)->delete();
        Expense::query()->where('period_id', $period->id)->delete();
        DB::table('fact_rotacion')->where('period_id', $period->id)->delete();

        // ── 2. Consolidated employee summaries ──────────────────────────────
        MonthlyEmployeeSummary::query()->where('period_id', $period->id)->delete();

        // ── 3. Generated reports (files + records) ──────────────────────────
        $this->clearGeneratedReports($period);

        // ── 4. Period summaries and children ────────────────────────────────
        $summaryIds = PeriodSummary::query()
            ->where('period_id', $period->id)
            ->pluck('id');

        if ($summaryIds->isNotEmpty()) {
            PeriodBranchSummary::query()->whereIn('period_summary_id', $summaryIds)->delete();
            PeriodCorporateSummary::query()->whereIn('period_summary_id', $summaryIds)->delete();
            PeriodIncident::query()->whereIn('period_summary_id', $summaryIds)->delete();
            PeriodSummary::query()->whereIn('id', $summaryIds)->delete();
        }

        // ── 5. Invalidate uploads so UI shows them as needing reprocess ─────
        ReportUpload::query()
            ->where('period_id', $period->id)
            ->update(['status' => 'pending']);

        Log::info('PeriodDerivedDataCleaner: clearForPeriod done', ['period_id' => $period->id]);
    }

    /**
     * Borra solo los registros importados por una carga (report_upload_id): NOI,
     * colocación, recuperación, cartera, gastos, directorio Lendus y fact_rotacion.
     *
     * Los resúmenes consolidados no se tocan (quedan desactualizados, pero sin
     * duplicar). Usar antes de volver a procesar una sola carga. Escribe en el log.
     *
     * @param  ReportUpload  $upload  Carga cuyos datos se eliminan.
     */
    public function clearForUpload(ReportUpload $upload): void
    {
        Log::info('PeriodDerivedDataCleaner: clearForUpload start', ['upload_id' => $upload->id]);

        NoiMovement::query()->where('report_upload_id', $upload->id)->delete();
        Placement::query()->where('report_upload_id', $upload->id)->delete();
        Recovery::query()->where('report_upload_id', $upload->id)->delete();
        Portfolio::query()->where('report_upload_id', $upload->id)->delete();
        Expense::query()->where('report_upload_id', $upload->id)->delete();
        LendusEmployeeDirectory::query()->where('report_upload_id', $upload->id)->delete();
        DB::table('fact_rotacion')->where('report_upload_id', $upload->id)->delete();

        Log::info('PeriodDerivedDataCleaner: clearForUpload done', ['upload_id' => $upload->id]);
    }

    /**
     * Borra solo los reportes generados del periodo: archivos Excel/PDF en el disco por
     * defecto y sus registros en period_radiography_exports (de todos los resúmenes).
     *
     * Deja intactos los datos importados y los resúmenes. Usar antes de regenerar una
     * radiografía sin reprocesar todo. Escribe en el log.
     *
     * @param  Period  $period  Periodo cuyos reportes se eliminan.
     */
    public function clearGeneratedReports(Period $period): void
    {
        $summaryIds = PeriodSummary::query()
            ->where('period_id', $period->id)
            ->pluck('id');

        if ($summaryIds->isEmpty()) {
            return;
        }

        $exports = PeriodRadiographyExport::query()
            ->whereIn('period_summary_id', $summaryIds)
            ->get();

        foreach ($exports as $export) {
            if ($export->export_path && Storage::exists($export->export_path)) {
                Storage::delete($export->export_path);
            }
        }

        PeriodRadiographyExport::query()
            ->whereIn('period_summary_id', $summaryIds)
            ->delete();

        Log::info('PeriodDerivedDataCleaner: clearGeneratedReports done', [
            'period_id' => $period->id,
            'deleted_files' => $exports->count(),
        ]);
    }

    /**
     * Borra solo las corridas, exportaciones y archivos previos con la MISMA identidad
     * exacta que se está (re)generando (period_id + report_type + scope +
     * comparison_period_id + branch_id + employee_id). Un reporte simple y un
     * comparativo mes vs mes del mismo periodo deben convivir — esto reemplaza la
     * antigua llamada a clearGeneratedReports() dentro de GenerateRadiographyJob, que
     * borraba todas las exportaciones del periodo sin importar el tipo de reporte.
     *
     * Efectos: borra archivos del disco, filas de period_radiography_exports y de
     * period_radiography_runs; escribe en el log.
     *
     * @param  Period  $period  Periodo del reporte.
     * @param  array  $identity  Claves report_type (default 'simple'), scope (default 'general'),
     *                           comparison_period_id, branch_id, employee_id (default null).
     * @param  int|null  $excludeRunId  Corrida actual que NO debe borrarse.
     */
    public function clearGeneratedReportsForIdentity(Period $period, array $identity, ?int $excludeRunId = null): void
    {
        $query = PeriodRadiographyRun::query()
            ->where('period_id', $period->id)
            ->where('report_type', $identity['report_type'] ?? 'simple')
            ->where('scope', $identity['scope'] ?? 'general')
            ->where('comparison_period_id', $identity['comparison_period_id'] ?? null)
            ->where('branch_id', $identity['branch_id'] ?? null)
            ->where('employee_id', $identity['employee_id'] ?? null);

        if ($excludeRunId) {
            $query->where('id', '!=', $excludeRunId);
        }

        $priorRuns = $query->get();

        if ($priorRuns->isEmpty()) {
            return;
        }

        $exports = PeriodRadiographyExport::query()
            ->whereIn('run_id', $priorRuns->pluck('id'))
            ->get();

        foreach ($exports as $export) {
            if ($export->export_path && Storage::exists($export->export_path)) {
                Storage::delete($export->export_path);
            }
        }

        PeriodRadiographyExport::query()->whereIn('run_id', $priorRuns->pluck('id'))->delete();
        PeriodRadiographyRun::query()->whereIn('id', $priorRuns->pluck('id'))->delete();

        Log::info('PeriodDerivedDataCleaner: clearGeneratedReportsForIdentity done', [
            'period_id' => $period->id,
            'identity' => $identity,
            'deleted_runs' => $priorRuns->count(),
            'deleted_files' => $exports->count(),
        ]);
    }
}
