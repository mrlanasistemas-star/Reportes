<?php

namespace App\Http\Controllers;

use App\Enums\DataSourceCode;
use App\Http\Requests\StoreReportUploadRequest;
use App\Jobs\GenerateRadiographyJob;
use App\Jobs\ReprocessPeriodUploadsJob;
use App\Jobs\ReprocessReportUploadJob;
use App\Jobs\UpdatePeriodDatabaseJob;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\Employee;
use App\Models\EmployeeAlias;
use App\Models\EmployeeBranchAssignment;
use App\Models\EmployeeMatchRejection;
use App\Models\Expense;
use App\Models\MonthlyEmployeeSummary;
use App\Models\Period;
use App\Models\PeriodDatabaseUpdateRun;
use App\Models\PeriodIncident;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodReprocessRun;
use App\Models\PeriodSummary;
use App\Models\ReportUpload;
use App\Services\Access\DataScopeService;
use App\Services\PeriodEmployeeRosterService;
use App\Services\PeriodRadiographyService;
use App\Services\PersonIdentityResolverService;
use App\Services\ReportUploadService;
use App\Support\ReportUploadStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controlador del flujo «Histórico general» (Carga de archivos → Radiografía del periodo).
 *
 * Es el asistente por pasos que usa el área financiera cada mes: (1) subir las fuentes
 * (NOI, Lendus, cartera, gastos ERP/Lendus, IMSS…), (2) «Cargar registros» = actualizar la
 * BD del periodo en segundo plano, (3) resolver incidencias (personas sin sucursal,
 * posibles coincidencias de persona, ubicaciones pendientes), (4) configurar el reporte
 * (alcance general/sucursal/gestor, simple o comparativo), (5) generarlo en cola y
 * (6/7) ver vista previa y descargar Excel/PDF.
 *
 * Rutas: prefijo /historico-general (middleware module:carga, nombres historico-general.*);
 * las acciones de escritura exigen permission:carga,{subir|procesar|generar|eliminar|descargar}
 * (ver routes/web.php). Página Inertia: resources/js/pages/historico-general/index.vue; los
 * endpoints JSON los consumen los componentes de resources/js/components/historico-general.
 *
 * Trabajo pesado en colas: UpdatePeriodDatabaseJob (carga de BD), GenerateRadiographyJob
 * (radiografía), ReprocessReportUploadJob / ReprocessPeriodUploadsJob (reprocesos). El estado
 * de cada proceso vive en period_database_update_runs, period_radiography_runs y
 * period_reprocess_runs; el estado del periodo, en period_summaries (+ period_incidents).
 */
class ReportUploadController extends Controller
{
    /** Las 13 sucursales financieras operativas — fuente de verdad para el selector UI. SJR es period-aware. */
    private const OPERATIVE_BRANCH_NAMES = [
        'ATLACOMULCO', 'ATLIXCO', 'CORDOBA', 'CUERNAVACA', 'HUAMANTLA',
        'IXTLAHUACA', 'MIACATLAN', 'ORIZABA', 'SAN JUAN DEL RÍO',
        'SAN LUIS POTOSI', 'TENANGO DEL VALLE', 'TLAXCALA', 'TULA',
    ];

