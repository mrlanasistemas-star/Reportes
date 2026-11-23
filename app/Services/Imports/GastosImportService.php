<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Expense;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Support\ReportUploadStorage;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Importador genérico/heredado de gastos en Excel (fuente `gastos`).
 *
 * Acepta un reporte de gastos con encabezados variables (empleado, categoría,
 * concepto, estatus, montos, fechas, observación, justificación, sucursal).
 * Flujo: detecta el renglón de encabezados por puntaje (primeras 50 filas),
 * exige al menos las columnas Concepto y Monto, borra los gastos previos del
 * upload e inserta los renglones válidos en fact_expenses.
 * Reglas de negocio:
 * - Se omiten renglones sin concepto, con monto vacío o cero, cancelados/rechazados
 *   y renglones de total/subtotal.
 * - La sucursal sale de la columna Sucursal, o del texto "SUCURSAL X" en
 *   observación/justificación, o de la asignación del empleado en el periodo.
 * - La categoría se guarda tal como viene (no pasa por ExpenseCategoryMapper).
 * Lo invoca ReportAnalysisService; las fuentes vigentes de gastos son las
 * específicas de ERP y Lendus.
 */
class GastosImportService
{
    /**
     * @param  BranchResolverService  $branchResolver  Resuelve sucursal a partir de códigos.
     */
    public function __construct(private readonly BranchResolverService $branchResolver) {}

    /**
     * Importa el archivo del upload a fact_expenses.
     * Efectos: borra e inserta filas de fact_expenses del upload; puede crear sucursales.
     *
     * @param  ReportUpload  $upload  Archivo subido a importar.
     * @param  callable|null  $progress  Callback opcional con estadísticas parciales cada 250 filas.
     * @return array<string,mixed> Estadísticas (leídas, insertadas, omitidas, con error) y log.
     *
     * @throws \RuntimeException Si falta el archivo, está vacío, no hay encabezados o faltan Concepto/Monto.
     */
    public function handle(ReportUpload $upload, ?callable $progress = null): array
    {
        if (! $upload->stored_path) {
            throw new \RuntimeException('El archivo no tiene stored_path.');
        }

        if (! ReportUploadStorage::exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico no existe en storage/public.');
        }

        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);

        $absolutePath = ReportUploadStorage::path($upload->stored_path);
        $sheets = Excel::toArray([], $absolutePath);

        if (empty($sheets) || empty($sheets[0])) {
            throw new \RuntimeException('El archivo de gastos está vacío o no se pudo leer.');
        }

        $rows = $sheets[0];
        $headerRowIndex = $this->detectHeaderRowIndex($rows);
        $headerRow = $rows[$headerRowIndex] ?? null;

        if (! $headerRow || ! is_array($headerRow)) {
            throw new \RuntimeException('No se encontró una fila de encabezados válida en el archivo de gastos.');
        }

        $headerMap = $this->buildHeaderMap($headerRow);

        $requiredColumns = ['concept', 'amount'];

        $missingRequired = collect($requiredColumns)
            ->filter(fn (string $field) => ! array_key_exists($field, $headerMap))
            ->values()
            ->all();

        if (! empty($missingRequired)) {
            throw new \RuntimeException(
                'El archivo de gastos no contiene columnas mínimas requeridas: '
                .implode(', ', $missingRequired)
                .'. Encabezados detectados: '
                .implode(', ', array_values(array_filter(array_map(fn ($value) => trim((string) $value), $headerRow))))
            );
        }

        Expense::query()
            ->where('report_upload_id', $upload->id)
            ->delete();

        $rowsRead = 0;
        $rowsInserted = 0;
        $rowsSkipped = 0;
        $rowsWithErrors = 0;

