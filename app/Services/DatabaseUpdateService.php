<?php

namespace App\Services;

use App\Enums\DataSourceCode;
use App\Enums\ReportUploadStatus;
use App\Models\Period;
use App\Models\PeriodDatabaseUpdateRun;
use App\Models\PeriodIncident;
use App\Models\PeriodSummary;
use App\Models\ReportUpload;
use App\Services\Imports\LendusEmployeeDirectoryImportService;
use App\Services\Imports\LendusIngresosCobranzaImportService;
use App\Services\Imports\NoiNominaImportService;
use App\Support\ReportUploadStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Orquesta el paso "Cargar registros / Actualizar BD" de un periodo.
 *
 * Es el proceso que convierte los archivos subidos del periodo (NOI Nómina, Lendus
 * Ingresos Cobranza y el resto de fuentes) en datos utilizables por la radiografía
 * financiera: escanea catálogos de empleados/sucursales, importa todos los archivos
 * vigentes a las tablas fact_*, asigna sucursales automáticamente, deriva rotación e
 * IMSS desde NOI y deja el PeriodSummary en estado "database_updated" con sus
 * incidencias.
 *
 * Lo invoca UpdatePeriodDatabaseJob (cola) a partir de una corrida
 * PeriodDatabaseUpdateRun, cuyo progreso y metadatos se van actualizando para la UI.
 */
class DatabaseUpdateService
{
    /**
     * Inyecta los importadores y servicios que participan en la actualización de BD.
     *
     * @param  NoiNominaImportService  $noiNominaImportService  Escaneo de NOI Nómina (y NOI Fiscal).
     * @param  LendusIngresosCobranzaImportService  $lendusIngresosCobranzaImportService  Escaneo de cobranza Lendus.
     * @param  LendusEmployeeDirectoryImportService  $lendusEmployeeDirectoryImportService  Directorio de empleados Lendus.
     * @param  EmployeeBranchAutoMatchService  $employeeBranchAutoMatchService  Asignación automática empleado→sucursal.
     * @param  ReportAnalysisService  $reportAnalysisService  Importa cada archivo a sus tablas fact_*.
     * @param  RotacionDerivedFromNoiService  $rotacionDerivedService  Deriva la rotación desde NOI.
     * @param  ImssFromNoiFiscalService  $imssDerivedService  Deriva el IMSS desde NOI Fiscal.
     */
    public function __construct(
        protected NoiNominaImportService $noiNominaImportService,
        protected LendusIngresosCobranzaImportService $lendusIngresosCobranzaImportService,
        protected LendusEmployeeDirectoryImportService $lendusEmployeeDirectoryImportService,
        protected EmployeeBranchAutoMatchService $employeeBranchAutoMatchService,
        protected ReportAnalysisService $reportAnalysisService,
        protected RotacionDerivedFromNoiService $rotacionDerivedService,
        protected ImssFromNoiFiscalService $imssDerivedService,
    ) {}

