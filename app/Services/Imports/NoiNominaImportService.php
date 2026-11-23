<?php

namespace App\Services\Imports;

use App\Models\Employee;
use App\Models\EmployeeMatchRejection;
use App\Models\NoiMovement;
use App\Models\ReportUpload;
use App\Services\PersonIdentityResolverService;
use App\Support\MoneyText;
use App\Support\ReportUploadStorage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Importador de la nómina NOI (fuentes `noi_nomina` y `noi_nomina_fiscal`):
 * cada fila es un concepto (percepción/deducción) de un trabajador y alimenta
 * fact_noi_movements, de donde salen Nómina y Capital Humano, roster de
 * colaboradores, IMSS y rotación derivados.
 *
 * Responsabilidades:
 * - Leer el archivo NOI (bloques "Clave del trabajador : N" + nombre, o formato
 *   plano por encabezados) y guardar un movimiento por concepto con importe.
 * - Resolver la IDENTIDAD del empleado de forma estable (ver resolveNoiIdentity()):
 *   la clave NOI solo identifica dentro del mismo sistema y se valida contra el nombre.
 * - Clasificar la evidencia de identidad fuera de NOI (PersonIdentityResolverService)
 *   y generar incidencias de revisión sin bloquear nómina real.
 * - Anclar con fila sintética a trabajadores cuyo bloque existe pero todos sus
 *   conceptos vienen en $0, solo si tienen evidencia fuera de NOI.
 * Lo usan ReportAnalysisService (handle) y DatabaseUpdateService (scanForDatabaseUpdate).
 */
class NoiNominaImportService
{
    private const NOI_IDENTITY_TYPE = 'noi_clave';

    /** Identidad dentro del archivo en curso: nombre normalizado => [clave => employee_id]. */
    private array $fileIdentity = [];

    /** Incidencias de identidad (homónimos, claves reutilizadas) del archivo en curso. */
    private array $identityIncidents = [];

    private string $identitySystem = 'noi_nomina';

    private string $identityFile = '';

    private ?int $identityPeriodId = null;

    private ?bool $identitiesTable = null;

    private array $employeeCache = [];

    /** Cache de evaluateNoiCandidateEvidence() por nombre normalizado, vigente solo dentro de una corrida de handle(). */
    private array $evidenceCache = [];

    /**
     * @param  PersonIdentityResolverService  $identityResolver  Evalúa evidencia de identidad y similitud de nombres.
     */
    public function __construct(
        private readonly PersonIdentityResolverService $identityResolver,
    ) {}

