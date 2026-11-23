<?php

namespace App\Services\Imports;

use App\Models\Placement;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Services\ColumnMapService;
use App\Support\ReportUploadStorage;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Importador del reporte de Ministraciones de Lendus (fuente `lendus_ministraciones`):
 * cada fila es un crédito desembolsado y alimenta la COLOCACIÓN (fact_placements).
 *
 * Flujo: lee la primera hoja, detecta el renglón de encabezados por puntaje de
 * columnas conocidas, mapea columnas solo con los alias AUTORIZADOS de
 * ColumnMapService ('lendus_ministraciones'), borra las colocaciones previas del
 * upload e inserta una fila por crédito con monto > 0.
 * Reglas de negocio:
 * - Colocación = "Monto desembolsado" SIEMPRE; "$ Desembolsado" (efectivo) solo
 *   se guarda en raw_payload para auditoría.
 * - La sucursal se resuelve únicamente por el prefijo del código de promotor.
 * - El producto se normaliza con plazo y periodicidad (p. ej. CRÉDITO DIARIO → i40).
 * Lo invoca ReportAnalysisService al procesar un upload de esta fuente.
 */
class LendusMinistracionesImportService
{
    /**
     * @param  BranchResolverService  $branchResolver  Resuelve sucursal por código y normaliza productos.
     * @param  ColumnMapService  $columnMap  Catálogo de columnas autorizadas por fuente.
     */
    public function __construct(
        private readonly BranchResolverService $branchResolver,
        private readonly ColumnMapService $columnMap,
    ) {}

    /**
     * Importa las ministraciones del upload a fact_placements.
     * Efectos: borra e inserta filas de fact_placements del upload; puede crear
     * sucursales (findOrCreateBranchByCode). En raw_payload guarda los componentes
     * del crédito (cuota, interés, IVA, adeudo, seguro, apertura, descuentos de
     * refinanciamiento/subproductos).
     *
     * @param  ReportUpload  $upload  Archivo subido a importar.
     * @param  callable|null  $progress  Callback opcional con estadísticas parciales cada 250 filas.
     * @return array<string,mixed> Estadísticas (leídas, insertadas, omitidas, con error) y log.
     *
     * @throws \RuntimeException Si falta el archivo, está vacío, no hay columna de monto o no se insertó ninguna fila.
     */
    public function handle(ReportUpload $upload, ?callable $progress = null): array
    {
        if (! $upload->stored_path) {
            throw new \RuntimeException('El archivo de ministraciones no tiene ruta de almacenamiento.');
        }

        if (! ReportUploadStorage::exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico de ministraciones no existe en storage/public.');
        }

        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);

        $absolutePath = ReportUploadStorage::path($upload->stored_path);
        $sheets = Excel::toArray([], $absolutePath);
        $rows = $sheets[0] ?? [];

        if (empty($rows)) {
            throw new \RuntimeException('El archivo de ministraciones está vacío o no se pudo leer.');
        }

        $headerIndex = $this->detectHeaderRowIndex($rows);
        $header = $rows[$headerIndex] ?? [];
        $map = $this->buildHeaderMap($header);

        if (! array_key_exists('amount', $map)) {
            throw new \RuntimeException(
                'El archivo de ministraciones no contiene una columna de monto reconocible. Encabezados detectados: '
                .implode(', ', array_values(array_filter(array_map(fn ($value) => trim((string) $value), $header))))
            );
        }

        Placement::query()
            ->where('report_upload_id', $upload->id)
            ->delete();

        $stats = [
            'rows_read' => 0,
            'rows_inserted' => 0,
            'rows_skipped' => 0,
            'rows_with_errors' => 0,
        ];

        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            if (! is_array($row) || $this->isEmptyRow($row)) {
                $stats['rows_skipped']++;

                continue;
            }

            $stats['rows_read']++;

