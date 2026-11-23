<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Support\ExpenseCategoryMapper;
use App\Support\ExpenseStatusNormalizer;
use App\Support\ReportUploadStorage;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Importador del reporte de requisiciones/gastos del ERP en Excel (fuente `gastos_erp`).
 *
 * Flujo: localiza el renglón de encabezados (celda "Folio" en las primeras 20
 * filas), mapea columnas por alias (con posiciones fijas de respaldo), borra los
 * gastos previos del upload y recorre las filas:
 * - Omite filas vacías y de continuación (sin folio: el total ya está en la fila principal).
 * - Omite estatus no facturables (ExpenseStatusNormalizer::isBillable).
 * - Omite totales en cero o vacíos.
 * - Si existe la columna "fecha de pago", solo acepta pagos dentro del periodo;
 *   si no existe, usa la fecha de captura sin filtrar por fecha.
 * - Resuelve sucursal (CORPORATIVO es sucursal real; rutas se convierten a la
 *   sucursal oficial) y empleado solicitante por nombre normalizado.
 * - Clasifica la categoría con ExpenseCategoryMapper y guarda en fact_expenses.
 * Lo invoca ReportAnalysisService al procesar un upload de esta fuente.
 */
class GastosErpExcelImportService
{
    /**
     * @param  BranchResolverService  $branchResolver  Resuelve/crea sucursales oficiales a partir de nombres o rutas.
     */
    public function __construct(private readonly BranchResolverService $branchResolver) {}

    /**
     * Importa el Excel del upload a fact_expenses (amount = paid_amount = total final de la requisición).
     * Efectos: borra e inserta filas de fact_expenses del upload; puede crear sucursales
     * (findOrCreateBranchByName). Errores por fila se cuentan y no detienen la importación.
     *
     * @param  ReportUpload  $upload  Archivo subido a importar (aporta periodo y ruta).
     * @param  callable|null  $progress  Callback opcional que recibe estadísticas parciales cada 100 filas.
     * @return array<string,mixed> Estadísticas: leídas, insertadas, omitidas (por estatus/fecha/otras), errores, conteo por estatus, si usó fecha de pago y log.
     *
     * @throws \RuntimeException Si falta el archivo o no se encuentra el renglón de encabezados.
     */
    public function handle(ReportUpload $upload, ?callable $progress = null): array
    {
        if (! $upload->stored_path) {
            throw new \RuntimeException('El archivo no tiene stored_path.');
        }

        if (! ReportUploadStorage::exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico no existe en storage/public.');
        }

        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);

        $absolutePath = ReportUploadStorage::path($upload->stored_path);

        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($absolutePath);
        $sheet = $spreadsheet->getActiveSheet();

        $allRows = $sheet->toArray(null, true, true, false);

        // Busca el renglón de encabezados (el que contiene la celda "Folio")
        $headerRowIndex = $this->detectHeaderRowIndex($allRows);
        if ($headerRowIndex === null) {
            throw new \RuntimeException('No se encontró la fila de encabezados en el archivo ERP (buscando "Folio").');
        }

        $headerRow = $allRows[$headerRowIndex];
        $colMap = $this->buildColumnMap($headerRow);

        Expense::query()->where('report_upload_id', $upload->id)->delete();

        // Filtro por fecha de pago: solo se incluyen filas cuya fecha_pago cae dentro del periodo.
        // Si el archivo ERP no tiene columna fecha_pago, se usa fecha_captura (sin filtro de fecha).
        $period = $upload->period;
        $hasFechaPago = isset($colMap['fecha_pago']);
        $periodStart = $hasFechaPago && $period?->start_date ? $period->start_date->format('Y-m-d') : null;
        $periodEnd = $hasFechaPago && $period?->end_date ? $period->end_date->format('Y-m-d') : null;

        $rowsRead = 0;
        $rowsInserted = 0;
        $rowsSkipped = 0;
        $rowsSkippedByStatus = 0;
        $rowsSkippedByDate = 0;
        $rowsErrors = 0;
        $statusCounts = [];

