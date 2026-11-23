<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Support\ExpenseCategoryMapper;
use App\Support\ReportUploadStorage;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Importador del reporte de gastos de Lendus en Excel (fuente `gastos_lendus_excel`).
 *
 * Complementa al PDF de Lendus (fuente autorizada de OPEX): de este Excel se
 * obtiene el desglose informativo por sucursal/colaborador (observación,
 * justificación, solicitante, folio, proveedor) y la detección de préstamos
 * intersucursales con su sucursal destino.
 * Flujo: detecta el renglón de encabezados (2+ anclas en las primeras 20 filas),
 * mapea columnas por alias (con posiciones fijas del formato real de Lendus),
 * borra los gastos previos del upload e inserta una fila por gasto con monto > 0.
 * Reglas de negocio:
 * - La sucursal origen (columna Sucursal) se convierte a sucursal REAL oficial.
 * - Si el texto (categoría/concepto/observación/justificación) indica fondeo,
 *   préstamo o depósito a otra sucursal, la categoría es "Préstamos Intersucursales"
 *   y se guarda la sucursal destino detectada; si no, se clasifica con ExpenseCategoryMapper.
 * - employee_id queda null (la atribución a colaborador se resuelve después).
 * Lo invoca ReportAnalysisService al procesar un upload de esta fuente.
 */
class GastosLendusExcelImportService
{
    /**
     * @param  BranchResolverService  $branchResolver  Convierte rutas/texto a sucursal oficial y crea sucursales.
     */
    public function __construct(private readonly BranchResolverService $branchResolver) {}

