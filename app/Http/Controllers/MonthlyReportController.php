<?php

namespace App\Http\Controllers;

use App\Enums\DataSourceCode;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\MonthlyEmployeeSummary;
use App\Models\Period;
use App\Models\PeriodRadiographyExport;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\Access\DataScopeService;
use App\Services\Access\ReportVisibilityService;
use App\Services\PeriodDerivedDataCleaner;
use App\Services\PeriodEmployeeRosterService;
use App\Services\PeriodRadiographyService;
use App\Services\RadiografiaExportService;
use App\Services\Radiography\EmployeesHistoricoExportService;
use App\Services\Radiography\RadiographySnapshotBuilder;
use App\Services\Reporting\OperativeBranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Módulo "Reportes mensuales" (Reportería): radiografía financiera de un
 * periodo (EBITDA, OPEX, colocación, recuperación, cartera, mora, nómina...).
 *
 * Responsabilidades:
 *   - Listar los reportes ya generados (PeriodRadiographyRun + respaldo de
 *     PeriodSummary heredados) con sus enlaces de ver/descargar.
 *   - Vista web interactiva del reporte (Preview.vue) y del comparativo entre
 *     dos periodos (ComparativePreview.vue), con datasets JSON por alcance
 *     (general / sucursal / colaborador).
 *   - Exportar Excel/PDF general y filtrado, CSV del consolidado y Excel de
 *     colaboradores; registrar las exportaciones en el Historial.
 *   - Consolidar el periodo, capturar el saldo inicial en caja y reportar el
 *     estado de las fuentes requeridas.
 *
 * Todas las cifras salen de RadiographySnapshotBuilder / RadiografiaExportService
 * (fuente única para Web, Excel y PDF). Cada acción aplica dos alcances: por
 * sucursal (DataScopeService) y por autor del reporte (ReportVisibilityService).
 */
class MonthlyReportController extends Controller
{
    /**
     * Las 13 sucursales operativas — fuente de verdad para el selector UI y validación.
     * Vive en OperativeBranchService (compartida con el módulo OKR) — nunca
     * duplicada aquí; esta constante solo re-expone el mismo arreglo para no
     * tocar los ~10 usos de `self::OPERATIVE_BRANCH_NAMES` en este archivo.
     */
    private const OPERATIVE_BRANCH_NAMES = OperativeBranchService::NAMES;

    /** Parte C4 del cierre (17-sep-2026) — lookups/datasets dinámicos nunca cacheables. */
    private const NO_STORE_HEADERS = ['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0', 'Pragma' => 'no-cache'];

    /** Mismas etiquetas que ReportConfigurationStep.vue (REPORT_TYPES) — no inventar otras. */
    private const REPORT_TYPE_LABELS = [
        'simple' => 'Radiografía simple',
        'month_vs_month' => 'Comparativo mes vs mes',
        'bimester_vs_bimester' => 'Comparativo bimestre vs bimestre',
        'quarter_vs_quarter' => 'Comparativo trimestre vs trimestre',
    ];

