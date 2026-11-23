<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\PeriodDatabaseUpdateRun;
use App\Models\Recovery;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Services\ColumnMapService;
use App\Services\SaveheartsRuleService;
use App\Support\MoneyText;
use App\Support\ReportUploadStorage;
use Carbon\Carbon;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Importador del reporte "Ingresos Cobranza" de Lendus (fuente
 * `lendus_ingresos_cobranza`): cada fila es un pago recibido y alimenta la
 * RECUPERACIÓN (fact_recoveries) y la Efectividad de Cobranza.
 *
 * Tiene dos modos:
 * - handle(): importación completa por bloques de 1,000 filas (PhpSpreadsheet con
 *   filtro de lectura) a fact_recoveries, con columnas autorizadas de
 *   ColumnMapService, sucursal por prefijo de contrato y regla Savehearts.
 * - scanForDatabaseUpdate(): lectura en streaming (OpenSpout) usada por
 *   "Cargar registros" para detectar promotores y sus sucursales y dar de alta
 *   empleados, sucursales y asignaciones empleado → sucursal del periodo.
 * Lo usan ReportAnalysisService y DatabaseUpdateService.
 */
class LendusIngresosCobranzaImportService
{
    // Máx 20 chars — columna `importer_version` es VARCHAR(20) en MySQL/MariaDB real
    // (SQLite, usado en tests, no valida longitud y deja pasar strings más largos sin error).
    public const IMPORTER_VERSION = 'ea_aprobada_2026_10';

    private array $branchCache = [];

    /**
     * @param  BranchResolverService  $branchResolver  Resuelve sucursal por código/ruta y normaliza productos.
     * @param  ColumnMapService  $columnMap  Catálogo de columnas autorizadas por fuente.
     * @param  SaveheartsRuleService  $savehearts  Detecta pagos Savehearts y calcula la parte de la empresa.
     */
    public function __construct(
        private readonly BranchResolverService $branchResolver,
        private readonly ColumnMapService $columnMap,
        private readonly SaveheartsRuleService $savehearts,
    ) {}

    /**
     * Importa el archivo a fact_recoveries.
     * Flujo: lee una vista previa de 30 filas para detectar encabezados, exige la
     * columna de promotor, borra las recuperaciones previas del upload y procesa el
     * resto en bloques de 1,000 filas con inserción masiva. Por cada fila guarda
     * montos (capital, interés, IVA, cargos, excedente, total), fechas, producto
     * normalizado y original, banderas Savehearts y versión del importador.
     * Los montos de TODOS los seguros se conservan; la exclusión se hace al consultar
     * (bandera is_savehearts).
     * Efectos: borra e inserta fact_recoveries del upload; puede crear sucursales.
     * Nota: un error al preparar una fila detiene la importación con excepción
     * (los bloques ya insertados permanecen).
     *
     * @param  ReportUpload  $upload  Archivo subido a importar.
     * @param  callable|null  $progress  Callback opcional con estadísticas cada 250 filas y por bloque insertado.
     * @return array<string,mixed> Estadísticas (leídas, insertadas, omitidas, con error) y log.
     *
     * @throws \RuntimeException Si falta el archivo, no tiene hojas/filas, faltan columnas mínimas o falla una fila.
     */
    public function handle(ReportUpload $upload, ?callable $progress = null): array
    {
        if (! $upload->stored_path) {
            throw new \RuntimeException('El archivo no tiene stored_path.');
        }

        if (! ReportUploadStorage::exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico no existe en storage/public.');
        }

        @set_time_limit(0);

        $absolutePath = ReportUploadStorage::path($upload->stored_path);

        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);

        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $sheetsInfo = $reader->listWorksheetInfo($absolutePath);

        if (empty($sheetsInfo)) {
            throw new \RuntimeException('No se encontraron hojas en el archivo de cobranza.');
        }

        $sheetInfo = $sheetsInfo[0];
        $sheetName = $sheetInfo['worksheetName'];
        $highestRow = (int) $sheetInfo['totalRows'];
        $lastColumnLetter = (string) $sheetInfo['lastColumnLetter'];

        if ($highestRow <= 0) {
            throw new \RuntimeException('El archivo de cobranza no contiene filas útiles.');
        }

        $previewRows = $this->readChunk(
            path: $absolutePath,
            sheetName: $sheetName,
            startRow: 1,
            endRow: min(30, $highestRow),
            lastColumnLetter: $lastColumnLetter,
        );

        if (empty($previewRows)) {
            throw new \RuntimeException('No se pudo leer la vista previa del archivo de cobranza.');
        }

        $headerRowIndex = $this->detectHeaderRowIndex($previewRows);
        $headerRow = $previewRows[$headerRowIndex] ?? null;

        if (! $headerRow || ! is_array($headerRow)) {
            throw new \RuntimeException('No se encontró una fila de encabezados válida en el archivo de cobranza.');
        }

        $headerMap = $this->buildHeaderMap($headerRow);

        $requiredColumns = ['promoter_name'];

        $missingRequired = collect($requiredColumns)
            ->filter(fn (string $field) => ! array_key_exists($field, $headerMap))
            ->values()
            ->all();

        if (! empty($missingRequired)) {
            throw new \RuntimeException(
                'El archivo de cobranza no contiene columnas mínimas requeridas: '
                .implode(', ', $missingRequired)
                .'. Encabezados detectados: '
                .implode(', ', array_values(array_filter($headerRow, fn ($value) => filled($value))))
            );
        }