    /**
     * Ejecuta la actualización completa de BD para un periodo.
     *
     * Pasos:
     *  1. Valida que existan (y estén físicamente en storage) NOI Nómina y Lendus
     *     Ingresos Cobranza.
     *  2. Escanea NOI Nómina (y NOI Fiscal si existe) para el catálogo de empleados.
     *  3. Escanea Lendus Ingresos Cobranza para catálogo de sucursales/empleados.
     *  4. Importa el directorio de empleados Lendus, si fue cargado.
     *  5. Importa a fact_* solo el archivo vigente (el más reciente) por fuente; los
     *     anteriores se marcan como Replaced. Un error en una fuente no aborta: se
     *     registra como incidencia "import_error.*" de severidad high.
     *  6. Verifica que fact_noi_movements y fact_recoveries tengan datos (periodo y
     *     sus semanas base); si no, lanza RuntimeException.
     *  7. Corre la asignación automática de sucursales y luego deriva roster,
     *     rotación e IMSS desde NOI (fallos aquí solo se registran en log).
     *  8. Guarda el PeriodSummary (status=database_updated, limpia la invalidación),
     *     reemplaza las incidencias "db_update.*" y publica estadísticas finales.
     *
     * Efectos secundarios: escribe en report_uploads (status), fact_*,
     * employee_branch_assignments, period_summaries, period_incidents y en la
     * metadata de la corrida; escribe en el log.
     *
     * @param  Period  $period  Periodo a actualizar.
     * @param  PeriodDatabaseUpdateRun|null  $run  Corrida para reportar progreso/cancelación; null si se ejecuta sin seguimiento.
     *
     * @throws ValidationException Si faltan las fuentes obligatorias o su archivo no existe.
     * @throws \RuntimeException Si las tablas de hechos obligatorias quedan vacías o la corrida fue cancelada.
     */
    public function updateForPeriod(Period $period, ?PeriodDatabaseUpdateRun $run = null): void
    {
        $required = [
            DataSourceCode::NoiNomina->value,
            DataSourceCode::LendusIngresosCobranza->value,
        ];

        // ── Step 1: Validate required files ──────────────────────────────────
        $this->progress($run, 'Validando fuentes obligatorias…', 5);
        $this->checkCancelled($run);

        $uploads = $period->reportUploads()->with('dataSource')->latest('id')->get();

        foreach ($required as $code) {
            $upload = $uploads->first(fn ($item) => $item->dataSource?->code === $code);
            if (! $upload) {
                throw ValidationException::withMessages([
                    'period' => 'Debes cargar NOI Nómina y Lendus Ingresos Cobranza antes de actualizar la BD.',
                ]);
            }

            if (! $upload->stored_path || ! ReportUploadStorage::exists($upload->stored_path)) {
                throw ValidationException::withMessages([
                    'period' => "El archivo de {$upload->dataSource?->name} no existe físicamente en storage.",
                ]);
            }
        }

        $noiUpload = $uploads->first(fn ($item) => $item->dataSource?->code === DataSourceCode::NoiNomina->value);
        $cobranzaUpload = $uploads->first(fn ($item) => $item->dataSource?->code === DataSourceCode::LendusIngresosCobranza->value);
        $fiscalUpload = $uploads->first(fn ($item) => $item->dataSource?->code === DataSourceCode::NoiNominaFiscal->value);

        // ── Step 2: Scan NOI — builds employee catalog ───────────────────────
        $this->progress($run, 'Leyendo NOI Nómina…', 20, [], 'noi_nomina', basename($noiUpload->stored_path ?? ''));
        $this->checkCancelled($run);
        $noiResult = $this->noiNominaImportService->scanForDatabaseUpdate($noiUpload);

        if ($fiscalUpload && $fiscalUpload->stored_path && ReportUploadStorage::exists($fiscalUpload->stored_path)) {
            $this->progress($run, 'Leyendo NOI Nómina Fiscal…', 30, [], 'noi_nomina_fiscal', basename($fiscalUpload->stored_path));
            $this->checkCancelled($run);
            $fiscalResult = $this->noiNominaImportService->scanForDatabaseUpdate($fiscalUpload);
            $noiResult['employees_detected'] += $fiscalResult['employees_detected'] ?? 0;
        }

        // ── Step 3: Scan Cobranza — builds branch/employee catalog + EBAs ───
        $this->progress($run, 'Leyendo Lendus Ingresos Cobranza…', 42, [], 'lendus_ingresos_cobranza', basename($cobranzaUpload->stored_path ?? ''));
        $this->checkCancelled($run);
        $cobranzaResult = $this->lendusIngresosCobranzaImportService->scanForDatabaseUpdate($cobranzaUpload, $run, 42, 55);

        // ── Step 4: Import Lendus employee directory ─────────────────────────
        $lendusEmpleadosUpload = $uploads->first(fn ($item) => $item->dataSource?->code === DataSourceCode::LendusEmpleados->value);
        if (
            $lendusEmpleadosUpload &&
            $lendusEmpleadosUpload->stored_path &&
            ReportUploadStorage::exists($lendusEmpleadosUpload->stored_path)
        ) {
            $this->progress($run, 'Importando directorio de empleados Lendus…', 58, [], 'lendus_empleados', basename($lendusEmpleadosUpload->stored_path));
            $this->checkCancelled($run);
            $this->lendusEmployeeDirectoryImportService->handle($lendusEmpleadosUpload);
        }

        // ── Step 5: Import ALL uploads into fact_* tables ────────────────────
        // This MUST run before automatch so fact_placements, fact_portfolios,
        // fact_recoveries, and fact_expenses are populated when the matcher runs.
        $this->progress($run, 'Importando archivos a tablas de hechos (fact_*)…', 62);
        $this->checkCancelled($run);

        $allUploads = ReportUpload::query()->with('dataSource')
            ->where('period_id', $period->id)
            ->where('status', '!=', ReportUploadStatus::Replaced)
            ->get();

        // Only the vigente (most recent) upload per data source must be imported.
        // Older uploads for the same source are superseded — reprocessing them would
        // silently reintroduce data from a file the user already replaced.
        $latestByCode = [];
        foreach ($allUploads as $upload) {
            $code = $upload->dataSource?->code ?? '';
            if (! $code) {
                continue;
            }
            if (! isset($latestByCode[$code]) || $upload->id > $latestByCode[$code]->id) {
                $latestByCode[$code] = $upload;
            }
        }

        $staleIds = $allUploads->pluck('id')->diff(collect($latestByCode)->pluck('id'));
        if ($staleIds->isNotEmpty()) {
            ReportUpload::query()->whereIn('id', $staleIds)->update(['status' => ReportUploadStatus::Replaced]);
        }

        $importErrors = [];
        $importErrorDetails = [];
        $identityIncidents = [];

        foreach ($latestByCode as $code => $upload) {
            if (! $upload->stored_path || ! ReportUploadStorage::exists($upload->stored_path)) {
                continue;
            }
            $this->checkCancelled($run);
            try {
                $this->reportAnalysisService->analyze($upload);
                $identityIncidents = array_merge($identityIncidents, $this->reportAnalysisService->pullLastImportIncidents());
            } catch (\Throwable $e) {
                $message = mb_strimwidth($e->getMessage(), 0, 120);
                $importErrors[] = ($upload->dataSource?->name ?? $code).': '.$message;
                $importErrorDetails[] = [
                    'code' => $code,
                    'name' => $upload->dataSource?->name ?? $code,
                    'upload_id' => $upload->id,
                    'error' => $message,
                ];
                Log::warning('DatabaseUpdateService: error importing upload', [
                    'upload_id' => $upload->id,
                    'source' => $code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // ── Step 6: Validate fact_* tables are populated ─────────────────────
        $dataIds = array_values(array_unique(array_merge([$period->id],
            $period->resolveBaseWeeklyIds(Period::all()))));
        $hasNoi = DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)->exists();
        $hasCobranza = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)->exists();

        if (! $hasNoi || ! $hasCobranza) {
            $missing = [];
            if (! $hasNoi) {
                $missing[] = 'fact_noi_movements (NOI Nómina)';
            }
            if (! $hasCobranza) {
                $missing[] = 'fact_recoveries (Cobranza)';
            }
            throw new \RuntimeException(
                'Cargar registros completó el escaneo pero las tablas de hechos requeridas quedaron vacías: '
                .implode(', ', $missing)
                .'. Verifica los archivos y vuelve a intentarlo.'
                .(! empty($importErrors) ? ' Errores: '.implode('; ', $importErrors) : '')
            );
        }

        if (! empty($importErrors)) {
            Log::warning('DatabaseUpdateService: import completed with non-critical errors', [
                'period_id' => $period->id,
                'errors' => $importErrors,
            ]);
        }

        // ── Step 7: AutoMatch — runs with full fact_* data ───────────────────
        // fact_placements, fact_portfolios, fact_recoveries, fact_expenses are
        // all populated above; automatch will find branch candidates correctly.
        $this->progress($run, 'Asignando sucursales automáticamente…', 82);
        $this->checkCancelled($run);
        $this->employeeBranchAutoMatchService->handle($period->id);

        // ── Step 7b: Derivar roster + Rotación + IMSS desde NOI (ya NO archivo manual) ──
        // Debe correr DESPUÉS de automatch (necesita employee_branch_assignments del
        // periodo ya resuelto). Idempotente: borra y recalcula solo este periodo.
        $this->progress($run, 'Derivando rotación e IMSS desde NOI…', 88);
        $this->checkCancelled($run);
        $rotationDerived = null;
        $imssDerived = null;
        try {
            $rotationDerived = $this->rotacionDerivedService->deriveForPeriod($period);
            $imssDerived = $this->imssDerivedService->deriveForPeriod($period);
        } catch (\Throwable $e) {
            Log::warning('DatabaseUpdateService: fallo derivando rotación/IMSS desde NOI', [
                'period_id' => $period->id,
                'error' => $e->getMessage(),
            ]);
        }

        // ── Step 8: Save PeriodSummary and incidents ──────────────────────────
        // Only after uploads are processed, fact_* are populated, and automatch
        // has run — so status=database_updated is actually accurate.
        $this->progress($run, 'Guardando resumen operativo y registrando incidencias…', 92);
        $this->checkCancelled($run);

        $summary = PeriodSummary::query()->updateOrCreate(
            ['period_id' => $period->id],
            [
                'status' => 'database_updated',
                'invalidated_at' => null,
                'invalidated_by' => null,
                'invalidated_reason' => null,
                'warnings' => [
                    'db_update' => [
                        'persons_detected' => $noiResult['employees_detected'] ?? 0,
                        'records_loaded' => ($noiResult['rows_read'] ?? 0) + ($cobranzaResult['records_loaded'] ?? $cobranzaResult['cobranza_rows_read'] ?? 0),
                        'records_excluded' => ($noiResult['rows_skipped'] ?? 0) + ($cobranzaResult['records_excluded'] ?? $cobranzaResult['cobranza_rows_skipped'] ?? 0),
                        'branches_included' => 12,
                        'branches_excluded' => 2,
                        'pending_locations' => $cobranzaResult['pending_locations'] ?? 0,
                    ],
                ],
            ],
        );

        PeriodIncident::query()
            ->where('period_summary_id', $summary->id)
            ->where('type', 'like', 'db_update.%')
            ->delete();

        $importFailureIncidents = array_map(fn (array $d) => [
            'type' => 'import_error.'.$d['code'],
            // 'high' (not 'critical') matches ReportUploadController::resolveWorkflowState()'s
            // $pendingCritical gate, which only counts severity === 'high'.
            'severity' => 'high',
            'message' => "Fallo al importar {$d['name']} (upload #{$d['upload_id']}): {$d['error']}",
            'context' => ['upload_id' => $d['upload_id'], 'source' => $d['code']],
        ], $importErrorDetails);

        $rotationImssIncidents = [];
        if (! $fiscalUpload) {
            $rotationImssIncidents[] = [
                'type' => 'imss_derivado.sin_noi_fiscal',
                'severity' => 'high',
                'message' => 'No se puede calcular IMSS: falta NOI Nómina Fiscal para este periodo.',
            ];
        }
        $sinSucursal = (int) ($rotationDerived['roster']['sin_sucursal'] ?? $imssDerived['roster']['sin_sucursal'] ?? 0);
        if ($sinSucursal > 0) {
            $rotationImssIncidents[] = [
                'type' => 'roster.empleados_sin_sucursal',
                'severity' => 'warning',
                'message' => "{$sinSucursal} empleado(s) del roster NOI sin sucursal resuelta — excluidos de Rotación/IMSS por sucursal. Ver reportes:audit-roster-periodo {$period->id} --detail.",
            ];
        }

        // Nunca bloquea ni sustituye: el legacy (archivo manual) sigue siendo la fuente
        // autoritativa del reporte mientras exista (ver BranchRadiographyCalculator /
        // RadiographySnapshotBuilder). Esto solo avisa cuando el derivado de NOI no
        // reproduce el mismo número, para que se investigue antes de confiar en el
        // derivado el día que se retire el archivo manual.
        if ($rotationDerived && ($rotationDerived['matches_legacy'] ?? null) === false) {
            $rotationImssIncidents[] = [
                'type' => 'rotacion_derivado.no_coincide_legacy',
                'severity' => 'warning',
                'message' => "El índice de rotación derivado de NOI no coincide con el archivo manual validado para este periodo. El reporte sigue usando el archivo. Ver reportes:audit-rotacion-derivada {$period->id} --detail.",
            ];
        }
        if ($imssDerived && ($imssDerived['matches_legacy'] ?? null) === false) {
            $rotationImssIncidents[] = [
                'type' => 'imss_derivado.no_coincide_legacy',
                'severity' => 'warning',
                'message' => "El IMSS derivado de NOI Fiscal no coincide con el archivo manual validado para este periodo. El reporte sigue usando el archivo. Ver reportes:audit-imss-derivado {$period->id} --detail.",
            ];
        }

        $allIncidents = array_merge($noiResult['incidents'] ?? [], $cobranzaResult['incidents'] ?? [], $importFailureIncidents, $rotationImssIncidents, $identityIncidents);
        // PROBLEMA 6: un fallo al persistir UNA incidencia (ej. mensaje inusualmente largo
        // desde NoiNominaImportService) no debe abortar la actualización de BD completa ni
        // tragarse la excepción real — se deja constancia clara con
        // 'failed_to_persist_incident' y se continúa con el resto.
        foreach ($allIncidents as $incident) {
            try {
                PeriodIncident::query()->create([
                    'period_summary_id' => $summary->id,
                    'type' => 'db_update.'.($incident['type'] ?? 'unknown'),
                    'severity' => $incident['severity'] ?? 'high',
                    'message' => $incident['message'] ?? 'Incidencia detectada durante actualización de BD.',
                    'context' => $incident['context'] ?? null,
                ]);
            } catch (\Throwable $incidentException) {
                Log::error('failed_to_persist_incident', [
                    'period_id' => $period->id,
                    'period_summary_id' => $summary->id,
                    'incident_type' => $incident['type'] ?? 'unknown',
                    'incident_severity' => $incident['severity'] ?? null,
                    'message_length' => mb_strlen((string) ($incident['message'] ?? '')),
                    'exception' => get_class($incidentException),
                    'exception_message' => $incidentException->getMessage(),
                ]);
            }
        }

        $criticalCount = count(array_filter($allIncidents, fn ($i) => in_array($i['severity'] ?? 'high', ['high', 'critical'], true)));
        $warningCount = count(array_filter($allIncidents, fn ($i) => ($i['severity'] ?? '') === 'warning'));

        $stats = [
            'persons_detected' => $noiResult['employees_detected'] ?? 0,
            'records_loaded' => ($noiResult['rows_read'] ?? 0) + ($cobranzaResult['records_loaded'] ?? $cobranzaResult['cobranza_rows_read'] ?? 0),
            'records_excluded' => ($noiResult['rows_skipped'] ?? 0) + ($cobranzaResult['records_excluded'] ?? $cobranzaResult['cobranza_rows_skipped'] ?? 0),
            'branches_included' => 12,
            'branches_excluded' => 2,
            'pending_locations' => $cobranzaResult['pending_locations'] ?? 0,
            'critical_incidents' => $criticalCount,
            'warnings' => $warningCount,
            'import_errors' => count($importErrors),
            'import_error_sources' => array_map(fn (array $d) => $d['name'], $importErrorDetails),
        ];

        $this->progress($run, 'Actualización completada.', 100, $stats);
    }

    /**
     * Registra el avance de la corrida para que la UI lo muestre.
     *
     * Actualiza en la corrida el campo log y la metadata (paso actual, porcentaje,
     * fuente/archivo en proceso y estadísticas si se envían). No hace nada si no hay corrida.
     *
     * @param  PeriodDatabaseUpdateRun|null  $run  Corrida en curso.
     * @param  string  $step  Texto del paso actual (en español, visible al usuario).
     * @param  int  $percent  Porcentaje de avance 0–100.
     * @param  array<string, mixed>  $stats  Estadísticas a publicar (solo si no está vacío).
     * @param  string|null  $currentSource  Código de la fuente que se está procesando.
     * @param  string|null  $currentFile  Nombre del archivo que se está procesando.
     */
    private function progress(
        ?PeriodDatabaseUpdateRun $run,
        string $step,
        int $percent,
        array $stats = [],
        ?string $currentSource = null,
        ?string $currentFile = null,
    ): void {
        if (! $run) {
            return;
        }

        $meta = $run->metadata ?? [];
        $meta['current_step'] = $step;
        $meta['progress_percent'] = $percent;
        if ($currentSource !== null) {
            $meta['current_source'] = $currentSource;
        }
        if ($currentFile !== null) {
            $meta['current_file'] = $currentFile;
        }
        if (! empty($stats)) {
            $meta['stats'] = $stats;
        }

        $run->update(['log' => $step, 'metadata' => $meta]);
    }

    /**
     * Verifica si el usuario canceló la corrida.
     *
     * Recarga la corrida desde BD; si su estado ya no es "running" ni "queued"
     * detiene el proceso. No hace nada si no hay corrida.
     *
     * @param  PeriodDatabaseUpdateRun|null  $run  Corrida en curso.
     *
     * @throws \RuntimeException Cuando la corrida fue cancelada.
     */
    private function checkCancelled(?PeriodDatabaseUpdateRun $run): void
    {
        if (! $run) {
            return;
        }
        $run->refresh();
        if (! in_array($run->status, ['running', 'queued'], true)) {
            throw new \RuntimeException('El proceso fue cancelado antes de completarse.');
        }
    }
}
