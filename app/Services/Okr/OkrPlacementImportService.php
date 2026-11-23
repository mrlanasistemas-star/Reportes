<?php

namespace App\Services\Okr;

use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\OkrObjective;
use App\Models\OkrPlacementMovement;
use App\Models\OkrPlacementUpload;
use App\Models\User;
use App\Services\Catalog\BranchRouteCatalog;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Parte 6/7 del cierre (04-oct-2026) — carga semanal de colocación por
 * Objective. Un archivo = UNA semana de UN Objective:
 *   - scope_type=employee: todas las filas atribuibles a ESE gestor se suman
 *     (6.2) — filas de otro nombre se ignoran (log, nunca se suman al total).
 *   - scope_type=branch: el archivo trae TODOS los gestores de la sucursal
 *     — el total de la sucursal es la suma de las filas, y cada fila queda
 *     también disponible por employee_id para el desglose "por gestor"
 *     (6.4) — UNA sola fuente canónica, nunca archivo de sucursal + archivos
 *     individuales sumados aparte (6.5).
 *
 * Formato esperado (encabezados flexibles, ver buildColumnMap()): columna de
 * colaborador/gestor/promotor + columna de monto/colocación + columna de
 * fecha opcional.
 */
class OkrPlacementImportService
{
    /** Encabezados reconocidos (minúsculas). contract/branch/movement_type son opcionales. */
    private const ALIASES = [
        'employee' => ['colaborador', 'gestor', 'promotor', 'empleado', 'nombre', 'asesor', 'nombre gestor', 'gestor asignado'],
        'amount' => ['colocacion', 'colocación', 'monto', 'importe', 'monto colocado', 'amount', 'monto otorgado', 'importe otorgado', 'monto prestado'],
        'date' => ['fecha', 'fecha operacion', 'fecha de operación', 'fecha operación', 'operation_date', 'fecha otorgamiento', 'fecha de otorgamiento', 'fecha de entrega'],
        'contract' => ['contrato', 'no. contrato', 'no contrato', 'numero de contrato', 'número de contrato', 'folio', 'credito', 'crédito', 'id credito', 'prestamo', 'préstamo'],
        // Oficina = Sucursal (las 13 oficiales). Ruta es distinta: pertenece a una
        // sucursal según las equivalencias verificadas de Reportería.
        'branch' => ['sucursal', 'oficina'],
        'route' => ['ruta', 'nombre ruta', 'ruta lendus'],
        'movement_type' => ['tipo', 'tipo de movimiento', 'tipo movimiento', 'movimiento', 'estatus', 'status', 'estado'],
        // 16-oct-2026 — número de empleado / código de promotor Lendus (p. ej.
        // ATLX12513): si viene, identifica al gestor ANTES que el nombre.
        'employee_code' => ['codigo', 'código', 'codigo gestor', 'código gestor', 'codigo promotor', 'código promotor', 'numero de empleado', 'número de empleado', 'no. empleado', 'clave promotor'],
    ];

    /**
     * @param  OkrCalendarService  $calendar  Inicio/fin de la semana cargada.
     * @param  OkrTrackingPeriodResolver  $periodResolver  Periodo mensual para verificar cobertura contra el roster.
     */
    public function __construct(
        private readonly OkrCalendarService $calendar,
        private readonly OkrTrackingPeriodResolver $periodResolver,
    ) {}

