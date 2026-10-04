<?php

namespace App\Services\Okr;

use App\Models\Employee;
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
    public function __construct(private readonly OkrCalendarService $calendar)
    {
    }

    /**
     * @throws OkrPlacementUploadConflictException si ya existe una carga ACTIVA
     *         para este Objective/semana y $confirmReplace es false (6.7).
     */
    public function import(OkrObjective $objective, int $weekNumber, UploadedFile $file, User $user, bool $confirmReplace = false): OkrPlacementUpload
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

        $parsed = $this->parseFile($absolutePath, $objective, $weekStart, $weekEnd);

        return DB::transaction(function () use ($objective, $weekNumber, $weekStart, $weekEnd, $file, $path, $disk, $user, $existing, $parsed) {
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
    private function parseFile(string $absolutePath, OkrObjective $objective, \Illuminate\Support\Carbon $weekStart, \Illuminate\Support\Carbon $weekEnd): array
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

        // scope_type=employee: solo se cuentan filas de ESE colaborador — un
        // archivo de sucursal cargado por error en un Objective individual
        // nunca debe sumar la colocación de otros gestores (6.2/Test 8).
        $restrictToEmployeeId = $objective->scope_type === OkrObjective::SCOPE_EMPLOYEE ? $objective->employee_id : null;

        foreach (array_slice($allRows, $headerRowIndex + 1) as $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $employeeNameRaw = $this->str($row[$colMap['employee']] ?? null);
            $amount = $this->toDecimal($row[$colMap['amount']] ?? null);
            if ($amount === null || $amount == 0.0) {
                continue;
            }
            $operationDate = isset($colMap['date']) ? $this->toDate($row[$colMap['date']] ?? null) : null;

            $employee = $employeeNameRaw ? $this->resolveEmployee($employeeNameRaw) : null;

            if ($restrictToEmployeeId !== null) {
                $matches = $employee?->id === $restrictToEmployeeId;
                if (!$matches) {
                    continue; // fila de otro colaborador — nunca se suma a este Objective individual
                }
            }

            if ($operationDate !== null) {
                $opDate = \Illuminate\Support\Carbon::parse($operationDate);
                if ($opDate->lt($weekStart) || $opDate->gt($weekEnd)) {
                    $rowsOutsideWeekRange++;
                }
            }

            $fingerprint = hash('sha256', implode('|', [
                $employeeNameRaw ?? '', $amount, $operationDate ?? '', $employee?->id ?? '',
            ]));
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
                'raw_payload'       => ['employee_name_raw' => $employeeNameRaw, 'amount' => $amount, 'operation_date' => $operationDate],
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
        ];
    }

    private function detectHeaderRowIndex(array $rows): ?int
    {
        $employeeAliases = ['colaborador', 'gestor', 'promotor', 'empleado', 'nombre'];
        $amountAliases   = ['colocacion', 'colocación', 'monto', 'importe', 'monto colocado', 'amount'];

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
        $aliases = [
            'employee' => ['colaborador', 'gestor', 'promotor', 'empleado', 'nombre'],
            'amount'   => ['colocacion', 'colocación', 'monto', 'importe', 'monto colocado', 'amount'],
            'date'     => ['fecha', 'fecha operacion', 'fecha de operación', 'fecha operación', 'operation_date'],
        ];

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

    private function resolveEmployee(string $name): ?Employee
    {
        return Employee::query()->where('normalized_name', $this->normalize($name))->first();
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