    /**
     * Página principal del asistente (Inertia «historico-general/index»).
     *
     * Arma, para TODOS los periodos, su estado de flujo (fuentes subidas/faltantes, estado de
     * la carga de BD, incidencias críticas, último intento y último éxito de la radiografía
     * simple/general, reprocesos, componentes de periodos automáticos) y los archivos subidos
     * agrupados por periodo. Elige el periodo inicial (el primero que recibe archivos o el
     * indicado en ?periodo=ID) y precarga sucursales operativas y colaboradores de ese periodo.
     * Solo lectura.
     *
     * @return Response Props: periods, sources, groupedUploads, currentPeriodId, preview, branches, employees.
     */
    public function index(): Response
    {
        // Fuentes activas con sus flags de requerimiento
        // La fotografía diaria de cartera (Efectividad V4) se carga con fecha desde su propio módulo.
        $sources = DataSource::query()
            ->where('is_active', true)
            ->where('code', '!=', DataSourceCode::LendusSaldosClienteFoto->value)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'description', 'is_required_for_bd', 'is_required_for_report']);

        $bdSourceCodes = $sources->where('is_required_for_bd', true)->pluck('code')->values()->all();
        $reportSourceCodes = $sources->where('is_required_for_report', true)->pluck('code')->values()->all();

        $summariesByPeriod = PeriodSummary::query()->with('incidents')->get()->keyBy('period_id');
        // Etapa 5 / "radiography_ready" siempre representan la Radiografía SIMPLE/GENERAL
        // del periodo (es la que respaldan las rutas de descarga "sin scope" — ver
        // GeneratedReportActions.vue/PeriodSelector.vue) — nunca "el último run sin
        // importar de qué reporte era". Un comparativo o un reporte por sucursal/gestor
        // generado DESPUÉS de un simple/general exitoso ya no debe pisar su estado.
        $runsByPeriod = PeriodRadiographyRun::query()
            ->where(fn ($q) => $q->where('report_type', 'simple')->orWhereNull('report_type'))
            ->where(fn ($q) => $q->where('scope', 'general')->orWhereNull('scope'))
            ->whereNull('branch_id')->whereNull('employee_id')->whereNull('comparison_period_id')
            ->orderByDesc('id')->get()->unique('period_id')->keyBy('period_id');
        // PROBLEMA 2/4/5: el ÚLTIMO ÉXITO simple/general por periodo, aparte del último
        // INTENTO ($runsByPeriod, que puede ser un 'failed' más reciente). Etapa 7
        // (Excel/PDF) y "reporte anterior disponible" deben resolver este, nunca
        // asumir que el intento más reciente es el que trae los archivos válidos.
        $latestSuccessRunsByPeriod = PeriodRadiographyRun::query()
            ->where(fn ($q) => $q->where('report_type', 'simple')->orWhereNull('report_type'))
            ->where(fn ($q) => $q->where('scope', 'general')->orWhereNull('scope'))
            ->whereNull('branch_id')->whereNull('employee_id')->whereNull('comparison_period_id')
            ->where('status', 'success')
            ->whereNotNull('output_excel_path')->whereNotNull('output_pdf_path')
            ->orderByDesc('id')->get()->unique('period_id')->keyBy('period_id');
        // Independiente del alcance — el dispatcher solo permite UN run activo por
        // periodo sin importar report_type/scope (ver generateRadiography()), así que
        // "¿hay algo corriendo ahora para este periodo?" SÍ es correcto period-wide.
        $activePeriodIds = PeriodRadiographyRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->pluck('period_id')->unique()->flip();
        $dbRunsByPeriod = PeriodDatabaseUpdateRun::query()->orderByDesc('id')->get()->unique('period_id')->keyBy('period_id');
        $reprocessRunsByPeriod = PeriodReprocessRun::query()->orderByDesc('id')->get()->unique('period_id')->keyBy('period_id');

        // Cargar TODOS los periodos para resolución recursiva de componentes
        $allPeriods = Period::query()->orderByDesc('year')->orderByDesc('month')->orderByDesc('sequence')->get();
        $weeklyPeriods = $allPeriods->where('type', 'weekly')->values();

        $periods = $allPeriods->map(function (Period $period) use (
            $allPeriods, $weeklyPeriods, $sources, $bdSourceCodes, $reportSourceCodes,
            $summariesByPeriod, $runsByPeriod, $latestSuccessRunsByPeriod, $dbRunsByPeriod, $reprocessRunsByPeriod, $activePeriodIds
        ) {
            $coveredWeeks = $this->resolveCoveredWeeks($period, $allPeriods);
            $uploads = $this->resolveUploadsForWeeks($coveredWeeks);
            $uploadedSourceCodes = $uploads->pluck('dataSource.code')->filter()->unique()->values();
            $missingSources = $sources->where('is_required_for_report', true)->whereNotIn('code', $uploadedSourceCodes)->pluck('name')->values();
            $uploadedRequiredCount = $sources->where('is_required_for_report', true)->whereIn('code', $uploadedSourceCodes->toArray())->count();
            $summary = $summariesByPeriod->get($period->id);
            $run = $runsByPeriod->get($period->id);
            $latestSuccessRun = $latestSuccessRunsByPeriod->get($period->id);
            $dbRun = $dbRunsByPeriod->get($period->id);
            $reprocessRun = $reprocessRunsByPeriod->get($period->id);

            $componentLabels = [];
            if (! empty($period->component_period_ids)) {
                $componentLabels = $allPeriods
                    ->whereIn('id', collect($period->component_period_ids)->map(fn ($id) => (int) $id)->all())
                    ->pluck('label')
                    ->values()
                    ->all();
            }

            $workflowState = $this->resolveWorkflowState($uploads, $summary, $run, $period->isCompound(), $dbRun, $bdSourceCodes, $reportSourceCodes, $activePeriodIds->has($period->id), $latestSuccessRun);

            // Para periodos automáticos: validar que sus meses operativos tengan reportes generados
            $canGenerateAutomatic = null;
            $missingComponentMonths = [];
            $automaticComponents = [];

            if ($period->isAutoGenerated()) {
                $canGenerateAutomatic = true;
                $componentIds = collect($period->component_period_ids ?? [])->map(fn ($id) => (int) $id)->all();

                if (empty($componentIds)) {
                    $canGenerateAutomatic = false;
                } else {
                    foreach ($allPeriods->whereIn('id', $componentIds) as $comp) {
                        $compSummary = $summariesByPeriod->get($comp->id);
                        $hasReport = $compSummary
                            && $compSummary->status === 'generated'
                            && ! $compSummary->invalidated_at;

                        $automaticComponents[] = [
                            'id' => $comp->id,
                            'label' => $comp->label,
                            'type' => $comp->type,
                            'has_report' => $hasReport,
                            'report_status' => $compSummary?->status ?? 'missing',
                        ];

                        if (! $hasReport) {
                            $missingComponentMonths[] = $comp->label;
                            $canGenerateAutomatic = false;
                        }
                    }
                }

                // Override: solo se puede generar si TODOS los meses componentes tienen reporte
                $workflowState['can_generate_radiography'] = $canGenerateAutomatic;
                if (! $canGenerateAutomatic) {
                    $workflowState['blocking_reasons'] = ! empty($missingComponentMonths)
                        ? ['No se puede generar todavía. Faltan reportes mensuales: '.implode(', ', $missingComponentMonths).'.']
                        : ['Este periodo automático no tiene meses operativos configurados.'];
                }
            }

            return [
                'id' => $period->id,
                'code' => $period->code,
                'label' => $period->label,
                'type' => $period->type,
                'year' => $period->year,
                'month' => $period->month,
                'is_closed' => (bool) $period->is_closed,
                'can_receive_uploads' => $period->canReceiveUploads(),
                'is_derived' => $period->isAutoGenerated(),
                'is_compound' => $period->isCompound(),
                'component_period_ids' => $period->component_period_ids ?? [],
                'component_labels' => $componentLabels,
                'start_date' => optional($period->start_date)->format('Y-m-d'),
                'end_date' => optional($period->end_date)->format('Y-m-d'),
                'updated_at' => optional($uploads->sortByDesc('created_at')->first()?->created_at)->format('d/m/Y H:i'),
                'uploaded_sources_count' => $uploadedRequiredCount,
                'required_sources_count' => $sources->where('is_required_for_report', true)->count(),
                'missing_sources_count' => $missingSources->count(),
                'processed_count' => $uploads->where('status', 'processed')->count(),
                'pending_count' => $uploads->whereIn('status', ['pending', 'processing'])->count(),
                'failed_count' => $uploads->where('status', 'failed')->count(),
                'missing_sources' => $missingSources,
                'report_final_available' => $missingSources->count() === 0 && $sources->where('is_required_for_report', true)->count() > 0,
                'radiography_status' => $summary?->status ?? 'missing',
                'radiography_invalidated' => (bool) $summary?->invalidated_at,
                'radiography_run_status' => $run?->status,
                'radiography_run_log' => $run?->log,
                'radiography_run_id' => $run?->id,
                'radiography_run_finished_at' => optional($run?->finished_at)->format('d/m/Y H:i'),
                'reprocess_run_status' => $reprocessRun?->status,
                'reprocess_run_log' => $reprocessRun?->log,
                'reprocess_run_progress' => $reprocessRun?->metadata['progress'] ?? null,
                'reprocess_run_id' => $reprocessRun?->id,
                'can_generate_automatic' => $canGenerateAutomatic,
                'missing_component_months' => $missingComponentMonths,
                'automatic_components' => $automaticComponents,
                ...$workflowState,
                'available_week_options' => $period->canReceiveUploads()
                    ? (
                        $period->isMonthly() && ! empty($period->component_period_ids)
                            ? $allPeriods->whereIn('id', collect($period->component_period_ids)->map(fn ($id) => (int) $id)->all())->sortBy('sequence')->map(fn ($week) => [
                                'id' => $week->id,
                                'label' => $week->label,
                                'sequence' => $week->sequence,
                                'start_date' => optional($week->start_date)->format('Y-m-d'),
                                'end_date' => optional($week->end_date)->format('Y-m-d'),
                            ])->values()
                            : $weeklyPeriods->where('year', $period->year)->where('month', $period->month)->sortBy('sequence')->map(fn ($week) => [
                                'id' => $week->id,
                                'label' => $week->label,
                                'sequence' => $week->sequence,
                                'start_date' => optional($week->start_date)->format('Y-m-d'),
                                'end_date' => optional($week->end_date)->format('Y-m-d'),
                            ])->values()
                    )
                    : collect(),
                'source_periods' => $coveredWeeks->map(fn ($week) => [
                    'id' => $week->id,
                    'label' => $week->label,
                    'start_date' => optional($week->start_date)->format('Y-m-d'),
                    'end_date' => optional($week->end_date)->format('Y-m-d'),
                    'uploaded_sources_count' => $this->resolveUploadsForWeeks(collect([$week]))->pluck('dataSource.code')->filter()->unique()->count(),
                    'required_sources_count' => $sources->where('is_required_for_report', true)->count(),
                    'complete' => $this->resolveUploadsForWeeks(collect([$week]))->filter(fn ($u) => (string) ($u->status?->value ?? $u->status) === 'processed')->pluck('dataSource.code')->filter()->unique()->count() >= $sources->where('is_required_for_report', true)->count(),
                ])->values(),
            ];
        })->values();

        $groupedUploads = $allPeriods->map(function (Period $period) use (
            $allPeriods, $sources, $bdSourceCodes, $reportSourceCodes,
            $summariesByPeriod, $runsByPeriod, $latestSuccessRunsByPeriod, $dbRunsByPeriod, $reprocessRunsByPeriod, $activePeriodIds
        ) {
            $coveredWeeks = $this->resolveCoveredWeeks($period, $allPeriods);
            $uploads = $this->resolveUploadsForWeeks($coveredWeeks);
            $uploadedSourceCodes = $uploads->pluck('dataSource.code')->filter()->unique()->values();
            $missingSources = $sources->where('is_required_for_report', true)->whereNotIn('code', $uploadedSourceCodes)->pluck('name')->values();
            $uploadedRequiredCount = $sources->where('is_required_for_report', true)->whereIn('code', $uploadedSourceCodes->toArray())->count();
            $summary = $summariesByPeriod->get($period->id);
            $run = $runsByPeriod->get($period->id);
            $latestSuccessRun = $latestSuccessRunsByPeriod->get($period->id);
            $dbRun = $dbRunsByPeriod->get($period->id);
            $reprocessRun = $reprocessRunsByPeriod->get($period->id);

            return [
                'period_id' => $period->id,
                'period_code' => $period->code,
                'period_label' => $period->label,
                'updated_at' => optional($uploads->sortByDesc('created_at')->first()?->created_at)->format('d/m/Y H:i'),
                'uploaded_sources_count' => $uploadedRequiredCount,
                'required_sources_count' => $sources->where('is_required_for_report', true)->count(),
                'missing_sources_count' => $missingSources->count(),
                'processed_count' => $uploads->where('status', 'processed')->count(),
                'pending_count' => $uploads->whereIn('status', ['pending', 'processing'])->count(),
                'failed_count' => $uploads->where('status', 'failed')->count(),
                'missing_sources' => $missingSources,
                'report_final_available' => $missingSources->count() === 0 && $sources->where('is_required_for_report', true)->count() > 0,
                'radiography_status' => $summary?->status ?? 'missing',
                'radiography_invalidated' => (bool) $summary?->invalidated_at,
                'radiography_run_status' => $run?->status,
                'radiography_run_log' => $run?->log,
                'radiography_run_id' => $run?->id,
                'radiography_run_finished_at' => optional($run?->finished_at)->format('d/m/Y H:i'),
                'reprocess_run_status' => $reprocessRun?->status,
                'reprocess_run_log' => $reprocessRun?->log,
                'reprocess_run_progress' => $reprocessRun?->metadata['progress'] ?? null,
                'reprocess_run_id' => $reprocessRun?->id,
                ...$this->resolveWorkflowState($uploads, $summary, $run, $period->isCompound(), $dbRun, $bdSourceCodes, $reportSourceCodes, $activePeriodIds->has($period->id), $latestSuccessRun),
                'uploads' => $uploads->unique('id')->values()->map(fn ($upload) => [
                    'id' => $upload->id,
                    'original_name' => $upload->original_name,
                    'status' => (string) ($upload->status?->value ?? $upload->status),
                    'uploaded_at' => optional($upload->created_at)->format('d/m/Y H:i'),
                    'notes' => $upload->notes,
                    'file_size' => $upload->file_size,
                    'mime_type' => $upload->mime_type,
                    'can_download' => (bool) $upload->stored_path,
                    'source_code' => $upload->dataSource?->code,
                    'source_name' => $upload->dataSource?->name,
                    'covered_period_ids' => $upload->covered_period_ids ?? [],
                    'covered_period_labels' => collect($upload->covered_period_ids ?? [])
                        ->map(fn ($weekId) => optional($allPeriods->firstWhere('id', (int) $weekId))?->label)
                        ->filter()->values(),
                ]),
            ];
        })->values();

        $currentPeriodId = ($periods->firstWhere('can_receive_uploads', true)['id'] ?? null)
            ?: ($periods->first()['id'] ?? null);
        // Enlace directo desde el Estado de Resultados (?periodo=ID): abre ese periodo si existe.
        $requestedPeriod = (int) request()->integer('periodo');
        if ($requestedPeriod > 0 && $periods->contains('id', $requestedPeriod)) {
            $currentPeriodId = $requestedPeriod;
        }

        $branches = Branch::query()
            ->whereIn('name', self::OPERATIVE_BRANCH_NAMES)
            ->orderBy('name')
            ->get(['id', 'name']);
        // Colaboradores válidos del periodo actual — roster canónico (dedup por
        // nombre_normalizado, sucursal solo si es operativa), NUNCA Employee::all().
        // Etapa 4 vuelve a pedir esto por AJAX cuando el usuario cambia de periodo en
        // el stepper (ver periodEmployeesForConfig()) — este valor inicial solo cubre
        // la primera carga de página para currentPeriodId.
        $employees = collect();
        if ($currentPeriodId) {
            $currentPeriod = $allPeriods->firstWhere('id', $currentPeriodId);
            if ($currentPeriod) {
                $employees = collect(app(PeriodEmployeeRosterService::class)->rosterRowsForSelector($currentPeriod)['rows'])
                    ->map(fn (array $r) => [
                        'id' => $r['employee_id'],
                        'full_name' => $r['name'],
                        'branch_name' => $r['is_branch_operativa'] ? $r['branch_name'] : null,
                    ])
                    ->values();
            }
        }

        return Inertia::render('historico-general/index', [
            'periods' => $periods,
            'sources' => $sources,
            'groupedUploads' => $groupedUploads,
            'currentPeriodId' => $currentPeriodId,
            'preview' => $this->previewPayload($allPeriods->firstWhere('id', $currentPeriodId)),
            'branches' => $branches,
            'employees' => $employees,
        ]);
    }

    /**
     * Colaboradores válidos del periodo dado, para el selector "Buscar empleado o
     * gestor" de la Etapa 4. Reemplaza el prop estático `employees` (que solo refleja
     * el periodo cargado en el primer render) cada vez que el usuario cambia de
     * periodo en el stepper — ver PeriodEmployeeRosterService::rosterRowsForSelector().
     *
     * @param  Period  $period  Periodo seleccionado en el asistente.
     * @param  PeriodEmployeeRosterService  $rosterService  Plantilla canónica de colaboradores.
     * @return JsonResponse {employees: list<{id, full_name, branch_name}>, sin_sucursal}
     */
    public function periodEmployeesForConfig(Period $period, PeriodEmployeeRosterService $rosterService): JsonResponse
    {
        $result = $rosterService->rosterRowsForSelector($period);

        $employees = collect($result['rows'])->map(fn (array $r) => [
            'id' => $r['employee_id'],
            'full_name' => $r['name'],
            'branch_name' => $r['is_branch_operativa'] ? $r['branch_name'] : null,
        ])->values();

        return response()->json([
            'employees' => $employees,
            'sin_sucursal' => $result['sin_sucursal'],
        ]);
    }

    /**
     * Sube un archivo fuente a un periodo (Paso 1). Rechaza periodos automáticos (no reciben
     * archivos directos). En periodos mensuales sin semanas indicadas, cubre por defecto todas
     * sus semanas componentes. El guardado físico y el registro los hace ReportUploadService.
     *
     * @param  StoreReportUploadRequest  $request  period_id, covered_period_ids[], data_source_id, file, notes.
     * @param  ReportUploadService  $service  Guarda el archivo y crea el report_upload.
     * @return RedirectResponse De regreso con mensaje flash de éxito o error.
     */
    public function store(StoreReportUploadRequest $request, ReportUploadService $service): RedirectResponse
    {
        $period = Period::query()->findOrFail((int) $request->integer('period_id'));

        if (! $period->canReceiveUploads()) {
            return back()->with('error', 'Este periodo es automático (bimestral/trimestral/etc.) y no recibe archivos directos.');
        }

        $coveredPeriodIds = collect($request->input('covered_period_ids', []))->map(fn ($id) => (int) $id)->filter()->values()->all();

        // Auto-populate covered_period_ids for monthly periods when not provided
        if ($period->isMonthly() && empty($coveredPeriodIds)) {
            $coveredPeriodIds = collect($period->component_period_ids ?? [])->map(fn ($id) => (int) $id)->values()->all();
        }

        $service->store(
            (int) $request->integer('period_id'),
            $coveredPeriodIds,
            (int) $request->integer('data_source_id'),
            $request->file('file'),
            $request->string('notes')->toString() ?: null
        );

        return back()->with('success', 'Archivo subido correctamente.');
    }

    /**
     * Elimina un archivo subido: borra el archivo físico (disco privado y, por compatibilidad,
     * el público heredado) y el registro report_uploads.
     *
     * @param  ReportUpload  $reportUpload  Archivo a eliminar.
     * @return RedirectResponse De regreso con mensaje flash.
     */
    public function destroy(ReportUpload $reportUpload): RedirectResponse
    {
        // Disco privado y, por compatibilidad, el público heredado.
        ReportUploadStorage::delete($reportUpload->stored_path);
        $reportUpload->delete();

        return back()->with('success', 'Archivo eliminado correctamente.');
    }

    /**
     * Fase 3 (descarga de archivos originales): devuelve los bytes físicos tal
     * cual se subieron — nunca una reconstrucción — preservando el nombre
     * original. 404 controlado si el registro ya no tiene archivo físico en
     * disco (borrado manualmente del storage, inconsistencia histórica, etc.)
     * en vez de dejar que Storage lance una excepción genérica.
     *
     * Exige alcance general (el archivo contiene todas las sucursales) y deja registro en el log.
     *
     * @param  ReportUpload  $reportUpload  Archivo a descargar.
     * @return StreamedResponse Descarga con el nombre original.
     */
    public function download(ReportUpload $reportUpload): StreamedResponse
    {
        // Fase 6.4/10 — el original (NOI, Lendus, cartera...) contiene TODAS las
        // sucursales: además del permiso carga.descargar exige alcance general.
        app(DataScopeService::class)->requireGeneral(request()->user(), 'El archivo original cargado');

        // Privado primero; si aún no se movió, el disco público heredado.
        $disk = ReportUploadStorage::diskFor($reportUpload->stored_path);

        if (! $disk) {
            abort(404, 'El archivo original ya no está disponible en el almacenamiento.');
        }

        Log::info('report_upload.download', [
            'report_upload_id' => $reportUpload->id,
            'user_id' => auth()->id(),
            'original_name' => $reportUpload->original_name,
        ]);

        return $disk->download(
            $reportUpload->stored_path,
            $reportUpload->original_name,
            $reportUpload->mime_type ? ['Content-Type' => $reportUpload->mime_type] : []
        );
    }

    /**
     * «Cargar registros» (Paso 2): encola la actualización de la BD del periodo.
     *
     * Valida que no haya otra carga activa y que estén las fuentes obligatorias de BD sin error
     * (resolveWorkflowState()); crea el run en period_database_update_runs dentro de una
     * transacción con bloqueo de la fila del periodo (evita doble envío) y despacha
     * UpdatePeriodDatabaseJob (que avisa por correo al terminar).
     *
     * @param  Period  $period  Periodo a cargar.
     * @return RedirectResponse De regreso con mensaje flash.
     *
     * @throws ValidationException Si ya hay una carga en cola o en proceso.
     */
    public function updateDatabase(Period $period): RedirectResponse
    {
        $existingRun = PeriodDatabaseUpdateRun::query()
            ->where('period_id', $period->id)
            ->whereIn('status', ['queued', 'running'])
            ->first();

        if ($existingRun) {
            throw ValidationException::withMessages([
                'period' => 'Ya hay una carga en proceso o en cola para este periodo.',
            ]);
        }

        $allPeriods = Period::query()->get();
        $coveredWeeks = $this->resolveCoveredWeeks($period, $allPeriods);
        $uploads = $this->resolveUploadsForWeeks($coveredWeeks);
        $summary = PeriodSummary::query()->where('period_id', $period->id)->first();

        $bdSourceCodes = DataSource::query()
            ->where('is_active', true)
            ->where('is_required_for_bd', true)
            ->pluck('code')
            ->values()
            ->all();

        $workflow = $this->resolveWorkflowState($uploads, $summary, null, $period->isCompound(), null, $bdSourceCodes, []);

        if (! $workflow['can_update_database']) {
            return back()->with('error', implode(' ', $workflow['blocking_reasons']) ?: 'Faltan fuentes obligatorias para actualizar la BD.');
        }

        // Guardia atómica contra doble envío (doble clic, dos pestañas): la revisión
        // de arriba lee fuera de lock, así que dos peticiones casi simultáneas
        // podían pasarla ambas y encolar dos cargas del mismo periodo. Se bloquea la
        // fila del periodo (serializa aunque todavía no exista ningún run) y se
        // vuelve a revisar dentro de la transacción antes de crear el run.
        $run = DB::transaction(function () use ($period) {
            DB::table('periods')->where('id', $period->id)->lockForUpdate()->first();

            $activeRun = PeriodDatabaseUpdateRun::query()
                ->where('period_id', $period->id)
                ->whereIn('status', ['queued', 'running'])
                ->lockForUpdate()
                ->first();

            if ($activeRun) {
                return null;
            }

            return PeriodDatabaseUpdateRun::query()->create([
                'period_id' => $period->id,
                'created_by' => auth()->id(),
                'status' => 'queued',
                'log' => 'Carga de registros en cola.',
                'queued_at' => now(),
                'started_at' => null,
            ]);
        });

        if (! $run) {
            throw ValidationException::withMessages([
                'period' => 'Ya hay una carga en proceso o en cola para este periodo.',
            ]);
        }

        UpdatePeriodDatabaseJob::dispatch($period->id, $run->id, auth()->id());

        return back()->with('success', 'El proceso fue enviado a cola. Te avisaremos por correo cuando termine.');
    }

    /**
     * Incidencias del periodo (Paso 3). Si aún no hay datos de NOI ni de recuperación, devuelve
     * has_data = false con un mensaje. Antes de responder refresca (ligero, sin regenerar la
     * radiografía) las incidencias «empleados sin sucursal» y «posibles coincidencias de
     * persona». Las incidencias de mora alta se ocultan.
     *
     * Efectos: puede crear/actualizar/borrar period_incidents de esos dos tipos.
     *
     * @param  Period  $period  Periodo.
     * @return JsonResponse {items: list<{id, type, severity, message, context}>, has_critical, has_data, message?}
     */
    public function incidents(Period $period)
    {
        $allPeriods = Period::all();
        $weeklyIds = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds,
            [$period->id]
        )));

        // Guard: if fact tables are empty, there's no real data to validate incidents against.
        // Return a clear "no data" state instead of 0 incidents (which is misleading).
        $hasFactData = DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)->exists()
                    || DB::table('fact_recoveries')->whereIn('period_id', $dataIds)->exists();

        if (! $hasFactData) {
            return response()->json([
                'items' => [],
                'has_critical' => false,
                'has_data' => false,
                'message' => 'Sin registros procesados para este periodo. Ejecuta primero "Cargar registros" (Etapa 2) para que las incidencias puedan calcularse.',
            ]);
        }

        // Lightweight refreshes: keep both counters in sync without full radiography
        $this->refreshEmpleadosSinSucursalIncident($period, $dataIds);
        $this->refreshCoincidenciasIncident($period, $dataIds);

        $summary = PeriodSummary::query()->where('period_id', $period->id)->with('incidents')->first();

        $items = $summary?->incidents
            ?->filter(fn ($i) => ! in_array($i->type, ['mora_alta', 'db_update.mora_alta'], true))
            ->map(fn ($incident) => [
                'id' => $incident->id,
                'type' => $incident->type,
                'severity' => $incident->severity,
                'message' => $incident->message,
                'context' => $incident->context,
            ])->values() ?? collect();

        return response()->json([
            'items' => $items,
            'has_critical' => (bool) ($items->contains(fn ($item) => $item['severity'] === 'high') ?? false),
            'has_data' => true,
        ]);
    }

    /**
     * Vuelve a calcular las incidencias del periodo y devuelve la lista nueva.
     * Se llama después de asignar sucursal a un colaborador para reflejar el estado actual.
     *
     * Efectos: ejecuta PeriodRadiographyService::generate() de forma síncrona (reescribe el
     * period_summary y sus incidencias); puede tardar.
     *
     * @param  Period  $period  Periodo.
     * @return JsonResponse {items, has_critical}
     */
    public function refreshIncidents(Period $period)
    {
        /** @var PeriodRadiographyService $svc */
        $svc = app(PeriodRadiographyService::class);
        $summary = $svc->generate($period);

        $items = $summary->incidents
            ->filter(fn ($i) => ! in_array($i->type, ['mora_alta', 'db_update.mora_alta'], true))
            ->map(fn ($incident) => [
                'id' => $incident->id,
                'type' => $incident->type,
                'severity' => $incident->severity,
                'message' => $incident->message,
                'context' => $incident->context,
            ])->values();

        return response()->json([
            'items' => $items,
            'has_critical' => (bool) $items->contains(fn ($item) => $item['severity'] === 'high'),
        ]);
    }

    /**
     * Personas con movimientos de nómina (NOI) en el periodo que no tienen sucursal asignada,
     * con diagnóstico de identidad para ayudar a resolverlas (Paso 3).
     *
     * Flujo: junta movimientos NOI sin asignación (máx. 500 filas), agrupa por nombre
     * normalizado (une NOI regular + fiscal de la misma persona), precarga una sola vez todo lo
     * que necesita PersonIdentityResolverService::buildResolutionTrace() (evita cientos de
     * consultas por persona) y clasifica cada persona por su mejor coincidencia:
     * score ≥ 100 exact_name_*, ≥ 80 fuzzy_match_*, ≥ 60 possible_match, si no needs_branch.
     * Sin datos NOI devuelve no_data = true. Solo lectura.
     *
     * @param  Period  $period  Periodo.
     * @return JsonResponse {items: list<persona con diagnosis, resolution_type, best_match>} o {items: [], no_data, message}
     */
    public function personasSinSucursal(Period $period)
    {
        $allPeriods = Period::all();
        $weeklyIds = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds,
            [$period->id]
        )));

        // Guard: if fact_noi_movements is empty, return no_data state.
        // This prevents showing a stale cached list in Vue or an empty list that
        // looks like "all employees are assigned" when data simply wasn't loaded.
        $hasNoi = DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)->exists();
        if (! $hasNoi) {
            return response()->json([
                'items' => [],
                'no_data' => true,
                'message' => 'Sin datos de nómina procesados para este periodo. Ejecuta primero "Cargar registros" para poder ver las personas sin sucursal asignada.',
            ]);
        }

        // Raw per-employee-per-source rows (employees that have no branch assignment)
        $rawItems = DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', function ($j) use ($period, $dataIds) {
                $j->on('eba.employee_id', '=', 'n.employee_id')
                    ->whereIn('eba.period_id', array_merge([$period->id], $dataIds));
            })
            ->leftJoin('employees as emp', 'n.employee_id', '=', 'emp.id')
            ->join('report_uploads as ru', 'n.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('n.period_id', $dataIds)
            ->whereNotNull('n.employee_id')
            ->whereNull('eba.branch_id')
            ->selectRaw('
                n.employee_id,
                COALESCE(emp.full_name, CONCAT("Emp #", n.employee_id)) AS nombre,
                COALESCE(emp.normalized_name, "") AS normalized_name,
                COALESCE(emp.employee_code, NULL) AS employee_code,
                ds.code AS fuente,
                COUNT(DISTINCT n.id) AS movimientos,
                SUM(n.amount) AS monto
            ')
            ->groupBy('n.employee_id', 'emp.full_name', 'emp.normalized_name', 'emp.employee_code', 'ds.id', 'ds.code')
            ->orderByDesc('monto')
            ->limit(500)
            ->get();

        // Group by normalized_name so NOI regular + NOI fiscal rows for the same person merge into one row
        $grouped = $rawItems->groupBy(function ($row) {
            return ($row->normalized_name !== '') ? $row->normalized_name : $row->nombre;
        });

        // Build one aggregated item per unique person
        $items = $grouped->map(function ($group) {
            $best = $group->sortByDesc('monto')->first();
            $employeeIds = $group->pluck('employee_id')->unique()->map(fn ($id) => (int) $id)->values()->all();
            $fuentes = $group->pluck('fuente')->unique()->sort()->values()->all();

            return (object) [
                'employee_ids' => $employeeIds,
                'employee_id' => (int) $best->employee_id,
                'nombre' => (string) $best->nombre,
                'normalized_name' => (string) $best->normalized_name,
                'employee_code' => $best->employee_code,
                'fuentes' => $fuentes,
                'movimientos' => (int) $group->sum('movimientos'),
                'monto' => (float) $group->sum('monto'),
            ];
        })->sortByDesc('monto')->values();

        // ── Pre-load data for diagnosis ──────────────────────────────────
        $allEmployeeIds = $items->flatMap(fn ($item) => $item->employee_ids)->unique()->values()->all();

        $allEmployees = Employee::query()
            ->whereNotNull('normalized_name')
            ->get(['id', 'full_name', 'normalized_name']);

        $rawMovements = DB::table('fact_noi_movements as n')
            ->join('report_uploads as ru', 'n.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('n.period_id', $dataIds)
            ->whereIn('n.employee_id', $allEmployeeIds)
            ->select('n.employee_id', 'n.movement_date', 'n.concept', 'n.amount', 'n.raw_payload', 'ds.code as source')
            ->orderByDesc('n.amount')
            ->get()
            ->groupBy('employee_id');

        $historicalBranches = DB::table('employee_branch_assignments as eba')
            ->join('branches as b', 'eba.branch_id', '=', 'b.id')
            ->whereIn('eba.employee_id', $allEmployeeIds)
            ->whereNotNull('eba.branch_id')
            ->orderByDesc('eba.period_id')
            ->select('eba.employee_id', 'b.name as branch_name')
            ->get()
            ->unique('employee_id')
            ->keyBy('employee_id');

        /** @var PersonIdentityResolverService $resolver */
        $resolver = app(PersonIdentityResolverService::class);

        // ── Preload everything buildResolutionTrace() would otherwise re-query PER ITEM ──
        // With 100+ unassigned people this turned "personas sin sucursal" into hundreds
        // of repeated full-table queries — the root cause of the endpoint hanging on
        // "Cargando personas…". Compute once here, reuse for every item below.
        $allEmployeeIdsGlobal = $allEmployees->pluck('id')->all();
        $candidateBranchesGlobal = empty($allEmployeeIdsGlobal) ? [] : DB::table('employee_branch_assignments as eba')
            ->join('branches as b', 'eba.branch_id', '=', 'b.id')
            ->whereIn('eba.employee_id', $allEmployeeIdsGlobal)
            ->whereNotNull('eba.branch_id')
            ->orderByDesc('eba.period_id')
            ->select('eba.employee_id', 'b.name as branch_name')
            ->get()
            ->unique('employee_id')
            ->pluck('branch_name', 'employee_id')
            ->all();

        $placementsPreloaded = empty($dataIds) ? collect() : DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('branch_id')
            ->selectRaw('normalized_promoter_name, MAX(promoter_code) as promoter_code, MAX(branch_id) as branch_id')
            ->groupBy('normalized_promoter_name')
            ->get()
            ->keyBy('normalized_promoter_name');

        $lendusPreloaded = DB::table('lendus_employee_directory')
            ->where('is_operational', true)
            ->whereNotNull('normalized_name')
            ->get(['id', 'codigo', 'nombre', 'normalized_name', 'puesto', 'estatus', 'inferred_branch_id', 'inferred_branch_name']);

        // fact_recoveries has no usable index for LOWER(promoter_name) — this was the
        // actual root cause of the endpoint hanging (a full scan of tens of thousands
        // of rows PER unassigned person). Aggregate once, scoped to this period.
        $contractsPreloaded = $resolver->buildContractsIndex($dataIds);

        $items = $items->map(function ($item) use (
            $resolver, $allEmployees, $rawMovements, $historicalBranches, $dataIds,
            $candidateBranchesGlobal, $placementsPreloaded, $lendusPreloaded, $contractsPreloaded,
        ) {
            // Merge sample movements across all employee_ids in the group
            $sampleMovs = collect(array_merge(
                ...array_map(fn ($eid) => ($rawMovements[(int) $eid] ?? collect())->all(), $item->employee_ids)
            ));

            $diagnosis = $resolver->buildResolutionTrace(
                employeeId: (int) $item->employee_id,
                fullName: (string) $item->nombre,
                allEmployees: $allEmployees,
                sampleMovs: $sampleMovs,
                employeeCode: $item->employee_code ?? null,
                historicalBranch: $historicalBranches[(int) $item->employee_id]->branch_name ?? null,
                dataIds: $dataIds,
                candidateBranchesPreloaded: $candidateBranchesGlobal,
                placementsPreloaded: $placementsPreloaded,
                lendusPreloaded: $lendusPreloaded,
                contractsPreloaded: $contractsPreloaded,
            );
            $item->diagnosis = $diagnosis;

            $bestMatch = $diagnosis['candidate_matches'][0] ?? null;
            $isGrouped = count($item->employee_ids) > 1;
            // Exact same normalized name = confirmed same person (from NOI regular + NOI fiscal or duplicates).
            // Always go directly to branch selector — never ask "¿Es la misma persona?".
            if ($bestMatch && $bestMatch['score'] >= 100.0) {
                $item->resolution_type = $bestMatch['branch'] ? 'exact_name_has_branch' : 'exact_name_no_branch';
            } elseif ($bestMatch && $bestMatch['score'] >= 80.0) {
                $item->resolution_type = $bestMatch['branch'] ? 'fuzzy_match_has_branch' : 'fuzzy_match_no_branch';
            } elseif ($bestMatch && $bestMatch['score'] >= 60.0) {
                $item->resolution_type = 'possible_match';
            } else {
                $item->resolution_type = 'needs_branch';
            }
            $item->best_match = $bestMatch;

            return $item;
        });

        return response()->json(['items' => $items->values()]);
    }

    /**
     * Confirma que dos registros de empleado son la misma persona.
     * Crea alias en ambos sentidos y hereda la sucursal del destino si existe.
     * Opcionalmente recibe canonical_name para unificar el nombre visible de ambos.
     *
     * Efectos: puede actualizar employees (full_name/normalized_name), crea/actualiza
     * employee_aliases, crea/actualiza employee_branch_assignments del periodo (match manual)
     * y refresca las incidencias de sin-sucursal y coincidencias. Errores → 500 con mensaje.
     *
     * @param  Period  $period  Periodo donde se asigna la sucursal.
     * @param  Request  $request  employee_id, target_employee_id, branch_id?, canonical_name?.
     * @return JsonResponse {ok, branch_assigned} o {ok: false, message} (500).
     */
    public function confirmarCoincidencia(Period $period, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'target_employee_id' => ['required', 'integer', 'exists:employees,id', 'different:employee_id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'canonical_name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $allPeriods = Period::all();
            $weeklyIds = $period->resolveBaseWeeklyIds($allPeriods);
            $dataIds = array_values(array_unique(array_merge(
                empty($weeklyIds) ? [] : $weeklyIds,
                [$period->id]
            )));

            $emp = Employee::findOrFail($validated['employee_id']);
            $target = Employee::findOrFail($validated['target_employee_id']);

            /** @var PersonIdentityResolverService $resolver */
            $resolver = app(PersonIdentityResolverService::class);

            // Apply canonical name if provided — update both employees
            $canonicalName = trim($validated['canonical_name'] ?? '');
            if ($canonicalName !== '') {
                $canonicalNorm = $resolver->normalizePersonName($canonicalName);
                if ($resolver->normalizePersonName($emp->full_name) !== $canonicalNorm) {
                    $emp->update(['full_name' => $canonicalName, 'normalized_name' => $canonicalNorm]);
                }
                if ($resolver->normalizePersonName($target->full_name) !== $canonicalNorm) {
                    $target->update(['full_name' => $canonicalName, 'normalized_name' => $canonicalNorm]);
                }
                $emp->refresh();
                $target->refresh();
            }

            // Save alias in both directions (if names differ after canonical update)
            $normEmp = $resolver->normalizePersonName($emp->full_name);
            $normTarget = $resolver->normalizePersonName($target->full_name);
            if ($normEmp !== $normTarget) {
                EmployeeAlias::query()->updateOrCreate(
                    ['employee_id' => $target->id, 'normalized_alias' => $normEmp],
                    ['alias_name' => $emp->full_name, 'source' => 'confirmed_match', 'confidence' => 1.00, 'created_by' => auth()->id()]
                );
                EmployeeAlias::query()->updateOrCreate(
                    ['employee_id' => $emp->id, 'normalized_alias' => $normTarget],
                    ['alias_name' => $target->full_name, 'source' => 'confirmed_match', 'confidence' => 1.00, 'created_by' => auth()->id()]
                );
            }

            // Resolve which branch to use
            $branchId = $validated['branch_id']
                ?? $resolver->resolveBranchFromExistingAssignments($target->id)
                ?? $resolver->resolveBranchFromCanonicalEmployee($emp);

            if ($branchId) {
                EmployeeBranchAssignment::query()->updateOrCreate(
                    ['period_id' => $period->id, 'employee_id' => $emp->id],
                    [
                        'branch_id' => $branchId,
                        'source_type' => 'manual',
                        'source_reference' => "Confirmado como misma persona que #{$target->id} — {$target->full_name}",
                        'match_type' => 'manual',
                        'confidence' => 1.00,
                        'was_manual_reviewed' => true,
                        'notes' => "Unificado manualmente. Alias confirmado con \"{$target->full_name}\".",
                    ]
                );
            }

            // Lightweight refreshes — no full radiography needed
            $this->refreshEmpleadosSinSucursalIncident($period, $dataIds);
            $this->refreshCoincidenciasIncident($period, $dataIds);

            return response()->json(['ok' => true, 'branch_assigned' => (bool) $branchId]);

        } catch (\Throwable $e) {
            Log::error('confirmarCoincidencia error', [
                'period_id' => $period->id,
                // Fase 10: solo IDs — sin nombres de personas (canonical_name) en el log.
                'payload' => $request->only(['employee_id', 'target_employee_id', 'branch_id']),
                'error' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'No se pudo confirmar la coincidencia: '.$e->getMessage(),
                'error' => app()->isLocal() ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Recalcula de forma ligera la incidencia «empleados_sin_sucursal» del periodo: cuenta
     * empleados con NOI sin asignación de sucursal, su monto y personas únicas. La borra si ya
     * no hay ninguno; si no, la crea o actualiza (severidad high si hay impacto monetario).
     * No hace nada si el periodo aún no tiene period_summary.
     *
     * @param  Period  $period  Periodo.
     * @param  list<int>  $dataIds  Periodos con datos (semanas base + el propio periodo).
     */
    private function refreshEmpleadosSinSucursalIncident(Period $period, array $dataIds): void
    {
        $summary = PeriodSummary::query()->where('period_id', $period->id)->first();
        if (! $summary) {
            return;
        }

        // Count raw employee_id records without branch
        $sinSucursal = DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', function ($j) use ($period, $dataIds) {
                $j->on('eba.employee_id', '=', 'n.employee_id')
                    ->whereIn('eba.period_id', array_merge([$period->id], $dataIds));
            })
            ->whereIn('n.period_id', $dataIds)
            ->whereNotNull('n.employee_id')
            ->whereNull('eba.branch_id')
            ->distinct('n.employee_id')
            ->count('n.employee_id');

        $existing = PeriodIncident::query()
            ->where('period_summary_id', $summary->id)
            ->where('type', 'empleados_sin_sucursal')
            ->first();

        if ($sinSucursal === 0) {
            $existing?->delete();

            return;
        }

        $sinSucursalMonto = (float) DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', function ($j) use ($period, $dataIds) {
                $j->on('eba.employee_id', '=', 'n.employee_id')
                    ->whereIn('eba.period_id', array_merge([$period->id], $dataIds));
            })
            ->whereIn('n.period_id', $dataIds)
            ->whereNotNull('n.employee_id')
            ->whereNull('eba.branch_id')
            ->sum('n.amount');

        // Count unique persons (grouped by normalized_name) — for UI display
        $uniquePersons = (int) DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', function ($j) use ($period, $dataIds) {
                $j->on('eba.employee_id', '=', 'n.employee_id')
                    ->whereIn('eba.period_id', array_merge([$period->id], $dataIds));
            })
            ->leftJoin('employees as emp', 'n.employee_id', '=', 'emp.id')
            ->whereIn('n.period_id', $dataIds)
            ->whereNotNull('n.employee_id')
            ->whereNull('eba.branch_id')
            ->whereNotNull('emp.normalized_name')
            ->distinct('emp.normalized_name')
            ->count('emp.normalized_name');

        // If some employees have no normalized_name, fall back to raw count
        if ($uniquePersons === 0) {
            $uniquePersons = $sinSucursal;
        }

        $severity = abs($sinSucursalMonto) > 0 ? 'high' : 'warning';
        $ctx = [
            'count' => $sinSucursal,
            'unique_persons_count' => $uniquePersons,
            'monto' => $sinSucursalMonto,
        ];

        if ($existing) {
            $existing->update([
                'severity' => $severity,
                'message' => "{$sinSucursal} empleado(s) del NOI no tienen sucursal asignada. Sus datos no aparecen en reportes por sucursal."
                    .($severity === 'high' ? ' Impacto monetario: $'.number_format(abs($sinSucursalMonto), 2) : ''),
                'context' => $ctx,
            ]);
        } else {
            PeriodIncident::query()->create([
                'period_summary_id' => $summary->id,
                'type' => 'empleados_sin_sucursal',
                'severity' => $severity,
                'message' => "{$sinSucursal} empleado(s) del NOI no tienen sucursal asignada. Sus datos no aparecen en reportes por sucursal."
                    .($severity === 'high' ? ' Impacto monetario: $'.number_format(abs($sinSucursalMonto), 2) : ''),
                'context' => $ctx,
            ]);
        }
    }

    /**
     * Recalcula de forma ligera la incidencia «posibles_coincidencias_persona».
     * Vuelve a detectar coincidencias (excluye pares descartados o con alias) y crea,
     * actualiza o borra la incidencia; guarda hasta 10 ejemplos en el contexto.
     * No hace nada si el periodo aún no tiene period_summary.
     *
     * @param  Period  $period  Periodo.
     * @param  list<int>  $dataIds  Periodos con datos (semanas base + el propio periodo).
     */
    private function refreshCoincidenciasIncident(Period $period, array $dataIds): void
    {
        $summary = PeriodSummary::query()->where('period_id', $period->id)->first();
        if (! $summary) {
            return;
        }

        /** @var PeriodRadiographyService $svc */
        $svc = app(PeriodRadiographyService::class);
        $coincidencias = $svc->detectCoincidencias($dataIds);

        $existing = PeriodIncident::query()
            ->where('period_summary_id', $summary->id)
            ->where('type', 'posibles_coincidencias_persona')
            ->first();

        if (empty($coincidencias)) {
            $existing?->delete();

            return;
        }

        $coincidenciaIds = array_unique(array_merge(
            array_column($coincidencias, 'a_id'),
            array_column($coincidencias, 'b_id'),
        ));
        $monetaryAmount = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereIn('employee_id', $coincidenciaIds)
            ->sum('amount');
        $hasMoney = abs($monetaryAmount) > 0;

        $data = [
            'type' => 'posibles_coincidencias_persona',
            'severity' => $hasMoney ? 'high' : 'warning',
            'message' => count($coincidencias).' posible(s) coincidencia(s) de persona detectada(s). Verifica si se trata de la misma persona escrita de forma diferente.'
                .($hasMoney ? ' Tienen impacto monetario: $'.number_format(abs($monetaryAmount), 2).'.' : ''),
            'context' => [
                'count' => count($coincidencias),
                'monto' => $monetaryAmount,
                'samples' => array_slice($coincidencias, 0, 10),
            ],
        ];

        if ($existing) {
            $existing->update($data);
        } else {
            PeriodIncident::query()->create(['period_summary_id' => $summary->id, ...$data]);
        }
    }

    /**
     * Descarta de forma permanente un par de posible coincidencia («no son la misma persona»)
     * para que no vuelva a aparecer. Guarda employee_match_rejections (par ordenado min/max)
     * y refresca la incidencia de coincidencias.
     *
     * @param  Period  $period  Periodo desde el que se descarta.
     * @param  Request  $request  employee_id_a, employee_id_b (distintos).
     * @return JsonResponse {ok: true}
     */
    public function descartarCoincidencia(Period $period, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id_a' => ['required', 'integer', 'exists:employees,id'],
            'employee_id_b' => ['required', 'integer', 'exists:employees,id', 'different:employee_id_a'],
        ]);

        $pairKey = EmployeeMatchRejection::pairKey(
            (int) $validated['employee_id_a'],
            (int) $validated['employee_id_b']
        );

        EmployeeMatchRejection::query()->updateOrCreate(
            ['pair_key' => $pairKey],
            [
                'employee_id_a' => min((int) $validated['employee_id_a'], (int) $validated['employee_id_b']),
                'employee_id_b' => max((int) $validated['employee_id_a'], (int) $validated['employee_id_b']),
                'period_id' => $period->id,
                'reason' => 'Descartado manualmente — no son la misma persona.',
                'created_by' => auth()->id(),
            ]
        );

        $allPeriods = Period::all();
        $weeklyIds = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds,
            [$period->id]
        )));
        $this->refreshCoincidenciasIncident($period, $dataIds);

        return response()->json(['ok' => true]);
    }

    /**
     * Marca como resuelta una incidencia de «ubicación pendiente» con la acción elegida
     * (mapear a sucursal, excluir, corporativo o sucursal cerrada). Solo actualiza la
     * incidencia (severity = resolved + contexto de quién/cuándo/cómo); no reasigna datos.
     *
     * @param  Period  $period  Periodo (la incidencia debe pertenecerle; si no, 404).
     * @param  Request  $request  incident_id, action, branch_id?.
     * @return RedirectResponse De regreso con mensaje flash.
     */
    public function resolvePendingLocation(Period $period, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'incident_id' => ['required', 'integer'],
            'action' => ['required', 'in:map_to_branch,exclude,mark_corporate,mark_closed'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $incident = PeriodIncident::findOrFail($validated['incident_id']);
        abort_unless($incident->periodSummary?->period_id === $period->id, 404);

        $actionLabel = match ($validated['action']) {
            'map_to_branch' => 'Mapeada a sucursal',
            'exclude' => 'Excluida del reporte',
            'mark_corporate' => 'Marcada como Corporativo',
            'mark_closed' => 'Marcada como sucursal cerrada',
        };

        $branchName = null;
        if ($validated['branch_id']) {
            $branchName = Branch::find($validated['branch_id'])?->name;
        }

        $incident->update([
            'severity' => 'resolved',
            'context' => array_merge($incident->context ?? [], [
                'resolved_action' => $validated['action'],
                'resolved_branch' => $branchName,
                'resolved_by' => auth()->id(),
                'resolved_at' => now()->toDateTimeString(),
            ]),
        ]);

        return back()->with('success', "Ubicación resuelta: {$actionLabel}.");
    }

    /**
     * Marca una incidencia como resuelta manualmente (severity = resolved) con nota opcional.
     *
     * @param  Period  $period  Periodo (la incidencia debe pertenecerle; si no, 404).
     * @param  PeriodIncident  $incident  Incidencia a resolver.
     * @param  Request  $request  resolution_note (opcional).
     * @return RedirectResponse De regreso con mensaje flash.
     */
    public function resolveIncident(Period $period, PeriodIncident $incident, Request $request): RedirectResponse
    {
        abort_unless($incident->periodSummary?->period_id === $period->id, 404);

        $incident->update([
            'severity' => 'resolved',
            'context' => array_merge($incident->context ?? [], [
                'resolved_by' => auth()->id(),
                'resolved_at' => now()->toDateTimeString(),
                'resolution_note' => (string) $request->input('resolution_note', 'Resuelta manualmente.'),
            ]),
        ]);

        return back()->with('success', 'Incidencia resuelta correctamente.');
    }

    /**
     * Encola la generación de la Radiografía (Paso 5) con la configuración del Paso 4.
     *
     * Reglas: periodos automáticos exigen que todos sus meses componentes tengan reporte
     * vigente; el estado del flujo (resuelto por la identidad exacta del reporte pedido) debe
     * permitir generar; alcance general exige sucursales oficiales incluidas; alcance sucursal
     * exige una sucursal operativa; alcance gestor exige empleado (y convierte el «gasto
     * general por gestor» en un manual_adjustment EFÍMERO dentro del config, sin escribir
     * ninguna tabla). Crea el run con su identidad en una transacción con bloqueo del periodo
     * (un solo run activo por periodo) y despacha GenerateRadiographyJob.
     *
     * @param  Period  $period  Periodo a reportar.
     * @param  Request  $request  config{report_type, scope, branch_id, employee_id, compare_period_id,
     *                            included_branch_ids, extra_employee_expense_amount, extra_employee_expense_notes, ...}.
     * @return RedirectResponse De regreso con mensaje flash de éxito o del motivo de bloqueo.
     */
    public function generateRadiography(Period $period, Request $request): RedirectResponse
    {
        $allPeriods = Period::query()->get();

        // Bloquear periodos automáticos si sus meses componentes no tienen reportes generados
        if ($period->isAutoGenerated()) {
            $componentIds = collect($period->component_period_ids ?? [])->map(fn ($id) => (int) $id)->all();
            $compSummaries = PeriodSummary::query()->whereIn('period_id', $componentIds)->get()->keyBy('period_id');
            $missingMonths = [];
            foreach ($allPeriods->whereIn('id', $componentIds) as $comp) {
                $compSummary = $compSummaries->get($comp->id);
                if (! $compSummary || $compSummary->status !== 'generated' || $compSummary->invalidated_at) {
                    $missingMonths[] = $comp->label;
                }
            }
            if (! empty($missingMonths)) {
                return back()->with('error', 'No se puede generar todavía. Faltan reportes mensuales: '.implode(', ', $missingMonths).'.');
            }
        }

        $coveredWeeks = $this->resolveCoveredWeeks($period, $allPeriods);
        $uploads = $this->resolveUploadsForWeeks($coveredWeeks);
        $summary = PeriodSummary::query()->where('period_id', $period->id)->with('incidents')->first();

        // PROBLEMA 3 (auditoría 27-ago-2026): antes se usaba el ÚLTIMO INTENTO del
        // periodo SIN IMPORTAR identidad ("->where('period_id', ...)->latest('id')")
        // para decidir can_generate_radiography/blocking_reasons — un comparativo o un
        // reporte por sucursal/gestor fallido podía bloquear (o un success ajeno podía
        // desbloquear) la generación de un alcance completamente distinto. La
        // configuración (scope/report_type/branch/employee/comparación) ya está
        // disponible en el request en este punto — se usa para resolver por identidad
        // exacta, igual que generationProgress().
        $config = $request->input('config', []);
        $scope = $config['scope'] ?? 'general';
        $type = ($config['report_type'] ?? 'simple') === 'simple' ? 'Radiografía simple' : 'Reporte comparativo';

        $identityForGate = [
            'period_id' => $period->id,
            'report_type' => $config['report_type'] ?? 'simple',
            'scope' => $scope,
            'branch_id' => $scope === 'branch' ? (int) ($config['branch_id'] ?? 0) ?: null : null,
            'employee_id' => $scope === 'employee' ? (int) ($config['employee_id'] ?? 0) ?: null : null,
            'comparison_period_id' => ! empty($config['compare_period_id']) ? (int) $config['compare_period_id'] : null,
        ];
        ['latest' => $latestRun, 'latest_success' => $latestSuccessRun] = PeriodRadiographyRun::resolveForIdentity($identityForGate);

        // Comparativos coherentes (23-nov-2026): solo entre periodos del MISMO tipo (mes con
        // mes, bimestre con bimestre…). El alcance (general/sucursal/gestor) es uno solo para
        // ambos lados, así que tampoco se puede comparar una sucursal contra un gestor.
        if (! empty($config['compare_period_id'])) {
            $comparePeriod = Period::query()->find((int) $config['compare_period_id']);
            if (! $comparePeriod || $comparePeriod->id === $period->id) {
                return back()->with('error', 'Selecciona un periodo distinto para comparar.');
            }
            if ($comparePeriod->type !== $period->type) {
                return back()->with('error', "No se puede comparar {$period->shortLabel()} con {$comparePeriod->shortLabel()}: solo se comparan periodos del mismo tipo (mes con mes, bimestre con bimestre…).");
            }
        }

        // Regeneración rápida (23-nov-2026): solo para reflejar clasificaciones de la
        // revisión de gastos sobre un reporte YA generado (mismos registros, solo cambian
        // asignaciones). El job omite la revisión de asignaciones y las atribuciones
        // automáticas; recalcula métricas, consolidado, Excel y PDF.
        $config['regeneracion_rapida'] = (bool) ($config['regeneracion_rapida'] ?? false);
        if ($config['regeneracion_rapida'] && ! $latestSuccessRun) {
            return back()->with('error', 'Este reporte todavía no se ha generado. Genéralo completo una vez; después podrás actualizarlo con tus clasificaciones.');
        }

        $bdSourceCodes = DataSource::query()->where('is_active', true)->where('is_required_for_bd', true)->pluck('code')->values()->all();
        $reportSourceCodes = DataSource::query()->where('is_active', true)->where('is_required_for_report', true)->pluck('code')->values()->all();

        $workflow = $this->resolveWorkflowState($uploads, $summary, $latestRun, $period->isCompound(), null, $bdSourceCodes, $reportSourceCodes, false, $latestSuccessRun);

        if (! $workflow['can_generate_radiography']) {
            return back()->with('error', implode(' ', $workflow['blocking_reasons']) ?: 'No se puede generar la Radiografía todavía.');
        }

        // Validate scope config before queuing — backend must reject invalid configs even if frontend fails.
        if ($scope === 'general') {
            $included = array_filter(array_map('intval', $config['included_branch_ids'] ?? []));
            if (empty($included)) {
                return back()->with('error', 'No se puede generar el reporte porque falta configurar correctamente el alcance. Selecciona al menos una sucursal oficial.');
            }
            // Verify all included branches are operative
            $validIds = Branch::query()->whereIn('name', self::OPERATIVE_BRANCH_NAMES)->pluck('id')->all();
            $invalid = array_diff($included, $validIds);
            if (! empty($invalid)) {
                return back()->with('error', 'No se puede generar el reporte: included_branch_ids contiene sucursales no oficiales. Revisa la configuración del alcance.');
            }
        } elseif ($scope === 'branch') {
            $branchId = (int) ($config['branch_id'] ?? 0);
            if (! $branchId) {
                return back()->with('error', 'No se puede generar el reporte porque falta seleccionar una sucursal.');
            }
            $valid = Branch::query()->where('id', $branchId)->whereIn('name', self::OPERATIVE_BRANCH_NAMES)->exists();
            if (! $valid) {
                return back()->with('error', 'La sucursal seleccionada no es operativa. Selecciona una sucursal oficial.');
            }
        } elseif ($scope === 'employee') {
            if (empty($config['employee_id'])) {
                return back()->with('error', 'No se puede generar el reporte porque falta seleccionar un empleado o gestor.');
            }

            // "Gasto general por gestor" capturado en Etapa 4 — reversión 07-sep-2026
            // (cierre): YA NO se persiste en EmployeePeriodManualExpenseService (ZERO
            // WRITES, ver auditoría). Se convierte a la forma efímera
            // manual_adjustment y viaja DENTRO del config del run — GenerateRadiographyJob
            // pasa este mismo $config a RadiografiaExportService::exportWithConfig()/
            // exportPdfWithConfig(), que ya lo aplican vía
            // RadiographySnapshotBuilder::applyEmployeeScope(). El archivo generado
            // por este run refleja el ajuste (es la salida de ESTA generación puntual),
            // pero nada queda escrito en ninguna tabla de gasto manual.
            if (array_key_exists('extra_employee_expense_amount', $config) && (float) $config['extra_employee_expense_amount'] > 0) {
                $config['manual_adjustment'] = [
                    'scope' => 'employee',
                    'employee_id' => (int) $config['employee_id'],
                    'amount' => round((float) $config['extra_employee_expense_amount'], 2),
                    'notes' => (string) ($config['extra_employee_expense_notes'] ?? ''),
                ];
            }
        }

        // Guardia atómica contra doble envío (doble click, dos pestañas, etc.): el
        // chequeo de resolveWorkflowState() arriba lee el último run fuera de lock,
        // así que dos requests casi simultáneas pueden pasarlo ambas. Con el lock
        // aquí, la segunda request ve el run "queued/running" recién creado por la
        // primera y aborta antes de encolar un segundo job para el mismo periodo.
        $run = DB::transaction(function () use ($period, $type, $scope, $config) {
            // Bloquea la fila del periodo: serializa también el caso en que aún no
            // existe ningún run (lockForUpdate sobre un resultado vacío no bloquea).
            DB::table('periods')->where('id', $period->id)->lockForUpdate()->first();

            $activeRun = PeriodRadiographyRun::query()
                ->where('period_id', $period->id)
                ->whereIn('status', ['queued', 'running'])
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($activeRun) {
                return null;
            }

            // Identidad fijada DESDE LA CREACIÓN (no solo cuando el job la backfillea al
            // arrancar) — así generationProgress() puede ubicar el run correcto por
            // identidad completa desde el primer poll, sin una ventana donde el run
            // exista con report_type/scope en NULL. Ver PeriodRadiographyRun::identity().
            return PeriodRadiographyRun::query()->create([
                'period_id' => $period->id,
                'report_type' => $config['report_type'] ?? 'simple',
                'scope' => $scope,
                'branch_id' => $scope === 'branch' ? (int) ($config['branch_id'] ?? 0) ?: null : null,
                'employee_id' => $scope === 'employee' ? (int) ($config['employee_id'] ?? 0) ?: null : null,
                'comparison_period_id' => ! empty($config['compare_period_id']) ? (int) $config['compare_period_id'] : null,
                'status' => 'queued',
                'queued_at' => now(),
                'created_by' => auth()->id(),
                'log' => "Radiografía en cola. Se generará un {$type} con alcance {$scope}.",
                'metadata' => ['config' => $config, 'progress_percent' => 0, 'current_step' => 'En cola'],
            ]);
        });

        if (! $run) {
            return back()->with('error', 'Ya hay una generación en curso para este periodo. Espera a que termine antes de volver a generarla.');
        }

        GenerateRadiographyJob::dispatch($period->id, auth()->id(), $run->id, $config);

        return back()->with('success', 'La Radiografía se está generando en segundo plano. Recibirás un correo cuando esté lista.');
    }

    /**
     * Endpoint JSON: progreso del run de generación de la radiografía.
     * Lo consulta cada 3 s el componente GenerateReportStep.vue.
     *
     * Si llegan parámetros de identidad (scope/report_type/branch_id/employee_id/
     * compare_period_id) se resuelve el último intento y el último éxito de ESA identidad;
     * si no, el último run del periodo (compatibilidad). Calcula tiempo transcurrido y aviso
     * de atasco (en cola ≥ 5 min, corriendo ≥ 30 min) y las URL de Excel/PDF/vista previa del
     * último éxito. Solo lectura.
     *
     * @param  Request  $request  Query opcional con la identidad del reporte.
     * @param  Period  $period  Periodo.
     * @return JsonResponse {status, run_id, log, error_message, error_code, tiempos,
     *                      stuck_warning, metadata, excel_url, pdf_url, preview_url, can_export, ...} o {status: null}.
     */
    public function generationProgress(Request $request, Period $period): JsonResponse
    {
        // Identidad del reporte que el wizard tiene configurado ahora mismo (Etapa 4) —
        // sin esto, "el último run del periodo" podía pertenecer a un reporte DISTINTO
        // (comparativo/por sucursal/por gestor) generado después, y Etapa 5 mostraba su
        // estado/errores en vez de los del reporte que el usuario realmente está viendo.
        // Si el frontend no manda identidad (compatibilidad hacia atrás), cae al
        // comportamiento anterior (el run más reciente de cualquier alcance).
        $hasIdentityParams = $request->has('scope') || $request->has('report_type');

        $identity = [
            'period_id' => $period->id,
            'report_type' => $request->query('report_type', 'simple'),
            'scope' => $request->query('scope', 'general'),
            'branch_id' => $request->query('branch_id') ? (int) $request->query('branch_id') : null,
            'employee_id' => $request->query('employee_id') ? (int) $request->query('employee_id') : null,
            'comparison_period_id' => $request->query('compare_period_id') ? (int) $request->query('compare_period_id') : null,
        ];

        if ($hasIdentityParams) {
            // PROBLEMA 2/4/5: 'latest' describe el INTENTO más reciente de ESTA
            // identidad (para el card de Etapa 5 — processing/failed/success en vivo).
            // 'latest_success' es el que de verdad tiene Excel/PDF descargables — puede
            // ser distinto de 'latest' si el intento más reciente falló pero uno
            // anterior de la MISMA identidad sí terminó bien. Nunca deben mezclarse.
            ['latest' => $run, 'latest_success' => $latestSuccessRun] = PeriodRadiographyRun::resolveForIdentity($identity);
        } else {
            // Compatibilidad hacia atrás (sin scope/report_type en la query): el
            // comportamiento anterior — el run más reciente de cualquier alcance.
            $run = PeriodRadiographyRun::query()->where('period_id', $period->id)->latest('id')->first();
            $latestSuccessRun = ($run && $run->status === 'success') ? $run : null;
        }

        if (! $run) {
            return response()->json(['status' => null]);
        }

        $status = $run->status;
        $isQueued = $status === 'queued';
        $running = in_array($status, ['queued', 'running'], true);

        $elapsedSeconds = null;
        $stuckWarning = false;

        if ($running) {
            $ref = $isQueued
                ? ($run->queued_at ?? $run->created_at)
                : ($run->started_at ?? $run->queued_at ?? $run->created_at);
            $elapsedSeconds = $ref ? max(0, (int) now()->diffInSeconds($ref)) : null;
            $stuckWarning = $elapsedSeconds !== null && (
                ($isQueued && $elapsedSeconds >= 300) ||
                (! $isQueued && $elapsedSeconds >= 1800)
            );
        } elseif ($run->started_at && $run->finished_at) {
            $elapsedSeconds = max(0, (int) $run->started_at->diffInSeconds($run->finished_at));
        }

        // Un comparativo/por-sucursal/por-gestor nunca debe apuntar a la ruta plana
        // del reporte simple del periodo — se resuelve por el run específico.
        $isSimpleGeneral = ($run->report_type ?: 'simple') === 'simple' && ($run->scope ?: 'general') === 'general';

        // PROBLEMA 2/4/5: excel_url/pdf_url/preview_url se resuelven contra
        // $latestSuccessRun (el último ÉXITO de ESTA identidad), NUNCA contra $run —
        // si $run es un intento MÁS RECIENTE que falló, antes esto devolvía null y
        // Etapa 5/7 perdían los enlaces de descarga de una versión anterior que seguía
        // siendo válida. can_export solo describe si HAY algo descargable ahora mismo
        // para esta identidad exacta — independiente de si el intento más reciente
        // (mostrado en $status) está processing/failed.
        $excelUrl = $latestSuccessRun?->output_excel_path
            ? ($isSimpleGeneral
                ? route('reportes-mensuales.export-radiography', $period->id)
                : route('reportes-mensuales.run-excel', $latestSuccessRun->id))
            : null;
        $pdfUrl = $latestSuccessRun?->output_pdf_path
            ? ($isSimpleGeneral
                ? route('reportes-mensuales.export-radiography-pdf', $period->id)
                : route('reportes-mensuales.run-pdf', $latestSuccessRun->id))
            : null;
        $previewUrl = $latestSuccessRun
            ? ($isSimpleGeneral
                ? route('reportes-mensuales.preview', $period->id)
                : route('reportes-mensuales.run-ver', $latestSuccessRun->id))
            : null;

        return response()->json([
            'status' => $status,
            'run_id' => $run->id,
            'report_type' => $run->report_type ?: 'simple',
            'scope' => $run->scope ?: 'general',
            'log' => $run->log ? mb_strimwidth($run->log, 0, 300) : null,
            'error_message' => $run->error_message ? mb_strimwidth($run->error_message, 0, 300) : null,
            // PROBLEMA 7: código corto para que la UI clasifique el fallo sin adivinar
            // a partir de texto libre (ver GenerateRadiographyJob::publicErrorCode()).
            'error_code' => is_array($run->metadata) ? ($run->metadata['error_code'] ?? null) : null,
            'queued_at' => optional($run->queued_at ?? $run->created_at)->format('d/m/Y H:i'),
            'started_at' => $isQueued ? null : optional($run->started_at)->format('d/m/Y H:i'),
            'finished_at' => optional($run->finished_at)->format('d/m/Y H:i'),
            'elapsed_seconds' => $elapsedSeconds,
            'stuck_warning' => $stuckWarning,
            'metadata' => $run->metadata,
            'excel_url' => $excelUrl,
            'pdf_url' => $pdfUrl,
            'preview_url' => $previewUrl,
            'can_export' => $latestSuccessRun !== null,
            'latest_success_run_id' => $latestSuccessRun?->id,
            'can_process_now' => ! app()->isProduction() && $isQueued,
        ]);
    }

    /**
     * Cancela la generación activa (en cola o corriendo) más reciente del periodo: marca el run
     * como cancelled con quién y cuándo. No borra el job de la cola (el job debe detectar la
     * cancelación por el estado del run).
     *
     * @param  Period  $period  Periodo.
     * @return RedirectResponse De regreso con mensaje flash.
     */
    public function cancelGeneration(Period $period): RedirectResponse
    {
        $run = PeriodRadiographyRun::query()
            ->where('period_id', $period->id)
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if (! $run) {
            return back()->with('error', 'No hay una generación activa para cancelar en este periodo.');
        }

        $cancelledBy = auth()->user()?->name ?? 'usuario';
        $run->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'cancelled_at' => now(),
            'cancelled_by' => auth()->id(),
            'log' => "Generación cancelada por {$cancelledBy}.",
            'error_message' => 'Cancelado manualmente.',
            'metadata' => array_merge(is_array($run->metadata) ? $run->metadata : [], [
                'current_step' => 'Cancelado',
            ]),
        ]);

        return back()->with('success', 'La generación fue cancelada. Puedes reintentarla cuando estés listo.');
    }

    /**
     * Ejecuta de forma síncrona el run de generación en cola (solo local/debug; 403 en
     * producción). Útil cuando no hay worker de colas corriendo.
     *
     * @param  Period  $period  Periodo.
     * @return RedirectResponse De regreso con mensaje flash (éxito o «terminó con error»).
     */
    public function processGenerationNow(Period $period): RedirectResponse
    {
        abort_if(app()->isProduction(), 403, 'Solo disponible en entorno local/debug.');

        $run = PeriodRadiographyRun::query()
            ->where('period_id', $period->id)
            ->where('status', 'queued')
            ->latest('id')
            ->first();

        if (! $run) {
            return back()->with('error', 'No hay una generación en cola para procesar.');
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        try {
            $config = is_array($run->metadata) ? ($run->metadata['config'] ?? []) : [];
            $job = new GenerateRadiographyJob($period->id, auth()->id(), $run->id, $config);
            app()->call([$job, 'handle']);

            return back()->with('success', 'Generación completada. Descarga Excel y PDF desde el paso de reporte.');
        } catch (\Throwable $e) {
            return back()->with('info', 'La generación terminó con error. Revisa el detalle en la etapa de generar reporte.');
        }
    }

    /**
     * Envía a reprocesar en cola las fuentes del periodo en estado pending/failed que NO son
     * fuentes de BD (las de BD se procesan con «Cargar registros»). Un ReprocessReportUploadJob
     * por archivo.
     *
     * @param  Period  $period  Periodo (solo archivos con period_id igual a este periodo).
     * @return RedirectResponse De regreso con mensaje flash.
     */
    public function processPendingSources(Period $period): RedirectResponse
    {
        $bdSourceCodes = DataSource::query()
            ->where('is_active', true)
            ->where('is_required_for_bd', true)
            ->pluck('code')
            ->values()
            ->all();

        $pendingUploads = ReportUpload::query()
            ->with('dataSource')
            ->where('period_id', $period->id)
            ->whereIn('status', ['pending', 'failed'])
            ->get()
            ->filter(fn ($u) => ! in_array($u->dataSource?->code, $bdSourceCodes, true))
            ->values();

        if ($pendingUploads->isEmpty()) {
            return back()->with('info', 'No hay fuentes pendientes de procesar para este periodo.');
        }

        foreach ($pendingUploads as $upload) {
            ReprocessReportUploadJob::dispatch($upload->id, auth()->id());
        }

        $count = $pendingUploads->count();
        $names = $pendingUploads->map(fn ($u) => $u->dataSource?->name ?? $u->id)->implode(', ');

        return back()->with('success', "Se enviaron {$count} fuente(s) a procesamiento: {$names}. Recibirás un correo cuando terminen.");
    }

    /**
     * Reprocesa en cola el último archivo con error de NOI Nómina, NOI Fiscal y Rotación, y el
     * de IMSS si falló o si quedó «procesado» pero sin monto o con gastos IMSS sin sucursal.
     *
     * @param  Period  $period  Periodo (solo archivos con period_id igual a este periodo).
     * @return RedirectResponse De regreso con mensaje flash.
     */
    public function reprocessFailedSources(Period $period): RedirectResponse
    {
        $targetCodes = ['noi_nomina', 'noi_nomina_fiscal', 'rotacion'];

        $latestByCode = ReportUpload::query()
            ->with('dataSource')
            ->where('period_id', $period->id)
            ->whereIn('status', ['failed', 'processed'])
            ->get()
            ->groupBy(fn ($u) => $u->dataSource?->code)
            ->map(fn ($group) => $group->sortByDesc('id')->first());

        $toReprocess = collect();

        foreach ($targetCodes as $code) {
            $upload = $latestByCode->get($code);
            $status = (string) ($upload?->status?->value ?? $upload?->status);
            if ($upload && $status === 'failed') {
                $toReprocess->push($upload);
            }
        }

        $imssUpload = $latestByCode->get('imss');
        if ($imssUpload) {
            $imssStatus = (string) ($imssUpload->status?->value ?? $imssUpload->status);
            $imssQuery = Expense::query()->where('period_id', $period->id)->where('category', 'IMSS');
            $imssSum = (float) (clone $imssQuery)->sum('amount');
            $hasUnassigned = (clone $imssQuery)->whereNull('branch_id')->exists();
            if ($imssStatus === 'failed' || ($imssStatus === 'processed' && ($imssSum <= 0 || $hasUnassigned))) {
                $toReprocess->push($imssUpload);
            }
        }

        if ($toReprocess->isEmpty()) {
            return back()->with('info', 'No hay fuentes en error para reprocesar (NOI Nómina, NOI Fiscal, Rotación e IMSS están correctas).');
        }

        foreach ($toReprocess as $upload) {
            ReprocessReportUploadJob::dispatch($upload->id, auth()->id());
        }

        $names = $toReprocess->map(fn ($u) => $u->dataSource?->name ?? $u->id)->implode(', ');

        return back()->with('success', "Se enviaron {$toReprocess->count()} fuente(s) con error a reprocesamiento: {$names}. Recibirás un correo cuando terminen.");
    }

    /**
     * Reprocesa un archivo concreto en cola. Si es fuente de BD, invalida el period_summary
     * (hay que volver a «Cargar registros»); si no, y la radiografía estaba generada, la pasa a
     * database_updated para pedir regenerarla. Despacha ReprocessReportUploadJob.
     *
     * @param  ReportUpload  $reportUpload  Archivo a reprocesar.
     * @return RedirectResponse De regreso con mensaje flash.
     */
    public function analyze(ReportUpload $reportUpload): RedirectResponse
    {
        $sourceCode = $reportUpload->dataSource?->code ?? '';
        $sourceName = $reportUpload->dataSource?->name ?? 'La fuente';

        $bdSourceCodes = DataSource::query()->where('is_active', true)->where('is_required_for_bd', true)->pluck('code')->values()->all();
        $isBdSource = in_array($sourceCode, $bdSourceCodes, true);

        $summary = PeriodSummary::query()
            ->where('period_id', $reportUpload->period_id)
            ->whereNull('invalidated_at')
            ->first();

        if ($summary && $isBdSource) {
            $summary->update([
                'invalidated_at' => now(),
                'invalidated_by' => auth()->id(),
                'invalidated_reason' => "Fuente de BD reprocesada: {$sourceName}. La actualización de BD debe ejecutarse nuevamente.",
            ]);
        } elseif ($summary && ! $isBdSource && $summary->status === 'generated') {
            $summary->update([
                'status' => 'database_updated',
                'invalidated_reason' => "Fuente reprocesada: {$sourceName}. Re-genera la Radiografía para reflejar los cambios.",
            ]);
        }

        ReprocessReportUploadJob::dispatch($reportUpload->id, auth()->id());

        return back()->with('success', "'{$sourceName}' fue enviado a reprocesamiento en cola. Recibirás un correo cuando termine.");
    }

    /**
     * Reprocesa TODO el periodo en cola: invalida el period_summary, crea un
     * period_reprocess_run y despacha ReprocessPeriodUploadsJob con las semanas cubiertas.
     * Rechaza si ya hay un reprocesamiento activo.
     *
     * @param  Period  $period  Periodo.
     * @return RedirectResponse De regreso con mensaje flash.
     */
    public function reprocessAll(Period $period): RedirectResponse
    {
        $existing = PeriodReprocessRun::query()
            ->where('period_id', $period->id)
            ->whereIn('status', ['queued', 'running'])
            ->first();

        if ($existing) {
            return back()->with('error', 'Ya hay un reprocesamiento en curso para este periodo. Espera a que termine.');
        }

        $summary = PeriodSummary::query()
            ->where('period_id', $period->id)
            ->whereNull('invalidated_at')
            ->first();

        if ($summary) {
            $summary->update([
                'invalidated_at' => now(),
                'invalidated_by' => auth()->id(),
                'invalidated_reason' => 'Todo el periodo fue enviado a reprocesamiento.',
            ]);
        }

        $allPeriods = Period::query()->get();
        $coveredWeeks = $this->resolveCoveredWeeks($period, $allPeriods);
        $coveredPeriodIds = $coveredWeeks->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $run = PeriodReprocessRun::query()->create([
            'period_id' => $period->id,
            'created_by' => auth()->id(),
            'status' => 'queued',
            'log' => 'Reprocesamiento completo del periodo en cola.',
            'metadata' => ['progress' => 0],
            'started_at' => now(),
        ]);

        ReprocessPeriodUploadsJob::dispatch($period->id, $run->id, $coveredPeriodIds, auth()->id());

        return back()->with('success', 'El reprocesamiento completo fue enviado a cola. Recibirás un correo cuando termine.');
    }

    /**
     * Cancela la carga de BD activa del periodo: borra su job de la tabla jobs (si sigue en
     * cola) y marca el run como cancelled.
     *
     * @param  Period  $period  Periodo.
     * @return RedirectResponse De regreso con mensaje flash.
     */
    public function cancelDatabaseUpdate(Period $period): RedirectResponse
    {
        $run = PeriodDatabaseUpdateRun::query()
            ->where('period_id', $period->id)
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if (! $run) {
            return back()->with('error', 'No hay un proceso activo para cancelar en este periodo.');
        }

        // Remove the job from the queue so it doesn't run after being cancelled
        $this->deleteJobForRun($run->id);

        $cancelledBy = auth()->user()?->name ?? 'usuario';
        $run->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'log' => "Carga cancelada por {$cancelledBy}.",
            'error_message' => 'Cancelado manualmente.',
        ]);

        return back()->with('success', 'El proceso fue cancelado. Puedes reintentarlo cuando estés listo.');
    }

    /**
     * Limpia una carga de BD atascada (en cola/corriendo sin avanzar): borra su job y marca el
     * run como cancelled para poder volver a iniciar la carga. Mismo efecto que
     * cancelDatabaseUpdate() con otro mensaje.
     *
     * @param  Period  $period  Periodo.
     * @return RedirectResponse De regreso con mensaje flash.
     */
    public function clearStuckDatabaseUpdate(Period $period): RedirectResponse
    {
        $run = PeriodDatabaseUpdateRun::query()
            ->where('period_id', $period->id)
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if (! $run) {
            return back()->with('error', 'No hay un proceso en estado atascado para este periodo.');
        }

        $this->deleteJobForRun($run->id);

        $run->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'log' => 'Estado limpiado manualmente por '.(auth()->user()?->name ?? 'usuario').'.',
            'error_message' => 'Limpiado manualmente (proceso atascado).',
        ]);

        return back()->with('success', 'Estado limpiado. Ya puedes volver a iniciar la carga.');
    }

    /**
     * Endpoint JSON: progreso de la última carga de BD del periodo (lo consulta
     * DatabaseUpdateStep.vue). Calcula tiempo transcurrido, aviso de atasco (cola ≥ 5 min,
     * corriendo ≥ 30 min) y si el run en cola quedó huérfano (sin job en la tabla jobs).
     *
     * @param  Period  $period  Periodo.
     * @return JsonResponse {status, log, error_message, tiempos, elapsed_seconds,
     *                      stuck_warning, metadata, job_in_queue, orphaned, can_process_now} o {status: null}.
     */
    public function loadProgressStatus(Period $period): JsonResponse
    {
        $dbRun = PeriodDatabaseUpdateRun::query()
            ->where('period_id', $period->id)
            ->latest('id')
            ->first();

        if (! $dbRun) {
            return response()->json(['status' => null]);
        }

        $status = $dbRun->status;
        $isQueued = $status === 'queued';
        $running = in_array($status, ['queued', 'running'], true);

        $elapsedSeconds = null;
        $stuckWarning = false;
        $jobInQueue = false;
        $orphaned = false;

        if ($running) {
            $ref = $isQueued
                ? ($dbRun->queued_at ?? $dbRun->created_at)
                : ($dbRun->started_at ?? $dbRun->queued_at ?? $dbRun->created_at);
            $elapsedSeconds = $ref ? max(0, (int) now()->diffInSeconds($ref)) : null;
            $stuckWarning = $elapsedSeconds !== null && (
                ($isQueued && $elapsedSeconds >= 300) ||
                (! $isQueued && $elapsedSeconds >= 1800)
            );

            if ($isQueued) {
                $jobInQueue = $this->jobExistsForRun($dbRun->id);
                $orphaned = ! $jobInQueue;
            }
        } elseif ($dbRun->started_at && $dbRun->finished_at) {
            $elapsedSeconds = max(0, (int) $dbRun->started_at->diffInSeconds($dbRun->finished_at));
        }

        return response()->json([
            'status' => $status,
            'log' => $dbRun->log ? mb_strimwidth($dbRun->log, 0, 300) : null,
            'error_message' => $dbRun->error_message ? mb_strimwidth($dbRun->error_message, 0, 300) : null,
            'queued_at' => optional($dbRun->queued_at ?? $dbRun->created_at)->format('d/m/Y H:i'),
            'started_at' => $isQueued ? null : optional($dbRun->started_at)->format('d/m/Y H:i'),
            'finished_at' => optional($dbRun->finished_at)->format('d/m/Y H:i'),
            'elapsed_seconds' => $elapsedSeconds,
            'stuck_warning' => $stuckWarning,
            'metadata' => $dbRun->metadata,
            'job_in_queue' => $jobInQueue,
            'orphaned' => $orphaned,
            'can_process_now' => ! app()->isProduction() && $isQueued,
        ]);
    }

    /**
     * Ejecuta de forma síncrona la carga de BD en cola (solo local/debug; 403 en producción).
     * Antes borra el job de la tabla jobs para evitar doble proceso si el worker arranca después.
     *
     * @param  Period  $period  Periodo.
     * @return RedirectResponse De regreso con mensaje flash.
     */
    public function processNow(Period $period): RedirectResponse
    {
        abort_if(app()->isProduction(), 403, 'Solo disponible en entorno local/debug.');

        $run = PeriodDatabaseUpdateRun::query()
            ->where('period_id', $period->id)
            ->where('status', 'queued')
            ->latest('id')
            ->first();

        if (! $run) {
            return back()->with('error', 'No hay un run en cola para procesar.');
        }

        // Remove from jobs table to prevent double-processing if worker starts later
        $this->deleteJobForRun($run->id);

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        try {
            $job = new UpdatePeriodDatabaseJob($period->id, $run->id, auth()->id());
            app()->call([$job, 'handle']);

            return back()->with('success', 'Proceso completado. Revisa el resultado en esta misma página.');
        } catch (\Throwable $e) {
            // Job already marked run as failed and sent email before re-throwing
            return back()->with('info', 'El proceso terminó con error. Revisa el detalle en la etapa de carga.');
        }
    }

    /**
     * Vuelve a encolar el job de una carga de BD que quedó en cola sin job (huérfana).
     * Si el job ya existe, no hace nada.
     *
     * @param  Period  $period  Periodo.
     * @return RedirectResponse De regreso con mensaje flash.
     */
    public function requeueRun(Period $period): RedirectResponse
    {
        $run = PeriodDatabaseUpdateRun::query()
            ->where('period_id', $period->id)
            ->where('status', 'queued')
            ->latest('id')
            ->first();

        if (! $run) {
            return back()->with('error', 'No hay un run en cola para reencolar.');
        }

        if ($this->jobExistsForRun($run->id)) {
            return back()->with('info', 'El job ya existe en la cola. Inicia el worker para procesarlo.');
        }

        $run->update([
            'queued_at' => now(),
            'log' => 'Job reencolado. Esperando worker.',
        ]);

        UpdatePeriodDatabaseJob::dispatch($period->id, $run->id, auth()->id());

        return back()->with('success', 'Job reencolado. Inicia el worker para procesarlo.');
    }

    /**
     * Indica si existe en la tabla jobs (cola database) un UpdatePeriodDatabaseJob para el run,
     * buscando el runId dentro del payload serializado.
     *
     * @param  int  $runId  id de period_database_update_runs.
     * @return bool true si el job está en la cola.
     */
    private function jobExistsForRun(int $runId): bool
    {
        return DB::table('jobs')
            ->where('payload', 'LIKE', '%UpdatePeriodDatabaseJob%')
            ->where('payload', 'LIKE', '%runId%i:'.$runId.';%')
            ->exists();
    }

    /**
     * Borra de la tabla jobs el UpdatePeriodDatabaseJob del run (misma búsqueda por payload que
     * jobExistsForRun()).
     *
     * @param  int  $runId  id de period_database_update_runs.
     */
    private function deleteJobForRun(int $runId): void
    {
        DB::table('jobs')
            ->where('payload', 'LIKE', '%UpdatePeriodDatabaseJob%')
            ->where('payload', 'LIKE', '%runId%i:'.$runId.';%')
            ->delete();
    }

    // ── Helpers privados ──────────────────────────────────────────────

    /**
     * Datos de vista previa rápida del periodo desde monthly_employee_summaries: totales
     * (empleados, gasto, neto, pagos) y hasta 50 colaboradores.
     *
     * @param  Period|null  $period  Periodo inicial de la página (null → vacío).
     * @return array{metrics: array<string, int|float>, employees: Collection|array}
     */
    private function previewPayload(?Period $period): array
    {
        if (! $period) {
            return ['metrics' => [], 'employees' => []];
        }

        $summary = MonthlyEmployeeSummary::query()
            ->where('period_id', $period->id)
            ->selectRaw('COUNT(*) as total_empleados')
            ->selectRaw('SUM(total_expenses) as gasto_total')
            ->selectRaw('SUM(net_amount) as neto_total')
            ->selectRaw('SUM(total_payments) as pagos_total')
            ->first();

        return [
            'metrics' => [
                'total_empleados' => (int) ($summary->total_empleados ?? 0),
                'gasto_total' => (float) ($summary->gasto_total ?? 0),
                'neto_total' => (float) ($summary->neto_total ?? 0),
                'pagos_total' => (float) ($summary->pagos_total ?? 0),
            ],
            'employees' => MonthlyEmployeeSummary::query()
                ->with(['employee:id,full_name', 'branch:id,name'])
                ->where('period_id', $period->id)
                ->limit(50)
                ->get()
                ->map(fn (MonthlyEmployeeSummary $row) => [
                    'id' => $row->id,
                    'employee_name' => $row->employee?->full_name ?? 'Sin empleado',
                    'branch_name' => $row->branch?->name,
                    'total_payments' => (float) $row->total_payments,
                    'total_expenses' => (float) $row->total_expenses,
                    'net_amount' => (float) $row->net_amount,
                ])->values(),
        ];
    }

    /**
     * Resolución recursiva: retorna los periodos SEMANALES que componen el periodo dado.
     * Usa component_period_ids cuando existe; hace fallback por fecha en caso contrario.
     * Un periodo base devuelve solo a sí mismo.
     *
     * @param  Period  $period  Periodo a resolver.
     * @param  Collection<int, Period>  $allPeriods  Todos los periodos (evita consultas).
     * @return Collection<int, Period> Semanas cubiertas.
     */
    private function resolveCoveredWeeks(Period $period, Collection $allPeriods): Collection
    {
        if ($period->isBase()) {
            return $allPeriods->where('id', $period->id)->values();
        }

        $weeklyIds = $period->resolveBaseWeeklyIds($allPeriods);

        return $allPeriods->whereIn('id', $weeklyIds)->where('type', 'weekly')->values();
    }

    /**
     * Archivos subidos que cubren alguna de las semanas dadas: por covered_period_ids si el
     * archivo los tiene; si no, por su period_id. Ordenados del más reciente al más antiguo.
     * Nota: lee TODOS los report_uploads y filtra en memoria.
     *
     * @param  Collection<int, Period>  $coveredWeeks  Semanas a considerar.
     * @return Collection<int, ReportUpload> Archivos con su dataSource (id, code, name).
     */
    private function resolveUploadsForWeeks(Collection $coveredWeeks): Collection
    {
        if ($coveredWeeks->isEmpty()) {
            return collect();
        }

        $weekIds = $coveredWeeks->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        return ReportUpload::query()
            ->with('dataSource:id,code,name')
            ->get()
            ->filter(function (ReportUpload $upload) use ($weekIds) {
                $coveredIds = collect($upload->covered_period_ids ?? [])
                    ->map(fn ($id) => (int) $id);

                if ($coveredIds->isNotEmpty()) {
                    return $coveredIds->intersect($weekIds)->isNotEmpty();
                }

                return in_array((int) $upload->period_id, $weekIds, true);
            })
            ->sortByDesc('created_at')
            ->values();
    }

    /**
     * Calcula el estado completo del flujo de un periodo: qué falta, qué está bloqueado y por
     * qué, y qué botones se pueden usar en cada paso.
     *
     * Reglas principales:
     *  - Fuentes de BD/reporte faltantes, con error o sin procesar.
     *  - «BD actualizada» = summary database_updated/generated sin invalidar y sin archivos de
     *    BD reemplazados después de la última carga exitosa (stale uploads); en periodos
     *    compuestos basta con tener las fuentes de BD sin error.
     *  - Incidencias críticas (severity high) bloquean generar.
     *  - radiography_ready depende del summary 'generated' (no del último intento de export).
     *  - $running (cualquier generación activa del periodo) bloquea generar; el estado visual
     *    usa solo el run de la propia identidad ($ownIdentityRunning).
     *  - Si la radiografía está lista y faltan métricas globales en el summary, las recalcula
     *    al vuelo desde las tablas fact_* solo para la vista previa (no escribe en BD).
     *
     * @param  Collection<int, ReportUpload>  $uploads  Archivos del periodo (resolveUploadsForWeeks()).
     * @param  PeriodSummary|null  $summary  Resumen del periodo.
     * @param  PeriodRadiographyRun|null  $run  Último intento de la identidad evaluada (simple/general por defecto).
     * @param  bool  $compoundPeriod  true si el periodo es compuesto (agrega otros periodos).
     * @param  PeriodDatabaseUpdateRun|null  $dbRun  Última carga de BD del periodo.
     * @param  list<string>  $bdSourceCodes  Códigos de fuentes obligatorias para la BD.
     * @param  list<string>  $reportSourceCodes  Códigos de fuentes obligatorias para el reporte.
     * @param  bool  $anyGenerationRunningForPeriod  Hay alguna generación activa (cualquier alcance).
     * @param  PeriodRadiographyRun|null  $latestSuccessRun  Último éxito de la identidad (con archivos).
     * @return array<string, mixed> Banderas can_*, blocking_reasons(_display), estado de runs,
     *                              fuentes faltantes, stale uploads y preview_summary.
     */
    private function resolveWorkflowState(
        Collection $uploads,
        ?PeriodSummary $summary,
        ?PeriodRadiographyRun $run = null,
        bool $compoundPeriod = false,
        ?PeriodDatabaseUpdateRun $dbRun = null,
        array $bdSourceCodes = [],
        array $reportSourceCodes = [],
        bool $anyGenerationRunningForPeriod = false,
        ?PeriodRadiographyRun $latestSuccessRun = null,
    ): array {
        $sourceCodes = $uploads->pluck('dataSource.code')->filter()->unique()->values();

        $missingDb = collect($bdSourceCodes)
            ->filter(fn ($code) => ! $sourceCodes->contains($code))
            ->values()
            ->all();

        $missingRadiography = collect($reportSourceCodes)
            ->filter(fn ($code) => ! $sourceCodes->contains($code))
            ->values()
            ->all();

        $failedDb = collect($bdSourceCodes)->filter(function ($code) use ($uploads) {
            $upload = $uploads->sortByDesc('id')->first(fn ($item) => $item->dataSource?->code === $code);

            return $upload && (string) ($upload->status?->value ?? $upload->status) === 'failed';
        })->values()->all();

        $unprocessedRadiography = collect($reportSourceCodes)->filter(function ($code) use ($uploads) {
            $upload = $uploads->sortByDesc('id')->first(fn ($item) => $item->dataSource?->code === $code);
            if (! $upload) {
                return false;
            }

            return (string) ($upload->status?->value ?? $upload->status) !== 'processed';
        })->values()->all();

        $pendingCritical = (int) ($summary?->incidents()->where('severity', 'high')->count() ?? 0);

        // ── Stale-upload detection ────────────────────────────────────────────
        // The BD state is stale (period_summary says 'database_updated' but data is gone) when:
        //   - The last DB_UPDATE_RUN completed successfully
        //   - AND at least one BD-required upload is NOT processed
        //
        // Note: the DB_UPDATE_RUN does NOT necessarily mark uploads as 'processed' itself —
        // that happens via separate reimport flows. So the simple fact that a BD-required upload
        // is pending while a successful run exists means there's a discrepancy.
        $staleUploads = [];
        $staleUploadInfo = [];
        if ($dbRun && $dbRun->status === 'success' && $dbRun->finished_at && $summary) {
            // Only flag as stale if summary was created from this run (status still 'database_updated')
            // AND fact data is absent for the BD-required sources
            $summaryIsFromRun = in_array($summary->status, ['database_updated', 'generated'], true)
                && ! $summary->invalidated_at;

            if ($summaryIsFromRun) {
                foreach ($bdSourceCodes as $code) {
                    $upload = $uploads->first(fn ($item) => ($item->dataSource?->code ?? '') === $code);
                    if (! $upload) {
                        continue;
                    }
                    $status = (string) ($upload->status?->value ?? $upload->status ?? 'unknown');
                    if ($status === 'processed') {
                        continue;
                    }

                    // BD-required upload is pending/failed while run is marked success → stale
                    $uploadTs = $upload->updated_at ?? $upload->created_at;
                    $staleUploads[] = $code;
                    $staleUploadInfo[] = [
                        'code' => $code,
                        'filename' => $upload->original_name,
                        'status' => $status,
                        'uploaded' => optional($uploadTs)->format('d/m/Y H:i'),
                        'last_run' => optional($dbRun->finished_at)->format('d/m/Y H:i'),
                        'reason' => 'Upload en estado "'.$status.'" aunque el último DB_UPDATE_RUN completó exitosamente. Vuelve a ejecutar Cargar registros.',
                    ];
                }
            }
        }

        $summaryValid = in_array($summary?->status, ['database_updated', 'generated'], true)
                           && ! $summary?->invalidated_at
                           && empty($staleUploads);   // stale uploads invalidate BD state
        $databaseUpdated = $summaryValid || ($compoundPeriod && empty($missingDb) && empty($failedDb));

        // PROBLEMA 2/4/5 (auditoría 27-ago-2026) — "radiography_ready" (Vista previa,
        // Etapa 6) ya NO depende de si el ÚLTIMO INTENTO de export (Excel/PDF, Etapa 7)
        // tuvo éxito. Son cosas distintas: la vista previa se construye en vivo desde
        // PeriodSummary/BranchRadiographyCalculator (ver MonthlyReportController::
        // previewPage()/exportRadiography(), que YA sólo miran el summary — nunca el
        // run), no desde archivos Excel/PDF ya escritos en disco. Antes, un intento de
        // REGENERACIÓN que fallaba DESPUÉS de que PeriodRadiographyService::generate()
        // ya había confirmado un summary válido (nueva versión) dejaba
        // radiography_ready=false y bloqueaba Vista previa/Exportación pese a que:
        //   (a) el summary seguía siendo válido y consultable, y
        //   (b) el histórico (MonthlyReportController::index()) seguía mostrando
        //       "Generado" para esa misma identidad vía su último run SUCCESS —
        //       dos fuentes de verdad distintas para la misma pregunta.
        // La comprobación de que el run falló SIGUE existiendo — ahora vive en
        // PeriodRadiographyRun::resolveForIdentity()['latest_success'], que es lo que
        // debe usarse para decidir si hay Excel/PDF descargables (Etapa 7), nunca para
        // bloquear la vista previa (Etapa 6).
        $radiographyReady = ($summary?->status === 'generated')
            && ! $summary?->invalidated_at
            && empty($staleUploads);
        // Usa el ÚLTIMO ÉXITO real de la identidad (puede no ser $run, si $run es un
        // intento más reciente que falló) — no basta con que $run mismo sea 'success'.
        $hasPreviousSuccess = ! $radiographyReady && $latestSuccessRun !== null;
        $runStatus = $run?->status;
        // $run debe llegar ya filtrado a la identidad simple/general (ver
        // PeriodRadiographyRun::scopeForIdentity()) — "el último run del periodo sin
        // importar de qué reporte era" hacía que un GENERAL exitoso se viera
        // fallido/desconocido en Etapa 5 si DESPUÉS se generaba (con éxito o error) un
        // reporte por sucursal/gestor del mismo periodo. $anyGenerationRunningForPeriod
        // sí es correcto que sea period-wide: el dispatcher solo permite UN run activo
        // (queued/running) por periodo sin importar el alcance (ver generateRadiography()).
        // BUG REAL (2026-08-26): $running mezclaba "¿está corriendo ESTE run (identidad
        // simple/general)?" con "¿hay CUALQUIER generación corriendo para el periodo
        // (cualquier alcance)?". Eso es correcto para bloquear un doble-submit
        // (can_generate_radiography), pero rompía la semántica de "¿mi reporte general
        // ya quedó listo?": si alguien generaba un reporte por sucursal/gestor DESPUÉS
        // de que el general ya había terminado con éxito, $running seguía true
        // (por el alcance ajeno) y Etapa 6/7 mostraban "Vista previa: Completo" pero
        // "Exportación: En proceso"/"Generar reporte: En proceso" — inconsistente,
        // aunque los archivos del general ya existían intactos. $ownIdentityRunning
        // se usa para todo lo que describe el ESTADO VISUAL de ESTE run (radiography_running,
        // can_export_radiography); $running (period-wide) se conserva solo para lo que
        // de verdad debe impedir un doble-submit (can_generate_radiography, blockingReasons).
        $ownIdentityRunning = in_array($runStatus, ['queued', 'running'], true);
        $running = $ownIdentityRunning || $anyGenerationRunningForPeriod;
        $dbRunStatus = $dbRun?->status;
        $dbRunning = in_array($dbRunStatus, ['queued', 'running'], true);

        $dbElapsedSeconds = null;
        $dbStuckWarning = false;
        $dbJobOrphaned = false;
        if ($dbRun) {
            if ($dbRunning) {
                $ref = $dbRunStatus === 'queued'
                    ? ($dbRun->queued_at ?? $dbRun->created_at)
                    : ($dbRun->started_at ?? $dbRun->queued_at ?? $dbRun->created_at);
                $dbElapsedSeconds = $ref ? max(0, (int) now()->diffInSeconds($ref)) : null;
                $dbStuckWarning = $dbElapsedSeconds !== null && (
                    ($dbRunStatus === 'queued' && $dbElapsedSeconds >= 300) ||
                    ($dbRunStatus !== 'queued' && $dbElapsedSeconds >= 1800)
                );
                if ($dbRunStatus === 'queued') {
                    $dbJobOrphaned = ! $this->jobExistsForRun($dbRun->id);
                }
            } elseif ($dbRun->started_at && $dbRun->finished_at) {
                $dbElapsedSeconds = max(0, (int) $dbRun->started_at->diffInSeconds($dbRun->finished_at));
            }
        }

        $blockingReasons = [];
        if (! empty($missingDb)) {
            $blockingReasons[] = 'Faltan archivos de BD obligatorios: '.implode(', ', $missingDb).'.';
        }
        if (! empty($failedDb)) {
            $blockingReasons[] = 'Fuentes de BD tienen error de procesamiento.';
        }
        if ($dbRunning) {
            $blockingReasons[] = 'La actualización de base de datos está en proceso.';
        }
        if (! empty($staleUploads)) {
            $names = implode(', ', array_map(
                fn ($s) => $s['code'].' ('.$s['filename'].', subido '.$s['uploaded'].')',
                $staleUploadInfo
            ));
            $blockingReasons[] = 'Se reemplazaron archivos de BD después de la última carga '
                .'(última carga: '.optional($dbRun?->finished_at)->format('d/m/Y H:i').'). '
                ."Ejecuta nuevamente Cargar registros. Archivos: {$names}.";
        }
        if (! $databaseUpdated) {
            $blockingReasons[] = 'Primero actualiza la BD (ejecuta Cargar registros).';
        }
        if ($pendingCritical > 0) {
            $blockingReasons[] = 'Hay incidencias críticas pendientes.';
        }
        if (! empty($missingRadiography)) {
            $blockingReasons[] = 'Faltan fuentes para generar la Radiografía: '.implode(', ', $missingRadiography).'.';
        }
        if (! empty($unprocessedRadiography)) {
            $details = implode('; ', collect($unprocessedRadiography)->map(function ($code) use ($uploads) {
                $u = $uploads->first(fn ($item) => ($item->dataSource?->code ?? '') === $code);
                $status = $u ? (string) ($u->status?->value ?? $u->status ?? 'desconocido') : 'no subido';
                $filename = $u?->original_name ?? 'sin archivo';

                return "{$code} — {$filename} [{$status}]";
            })->all());
            $blockingReasons[] = "Fuentes sin procesar (todas deben estar en estado 'procesado' para generar): {$details}";
        }
        if ($running) {
            $blockingReasons[] = 'La Radiografía está en proceso.';
        }

        $previewSummary = null;
        if ($radiographyReady && $summary) {
            $gm = $summary->global_metrics ?? [];

            $needsRecompute = (($gm['colocacion_total'] ?? 0) == 0 && ($gm['valor_cartera_total'] ?? 0) == 0)
                || ($gm['recuperacion_total'] ?? 0) == 0;

            if ($needsRecompute) {
                $period = Period::find($summary->period_id);
                if ($period) {
                    $allPeriodsLocal = Period::all();
                    $weeklyIdsLocal = $period->resolveBaseWeeklyIds($allPeriodsLocal);
                    if (empty($weeklyIdsLocal)) {
                        $weeklyIdsLocal = [$period->id];
                    }
                    $dataIdsLocal = array_values(array_unique(array_merge($weeklyIdsLocal, [$period->id])));

                    if (($gm['colocacion_total'] ?? 0) == 0) {
                        $gm['colocacion_total'] = (float) DB::table('fact_placements')->whereIn('period_id', $dataIdsLocal)->sum('amount');
                    }
                    if (($gm['recuperacion_total'] ?? 0) == 0) {
                        $gm['recuperacion_total'] = (float) DB::table('fact_recoveries')
                            ->whereIn('period_id', $dataIdsLocal)
                            ->selectRaw("SUM(CASE
                                WHEN is_savehearts = 1 THEN COALESCE(savehearts_crece_share, 0)
                                WHEN is_savehearts = 0 AND (UPPER(COALESCE(concept,'')) LIKE '%COBERTURA%' OR UPPER(COALESCE(operation,'')) LIKE '%COBERTURA%') THEN 0
                                ELSE total_amount
                            END) as total")
                            ->value('total') ?? 0;
                    }
                    if (($gm['valor_cartera_total'] ?? 0) == 0) {
                        $ct = (float) DB::table('fact_portfolios')->whereIn('period_id', $dataIdsLocal)->sum('balance');
                        $cv = (float) DB::table('fact_portfolios')->whereIn('period_id', $dataIdsLocal)->where('days_past_due', '>', 0)->sum('balance');
                        $gm['valor_cartera_total'] = $ct;
                        $gm['cartera_vencida_total'] = $cv;
                        $gm['mora_porcentaje'] = $ct > 0 ? round($cv / $ct * 100, 2) : 0;
                    }
                    if (($gm['gasto_total'] ?? 0) == 0) {
                        $gm['gasto_total'] = (float) DB::table('fact_expenses')->whereIn('period_id', $dataIdsLocal)->sum('amount');
                    }
                }
            }

            // Always recompute payroll inline if pagos_total is 0
            if (($gm['pagos_total'] ?? 0) == 0) {
                $periodForPayroll = isset($period) ? $period : Period::find($summary->period_id);
                if ($periodForPayroll) {
                    $mes = DB::table('fact_period_employee_summary')
                        ->where('period_id', $periodForPayroll->id)
                        ->selectRaw('COUNT(*) as cnt, SUM(total_payments) as pagos, SUM(total_bonuses) as bonos, SUM(net_amount) as neto')
                        ->first();
                    $mesPagos = (float) ($mes?->pagos ?? 0) + (float) ($mes?->bonos ?? 0);
                    if ((int) ($mes?->cnt ?? 0) > 0 && $mesPagos > 0) {
                        $gm['total_empleados'] = (int) $mes->cnt;
                        $gm['pagos_total'] = (float) $mes->pagos;
                        $gm['neto_total'] = (float) $mes->neto;
                    } else {
                        // Fallback via NOI
                        if (! isset($dataIdsLocal)) {
                            $allPeriodsLocal2 = Period::all();
                            $wIds = $periodForPayroll->resolveBaseWeeklyIds($allPeriodsLocal2);
                            $dataIdsLocal = empty($wIds) ? [$periodForPayroll->id] : array_values(array_unique(array_merge($wIds, [$periodForPayroll->id])));
                        }
                        $noiPagos = (float) DB::table('fact_noi_movements')
                            ->whereIn('period_id', $dataIdsLocal)
                            ->whereNotNull('employee_id')
                            ->whereRaw("LOWER(COALESCE(concept_type,'')) = 'percepcion'")
                            ->whereRaw("LOWER(COALESCE(concept,'')) NOT LIKE '%bono%'")
                            ->sum('amount');
                        $noiComisiones = (float) DB::table('fact_noi_movements')
                            ->whereIn('period_id', $dataIdsLocal)
                            ->whereNotNull('employee_id')
                            ->whereRaw("LOWER(COALESCE(concept,'')) LIKE '%comisi%'")
                            ->sum('amount');
                        if ($noiPagos + $noiComisiones > 0) {
                            $gm['pagos_total'] = $noiPagos + $noiComisiones;
                            $noiDesc = (float) DB::table('fact_noi_movements')
                                ->whereIn('period_id', $dataIdsLocal)
                                ->whereNotNull('employee_id')
                                ->whereRaw("LOWER(COALESCE(concept_type,'')) IN ('deduccion','descuento')")
                                ->sum('amount');
                            $gm['neto_total'] = $gm['pagos_total'] - $noiDesc;
                        }
                        if (($gm['total_empleados'] ?? 0) == 0) {
                            $gm['total_empleados'] = (int) DB::table('fact_noi_movements')
                                ->whereIn('period_id', $dataIdsLocal)->whereNotNull('employee_id')->distinct('employee_id')->count('employee_id');
                        }
                    }
                }
            }

            $previewSummary = [
                'global_metrics' => $gm,
                'generated_at' => optional($summary->generated_at)->format('d/m/Y H:i'),
                'version' => $summary->version,
            ];
        }

        return [
            'database_updated' => $databaseUpdated,
            'database_invalidated' => (bool) $summary?->invalidated_at,
            'database_update_run_status' => $dbRunStatus,
            'database_update_run_log' => $dbRun?->log ? mb_strimwidth($dbRun->log, 0, 300) : null,
            'database_update_run_error' => $dbRun?->error_message ? mb_strimwidth($dbRun->error_message, 0, 300) : null,
            'database_update_run_queued_at' => optional($dbRun?->queued_at ?? $dbRun?->created_at)->format('d/m/Y H:i'),
            'database_update_run_started_at' => ($dbRunStatus !== 'queued') ? optional($dbRun?->started_at)->format('d/m/Y H:i') : null,
            'database_update_run_finished_at' => optional($dbRun?->finished_at)->format('d/m/Y H:i'),
            'database_update_run_metadata' => $dbRun?->metadata,
            'database_update_elapsed_seconds' => $dbElapsedSeconds,
            'database_update_stuck_warning' => $dbStuckWarning,
            'database_update_job_orphaned' => $dbJobOrphaned,
            'database_update_can_process_now' => ! app()->isProduction() && $dbRunStatus === 'queued',
            'pending_critical_incidents_count' => $pendingCritical,
            'missing_database_sources' => $missingDb,
            'missing_radiography_sources' => $missingRadiography,
            'unprocessed_radiography_sources' => $unprocessedRadiography,
            'radiography_ready' => $radiographyReady,
            'radiography_invalidated' => (bool) $summary?->invalidated_at,
            'radiography_running' => $ownIdentityRunning,
            'radiography_run_queued_at' => optional($run?->queued_at ?? $run?->created_at)->format('d/m/Y H:i'),
            'radiography_run_started_at' => ($runStatus !== 'queued') ? optional($run?->started_at)->format('d/m/Y H:i') : null,
            'radiography_run_metadata' => $run?->metadata,
            'radiography_run_error' => $run?->error_message ? mb_strimwidth($run->error_message, 0, 300) : null,
            'radiography_can_process_now' => ! app()->isProduction() && $runStatus === 'queued',
            'can_update_database' => empty($missingDb) && empty($failedDb) && ! $dbRunning,
            'can_resolve_incidents' => $databaseUpdated,
            'can_generate_radiography' => $databaseUpdated && $pendingCritical === 0 && empty($missingRadiography) && empty($unprocessedRadiography) && ! $running,
            'can_export_radiography' => $radiographyReady && empty($missingRadiography) && empty($unprocessedRadiography) && ! $ownIdentityRunning,
            'blocking_reasons' => array_values(array_unique($blockingReasons)),
            // "La Radiografía está en proceso." solo describe por qué NO puedes lanzar
            // otro submit ahora mismo (puede deberse a un alcance ajeno corriendo) — es
            // un blocker de negocio, no el estado visual de "mi" generación. Etapa 5 ya
            // tiene su propia tarjeta PROCESSING (ver GenerateReportStep.vue); reusar
            // este mensaje ahí mostraba "Bloqueado" mientras el reporte solo procesaba.
            'blocking_reasons_display' => array_values(array_unique(array_diff($blockingReasons, ['La Radiografía está en proceso.']))),
            'preview_summary' => $previewSummary,
            // Stale-upload state: visible to UI so it can show "archivos reemplazados" warning
            'has_stale_uploads' => ! empty($staleUploads),
            'stale_upload_sources' => $staleUploads,
            'stale_upload_details' => $staleUploadInfo,
            // Previous successful radiography (run succeeded but summary was later reset)
            'has_previous_radiography' => $hasPreviousSuccess,
            'previous_radiography_at' => $hasPreviousSuccess ? optional($latestSuccessRun->finished_at)->format('d/m/Y H:i') : null,
        ];
    }
}