        foreach (array_slice($allRows, $headerRowIndex + 1) as $row) {
            if ($this->isEmptyRow($row)) {
                $rowsSkipped++;

                continue;
            }

            $folio = $this->str($row[$colMap['folio']] ?? null);

            // Fila de continuación (sin folio) — se omite, el total ya está en la fila principal
            if (! $folio) {
                $rowsSkipped++;

                continue;
            }

            $rowsRead++;

            try {
                $rawStatus = $this->str($row[$colMap['estatus']] ?? null);
                $normStatus = $rawStatus ? ExpenseStatusNormalizer::normalize($rawStatus) : 'SIN ESTATUS';

                $statusCounts[$normStatus] = ($statusCounts[$normStatus] ?? 0) + 1;

                if ($rawStatus !== null && ! ExpenseStatusNormalizer::isBillable($rawStatus)) {
                    $rowsSkippedByStatus++;

                    continue;
                }

                $sucursal = $this->str($row[$colMap['sucursal']] ?? null);
                $solicitante = $this->str($row[$colMap['solicitante']] ?? null);
                $concepto = $this->str($row[$colMap['concepto']] ?? null);
                $observ = $this->str($row[$colMap['observaciones']] ?? null);
                $fechaCapturaRaw = $row[$colMap['fecha_captura']] ?? null;
                $fechaPagoRaw = $hasFechaPago ? ($row[$colMap['fecha_pago']] ?? null) : null;
                $total = $this->toDecimal($row[$colMap['total_final']] ?? null);

                // Omite filas con total en cero o vacío
                if ($total === null || $total == 0.0) {
                    $rowsSkipped++;

                    continue;
                }

                // Fecha del gasto: se prefiere fecha_pago; si no hay, fecha_captura
                $fechaPago = $hasFechaPago ? $this->toDate($fechaPagoRaw) : null;
                $fechaCaptura = $this->toDate($fechaCapturaRaw);
                $primaryDate = $fechaPago ?? $fechaCaptura;

                // Filtra por fecha de pago cuando existe la columna fecha_pago
                if ($hasFechaPago && $periodStart && $periodEnd) {
                    if (! $fechaPago || $fechaPago < $periodStart || $fechaPago > $periodEnd) {
                        $rowsSkippedByDate++;

                        continue;
                    }
                }

                $branch = $this->resolveBranch($sucursal);
                $employee = $this->resolveEmployee($solicitante);

                $notes = collect([$observ, 'Folio: '.$folio])
                    ->filter()
                    ->implode(' | ');

                Expense::query()->create([
                    'period_id' => $upload->period_id,
                    'report_upload_id' => $upload->id,
                    'employee_id' => $employee?->id,
                    'branch_id' => $branch?->id,
                    'category' => ExpenseCategoryMapper::fromFields($concepto, $observ),
                    'concept' => $concepto ?? 'Sin concepto',
                    'amount' => $total,
                    'paid_amount' => $total,
                    'expense_date' => $primaryDate,
                    'observations' => $notes ?: null,
                    'raw_payload' => [
                        'estatus' => $rawStatus,
                        'folio' => $folio,
                        'sucursal_raw' => $sucursal,
                        'fecha_pago' => $fechaPago,
                        'fecha_captura' => $fechaCaptura,
                    ],
                ]);

                $rowsInserted++;
            } catch (\Throwable) {
                $rowsErrors++;
            }

            if ($progress && $rowsRead % 100 === 0) {
                $progress([
                    'rows_read' => $rowsRead,
                    'rows_inserted' => $rowsInserted,
                    'rows_skipped' => $rowsSkipped,
                    'rows_skipped_by_status' => $rowsSkippedByStatus,
                    'rows_skipped_by_date' => $rowsSkippedByDate,
                    'rows_with_errors' => $rowsErrors,
                    'log' => "Gastos ERP: {$rowsRead} requisiciones leídas…",
                ]);
            }
        }