    /**
     * Importa el archivo NOI del upload a fact_noi_movements.
     * Efectos: reinicia cachés e identidad por archivo, borra los movimientos previos
     * del upload, crea/actualiza employees, employee_external_identities,
     * employee_aliases y employee_match_rejections, e inserta movimientos (incluidas
     * filas ancla en $0). Devuelve incidencias de identidad para el panel.
     *
     * @param  ReportUpload  $upload  Archivo NOI subido.
     * @param  callable|null  $progress  Callback opcional con estadísticas parciales.
     * @return array<string,mixed> Estadísticas (leídas, insertadas, omitidas, con error), incidencias y log.
     *
     * @throws \RuntimeException Si falta el archivo, está vacío, no hay encabezados, faltan columnas mínimas o falla la inserción de una fila.
     */
    public function handle(ReportUpload $upload, ?callable $progress = null): array
    {
        // BUG CRÍTICO (encontrado 2026-07-23): $employeeCache es una propiedad de instancia
        // que sobrevivía entre llamadas a handle() para DISTINTOS uploads/periodos cuando el
        // mismo objeto NoiNominaImportService procesaba varios archivos NOI en una sola
        // ejecución (ReportAnalysisService/DatabaseUpdateService). "Clave del trabajador" en
        // NOI NO es un identificador estable entre periodos — se reutiliza para personas
        // distintas de un mes a otro. Con la cache viva, la clave 36 de un periodo anterior
        // (ej. "RICARDO ROCHA MORA") quedaba pegada a la clave 36 de un periodo posterior que
        // en realidad era otra persona (ej. "JOSE JUAN MUÑOZ VAZQUEZ" en Junio), porque el
        // cache devolvía el Employee equivocado sin volver a consultar/actualizar la BD.
        // Resultado: empleados fusionados o perdidos en el roster/IMSS derivado. Debe
        // reiniciarse en cada import — el cache solo es válido DENTRO de un mismo archivo.
        $this->employeeCache = [];
        $this->evidenceCache = [];
        $this->startIdentityScope($upload);

        if (! $upload->stored_path) {
            throw new \RuntimeException('El archivo no tiene stored_path.');
        }

        if (! ReportUploadStorage::exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico no existe en storage/public.');
        }

        @set_time_limit(0);

        $absolutePath = ReportUploadStorage::path($upload->stored_path);
        $sheets = Excel::toArray([], $absolutePath);

        if (empty($sheets) || empty($sheets[0])) {
            throw new \RuntimeException('El archivo NOI está vacío o no se pudo leer.');
        }

        $rows = $sheets[0];
        $sheetName = $this->firstSheetName($absolutePath);
        $headerRowIndex = $this->detectHeaderRowIndex($rows);
        $headerRow = $rows[$headerRowIndex] ?? null;

        if (! $headerRow || ! is_array($headerRow)) {
            throw new \RuntimeException('No se encontró una fila de encabezados válida en el archivo NOI.');
        }

        $headerMap = $this->buildHeaderMap($headerRow);

        $requiredColumns = ['concept', 'amount'];

        $missingRequired = collect($requiredColumns)
            ->filter(fn (string $field) => ! array_key_exists($field, $headerMap))
            ->values()
            ->all();

        if (! empty($missingRequired)) {
            throw new \RuntimeException(
                'El archivo NOI no contiene columnas mínimas requeridas: '
                .implode(', ', $missingRequired)
                .'. Encabezados detectados: '
                .implode(', ', array_values(array_filter($headerRow, fn ($value) => filled($value))))
            );
        }

        NoiMovement::query()
            ->where('report_upload_id', $upload->id)
            ->delete();

        $rowsRead = 0;
        $rowsInserted = 0;
        $rowsSkipped = 0;
        $rowsWithErrors = 0;

        // Clasificación de identidad (regla 2026-08-24): cada colaborador detectado en NOI
        // debe cruzarse contra Lendus/cartera antes de contar como operativo. Ver
        // PersonIdentityResolverService::evaluateNoiCandidateEvidence().
        $identityStats = [
            'matched' => 0, 'matched_non_portfolio_role' => 0,
            'needs_review' => 0, 'skipped_stale_zero' => 0,
        ];
        $identityIncidents = [];
        $flaggedNeedsReview = []; // normalized_name => true, evita incidencias duplicadas

        $currentEmployeeName = null;
        $currentEmployeeCode = null;

        // Bloques de empleado detectados en el archivo (clave => nombre) y claves que
        // efectivamente insertaron al menos una fila. Un empleado cuyos conceptos vienen
        // TODOS en $0 (ej. alta reciente sin percepciones fiscales aún ese periodo) nunca
        // dispara shouldInsertRow() y por lo tanto desaparece por completo del roster/IMSS
        // aunque su bloque "Clave del trabajador : N" exista en el archivo. Para el conteo
        // de colaboradores únicos (regla 2026-07-23: cada clave cuenta 1, sin importar si
        // tuvo movimientos con importe) se ancla con una fila sintética al final.
        $blockEmployees = [];
        $insertedCodes = [];

        foreach (array_slice($rows, $headerRowIndex + 1, null, true) as $rowIndex => $row) {
            if (! is_array($row) || $this->isEmptyRow($row)) {
                $rowsSkipped++;

                continue;
            }

            $rowsRead++;
            $sourceRowNumber = $rowIndex + 1;

            try {
                $mapped = $this->mapRow($row, $headerMap);

                if ($mapped['employee_code']) {
                    $currentEmployeeCode = $mapped['employee_code'];
                }

                if ($this->isValidEmployeeName($mapped['employee_name'])) {
                    $currentEmployeeName = $mapped['employee_name'];
                }

                if (
                    ! $this->isValidEmployeeName($mapped['employee_name']) &&
                    $currentEmployeeName &&
                    ($mapped['concept'] || $mapped['amount'] !== null)
                ) {
                    $mapped['employee_name'] = $currentEmployeeName;
                }

                if (
                    ! $mapped['employee_code'] &&
                    $currentEmployeeCode &&
                    ($mapped['concept'] || $mapped['amount'] !== null)
                ) {
                    $mapped['employee_code'] = $currentEmployeeCode;
                }

                if ($mapped['employee_code'] && $this->isValidEmployeeName($mapped['employee_name'])) {
                    $blockEmployees[$mapped['employee_code']] = $mapped['employee_name'];
                }

                if (! $this->shouldInsertRow($mapped)) {
                    $rowsSkipped++;

                    continue;
                }

                $employee = $this->resolveEmployee($mapped);

                if (! $employee) {
                    $rowsSkipped++;

                    continue;
                }

                if ($mapped['employee_code']) {
                    $insertedCodes[$mapped['employee_code']] = true;
                }

                // Clasificar identidad UNA vez por persona (no por fila) — el colaborador
                // trae dinero real este periodo, así que NUNCA se bloquea su inserción (eso
                // haría desaparecer nómina real de los reportes), pero si no hay ninguna
                // evidencia fuera de NOI se marca como incidencia NEEDS_REVIEW para que un
                // humano confirme identidad/sucursal en el panel de incidencias.
                $normEmpName = $this->normalizeName($mapped['employee_name']);
                if ($normEmpName !== '' && ! isset($flaggedNeedsReview[$normEmpName])) {
                    $evidence = $this->evaluateEvidence($normEmpName, $mapped['employee_name']);
                    if ($evidence['found']) {
                        $identityStats[$evidence['is_non_portfolio_role'] ? 'matched_non_portfolio_role' : 'matched']++;
                    } else {
                        $identityStats['needs_review']++;
                        $identityIncidents[] = [
                            'type' => 'noi_identidad.sin_respaldo',
                            'severity' => 'warning',
                            'message' => "\"{$mapped['employee_name']}\" tiene pago en NOI pero no se encontró en el directorio Lendus, cartera/colocación/cobranza ni asignaciones históricas. Verificar identidad y sucursal antes de confiar en el reporte.",
                            'context' => ['normalized_name' => $normEmpName, 'employee_id' => $employee->id],
                        ];
                    }
                    $flaggedNeedsReview[$normEmpName] = true;
                }

                $normalizedRow = json_encode($mapped['raw_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                NoiMovement::query()->create([
                    'period_id' => $upload->period_id,
                    'employee_id' => $employee->id,
                    'report_upload_id' => $upload->id,
                    'concept' => $mapped['concept'] ?: 'Sin concepto',
                    'concept_type' => $mapped['concept_type'] ?: $this->inferConceptType($mapped['concept']),
                    'amount' => $mapped['amount'],
                    'quantity' => 1,
                    'payroll_type' => $mapped['payroll_type'] ?: 'NOI',
                    'movement_date' => $mapped['movement_date'] ?? now()->toDateString(),
                    'raw_row_hash' => hash('sha256', $normalizedRow),
                    'source_row_key' => hash('sha256', implode('|', [
                        $upload->id,
                        $sheetName,
                        $sourceRowNumber,
                        $normalizedRow,
                    ])),
                    'raw_payload' => $mapped['raw_payload'],
                ]);

                $rowsInserted++;
            } catch (\Throwable $e) {
                $rowsWithErrors++;

                throw new \RuntimeException(
                    'Error al insertar fila NOI. Empleado: '
                    .($mapped['employee_name'] ?? 'N/D')
                    .' | Concepto: '
                    .($mapped['concept'] ?? 'N/D')
                    .' | Amount: '
                    .(($mapped['amount'] ?? null) === null ? 'NULL' : (string) $mapped['amount'])
                    .' | Detalle: '
                    .$e->getMessage()
                );
            }

            if ($progress && $rowsRead % 250 === 0) {
                $progress([
                    'rows_read' => $rowsRead,
                    'rows_inserted' => $rowsInserted,
                    'rows_skipped' => $rowsSkipped,
                    'rows_with_errors' => $rowsWithErrors,
                    'log' => "Procesando NOI... {$rowsRead} filas leídas.",
                ]);
            }
        }

        // ── Anclar empleados cuyo bloque existe pero TODOS sus conceptos vinieron en $0 ──
        // Sin esta fila sintética, esos colaboradores nunca generan un Employee/NoiMovement y
        // desaparecen del roster/IMSS derivado aunque su "Clave del trabajador" sí esté en el
        // archivo (regla 2026-07-23: el conteo de colaboradores no depende de tener importe).
        //
        // REGLA 2026-08-24: esta ancla YA NO es incondicional. NOI trae bajas/empleados
        // obsoletos con $0 que ya no existen en ninguna fuente Lendus vigente — anclarlos
        // los resucita como "colaborador operativo" y contamina roster/selector/reportes
        // (ver PersonIdentityResolverService::evaluateNoiCandidateEvidence()). Un colaborador
        // legítimo con $0 (ej. alta reciente sin percepciones fiscales, gerente sin cartera)
        // SIGUE anclándose normalmente porque tiene evidencia fuera de NOI. Uno sin ninguna
        // evidencia se descarta silenciosamente del lado operativo — solo cuenta en las
        // estadísticas de importación como "ignorado".
        $rowsAnchored = 0;
        foreach ($blockEmployees as $code => $name) {
            if (isset($insertedCodes[$code])) {
                continue;
            }

            $normBlockName = $this->normalizeName($name);
            $evidence = $normBlockName !== '' ? $this->evaluateEvidence($normBlockName, $name) : ['found' => false, 'is_non_portfolio_role' => false, 'detail' => 'Nombre vacío'];

            if (! $evidence['found']) {
                $identityStats['skipped_stale_zero']++;
                $identityIncidents[] = [
                    'type' => 'noi_identidad.stale_zero_ignorado',
                    'severity' => 'info',
                    'message' => "Ignorado: \"{$name}\" aparece en NOI con \$0 y sin actividad en el periodo, y no se encontró en el directorio Lendus, cartera/colocación/cobranza ni asignaciones históricas. No se creó como colaborador operativo.",
                    'context' => ['normalized_name' => $normBlockName, 'employee_code' => $code],
                ];

                continue;
            }

            $identityStats[$evidence['is_non_portfolio_role'] ? 'matched_non_portfolio_role' : 'matched']++;

            $mapped = [
                'employee_code' => $code,
                'employee_name' => $name,
                'raw_payload' => [null, $name, 'SIN PERCEPCIONES CON IMPORTE EN EL PERIODO', 0],
            ];

            $employee = $this->resolveEmployee($mapped);
            if (! $employee) {
                continue;
            }

            $normalizedRow = json_encode($mapped['raw_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            NoiMovement::query()->create([
                'period_id' => $upload->period_id,
                'employee_id' => $employee->id,
                'report_upload_id' => $upload->id,
                'concept' => 'SIN PERCEPCIONES CON IMPORTE EN EL PERIODO',
                'concept_type' => 'headcount_only',
                'amount' => 0,
                'quantity' => 1,
                'payroll_type' => 'NOI',
                'movement_date' => now()->toDateString(),
                'raw_row_hash' => hash('sha256', $normalizedRow),
                'source_row_key' => hash('sha256', implode('|', [$upload->id, $sheetName, 'anchor', $code, $normalizedRow])),
                'raw_payload' => $mapped['raw_payload'],
            ]);

            $rowsInserted++;
            $rowsAnchored++;
        }

        return [
            'rows_read' => $rowsRead,
            'rows_inserted' => $rowsInserted,
            'rows_anchored' => $rowsAnchored,
            'rows_skipped' => $rowsSkipped,
            'rows_with_errors' => $rowsWithErrors,
            'identity_stats' => $identityStats,
            'incidents' => array_merge($identityIncidents, $this->identityIncidents),
            'log' => sprintf(
                'Importación NOI completada. Leídas: %d, insertadas: %d, omitidas: %d, con error: %d. '
                .'Identidad — vinculados: %d, roles sin cartera: %d, requieren revisión: %d, ignorados ($0 sin respaldo): %d.',
                $rowsRead,
                $rowsInserted,
                $rowsSkipped,
                $rowsWithErrors,
                $identityStats['matched'],
                $identityStats['matched_non_portfolio_role'],
                $identityStats['needs_review'],
                $identityStats['skipped_stale_zero'],
            ),
        ];
    }