        foreach (array_slice($rows, $headerRowIndex + 1) as $row) {
            if (! is_array($row) || $this->isEmptyRow($row)) {
                $rowsSkipped++;

                continue;
            }

            $rowsRead++;

            try {
                $mapped = $this->mapRow($row, $headerMap);

                if (! $this->shouldInsertRow($mapped)) {
                    $rowsSkipped++;

                    continue;
                }

                $employee = $this->resolveEmployee($mapped['employee_name']);

                // Sucursal: primero la columna; si no, la asignación del empleado en el periodo
                $branch = $this->resolveBranch($mapped['branch_name']);
                if (! $branch && $employee) {
                    $branch = $this->resolveBranchFromEmployee($employee->id, $upload->period_id);
                }

                Expense::query()->create([
                    'period_id' => $upload->period_id,
                    'report_upload_id' => $upload->id,
                    'employee_id' => $employee?->id,
                    'branch_id' => $branch?->id,
                    'category' => $mapped['category'],
                    'concept' => $mapped['concept'],
                    'amount' => $mapped['amount'],
                    'paid_amount' => $mapped['paid_amount'],
                    'expense_date' => $mapped['expense_date'],
                    'observations' => $mapped['observations'],
                    'raw_payload' => null,
                ]);

                $rowsInserted++;
            } catch (\Throwable) {
                $rowsWithErrors++;
            }

            if ($progress && $rowsRead % 250 === 0) {
                $progress([
                    'rows_read' => $rowsRead,
                    'rows_inserted' => $rowsInserted,
                    'rows_skipped' => $rowsSkipped,
                    'rows_with_errors' => $rowsWithErrors,
                    'log' => "Procesando gastos... {$rowsRead} filas leídas.",
                ]);
            }
        }