    /**
     * Lista de reportes generados (página Inertia ReportesMensuales/Index).
     *
     * Toma los runs exitosos, deduplica por identidad (se queda con el más
     * reciente), aplica el alcance por autor y por sucursal, calcula si cada
     * reporte sigue vigente o quedó invalidado (según su PeriodSummary) y arma
     * nombre, rango de fechas y URLs de Excel/PDF/vista. Agrega como respaldo los
     * PeriodSummary "generated" sin run simple/general (reportes heredados).
     *
     * @param  Request  $request  Petición del usuario autenticado.
     * @return Response Página con `generatedReports` (más reciente primero) y `visibility`.
     *
     * Solo lectura.
     */
    public function index(Request $request): Response
    {
        // Fuente: PeriodRadiographyRun (un row por reporte REALMENTE generado, con su
        // identidad propia), no PeriodSummary (un row por periodo) — así un simple y
        // un comparativo del mismo periodo aparecen como dos filas distintas, cada
        // una con su tipo/alcance real y sus propios enlaces de descarga.
        // Runs de antes de esta corrección no tenían identidad ni limpieza por
        // identidad — puede haber varios runs "success" históricos para la MISMA
        // identidad (mismo period_id/report_type/scope/...). Para esos casos, la
        // lista solo debe mostrar el más reciente de cada identidad, nunca todos
        // los duplicados históricos.
        $visibility = app(ReportVisibilityService::class);
        $visibilityMode = $visibility->mode($request->user());
        $runs = PeriodRadiographyRun::query()
            ->with([
                'period:id,name,custom_name,code,type,year,month,sequence,start_date,end_date',
                'comparisonPeriod:id,name,custom_name,code,type,year,month,sequence,start_date,end_date',
                'branch:id,name',
                'employee:id,full_name',
            ])
            ->where('status', 'success')
            // 27-oct-2026 — alcance por AUTOR: "Ver reportes propios" solo lista
            // los runs que generó este usuario (antes de deduplicar por identidad,
            // para que un run propio no lo tape uno más reciente de otro usuario);
            // sin permiso de ver, la lista sale vacía.
            ->when($visibilityMode === ReportVisibilityService::OWN, fn ($q) => $q->where('created_by', $request->user()->id))
            ->when($visibilityMode === ReportVisibilityService::NONE, fn ($q) => $q->whereRaw('1 = 0'))
            ->latest('finished_at')
            ->get()
            ->unique(fn (PeriodRadiographyRun $run) => implode('|', $run->identity()))
            ->values();

        // Fase 6.4 — alcance restringido: solo reportes por sucursal/gestor de SUS
        // sucursales; los generales (y los consolidados heredados) no se listan.
        // Se aplica ENCIMA del alcance por autor ("de todos" = de todos, en sus sucursales).
        $restricted = app(DataScopeService::class)->isRestricted($request->user());
        if ($restricted) {
            $runs = $runs->filter(fn (PeriodRadiographyRun $run) => $this->runWithinScope($request->user(), $run))->values();
        }

        // Comparativos inválidos heredados (23-nov-2026): antes se podía generar «mes vs
        // bimestre». Hoy el servidor lo rechaza; los que ya existían no se listan (siguen
        // en la BD, solo no se muestran porque sus cifras no son comparables).
        $runs = $runs->reject(fn (PeriodRadiographyRun $run) => $run->comparisonPeriod && $run->period
            && $run->comparisonPeriod->type !== $run->period->type)->values();

        $summaryIds = $runs->pluck('period_summary_id')->filter()->unique();
        $summaries = PeriodSummary::query()->whereIn('id', $summaryIds)->get()->keyBy('id');

        $generatedReports = $runs->map(function (PeriodRadiographyRun $run) use ($summaries) {
            $reportType = $run->report_type ?: 'simple';
            $scope = $run->scope ?: 'general';
            $isSimpleGeneral = $reportType === 'simple' && $scope === 'general';

            $summary = $run->period_summary_id ? $summaries->get($run->period_summary_id) : null;
            $status = (! $summary || $summary->status !== 'generated' || $summary->invalidated_at)
                ? 'invalidated' : 'generated';

            $name = self::REPORT_TYPE_LABELS[$reportType] ?? 'Radiografía';
            if ($reportType !== 'simple' && $run->comparisonPeriod) {
                $name = self::REPORT_TYPE_LABELS[$reportType].' — '.$run->comparisonPeriod->label.' vs '.$run->period?->label;
            } else {
                $name = 'Radiografía '.$run->period?->label;
            }
            if ($scope === 'branch' && $run->branch) {
                $name .= ' — '.$run->branch->name;
            } elseif ($scope === 'employee' && $run->employee) {
                $name .= ' — '.$run->employee->full_name;
            }

            return [
                'id' => $run->id,
                'name' => $name,
                'period_id' => $run->period_id,
                'period' => $run->period?->label,
                'period_code' => $run->period?->code,
                'period_type' => $run->period?->type,
                // Rango real de fechas del periodo (cierre 17-sep-2026, ronda 5 — pedido
                // explícito: la tarjeta debe decir de qué mes/semanas es, no solo el
                // nombre). Nunca se inventa: viene directo de periods.start_date/end_date.
                'period_start' => optional($run->period?->start_date)->format('d/m/Y'),
                'period_end' => optional($run->period?->end_date)->format('d/m/Y'),
                'comparison_period' => $run->comparisonPeriod?->label,
                'comparison_period_start' => optional($run->comparisonPeriod?->start_date)->format('d/m/Y'),
                'comparison_period_end' => optional($run->comparisonPeriod?->end_date)->format('d/m/Y'),
                'type' => self::REPORT_TYPE_LABELS[$reportType] ?? $reportType,
                'scope' => $scope,
                'scope_detail' => $scope === 'branch' ? $run->branch?->name : ($scope === 'employee' ? $run->employee?->full_name : null),
                'generated_at' => optional($run->finished_at)->format('d/m/Y H:i'),
                '_sort_ts' => optional($run->finished_at)->timestamp ?? 0,
                'generated_by' => $run->created_by,
                'status' => $status,
                'excel_url' => $isSimpleGeneral
                    ? route('reportes-mensuales.export-radiography', $run->period_id)
                    : route('reportes-mensuales.run-excel', $run->id),
                'pdf_url' => $isSimpleGeneral
                    ? route('reportes-mensuales.export-radiography-pdf', $run->period_id)
                    : route('reportes-mensuales.run-pdf', $run->id),
                'preview_url' => $isSimpleGeneral
                    ? route('reportes-mensuales.preview', $run->period_id)
                    : route('reportes-mensuales.run-ver', $run->id),
            ];
        })->values();

        // Periodos generados por una vía anterior a esta corrección (p. ej. el botón
        // de descarga directa de MonthlyReportController::exportRadiography, que
        // nunca crea un PeriodRadiographyRun) no tienen ningún run "simple/general"
        // asociado — sin este respaldo, desaparecerían de la lista aunque el reporte
        // exista de verdad. Se agregan como fila "simple/general" igual que antes.
        $periodIdsWithSimpleGeneralRun = $runs
            ->filter(fn (PeriodRadiographyRun $run) => ($run->report_type ?: 'simple') === 'simple' && ($run->scope ?: 'general') === 'general')
            ->pluck('period_id')->all();

        $legacySummaries = PeriodSummary::query()
            ->with(['period:id,name,custom_name,code,type,year,month,sequence,start_date,end_date'])
            ->where('status', 'generated')
            ->whereNotIn('period_id', $periodIdsWithSimpleGeneralRun)
            ->when($restricted, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($visibilityMode === ReportVisibilityService::OWN, fn ($q) => $q->where('generated_by', $request->user()->id))
            ->when($visibilityMode === ReportVisibilityService::NONE, fn ($q) => $q->whereRaw('1 = 0'))
            ->latest('generated_at')
            ->get()
            ->map(fn (PeriodSummary $summary) => [
                'id' => 'summary-'.$summary->id,
                'name' => 'Radiografía '.$summary->period?->label,
                'period_id' => $summary->period_id,
                'period' => $summary->period?->label,
                'period_code' => $summary->period?->code,
                'period_type' => $summary->period?->type,
                'period_start' => optional($summary->period?->start_date)->format('d/m/Y'),
                'period_end' => optional($summary->period?->end_date)->format('d/m/Y'),
                'comparison_period' => null,
                'comparison_period_start' => null,
                'comparison_period_end' => null,
                'type' => self::REPORT_TYPE_LABELS['simple'],
                'scope' => 'general',
                'scope_detail' => null,
                'generated_at' => optional($summary->generated_at)->format('d/m/Y H:i'),
                '_sort_ts' => optional($summary->generated_at)->timestamp ?? 0,
                'generated_by' => $summary->generated_by,
                'status' => $summary->invalidated_at ? 'invalidated' : 'generated',
                'excel_url' => route('reportes-mensuales.export-radiography', $summary->period_id),
                'pdf_url' => route('reportes-mensuales.export-radiography-pdf', $summary->period_id),
                'preview_url' => route('reportes-mensuales.preview', $summary->period_id),
            ]);

        $generatedReports = $generatedReports->concat($legacySummaries)
            ->sortByDesc('_sort_ts')
            ->map(fn (array $row) => Arr::except($row, ['_sort_ts']))
            ->values();

        return Inertia::render('ReportesMensuales/Index', [
            'message' => 'Consulta, previsualiza y descarga los reportes ya generados.',
            'generatedReports' => $generatedReports,
            'visibility' => $visibility->describe($request->user()),
        ]);
    }

    /**
     * Ajuste temporal de OPEX EFÍMERO (cierre 17-sep-2026, ronda 2) — modelo ÚNICO
     * `TemporaryOpexAdjustmentService::normalize()` es la fuente de verdad de qué
     * hace cada modo; este método SOLO parsea la request a la misma forma
     * canónica {mode, employee_id, branch_id, amount_per_employee, notes}. Nunca
     * persiste — si los parámetros no vienen o no son válidos, el snapshot es
     * exactamente el oficial de BD.
     *
     * Parámetros de request:
     *   manual_mode = employee | branch_each_employee | all_each_employee
     *   manual_employee_id       (requerido si mode=employee)
     *   manual_branch_id         (requerido si mode=branch_each_employee)
     *   manual_amount_per_employee
     *   manual_notes
     *
     * @param  Request  $request  Lee los parámetros de query string o del cuerpo.
     * @return array Ajuste normalizado, o [] si el monto es <= 0, el modo no es
     *               válido o falta el empleado/sucursal que el modo exige.
     */
    private function manualAdjustmentFromRequest(Request $request): array
    {
        $amount = (float) $request->query('manual_amount_per_employee', $request->input('manual_amount_per_employee', 0));
        if ($amount <= 0) {
            return [];
        }

        $mode = $request->query('manual_mode', $request->input('manual_mode', ''));
        if (! in_array($mode, ['employee', 'branch_each_employee', 'all_each_employee'], true)) {
            return [];
        }

        $notes = (string) $request->query('manual_notes', $request->input('manual_notes', ''));

        if ($mode === 'employee') {
            $employeeId = (int) $request->query('manual_employee_id', $request->input('manual_employee_id', 0));
            if (! $employeeId) {
                return [];
            }

            return ['mode' => 'employee', 'employee_id' => $employeeId, 'branch_id' => null, 'amount_per_employee' => round($amount, 2), 'notes' => $notes];
        }

        if ($mode === 'branch_each_employee') {
            $branchId = (int) $request->query('manual_branch_id', $request->input('manual_branch_id', 0));
            if (! $branchId) {
                return [];
            }

            return ['mode' => 'branch_each_employee', 'employee_id' => null, 'branch_id' => $branchId, 'amount_per_employee' => round($amount, 2), 'notes' => $notes];
        }

        return ['mode' => 'all_each_employee', 'employee_id' => null, 'branch_id' => null, 'amount_per_employee' => round($amount, 2), 'notes' => $notes];
    }

    /**
     * Registra en el Historial (PeriodRadiographyRun + PeriodRadiographyExport) un
     * Excel/PDF generado por exportFilteredRadiography()/exportFilteredRadiographyPdf()
     * — comparativo mes vs mes/gestor y export filtrado por sucursal/gestor descargaban
     * el archivo correcto pero nunca quedaban registrados (bug real: "descarga y listo",
     * nunca aparecían en Reportes mensuales → Histórico). Reutiliza la MISMA identidad
     * (period_id/report_type/scope/branch_id/employee_id/comparison_period_id) que ya usa
     * GenerateRadiographyJob, así que un comparativo generado aquí y otro generado por el
     * job encolado son indistinguibles para index()/viewRun()/downloadRunExcel().
     *
     * Reutiliza el MISMO run para Excel y PDF de una misma identidad (búsqueda por
     * identidad, no por id) — descargar primero el Excel y después el PDF (o viceversa,
     * en pestañas o momentos distintos) termina en una sola fila de historial con ambos
     * archivos, nunca dos filas parciales que se pisen entre sí.
     *
     * RESILIENCIA (retoma 21-sep-2026, puntos 9/11/14/15): este método corre DESPUÉS de
     * que el archivo (Excel/PDF) ya existe en disco. Persistir su metadata en
     * PeriodRadiographyRun/PeriodRadiographyExport es un proceso DISTINTO de haber
     * generado el archivo — un error de BD aquí (columna, constraint, deadlock) NUNCA
     * debe convertir una descarga que ya funcionó en un 500. Por eso TODO el cuerpo está
     * envuelto en try/catch: si falla, se loguea y el caller sigue directo a
     * response()->download() con el archivo ya generado. El único costo es que esa
     * descarga concreta no queda registrada en Histórico — mejor que perder la descarga.
     *
     * @param  Period  $period  Periodo del reporte exportado.
     * @param  array  $config  Configuración del export (scope, report_type, branch_id,
     *                         employee_id, compare_period_id, manual_adjustment).
     * @param  string  $fileType  "excel" o "pdf" (define la columna output_{tipo}_path).
     * @param  string  $path  Ruta absoluta del archivo ya generado.
     * @param  PeriodDerivedDataCleaner  $cleaner  Borra versiones anteriores de la misma identidad.
     *
     * Efectos: crea/actualiza period_radiography_runs y period_radiography_exports
     * y limpia runs previos de la identidad. Nunca lanza: los errores se registran en el log.
     */
    private function persistFilteredRunExport(Period $period, array $config, string $fileType, string $path, PeriodDerivedDataCleaner $cleaner): void
    {
        try {
            $identity = [
                'period_id' => $period->id,
                'report_type' => $config['report_type'] ?? 'simple',
                'scope' => $config['scope'] ?? 'general',
                'branch_id' => ! empty($config['branch_id']) ? (int) $config['branch_id'] : null,
                'employee_id' => ! empty($config['employee_id']) ? (int) $config['employee_id'] : null,
                'comparison_period_id' => ! empty($config['compare_period_id']) ? (int) $config['compare_period_id'] : null,
            ];

            $run = PeriodRadiographyRun::query()->forIdentity($identity)->latest('id')->first()
                ?? new PeriodRadiographyRun($identity);

            $summary = PeriodSummary::query()
                ->where('period_id', $period->id)
                ->where('status', 'generated')
                ->whereNull('invalidated_at')
                ->latest('id')
                ->first();

            $run->fill(array_merge($identity, [
                'status' => 'success',
                'period_summary_id' => $summary?->id ?? $run->period_summary_id,
                'started_at' => $run->started_at ?? now(),
                'finished_at' => now(),
                'log' => 'Radiografía generada. Excel y PDF listos para descargar.',
                'created_by' => $run->created_by ?? auth()->id(),
            ]));
            $run->{"output_{$fileType}_path"} = $path;
            $run->save();

            PeriodRadiographyExport::query()->updateOrCreate(
                ['run_id' => $run->id, 'file_type' => $fileType],
                [
                    'period_summary_id' => $run->period_summary_id,
                    'export_path' => $path,
                    'template_version' => config('app.version'),
                    'metadata' => ['period_id' => $period->id, 'period_label' => $period->label, 'config' => $config],
                    'exported_at' => now(),
                    'exported_by' => auth()->id(),
                ],
            );

            try {
                $cleaner->clearGeneratedReportsForIdentity($period, $identity, excludeRunId: $run->id);
            } catch (\Throwable $cleanupException) {
                Log::warning('MonthlyReportController: no se pudo limpiar versiones anteriores de esta identidad tras exportar (el archivo nuevo sigue siendo válido).', [
                    'period_id' => $period->id,
                    'run_id' => $run->id,
                    'identity' => $identity,
                    'exception' => get_class($cleanupException),
                    'message' => $cleanupException->getMessage(),
                ]);
            }
        } catch (\Throwable $persistException) {
            Log::error('MonthlyReportController: no se pudo registrar el historial de este export (el archivo generado sigue siendo válido y descargable).', [
                'period_id' => $period->id,
                'config' => $config,
                'file_type' => $fileType,
                'path' => $path,
                'exception' => get_class($persistException),
                'message' => $persistException->getMessage(),
            ]);
        }
    }

    /**
     * Resuelve el export de un run específico por su tipo de archivo — nunca cae al
     * reporte simple del periodo, aunque el run pedido sea comparativo/por sucursal.
     *
     * Usa el export más reciente del tipo pedido y, si no hay, la ruta guardada en el run.
     *
     * @param  PeriodRadiographyRun  $run  Reporte generado.
     * @param  string  $fileType  "excel" o "pdf".
     * @return string Ruta absoluta del archivo en disco.
     *
     * Lanza 404 si el archivo no existe o está vacío.
     */
    private function resolveRunExportPath(PeriodRadiographyRun $run, string $fileType): string
    {
        $export = $run->exports()->where('file_type', $fileType)->latest('id')->first();
        $path = $export?->export_path ?? ($fileType === 'excel' ? $run->output_excel_path : $run->output_pdf_path);

        abort_unless($path && file_exists($path) && filesize($path) > 0, 404,
            'El archivo generado para este reporte ya no está disponible. Vuelve a generarlo desde Histórico General.');

        return $path;
    }

    /**
     * Descarga el Excel de un reporte generado específico (comparativo, por
     * sucursal o por gestor), sin caché.
     *
     * @param  PeriodRadiographyRun  $run  Reporte a descargar.
     * @return BinaryFileResponse
     *
     * Lanza 403 si el run está fuera del alcance del usuario y 404 si el archivo ya no existe.
     */
    public function downloadRunExcel(PeriodRadiographyRun $run)
    {
        $this->authorizeRun($run);
        $path = $this->resolveRunExportPath($run, 'excel');

        return response()->download($path, basename($path), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Descarga el PDF de un reporte generado específico, sin caché.
     *
     * @param  PeriodRadiographyRun  $run  Reporte a descargar.
     * @return BinaryFileResponse
     *
     * Lanza 403 si el run está fuera del alcance del usuario y 404 si el archivo ya no existe.
     */
    public function downloadRunPdf(PeriodRadiographyRun $run)
    {
        $this->authorizeRun($run);
        $path = $this->resolveRunExportPath($run, 'pdf');

        return response()->download($path, basename($path), [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * "Ver" de un run específico. Simple+general reutiliza la vista web completa ya
     * existente; comparativo renderiza la misma plantilla del PDF comparativo como
     * página web normal (sin Browsershot) — reutiliza exactamente los mismos datos que el
     * PDF, así que nunca se desincroniza de lo que se descarga.
     *
     * Nota: hoy todos los casos redirigen — simple/general a la vista web del
     * periodo, comparativos a comparativoPage() y por sucursal/gestor a
     * previewPage() con su filtro.
     *
     * @param  PeriodRadiographyRun  $run  Reporte a visualizar.
     * @return RedirectResponse
     *
     * Lanza 403 si el run está fuera del alcance del usuario.
     */
    public function viewRun(PeriodRadiographyRun $run)
    {
        $this->authorizeRun($run);
        $reportType = $run->report_type ?: 'simple';
        $scope = $run->scope ?: 'general';

        if ($reportType === 'simple' && $scope === 'general') {
            return redirect()->route('reportes-mensuales.preview', $run->period_id);
        }

        // Bug real corregido (Parte B, cierre 17-sep-2026): esto renderizaba la MISMA
        // plantilla del PDF (reports.radiography-pdf-comparative) como página web — la
        // Web se veía literalmente como el PDF, sin selectores ni gráficas. Ahora
        // redirige a la vista Inertia interactiva (ComparativePreview.vue), cargando
        // inicialmente EXACTAMENTE el period/comparison_period/scope de este run
        // (B14) — cambiar de periodo después es solo visualización, nunca crea un
        // run nuevo. El PDF/Excel comparativos (exportPdfWithConfig/exportWithConfig,
        // reports.radiography-pdf-comparative) NO se tocan — siguen siendo la fuente
        // de descarga real.
        if (in_array($reportType, ['month_vs_month', 'bimester_vs_bimester', 'quarter_vs_quarter'], true)) {
            return redirect()->route('reportes-mensuales.comparativo', array_filter([
                'period_a' => $run->period_id,
                'period_b' => $run->comparison_period_id,
                'scope' => $scope !== 'general' ? $scope : null,
                'branch_id' => $run->branch_id,
                'employee_id' => $run->employee_id,
            ]));
        }

        // Por sucursal / por gestor (simple, sin comparativo): la vista web completa
        // ya soporta filtrar por scope/branch_id/employee_id vía query string.
        return redirect()->route('reportes-mensuales.preview', array_filter([
            'period' => $run->period_id,
            'scope' => $scope,
            'branch_id' => $run->branch_id,
            'employee_id' => $run->employee_id,
        ]));
    }

    /**
     * Ruta heredada: redirige al listado de reportes mensuales con el periodo en la query.
     *
     * @param  Period  $period  Periodo solicitado.
     */
    public function show(Period $period): RedirectResponse
    {
        return redirect()->route('reportes-mensuales.index', ['period' => $period->id]);
    }

    /**
     * Vista web completa de la radiografía de un periodo (ReportesMensuales/Preview).
     *
     * Construye el snapshot GENERAL (alimenta los selectores de sucursal y
     * gestor) y, si la URL trae ?scope=branch|employee, también el snapshot ya
     * filtrado para evitar el parpadeo del general en un F5. Un usuario
     * restringido nunca ve el general: sin alcance en la URL se le redirige a
     * su primera sucursal. También indica si ya existen Excel/PDF exportados y
     * envía las URLs de los endpoints AJAX (datos por alcance, exportaciones
     * filtradas, saldo inicial).
     *
     * @param  Period  $period  Periodo a visualizar.
     * @param  Request  $request  scope, branch_id y employee_id opcionales (deep-link).
     * @param  RadiographySnapshotBuilder  $snapshotBuilder  Arma el snapshot general desde el PeriodSummary vigente.
     * @param  RadiografiaExportService  $exportService  Arma el snapshot proyectado a un alcance.
     * @return Response|RedirectResponse Página Inertia, o redirección a la vista por sucursal (restringidos).
     *
     * Lanza 403 por alcance de sucursal o de autor. Snapshot nulo si el periodo no
     * tiene consolidado vigente. Solo lectura.
     */
    public function previewPage(Period $period, Request $request, RadiographySnapshotBuilder $snapshotBuilder, RadiografiaExportService $exportService): Response|RedirectResponse
    {
        // Fase 6.4 — alcance restringido: nunca la radiografía general. Sin alcance en
        // la URL se abre directamente la vista de su (primera) sucursal; un gestor
        // ajeno → 403 (una sucursal ajena ya la rechaza EnsureBranchScope).
        $dataScope = app(DataScopeService::class);
        $restricted = $dataScope->isRestricted($request->user());
        if ($restricted) {
            $scopeParam = $request->query('scope');
            if ($scopeParam === 'employee' && (int) $request->query('employee_id', 0)) {
                $dataScope->authorizeEmployee($request->user(), (int) $request->query('employee_id'), $period->id);
            } elseif ($scopeParam !== 'branch' || ! (int) $request->query('branch_id', 0)) {
                return redirect()->route('reportes-mensuales.preview', [
                    'period' => $period->id, 'scope' => 'branch',
                    'branch_id' => $dataScope->resolveBranch($request->user(), null),
                ]);
            }
        }
        // 27-oct-2026 — alcance por AUTOR (después del de sucursal).
        $visibility = app(ReportVisibilityService::class);
        $previewScope = in_array($request->query('scope'), ['branch', 'employee'], true) ? $request->query('scope') : 'general';
        $visibility->authorizePeriodReport($request->user(), $period->id, $previewScope,
            (int) $request->query('branch_id', 0) ?: null, (int) $request->query('employee_id', 0) ?: null);

        $summary = PeriodSummary::query()
            ->with(['branchSummaries', 'incidents'])
            ->where('period_id', $period->id)
            ->where('status', 'generated')
            ->whereNull('invalidated_at')
            ->first();

        $run = PeriodRadiographyRun::query()
            ->where('period_id', $period->id)
            ->whereIn('status', ['success'])
            ->latest('id')
            ->first();

        $hasExcelExport = false;
        $hasPdfExport = false;
        $snapshot = null;   // snapshot GENERAL — nunca se filtra, alimenta los selectores
        $initialSnapshot = null;  // lo que realmente se manda a la página (general o ya-filtrado)
        $initialScope = null;

        if ($summary) {
            $excelExport = PeriodRadiographyExport::query()
                ->where('period_summary_id', $summary->id)
                ->where('file_type', 'excel')
                ->latest('id')
                ->first();
            $pdfExport = PeriodRadiographyExport::query()
                ->where('period_summary_id', $summary->id)
                ->where('file_type', 'pdf')
                ->latest('id')
                ->first();
            $hasExcelExport = $excelExport && is_string($excelExport->export_path) && File::exists($excelExport->export_path);
            $hasPdfExport = $pdfExport && is_string($pdfExport->export_path) && File::exists($pdfExport->export_path);

            // Build full GENERAL snapshot (single source of truth) — SIEMPRE sin scope,
            // porque de aquí salen las listas de sucursales/gestores del selector.
            $snapshot = $snapshotBuilder->build($period, $summary);

            // Deep-link (?scope=branch|employee&branch_id=/employee_id=): si la URL ya trae
            // un alcance válido, la primera respuesta del servidor entrega directamente el
            // reporte filtrado — sin esto, un F5 con filtro activo mostraría un parpadeo del
            // reporte general antes de que el frontend pida el dataset filtrado por AJAX.
            $scopeParam = $request->query('scope');
            if (in_array($scopeParam, ['branch', 'employee'], true)) {
                $config = ['scope' => $scopeParam];
                if ($scopeParam === 'branch') {
                    $config['branch_id'] = (int) $request->query('branch_id', 0);
                } else {
                    $config['employee_id'] = (int) $request->query('employee_id', 0);
                }
                try {
                    $initialSnapshot = $exportService->buildSnapshot($period, $config);
                    $initialScope = $initialSnapshot['scope'] ?? null;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        // Restringido: solo el snapshot ya filtrado; nunca cae al general si falló.
        $activeSnapshot = $restricted ? $initialSnapshot : ($initialSnapshot ?? $snapshot);

        // Operative branches: hardcoded constant — never shows routes or non-op branches
        $operativeBranches = Branch::query()
            ->whereIn('name', self::OPERATIVE_BRANCH_NAMES)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])
            ->values();

        // Gestores: from snapshot employees_gestores (has per-gestor metrics)
        $employees = collect();
        if ($snapshot) {
            $gestores = $snapshot['sections']['employees_gestores'] ?? [];
            // Build list: prefer employees with actual colocacion or recuperacion data
            $employees = collect($gestores)
                ->sortBy('name')
                ->map(fn ($g) => [
                    'id' => 0,            // will be resolved below
                    'name' => $g['name'],
                    'branch' => $g['branch'] ?? '',
                    'recuperacion' => (float) ($g['recuperacion'] ?? 0),
                    'colocacion' => (float) ($g['colocacion'] ?? 0),
                ])
                ->values();

            // Resolve DB employee IDs (for download endpoint)
            $allEmpIds = Employee::query()
                ->orderBy('full_name')
                ->get(['id', 'full_name'])
                ->keyBy(fn ($e) => strtoupper(trim($e->full_name)));

            $employees = $employees->map(function ($e) use ($allEmpIds) {
                $key = strtoupper(trim($e['name']));
                $dbEmp = $allEmpIds->get($key);

                return [
                    'id' => $dbEmp?->id ?? 0,
                    'name' => $e['name'],
                    'branch' => $e['branch'],
                ];
            })->filter(fn ($e) => $e['id'] > 0)->values();

            if ($employees->isEmpty()) {
                $employees = Employee::query()->orderBy('full_name')
                    ->get(['id', 'full_name'])
                    ->map(fn ($e) => ['id' => $e->id, 'name' => $e->full_name, 'branch' => ''])
                    ->values();
            }

            // Fase 6.4 — el selector de gestores solo ofrece los de sus sucursales.
            if ($restricted) {
                $employees = $employees->filter(fn ($e) => $dataScope->allowsBranchName($request->user(), $e['branch'] ?? null))->values();
            }
        }

        if ($restricted) {
            $operativeBranches = collect($dataScope->filterBranchOptions($request->user(), $operativeBranches));
        }

        // All available periods for compare selectors (with snapshot flag for comparativo protection)
        $allPeriods = $this->allPeriodsForSelector($request->user());

        return Inertia::render('ReportesMensuales/Preview', [
            'period' => [
                'id' => $period->id,
                'label' => $period->label,
                'code' => $period->code,
                'type' => $period->type,
                'start_date' => optional($period->start_date)->format('Y-m-d'),
                'end_date' => optional($period->end_date)->format('Y-m-d'),
            ],
            'snapshot' => $activeSnapshot,
            'initialScope' => $initialScope,
            'run' => $run ? [
                'status' => $run->status,
                'started_at' => optional($run->started_at)->format('d/m/Y H:i'),
                'finished_at' => optional($run->finished_at)->format('d/m/Y H:i'),
            ] : null,
            'hasExcelExport' => $hasExcelExport,
            'hasPdfExport' => $hasPdfExport,
            'excelUrl' => route('reportes-mensuales.export-radiography', $period->id),
            'pdfUrl' => route('reportes-mensuales.export-radiography-pdf', $period->id),
            'branches' => $operativeBranches,
            'employees' => $employees,
            'allPeriods' => $allPeriods,
            'scopedDataUrl' => route('reportes-mensuales.scoped-data', $period->id),
            'filteredExcelBaseUrl' => route('reportes-mensuales.export-filtered-radiography', $period->id),
            'filteredPdfBaseUrl' => route('reportes-mensuales.export-filtered-radiography-pdf', $period->id),
            'updateSaldoInicialUrl' => route('reportes-mensuales.update-saldo-inicial', $period->id),
        ]);
    }

    /**
     * Compartido por previewPage()/comparativoPage() — lista de periodos para selectores,
     * cada uno con `has_snapshot` (tiene radiografía generada). Con "Ver reportes
     * propios" solo incluye los periodos donde el usuario generó algún reporte.
     *
     * @param  User|null  $user  Usuario para filtrar por visibilidad; null = todos.
     * @return Collection<int, array{id:int,label:string,code:?string,type:string,has_snapshot:bool}>
     */
    private function allPeriodsForSelector(?User $user = null): Collection
    {
        $periodsWithSnap = PeriodSummary::where('status', 'generated')->pluck('period_id')->flip();
        // 27-oct-2026 — con "Ver reportes propios" solo se ofrecen los periodos de los que generó algún reporte.
        $visibleIds = $user ? app(ReportVisibilityService::class)->visiblePeriodIds($user) : null;

        return Period::query()
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
            ->orderByDesc('year')->orderByDesc('month')->orderByDesc('sequence')
            ->get(['id', 'name', 'custom_name', 'code', 'type', 'year', 'month'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'label' => $p->label,
                'code' => $p->code,
                'type' => $p->type,
                'has_snapshot' => $periodsWithSnap->has($p->id),
            ])
            ->values();
    }

    /** Mismo mapeo type→report_type que ya usan comparePeriodOptions (Preview.vue)/config del run. */
    private const PERIOD_TYPE_TO_COMPARATIVE_REPORT_TYPE = [
        'monthly' => 'month_vs_month',
        'bimonthly' => 'bimester_vs_bimester',
        'quarterly' => 'quarter_vs_quarter',
    ];

    /**
     * Vista web INTERACTIVA del comparativo (Parte B del cierre, 17-sep-2026).
     *
     * ANTES: MonthlyReportController::viewRun() reutilizaba la plantilla PDF
     * (reports.radiography-pdf-comparative) como página web — bug real confirmado en
     * código: la Web se veía literalmente como el PDF, sin selectores interactivos,
     * gráficas ni forma de comparar otro periodo sin regenerar un run.
     *
     * AHORA: esta es una página Inertia/Vue real (ComparativePreview.vue). Nunca
     * calcula cifras propias — TODO dato sale de
     * RadiografiaExportService::comparativeViewData(), la MISMA fuente que ya usan el
     * Excel y el PDF comparativos (que NO se tocan en esta sesión — B1/B3 del
     * pendiente). Los selectores de periodo/alcance viven en el cliente y piden
     * comparativoData() vía JSON — cambiar de periodo aquí NUNCA crea un
     * PeriodRadiographyRun ni genera archivos (B14).
     *
     * Sin period_a/period_b toma los dos meses con radiografía más recientes.
     *
     * @param  Request  $request  period_a (actual), period_b (comparación), scope,
     *                            branch_id y employee_id opcionales.
     * @return Response Página Inertia con periodos, sucursales y URLs de datos.
     *
     * Lanza 403 por alcance de sucursal (en ambos periodos si es por colaborador) o de autor.
     */
    public function comparativoPage(Request $request): Response
    {
        $allPeriods = $this->allPeriodsForSelector($request->user());

        $periodAId = (int) $request->query('period_a', 0) ?: null;
        $periodBId = (int) $request->query('period_b', 0) ?: null;
        $scope = $request->query('scope', 'general');
        $branchId = (int) $request->query('branch_id', 0) ?: null;
        $employeeId = (int) $request->query('employee_id', 0) ?: null;

        // Sin deep-link todavía: default razonable — los dos periodos MENSUALES con
        // radiografía generada más recientes (mes actual vs mes anterior).
        if (! $periodAId || ! $periodBId) {
            $monthlyWithSnapshot = $allPeriods->where('type', 'monthly')->where('has_snapshot', true)->values();
            $periodAId ??= (int) ($monthlyWithSnapshot[0]['id'] ?? 0) ?: null;
            $periodBId ??= (int) ($monthlyWithSnapshot[1]['id'] ?? 0) ?: null;
        }

        // Periodo B inválido (de otro tipo, igual a A o inexistente, p. ej. un enlace a un
        // comparativo heredado «mes vs bimestre»): se usa el periodo anterior del MISMO tipo
        // que A con radiografía, en lugar de dejar la pantalla vacía.
        $typeOf = fn (?int $id) => $id ? ($allPeriods->firstWhere('id', $id)['type'] ?? null) : null;
        if ($periodAId && ($periodBId === $periodAId || $typeOf($periodBId) !== $typeOf($periodAId))) {
            $sameType = $allPeriods->where('type', $typeOf($periodAId))->where('has_snapshot', true)->values();
            $idx = $sameType->search(fn ($p) => (int) $p['id'] === $periodAId);
            $next = $idx === false ? null : $sameType->get($idx + 1);
            $prev = $idx === false ? null : ($idx > 0 ? $sameType->get($idx - 1) : null);
            $periodBId = (int) (($next ?? $prev)['id'] ?? 0) ?: null;
        }

        $operativeBranches = Branch::query()
            ->whereIn('name', self::OPERATIVE_BRANCH_NAMES)
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])
            ->values();

        // Fase 6.4 — alcance restringido: sin comparativo general; su sucursal por defecto.
        $dataScope = app(DataScopeService::class);
        if ($dataScope->isRestricted($request->user())) {
            $operativeBranches = collect($dataScope->filterBranchOptions($request->user(), $operativeBranches));
            if ($scope === 'employee' && $employeeId) {
                $dataScope->authorizeEmployee($request->user(), $employeeId, $periodAId);
                if ($periodBId && $periodBId !== $periodAId) {
                    $this->authorizeEmployeeInComparePeriod($request->user(), $employeeId, $periodBId);
                }
            } elseif ($scope !== 'employee') {
                $scope = 'branch';
                $branchId = $dataScope->resolveBranch($request->user(), $branchId);
            }
        }

        // 27-oct-2026 — alcance por AUTOR: un enlace directo a un comparativo ajeno → 403.
        $visibility = app(ReportVisibilityService::class);
        abort_unless($visibility->canViewAny($request->user()), 403, $visibility->deniedMessage($request->user()));
        if ($request->query('period_a') && $request->query('period_b') && $periodAId && $periodBId) {
            $visibility->authorizePeriodReport($request->user(), $periodAId, $scope, $branchId, $employeeId, $periodBId);
        }

        return Inertia::render('ReportesMensuales/ComparativePreview', [
            'initialPeriodAId' => $periodAId,
            'initialPeriodBId' => $periodBId,
            'initialScope' => in_array($scope, ['general', 'branch', 'employee'], true) ? $scope : 'general',
            'initialBranchId' => $branchId,
            'initialEmployeeId' => $employeeId,
            'periods' => $allPeriods,
            'branches' => $operativeBranches,
            'comparativeDataUrl' => route('reportes-mensuales.comparativo-data'),
            'employeesLookupUrl' => route('reportes-mensuales.comparativo-employees-lookup'),
        ]);
    }

    /**
     * Dataset JSON del comparativo — consumido por ComparativePreview.vue (B5/B13: cambiar
     * de periodo/alcance NO recarga la página, pide este JSON). ÚNICA fuente de cifras:
     * RadiografiaExportService::comparativeViewData(), la misma que alimenta el PDF/Excel
     * comparativo — así Web/Excel/PDF nunca pueden divergir (B3/B16 del pendiente).
     *
     * Valida que ambos periodos existan, sean distintos y del mismo tipo
     * (mensual, bimestral o trimestral), y que la sucursal sea operativa o el
     * colaborador exista.
     *
     * @param  Request  $request  period_a, period_b, scope (general|branch|employee), branch_id, employee_id.
     * @param  RadiografiaExportService  $exportService  Calcula las filas del comparativo.
     * @return JsonResponse Filas, etiquetas y URLs de export (200), o `{error}` con 422/404.
     *
     * Lanza 403 por alcance. Sin caché.
     */
    public function comparativoData(Request $request, RadiografiaExportService $exportService): JsonResponse
    {
        $periodAId = (int) $request->query('period_a', 0);
        $periodBId = (int) $request->query('period_b', 0);
        $scope = $request->query('scope', 'general');

        if (! $periodAId || ! $periodBId) {
            return response()->json(['error' => 'Selecciona los dos periodos a comparar.'], 422);
        }
        if ($periodAId === $periodBId) {
            return response()->json(['error' => 'Selecciona dos periodos distintos.'], 422);
        }
        if (! in_array($scope, ['general', 'branch', 'employee'], true)) {
            return response()->json(['error' => 'Alcance no válido. Usa general, branch o employee.'], 422);
        }

        $periodA = Period::find($periodAId);
        $periodB = Period::find($periodBId);
        if (! $periodA || ! $periodB) {
            return response()->json(['error' => 'Uno de los periodos seleccionados no existe.'], 404);
        }

        // B6: nunca permitir comparar tipos distintos (ej. un mensual contra un
        // bimestre) — ni desde el cliente ni si alguien golpea el endpoint directo.
        if ($periodA->type !== $periodB->type || ! isset(self::PERIOD_TYPE_TO_COMPARATIVE_REPORT_TYPE[$periodA->type])) {
            return response()->json([
                'error' => 'Los dos periodos deben ser del mismo tipo (ambos mensuales, ambos bimestrales o ambos trimestrales).',
            ], 422);
        }
        $reportType = self::PERIOD_TYPE_TO_COMPARATIVE_REPORT_TYPE[$periodA->type];

        $config = ['scope' => $scope, 'report_type' => $reportType, 'compare_period_id' => $periodB->id];

        $this->authorizeReportScope($request, $scope, (int) $request->query('employee_id', 0), $periodA->id, 'El comparativo general', $periodB->id);

        if ($scope === 'branch') {
            $branchId = (int) $request->query('branch_id', 0);
            if (! $branchId) {
                return response()->json(['error' => 'Selecciona una sucursal.'], 422);
            }
            $branch = Branch::find($branchId);
            if (! $branch || ! in_array($branch->name, self::OPERATIVE_BRANCH_NAMES, true)) {
                return response()->json(['error' => 'Selecciona una sucursal operativa válida.'], 422);
            }
            $config['branch_id'] = $branchId;
        }

        if ($scope === 'employee') {
            $employeeId = (int) $request->query('employee_id', 0);
            if (! $employeeId) {
                return response()->json(['error' => 'Selecciona un colaborador.'], 422);
            }
            if (! Employee::query()->whereKey($employeeId)->exists()) {
                return response()->json(['error' => 'Colaborador no encontrado.'], 404);
            }
            $config['employee_id'] = $employeeId;
        }

        try {
            $data = $exportService->comparativeViewData($periodA, $config);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'No se pudo construir el comparativo: '.$e->getMessage()], 422);
        }

        return response()->json([
            'periodA' => [
                'id' => $periodA->id, 'label' => $periodA->label, 'code' => $periodA->code, 'type' => $periodA->type,
                'composite' => $data['currentComposite'] ?? null,
            ],
            'periodB' => [
                'id' => $periodB->id, 'label' => $periodB->label, 'code' => $periodB->code, 'type' => $periodB->type,
                'composite' => $data['compareComposite'] ?? null,
            ],
            'scope' => $scope,
            'scopeLabel' => $data['scopeLabel'],
            'reportType' => $reportType,
            'branchId' => $config['branch_id'] ?? null,
            'employeeId' => $config['employee_id'] ?? null,
            'rows' => $data['rows'],
            'exportUrls' => [
                'excel' => route('reportes-mensuales.export-filtered-radiography', $periodA->id).'?'.http_build_query(array_filter([
                    'report_type' => $reportType, 'compare_period_id' => $periodB->id,
                    'scope' => $scope !== 'general' ? $scope : null,
                    'branch_id' => $config['branch_id'] ?? null, 'employee_id' => $config['employee_id'] ?? null,
                ])),
                'pdf' => route('reportes-mensuales.export-filtered-radiography-pdf', $periodA->id).'?'.http_build_query(array_filter([
                    'report_type' => $reportType, 'compare_period_id' => $periodB->id,
                    'scope' => $scope !== 'general' ? $scope : null,
                    'branch_id' => $config['branch_id'] ?? null, 'employee_id' => $config['employee_id'] ?? null,
                ])),
            ],
        ], 200, ['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0', 'Pragma' => 'no-cache']);
    }

    /**
     * Roster de colaboradores de UN periodo para el selector de alcance "Por gestor"
     * del comparativo — reutiliza PeriodEmployeeRosterService::rosterRowsForSelector(),
     * el MISMO roster que ya usa el flujo de generación de reportes (nunca una consulta
     * nueva/paralela a Employee::all()).
     *
     * @param  Request  $request  `period_id` del periodo cuyo roster se lista.
     * @param  PeriodEmployeeRosterService  $rosterService  Fuente del roster.
     * @return JsonResponse `{employees: [{id, name}]}`; lista vacía si falta el periodo,
     *                      no existe o el usuario no puede ver sus reportes. Restringidos
     *                      solo reciben colaboradores de sus sucursales. Sin caché.
     */
    public function comparativoEmployeesLookup(Request $request, PeriodEmployeeRosterService $rosterService): JsonResponse
    {
        $periodId = (int) $request->query('period_id', 0);
        if (! $periodId) {
            return response()->json(['employees' => []], 200, self::NO_STORE_HEADERS);
        }
        $period = Period::find($periodId);
        if (! $period) {
            return response()->json(['employees' => []], 200, self::NO_STORE_HEADERS);
        }

        // 27-oct-2026 — sin permiso de ver reportes de ese periodo, no se expone su roster.
        $visibleIds = app(ReportVisibilityService::class)->visiblePeriodIds($request->user());
        if ($visibleIds !== null && ! in_array($period->id, $visibleIds, true)) {
            return response()->json(['employees' => []], 200, self::NO_STORE_HEADERS);
        }

        $rows = $rosterService->rosterRowsForSelector($period)['rows'] ?? [];

        // Fase 6.4 — solo colaboradores de las sucursales del usuario.
        $dataScope = app(DataScopeService::class);
        if ($dataScope->isRestricted($request->user())) {
            $rows = array_values(array_filter($rows, fn ($r) => array_key_exists('branch_id', $r) ? $dataScope->allows($request->user(), $r['branch_id']) : $dataScope->allowsEmployee($request->user(), (int) $r['employee_id'], $period->id)));
        }

        return response()->json([
            'employees' => collect($rows)->map(fn ($r) => [
                'id' => (int) $r['employee_id'],
                'name' => $r['name'],
            ])->values(),
        ], 200, self::NO_STORE_HEADERS);
    }

    /**
     * Genera (consolida) la radiografía del periodo de forma síncrona si todas
     * las fuentes requeridas están cargadas y procesadas (ver sourceStatus()).
     * El Excel complementario de gastos Lendus tiene mensaje propio porque sin
     * él no se identifica la sucursal receptora de los préstamos intersucursales.
     *
     * @param  Period  $period  Periodo a consolidar.
     * @param  PeriodRadiographyService  $service  Genera el PeriodSummary y sus resúmenes en BD.
     * @return RedirectResponse Con éxito o con la lista de fuentes faltantes/con error.
     *
     * Lanza 403 si el usuario no tiene alcance general. Escribe en BD.
     */
    public function consolidate(Period $period, PeriodRadiographyService $service): RedirectResponse
    {
        app(DataScopeService::class)->requireGeneral(request()->user(), 'Consolidar el periodo');
        $status = $this->sourceStatus($period);
        $allMissing = array_merge($status['missing'], $status['errors']);
        if (! empty($allMissing)) {
            if (in_array(DataSourceCode::GastosLendusExcel->value, $allMissing, true)) {
                return back()->with('error', 'Falta cargar el Excel complementario de gastos Lendus. Este archivo es necesario para identificar la sucursal que recibe en préstamos intersucursales.');
            }

            return back()->with('error', 'No se puede generar la radiografía. Faltan fuentes o análisis procesado: '.implode(', ', $allMissing).'.');
        }
        $service->generate($period, auth()->id());

        return back()->with('success', 'Radiografía consolidada correctamente.');
    }

    /**
     * Captura/actualiza el saldo inicial en caja del periodo — único insumo del cálculo de
     * EBITDA que no viene de ninguna fuente importada. Sin este dato el sistema no debe asumir
     * $0 en silencio; este endpoint permite capturarlo desde el preview de la radiografía.
     *
     * @param  Period  $period  Periodo a actualizar.
     * @param  Request  $request  `saldo_inicial_caja` (numérico, obligatorio).
     * @return JsonResponse|RedirectResponse JSON `{ok, saldo_inicial_caja}` si la petición
     *                                       lo pide; si no, redirección con mensaje.
     *
     * Escribe periods.saldo_inicial_caja. Lanza 403 sin alcance general o sin
     * permiso de ver reportes del periodo; ValidationException si no es numérico.
     */
    public function updateSaldoInicial(Period $period, Request $request): JsonResponse|RedirectResponse
    {
        app(DataScopeService::class)->requireGeneral($request->user(), 'El saldo inicial en caja del periodo');
        app(ReportVisibilityService::class)->authorizePeriodReport($request->user(), $period->id);
        $validated = $request->validate([
            'saldo_inicial_caja' => ['required', 'numeric'],
        ]);

        $period->update(['saldo_inicial_caja' => $validated['saldo_inicial_caja']]);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'saldo_inicial_caja' => (float) $period->saldo_inicial_caja]);
        }

        return back()->with('success', 'Saldo inicial en caja actualizado.');
    }