    /**
     * Envoltura con caché de PersonIdentityResolverService::evaluateNoiCandidateEvidence(),
     * válida solo durante una corrida de handle(): un archivo NOI repite a la misma
     * persona en muchas filas (una por concepto), así que las consultas a BD solo
     * deben hacerse una vez por nombre.
     *
     * @param  string  $normalizedName  Nombre normalizado del trabajador.
     * @param  string  $displayName  Nombre tal como viene en NOI.
     * @return array Resultado de la evaluación de evidencia.
     */
    private function evaluateEvidence(string $normalizedName, string $displayName): array
    {
        if (! array_key_exists($normalizedName, $this->evidenceCache)) {
            $this->evidenceCache[$normalizedName] = $this->identityResolver->evaluateNoiCandidateEvidence($displayName);
        }

        return $this->evidenceCache[$normalizedName];
    }

    /**
     * Escaneo ligero para "Cargar registros": lee solo las columnas A y B por
     * bloques, detecta cada trabajador (la clave del bloque se arrastra al nombre
     * de las filas siguientes; en formato plano el nombre va en A) y resuelve su
     * identidad con la MISMA regla que handle() (antes un upsert sin índice único
     * duplicaba la plantilla en cada carga). No inserta movimientos.
     * Efectos: crea/actualiza employees e identidades NOI.
     *
     * @param  ReportUpload  $upload  Upload NOI del periodo.
     * @return array{employees_detected:int,rows_read:int,rows_skipped:int,incidents:array} Resumen del escaneo.
     *
     * @throws \RuntimeException Si falta el archivo, no tiene hojas o no tiene filas útiles.
     */
    public function scanForDatabaseUpdate(ReportUpload $upload): array
    {
        if (! $upload->stored_path) {
            throw new \RuntimeException('El archivo NOI no tiene ruta de almacenamiento.');
        }

        if (! ReportUploadStorage::exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico de NOI no existe en storage/public.');
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
            throw new \RuntimeException('No se encontraron hojas en el archivo NOI.');
        }

        $sheetName = $sheetsInfo[0]['worksheetName'];
        $highestRow = (int) ($sheetsInfo[0]['totalRows'] ?? 0);

        if ($highestRow <= 0) {
            throw new \RuntimeException('El archivo NOI no contiene filas útiles.');
        }

        $employeesByKey = [];
        $currentCode = null;
        $rowsRead = 0;
        $rowsSkipped = 0;
        $incidents = [];

        /*
         * Actualizar BD debe ser ligero:
         * para NOI solo detectamos empleados desde columnas A y B.
         * No leemos conceptos, importes, acumulados ni movimientos.
         */
        $chunkSize = 300;

        for ($start = 1; $start <= $highestRow; $start += $chunkSize) {
            $end = min($start + $chunkSize - 1, $highestRow);

            $chunkRows = $this->readNoiEmployeeColumnsForDatabaseUpdate(
                path: $absolutePath,
                sheetName: $sheetName,
                startRow: $start,
                endRow: $end,
            );

            foreach ($chunkRows as $row) {
                if (! is_array($row) || $this->isEmptyRow($row)) {
                    $rowsSkipped++;

                    continue;
                }

                $rowsRead++;

                $col0 = $this->cleanString($row[0] ?? null);
                $col1 = $this->cleanString($row[1] ?? null);

                // El bloque NOI trae "Clave del trabajador : N" en una fila y el
                // nombre en las siguientes: la clave vigente se arrastra al nombre.
                $blockCode = $this->extractEmployeeCode($col1);
                if ($blockCode !== null) {
                    $currentCode = $blockCode;
                    // Formato plano (clave y nombre en la misma fila): el nombre va en A.
                    if (! $this->isValidEmployeeName($col0)) {
                        continue;
                    }
                }

                $employeeName = null;

                if ($blockCode === null && $this->isValidEmployeeName($col1)) {
                    $employeeName = $col1;
                } elseif ($this->isValidEmployeeName($col0)) {
                    $employeeName = $col0;
                }

                if (! $this->isValidEmployeeName($employeeName)) {
                    $rowsSkipped++;

                    continue;
                }

                $normalized = $this->normalizeName($employeeName);

                if ($normalized === '') {
                    $rowsSkipped++;

                    continue;
                }

                $key = $currentCode ? "code:{$currentCode}" : "name:{$normalized}";

                if (isset($employeesByKey[$key])) {
                    continue;
                }

                $employeesByKey[$key] = ['employee_code' => $currentCode, 'full_name' => $employeeName, 'normalized_name' => $normalized];
            }

            unset($chunkRows);
            gc_collect_cycles();
        }

        // BUG CORREGIDO (cierre 2026-10): antes se hacía upsert(['employee_code' |
        // 'normalized_name', 'source_system']) — `employees` no tiene índice ÚNICO en
        // esas columnas, así que en MySQL el upsert era un INSERT simple y cada
        // "Actualizar BD" duplicaba a toda la plantilla (verificado en un clon:
        // 157 → 284 registros con una sola carga). Ahora se usa la MISMA resolución
        // de identidad que la importación de movimientos (resolveNoiIdentity).
        $this->employeeCache = [];
        $this->startIdentityScope($upload);
        $employeesDetected = 0;
        foreach ($employeesByKey as $employee) {
            $this->resolveEmployee(['employee_code' => $employee['employee_code'], 'employee_name' => $employee['full_name']]);
            $employeesDetected++;
        }
        $incidents = array_merge($incidents, $this->identityIncidents);

        return [
            'employees_detected' => $employeesDetected,
            'rows_read' => $rowsRead,
            'rows_skipped' => $rowsSkipped,
            'incidents' => $incidents,
        ];
    }