        return [
            'rows_read' => $rowsRead,
            'rows_inserted' => $rowsInserted,
            'rows_skipped' => $rowsSkipped,
            'rows_with_errors' => $rowsWithErrors,
            'log' => sprintf(
                'Importación de gastos finalizada. Leídas: %d, insertadas: %d, omitidas: %d, con error: %d.',
                $rowsRead,
                $rowsInserted,
                $rowsSkipped,
                $rowsWithErrors,
            ),
        ];
    }

    /**
     * Recorre las primeras 50 filas puntuando encabezados conocidos del reporte de
     * gastos; devuelve la primera con 4+ coincidencias o la de mayor puntaje.
     *
     * @param  array  $rows  Filas de la hoja.
     * @return int Índice del renglón de encabezados.
     */
    private function detectHeaderRowIndex(array $rows): int
    {
        $bestIndex = 0;
        $bestScore = -1;

        foreach (array_slice($rows, 0, 50, true) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized = array_map(
                fn ($value) => $this->normalizeHeader((string) $value),
                $row,
            );

            $score = 0;

            foreach ($normalized as $value) {
                if (in_array($value, [
                    'empleado',
                    'categoria',
                    'concepto',
                    'estatus',
                    'fecha_creacion',
                    'monto_gasto',
                    'monto_pagado_empleado',
                    'monto_pagado_empresa',
                    'fecha_de_autorizacion',
                    'monto_aplicado_en_caja',
                    'autorizado_por',
                    'observacion',
                    'justificacion',
                    'monto',
                    'importe',
                    'total',
                    'sucursal',
                    'oficina',
                ], true)) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = (int) $index;
            }

            if ($score >= 4) {
                return (int) $index;
            }
        }

        return $bestIndex;
    }

    /**
     * Mapea cada campo lógico (empleado, sucursal, categoría, concepto, estatus,
     * montos, fechas, autorizado por, observaciones, justificación) a la primera
     * columna cuyo encabezado normalizado coincide con un alias.
     *
     * @param  array  $headerRow  Celdas del renglón de encabezados.
     * @return array<string,int> Campo => índice de columna.
     */
    private function buildHeaderMap(array $headerRow): array
    {
        $aliases = [
            'employee_name' => [
                'employee_name',
                'empleado',
                'nombre_empleado',
                'nombre',
                'colaborador',
                'responsable',
            ],
            'branch_name' => [
                'branch_name',
                'sucursal',
                'oficina',
                'branch',
                'unidad',
                'ruta',
            ],
            'category' => [
                'category',
                'categoria',
                'tipo_gasto',
                'rubro',
            ],
            'concept' => [
                'concept',
                'concepto',
                'descripcion',
                'detalle',
                'gasto',
            ],
            'status' => [
                'status',
                'estatus',
                'estado',
            ],
            'amount' => [
                'amount',
                'importe',
                'monto',
                'total',
                'valor',
                'monto_gasto',
                'importe_gasto',
            ],
            'paid_amount' => [
                'paid_amount',
                'monto_pagado',
                'pagado',
                'importe_pagado',
                'monto_pagado_empleado',
                'monto_pagado_empresa',
                'monto_aplicado_en_caja',
            ],
            'expense_date' => [
                'expense_date',
                'fecha_gasto',
                'fecha',
                'fecha_pago',
                'fecha_creacion',
                'fecha_de_creacion',
            ],
            'authorization_date' => [
                'authorization_date',
                'fecha_autorizacion',
                'fecha_de_autorizacion',
            ],
            'authorized_by' => [
                'authorized_by',
                'autorizado_por',
            ],
            'observations' => [
                'observations',
                'observacion',
                'observaciones',
                'comentarios',
                'notas',
            ],
            'justification' => [
                'justification',
                'justificacion',
            ],
        ];

        $normalizedHeaders = [];

        foreach ($headerRow as $index => $value) {
            $normalizedHeaders[$index] = $this->normalizeHeader((string) $value);
        }

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
     * Extrae los campos del renglón, deduce la sucursal desde observación o
     * justificación si no hay columna, y arma las observaciones combinadas
     * (observación, justificación, autorizado por, fecha de autorización, estatus).
     *
     * @param  array  $row  Fila de datos.
     * @param  array  $headerMap  Mapa campo => índice.
     * @return array<string,mixed> Renglón normalizado listo para validar e insertar.
     */
    private function mapRow(array $row, array $headerMap): array
    {
        $employeeName = $this->cleanString($this->valueFromRow($row, $headerMap, 'employee_name'));
        $branchName = $this->cleanString($this->valueFromRow($row, $headerMap, 'branch_name'));
        $category = $this->cleanString($this->valueFromRow($row, $headerMap, 'category'));
        $concept = $this->cleanString($this->valueFromRow($row, $headerMap, 'concept'));
        $status = $this->cleanString($this->valueFromRow($row, $headerMap, 'status'));
        $amount = $this->toDecimal($this->valueFromRow($row, $headerMap, 'amount'));
        $paidAmount = $this->toDecimal($this->valueFromRow($row, $headerMap, 'paid_amount'));
        $expenseDate = $this->toDateValue($this->valueFromRow($row, $headerMap, 'expense_date'));
        $authorizationDate = $this->toDateValue($this->valueFromRow($row, $headerMap, 'authorization_date'));
        $authorizedBy = $this->cleanString($this->valueFromRow($row, $headerMap, 'authorized_by'));
        $observations = $this->cleanString($this->valueFromRow($row, $headerMap, 'observations'));
        $justification = $this->cleanString($this->valueFromRow($row, $headerMap, 'justification'));

        if (! $branchName) {
            $branchName = $this->extractBranchFromText($observations)
                ?? $this->extractBranchFromText($justification);
        }

        $notes = collect([
            $observations,
            $justification ? 'Justificación: '.$justification : null,
            $authorizedBy ? 'Autorizado por: '.$authorizedBy : null,
            $authorizationDate ? 'Fecha autorización: '.$authorizationDate : null,
            $status ? 'Estatus: '.$status : null,
        ])
            ->filter()
            ->implode(' | ');

        return [
            'employee_name' => $employeeName,
            'branch_name' => $branchName,
            'category' => $category,
            'concept' => $concept,
            'status' => $status,
            'amount' => $amount,
            'paid_amount' => $paidAmount,
            'expense_date' => $expenseDate,
            'observations' => $notes ?: null,
            'raw_payload' => $row,
        ];
    }

    /**
     * Indica si el renglón debe importarse: requiere concepto y monto distinto de
     * cero; descarta estatus cancelado/rechazado y renglones de total/subtotal.
     *
     * @param  array  $mapped  Renglón devuelto por mapRow().
     * @return bool true si se inserta.
     */
    private function shouldInsertRow(array $mapped): bool
    {
        if (! $mapped['concept']) {
            return false;
        }

        if ($mapped['amount'] === null) {
            return false;
        }

        if ((float) $mapped['amount'] == 0.0) {
            return false;
        }

        $status = $this->normalizeName((string) ($mapped['status'] ?? ''));

        if (in_array($status, ['cancelado', 'cancelada', 'rechazado', 'rechazada'], true)) {
            return false;
        }

        $concept = $this->normalizeName((string) $mapped['concept']);

        if (in_array($concept, ['total', 'subtotal', 'totales'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Sucursal asignada al empleado en el periodo (employee_branch_assignments).
     *
     * @param  int  $employeeId  Empleado del gasto.
     * @param  int  $periodId  Periodo del upload.
     * @return Branch|null Sucursal asignada o null.
     */
    private function resolveBranchFromEmployee(int $employeeId, int $periodId): ?Branch
    {
        $assignment = EmployeeBranchAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('period_id', $periodId)
            ->whereNotNull('branch_id')
            ->first();

        if ($assignment?->branch_id) {
            return Branch::query()->find($assignment->branch_id);
        }

        return null;
    }

    /**
     * Busca el empleado por nombre normalizado (coincidencia exacta).
     *
     * @param  string|null  $fullName  Nombre del empleado.
     * @return Employee|null Empleado o null.
     */
    private function resolveEmployee(?string $fullName): ?Employee
    {
        if (! $fullName) {
            return null;
        }

        $normalizedName = $this->normalizeName($fullName);

        return Employee::query()
            ->where('normalized_name', $normalizedName)
            ->first();
    }

    /**
     * Busca la sucursal por nombre normalizado o nombre exacto; si no existe, la
     * interpreta como código (p. ej. "ORI" u "ORI09247") y la busca o crea.
     *
     * @param  string|null  $branchName  Texto de sucursal.
     * @return Branch|null Sucursal o null.
     */
    private function resolveBranch(?string $branchName): ?Branch
    {
        if (! $branchName) {
            return null;
        }

        // Primero busca por nombre (p. ej. "ORIZABA" ya puede ser el nombre de la sucursal)
        $normalizedName = $this->normalizeName($branchName);

        $existing = Branch::query()
            ->where('normalized_name', $normalizedName)
            ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($branchName)])
            ->first();

        if ($existing) {
            return $existing;
        }

        // Luego BranchResolverService (por si el valor es un código como "ORI" u "ORI09247")
        return $this->branchResolver->findOrCreateBranchByCode($branchName);
    }

    /**
     * Extrae el texto que sigue a "SUCURSAL" (incluye errores de captura comunes como SUCUSAL/SUCURSLA).
     *
     * @param  string|null  $value  Observación o justificación.
     * @return string|null Nombre candidato de sucursal o null.
     */
    private function extractBranchFromText(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $clean = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        if ($clean === '') {
            return null;
        }

        if (preg_match('/\b(?:SUCURSAL|SUCUSAL|SUCURSLA|SUCURSLA\.?)\s+(.+)$/iu', $clean, $matches)) {
            return $this->cleanBranchCandidate($matches[1] ?? null);
        }

        return null;
    }

    /**
     * Limpia un nombre candidato: espacios simples, solo letras/dígitos/guiones/puntos y sin puntuación en los extremos.
     *
     * @param  string|null  $value  Texto candidato.
     * @return string|null Texto limpio o null si queda vacío.
     */
    private function cleanBranchCandidate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = preg_replace('/[^A-ZÁÉÍÓÚÜÑ0-9\s\-\._]/iu', '', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B.-_");

        return $value !== '' ? $value : null;
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

        $index = $headerMap[$field];

        return $row[$index] ?? null;
    }

    /**
     * Normaliza un encabezado a formato snake (minúsculas, sin acentos, no alfanuméricos → '_').
     *
     * @param  string  $value  Texto del encabezado.
     * @return string Encabezado normalizado.
     */
    private function normalizeHeader(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $value,
        );

        $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? $value;
        $value = trim($value, '_');

        return $value;
    }

    /**
     * Minúsculas, sin acentos/ñ y espacios simples.
     *
     * @param  string  $value  Texto original.
     * @return string Texto normalizado.
     */
    private function normalizeName(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $value,
        );

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Convierte un monto (número o texto con $, comas o espacios) a float con 2 decimales.
     *
     * @param  mixed  $value  Valor de celda.
     * @return float|null Monto o null si no es numérico.
     */
    private function toDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        $normalized = str_replace(['$', ',', ' '], '', (string) $value);

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }

    /**
     * Convierte una fecha de Excel (número de serie) o texto a 'Y-m-d'.
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
                $date = Date::excelToDateTimeObject((float) $value);

                return $date->format('Y-m-d');
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
     * @param  mixed  $value  Valor de celda.
     * @return string|null Texto recortado o null si queda vacío.
     */
    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
