<?php

namespace App\Services\Okr;

use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\OkrObjective;
use App\Models\OkrPlacementMovement;
use App\Models\OkrPlacementUpload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

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
        'employee'      => ['colaborador', 'gestor', 'promotor', 'empleado', 'nombre', 'asesor', 'nombre gestor', 'gestor asignado'],
        'amount'        => ['colocacion', 'colocación', 'monto', 'importe', 'monto colocado', 'amount', 'monto otorgado', 'importe otorgado', 'monto prestado'],
        'date'          => ['fecha', 'fecha operacion', 'fecha de operación', 'fecha operación', 'operation_date', 'fecha otorgamiento', 'fecha de otorgamiento', 'fecha de entrega'],
        'contract'      => ['contrato', 'no. contrato', 'no contrato', 'numero de contrato', 'número de contrato', 'folio', 'credito', 'crédito', 'id credito', 'prestamo', 'préstamo'],
        // Oficina = Sucursal (las 13 oficiales). Ruta es distinta: pertenece a una
        // sucursal según las equivalencias verificadas de Reportería.
        'branch'        => ['sucursal', 'oficina'],
        'route'         => ['ruta', 'nombre ruta', 'ruta lendus'],
        'movement_type' => ['tipo', 'tipo de movimiento', 'tipo movimiento', 'movimiento', 'estatus', 'status', 'estado'],
        // 16-oct-2026 — número de empleado / código de promotor Lendus (p. ej.
        // ATLX12513): si viene, identifica al gestor ANTES que el nombre.
        'employee_code' => ['codigo', 'código', 'codigo gestor', 'código gestor', 'codigo promotor', 'código promotor', 'numero de empleado', 'número de empleado', 'no. empleado', 'clave promotor'],
    ];

    public function __construct(
        private readonly OkrCalendarService $calendar,
        private readonly OkrTrackingPeriodResolver $periodResolver,
    ) {
    }

    /**
     * @throws OkrPlacementUploadConflictException si ya existe una carga ACTIVA
     *         para este Objective/semana y $confirmReplace es false (6.7).
     */
    /**
     * @param  bool  $declaredComplete  14-oct-2026: quien sube el archivo de SUCURSAL declara que es el
     *         reporte COMPLETO de movimientos de la semana (así es el reporte de Lendus: solo lista
     *         créditos otorgados, un gestor sin colocación no aparece). Con esa declaración la ausencia
     *         de filas de un gestor es un $0 real; sin ella se conserva la regla 18 (dato faltante).
     */
    public function import(OkrObjective $objective, int $weekNumber, UploadedFile $file, User $user, bool $confirmReplace = false, bool $declaredComplete = false): OkrPlacementUpload
    {
        $existing = OkrPlacementUpload::query()
            ->where('okr_objective_id', $objective->id)
            ->where('week_number', $weekNumber)
            ->where('status', OkrPlacementUpload::STATUS_ACTIVE)
            ->first();

        if ($existing && !$confirmReplace) {
            throw new OkrPlacementUploadConflictException($existing);
        }

        $weekStart = $this->calendar->weekStart($objective->start_date, $weekNumber);
        $weekEnd   = $this->calendar->weekEnd($objective->start_date, $weekNumber);

        $disk = 'local';
        $path = $file->store('okr-placement-uploads/' . $objective->id, $disk);
        $absolutePath = Storage::disk($disk)->path($path);

        $parsed = $this->parseFile($absolutePath, $objective, $weekStart, $weekEnd, $weekNumber);
        $coverageStatus = $declaredComplete && $objective->scope_type === OkrObjective::SCOPE_BRANCH
            ? OkrPlacementUpload::COVERAGE_FULL
            : $this->resolveCoverageStatus($objective, $weekStart, $weekEnd, $parsed['employee_ids_seen']);
        $parsed['rows_duplicated'] = $this->countDuplicatedAgainstOtherWeeks($objective, $weekNumber, $parsed['rows']);

        return DB::transaction(function () use ($objective, $weekNumber, $weekStart, $weekEnd, $file, $path, $disk, $user, $existing, $parsed, $coverageStatus) {
            if ($existing) {
                $existing->update(['status' => OkrPlacementUpload::STATUS_SUPERSEDED]);
            }

            $upload = OkrPlacementUpload::query()->create([
                'okr_objective_id'         => $objective->id,
                'week_number'              => $weekNumber,
                'week_start'                => $weekStart,
                'week_end'                  => $weekEnd,
                'original_filename'        => $file->getClientOriginalName(),
                'stored_path'               => $path,
                'disk'                      => $disk,
                'uploaded_by'               => $user->id,
                'status'                    => OkrPlacementUpload::STATUS_ACTIVE,
                'replaced_upload_id'        => $existing?->id,
                'total_amount'              => $parsed['total_amount'],
                'rows_count'                => count($parsed['rows']),
                'unattributed_amount'       => $parsed['unattributed_amount'],
                'rows_outside_week_range'   => $parsed['rows_outside_week_range'],
                'coverage_status'           => $coverageStatus,
                'rows_duplicated'           => $parsed['rows_duplicated'],
                'rows_other_branch'         => $parsed['rows_other_branch'],
                'rows_cancelled'            => $parsed['rows_cancelled'],
                'rows_out_of_objective_range' => $parsed['rows_out_of_objective_range'],
            ]);

            foreach ($parsed['rows'] as $row) {
                OkrPlacementMovement::query()->create([
                    'okr_placement_upload_id' => $upload->id,
                    'employee_id'             => $row['employee_id'],
                    'employee_name_raw'       => $row['employee_name_raw'],
                    'amount'                  => $row['amount'],
                    'operation_date'          => $row['operation_date'],
                    'fingerprint'             => $row['fingerprint'],
                    'raw_payload'             => $row['raw_payload'],
                ]);
            }

            return $upload;
        });
    }

    /**
     * @return array{rows: array<int, array{employee_id:?int, employee_name_raw:?string, amount:float, operation_date:?string, fingerprint:string, raw_payload:array}>, total_amount:float, unattributed_amount:?float, rows_outside_week_range:int}
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

        foreach (array_slice($allRows, $headerRowIndex + 1) as $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $employeeNameRaw = $this->str($row[$colMap['employee']] ?? null);
            $amount = $this->toDecimal($row[$colMap['amount']] ?? null);
            $employeeCode = isset($colMap['employee_code']) ? $this->str($row[$colMap['employee_code']] ?? null) : null;
            $employee = ($employeeCode ? $this->resolveEmployeeByCode($employeeCode) : null)
                ?? ($employeeNameRaw ? $this->resolveEmployee($employeeNameRaw) : null);
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
                ? (\App\Services\Catalog\BranchRouteCatalog::officialBranchFor($branchRaw) ?? $branchRaw)
                : ($routeRaw !== null ? ($this->officialBranchForRoute($routeRaw) ?? '__ruta_sin_equivalencia__') : null);

            // Fila de OTRA sucursal (solo si el archivo trae la columna): nunca se
            // mezcla colocación de otras sucursales en este OKR.
            if ($rowBranch !== null && $objectiveBranchName !== null && $this->normalizeBranch($rowBranch) !== $this->normalizeBranch($objectiveBranchName)) {
                $rowsOtherBranch++;

                continue;
            }

            if ($employee !== null) {
                $employeeIdsSeen[$employee->id] = true; // cuenta aunque el monto sea 0/nulo
            }
            if ($amount === null || $amount == 0.0) {
                continue;
            }

            // Movimiento cancelado/revertido — no es colocación válida.
            if ($movementType !== null && preg_match('/CANCEL|REVERS|ANULAD/u', mb_strtoupper($movementType))) {
                $rowsCancelled++;

                continue;
            }

            $operationDate = isset($colMap['date']) ? $this->toDate($row[$colMap['date']] ?? null) : null;

            if ($restrictToEmployeeIds !== null && !in_array($employee?->id, $restrictToEmployeeIds, true)) {
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
                    $employeeNameRaw ?? '', $amount, $operationDate ?? '', $employee?->id ?? '',
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
                'employee_id'       => $employee?->id,
                'employee_name_raw' => $employeeNameRaw,
                'amount'            => $amount,
                'operation_date'    => $operationDate,
                'fingerprint'       => $fingerprint,
                'raw_payload'       => array_filter([
                    'employee_name_raw' => $employeeNameRaw, 'amount' => $amount, 'operation_date' => $operationDate,
                    'contract' => $contract, 'branch' => $branchRaw, 'route' => $routeRaw, 'movement_type' => $movementType,
                ], fn ($v) => $v !== null),
            ];

            $totalAmount += $amount;
            if ($employee === null) {
                $unattributedAmount += $amount;
                $hasUnattributed = true;
            }
        }

        if (empty($rows)) {
            throw new \RuntimeException($restrictToEmployeeId !== null
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
            'employee_ids_seen' => array_keys($employeeIdsSeen),
        ];
    }

    /**
     * Sucursal oficial de una RUTA de Lendus (p. ej. ATLIX-GTE → ATLIXCO) con las
     * equivalencias VERIFICADAS de Reportería (BranchResolverService) —
     * 15-oct-2026. Oficina = Sucursal (13 oficiales); una ruta es distinta y solo
     * se traduce con esa tabla. Ruta desconocida → null (nunca por aproximación).
     */
    private function officialBranchForRoute(string $route): ?string
    {
        return app(\App\Services\BranchResolverService::class)->resolveRealBranchFromRoute($route);
    }

    private function normalizeBranch(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim(\Illuminate\Support\Str::of($value)->ascii()->upper()->value())) ?? '';
    }

    /**
     * Movimientos de este archivo que YA están en la carga activa de OTRA
     * semana del mismo OKR (informativo — el conteo autoritativo sin
     * duplicados lo hace OkrWeeklyPlacementResolver al acumular).
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

    private function detectHeaderRowIndex(array $rows): ?int
    {
        $employeeAliases = self::ALIASES['employee'];
        $amountAliases   = self::ALIASES['amount'];

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

    /** Gestor por número de empleado Lendus (directorio de colaboradores, cualquier carga). */
    private function resolveEmployeeByCode(string $code): ?Employee
    {
        $norm = DB::table('lendus_employee_directory')->whereRaw('UPPER(TRIM(codigo)) = ?', [mb_strtoupper(trim($code))])->value('normalized_name');

        return $norm ? Employee::query()->where('normalized_name', $norm)->orderBy('id')->first() : null;
    }

    private function resolveEmployee(string $name): ?Employee
    {
        return Employee::query()->where('normalized_name', $this->normalize($name))->orderBy('id')->first();
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

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

    private function toDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
        try {
            return \Carbon\Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string) $value);

        return $v === '' ? null : $v;
    }

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