    /**
     * Importa el Excel del upload a fact_expenses (amount = paid_amount = monto).
     * Efectos: borra e inserta filas de fact_expenses del upload; puede crear sucursales.
     *
     * @param  ReportUpload  $upload  Archivo subido a importar.
     * @param  callable|null  $progress  Callback opcional con estadísticas parciales cada 100 filas.
     * @return array<string,mixed> Estadísticas (leídas, insertadas, omitidas, errores) y log.
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

        $headerRowIndex = $this->detectHeaderRowIndex($allRows);
        if ($headerRowIndex === null) {
            throw new \RuntimeException('No se encontró la fila de encabezados en el Excel Gastos Lendus.');
        }

        $colMap = $this->buildColumnMap($allRows[$headerRowIndex]);

        Expense::query()->where('report_upload_id', $upload->id)->delete();

        $rowsRead = 0;
        $rowsInserted = 0;
        $rowsSkipped = 0;
        $rowsErrors = 0;
        $branchCache = [];

        foreach (array_slice($allRows, $headerRowIndex + 1) as $row) {
            if ($this->isEmptyRow($row)) {
                $rowsSkipped++;

                continue;
            }

            $amount = $this->toDecimal($this->col($row, $colMap['monto']));
            if ($amount === null || $amount <= 0.0) {
                $rowsSkipped++;

                continue;
            }

            $rowsRead++;

            try {
                $sucursal = $this->str($this->col($row, $colMap['sucursal']));
                $categoria = $this->str($this->col($row, $colMap['categoria']));
                $concepto = $this->str($this->col($row, $colMap['concepto']));
                $observacion = $this->str($this->col($row, $colMap['observacion']));
                $justificacion = $this->str($this->col($row, $colMap['justificacion']));
                $folio = $this->str($this->col($row, $colMap['folio']));
                $proveedor = $this->str($this->col($row, $colMap['proveedor']));
                $solicitante = $this->str($this->col($row, $colMap['solicitante']));
                $fechaStr = $this->col($row, $colMap['fecha']);
                $date = $this->toDate($fechaStr);

                // Sucursal origen (columna Sucursal) — debe ser una sucursal real
                $fromBranchName = $sucursal ? $this->resolveRealBranchName($sucursal) : null;
                $fromBranchKey = $fromBranchName ?? ($sucursal ?? '');
                $branch = array_key_exists($fromBranchKey, $branchCache)
                    ? $branchCache[$fromBranchKey]
                    : ($branchCache[$fromBranchKey] = $fromBranchName
                        ? $this->findOrCreateBranch($fromBranchName)
                        : null);

                // Detecta sucursal destino intersucursal en los campos de texto combinados
                $searchText = implode(' ', array_filter([$categoria, $concepto, $observacion, $justificacion]));
                $toBranchDetected = $this->detectToBranch($searchText);

                $isIntersucursal = $toBranchDetected !== null
                    || $this->looksLikeIntersucursal($searchText);

                $category = $isIntersucursal
                    ? 'Préstamos Intersucursales'
                    : ExpenseCategoryMapper::fromFields($concepto, $categoria, $observacion);

                $observationParts = array_filter([$observacion, $justificacion]);
                $observations = $observationParts ? implode(' | ', $observationParts) : null;

                $rawPayload = array_filter([
                    'branch_to_detected' => $toBranchDetected,
                    'folio' => $folio,
                    'proveedor' => $proveedor,
                    'solicitante' => $solicitante,
                ]);

                Expense::query()->create([
                    'period_id' => $upload->period_id,
                    'report_upload_id' => $upload->id,
                    'employee_id' => null,
                    'branch_id' => $branch?->id,
                    'category' => $category,
                    'concept' => $concepto ?? 'Sin concepto',
                    'amount' => $amount,
                    'paid_amount' => $amount,
                    'expense_date' => $date,
                    'observations' => $observations,
                    'raw_payload' => $rawPayload ?: null,
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
                    'rows_with_errors' => $rowsErrors,
                    'log' => "Gastos Lendus Excel: {$rowsRead} filas leídas…",
                ]);
            }
        }

        return [
            'rows_read' => $rowsRead,
            'rows_inserted' => $rowsInserted,
            'rows_skipped' => $rowsSkipped,
            'rows_with_errors' => $rowsErrors,
            'log' => sprintf(
                'Gastos Lendus Excel importados. Leídas: %d, insertadas: %d, omitidas: %d, errores: %d.',
                $rowsRead, $rowsInserted, $rowsSkipped, $rowsErrors
            ),
        ];
    }

    /**
     * Devuelve la primera de las 20 filas iniciales con al menos 2 celdas que
     * contienen palabras ancla (fecha, monto, folio, sucursal, concepto...).
     *
     * @param  array  $rows  Filas de la hoja.
     * @return int|null Índice del renglón de encabezados o null.
     */
    private function detectHeaderRowIndex(array $rows): ?int
    {
        $anchors = ['fecha', 'monto', 'folio', 'total', 'sucursal', 'concepto', 'empleado', 'estatus', 'observac', 'justific'];
        foreach (array_slice($rows, 0, 20, true) as $index => $row) {
            $hits = 0;
            foreach ($row as $cell) {
                $norm = strtr(mb_strtolower(trim((string) $cell)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
                foreach ($anchors as $anchor) {
                    if (str_contains($norm, $anchor)) {
                        $hits++;
                        break;
                    }
                }
            }
            if ($hits >= 2) {
                return (int) $index;
            }
        }

        return null;
    }

    /**
     * Mapea campos lógicos a columnas por coincidencia exacta de alias; si faltan,
     * usa las posiciones del formato real de Lendus (empleado 0, categoría 1,
     * concepto 2, fecha 4, monto pagado empresa 7, observación 11, justificación 12).
     * Sucursal, folio y proveedor quedan null si no hay encabezado.
     *
     * @param  array  $headerRow  Celdas del renglón de encabezados.
     * @return array<string,int|null> Campo => índice de columna (o null).
     */
    private function buildColumnMap(array $headerRow): array
    {
        // Distribución real de columnas del archivo de Lendus:
        // Col 0: Empleado | Col 1: Categoría | Col 2: Concepto | Col 3: Estatus
        // Col 4: Fecha Creación | Col 5: Monto Gasto | Col 6: Monto pagado empleado
        // Col 7: Monto pagado empresa | Col 11: Observación | Col 12: Justificación
        $aliases = [
            'solicitante' => ['solicitante', 'empleado', 'responsable', 'usuario'],
            'categoria' => ['categoria', 'categoría', 'tipo'],
            'concepto' => ['concepto', 'descripcion', 'descripción', 'motivo'],
            'estatus' => ['estatus', 'estado', 'status'],
            'fecha' => ['fecha creacion', 'fecha creación', 'fecha_creacion', 'fecha de creacion', 'fecha de creación', 'fecha gasto', 'fecha de gasto', 'fecha'],
            'monto' => ['monto aplicado en caja', 'monto pagado empresa', 'monto pagado por empresa', 'monto gasto', 'monto total', 'monto', 'total', 'importe'],
            'sucursal' => ['sucursal', 'oficina', 'unidad', 'sucursal origen'],
            'observacion' => ['observacion', 'observación', 'observaciones', 'obs'],
            'justificacion' => ['justificacion', 'justificación', 'justificaciones', 'justif'],
            'folio' => ['folio', 'id', 'num', 'número', 'numero'],
            'proveedor' => ['proveedor', 'empresa', 'beneficiario'],
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

        if (! isset($map['solicitante'])) {
            $map['solicitante'] = 0;
        }
        if (! isset($map['categoria'])) {
            $map['categoria'] = 1;
        }
        if (! isset($map['concepto'])) {
            $map['concepto'] = 2;
        }
        if (! isset($map['fecha'])) {
            $map['fecha'] = 4;
        }
        if (! isset($map['monto'])) {
            $map['monto'] = 7;
        }
        if (! isset($map['observacion'])) {
            $map['observacion'] = 11;
        }
        if (! isset($map['justificacion'])) {
            $map['justificacion'] = 12;
        }
        if (! isset($map['sucursal'])) {
            $map['sucursal'] = null;
        }
        if (! isset($map['folio'])) {
            $map['folio'] = null;
        }
        if (! isset($map['proveedor'])) {
            $map['proveedor'] = null;
        }

        return $map;
    }

    /**
     * Intenta extraer de texto libre el nombre de la sucursal destino de un préstamo.
     * Divide el texto por "|" (p. ej. "FONDEO | A CUERNAVACA") y evalúa cada segmento.
     *
     * @param  string  $text  Texto combinado de categoría, concepto, observación y justificación.
     * @return string|null Nombre real de la sucursal destino o null.
     */
    private function detectToBranch(string $text): ?string
    {
        if (empty($text)) {
            return null;
        }

        // Divide por "|" y procesa cada segmento; cubre "FONDEO | A CUERNAVACA"
        $segments = array_map('trim', explode('|', $text));
        foreach ($segments as $segment) {
            $result = $this->detectToBranchSegment($segment);
            if ($result) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Aplica patrones (FONDEO A/PARA SUC..., PRÉSTAMO..., INTERSUCURSAL..., CRÉDITO A...,
     * DEPÓSITO..., APOYO A...) tomando la ÚLTIMA coincidencia, limpia palabras de
     * ruido y la convierte a sucursal oficial; como respaldo intenta con el segmento completo.
     *
     * @param  string  $text  Segmento de texto.
     * @return string|null Nombre real de la sucursal o null.
     */
    private function detectToBranchSegment(string $text): ?string
    {
        if (empty(trim($text))) {
            return null;
        }

        $upper = mb_strtoupper(trim($text));

        $patterns = [
            '/FOND(?:EA|EO)\s+(?:A|PARA|DE)\s+SUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/FOND(?:EA|EO)\s+(?:A|PARA|DE)\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/FOND(?:EA|EO)\s+SUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/FOND(?:EA|EO)\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/PR[EÉ]STAMO\s+(?:A|PARA|INTER)?\s*SUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/PR[EÉ]STAMO\s+(?:A|PARA|INTER)?\s*([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/INTERSUCURSAL\s+(?:A|PARA)?\s*SUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/INTERSUCURSAL\s+(?:A|PARA)?\s*([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/CR[EÉ]DITO\s+(?:A|PARA)\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/(?:A|PARA)\s+SUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/\bSUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/DEP[OÓ]SITO\s+(?:A|PARA)?\s*([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/APOYO\s+(?:A|PARA)\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $upper, $matches)) {
                // Toma la ÚLTIMA coincidencia para cubrir el doble prefijo "FONDEO FONDEO XXXX"
                $lastIdx = count($matches[1]) - 1;
                $candidate = $this->stripBranchNoise(trim($matches[1][$lastIdx]));
                if (mb_strlen($candidate) < 3) {
                    continue;
                }
                $resolved = $this->branchResolver->resolveRealBranchFromRoute($candidate);
                if ($resolved) {
                    return $resolved;
                }
            }
        }

        // Respaldo: quita ruido del segmento completo y lo resuelve directamente.
        // Cubre "SAN JUAN DEL RIO", "SUCURSAL CORDOBA", "A SUC TENANGO", etc.
        $stripped = $this->stripBranchNoise($upper);
        if (mb_strlen($stripped) >= 3) {
            $resolved = $this->branchResolver->resolveRealBranchFromRoute($stripped);
            if ($resolved) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Quita repetidamente palabras de ruido al inicio (FONDEO, DEPÓSITO, A, PARA, DE, SUC, SUCURSAL).
     *
     * @param  string  $candidate  Texto candidato a nombre de sucursal.
     * @return string Texto en mayúsculas sin el ruido inicial.
     */
    private function stripBranchNoise(string $candidate): string
    {
        static $noiseLeading = ['FONDEO', 'FONDEA', 'DEPOSITO', 'DEPÓSITO', 'A', 'PARA', 'DE', 'SUC', 'SUCURSAL'];
        $c = mb_strtoupper(trim($candidate));
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($noiseLeading as $noise) {
                if (str_starts_with($c, $noise.' ')) {
                    $c = trim(mb_substr($c, mb_strlen($noise)));
                    $changed = true;
                    break;
                }
            }
        }

        return $c;
    }

    /**
     * Indica si el texto contiene palabras clave de préstamo entre sucursales
     * (FONDEO, FONDEA, INTERSUCURSAL, PRÉSTAMO SUCURSAL) aunque no se detecte destino.
     *
     * @param  string  $text  Texto combinado del gasto.
     * @return bool true si parece préstamo intersucursal.
     */
    private function looksLikeIntersucursal(string $text): bool
    {
        if (empty($text)) {
            return false;
        }

        $upper = mb_strtoupper($text);

        $keywords = ['FONDEO', 'FONDEA', 'INTERSUCURSAL', 'INTER-SUCURSAL',
            'PRESTAMO SUCURSAL', 'PRÉSTAMO SUCURSAL'];

        foreach ($keywords as $kw) {
            if (str_contains($upper, $kw)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string  $name  Nombre de sucursal/ruta.
     * @return string|null Nombre de la sucursal oficial o null.
     */
    private function resolveRealBranchName(string $name): ?string
    {
        return $this->branchResolver->resolveRealBranchFromRoute($name);
    }

    /**
     * Efecto secundario: puede crear la sucursal en `branches`.
     *
     * @param  string  $name  Nombre oficial de la sucursal.
     * @return Branch|null Sucursal encontrada o creada.
     */
    private function findOrCreateBranch(string $name): ?Branch
    {
        return $this->branchResolver->findOrCreateBranchByName($name);
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
     * @return string|null Fecha ISO o null.
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
     * @param  array  $row  Fila de datos.
     * @param  int|null  $idx  Índice de columna (null si la columna no existe).
     * @return mixed Valor de la celda o null.
     */
    private function col(array $row, ?int $idx): mixed
    {
        return $idx !== null ? ($row[$idx] ?? null) : null;
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