            try {
                $amountMonto = $this->toDecimal($this->valueFromRow($row, $map, 'amount'));
                $amountDesemb = $this->toDecimal($this->valueFromRow($row, $map, 'amount_disbursed'));
                $creditOrigin = $this->clean($this->valueFromRow($row, $map, 'credit_origin'));

                // Otorgamientos = Monto desembolsado SIEMPRE (col 53).
                // $ Desembolsado (col 54) se guarda solo para auditoría, nunca para calcular colocación.
                $amount = $amountMonto;

                if (($amount ?? 0) <= 0) {
                    $stats['rows_skipped']++;

                    continue;
                }

                $cuotaTotal = $this->toDecimal($this->valueFromRow($row, $map, 'cuota_total'));
                $interes = $this->toDecimal($this->valueFromRow($row, $map, 'interes_total'));
                $impuesto = $this->toDecimal($this->valueFromRow($row, $map, 'impuesto_total'));
                $adeudoTotal = $this->toDecimal($this->valueFromRow($row, $map, 'adeudo_total'));
                $seguro = $this->toDecimal($this->valueFromRow($row, $map, 'seguro'));
                $apertura = $this->toDecimal($this->valueFromRow($row, $map, 'apertura'));
                $descRefi = $this->toDecimal($this->valueFromRow($row, $map, 'descuento_refinanciamiento'));
                $descSubprod = $this->toDecimal($this->valueFromRow($row, $map, 'descuento_subproductos'));

                $promoterName = $this->clean($this->valueFromRow($row, $map, 'promoter_name'));
                $promoterCode = $this->clean($this->valueFromRow($row, $map, 'promoter_code'));
                $productName = $this->clean($this->valueFromRow($row, $map, 'product_name'));
                $clientName = $this->clean($this->valueFromRow($row, $map, 'client_name'));

                // Si la columna de nombre de promotor trae algo con forma de código, se mueve a código
                if ($promoterName && ! $promoterCode && preg_match('/^[A-Z]{2,6}\d{4,}$/i', $promoterName)) {
                    $promoterCode = $promoterName;
                    $promoterName = null;
                }
                if ($promoterName && $promoterCode && $promoterName === $promoterCode) {
                    $promoterName = null;
                }

                // Lee plazo/periodicidad para normalizar el producto con precisión
                // (necesario para: CREDITO DIARIO → i40, SEMANAL sin código → s12, etc.)
                $numPaymentsRaw = $this->valueFromRow($row, $map, 'num_payments')
                                ?? $this->valueFromRow($row, $map, 'term');
                $numPayments = is_numeric($numPaymentsRaw) ? (int) round((float) $numPaymentsRaw) : null;
                $periodicity = $this->clean($this->valueFromRow($row, $map, 'periodicity'));

                $normalizedProduct = $productName
                    ? $this->branchResolver->normalizeProduct($productName, $numPayments, $periodicity)
                    : null;

                // Sucursal: solo por prefijo de promoter_code (únicamente columnas autorizadas)
                $branch = $promoterCode
                    ? $this->branchResolver->findOrCreateBranchByCode($promoterCode)
                    : null;

                Placement::query()->create([
                    'period_id' => $upload->period_id,
                    'report_upload_id' => $upload->id,
                    'branch_id' => $branch?->id,
                    'client_name' => $clientName,
                    'normalized_client_name' => $this->normalize($clientName),
                    'promoter_name' => $promoterName,
                    'promoter_code' => $promoterCode,
                    'normalized_promoter_name' => $this->normalize($promoterName ?? $promoterCode),
                    'product_name' => $normalizedProduct,
                    'amount' => $amount,
                    'operation_date' => $this->toDate($this->valueFromRow($row, $map, 'operation_date')),
                    'raw_payload' => json_encode([
                        'credit_origin' => $creditOrigin,
                        'monto_desembolsado' => $amountMonto,
                        'efectivo_desembolsado' => $amountDesemb,
                        'cuota_total' => $cuotaTotal,
                        'interes_total' => $interes,
                        'impuesto_total' => $impuesto,
                        'adeudo_total' => $adeudoTotal,
                        'seguro' => $seguro,
                        'apertura' => $apertura,
                        'descuento_refinanciamiento' => $descRefi,
                        'descuento_subproductos' => $descSubprod,
                        // compatibilidad hacia atrás
                        'amount_monto53' => $amountMonto,
                        'amount_desemb54' => $amountDesemb,
                        'amount_used' => $amount,
                    ]),
                ]);

                $stats['rows_inserted']++;
            } catch (\Throwable) {
                $stats['rows_with_errors']++;
            }