        Recovery::query()
            ->where('report_upload_id', $upload->id)
            ->delete();

        $rowsRead = 0;
        $rowsInserted = 0;
        $rowsSkipped = 0;
        $rowsWithErrors = 0;

        $chunkSize = 1000;
        $dataStartRow = $headerRowIndex + 2;

        for ($start = $dataStartRow; $start <= $highestRow; $start += $chunkSize) {
            $end = min($start + $chunkSize - 1, $highestRow);

            $chunkRows = $this->readChunk(
                path: $absolutePath,
                sheetName: $sheetName,
                startRow: $start,
                endRow: $end,
                lastColumnLetter: $lastColumnLetter,
            );

            $batch = [];

            foreach ($chunkRows as $row) {
                if (! is_array($row) || $this->isEmptyRow($row)) {
                    $rowsSkipped++;

                    continue;
                }

                $rowsRead++;

                if ($progress && $rowsRead % 250 === 0) {
                    $progress([
                        'rows_read' => $rowsRead,
                        'rows_inserted' => $rowsInserted,
                        'rows_skipped' => $rowsSkipped,
                        'rows_with_errors' => $rowsWithErrors,
                        'log' => "Procesando cobranza... {$rowsRead} filas leídas.",
                    ]);
                }

                try {
                    $mapped = $this->mapRow($row, $headerMap);

                    if (! $this->shouldInsertRow($mapped)) {
                        $rowsSkipped++;

                        continue;
                    }

                    // ── Regla Savehearts ────────────────────────────────────────
                    $isSavehearts = $this->savehearts->isSaveheartsRow($mapped);
                    $saveheartsShare = $isSavehearts
                        ? $this->savehearts->saveheartsCompanyShare($mapped)
                        : 0.0;
                    // Se conservan los montos de TODOS los seguros (incluidos los que no son CRECE).
                    // La exclusión se hace al consultar, con la bandera is_savehearts.

                    $branch = $this->resolveBranchFromCobranza(
                        $mapped['contract'],
                        $mapped['promoter_name'],
                        $mapped['branch_name_raw'],
                    );

                    $batch[] = [
                        'period_id' => $upload->period_id,
                        'report_upload_id' => $upload->id,
                        'branch_id' => $branch?->id,
                        'office_name_raw' => $mapped['branch_name_raw'],
                        'contract' => $mapped['contract'],
                        'client_name' => $mapped['client_name'],
                        'acreditado' => $mapped['acreditado'],
                        'normalized_client_name' => $mapped['client_name']
                            ? $this->normalizeHumanName($mapped['client_name'])
                            : null,
                        'promoter_name' => $mapped['promoter_name'],
                        'product_name' => $mapped['product_name'],
                        // Texto original (la Efectividad V4 excluye por REESTRUCT/UNIFICAC/CONDONAC/COBERTURA).
                        'product_name_raw' => $mapped['product_name_raw'] !== null ? mb_substr($mapped['product_name_raw'], 0, 160) : null,
                        'operation' => $mapped['operation'],
                        'concept' => $mapped['concept'],
                        'transaction' => $mapped['transaction'],
                        'capital' => $mapped['capital'],
                        'interest' => $mapped['interest'],
                        'tax' => $mapped['tax'],
                        'charges' => $mapped['charges'],
                        'charges_due' => $mapped['charges_due'],
                        'excedente' => $mapped['excedente'],
                        'total_amount' => $mapped['total_amount'],
                        'days_past_due' => $mapped['days_past_due'],
                        'payment_date' => $mapped['payment_date'],
                        'installment_due_date' => $mapped['installment_due_date'],
                        'installment_number' => $mapped['installment_number'],
                        'contract_due_date' => $mapped['contract_due_date'],
                        'is_savehearts' => $isSavehearts,
                        'savehearts_crece_share' => $saveheartsShare,
                        'importer_version' => self::IMPORTER_VERSION,
                        'raw_payload' => json_encode([
                            'branch_name_raw' => $mapped['branch_name_raw'],
                            'promoter_name' => $mapped['promoter_name'],
                            'client_name' => $mapped['client_name'],
                            'acreditado' => $mapped['acreditado'],
                            'product_name' => $mapped['product_name'],
                            'operation' => $mapped['operation'],
                            'concept' => $mapped['concept'],
                            'transaction' => $mapped['transaction'],
                            'total_amount_original' => $mapped['total_amount'],
                            'installment_due_date' => $mapped['installment_due_date'],
                            'installment_number' => $mapped['installment_number'],
                            'contract_due_date' => $mapped['contract_due_date'],
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                } catch (\Throwable $e) {
                    $rowsWithErrors++;

                    throw new \RuntimeException(
                        'Error al preparar fila de cobranza. '
                        .'Promotor: '.($mapped['promoter_name'] ?? 'N/D')
                        .' | Cliente: '.($mapped['client_name'] ?? 'N/D')
                        .' | Detalle: '.$e->getMessage()
                    );
                }
            }

            if (! empty($batch)) {
                Recovery::query()->insert($batch);
                $rowsInserted += count($batch);
                if ($progress) {
                    $progress([
                        'rows_read' => $rowsRead,
                        'rows_inserted' => $rowsInserted,
                        'rows_skipped' => $rowsSkipped,
                        'rows_with_errors' => $rowsWithErrors,
                        'log' => "Insertadas {$rowsInserted} filas de cobranza...",
                    ]);
                }
            }

            unset($chunkRows, $batch);
            gc_collect_cycles();
        }

        return [
            'rows_read' => $rowsRead,
            'rows_inserted' => $rowsInserted,
            'rows_skipped' => $rowsSkipped,
            'rows_with_errors' => $rowsWithErrors,
            'log' => sprintf(
                'Cobranza importada. Leídas: %d, insertadas: %d, omitidas: %d, con error: %d. Revisa Asignación sucursal para validar cruces, incidencias, altas y bajas.',
                $rowsRead,
                $rowsInserted,
                $rowsSkipped,
                $rowsWithErrors
            ),
        ];
    }

    /**
     * Escaneo rápido para "Cargar registros" (no inserta pagos):
     * 1. Estima filas para la barra de progreso.
     * 2. Lee en streaming la primera hoja; detecta encabezados con las primeras 30
     *    filas no vacías y acumula promotor → oficinas en memoria. Cada 1,000 filas
     *    actualiza el avance, revisa cancelación del run y un tope de tiempo.
     * 3. Registra incidencias 'warning' de promotores sin sucursal (no bloquean el reporte).
     * 4. Inserta empleados nuevos (source_system = lendus_cobranza).
     * 5. Upsert de sucursales: la sucursal real deducida del código del promotor y
     *    también las oficinas/rutas crudas como respaldo.
     * 6. Upsert de employee_branch_assignments del periodo (prefiere la sucursal real).
     * Efectos: escribe employees, branches y employee_branch_assignments; actualiza el run.
     *
     * @param  ReportUpload  $upload  Upload de cobranza del periodo.
     * @param  PeriodDatabaseUpdateRun|null  $run  Run de "Cargar registros" para reportar avance (opcional).
     * @param  int  $progressStart  Porcentaje inicial de esta etapa en la barra global.
     * @param  int  $progressEnd  Porcentaje final de esta etapa en la barra global.
     * @return array<string,mixed> Promotores, sucursales, asignaciones e incidencias detectadas, filas leídas/excluidas y duración.
     *
     * @throws \RuntimeException Si falta el archivo, no hay filas, no hay columna de promotor, se canceló el run o se excedió el tiempo.
     */
    public function scanForDatabaseUpdate(
        ReportUpload $upload,
        ?PeriodDatabaseUpdateRun $run = null,
        int $progressStart = 55,
        int $progressEnd = 78,
    ): array {
        if (! $upload->stored_path) {
            throw new \RuntimeException('El archivo de cobranza no tiene ruta de almacenamiento.');
        }

        if (! ReportUploadStorage::exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico de cobranza no existe en storage/public.');
        }

        @set_time_limit(0);

        $startTime = microtime(true);
        $absolutePath = ReportUploadStorage::path($upload->stored_path);

        // ── 1. Lectura solo de metadatos para estimar filas (barra de progreso) ────
        $this->updateRun($run, $progressStart, 'Preparando Cobranza…');
        $infoReader = IOFactory::createReaderForFile($absolutePath);
        $infoReader->setReadDataOnly(true);
        $sheetsInfo = $infoReader->listWorksheetInfo($absolutePath);

        if (empty($sheetsInfo)) {
            throw new \RuntimeException('No se encontró hoja en el archivo de cobranza.');
        }

        $totalRowsEstimate = min(max(0, (int) ($sheetsInfo[0]['totalRows'] ?? 0)), 100_000);

        if ($totalRowsEstimate <= 1) {
            throw new \RuntimeException('El archivo de cobranza no contiene filas con datos.');
        }

        // ── 2. Lectura en streaming de una sola pasada con OpenSpout (el archivo se abre una vez) ──
        // Guarda las primeras 30 filas no vacías para detectar el encabezado y luego lee
        // el resto fila por fila sin volver a abrir el archivo.
        $this->updateRun($run, $progressStart + 2, 'Abriendo Cobranza en modo streaming…');

        $spoutOptions = new Options(
            SHOULD_FORMAT_DATES: false,
            SHOULD_PRESERVE_EMPTY_ROWS: false,
        );
        $spoutReader = new Reader($spoutOptions);
        $spoutReader->open($absolutePath);

        $headerBuffer = []; // primeras ≤30 filas no vacías para detectar el encabezado
        $headerDetected = false;
        $promoterColIndex = null;
        $branchColIndex = null;

        $rowsRead = 0;
        $rowsSkipped = 0;
        $streamedCount = 0; // filas no vacías entregadas por OpenSpout
        $promoterBranchMap = [];
        $noAnyBranch = [];

        try {
            foreach ($spoutReader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $streamedCount++;
                    // OpenSpout Row::toArray() devuelve un arreglo disperso base 0
                    // indexado por la posición real de la columna — mismos índices que buildHeaderMap.
                    $values = $row->toArray();

                    // ── Fase A: acumula filas hasta poder detectar el encabezado ─
                    if (! $headerDetected) {
                        $headerBuffer[] = $values;

                        if (count($headerBuffer) >= 30) {
                            [$promoterColIndex, $branchColIndex] = $this->detectCobranzaColumns($headerBuffer);
                            $headerDetected = true;
                            $this->updateRun($run, $progressStart + 5, 'Leyendo Lendus Cobranza…');
                        }

                        continue;
                    }

                    // ── Fase B: procesa las filas de datos directamente ─────────────────────
                    $this->accumulateCobranzaRow(
                        $values, $promoterColIndex, $branchColIndex,
                        $promoterBranchMap, $noAnyBranch, $rowsRead, $rowsSkipped
                    );

                    // Cada 1000 filas: avance + revisión de cancelación + tope de tiempo
                    if ($rowsRead % 1_000 === 0 && $rowsRead > 0) {

                        if ($run) {
                            $run->refresh();
                            if (! in_array($run->status, ['running', 'queued'], true)) {
                                throw new \RuntimeException('El proceso fue cancelado durante la lectura de Cobranza.');
                            }
                        }

                        if ((microtime(true) - $startTime) > 1500) {
                            throw new \RuntimeException(sprintf(
                                'Cobranza: lectura superó 25 min. Filas leídas: %d. El archivo puede ser demasiado grande.',
                                $rowsRead
                            ));
                        }

                        if ($run && $totalRowsEstimate > 1) {
                            $pct = (int) ($progressStart + ($streamedCount / $totalRowsEstimate) * ($progressEnd - $progressStart));
                            $pct = min($progressEnd, max($progressStart + 5, $pct));
                            $uniq = count($promoterBranchMap);
                            $run->update([
                                'log' => "Leyendo Cobranza… {$rowsRead} filas, {$uniq} promotores únicos.",
                                'metadata' => array_merge($run->metadata ?? [], [
                                    'current_step' => "Leyendo Lendus Cobranza… {$uniq} promotores",
                                    'progress_percent' => $pct,
                                    'current_source' => 'lendus_ingresos_cobranza',
                                    'processed_rows' => $rowsRead,
                                    'total_rows' => $totalRowsEstimate,
                                    'cobranza_rows_read' => $rowsRead,
                                    'cobranza_rows_skipped' => $rowsSkipped,
                                    'cobranza_total_rows' => $totalRowsEstimate,
                                    'cobranza_promoters_detected' => $uniq,
                                ]),
                            ]);
                        }
                    }
                }
                break; // solo la primera hoja
            }

            // ── Archivos con menos de 30 filas no vacías ─────────────
            if (! $headerDetected && ! empty($headerBuffer)) {
                [$promoterColIndex, $branchColIndex] = $this->detectCobranzaColumns($headerBuffer);
            }

            // Procesa las filas de datos que quedaron en el búfer de detección de encabezado
            // (filas posteriores al encabezado dentro del búfer de 30 filas)
            if ($promoterColIndex !== null) {
                $headerBufferIndex = $this->detectHeaderRowIndex($headerBuffer);
                foreach (array_slice($headerBuffer, $headerBufferIndex + 1) as $bufferedValues) {
                    $this->accumulateCobranzaRow(
                        $bufferedValues, $promoterColIndex, $branchColIndex,
                        $promoterBranchMap, $noAnyBranch, $rowsRead, $rowsSkipped
                    );
                }
            }

        } finally {
            $spoutReader->close();
        }

        // ── 4. Incidencias: promotores que nunca aparecieron con sucursal ──────
        // Severidad 'warning' (no 'high') para que no bloqueen la generación del reporte.
        $incidents = [];
        foreach ($noAnyBranch as $normalized => $rawName) {
            if (empty($promoterBranchMap[$normalized]['branches'])) {
                $incidents[] = [
                    'type' => 'promoter_without_branch',
                    'severity' => 'warning',
                    'message' => "Promotor sin sucursal: {$rawName}",
                ];
            }
        }

        // ── 5. Inserción masiva de empleados nuevos (precarga para evitar consultas por fila) ─
        // la tabla employees no tiene índice único en (normalized_name, source_system),
        // así que primero se cargan los existentes y solo se insertan los realmente nuevos.
        $existingEmployees = ! empty($promoterBranchMap)
            ? Employee::query()
                ->where('source_system', 'lendus_cobranza')
                ->whereIn('normalized_name', array_keys($promoterBranchMap))
                ->pluck('id', 'normalized_name')
                ->all()
            : [];

        $newEmployeeRows = [];
        foreach ($promoterBranchMap as $normalized => $data) {
            if (! isset($existingEmployees[$normalized])) {
                $newEmployeeRows[] = [
                    'full_name' => $data['full_name'],
                    'normalized_name' => $normalized,
                    'is_active' => true,
                    'source_system' => 'lendus_cobranza',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($newEmployeeRows, 300) as $chunk) {
            Employee::query()->insert($chunk);
        }

        $employeeIdMap = ! empty($promoterBranchMap)
            ? Employee::query()
                ->where('source_system', 'lendus_cobranza')
                ->whereIn('normalized_name', array_keys($promoterBranchMap))
                ->pluck('id', 'normalized_name')
                ->all()
            : [];

        // ── 6. Upsert masivo de sucursales (code tiene índice único en BD) ────────
        // Primera pasada: reúne todas las sucursales. Para cada promotor con forma de código
        // (p. ej. "ORI09247") resuelve la sucursal real con BranchResolverService.
        $branchRows = [];
        foreach ($promoterBranchMap as $normalizedPromoter => $data) {
            $promoterRaw = $data['full_name'] ?? $normalizedPromoter;

            // Si el promotor parece código, resuelve la sucursal real por su prefijo
            $realBranchFromCode = null;
            if (preg_match('/^[A-Z]{2,4}\d{4,}/i', $promoterRaw)) {
                $resolvedName = $this->branchResolver->resolveBranchNameFromCode($promoterRaw);
                if ($resolvedName) {
                    $realNormalized = $this->normalizeHumanName($resolvedName);
                    if ($realNormalized !== '' && ! isset($branchRows[$realNormalized])) {
                        $code = Str::upper(Str::limit(
                            preg_replace('/[^A-Za-z0-9]+/', '_', Str::ascii($realNormalized)),
                            60, ''
                        ));
                        $branchRows[$realNormalized] = [
                            'code' => $code ?: 'SUCURSAL_'.substr(md5($realNormalized), 0, 8),
                            'name' => $resolvedName,
                            'normalized_name' => $realNormalized,
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                        $realBranchFromCode = $realNormalized;
                    }
                }
            }

            // También registra las oficinas/rutas crudas (se usan como respaldo)
            foreach ($data['branches'] as $normalizedBranch => $rawBranchName) {
                if (! isset($branchRows[$normalizedBranch])) {
                    $code = Str::upper(Str::limit(
                        preg_replace('/[^A-Za-z0-9]+/', '_', Str::ascii($normalizedBranch)),
                        60, ''
                    ));
                    $branchRows[$normalizedBranch] = [
                        'code' => $code ?: 'SUCURSAL_'.substr(md5($normalizedBranch), 0, 8),
                        'name' => $rawBranchName,
                        'normalized_name' => $normalizedBranch,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            // Si se resolvió la sucursal real, se usa en lugar del mapa de oficinas
            if ($realBranchFromCode) {
                $promoterBranchMap[$normalizedPromoter]['real_branch_normalized'] = $realBranchFromCode;
            }
        }

        if (! empty($branchRows)) {
            foreach (array_chunk(array_values($branchRows), 300) as $chunk) {
                Branch::query()->upsert($chunk, ['code'], ['name', 'normalized_name', 'is_active', 'updated_at']);
            }
        }

        $branchIdMap = ! empty($branchRows)
            ? Branch::query()
                ->whereIn('code', array_column(array_values($branchRows), 'code'))
                ->pluck('id', 'normalized_name')
                ->all()
            : [];

        // ── 7. Upsert masivo de EmployeeBranchAssignments ───────────────────────
        // Índice único en BD: (period_id, employee_id)
        // Se prefiere la sucursal real (por prefijo del código de promotor) sobre la oficina/ruta.
        $assignmentRows = [];
        foreach ($promoterBranchMap as $normalizedPromoter => $data) {
            $employeeId = $employeeIdMap[$normalizedPromoter] ?? null;
            if (! $employeeId) {
                continue;
            }

            // Usa la sucursal real si se resolvió por prefijo del código de promotor
            $realBranchNormalized = $data['real_branch_normalized'] ?? null;
            $branchesToAssign = $realBranchNormalized
                ? [$realBranchNormalized => true]
                : $data['branches'];

            foreach ($branchesToAssign as $normalizedBranch => $_) {
                $branchId = $branchIdMap[$normalizedBranch] ?? null;
                if (! $branchId) {
                    continue;
                }

                $assignmentRows[] = [
                    'period_id' => $upload->period_id,
                    'employee_id' => $employeeId,
                    'branch_id' => $branchId,
                    'source_type' => 'lendus',
                    'match_type' => 'normalized',
                    'confidence' => '0.80',
                    'was_manual_reviewed' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($assignmentRows, 300) as $chunk) {
            EmployeeBranchAssignment::query()->upsert(
                $chunk,
                ['period_id', 'employee_id'],
                ['branch_id', 'source_type', 'match_type', 'confidence', 'was_manual_reviewed', 'updated_at'],
            );
        }

        // Las rutas y ubicaciones no operativas de cobranza se ignoran en silencio.
        // NO son sucursales, no generan incidencias y no bloquean el reporte.

        $duration = round(microtime(true) - $startTime, 1);

        return [
            'promoters_detected' => count($promoterBranchMap),
            'branches_detected' => count($branchRows),
            'records_loaded' => $rowsRead,
            'records_excluded' => $rowsSkipped,
            'pending_locations' => 0,
            'pending_location_names' => [],
            'cobranza_rows_read' => $rowsRead,
            'cobranza_rows_skipped' => $rowsSkipped,
            'cobranza_promoters_detected' => count($promoterBranchMap),
            'cobranza_branches_detected' => count($branchRows),
            'cobranza_assignments_created' => count($assignmentRows),
            'cobranza_incidents_created' => count($incidents),
            'cobranza_duration_seconds' => $duration,
            'incidents' => $incidents,
        ];
    }

    /**
     * Lee solo el rango de filas indicado de una hoja (filtro de lectura de
     * PhpSpreadsheet) para no cargar el archivo completo en memoria.
     *
     * @param  string  $path  Ruta absoluta del archivo.
     * @param  string  $sheetName  Hoja a leer.
     * @param  int  $startRow  Primera fila (1-based).
     * @param  int  $endRow  Última fila (inclusive).
     * @param  string  $lastColumnLetter  Última columna con datos.
     * @return array<int,array> Filas del rango (valores calculados).
     */
    private function readChunk(
        string $path,
        string $sheetName,
        int $startRow,
        int $endRow,
        string $lastColumnLetter,
    ): array {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $reader->setLoadSheetsOnly([$sheetName]);
        $reader->setReadFilter(new class($startRow, $endRow) implements IReadFilter
        {
            /**
             * @param  int  $startRow  Primera fila permitida.
             * @param  int  $endRow  Última fila permitida.
             */
            public function __construct(
                private readonly int $startRow,
                private readonly int $endRow,
            ) {}

            /**
             * Filtro de lectura: solo permite celdas dentro del rango de filas.
             *
             * @param  string  $columnAddress  Columna de la celda.
             * @param  int  $row  Fila de la celda.
             * @param  string  $worksheetName  Hoja (no se usa).
             * @return bool true si la celda se debe leer.
             */
            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row >= $this->startRow && $row <= $this->endRow;
            }
        });

        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getActiveSheet();

        $range = 'A'.$startRow.':'.$lastColumnLetter.$endRow;

        $rows = $sheet->rangeToArray(
            $range,
            null,
            true,
            false,
            false,
        );

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $rows;
    }

    /**
     * Detecta los índices (base 0) de las columnas de promotor y de oficina/sucursal
     * a partir del bloque de filas iniciales.
     *
     * @param  array  $rows  Primeras filas no vacías del archivo.
     * @return array{0:int,1:int|null} [índice de promotor, índice de oficina o null].
     *
     * @throws \RuntimeException Si no se encuentra la columna de promotor/asesor.
     */
    private function detectCobranzaColumns(array $rows): array
    {
        $headerRowIndex = $this->detectHeaderRowIndex($rows);
        $headerRow = $rows[$headerRowIndex] ?? [];
        $headerMap = $this->buildHeaderMap($headerRow);
        $promoterColIndex = $headerMap['promoter_name'] ?? null;
        $branchColIndex = $headerMap['branch_name_raw'] ?? null;

        if ($promoterColIndex === null) {
            $detected = implode(', ', array_filter(array_map(fn ($v) => $this->cellToString($v), $headerRow), fn ($v) => $v !== ''));
            throw new \RuntimeException(
                "No se encontró columna de promotor/asesor en cobranza. Encabezados detectados: {$detected}"
            );
        }

        return [$promoterColIndex, $branchColIndex];
    }

    /**
     * Acumula una fila en el mapa promotor → oficinas (por referencia, sin consultas a BD).
     * Se llama una vez por fila leída en streaming. Lleva además la lista de
     * promotores que aún no han aparecido con ninguna oficina.
     *
     * @param  array  $values  Valores de la fila.
     * @param  int  $promoterColIndex  Índice de la columna de promotor.
     * @param  int|null  $branchColIndex  Índice de la columna de oficina (null si no existe).
     * @param  array  $promoterBranchMap  Mapa acumulado nombre normalizado => full_name + oficinas.
     * @param  array  $noAnyBranch  Promotores sin oficina detectada.
     * @param  int  $rowsRead  Contador de filas leídas.
     * @param  int  $rowsSkipped  Contador de filas omitidas (sin promotor).
     */
    private function accumulateCobranzaRow(
        array $values,
        int $promoterColIndex,
        ?int $branchColIndex,
        array &$promoterBranchMap,
        array &$noAnyBranch,
        int &$rowsRead,
        int &$rowsSkipped,
    ): void {
        $promoterRaw = $this->cleanString($values[$promoterColIndex] ?? null);

        if ($promoterRaw === null) {
            $rowsSkipped++;

            return;
        }

        $normalizedPromoter = $this->normalizeHumanName($promoterRaw);
        if ($normalizedPromoter === '') {
            $rowsSkipped++;

            return;
        }

        $rowsRead++;

        if (! isset($promoterBranchMap[$normalizedPromoter])) {
            $promoterBranchMap[$normalizedPromoter] = ['full_name' => $promoterRaw, 'branches' => []];
        }

        $branchRaw = $branchColIndex !== null
            ? $this->cleanString($values[$branchColIndex] ?? null)
            : null;

        if ($branchRaw !== null) {
            $normalizedBranch = $this->normalizeHumanName($branchRaw);
            if ($normalizedBranch !== '') {
                $promoterBranchMap[$normalizedPromoter]['branches'][$normalizedBranch] = $branchRaw;
                unset($noAnyBranch[$normalizedPromoter]);
            }
        } elseif (empty($promoterBranchMap[$normalizedPromoter]['branches'])) {
            $noAnyBranch[$normalizedPromoter] = $promoterRaw;
        }
    }

    /**
     * Escribe paso actual, porcentaje y (opcional) filas procesadas/total en el
     * metadata del run de "Cargar registros". Sin run no hace nada.
     *
     * @param  PeriodDatabaseUpdateRun|null  $run  Run a actualizar.
     * @param  int  $pct  Porcentaje global de avance.
     * @param  string  $step  Texto del paso actual.
     * @param  int  $processedRows  Filas procesadas (opcional).
     * @param  int  $totalRows  Total estimado de filas (opcional).
     */
    private function updateRun(
        ?PeriodDatabaseUpdateRun $run,
        int $pct,
        string $step,
        int $processedRows = 0,
        int $totalRows = 0,
    ): void {
        if (! $run) {
            return;
        }

        $extra = ['current_source' => 'lendus_ingresos_cobranza'];
        if ($totalRows > 0) {
            $extra['processed_rows'] = $processedRows;
            $extra['total_rows'] = $totalRows;
        }

        $run->update([
            'log' => $step,
            'metadata' => array_merge($run->metadata ?? [], [
                'current_step' => $step,
                'progress_percent' => $pct,
            ] + $extra),
        ]);
    }

    /**
     * Devuelve la fila con más coincidencias de encabezados conocidos de cobranza
     * (promotor, contrato, producto, operación, capital, interés, total, oficina...).
     *
     * @param  array  $rows  Filas candidatas (vista previa).
     * @return int Índice del renglón de encabezados.
     */
    private function detectHeaderRowIndex(array $rows): int
    {
        $bestIndex = 0;
        $bestScore = -1;

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized = array_map(
                fn ($value) => $this->normalizeHeader($this->cellToString($value)),
                $row
            );

            $score = 0;

            foreach ($normalized as $value) {
                if (
                    in_array($value, [
                        'promotor',
                        'contrato',
                        'producto_de_credito',
                        'operacion',
                        'concepto',
                        'transaccion',
                        'capital',
                        'interes',
                        'impuesto',
                        'cargos_calendario',
                        'cargos_vencimiento',
                        'excedente',
                        'total',
                        'dias_vencidos_pago',
                        'oficina',
                        'ruta',
                        'fecha_transaccion',
                    ], true)
                ) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = (int) $index;
            }
        }

        return $bestIndex;
    }

    /**
     * Mapea cada campo autorizado (ColumnMapService, 'lendus_ingresos_cobranza') a la
     * primera columna cuyo encabezado normalizado coincide exactamente con un alias,
     * sin reutilizar columnas.
     *
     * @param  array  $headerRow  Celdas del encabezado.
     * @return array<string,int> Campo => índice de columna.
     */
    private function buildHeaderMap(array $headerRow): array
    {
        $normalizedHeaders = [];
        foreach ($headerRow as $index => $header) {
            $normalized = $this->normalizeHeader($this->cellToString($header));
            if ($normalized !== '') {
                $normalizedHeaders[(int) $index] = $normalized;
            }
        }

        // ColumnMapService es la única fuente de verdad de las columnas autorizadas.
        $defs = $this->columnMap->getFieldDefinitions('lendus_ingresos_cobranza');
        $map = [];
        $matched = [];

        foreach ($defs as $field => $def) {
            foreach ($def['aliases'] as $alias) {
                foreach ($normalizedHeaders as $index => $normalizedHeader) {
                    if ($normalizedHeader === $alias && ! in_array($index, $matched, true)) {
                        $map[$field] = $index;
                        $matched[] = $index;
                        break 2;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Convierte la fila en un arreglo normalizado: textos, producto normalizado y
     * original, fechas (cuota/vencimiento solo si el Excel las trae; nunca se
     * reconstruyen), montos por componente y total. Si no hay columna Total, el
     * total = capital + interés + IVA + cargos + cargos vencidos.
     *
     * @param  array  $row  Fila de datos.
     * @param  array  $headerMap  Mapa campo => índice.
     * @return array<string,mixed> Pago normalizado.
     */
    private function mapRow(array $row, array $headerMap): array
    {
        $promoterName = $this->cleanString($this->valueFromRow($row, $headerMap, 'promoter_name'));
        $contract = $this->cleanString($this->valueFromRow($row, $headerMap, 'contract'));
        $clientName = $this->cleanString($this->valueFromRow($row, $headerMap, 'client_name'));
        $branchNameRaw = $this->cleanString($this->valueFromRow($row, $headerMap, 'branch_name_raw'));
        $rawProductName = $this->cleanString($this->valueFromRow($row, $headerMap, 'product_name'));
        $productName = $rawProductName
            ? $this->branchResolver->normalizeProduct($rawProductName, null, null)
            : null;

        $operation = $this->cleanString($this->valueFromRow($row, $headerMap, 'operation'));
        $concept = $this->cleanString($this->valueFromRow($row, $headerMap, 'concept'));
        $transaction = $this->cleanString($this->valueFromRow($row, $headerMap, 'transaction'));
        $paymentDate = $this->toDateValue($this->valueFromRow($row, $headerMap, 'payment_date'));
        $acreditado = $this->cleanString($this->valueFromRow($row, $headerMap, 'acreditado'));

        // Fecha cuota / número de cuota / vencimiento de contrato: solo si el Excel los trae.
        // Nunca se reconstruyen (p.ej. a partir de "Días vencidos pago").
        $installmentDueDate = $this->toDateValue($this->valueFromRow($row, $headerMap, 'installment_due_date'));
        $installmentNumber = $this->cleanString($this->valueFromRow($row, $headerMap, 'installment_number'));
        $contractDueDate = $this->toDateValue($this->valueFromRow($row, $headerMap, 'contract_due_date'));

        $capital = $this->toDecimal($this->valueFromRow($row, $headerMap, 'capital')) ?? 0;
        $interest = $this->toDecimal($this->valueFromRow($row, $headerMap, 'interest')) ?? 0;
        $tax = $this->toDecimal($this->valueFromRow($row, $headerMap, 'tax')) ?? 0;
        $charges = $this->toDecimal($this->valueFromRow($row, $headerMap, 'charges')) ?? 0;
        $chargesDue = $this->toDecimal($this->valueFromRow($row, $headerMap, 'charges_due')) ?? 0;
        $excedente = $this->toDecimal($this->valueFromRow($row, $headerMap, 'excedente')) ?? 0;
        $daysPastDue = (int) ($this->toDecimal($this->valueFromRow($row, $headerMap, 'days_past_due')) ?? 0) ?: null;

        $explicitTotal = $this->toDecimal($this->valueFromRow($row, $headerMap, 'total'));
        $totalAmount = $explicitTotal ?? ($capital + $interest + $tax + $charges + $chargesDue);

        return [
            'promoter_name' => $promoterName,
            'contract' => $contract,
            'client_name' => $clientName,
            'acreditado' => $acreditado,
            'branch_name_raw' => $branchNameRaw,
            'product_name' => $productName,
            'product_name_raw' => $rawProductName,
            'operation' => $operation,
            'concept' => $concept,
            'transaction' => $transaction,
            'payment_date' => $paymentDate,
            'installment_due_date' => $installmentDueDate,
            'installment_number' => $installmentNumber,
            'contract_due_date' => $contractDueDate,
            'capital' => $capital,
            'interest' => $interest,
            'tax' => $tax,
            'charges' => $charges,
            'charges_due' => $chargesDue,
            'excedente' => $excedente,
            'total_amount' => $totalAmount,
            'days_past_due' => $daysPastDue,
        ];
    }

    /**
     * Solo se insertan filas con promotor.
     *
     * @param  array  $mapped  Fila normalizada.
     * @return bool true si tiene promotor.
     */
    private function shouldInsertRow(array $mapped): bool
    {
        return filled($mapped['promoter_name']);
    }

    /**
     * Resuelve la sucursal operativa en este orden:
     * 1. Prefijo del código de contrato (p. ej. "24ORI032294" → ORI → ORIZABA).
     * 2. Nombre del promotor si empieza con un prefijo de código reconocible.
     * 3. Columna cruda de oficina/sucursal (interna — nunca se muestra como métrica).
     *
     * @param  string|null  $contract  Contrato del pago.
     * @param  string|null  $promoterName  Promotor (a veces viene como código).
     * @param  string|null  $branchNameRaw  Oficina/ruta tal como viene.
     * @return Branch|null Sucursal resuelta o null.
     */
    private function resolveBranchFromCobranza(?string $contract, ?string $promoterName, ?string $branchNameRaw): ?Branch
    {
        if ($contract) {
            $branch = $this->branchResolver->findOrCreateBranchByCode($contract);
            if ($branch) {
                return $branch;
            }
        }

        if ($promoterName && preg_match('/^[A-Z]{2,4}\d{4,}/i', $promoterName)) {
            $branch = $this->branchResolver->findOrCreateBranchByCode($promoterName);
            if ($branch) {
                return $branch;
            }
        }

        return $this->resolveBranch($branchNameRaw);
    }

    /**
     * Convierte una oficina/ruta a la sucursal operativa oficial (sin crear rutas
     * desconocidas), con caché en memoria por nombre normalizado.
     *
     * @param  string|null  $branchName  Oficina/ruta.
     * @return Branch|null Sucursal oficial o null.
     */
    private function resolveBranch(?string $branchName): ?Branch
    {
        if (! $branchName) {
            return null;
        }

        $normalized = $this->normalizeHumanName($branchName);

        if ($normalized === '') {
            return null;
        }

        if (isset($this->branchCache[$normalized])) {
            return $this->branchCache[$normalized];
        }

        // Convierte ruta/oficina → sucursal operativa oficial. Nunca crea rutas desconocidas.
        $branch = $this->branchResolver->resolveOfficialBranchByName($branchName);

        return $this->branchCache[$normalized] = $branch;
    }

    /**
     * @param  array  $row  Fila de datos.
     * @param  array  $headerMap  Mapa campo => índice.
     * @param  string  $field  Campo lógico.
     * @return mixed Valor de la celda o null si la columna no existe.
     */
    private function valueFromRow(array $row, array $headerMap, string $field): mixed
    {
        if (! array_key_exists($field, $headerMap)) {
            return null;
        }

        return $row[$headerMap[$field]] ?? null;
    }

    /**
     * Normaliza un encabezado a snake (ASCII, minúsculas, no alfanuméricos → '_').
     *
     * @param  string  $value  Encabezado.
     * @return string Encabezado normalizado.
     */
    private function normalizeHeader(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();
    }

    /**
     * Normaliza un nombre de persona u oficina (ASCII, minúsculas, sin signos, espacios simples).
     *
     * @param  string|null  $value  Texto original.
     * @return string Texto normalizado ('' si viene vacío).
     */
    private function normalizeHumanName(?string $value): string
    {
        return Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();
    }

    /**
     * @param  mixed  $value  Valor de celda.
     * @return string|null Texto recortado o null si queda vacío.
     */
    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = $this->cellToString($value);

        return $value !== '' ? $value : null;
    }

    /**
     * Convierte cualquier valor de celda a texto (fechas como Y-m-d, booleanos como 1/0).
     *
     * @param  mixed  $value  Valor de celda.
     * @return string Texto recortado.
     */
    private function cellToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return trim((string) json_encode($value, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Convierte un monto a float SIN redondear. Si el texto trae coma y punto, la
     * coma se toma como separador de miles; si solo trae coma, se toma como decimal.
     *
     * @param  mixed  $value  Valor de celda.
     * @return float|null Monto o null si no es numérico.
     */
    private function toDecimal(mixed $value): ?float
    {
        // Regla única de montos en texto (incluye «12,500» = 12500): ver App\Support\MoneyText.
        return MoneyText::toDecimal($value);
    }

    /**
     * Convierte una fecha (DateTime, serie de Excel o texto) a 'Y-m-d'.
     *
     * @param  mixed  $value  Valor de celda.
     * @return string|null Fecha ISO o null.
     */
    private function toDateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject($value))->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array  $row  Fila leída.
     * @return bool true si todas las celdas están vacías.
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