    /**
     * Descarga en CSV el consolidado por empleado del periodo
     * (monthly_employee_summaries): pagos, bonos, descuentos, gastos, neto y si
     * quedó incluido o excluido del reporte (con motivo).
     *
     * @param  Period  $period  Periodo a exportar.
     * @return StreamedResponse Archivo consolidado_{código}.csv generado al vuelo.
     *
     * Lanza 403 sin alcance general o sin permiso de ver reportes del periodo.
     */
    public function exportSummary(Period $period): StreamedResponse
    {
        app(DataScopeService::class)->requireGeneral(request()->user(), 'El consolidado del periodo');
        app(ReportVisibilityService::class)->authorizePeriodReport(request()->user(), $period->id);
        $rows = MonthlyEmployeeSummary::query()->with(['employee:id,full_name', 'branch:id,name'])->where('period_id', $period->id)->orderByDesc('included_in_report')->orderBy('employee_id')->get();
        $filename = sprintf('consolidado_%s.csv', $period->code ?: $period->id);

        return response()->streamDownload(function () use ($rows, $period) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['period_id', 'period_code', 'period_label', 'employee_id', 'employee_name', 'branch_id', 'branch_name', 'total_payments', 'total_bonuses', 'total_discounts', 'total_expenses', 'net_amount', 'has_useful_movement', 'included_in_report', 'exclusion_reason']);
            foreach ($rows as $row) {
                fputcsv($handle, [$period->id, $period->code, $period->label, $row->employee_id, $row->employee?->full_name, $row->branch_id, $row->branch?->name, $row->total_payments, $row->total_bonuses, $row->total_discounts, $row->total_expenses, $row->net_amount, $row->has_useful_movement ? 1 : 0, $row->included_in_report ? 1 : 0, $row->exclusion_reason]);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Genera y descarga el Excel de la radiografía GENERAL del periodo.
     *
     * Requiere PeriodSummary vigente (un job viejo en cola/corriendo solo se
     * registra como advertencia, no bloquea). Valida fuentes: el Excel de gastos
     * Lendus es obligatorio siempre; las demás fuentes de gastos se omiten de la
     * validación si el snapshot ya trae gastos.
     *
     * @param  Period  $period  Periodo a exportar.
     * @param  RadiografiaExportService  $service  Genera el .xlsx.
     * @return BinaryFileResponse|\Illuminate\Http\Response
     *                                                      Descarga, o texto con 409 (sin consolidado / faltan fuentes) o 500 (fallo al generar).
     *
     * Efectos: registra una fila en period_radiography_exports; escribe logs de
     * validación. Lanza 403 sin alcance general o sin permiso de ver reportes.
     */
    public function exportRadiography(Period $period, RadiografiaExportService $service)
    {
        app(DataScopeService::class)->requireGeneral(request()->user(), 'La radiografía general');
        app(ReportVisibilityService::class)->authorizePeriodReport(request()->user(), $period->id);
        // Check summary FIRST — a pending old job must NOT block an already-generated report
        $summary = PeriodSummary::query()
            ->where('period_id', $period->id)
            ->where('status', 'generated')
            ->whereNull('invalidated_at')
            ->latest('id')
            ->first();

        if (! $summary) {
            return response('No existe un consolidado vigente para exportar la radiografía.', 409);
        }

        // Log if there is a stale queued/running job (informational only)
        $latestRun = PeriodRadiographyRun::query()->where('period_id', $period->id)->latest('id')->first();
        if ($latestRun && in_array($latestRun->status, ['queued', 'running'], true)) {
            Log::warning('exportRadiography: stale job detected but summary exists, proceeding.', [
                'period_id' => $period->id,
                'run_id' => $latestRun->id,
                'run_status' => $latestRun->status,
            ]);
        }

        // Build snapshot to check if expense data actually exists before trusting sourceStatus
        $snapshot = app(RadiographySnapshotBuilder::class)->build($period, $summary);

        $hasExpensesInSnapshot =
            (float) data_get($snapshot, 'summary.expenses_total', 0) > 0
            || (float) data_get($snapshot, 'sections.expenses_detail.total', 0) > 0
            || (float) data_get($snapshot, 'sections.expenses_matrix.grand_total', 0) > 0;

        $sources = $this->sourceStatus($period);

        Log::info('Excel export source validation', [
            'period_id' => $period->id,
            'period_label' => $period->label,
            'missing' => $sources['missing'] ?? [],
            'errors' => $sources['errors'] ?? [],
            'has_expenses_snapshot' => $hasExpensesInSnapshot,
            'expenses_total' => data_get($snapshot, 'summary.expenses_total'),
            'expenses_detail_total' => data_get($snapshot, 'sections.expenses_detail.total'),
            'expenses_matrix_total' => data_get($snapshot, 'sections.expenses_matrix.grand_total'),
        ]);

        $missing = collect($sources['missing'] ?? []);
        $errors = collect($sources['errors'] ?? []);

        // gastos_lendus_excel is unconditionally required — never bypass, even when expenses exist.
        // Without it, P. INTERSUC. shows "No identificada" for every intersucursal row.
        if ($missing->contains(DataSourceCode::GastosLendusExcel->value)
            || $errors->contains(DataSourceCode::GastosLendusExcel->value)) {
            return response(
                'Falta cargar el Excel complementario de gastos Lendus. Este archivo es necesario para identificar la sucursal que recibe en préstamos intersucursales.',
                409
            );
        }

        // Other gastos sources may be bypassed if expense data already exists in the snapshot.
        if ($hasExpensesInSnapshot) {
            $gastosCodes = [DataSourceCode::Gastos->value, 'GASTOS'];
            $missing = $missing->reject(fn ($code) => in_array($code, $gastosCodes, true));
            $errors = $errors->reject(fn ($code) => in_array($code, $gastosCodes, true));
        }

        if ($missing->isNotEmpty() || $errors->isNotEmpty()) {
            return response(
                'No se puede exportar. Faltan fuentes procesadas: '.$missing->merge($errors)->implode(', ').'.',
                409
            );
        }

        try {
            $path = $service->export($period);
        } catch (\Throwable $e) {
            report($e);

            return response('No se pudo generar el Excel: '.$e->getMessage(), 500);
        }

        if (! file_exists($path) || filesize($path) === 0) {
            return response('El archivo Excel generado está vacío o no existe.', 500);
        }

        PeriodRadiographyExport::query()->create([
            'period_summary_id' => $summary->id,
            'export_path' => $path,
            'file_type' => 'excel',
            'template_version' => config('app.version'),
            'metadata' => ['period_id' => $period->id, 'period_label' => $period->label],
            'exported_at' => now(),
            'exported_by' => auth()->id(),
        ]);

        return response()->download($path, basename($path), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Genera y descarga el PDF de la radiografía GENERAL del periodo. A
     * diferencia del Excel no vuelve a validar las fuentes, solo exige un
     * PeriodSummary vigente.
     *
     * @param  Period  $period  Periodo a exportar.
     * @param  RadiografiaExportService  $service  Genera el PDF.
     * @return BinaryFileResponse|\Illuminate\Http\Response
     *                                                      Descarga, o texto con 409 (sin consolidado) o 500 (fallo al generar).
     *
     * Efectos: registra una fila en period_radiography_exports. Lanza 403 sin
     * alcance general o sin permiso de ver reportes.
     */
    public function exportRadiographyPdf(Period $period, RadiografiaExportService $service)
    {
        app(DataScopeService::class)->requireGeneral(request()->user(), 'La radiografía general');
        app(ReportVisibilityService::class)->authorizePeriodReport(request()->user(), $period->id);
        $summary = PeriodSummary::query()
            ->where('period_id', $period->id)
            ->where('status', 'generated')
            ->whereNull('invalidated_at')
            ->latest('id')
            ->first();

        if (! $summary) {
            return response('No existe un consolidado vigente para exportar el PDF.', 409);
        }

        try {
            $path = $service->exportPdf($period);
        } catch (\Throwable $e) {
            report($e);

            return response('No se pudo generar el PDF: '.$e->getMessage(), 500);
        }

        if (! file_exists($path) || filesize($path) === 0) {
            return response('El archivo PDF generado está vacío o no existe.', 500);
        }

        PeriodRadiographyExport::query()->create([
            'period_summary_id' => $summary->id,
            'export_path' => $path,
            'file_type' => 'pdf',
            'template_version' => config('app.version'),
            'metadata' => ['period_id' => $period->id, 'period_label' => $period->label],
            'exported_at' => now(),
            'exported_by' => auth()->id(),
        ]);

        return response()->download($path, basename($path), [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Genera y descarga un Excel filtrado o comparativo (por sucursal, por
     * gestor, mes vs mes, etc.), opcionalmente con un ajuste manual de OPEX
     * efímero (nunca se guarda), y lo registra en el Historial.
     *
     * @param  Period  $period  Periodo principal del reporte.
     * @param  Request  $request  scope, report_type, branch_id, employee_id,
     *                            compare_period_id y parámetros manual_* opcionales.
     * @param  RadiografiaExportService  $service  Genera el .xlsx con la configuración.
     * @param  PeriodDerivedDataCleaner  $cleaner  Limpia versiones previas de la misma identidad.
     * @return BinaryFileResponse|\Illuminate\Http\Response
     *                                                      Descarga, o texto con 409 (sin consolidado), 422 (sucursal/gestor no
     *                                                      válidos) o 500 (fallo al generar).
     *
     * Efectos: escribe run/export en BD (ver persistFilteredRunExport()). Lanza 403 por alcance.
     */
    public function exportFilteredRadiography(Period $period, Request $request, RadiografiaExportService $service, PeriodDerivedDataCleaner $cleaner)
    {
        // Fase 6.4 — alcance ANTES que cualquier otra respuesta (general/gestor/sucursal ajena → 403).
        $this->authorizeReportScope($request, $request->input('scope'), (int) $request->input('employee_id', 0), $period->id, 'La radiografía general', (int) $request->input('compare_period_id', 0) ?: null);
        $summary = PeriodSummary::query()
            ->where('period_id', $period->id)
            ->where('status', 'generated')
            ->whereNull('invalidated_at')
            ->latest('id')
            ->first();

        if (! $summary) {
            return response('No existe un consolidado vigente para exportar la radiografía.', 409);
        }

        $config = $request->only([
            'scope', 'report_type', 'branch_id', 'employee_id', 'compare_period_id',
        ]);
        $manualAdjustment = $this->manualAdjustmentFromRequest($request);
        if (! empty($manualAdjustment)) {
            $config['manual_adjustment'] = $manualAdjustment;
        }

        // Validate branch is operative
        if (($config['scope'] ?? '') === 'branch') {
            $branchId = (int) ($config['branch_id'] ?? 0);
            if (! $branchId) {
                return response('Selecciona una sucursal.', 422);
            }
            $branch = Branch::find($branchId);
            if (! $branch || ! in_array($branch->name, self::OPERATIVE_BRANCH_NAMES, true)) {
                return response('Selecciona una sucursal operativa válida.', 422);
            }
        }

        if (($config['scope'] ?? '') === 'employee' && ! (int) ($config['employee_id'] ?? 0)) {
            return response('Selecciona un gestor.', 422);
        }

        try {
            $path = $service->exportWithConfig($period, $config);
        } catch (\Throwable $e) {
            report($e);

            return response('No se pudo generar el Excel filtrado: '.$e->getMessage(), 500);
        }

        if (! file_exists($path) || filesize($path) === 0) {
            return response('El archivo Excel generado está vacío o no existe.', 500);
        }

        $this->persistFilteredRunExport($period, $config, 'excel', $path, $cleaner);

        return response()->download($path, basename($path), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Versión PDF de exportFilteredRadiography(): genera el PDF filtrado o
     * comparativo y lo registra en el Historial. No repite la validación de
     * sucursal operativa / gestor obligatorio que sí hace la versión Excel.
     *
     * @param  Period  $period  Periodo principal del reporte.
     * @param  Request  $request  Mismos parámetros que la versión Excel.
     * @param  RadiografiaExportService  $service  Genera el PDF con la configuración.
     * @param  PeriodDerivedDataCleaner  $cleaner  Limpia versiones previas de la misma identidad.
     * @return BinaryFileResponse|\Illuminate\Http\Response
     *                                                      Descarga, o texto con 409 (sin consolidado) o 500 (fallo al generar).
     *
     * Efectos: escribe run/export en BD. Lanza 403 por alcance.
     */
    public function exportFilteredRadiographyPdf(Period $period, Request $request, RadiografiaExportService $service, PeriodDerivedDataCleaner $cleaner)
    {
        // Fase 6.4 — alcance ANTES que cualquier otra respuesta (general/gestor/sucursal ajena → 403).
        $this->authorizeReportScope($request, $request->input('scope'), (int) $request->input('employee_id', 0), $period->id, 'La radiografía general', (int) $request->input('compare_period_id', 0) ?: null);
        $summary = PeriodSummary::query()
            ->where('period_id', $period->id)
            ->where('status', 'generated')
            ->whereNull('invalidated_at')
            ->latest('id')
            ->first();

        if (! $summary) {
            return response('No existe un consolidado vigente para exportar el PDF.', 409);
        }

        $config = $request->only([
            'scope', 'report_type', 'branch_id', 'employee_id', 'compare_period_id',
        ]);
        $manualAdjustment = $this->manualAdjustmentFromRequest($request);
        if (! empty($manualAdjustment)) {
            $config['manual_adjustment'] = $manualAdjustment;
        }

        try {
            $path = $service->exportPdfWithConfig($period, $config);
        } catch (\Throwable $e) {
            report($e);

            return response('No se pudo generar el PDF filtrado: '.$e->getMessage(), 500);
        }

        if (! file_exists($path) || filesize($path) === 0) {
            return response('El archivo PDF generado está vacío o no existe.', 500);
        }

        $this->persistFilteredRunExport($period, $config, 'pdf', $path, $cleaner);

        return response()->download($path, basename($path), [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * "Descargar Excel de colaboradores" (frente 6, auditoría 07-sep-2026) —
     * TODOS los colaboradores del periodo, ignorando explícitamente el filtro
     * individual de employee_id (si la pantalla tiene uno seleccionado) pero
     * respetando el resto de filtros aplicables (branch_id). No requiere que
     * exista una Radiografía generada — se calcula directamente sobre
     * fact_* igual que la vista Web (RadiographySnapshotBuilder::
     * buildAllEmployeeGestorRows()).
     *
     * Un usuario restringido siempre recibe el filtro de una de sus sucursales.
     *
     * @param  Period  $period  Periodo a exportar.
     * @param  Request  $request  branch_id opcional y parámetros manual_* del ajuste efímero.
     * @param  EmployeesHistoricoExportService  $service  Construye la hoja de cálculo.
     * @return BinaryFileResponse|\Illuminate\Http\Response
     *                                                      Descarga, o texto con 500 si falla.
     *
     * Efectos: guarda el .xlsx en storage/app/radiografias (no se borra tras
     * enviarlo). Lanza 403 si la sucursal pedida está fuera del alcance.
     */
    public function exportEmployeesHistorico(Period $period, Request $request, EmployeesHistoricoExportService $service)
    {
        $filters = $request->only(['branch_id']);
        // Fase 6.4 — restringido: siempre filtrado a una de sus sucursales.
        $scopedBranch = app(DataScopeService::class)->resolveBranch($request->user(), (int) ($filters['branch_id'] ?? 0) ?: null);
        if ($scopedBranch !== null) {
            $filters['branch_id'] = $scopedBranch;
        }
        $manualAdjustment = $this->manualAdjustmentFromRequest($request);

        try {
            $spreadsheet = $service->build($period, $filters, $manualAdjustment);
        } catch (\Throwable $e) {
            report($e);

            return response('No se pudo generar el Excel de colaboradores: '.$e->getMessage(), 500);
        }

        $directory = storage_path('app/radiografias');
        File::ensureDirectoryExists($directory);
        $outputPath = $directory.'/colaboradores_'.($period->code ?: $period->id).'_'.now()->format('Ymd_His').'.xlsx';

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        // BUG REAL (07-sep-2026, cierre): sin esto, PhpSpreadsheet omite los
        // objetos de gráfica al guardar el .xlsx aunque el código los haya
        // construido — el usuario veía la hoja "Gráficas" con solo números, sin
        // ninguna gráfica renderizada. Mismo patrón que RadiografiaExportService::export().
        $writer->setIncludeCharts(true);
        $writer->save($outputPath);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        if (! file_exists($outputPath) || filesize($outputPath) === 0) {
            return response('El archivo Excel generado está vacío o no existe.', 500);
        }

        return response()->download($outputPath, basename($outputPath), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Dataset COMPLETO del reporte proyectado a un alcance (sucursal/colaborador) — misma
     * forma que el snapshot general (period/summary/branch_radiography/sections/charts),
     * consumido por Preview.vue para reemplazar TODO el dashboard (no solo las tarjetas
     * KPI) cuando el usuario cambia de filtro. Nunca reimporta ni regenera: proyecta el
     * snapshot ya calculado del periodo (ver RadiographySnapshotBuilder::applyScope()).
     * La misma función (RadiografiaExportService::buildSnapshot) es la que ya usa
     * previewPage() para el deep-link inicial, así que ambos caminos son idénticos.
     *
     * @param  Period  $period  Periodo del reporte.
     * @param  Request  $request  scope (general|branch|employee), branch_id, employee_id
     *                            y parámetros manual_* del ajuste efímero.
     * @param  RadiografiaExportService  $exportService  Construye el snapshot proyectado.
     * @return JsonResponse `{snapshot}` (200); `{error, snapshot}` con 404 si el alcance no
     *                      tiene datos en el periodo; `{error}` con 422/404/500. Sin caché.
     *
     * Lanza 403 por alcance de sucursal o de autor.
     */
    public function scopedData(Period $period, Request $request, RadiografiaExportService $exportService): JsonResponse
    {
        $scope = $request->query('scope', 'general');

        if (! in_array($scope, ['general', 'branch', 'employee'], true)) {
            return response()->json(['error' => 'Alcance no válido. Usa general, branch o employee.'], 422);
        }

        $config = ['scope' => $scope];

        $this->authorizeReportScope($request, $scope, (int) $request->query('employee_id', 0), $period->id, 'La radiografía general');

        if ($scope === 'branch') {
            $branchId = (int) $request->query('branch_id', 0);
            if (! $branchId) {
                return response()->json(['error' => 'Selecciona una sucursal.'], 422);
            }
            $branch = Branch::find($branchId);
            if (! $branch || ! in_array($branch->name, self::OPERATIVE_BRANCH_NAMES, true)) {
                return response()->json(['error' => 'Selecciona una sucursal operativa válida.'], 422);
            }
            $config['branch_id'] = $branchId;
        }

        if ($scope === 'employee') {
            $employeeId = (int) $request->query('employee_id', 0);
            if (! $employeeId) {
                return response()->json(['error' => 'Selecciona un colaborador.'], 422);
            }
            if (! Employee::query()->whereKey($employeeId)->exists()) {
                return response()->json(['error' => 'Colaborador no encontrado.'], 404);
            }
            $config['employee_id'] = $employeeId;
        }

        // Ajuste manual EFÍMERO (cierre 17-sep-2026, ronda 2) — YA soporta los 3
        // alcances (employee/branch/general) vía TemporaryOpexAdjustmentService;
        // el ajuste solo se aplica de verdad si su modo coincide con este scope
        // (ver applyEmployeeScope()/applyBranchScope()/applyGeneralManualAdjustment()).
        $manualAdjustment = $this->manualAdjustmentFromRequest($request);
        if (! empty($manualAdjustment)) {
            $config['manual_adjustment'] = $manualAdjustment;
        }

        try {
            $snapshot = $exportService->buildSnapshot($period, $config);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'No se pudo construir la radiografía para este alcance.'], 500);
        }

        // Nunca cacheable — un ajuste manual EFÍMERO cambia el resultado para la MISMA
        // URL de scope, así que un cache compartido/proxy/navegador que ignore el
        // querystring podría servir un snapshot ajustado (o sin ajustar) equivocado
        // (Parte C4 del cierre, 17-sep-2026).
        $noStoreHeaders = ['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0', 'Pragma' => 'no-cache'];

        if ($scope !== 'general' && (($snapshot['scope']['available'] ?? true) === false)) {
            $label = $scope === 'branch' ? ($snapshot['scope']['branch_name'] ?? 'la sucursal seleccionada') : ($snapshot['scope']['employee_name'] ?? 'el colaborador seleccionado');

            return response()->json([
                'error' => "Sin datos de radiografía para {$label} en este periodo.",
                'snapshot' => $snapshot,
            ], 404, $noStoreHeaders);
        }

        return response()->json(['snapshot' => $snapshot], 200, $noStoreHeaders);
    }

    /**
     * Estado del periodo para la UI (JSON): si la radiografía está lista o
     * invalidada, incidencias, fuentes procesadas/con error/faltantes, el último
     * run, y si hoy se puede generar, regenerar o exportar.
     *
     * @param  Period  $period  Periodo consultado.
     * @return JsonResponse
     *
     * Solo lectura; no aplica validaciones de alcance.
     */
    public function status(Period $period)
    {
        $summary = PeriodSummary::query()->with('incidents')->where('period_id', $period->id)->first();
        $sources = $this->sourceStatus($period);
        $latestRun = PeriodRadiographyRun::query()->where('period_id', $period->id)->latest('id')->first();
        $ready = (bool) ($summary && $summary->status === 'generated' && ! $summary->invalidated_at);
        $running = $latestRun && in_array($latestRun->status, ['queued', 'running'], true);

        return response()->json([
            'ready' => $ready,
            'status' => $summary?->status ?? 'missing',
            'invalidated_at' => $summary?->invalidated_at,
            'invalidated_reason' => $summary?->invalidated_reason,
            'incidents_count' => (int) ($summary?->incidents?->count() ?? 0),
            'sources_processed' => $sources['processed'],
            'sources_error' => $sources['errors'],
            'sources_missing' => $sources['missing'],
            'run_status' => $latestRun?->status,
            'run_log' => $latestRun?->log,
            'run_finished_at' => optional($latestRun?->finished_at)->format('d/m/Y H:i'),
            'can_generate' => ! $ready && ! $running && empty($sources['missing']),
            'can_regenerate' => $ready && ! $running && empty($sources['missing']),
            'can_export' => $ready && ! $running && empty($sources['missing']) && empty($sources['errors']),
        ]);
    }

    /**
     * Códigos de fuente obligatorios para consolidar/exportar la radiografía:
     * nómina NOI, ingresos/cobranza, gastos, ministraciones y saldos cliente de
     * Lendus, y el Excel complementario de gastos Lendus.
     *
     * @return array<int, string>
     */
    private function requiredSourceCodes(): array
    {
        return [
            DataSourceCode::NoiNomina->value,
            DataSourceCode::LendusIngresosCobranza->value,
            DataSourceCode::Gastos->value,
            DataSourceCode::LendusMinistraciones->value,
            DataSourceCode::LendusSaldosCliente->value,
            DataSourceCode::GastosLendusExcel->value,
        ];
    }

    /**
     * Códigos que satisfacen el requisito "gastos" (incluye las fuentes
     * desagregadas de gastos Lendus y ERP).
     *
     * @return array<int, string>
     */
    private function gastosEquivalentCodes(): array
    {
        return [
            DataSourceCode::Gastos->value,
            DataSourceCode::GastosLendus->value,
            DataSourceCode::GastosErp->value,
        ];
    }

    /**
     * Clasifica cada fuente requerida según las cargas del periodo y de todas
     * sus semanas base: "processed" (al menos una carga procesada), "errors"
     * (hay cargas pero ninguna procesada) o "missing" (sin cargas).
     *
     * @param  Period  $period  Periodo (mensual o compuesto) a revisar.
     * @return array{processed: array<int,string>, errors: array<int,string>, missing: array<int,string>}
     */
    private function sourceStatus(Period $period): array
    {
        // Incluir uploads del periodo mensual Y de todas sus semanas componentes
        $allPeriods = Period::all();
        $weeklyIds = $period->resolveBaseWeeklyIds($allPeriods);
        $allIds = array_unique(array_merge([$period->id], $weeklyIds));

        $uploads = ReportUpload::query()
            ->whereIn('period_id', $allIds)
            ->with('dataSource:id,code,name')
            ->get();

        $required = $this->requiredSourceCodes();
        $gastosCodes = $this->gastosEquivalentCodes();

        $processed = [];
        $errors = [];
        $missing = [];

        foreach ($required as $code) {
            // Para gastos aceptar cualquier variante procesada
            $codesToCheck = $code === DataSourceCode::Gastos->value ? $gastosCodes : [$code];

            $sourceUploads = $uploads->filter(
                fn ($upload) => in_array($upload->dataSource?->code, $codesToCheck, true)
            );

            if ($sourceUploads->isEmpty()) {
                $missing[] = $code;

                continue;
            }
            if ($sourceUploads->contains(fn ($upload) => (string) ($upload->status?->value ?? $upload->status) === 'processed')) {
                $processed[] = $code;
            } else {
                $errors[] = $code;
            }
        }

        return compact('processed', 'errors', 'missing');
    }

    /**
     * Fase 6.4 — alcance de un reporte pedido por parámetros: general → solo
     * alcance general; gestor → debe pertenecer a sus sucursales; sucursal → la
     * valida EnsureBranchScope (y aquí otra vez, por defensa en profundidad).
     *
     * Después valida el alcance por autor (ReportVisibilityService) si hay periodo.
     *
     * @param  Request  $request  De aquí salen el usuario y branch_id.
     * @param  string|null  $scope  general|branch|employee (cualquier otro = general).
     * @param  int  $employeeId  Colaborador pedido (0 = ninguno).
     * @param  int|null  $periodId  Periodo principal.
     * @param  string  $what  Nombre de lo que se protege, para el mensaje 403.
     * @param  int|null  $comparePeriodId  Periodo de comparación, si es comparativo.
     *
     * Lanza 403 si el usuario no tiene el alcance requerido.
     */
    private function authorizeReportScope(Request $request, ?string $scope, int $employeeId, ?int $periodId, string $what, ?int $comparePeriodId = null): void
    {
        $dataScope = app(DataScopeService::class);
        $user = $request->user();

        // Sin el ID correspondiente, un alcance branch/employee podría terminar en el
        // reporte general: para alcance restringido se trata como general (403).
        $branchId = (int) $request->input('branch_id', 0);
        if ($scope === 'employee' && $employeeId > 0) {
            $dataScope->authorizeEmployee($user, $employeeId, $periodId);
            // Comparativo: el colaborador también debe pertenecer a sus sucursales en
            // el periodo B — nunca mostrar datos de B de otra sucursal.
            if ($comparePeriodId && $comparePeriodId !== $periodId) {
                $this->authorizeEmployeeInComparePeriod($user, $employeeId, $comparePeriodId);
            }
        } elseif ($scope === 'branch' && $branchId > 0) {
            $dataScope->authorizeBranch($user, $branchId);
        } else {
            $dataScope->requireGeneral($user, $what);
            $scope = 'general';
        }

        // 27-oct-2026 — alcance por AUTOR, después del de sucursal (sus mensajes mandan).
        if ($periodId) {
            app(ReportVisibilityService::class)->authorizePeriodReport($user, $periodId, $scope, $branchId ?: null, $employeeId ?: null, $comparePeriodId);
        }
    }

    /**
     * 403 en español si el colaborador no pertenece al alcance del usuario en el periodo de comparación.
     *
     * @param  User  $user  Usuario que consulta.
     * @param  int  $employeeId  Colaborador del comparativo.
     * @param  int  $comparePeriodId  Periodo B del comparativo.
     */
    private function authorizeEmployeeInComparePeriod(User $user, int $employeeId, int $comparePeriodId): void
    {
        $dataScope = app(DataScopeService::class);
        if ($dataScope->allowsEmployee($user, $employeeId, $comparePeriodId)) {
            return;
        }

        $label = Period::query()->whereKey($comparePeriodId)->first()?->label ?? 'el periodo de comparación';

        abort(403, "En {$label} (periodo de comparación) ese colaborador no pertenece a tus sucursales autorizadas ({$dataScope->label($user)}), "
            .'así que no podemos mostrar el comparativo. Elige otro periodo o pide a un administrador que amplíe tu alcance en Usuarios.');
    }

    /**
     * Indica si un run cae dentro del alcance por sucursal del usuario: por
     * sucursal → la sucursal debe estar permitida; por gestor → el colaborador
     * debe pertenecer a sus sucursales en ese periodo; general → solo usuarios
     * con alcance general.
     *
     * @param  User  $user  Usuario que consulta.
     * @param  PeriodRadiographyRun  $run  Reporte generado.
     */
    private function runWithinScope(User $user, PeriodRadiographyRun $run): bool
    {
        $dataScope = app(DataScopeService::class);

        return match ($run->scope ?: 'general') {
            'branch' => $dataScope->allows($user, $run->branch_id ? (int) $run->branch_id : null),
            'employee' => $run->employee_id && $dataScope->allowsEmployee($user, (int) $run->employee_id, (int) $run->period_id),
            default => $dataScope->isGeneral($user),
        };
    }

    /**
     * Descarga/vista de un run: 403 si su sucursal/gestor está fuera del alcance
     * (o es general y el usuario no), y también si el usuario solo puede ver sus
     * propios reportes y el run es de otro autor.
     *
     * @param  PeriodRadiographyRun  $run  Reporte a autorizar.
     */
    private function authorizeRun(PeriodRadiographyRun $run): void
    {
        $user = request()->user();
        if ($this->runWithinScope($user, $run)) {
            // 27-oct-2026 — además del alcance por sucursal, el alcance por autor:
            // con solo "Ver reportes propios", un run ajeno por URL directa → 403.
            $visibility = app(ReportVisibilityService::class);
            abort_unless($visibility->canViewRun($user, $run), 403, $visibility->deniedMessage($user));

            return;
        }

        abort(403, 'Este reporte corresponde a información fuera de tu alcance por sucursal ('
            .app(DataScopeService::class)->label($user).'). Si necesitas consultarlo, pide a un administrador que amplíe tu alcance en Usuarios.');
    }
}