    /**
     * Importa el archivo de colocación de UNA semana de UN Objective: guarda
     * el archivo, lo interpreta (parseFile), determina la cobertura, cuenta
     * movimientos repetidos de otras semanas y persiste carga + movimientos
     * bajo candado por OKR y transacción. Si hay una carga activa de esa
     * semana, la reemplaza solo con $confirmReplace (la anterior queda
     * "superseded"). Ante cualquier fallo borra el archivo y compensa lo escrito.
     *
     * Efecto: archivo en disco 'local' (okr-placement-uploads/{objective}/),
     * INSERT en okr_placement_uploads y okr_placement_movements, UPDATE de la
     * carga reemplazada.
     *
     * @param  OkrObjective  $objective  Objective (sucursal o individual) al que se carga.
     * @param  int  $weekNumber  Semana del OKR que representa el archivo.
     * @param  UploadedFile  $file  Excel/CSV subido.
     * @param  User  $user  Usuario que carga.
     * @param  bool  $confirmReplace  true para reemplazar una carga activa existente.
     * @param  bool  $declaredComplete  14-oct-2026: quien sube el archivo de SUCURSAL declara que es el
     *                                  reporte COMPLETO de movimientos de la semana (así es el reporte de Lendus: solo lista
     *                                  créditos otorgados, un gestor sin colocación no aparece). Con esa declaración la ausencia
     *                                  de filas de un gestor es un $0 real; sin ella se conserva la regla 18 (dato faltante).
     * @return OkrPlacementUpload Carga creada.
     *
     * @throws OkrPlacementUploadConflictException si ya existe una carga ACTIVA
     *                                             para este Objective/semana y $confirmReplace es false (6.7).
     * @throws \RuntimeException si el archivo no tiene encabezados reconocibles o filas válidas.
     */
    public function import(OkrObjective $objective, int $weekNumber, UploadedFile $file, User $user, bool $confirmReplace = false, bool $declaredComplete = false): OkrPlacementUpload
    {
        $existing = OkrPlacementUpload::query()
            ->where('okr_objective_id', $objective->id)
            ->where('week_number', $weekNumber)
            ->where('status', OkrPlacementUpload::STATUS_ACTIVE)
            ->first();

        if ($existing && ! $confirmReplace) {
            throw new OkrPlacementUploadConflictException($existing);
        }

        $weekStart = $this->calendar->weekStart($objective->start_date, $weekNumber);
        $weekEnd = $this->calendar->weekEnd($objective->start_date, $weekNumber);

        $disk = 'local';
        $path = $file->store('okr-placement-uploads/'.$objective->id, $disk);
        $absolutePath = Storage::disk($disk)->path($path);

        // 4.9 (19-oct-2026): si el archivo no se puede leer o la carga no se
        // confirma, el archivo guardado se borra — nunca archivos huérfanos — y
        // se deshace lo escrito (compensación explícita: con tablas MyISAM la
        // transacción no revierte nada).
        $state = new \ArrayObject(['upload_id' => null, 'superseded_id' => null]); // objeto: se comparte entre closures
        try {
            $parsed = $this->parseFile($absolutePath, $objective, $weekStart, $weekEnd, $weekNumber);
            $isBranchFile = $objective->scope_type === OkrObjective::SCOPE_BRANCH;
            // 4.6: reporte COMPLETO declarado → quien no aparece = $0 real; PARCIAL →
            // la cobertura se verifica contra el roster y la ausencia queda como
            // dato faltante (nunca un cero asumido).
            $coverageStatus = $declaredComplete && $isBranchFile
                ? OkrPlacementUpload::COVERAGE_FULL
                : $this->resolveCoverageStatus($objective, $weekStart, $weekEnd, $parsed['employee_ids_seen']);
            $parsed['rows_duplicated'] = $this->countDuplicatedAgainstOtherWeeks($objective, $weekNumber, $parsed['rows']);

            return app(OkrDocumentIssuer::class)->exclusively('okr-placement:'.$objective->id, fn () => DB::transaction(function () use ($objective, $weekNumber, $weekStart, $weekEnd, $file, $path, $disk, $user, $parsed, $coverageStatus, $confirmReplace, $declaredComplete, $isBranchFile, $state) {
                // Concurrencia: dos cargas simultáneas de la MISMA semana. Se
                // bloquea el OKR y se vuelve a leer la carga activa dentro de la
                // transacción; el índice okr_uploads_one_current es la red final.
                OkrObjective::query()->whereKey($objective->id)->lockForUpdate()->first();
                $existing = OkrPlacementUpload::query()->where('okr_objective_id', $objective->id)
                    ->where('week_number', $weekNumber)->where('status', OkrPlacementUpload::STATUS_ACTIVE)->lockForUpdate()->first();
                if ($existing && ! $confirmReplace) {
                    throw new OkrPlacementUploadConflictException($existing);
                }

                return $this->persistUpload($objective, $weekNumber, $weekStart, $weekEnd, $file, $path, $disk, $user, $existing, $parsed, $coverageStatus, $isBranchFile ? $declaredComplete : null, $state);
            }));
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);
            $this->compensate($state);
            if (OkrDocumentIssuer::isCurrentGuardViolation($e)) {
                $winner = OkrPlacementUpload::query()->where('okr_objective_id', $objective->id)
                    ->where('week_number', $weekNumber)->where('status', OkrPlacementUpload::STATUS_ACTIVE)->first();
                if ($winner) {
                    throw new OkrPlacementUploadConflictException($winner);
                }
            }
            throw $e;
        }
    }

    /**
     * Deshace una carga a medias (movimientos, carga nueva y reemplazo): borra
     * movimientos y carga nueva y reactiva la carga que se había marcado como
     * reemplazada. Necesario con tablas MyISAM; los errores solo se registran en log.
     *
     * @param  \ArrayObject  $state  ['upload_id' => ?int, 'superseded_id' => ?int].
     */
    private function compensate(\ArrayObject $state): void
    {
        try {
            if ($state['upload_id'] !== null) {
                OkrPlacementMovement::query()->where('okr_placement_upload_id', $state['upload_id'])->delete();
                OkrPlacementUpload::query()->whereKey($state['upload_id'])->delete();
            }
            if ($state['superseded_id'] !== null) {
                OkrPlacementUpload::query()->whereKey($state['superseded_id'])->first()?->update(['status' => OkrPlacementUpload::STATUS_ACTIVE]);
            }
        } catch (\Throwable $e) {
            Log::error('OKR: no se pudo deshacer una carga de colocación fallida', ['state' => $state->getArrayCopy(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * Escribe la carga y sus movimientos: marca la carga anterior como
     * reemplazada (si existe), crea la nueva con totales y contadores del
     * análisis y un movimiento por fila. Registra ids en $state para compensar.
     *
     * @param  OkrObjective  $objective  Objective de la carga.
     * @param  int  $weekNumber  Semana.
     * @param  \Illuminate\Support\Carbon  $weekStart  Inicio de la semana.
     * @param  \Illuminate\Support\Carbon  $weekEnd  Fin de la semana.
     * @param  UploadedFile  $file  Archivo original (nombre).
     * @param  string  $path  Ruta guardada en el disco.
     * @param  string  $disk  Disco de almacenamiento.
     * @param  User  $user  Usuario que carga.
     * @param  OkrPlacementUpload|null  $existing  Carga activa a reemplazar.
     * @param  array  $parsed  Resultado de parseFile() (+ rows_duplicated).
     * @param  string  $coverageStatus  OkrPlacementUpload::COVERAGE_*.
     * @param  bool|null  $declaredComplete  Declaración de reporte completo (solo sucursal; null en individual).
     * @param  \ArrayObject  $state  Estado compartido para la compensación.
     * @return OkrPlacementUpload Carga creada.
     */
    private function persistUpload(OkrObjective $objective, int $weekNumber, \Illuminate\Support\Carbon $weekStart, \Illuminate\Support\Carbon $weekEnd, UploadedFile $file, string $path, string $disk, User $user, ?OkrPlacementUpload $existing, array $parsed, string $coverageStatus, ?bool $declaredComplete, \ArrayObject $state): OkrPlacementUpload
    {
        if ($existing) {
            $existing->update(['status' => OkrPlacementUpload::STATUS_SUPERSEDED]);
            $state['superseded_id'] = $existing->id;
        }

        $upload = OkrPlacementUpload::query()->create([
            'okr_objective_id' => $objective->id,
            'week_number' => $weekNumber,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'disk' => $disk,
            'uploaded_by' => $user->id,
            'status' => OkrPlacementUpload::STATUS_ACTIVE,
            'replaced_upload_id' => $existing?->id,
            'total_amount' => $parsed['total_amount'],
            'rows_count' => count($parsed['rows']),
            'unattributed_amount' => $parsed['unattributed_amount'],
            'rows_outside_week_range' => $parsed['rows_outside_week_range'],
            'coverage_status' => $coverageStatus,
            'declared_complete' => $declaredComplete,
            'rows_duplicated' => $parsed['rows_duplicated'],
            'rows_other_branch' => $parsed['rows_other_branch'],
            'rows_cancelled' => $parsed['rows_cancelled'],
            'rows_out_of_objective_range' => $parsed['rows_out_of_objective_range'],
            'rows_pending_attribution' => $parsed['rows_pending_attribution'],
        ]);
        $state['upload_id'] = $upload->id;

        foreach ($parsed['rows'] as $row) {
            OkrPlacementMovement::query()->create([
                'okr_placement_upload_id' => $upload->id,
                'employee_id' => $row['employee_id'],
                'employee_name_raw' => $row['employee_name_raw'],
                'attribution_status' => $row['attribution_status'],
                'attribution_method' => $row['attribution_method'],
                'attribution_note' => $row['attribution_note'],
                'amount' => $row['amount'],
                'operation_date' => $row['operation_date'],
                'fingerprint' => $row['fingerprint'],
                'raw_payload' => $row['raw_payload'],
            ]);
        }

        return $upload;
    }

    /**
     * Lee la hoja activa del archivo y convierte cada fila en movimiento.
     * Descarta (contando el motivo): filas de otra sucursal o ruta sin
     * equivalencia, montos 0/vacíos, cancelados/revertidos, filas de otro
     * colaborador en un OKR individual, fechas fuera del plazo del OKR y
     * duplicados dentro del mismo archivo. Cada fila se atribuye con
     * OkrPlacementAttributionResolver y recibe una huella (contrato+importe+fecha
     * o gestor+importe+fecha, anclada a la semana si no hay contrato ni fecha).
     *
     * Efecto: solo lectura del archivo y consultas de atribución.
     *
     * @param  string  $absolutePath  Ruta absoluta del archivo guardado.
     * @param  OkrObjective  $objective  Objective de la carga.
     * @param  \Illuminate\Support\Carbon  $weekStart  Inicio de la semana.
     * @param  \Illuminate\Support\Carbon  $weekEnd  Fin de la semana.
     * @param  int  $weekNumber  Semana (para anclar huellas sin fecha).
     * @return array{rows: array<int, array{employee_id:?int, employee_name_raw:?string, amount:float, operation_date:?string, fingerprint:string, raw_payload:array}>, total_amount:float, unattributed_amount:?float, rows_outside_week_range:int, rows_other_branch:int, rows_cancelled:int, rows_out_of_objective_range:int, rows_pending_attribution:int, employee_ids_seen:int[]}
     *
     * @throws \RuntimeException Sin encabezados reconocibles o sin ninguna fila válida.
     */
    private function parseFile(string $absolutePath, OkrObjective $objective, \Illuminate\Support\Carbon $weekStart, \Illuminate\Support\Carbon $weekEnd, int $weekNumber): array
    {
        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($absolutePath);
        $sheet = $spreadsheet->getActiveSheet();
        $allRows = $sheet->toArray(null, true, true, false);

        $headerRowIndex = $this->detectHeaderRowIndex($allRows);
        if ($headerRowIndex === null) {
            throw new \RuntimeException('No se encontraron columnas de colaborador/monto en el archivo. Verifica que tenga encabezados como "Colaborador"/"Gestor" y "Colocación"/"Monto".');
        }

        $colMap = $this->buildColumnMap($allRows[$headerRowIndex]);

        $rows = [];
        $seenFingerprints = [];
        $totalAmount = 0.0;
        $unattributedAmount = 0.0;
        $hasUnattributed = false;
        $rowsOutsideWeekRange = 0;
        // 18: empleados que aparecen en el archivo CON TODO Y fila de monto
        // cero (el filtro de amount==0 más abajo los saca de $rows/movements,
        // pero SÍ cuentan para saber si el archivo mencionó a ese gestor —
        // nunca confundir "no tiene fila" con "tiene fila en $0").
        $employeeIdsSeen = [];

        // scope_type=employee: solo se cuentan filas de ESE colaborador — un
        // archivo de sucursal cargado por error en un Objective individual
        // nunca debe sumar la colocación de otros gestores (6.2/Test 8).
        // Identidad canónica completa del gestor (todos sus ids históricos) —
        // nunca una coincidencia insegura por nombre libre.
        $restrictToEmployeeIds = $objective->scope_type === OkrObjective::SCOPE_EMPLOYEE && $objective->employee_id
            ? app(OkrEmployeeBranchResolver::class)->canonicalIdsFor((int) $objective->employee_id)
            : null;

        // Corrección 14-oct-2026 — el MISMO reporte de Lendus trae movimientos
        // de varios gestores, contratos y (a veces) sucursales. Columnas
        // opcionales reconocidas: Contrato, Sucursal, Tipo de movimiento/Estatus.
        $objectiveBranchName = $objective->branch?->name;
        $objectiveStart = \Illuminate\Support\Carbon::parse($objective->start_date)->startOfDay();
        $objectiveEnd = \Illuminate\Support\Carbon::parse($objective->end_date)->endOfDay();
        $rowsOtherBranch = 0;
        $rowsCancelled = 0;
        $rowsOutOfObjectiveRange = 0;
        $rowsPendingAttribution = 0;
        $attributor = app(OkrPlacementAttributionResolver::class);

        foreach (array_slice($allRows, $headerRowIndex + 1) as $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $employeeNameRaw = $this->str($row[$colMap['employee']] ?? null);
            $amount = $this->toDecimal($row[$colMap['amount']] ?? null);
            $employeeCode = isset($colMap['employee_code']) ? $this->str($row[$colMap['employee_code']] ?? null) : null;
            $operationDate = isset($colMap['date']) ? $this->toDate($row[$colMap['date']] ?? null) : null;
            $contract = isset($colMap['contract']) ? $this->str($row[$colMap['contract']] ?? null) : null;
            $branchRaw = isset($colMap['branch']) ? $this->str($row[$colMap['branch']] ?? null) : null;
            $routeRaw = isset($colMap['route']) ? $this->str($row[$colMap['route']] ?? null) : null;
            $movementType = isset($colMap['movement_type']) ? $this->str($row[$colMap['movement_type']] ?? null) : null;

            // Sucursal de la fila: la columna Sucursal/Oficina manda; si el archivo
            // solo trae Ruta, se traduce a su sucursal con las equivalencias
            // verificadas (una ruta sin equivalencia nunca se atribuye).
            // La columna Oficina/Sucursal también pasa por el catálogo oficial
            // (acentos, o una ruta capturada ahí) — 16-oct-2026.
            $rowBranch = $branchRaw !== null
                ? (BranchRouteCatalog::officialBranchFor($branchRaw) ?? $branchRaw)
                : ($routeRaw !== null ? ($this->officialBranchForRoute($routeRaw) ?? '__ruta_sin_equivalencia__') : null);

            // Fila de OTRA sucursal (solo si el archivo trae la columna): nunca se
            // mezcla colocación de otras sucursales en este OKR.
            if ($rowBranch !== null && $objectiveBranchName !== null && $this->normalizeBranch($rowBranch) !== $this->normalizeBranch($objectiveBranchName)) {
                $rowsOtherBranch++;

                continue;
            }

            // Prioridad 4 (15-oct-2026): atribución por número de empleado vigente
            // para la FECHA del movimiento → identidad canónica → código de
            // vendedor → asignación del periodo → sucursal oficial; el nombre solo
            // si es exacto y no contradice nada. Ambigüedad = pendiente.
            $attribution = $attributor->resolve($employeeCode, $employeeNameRaw, $operationDate ?? $weekEnd->toDateString(), $objectiveBranchName, $rowBranch !== null);
            $employeeId = $attribution['employee_id'];

            if ($employeeId !== null) {
                $employeeIdsSeen[$employeeId] = true; // cuenta aunque el monto sea 0/nulo
            }
            if ($amount === null || $amount == 0.0) {
                continue;
            }

            // Movimiento cancelado/revertido — no es colocación válida.
            if ($movementType !== null && preg_match('/CANCEL|REVERS|ANULAD/u', mb_strtoupper($movementType))) {
                $rowsCancelled++;

                continue;
            }

            if ($restrictToEmployeeIds !== null && ! in_array($employeeId, $restrictToEmployeeIds, true)) {
                continue; // fila de otro colaborador — nunca se suma a este Objective individual
            }

            if ($operationDate !== null) {
                $opDate = \Illuminate\Support\Carbon::parse($operationDate);
                // Fuera del plazo COMPLETO del OKR: no pertenece a este seguimiento.
                if ($opDate->lt($objectiveStart) || $opDate->gt($objectiveEnd)) {
                    $rowsOutOfObjectiveRange++;

                    continue;
                }
                if ($opDate->lt($weekStart) || $opDate->gt($weekEnd)) {
                    $rowsOutsideWeekRange++;
                }
            }

            // Con contrato, la identidad del movimiento es el contrato (+fecha+importe):
            // si reaparece en el archivo de una semana posterior, la huella coincide
            // y OkrWeeklyPlacementResolver lo cuenta una sola vez.
            $fingerprint = $contract !== null
                ? hash('sha256', implode('|', ['contract', mb_strtoupper($contract), $amount, $operationDate ?? '']))
                : hash('sha256', implode('|', array_filter([
                    $employeeNameRaw ?? '', $amount, $operationDate ?? '', $employeeId ?? '', $employeeCode,
                    // Sin contrato NI fecha la fila no tiene identidad propia entre
                    // semanas (el mismo importe del mismo gestor puede repetirse
                    // legítimamente): se ancla a su semana para no descartarla.
                    $operationDate === null ? "week:{$weekNumber}" : null,
                ], fn ($v) => $v !== null)));
            // Fila duplicada dentro del MISMO archivo (6.8) — no se cuenta dos veces.
            if (isset($seenFingerprints[$fingerprint])) {
                continue;
            }
            $seenFingerprints[$fingerprint] = true;

            $rows[] = [
                'employee_id' => $employeeId,
                'employee_name_raw' => $employeeNameRaw,
                'attribution_status' => $attribution['status'],
                'attribution_method' => $attribution['method'],
                'attribution_note' => $attribution['note'] !== null ? mb_substr($attribution['note'], 0, 600) : null,
                'amount' => $amount,
                'operation_date' => $operationDate,
                'fingerprint' => $fingerprint,
                'raw_payload' => array_filter([
                    'employee_name_raw' => $employeeNameRaw, 'amount' => $amount, 'operation_date' => $operationDate,
                    'contract' => $contract, 'branch' => $branchRaw, 'route' => $routeRaw, 'movement_type' => $movementType,
                    'employee_code' => $employeeCode,
                ], fn ($v) => $v !== null),
            ];

            $totalAmount += $amount;
            if ($employeeId === null) {
                $unattributedAmount += $amount;
                $hasUnattributed = true;
                $rowsPendingAttribution++;
            }
        }

        if (empty($rows)) {
            throw new \RuntimeException($restrictToEmployeeIds !== null
                ? 'El archivo no tiene ninguna fila atribuible a este colaborador.'
                : 'El archivo no tiene ninguna fila con monto válido.');
        }

        return [
            'rows' => $rows,
            'total_amount' => round($totalAmount, 2),
            'unattributed_amount' => $hasUnattributed ? round($unattributedAmount, 2) : null,
            'rows_outside_week_range' => $rowsOutsideWeekRange,
            'rows_other_branch' => $rowsOtherBranch,
            'rows_cancelled' => $rowsCancelled,
            'rows_out_of_objective_range' => $rowsOutOfObjectiveRange,
            'rows_pending_attribution' => $rowsPendingAttribution,
            'employee_ids_seen' => array_keys($employeeIdsSeen),
        ];
    }

    /**
     * Sucursal oficial de una RUTA de Lendus (p. ej. ATLIX-GTE → ATLIXCO) con las
     * equivalencias VERIFICADAS de Reportería (BranchResolverService) —
     * 15-oct-2026. Oficina = Sucursal (13 oficiales); una ruta es distinta y solo
     * se traduce con esa tabla. Ruta desconocida → null (nunca por aproximación).
     *
     * @param  string  $route  Ruta tal como viene en el archivo.
     * @return string|null Sucursal oficial o null.
     */
    private function officialBranchForRoute(string $route): ?string
    {
        return BranchRouteCatalog::officialBranchFor($route);
    }

    /**
     * Normaliza un nombre de sucursal para comparar: sin acentos, mayúsculas y espacios compactados.
     *
     * @param  string  $value  Nombre de sucursal.
     * @return string Nombre normalizado.
     */
    private function normalizeBranch(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim(Str::of($value)->ascii()->upper()->value())) ?? '';
    }

    /**
     * Movimientos de este archivo que YA están en la carga activa de OTRA
     * semana del mismo OKR (informativo — el conteo autoritativo sin
     * duplicados lo hace OkrWeeklyPlacementResolver al acumular).
     *
     * @param  OkrObjective  $objective  Objective de la carga.
     * @param  int  $weekNumber  Semana de este archivo (se excluye).
     * @param  array  $rows  Filas parseadas (con fingerprint).
     * @return int Número de huellas distintas ya presentes en otras semanas.
     */
    private function countDuplicatedAgainstOtherWeeks(OkrObjective $objective, int $weekNumber, array $rows): int
    {
        $fingerprints = array_column($rows, 'fingerprint');
        if ($fingerprints === []) {
            return 0;
        }

        return (int) DB::table('okr_placement_movements as m')
            ->join('okr_placement_uploads as u', 'u.id', '=', 'm.okr_placement_upload_id')
            ->where('u.okr_objective_id', $objective->id)
            ->where('u.status', OkrPlacementUpload::STATUS_ACTIVE)
            ->where('u.week_number', '!=', $weekNumber)
            ->whereIn('m.fingerprint', $fingerprints)
            ->distinct()
            ->count('m.fingerprint');
    }

    /**
     * 18: ¿este archivo de sucursal mencionó a TODOS los empleados con
     * asignación activa a esa sucursal en el periodo de la semana cargada?
     * Si no se puede resolver un periodo real (mes todavía sin radiografía
     * generada) o la sucursal no tiene roster registrado, se devuelve
     * UNKNOWN — nunca se asume cobertura que no se pudo verificar.
     * El roster excluye empleados inactivos y bajas fuera de la nómina vigente.
     *
     * @param  OkrObjective  $objective  Objective de la carga.
     * @param  \Illuminate\Support\Carbon  $weekStart  Inicio de la semana.
     * @param  \Illuminate\Support\Carbon  $weekEnd  Fin de la semana.
     * @param  int[]  $employeeIdsSeen  Empleados mencionados en el archivo (aunque sea con $0).
     * @return string OkrPlacementUpload::COVERAGE_FULL|PARTIAL|UNKNOWN.
     */
    private function resolveCoverageStatus(OkrObjective $objective, \Illuminate\Support\Carbon $weekStart, \Illuminate\Support\Carbon $weekEnd, array $employeeIdsSeen): string
    {
        if ($objective->scope_type !== OkrObjective::SCOPE_BRANCH || $objective->branch_id === null) {
            return OkrPlacementUpload::COVERAGE_UNKNOWN; // solo aplica a archivos de sucursal (6.4) — un archivo individual no "cubre" a nadie más
        }

        $period = $this->periodResolver->getPeriodForDate($weekEnd) ?? $this->periodResolver->getPeriodForDate($weekStart);
        if ($period === null) {
            return OkrPlacementUpload::COVERAGE_UNKNOWN;
        }

        $roster = EmployeeBranchAssignment::query()
            ->where('period_id', $period->id)
            ->where('branch_id', $objective->branch_id)
            ->whereHas('employee', fn ($q) => $q->where('is_active', true))
            ->pluck('employee_id')
            ->unique()
            ->values();

        // Bajas (fuera de la nómina vigente) no cuentan para la cobertura.
        $active = app(OkrEmployeeBranchResolver::class)->activeMap($roster->all());
        $roster = $roster->filter(fn ($id) => $active[(int) $id] ?? false)->values();

        if ($roster->isEmpty()) {
            return OkrPlacementUpload::COVERAGE_UNKNOWN;
        }

        $missing = $roster->diff($employeeIdsSeen);

        return $missing->isEmpty() ? OkrPlacementUpload::COVERAGE_FULL : OkrPlacementUpload::COVERAGE_PARTIAL;
    }

    /**
     * Busca, en las primeras 20 filas, la fila de encabezados que tenga a la vez
     * una columna de colaborador y una de monto (coincidencia exacta con ALIASES).
     *
     * @param  array  $rows  Filas de la hoja.
     * @return int|null Índice de la fila de encabezados, o null si no hay.
     */
    private function detectHeaderRowIndex(array $rows): ?int
    {
        $employeeAliases = self::ALIASES['employee'];
        $amountAliases = self::ALIASES['amount'];

        foreach (array_slice($rows, 0, 20, true) as $index => $row) {
            $hasEmployee = false;
            $hasAmount = false;
            foreach ($row as $cell) {
                $norm = mb_strtolower(trim((string) $cell));
                if (in_array($norm, $employeeAliases, true)) {
                    $hasEmployee = true;
                }
                if (in_array($norm, $amountAliases, true)) {
                    $hasAmount = true;
                }
            }
            if ($hasEmployee && $hasAmount) {
                return (int) $index;
            }
        }

        return null;
    }

    /**
     * Mapa campo lógico => índice de columna según ALIASES (primera coincidencia por campo).
     *
     * @param  array  $headerRow  Fila de encabezados.
     * @return array<string, int|string> Por ejemplo ['employee' => 0, 'amount' => 3].
     */
    private function buildColumnMap(array $headerRow): array
    {
        $aliases = self::ALIASES;

        $map = [];
        foreach ($aliases as $field => $possibleNames) {
            foreach ($headerRow as $idx => $cell) {
                $norm = mb_strtolower(trim((string) $cell));
                if (in_array($norm, $possibleNames, true)) {
                    $map[$field] = $idx;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Convierte una celda a importe (quita "$", comas y espacios), redondeado a 2 decimales.
     *
     * @param  mixed  $value  Valor de la celda.
     * @return float|null Importe o null si no es numérico.
     */
    private function toDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return round((float) $value, 2);
        }
        $clean = str_replace(['$', ',', ' '], '', (string) $value);

        return is_numeric($clean) ? round((float) $clean, 2) : null;
    }

    /**
     * Convierte una celda a fecha Y-m-d (número serial de Excel o texto interpretable por Carbon).
     *
     * @param  mixed  $value  Valor de la celda.
     * @return string|null Fecha o null si no se puede interpretar.
     */
    private function toDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            try {
                return Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
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
     * Texto recortado de una celda; vacío → null.
     *
     * @param  mixed  $value  Valor de la celda.
     * @return string|null Texto o null.
     */
    private function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string) $value);

        return $v === '' ? null : $v;
    }

    /**
     * ¿Todas las celdas de la fila están vacías?
     *
     * @param  array  $row  Fila de la hoja.
     * @return bool true si la fila no tiene contenido.
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