            if ($progress && $stats['rows_read'] % 250 === 0) {
                $progress($stats + ['log' => "Integrando ministraciones... {$stats['rows_read']} filas leídas."]);
            }
        }

        if ($stats['rows_inserted'] <= 0) {
            throw new \RuntimeException(
                'El archivo de ministraciones fue leído, pero no generó registros útiles. '
                .'Revisa que exista una columna de monto con valores mayores a 0. '
                .'Filas leídas: '.$stats['rows_read'].', omitidas: '.$stats['rows_skipped'].'.'
            );
        }

        return $stats + [
            'log' => sprintf(
                'Importación de ministraciones finalizada. Leídas: %d, insertadas: %d, omitidas: %d, con error: %d.',
                $stats['rows_read'],
                $stats['rows_inserted'],
                $stats['rows_skipped'],
                $stats['rows_with_errors'],
            ),
        ];
    }

    /**
     * Recorre las primeras 80 filas y puntúa cuántas celdas coinciden con encabezados
     * conocidos (cliente, oficina, promotor, contrato, fecha/monto desembolsado...).
     * Devuelve la primera fila con 6+ coincidencias o, si ninguna llega, la de mayor puntaje.
     *
     * @param  array  $rows  Filas de la hoja.
     * @return int Índice del renglón de encabezados.
     */
    private function detectHeaderRowIndex(array $rows): int
    {
        $bestIndex = 0;
        $bestScore = -1;

        foreach (array_slice($rows, 0, 80, true) as $index => $row) {
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
                    'cliente',
                    'nombre_del_cliente',
                    'nombre_cliente',
                    'acreditado',
                    'nombre_acreditado',
                    'oficina',
                    'zona',
                    'coordinador',
                    'nombre_coordinador',
                    'promotor',
                    'nombre_promotor',
                    'contrato',
                    'estatus',
                    'fecha_desembolso',
                    'monto_desembolsado',
                    'desembolsado',
                    'producto_de_credito',
                ], true)) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = (int) $index;
            }

            if ($score >= 6) {
                return (int) $index;
            }
        }

        return $bestIndex;
    }

    /**
     * Mapea cada campo definido en ColumnMapService a la primera columna cuyo
     * encabezado normalizado coincide exactamente con un alias, sin reutilizar columnas.
     *
     * @param  array  $header  Celdas del renglón de encabezados.
     * @return array<string,int> Campo => índice de columna.
     */
    private function buildHeaderMap(array $header): array
    {
        $normalizedHeaders = [];
        foreach ($header as $index => $value) {
            $normalized = $this->columnMap->normalizeHeader((string) $value);
            if ($normalized !== '') {
                $normalizedHeaders[$index] = $normalized;
            }
        }

        $defs = $this->columnMap->getFieldDefinitions('lendus_ministraciones');
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
     * @param  array  $row  Fila de datos.
     * @param  array  $map  Mapa campo => índice.
     * @param  string  $field  Campo lógico.
     * @return mixed Valor de la celda o null si la columna no existe.
     */
    private function valueFromRow(array $row, array $map, string $field): mixed
    {
        if (! array_key_exists($field, $map)) {
            return null;
        }

        return $row[$map[$field]] ?? null;
    }

    /**
     * Normaliza un encabezado a formato snake (minúsculas, sin acentos, no
     * alfanuméricos → '_') para la detección del renglón de encabezados.
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

        return trim($value, '_');
    }

    /**
     * Normaliza nombres de cliente/promotor (minúsculas, sin acentos, espacios simples).
     *
     * @param  string|null  $value  Texto original.
     * @return string|null Texto normalizado o null si viene vacío.
     */
    private function normalize(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

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
     * @param  mixed  $value  Valor de celda.
     * @return string|null Texto recortado o null si queda vacío.
     */
    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Convierte un monto a float con 2 decimales; acepta $, comas, espacios y
     * paréntesis contables (negativos).
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

        $value = str_replace(['$', ',', ' '], '', (string) $value);
        $value = str_replace(['(', ')'], ['-', ''], $value);

        return is_numeric($value) ? round((float) $value, 2) : null;
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
            return Carbon::parse((string) $value)->toDateString();
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