        return [
            'rows_read' => $rowsRead,
            'rows_inserted' => $rowsInserted,
            'rows_skipped' => $rowsSkipped,
            'rows_skipped_by_status' => $rowsSkippedByStatus,
            'rows_skipped_by_date' => $rowsSkippedByDate,
            'rows_with_errors' => $rowsErrors,
            'status_counts' => $statusCounts,
            'uses_fecha_pago' => $hasFechaPago,
            'log' => sprintf(
                'Gastos ERP importados. Leídas: %d, contabilizadas: %d, ignoradas por estatus: %d, ignoradas por fecha pago: %d, omitidas: %d, errores: %d.',
                $rowsRead, $rowsInserted, $rowsSkippedByStatus, $rowsSkippedByDate, $rowsSkipped, $rowsErrors
            ),
        ];
    }

    /**
     * Busca, en las primeras 20 filas, la que contiene una celda "Folio".
     *
     * @param  array  $rows  Todas las filas de la hoja.
     * @return int|null Índice del renglón de encabezados o null si no se encontró.
     */
    private function detectHeaderRowIndex(array $rows): ?int
    {
        foreach (array_slice($rows, 0, 20, true) as $index => $row) {
            foreach ($row as $cell) {
                if (strtolower(trim((string) $cell)) === 'folio') {
                    return (int) $index;
                }
            }
        }

        return null;
    }

    /**
     * Mapea campos lógicos (folio, estatus, sucursal, solicitante, concepto,
     * observaciones, fechas, total final) a índices de columna por coincidencia exacta
     * de alias. Si faltan columnas clave usa posiciones fijas del formato conocido
     * del ERP (p. ej. total final = columna Z).
     *
     * @param  array  $headerRow  Celdas del renglón de encabezados.
     * @return array<string,int> Campo => índice de columna.
     */
    private function buildColumnMap(array $headerRow): array
    {
        $aliases = [
            'folio' => ['folio'],
            'estatus' => ['estatus', 'estado', 'status'],
            'sucursal' => ['sucursal', 'oficina', 'unidad'],
            'codigo_sucursal' => ['código sucursal', 'codigo sucursal', 'código sucursal'],
            'solicitante' => ['solicitante', 'empleado', 'responsable'],
            'concepto' => ['concepto'],
            'observaciones' => ['observaciones', 'observación', 'observacion'],
            'fecha_pago' => ['fecha pago', 'fecha de pago', 'fecha_pago', 'f. pago', 'fecha de pago real'],
            'fecha_captura' => ['fecha captura', 'fecha de captura', 'fecha_captura', 'fecha registro'],
            'total_final' => ['total final requisición', 'total final requisicion', 'total final', 'monto pagado empresa', 'monto pagado por empresa'],
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

        // Respaldo: posición fija conocida si no se detectó el encabezado
        if (! isset($map['folio'])) {
            $map['folio'] = 0;
        }
        if (! isset($map['sucursal'])) {
            $map['sucursal'] = 4;
        }
        if (! isset($map['solicitante'])) {
            $map['solicitante'] = 6;
        }
        if (! isset($map['concepto'])) {
            $map['concepto'] = 9;
        }
        if (! isset($map['observaciones'])) {
            $map['observaciones'] = 10;
        }
        if (! isset($map['fecha_captura'])) {
            $map['fecha_captura'] = 11;
        }
        if (! isset($map['total_final'])) {
            $map['total_final'] = 25;
        } // Column Z (0-indexed)

        return $map;
    }

    /**
     * Resuelve la sucursal del gasto: CORPORATIVO/CORP se busca o crea como sucursal
     * real; luego busca por nombre o nombre normalizado; si no existe, intenta
     * convertir una ruta a su sucursal oficial y la busca o crea.
     *
     * @param  string|null  $name  Texto de la columna Sucursal.
     * @return Branch|null Sucursal encontrada/creada o null.
     */
    private function resolveBranch(?string $name): ?Branch
    {
        if (! $name) {
            return null;
        }

        $norm = mb_strtolower(trim($name));

        // CORPORATIVO: se busca/crea como sucursal real (no null)
        if (in_array($norm, ['corporativo', 'corp', 'corporate'], true)) {
            return $this->branchResolver->findOrCreateBranchByName('CORPORATIVO');
        }

        $branch = Branch::query()
            ->whereRaw('LOWER(name) = ?', [$norm])
            ->orWhereRaw('LOWER(normalized_name) = ?', [$this->normalize($name)])
            ->first();

        // Convierte ruta → sucursal oficial antes de crear un registro nuevo.
        $officialName = $this->branchResolver->resolveRealBranchFromRoute($name);

        return $branch ?? ($officialName ? $this->branchResolver->findOrCreateBranchByName($officialName) : null);
    }

    /**
     * Busca el empleado solicitante por nombre normalizado (coincidencia exacta).
     *
     * @param  string|null  $name  Nombre del solicitante.
     * @return Employee|null Empleado o null si no se encontró.
     */
    private function resolveEmployee(?string $name): ?Employee
    {
        if (! $name) {
            return null;
        }

        return Employee::query()
            ->where('normalized_name', $this->normalize($name))
            ->first();
    }

    /**
     * Minúsculas, sin acentos/ñ y espacios simples (para comparar nombres).
     *
     * @param  string  $value  Texto original.
     * @return string Texto normalizado.
     */
    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return preg_replace('/\s+/', ' ', $value) ?? $value;
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

        $clean = str_replace(['$', ',', ' '], '', (string) $value);

        return is_numeric($clean) ? round((float) $clean, 2) : null;
    }

    /**
     * Convierte una fecha de Excel (número de serie) o texto a 'Y-m-d'.
     *
     * @param  mixed  $value  Valor de celda.
     * @return string|null Fecha ISO o null si no se puede interpretar.
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
     * @param  mixed  $value  Valor de celda.
     * @return string|null Texto recortado o null si queda vacío.
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
     * @param  array  $row  Fila leída.
     * @return bool true si todas las celdas están vacías.
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