    /**
     * @param  string  $absolutePath  Ruta absoluta del archivo.
     * @return string Nombre de la primera hoja ('Sheet1' si no se puede leer).
     */
    private function firstSheetName(string $absolutePath): string
    {
        try {
            $reader = IOFactory::createReaderForFile($absolutePath);
            $sheetsInfo = $reader->listWorksheetInfo($absolutePath);

            return $sheetsInfo[0]['worksheetName'] ?? 'Sheet1';
        } catch (\Throwable) {
            return 'Sheet1';
        }
    }

    /**
     * Devuelve, de las primeras 20 filas, la de mayor coincidencia con encabezados
     * conocidos de NOI (nombre del trabajador, concepto, acumulado, importe, monto, total).
     *
     * @param  array  $rows  Filas de la hoja.
     * @return int Índice del renglón de encabezados.
     */
    private function detectHeaderRowIndex(array $rows): int
    {
        $bestIndex = 0;
        $bestScore = -1;

        foreach (array_slice($rows, 0, 20, true) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized = array_map(
                fn ($value) => $this->normalizeHeader((string) $value),
                $row
            );

            $score = 0;

            foreach ($normalized as $value) {
                if (in_array($value, [
                    'nombre_del_trabajador',
                    'concepto',
                    'acumulado',
                    'importe',
                    'monto',
                    'total',
                ], true)) {
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
     * Mapea campos lógicos (nombre del trabajador, concepto, tipo de concepto,
     * importe, cantidad, tipo de nómina, fecha) a columnas por alias de encabezado
     * normalizado, incluidos los formatos NOMBRE_TRAB / DESCRIP_CPTO / "Monto del periodo".
     *
     * @param  array  $headerRow  Celdas del encabezado.
     * @return array<string,int> Campo => índice de columna.
     */
    private function buildHeaderMap(array $headerRow): array
    {
        $normalizedHeaders = [];

        foreach ($headerRow as $index => $header) {
            $normalizedHeaders[(int) $index] = $this->normalizeHeader((string) $header);
        }

        $aliases = [
            // Nombre del colaborador POR ENCABEZADO — formato NOI detectado 2026-08-25
            // (columnas NOMBRE_TRAB / DESCRIP_CPTO / "Monto del periodo", que rompían la
            // importación con "columnas mínimas requeridas: concept, amount"). Antes de
            // este alias, el nombre solo se leía por POSICIÓN (columnas A/B, ver mapRow())
            // — se conserva ese fallback posicional para no romper el formato anterior,
            // pero cuando el archivo declara un encabezado real de nombre, se usa ese.
            'employee_name' => [
                'nombre_trab',
                'nombre_trabajador',
                'nombre_del_trabajador',
            ],
            'concept' => [
                'concept',
                'concepto',
                'descripcion_concepto',
                'descrip_cpto',
                'descripcion_cpto',
                'movimiento',
                'descripcion',
            ],
            'concept_type' => [
                'concept_type',
                'tipo_concepto',
                'tipo',
                'naturaleza',
                'tipo_movimiento',
            ],
            'amount' => [
                'amount',
                'importe',
                'monto',
                'monto_del_periodo',
                'neto',
                'total',
                'importe_neto',
                'pago_neto',
                'valor',
                'acumulado',
            ],
            'quantity' => [
                'quantity',
                'cantidad',
                'unidades',
                'dias',
                'horas',
            ],
            'payroll_type' => [
                'payroll_type',
                'tipo_nomina',
                'nomina',
                'nomina_tipo',
                'periodo_nomina',
            ],
            'movement_date' => [
                'movement_date',
                'fecha',
                'fecha_movimiento',
                'fecha_aplicacion',
                'fecha_nomina',
                'periodo',
            ],
        ];

        $map = [];

        foreach ($aliases as $field => $possibleHeaders) {
            foreach ($normalizedHeaders as $index => $normalizedHeader) {
                if (in_array($normalizedHeader, $possibleHeaders, true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Extrae los datos de la fila: clave del trabajador (columna B), nombre
     * (prefiere el encabezado; si no, columnas B o A), concepto, tipo, importe,
     * cantidad, tipo de nómina y fecha. Las deducciones D### negativas se guardan
     * en valor absoluto (el SnapshotBuilder las resta; así no hay doble negativo).
     *
     * @param  array  $row  Fila de datos.
     * @param  array  $headerMap  Mapa campo => índice.
     * @return array<string,mixed> Fila normalizada.
     */
    private function mapRow(array $row, array $headerMap): array
    {
        $col0 = $this->cleanString($row[0] ?? null);
        $col1 = $this->cleanString($row[1] ?? null);

        $employeeCode = $this->extractEmployeeCode($col1);

        // Preferir el nombre resuelto por ENCABEZADO (alias, ej. NOMBRE_TRAB) — nunca
        // depender solo de la posición de columna. Si el archivo no declara ese
        // encabezado (formato anterior), cae al fallback posicional original (A/B).
        $headerEmployeeName = $this->cleanString($this->valueFromRow($row, $headerMap, 'employee_name'));

        $employeeName = null;

        if ($this->isValidEmployeeName($headerEmployeeName)) {
            $employeeName = $headerEmployeeName;
        } elseif ($this->isValidEmployeeName($col1)) {
            $employeeName = $col1;
        } elseif ($this->isValidEmployeeName($col0)) {
            $employeeName = $col0;
        }

        $concept = $this->cleanString($this->valueFromRow($row, $headerMap, 'concept'));
        $conceptType = $this->cleanString($this->valueFromRow($row, $headerMap, 'concept_type'));
        $amount = $this->toDecimal($this->valueFromRow($row, $headerMap, 'amount'));
        $quantity = $this->toDecimal($this->valueFromRow($row, $headerMap, 'quantity'));

        // Las deducciones (D###) se guardan como valores absolutos positivos.
        // El SnapshotBuilder las resta; si vienen negativas, abs() evita el doble negativo.
        if ($amount !== null && $concept && preg_match('/^D\d{3}\b/i', trim($concept)) && $amount < 0) {
            $amount = abs($amount);
        }
        $payrollType = $this->cleanString($this->valueFromRow($row, $headerMap, 'payroll_type'));
        $movementDate = $this->toDateValue($this->valueFromRow($row, $headerMap, 'movement_date'));

        return [
            'employee_code' => $employeeCode,
            'employee_name' => $employeeName,
            'concept' => $concept,
            'concept_type' => $conceptType,
            'amount' => $amount,
            'quantity' => $quantity,
            'payroll_type' => $payrollType,
            'movement_date' => $movementDate,
            'raw_payload' => $row,
        ];
    }

    /**
     * Indica si la fila es un movimiento real: nombre válido, concepto, importe
     * distinto de cero y que no sea un renglón de acumulado/total/subtotal.
     *
     * @param  array  $mapped  Fila de mapRow().
     * @return bool true si se inserta.
     */
    private function shouldInsertRow(array $mapped): bool
    {
        if (! $this->isValidEmployeeName($mapped['employee_name'])) {
            return false;
        }

        if (! $mapped['concept']) {
            return false;
        }

        if ($mapped['amount'] === null) {
            return false;
        }

        if ((float) $mapped['amount'] == 0.0) {
            return false;
        }

        $concept = $this->normalizeText($mapped['concept']);

        if (in_array($concept, ['acumulado', 'total', 'totales', 'subtotal', 'sub_total'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Efecto secundario: puede crear o actualizar el Employee. Usa una caché por
     * nombre+clave que solo vive dentro del archivo en curso.
     *
     * Identidad ÚNICA y estable del empleado: nombre_normalizado + source_system='noi'.
     * "Clave del trabajador" (employee_code) NUNCA se usa como llave de búsqueda/upsert
     * — confirmado 2026-07-23 que el mismo número identifica personas distintas entre
     * archivos/periodos (clave 36: Ricardo Rocha Mora vs José Juan Muñoz Vázquez; clave 7:
     * Diego Martínez Romero vs Ernesto López López). Usarlo como llave sobrescribía
     * retroactivamente el nombre de un Employee ya vinculado a movimientos de un periodo
     * anterior cada vez que un periodo posterior reutilizaba ese código para otra persona.
     * employee_code se conserva solo como dato informativo (columna "Clave" en
     * auditorías/Excel) del ÚLTIMO archivo que lo reportó — nunca como identidad.
     *
     * @param  array  $mapped  Debe traer employee_code y employee_name.
     * @return Employee|null Empleado resuelto o null si el nombre no es válido.
     */
    private function resolveEmployee(array $mapped): ?Employee
    {
        $employeeCode = $mapped['employee_code'] ?: null;
        $fullName = $mapped['employee_name'];

        if (! $this->isValidEmployeeName($fullName)) {
            return null;
        }

        $normalizedName = $this->normalizeName($fullName);

        if ($normalizedName === '') {
            return null;
        }

        $cacheKey = "name:{$normalizedName}|code:".($employeeCode ?? '-');

        if (array_key_exists($cacheKey, $this->employeeCache)) {
            return $this->employeeCache[$cacheKey];
        }

        return $this->employeeCache[$cacheKey] = $this->resolveNoiIdentity($fullName, $normalizedName, $employeeCode);
    }

    /**
     * Efectos: crea/actualiza employees, vincula la clave en
     * employee_external_identities, registra alias y rechazos de fusión, y agrega
     * incidencias de identidad del archivo.
     *
     * Identidad NOI (cierre 2026-10) — una sola regla para handle() y para
     * scanForDatabaseUpdate():
     *
     *   1. La clave del trabajador es estable DENTRO de un mismo sistema (NOI
     *      Nómina o NOI Nómina Fiscal; se verificó Abril–Junio 2026 sin
     *      reutilizaciones), pero NO entre sistemas (la clave 36 normal y la 36
     *      fiscal son personas distintas). Se guarda como identidad explícita en
     *      employee_external_identities (system_code = fuente, external_type =
     *      'noi_clave') y se usa para reconocer a la misma persona aunque cambie
     *      la captura del nombre (si el nombre es muy distinto, la clave se
     *      considera reutilizada y NO se usa).
     *   2. Sin clave vinculada: mismo nombre normalizado (registro más antiguo).
     *   3. Mismo nombre con OTRA clave en el MISMO archivo = homónimo: se crea
     *      (o reutiliza por clave) un registro aparte y se registra el rechazo de
     *      fusión (employee_match_rejections) para que ningún consumidor los
     *      junte por nombre. Queda una incidencia para revisión.
     *
     * @param  string  $fullName  Nombre tal como viene en NOI.
     * @param  string  $normalizedName  Nombre normalizado.
     * @param  string|null  $code  Clave del trabajador (null si el formato no la trae).
     * @return Employee Empleado resuelto o creado.
     */
    private function resolveNoiIdentity(string $fullName, string $normalizedName, ?string $code): Employee
    {
        [$firstName, $paternalLastName, $maternalLastName] = $this->splitName($fullName);
        $attributes = [
            'full_name' => $fullName,
            'first_name' => $firstName,
            'paternal_last_name' => $paternalLastName,
            'maternal_last_name' => $maternalLastName,
            'is_active' => true,
        ];

        $seenInFile = $this->fileIdentity[$normalizedName] ?? [];

        if ($code !== null && $seenInFile !== [] && ! isset($seenInFile[$code])) {
            $employee = $this->employeeByNoiCode($code, $normalizedName, excludeIds: array_values($seenInFile))
                ?? Employee::query()->create($attributes + [
                    'normalized_name' => $normalizedName,
                    'source_system' => 'noi',
                    'employee_code' => $code,
                ]);
            foreach ($seenInFile as $otherCode => $otherId) {
                $this->rejectMerge((int) $otherId, (int) $employee->id, "Homónimos en la misma nómina NOI ({$this->identityFile}): claves {$otherCode} y {$code}.");
            }
            $this->identityIncidents[] = [
                'type' => 'noi_identidad.homonimo',
                'severity' => 'warning',
                'message' => "\"{$fullName}\" aparece con dos claves distintas en el mismo archivo NOI (".implode(', ', array_keys($seenInFile))." y {$code}). Se registraron como personas distintas; verifica la identidad antes de conciliar.",
                'context' => ['normalized_name' => $normalizedName, 'employee_code' => $code, 'employee_id' => $employee->id],
            ];
        } else {
            $employee = $code !== null ? $this->employeeByNoiCode($code, $normalizedName) : null;

            if ($employee === null) {
                // Mismo nombre en CUALQUIER fuente (preferente NOI): el catálogo de
                // colaboradores es uno solo. Antes solo se buscaba source_system='noi'
                // y una persona dada de alta primero por Lendus cobranza quedaba
                // duplicada en cada importación (8 casos en Abril–Junio 2026).
                $employee = Employee::query()
                    ->where('normalized_name', $normalizedName)
                    ->orderByRaw("CASE WHEN source_system = 'noi' THEN 0 ELSE 1 END")
                    ->orderBy('id')
                    ->first();

                if ($employee === null) {
                    $employee = Employee::query()->create($attributes + [
                        'normalized_name' => $normalizedName,
                        'source_system' => 'noi',
                        'employee_code' => $code,
                    ]);
                } else {
                    $employee->fill($attributes + ['employee_code' => $code ?? $employee->employee_code])->save();
                }
            } elseif ($employee->normalized_name !== $normalizedName) {
                // Misma clave, nombre capturado distinto: se conserva el registro y el
                // nombre nuevo queda como alias conciliado por clave (nunca se renombra).
                $this->registerAlias($employee, $fullName, $normalizedName);
                $employee->forceFill(['employee_code' => $code, 'is_active' => true])->save();
            } else {
                $employee->fill($attributes + ['employee_code' => $code])->save();
            }
        }

        if ($code !== null) {
            $this->fileIdentity[$normalizedName][$code] = (int) $employee->id;
            $this->linkNoiCode($employee, $code);
        }

        return $employee;
    }

    /**
     * Employee vinculado a la clave NOI en este sistema, si el nombre es compatible
     * (igual o similitud ≥ 90%). Si la clave está vinculada a alguien con nombre muy
     * distinto, se considera reutilizada: no se usa y se registra una incidencia.
     *
     * @param  string  $code  Clave del trabajador.
     * @param  string  $normalizedName  Nombre normalizado del archivo actual.
     * @param  array  $excludeIds  Ids que no se deben devolver (homónimos ya vistos en el archivo).
     * @return Employee|null Empleado vinculado o null.
     */
    private function employeeByNoiCode(string $code, string $normalizedName, array $excludeIds = []): ?Employee
    {
        if (! $this->identitiesAvailable()) {
            return null;
        }

        $employeeId = DB::table('employee_external_identities')
            ->where('system_code', $this->identitySystem)
            ->where('external_type', self::NOI_IDENTITY_TYPE)
            ->where('external_id', $code)
            ->where('active', true)
            ->value('employee_id');
        if ($employeeId === null || in_array((int) $employeeId, array_map('intval', $excludeIds), true)) {
            return null;
        }

        $employee = Employee::query()->find($employeeId);
        if ($employee === null) {
            return null;
        }
        if ($employee->normalized_name === $normalizedName
            || $this->identityResolver->isLikelySamePerson($employee->normalized_name, $normalizedName, 90.0)) {
            return $employee;
        }

        $this->identityIncidents[] = [
            'type' => 'noi_identidad.clave_reutilizada',
            'severity' => 'warning',
            'message' => "La clave NOI {$code} ({$this->identitySystem}) estaba vinculada a \"{$employee->full_name}\" y ahora aparece con \"{$normalizedName}\". No se usó la clave para identificarlo: verifica si es la misma persona.",
            'context' => ['normalized_name' => $normalizedName, 'employee_code' => $code, 'linked_employee_id' => $employee->id],
        ];

        return null;
    }

    /**
     * Registra la clave NOI como identidad externa del empleado en el sistema actual.
     * Nunca reasigna en silencio una clave que ya está vinculada.
     *
     * @param  Employee  $employee  Empleado al que se vincula.
     * @param  string  $code  Clave del trabajador.
     */
    private function linkNoiCode(Employee $employee, string $code): void
    {
        if (! $this->identitiesAvailable()) {
            return;
        }

        $exists = DB::table('employee_external_identities')
            ->where('system_code', $this->identitySystem)
            ->where('external_type', self::NOI_IDENTITY_TYPE)
            ->where('external_id', $code)
            ->where('active', true)
            ->exists();
        if ($exists) {
            return; // nunca se reasigna en silencio una clave ya vinculada
        }

        DB::table('employee_external_identities')->insert([
            'employee_id' => $employee->id,
            'system_code' => $this->identitySystem,
            'external_id' => $code,
            'external_type' => self::NOI_IDENTITY_TYPE,
            'verified_at' => now(),
            'verification_method' => "Importación NOI: clave del trabajador en {$this->identityFile}",
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Registra en employee_match_rejections que dos empleados NO deben fusionarse
     * (p. ej. homónimos con claves distintas en la misma nómina). Ignora duplicados.
     *
     * @param  int  $a  Id del primer empleado.
     * @param  int  $b  Id del segundo empleado.
     * @param  string  $reason  Motivo (se trunca a 500 caracteres).
     */
    private function rejectMerge(int $a, int $b, string $reason): void
    {
        if ($a === $b || ! Schema::hasTable('employee_match_rejections')) {
            return;
        }

        DB::table('employee_match_rejections')->insertOrIgnore([
            'employee_id_a' => min($a, $b),
            'employee_id_b' => max($a, $b),
            'period_id' => $this->identityPeriodId,
            'pair_key' => EmployeeMatchRejection::pairKey($a, $b),
            'reason' => mb_substr($reason, 0, 500),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Guarda el nombre como alias del empleado (source 'noi_clave', confianza 0.95)
     * si la tabla existe y el alias aún no está registrado.
     *
     * @param  Employee  $employee  Empleado.
     * @param  string  $fullName  Nombre original.
     * @param  string  $normalizedName  Nombre normalizado.
     */
    private function registerAlias(Employee $employee, string $fullName, string $normalizedName): void
    {
        if (! Schema::hasTable('employee_aliases')
            || DB::table('employee_aliases')->where('employee_id', $employee->id)->where('normalized_alias', $normalizedName)->exists()) {
            return;
        }

        DB::table('employee_aliases')->insert([
            'employee_id' => $employee->id,
            'alias_name' => $fullName,
            'normalized_alias' => $normalizedName,
            'source' => 'noi_clave',
            'confidence' => 0.95,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Indica (con caché) si existe la tabla employee_external_identities.
     *
     * @return bool true si la tabla existe.
     */
    private function identitiesAvailable(): bool
    {
        return $this->identitiesTable ??= Schema::hasTable('employee_external_identities');
    }

    /**
     * Define el sistema de identidad (noi_nomina o noi_nomina_fiscal), el nombre de
     * archivo para mensajes y el periodo del upload.
     *
     * Reinicia el estado de identidad: válido solo dentro de UN archivo.
     *
     * @param  ReportUpload  $upload  Upload en proceso.
     */
    private function startIdentityScope(ReportUpload $upload): void
    {
        $this->fileIdentity = [];
        $this->identityIncidents = [];
        $this->identitySystem = $upload->dataSource?->code === 'noi_nomina_fiscal' ? 'noi_nomina_fiscal' : 'noi_nomina';
        $this->identityFile = (string) ($upload->original_name ?: "carga {$upload->id}");
        $this->identityPeriodId = $upload->period_id ? (int) $upload->period_id : null;
    }

    /**
     * Extrae el número de "Clave del trabajador : N".
     *
     * @param  string|null  $value  Texto de la celda.
     * @return string|null Clave o null si no está presente.
     */
    private function extractEmployeeCode(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (preg_match('/clave\s+del\s+trabajador\s*:\s*(\d+)/i', $value, $matches)) {
            return $matches[1];
        }

        return null;
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
     * Normaliza un nombre de persona (ASCII, minúsculas, sin signos, espacios simples).
     * Es la llave de identidad por nombre.
     *
     * @param  string  $value  Nombre original.
     * @return string Nombre normalizado.
     */
    private function normalizeName(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();
    }

    /**
     * Normaliza texto libre (mismo algoritmo que normalizeName()).
     *
     * @param  string  $value  Texto original.
     * @return string Texto normalizado.
     */
    private function normalizeText(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();
    }

    /**
     * Divide el nombre completo en nombre, apellido paterno y materno tomando las
     * tres primeras palabras (aproximación; NOI no separa los campos).
     *
     * @param  string  $fullName  Nombre completo.
     * @return array{0:?string,1:?string,2:?string} [nombre, paterno, materno].
     */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        $parts = array_values(array_filter($parts, fn ($part) => $part !== ''));

        return [
            $parts[0] ?? null,
            $parts[1] ?? null,
            $parts[2] ?? null,
        ];
    }

    /**
     * Infiere el tipo de concepto cuando falta la columna de tipo: P### = percepción,
     * D### = deducción; si no hay código, usa palabras clave (sueldo, bono, prima...
     * = percepción; descuento, préstamo, IMSS, ISR... = deducción).
     *
     * @param  string|null  $concept  Concepto NOI.
     * @return string|null 'percepcion', 'deduccion' o null si no se puede inferir.
     */
    private function inferConceptType(?string $concept): ?string
    {
        if (! $concept) {
            return null;
        }

        $normalized = strtoupper(trim($concept));

        // P### NOMBRE → percepción
        if (preg_match('/^P\d{3}\b/', $normalized)) {
            return 'percepcion';
        }

        // D### NOMBRE → deducción
        if (preg_match('/^D\d{3}\b/', $normalized)) {
            return 'deduccion';
        }

        // Respaldo por palabras clave
        $lower = strtolower($normalized);
        if (str_contains($lower, 'sueldo') || str_contains($lower, 'salario') ||
            str_contains($lower, 'vacacion') || str_contains($lower, 'bono') ||
            str_contains($lower, 'prima') || str_contains($lower, 'compensacion') ||
            str_contains($lower, 'percepcion')) {
            return 'percepcion';
        }

        if (str_contains($lower, 'descuento') || str_contains($lower, 'prestamo') ||
            str_contains($lower, 'deduccion') || str_contains($lower, 'imss') ||
            str_contains($lower, 'isr') || str_contains($lower, 'impuesto')) {
            return 'deduccion';
        }

        return null;
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

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * Indica si el texto puede ser un nombre de trabajador: descarta vacíos, guiones,
     * la etiqueta "Clave del trabajador" y encabezados como "Nombre"/"Empleado".
     *
     * @param  string|null  $value  Texto candidato.
     * @return bool true si parece nombre real.
     */
    private function isValidEmployeeName(?string $value): bool
    {
        if (! $value) {
            return false;
        }

        $value = trim($value);

        if ($value === '' || $value === '-' || $value === '--') {
            return false;
        }

        if (stripos($value, 'clave del trabajador') !== false) {
            return false;
        }

        $normalized = $this->normalizeName($value);

        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, [
            'nombre',
            'trabajador',
            'empleado',
            'nombre del trabajador',
            'nombre trabajador',
            'nombre empleado',
        ], true)) {
            return false;
        }

        return true;
    }

    /**
     * Convierte un importe a float SIN redondear. Con coma y punto, la coma es
     * separador de miles; si solo hay coma, se toma como decimal.
     *
     * @param  mixed  $value  Valor de celda.
     * @return float|null Importe o null si no es numérico.
     */
    private function toDecimal(mixed $value): ?float
    {
        // Regla única de montos en texto (incluye «12,500» = 12500): ver App\Support\MoneyText.
        return MoneyText::toDecimal($value);
    }

    /**
     * Convierte una fecha (serie de Excel o texto) a 'Y-m-d'.
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

    /**
     * Lee únicamente las columnas A y B del rango de filas indicado (filtro de
     * lectura) para detectar trabajadores con poco consumo de memoria.
     *
     * @param  string  $path  Ruta absoluta del archivo.
     * @param  string  $sheetName  Hoja a leer.
     * @param  int  $startRow  Primera fila (1-based).
     * @param  int  $endRow  Última fila (inclusive).
     * @return array<int,array> Filas con las columnas A y B.
     */
    private function readNoiEmployeeColumnsForDatabaseUpdate(
        string $path,
        string $sheetName,
        int $startRow,
        int $endRow,
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
             * Filtro de lectura: solo celdas de las columnas A y B dentro del rango de filas.
             *
             * @param  string  $columnAddress  Columna de la celda.
             * @param  int  $row  Fila de la celda.
             * @param  string  $worksheetName  Hoja (no se usa).
             * @return bool true si la celda se debe leer.
             */
            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                if ($row < $this->startRow || $row > $this->endRow) {
                    return false;
                }

                return in_array($columnAddress, ['A', 'B'], true);
            }
        });

        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getActiveSheet();

        $rows = $sheet->rangeToArray(
            'A'.$startRow.':B'.$endRow,
            null,
            true,
            false,
            false,
        );

        $spreadsheet->disconnectWorksheets();
        unset($sheet, $spreadsheet);

        return $rows;
    }
}
