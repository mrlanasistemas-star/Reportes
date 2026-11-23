<?php

namespace App\Services\Imports;

use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Services\Imports\Support\PdfCoordinateExtractor;
use App\Support\ReportUploadStorage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser;

/**
 * Importa el archivo ÍNDICE DE ROTACIÓN DE PERSONAL 2026.
 * Solo importa el mes que corresponde al periodo del upload.
 * Ejemplo: si period.label contiene "Abril", importa la hoja/columna "ABRIL".
 * Almacena en fact_rotacion (una fila por sucursal para el mes del periodo).
 *
 * Formatos soportados: Excel con hoja por mes, Excel con bloques de sucursal
 * apilados (meses en columnas) y PDF "ROTACIÓN POR SUCURSALES". Lo invocan
 * ReportAnalysisService y el comando ReprocesarRotacionCommand.
 * Nota: desde jul-2026 la rotación del reporte se deriva de NOI; este importador
 * queda para archivos de rotación que todavía se suban.
 */
class RotacionExcelImportService
{
    private const MESES_ES = [
        1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL',
        5 => 'MAYO', 6 => 'JUNIO', 7 => 'JULIO', 8 => 'AGOSTO',
        9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE',
    ];

    // El PDF de rotación abrevia los últimos cuatro meses en su fila de encabezados.
    private const PDF_MONTH_HEADER = [
        1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL',
        5 => 'MAYO', 6 => 'JUNIO', 7 => 'JULIO', 8 => 'AGOSTO',
        9 => 'SEPT', 10 => 'OCT', 11 => 'NOV', 12 => 'DIC',
    ];

    private const ALL_MONTH_HEADER_TOKENS = [
        'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO',
        'JULIO', 'AGOSTO', 'SEPT', 'OCT', 'NOV', 'DIC',
    ];

    private const METRIC_LABEL_PREFIXES = ['ALTA', 'BAJA', 'PLANTILL', 'ROTACI', 'PROMEDIO', 'INDICE'];

    // Alias de sucursal específicos de Rotación (ver spec sección 6). Se resuelven
    // ANTES de caer a BranchResolverService::resolveRealBranchFromRoute(), porque
    // ese resolver exige coincidencia exacta y no conoce variantes como "SAN LUIS" a secas.
    private const SUCURSAL_ALIASES = [
        'SAN LUIS P' => 'SAN LUIS POTOSI',
        'SAN LUIS' => 'SAN LUIS POTOSI',
        'SAN LUIS POTOSI' => 'SAN LUIS POTOSI',
        'TENANGO' => 'TENANGO DEL VALLE',
        'TENANGO DEL VALLE' => 'TENANGO DEL VALLE',
        'CORDOBA' => 'CORDOBA',
        'CÓRDOBA' => 'CORDOBA',
    ];

    /**
     * @param  BranchResolverService  $branchResolver  Busca o crea sucursales y resuelve rutas a sucursal oficial.
     */
    public function __construct(private readonly BranchResolverService $branchResolver) {}

    /**
     * Valida el archivo y delega en handlePdf() o handleExcel() según la extensión.
     *
     * @param  ReportUpload  $upload  Archivo de rotación subido.
     * @param  callable|null  $progress  Callback opcional con el resultado.
     * @return array<string,mixed> Estadísticas (leídas, insertadas, omitidas, errores) y log.
     *
     * @throws \RuntimeException Si falta el archivo (o, en PDF, si no se extrae ningún bloque).
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
        @set_time_limit(0);

        $absolutePath = ReportUploadStorage::path($upload->stored_path);
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            return $this->handlePdf($absolutePath, $upload, $progress);
        }

        return $this->handleExcel($absolutePath, $upload, $progress);
    }

    /**
     * Determina el mes objetivo, BORRA toda la rotación del periodo (sin importar el
     * upload que la generó) y procesa el libro:
     * 1) si hay una hoja con el nombre del mes, la lee como tabla por sucursal;
     * 2) si no, recorre las hojas (primero las que contienen el año del periodo)
     *    buscando bloques por sucursal con el mes en columnas.
     * Efectos: borra e inserta filas en fact_rotacion; puede crear sucursales.
     *
     * @param  string  $absolutePath  Ruta absoluta del libro.
     * @param  ReportUpload  $upload  Upload de origen.
     * @param  callable|null  $progress  Callback opcional.
     * @return array<string,mixed> Estadísticas y log (mes y hoja usados).
     */
    private function handleExcel(string $absolutePath, ReportUpload $upload, ?callable $progress = null): array
    {
        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($absolutePath);

        // Determina el mes objetivo a partir del periodo
        $period = $upload->period;
        $targetMes = $this->resolveTargetMes($period);

        // Borra TODA la rotación del periodo sin importar qué upload la generó
        DB::table('fact_rotacion')->where('period_id', $upload->period_id)->delete();

        $inserted = 0;
        $skipped = 0;
        $errors = 0;
        $usedSheet = null;

        // Estrategia 1: hoja cuyo nombre coincide con el mes objetivo
        $targetSheet = null;
        foreach ($spreadsheet->getSheetNames() as $name) {
            $nameNorm = strtr(mb_strtoupper(trim($name)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
            if ($nameNorm === $targetMes || str_contains($nameNorm, $targetMes)) {
                $targetSheet = $spreadsheet->getSheetByName($name);
                $usedSheet = $name;
                break;
            }
        }

        if ($targetSheet) {
            $rows = $targetSheet->toArray(null, true, true, false);
            $result = $this->parseSheetAllBranches($rows, $targetMes, $usedSheet, $upload);
            $inserted += $result['inserted'];
            $skipped += $result['skipped'];
            $errors += $result['errors'];
        } else {
            // Estrategia 2: recorre todas las hojas buscando columnas del mes objetivo.
            // Se prefieren hojas cuyo nombre contiene el año del periodo (p. ej. "SUCURSALES 2026" sobre "INDICADOR 2025").
            $targetYear = (string) ($period?->year ?? date('Y'));
            $allSheets = iterator_to_array($spreadsheet->getWorksheetIterator());
            usort($allSheets, static function ($a, $b) use ($targetYear) {
                $aY = str_contains($a->getTitle(), $targetYear);
                $bY = str_contains($b->getTitle(), $targetYear);
                if ($aY !== $bY) {
                    return $aY ? -1 : 1;
                }

                return 0;
            });

            foreach ($allSheets as $ws) {
                $rows = $ws->toArray(null, true, true, false);
                $result = $this->parseSheetMonthColumn($rows, $targetMes, $ws->getTitle(), $upload);
                if ($result['inserted'] > 0) {
                    $inserted += $result['inserted'];
                    $skipped += $result['skipped'];
                    $errors += $result['errors'];
                    $usedSheet = $ws->getTitle();
                    break;
                }
                $skipped += $result['skipped'];
            }
        }

        if ($progress) {
            $progress([
                'rows_read' => $inserted + $skipped,
                'rows_inserted' => $inserted,
                'rows_skipped' => $skipped,
                'rows_with_errors' => $errors,
                'log' => "Rotación importada (mes: {$targetMes}, hoja: {$usedSheet}). Insertadas: {$inserted}.",
            ]);
        }

        return [
            'rows_read' => $inserted + $skipped,
            'rows_inserted' => $inserted,
            'rows_skipped' => $skipped,
            'rows_with_errors' => $errors,
            'log' => sprintf(
                'Rotación importada. Mes: %s, Hoja: %s. Insertadas: %d, omitidas: %d, errores: %d.',
                $targetMes, $usedSheet ?? 'ninguna', $inserted, $skipped, $errors
            ),
        ];
    }

    /**
     * Procesa una hoja específica del mes donde cada renglón es una sucursal con
     * altas, bajas, promedio de personal e índice. Omite filas vacías, sin datos o
     * cuyo "nombre" es en realidad una etiqueta de métrica. Si falta el índice, lo
     * deriva como bajas / promedio × 100.
     *
     * @param  array  $rows  Filas de la hoja.
     * @param  string  $mes  Mes objetivo (ENERO..DICIEMBRE).
     * @param  string  $hoja  Nombre de la hoja (se guarda como hoja_fuente).
     * @param  ReportUpload  $upload  Upload de origen.
     * @return array{inserted:int,skipped:int,errors:int} Conteos.
     */
    private function parseSheetAllBranches(array $rows, string $mes, string $hoja, ReportUpload $upload): array
    {
        $inserted = 0;
        $skipped = 0;
        $errors = 0;

        $headerIdx = $this->detectHeaderRow($rows);
        if ($headerIdx === null) {
            return ['inserted' => 0, 'skipped' => count($rows), 'errors' => 0];
        }

        $headers = $rows[$headerIdx];
        $colMap = $this->buildColMapRotacion($headers);

        foreach (array_slice($rows, $headerIdx + 1) as $row) {
            if ($this->isEmptyRow($row)) {
                $skipped++;

                continue;
            }

            $sucursal = $this->str($this->col($row, $colMap['sucursal']));
            $altas = (int) ($this->toDecimal($this->col($row, $colMap['altas'])) ?? 0);
            $bajas = (int) ($this->toDecimal($this->col($row, $colMap['bajas'])) ?? 0);
            $promedio = $this->toDecimal($this->col($row, $colMap['promedio'])) ?? 0.0;
            $indice = $this->toDecimal($this->col($row, $colMap['indice'])) ?? 0.0;

            if (! $sucursal || ($altas === 0 && $bajas === 0 && $promedio == 0.0 && $indice == 0.0)) {
                $skipped++;

                continue;
            }

            // Protección: descarta etiquetas de métrica que nunca son nombres de sucursal
            $sucNorm = strtr(mb_strtoupper($sucursal), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
            $metricPfx = ['ALTA', 'BAJA', 'PLANTILL', 'ROTACI', 'PROMEDIO', 'INDICE'];
            $isMetric = false;
            foreach ($metricPfx as $pfx) {
                if (str_starts_with($sucNorm, $pfx)) {
                    $isMetric = true;
                    break;
                }
            }
            if ($isMetric) {
                $skipped++;

                continue;
            }

            // Deriva el índice si no viene pero sí hay bajas y promedio
            if ($indice == 0.0 && $promedio > 0.0 && $bajas > 0) {
                $indice = round(($bajas / $promedio) * 100, 4);
            }

            try {
                $branchId = null;
                $branchNorm = $this->resolveCanonicalSucursal($sucursal);
                if ($branchNorm) {
                    $branch = $this->branchResolver->findOrCreateBranchByName($branchNorm);
                    $branchId = $branch?->id;
                }

                DB::table('fact_rotacion')->insert([
                    'period_id' => $upload->period_id,
                    'report_upload_id' => $upload->id,
                    'sucursal_nombre' => $branchNorm ?? $sucursal,
                    'branch_id' => $branchId,
                    'mes' => $mes,
                    'altas' => $altas,
                    'bajas' => $bajas,
                    'promedio_personal' => $promedio,
                    'plantilla_maxima' => null,
                    'indice_rotacion' => $indice,
                    'hoja_fuente' => $hoja,
                    'raw_payload' => json_encode(['altas' => $altas, 'plantilla' => $promedio]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $inserted++;
            } catch (\Throwable) {
                $errors++;
            }
        }

        return ['inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Procesa una hoja donde los bloques de sucursal están apilados verticalmente.
     * Cada bloque tiene la forma:
     *   Fila N  : NOMBRE_SUCURSAL (texto suelto)
     *   Fila N+1: [vacío] [ENERO] [FEB] [MAR] [ABR] ... (encabezados de mes)
     *   Fila N+2: ALTAS      | n | n | n | n |
     *   Fila N+3: BAJAS      | n | n | n | n |
     *   Fila N+4: PLANTILLA  | n | n | n | n |
     *   Fila N+5: ROTACIÓN   | n% | n% | n% | n% |
     * Localiza cada fila de encabezados de mes, toma la columna del mes objetivo,
     * busca el nombre de sucursal hasta 5 filas arriba y lee hasta 7 filas de
     * métricas debajo. Deriva el índice si falta e inserta una fila por sucursal.
     *
     * @param  array  $rows  Filas de la hoja.
     * @param  string  $targetMes  Mes objetivo.
     * @param  string  $hoja  Nombre de la hoja.
     * @param  ReportUpload  $upload  Upload de origen.
     * @return array{inserted:int,skipped:int,errors:int} Conteos.
     */
    private function parseSheetMonthColumn(array $rows, string $targetMes, string $hoja, ReportUpload $upload): array
    {
        $inserted = 0;
        $skipped = 0;
        $errors = 0;

        $norm = static function (string $v): string {
            return strtr(mb_strtoupper(trim($v)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
        };

        $allMonths = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO',
            'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];

        $isMonthHeaderRow = function (array $row) use ($allMonths, $norm): bool {
            $found = 0;
            foreach ($row as $cell) {
                $v = $norm((string) $cell);
                foreach ($allMonths as $m) {
                    if (str_contains($v, $m)) {
                        $found++;
                        break;
                    }
                }
                if ($found >= 2) {
                    return true;
                }
            }

            return false;
        };

        $metricPrefixes = ['ALTA', 'BAJA', 'PLANTILL', 'ROTACI', 'PROMEDIO', 'INDICE'];
        $isMetricLabel = function (string $v) use ($metricPrefixes, $norm): bool {
            $u = $norm($v);
            foreach ($metricPrefixes as $p) {
                if (str_starts_with($u, $p)) {
                    return true;
                }
            }

            return false;
        };

        // Reúne todas las filas de encabezado de mes y la columna del mes objetivo
        $rowsArr = array_values($rows);
        $blocks = []; // [{sucursal, targetCol, headerRowIdx}]

        foreach ($rowsArr as $idx => $row) {
            if (! $isMonthHeaderRow($row)) {
                continue;
            }

            // Busca la columna del mes objetivo
            $targetCol = null;
            foreach ($row as $colIdx => $cell) {
                $v = $norm((string) $cell);
                if ($v === $targetMes || str_contains($v, $targetMes)) {
                    $targetCol = $colIdx;
                    break;
                }
            }
            if ($targetCol === null) {
                continue;
            }

            // Nombre de sucursal: revisa hasta 5 filas arriba, en TODAS las columnas.
            // Omite números y etiquetas de métrica — los nombres de sucursal siempre son texto.
            $sucursalName = null;
            for ($back = 1; $back <= 5; $back++) {
                $prevIdx = $idx - $back;
                if ($prevIdx < 0) {
                    break;
                }
                $prevRow = $rowsArr[$prevIdx] ?? [];
                if ($isMonthHeaderRow($prevRow)) {
                    break;
                }
                foreach ($prevRow as $cell) {
                    $candidate = trim((string) $cell);
                    if ($candidate === '') {
                        continue;
                    }
                    if (is_numeric($candidate)) {
                        continue;
                    }
                    if ($isMetricLabel($candidate)) {
                        continue;
                    }
                    $sucursalName = $candidate;
                    break 2;
                }
            }

            if (! $sucursalName) {
                continue;
            }

            $blocks[] = ['sucursal' => $sucursalName, 'targetCol' => $targetCol, 'headerRow' => $idx];
        }

        if (empty($blocks)) {
            return ['inserted' => 0, 'skipped' => count($rows), 'errors' => 0];
        }

        foreach ($blocks as $block) {
            $sucursal = $block['sucursal'];
            $targetCol = $block['targetCol'];
            $hdrIdx = $block['headerRow'];

            $altas = 0;
            $bajas = 0;
            $plantilla = 0.0;
            $indice = 0.0;

            // Lee hasta 7 filas de métricas debajo del encabezado de meses
            for ($ri = $hdrIdx + 1; $ri <= $hdrIdx + 7; $ri++) {
                $mrow = $rowsArr[$ri] ?? null;
                if ($mrow === null) {
                    break;
                }
                $label = $norm(trim((string) ($mrow[0] ?? '')));
                if ($label === '') {
                    continue;
                }
                if ($isMonthHeaderRow($mrow)) {
                    break;
                } // empezó el siguiente bloque

                $val = $this->toDecimal($mrow[$targetCol] ?? null);

                if (str_starts_with($label, 'ALTA')) {
                    $altas = (int) ($val ?? 0);
                } elseif (str_starts_with($label, 'BAJA')) {
                    $bajas = (int) ($val ?? 0);
                } elseif (str_starts_with($label, 'PLANTILL') || str_starts_with($label, 'PROMEDIO')) {
                    $plantilla = (float) ($val ?? 0);
                } elseif (str_starts_with($label, 'ROTACI') || str_starts_with($label, 'INDICE')) {
                    $indice = (float) ($val ?? 0);
                }
            }

            // Deriva el índice si falta
            if ($indice == 0.0 && $plantilla > 0.0 && $bajas > 0) {
                $indice = round($bajas / $plantilla * 100, 4);
            }

            if ($altas === 0 && $bajas === 0 && $plantilla == 0.0 && $indice == 0.0) {
                $skipped++;

                continue;
            }

            try {
                $branchId = null;
                $branchNorm = $this->resolveCanonicalSucursal($sucursal);
                if ($branchNorm) {
                    $branch = $this->branchResolver->findOrCreateBranchByName($branchNorm);
                    $branchId = $branch?->id;
                }

                DB::table('fact_rotacion')->insert([
                    'period_id' => $upload->period_id,
                    'report_upload_id' => $upload->id,
                    'sucursal_nombre' => $branchNorm ?? $sucursal,
                    'branch_id' => $branchId,
                    'mes' => $targetMes,
                    'altas' => $altas,
                    'bajas' => $bajas,
                    'promedio_personal' => $plantilla,
                    'plantilla_maxima' => null,
                    'indice_rotacion' => $indice,
                    'hoja_fuente' => $hoja,
                    'raw_payload' => json_encode(['altas' => $altas, 'plantilla' => $plantilla, 'col_abr' => $targetCol]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $inserted++;
            } catch (\Throwable) {
                $errors++;
            }
        }

        return ['inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Obtiene el mes del periodo: primero el campo month; luego el nombre del
     * periodo (p. ej. "Abril 2026"); por último start_date. Si no se puede
     * determinar devuelve 'ABRIL' por defecto.
     *
     * @param  mixed  $period  Periodo del upload (puede ser null).
     * @return string Mes en mayúsculas (ENERO..DICIEMBRE).
     */
    private function resolveTargetMes(mixed $period): string
    {
        if (! $period) {
            return 'ABRIL';
        }

        // Primero: campo month del periodo (lo más confiable — start_date puede caer en el mes anterior)
        // P13 (7-dic → 3-ene) cae casi todo en diciembre.
        if ((int) $period->month === 13) {
            return self::MESES_ES[12];
        }
        if (! empty($period->month) && isset(self::MESES_ES[(int) $period->month])) {
            return self::MESES_ES[(int) $period->month];
        }

        // Segundo: texto del nombre del periodo (p. ej. "Abril 2026")
        $label = mb_strtoupper((string) ($period->name ?? $period->label ?? ''));
        foreach (self::MESES_ES as $mes) {
            $mesNorm = strtr($mes, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
            if (str_contains($label, $mes) || str_contains($label, $mesNorm)) {
                return $mes;
            }
        }

        // Último: start_date (riesgoso — los periodos mensuales suelen iniciar en la última semana del mes anterior)
        if ($period->start_date) {
            try {
                $month = (int) Carbon::parse($period->start_date)->format('n');

                return self::MESES_ES[$month] ?? 'ABRIL';
            } catch (\Throwable) {
            }
        }

        return 'ABRIL';
    }

    /**
     * Resuelve el nombre canónico de sucursal para Rotación: alias explícitos
     * primero (SAN LUIS → SAN LUIS POTOSI, TENANGO → TENANGO DEL VALLE, etc.),
     * luego el resolver general de rutas/sucursales.
     *
     * @param  string  $raw  Nombre de sucursal tal como viene en el archivo.
     * @return string|null Nombre oficial o null si no se reconoce.
     */
    private function resolveCanonicalSucursal(string $raw): ?string
    {
        $norm = strtr(mb_strtoupper(trim($raw)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
        $norm = (string) preg_replace('/\s+/', ' ', $norm);

        if (isset(self::SUCURSAL_ALIASES[$norm])) {
            return self::SUCURSAL_ALIASES[$norm];
        }

        return $this->branchResolver->resolveRealBranchFromRoute($raw);
    }

    // ── PDF parsing ──────────────────────────────────────────────────────────
    //
    // El PDF "ROTACIÓN POR SUCURSALES 2026" trae 12 bloques (uno por sucursal)
    // apilados verticalmente. Algunos extractores de texto plano envían los
    // nombres de sucursal al final del documento fuera de orden, por lo que
    // usamos coordenadas (PdfCoordinateExtractor) para reconstruir cada fila
    // y ubicar el nombre de sucursal justo encima de su bloque de meses.

    /**
     * Procesa el PDF "ROTACIÓN POR SUCURSALES": reconstruye filas por coordenadas,
     * detecta filas de encabezado de meses (6+ tokens de mes) y la X de la columna
     * del mes objetivo, toma el nombre de sucursal más cercano arriba y lee ALTAS,
     * BAJAS, PLANTILLA (y plantilla máxima aislada) y ROTACIÓN. Evita duplicar bloques.
     * Efectos: borra la rotación del periodo e inserta en fact_rotacion.
     *
     * @param  string  $absolutePath  Ruta absoluta del PDF.
     * @param  ReportUpload  $upload  Upload de origen.
     * @param  callable|null  $progress  Callback opcional.
     * @return array<string,mixed> Estadísticas y log.
     *
     * @throws \RuntimeException Si no se pudo extraer ningún bloque de sucursal para el mes.
     */
    private function handlePdf(string $absolutePath, ReportUpload $upload, ?callable $progress = null): array
    {
        $period = $upload->period;
        $targetMes = $this->resolveTargetMes($period);
        $monthNum = array_search($targetMes, self::MESES_ES, true) ?: null;
        $headerTok = $monthNum ? (self::PDF_MONTH_HEADER[$monthNum] ?? $targetMes) : $targetMes;

        DB::table('fact_rotacion')->where('period_id', $upload->period_id)->delete();

        $parser = new Parser;
        $pdfDoc = $parser->parseFile($absolutePath);

        $inserted = 0;
        $skipped = 0;
        $errors = 0;
        $blocksSeen = [];

        $norm = static fn (string $v): string => PdfCoordinateExtractor::normalizeUpper($v);

        foreach ($pdfDoc->getPages() as $page) {
            $rows = PdfCoordinateExtractor::extractRows($page);
            if (empty($rows)) {
                continue;
            }

            // 1. Detecta filas de encabezado de meses (>=6 tokens de mes) y la X de la columna del mes objetivo.
            $headerBlocks = [];
            foreach ($rows as $idx => $row) {
                $hits = 0;
                $targetX = null;
                foreach ($row['tokens'] as $tok) {
                    $tn = $norm($tok['text']);
                    if (in_array($tn, self::ALL_MONTH_HEADER_TOKENS, true)) {
                        $hits++;
                        if ($tn === $headerTok) {
                            $targetX = $tok['x'];
                        }
                    }
                }
                if ($hits >= 6 && $targetX !== null) {
                    $headerBlocks[] = ['idx' => $idx, 'x' => $targetX];
                }
            }

            foreach ($headerBlocks as $block) {
                $hdrIdx = $block['idx'];
                $targetX = $block['x'];

                // 2. Nombre de sucursal: fila de texto no-métrica más cercana arriba del encabezado.
                $sucursalRaw = null;
                for ($j = $hdrIdx - 1; $j >= 0 && $j >= $hdrIdx - 5; $j--) {
                    $gap = $rows[$j]['y'] - $rows[$hdrIdx]['y'];
                    if ($gap > 20) {
                        break;
                    }
                    $cand = trim($rows[$j]['text']);
                    if ($cand === '' || is_numeric($cand)) {
                        continue;
                    }
                    if ($this->isMetricLabel($cand)) {
                        continue;
                    }
                    $sucursalRaw = $cand;
                    break;
                }

                if (! $sucursalRaw) {
                    $skipped++;

                    continue;
                }

                // 3. Filas de métricas: ALTAS, BAJAS, PLANTILLA (+ plantilla máxima aislada), ROTACIÓN.
                $altas = 0;
                $bajas = 0;
                $plantilla = 0.0;
                $plantillaMax = null;
                $indice = null;

                $k = $hdrIdx + 1;
                $limit = min($hdrIdx + 9, count($rows) - 1);
                while ($k <= $limit) {
                    $row = $rows[$k];
                    if (empty($row['tokens'])) {
                        $k++;

                        continue;
                    }
                    $label = $norm($row['tokens'][0]['text']);
                    if (in_array($label, self::ALL_MONTH_HEADER_TOKENS, true)) {
                        break; // se llegó al encabezado del siguiente bloque
                    }

                    if (str_starts_with($label, 'ALTA')) {
                        $val = PdfCoordinateExtractor::nearestToken($row, $targetX);
                        $altas = (int) ($this->toDecimal($val) ?? 0);
                    } elseif (str_starts_with($label, 'BAJA')) {
                        $val = PdfCoordinateExtractor::nearestToken($row, $targetX);
                        $bajas = (int) ($this->toDecimal($val) ?? 0);
                    } elseif (str_starts_with($label, 'PLANTILL')) {
                        $val = PdfCoordinateExtractor::nearestToken($row, $targetX);
                        $plantilla = (float) ($this->toDecimal($val) ?? 0);

                        // El número aislado (plantilla máxima/autorizada) vive en la fila
                        // inmediatamente siguiente, con un único token numérico.
                        $peek = $rows[$k + 1] ?? null;
                        if ($peek && count($peek['tokens']) === 1 && is_numeric(trim($peek['tokens'][0]['text']))) {
                            $plantillaMax = (float) $peek['tokens'][0]['text'];
                            $k++;
                        }
                    } elseif (str_starts_with($label, 'ROTACI')) {
                        $val = PdfCoordinateExtractor::nearestToken($row, $targetX);
                        $indice = $this->toDecimal($val);
                        $k++;
                        break; // métricas del bloque completas
                    }
                    $k++;
                }

                if ($indice === null) {
                    $indice = ($plantilla > 0.0) ? round($bajas / $plantilla * 100, 4) : 0.0;
                }

                $branchNorm = $this->resolveCanonicalSucursal($sucursalRaw);
                if (! $branchNorm) {
                    Log::warning('RotacionExcelImportService (PDF): sucursal no reconocida.', ['raw' => $sucursalRaw]);
                    $errors++;

                    continue;
                }

                // Evitar duplicados si el mismo bloque se detecta dos veces (p.ej. reintentos de layout).
                if (isset($blocksSeen[$branchNorm])) {
                    continue;
                }
                $blocksSeen[$branchNorm] = true;

                try {
                    $branch = $this->branchResolver->findOrCreateBranchByName($branchNorm);
                    $branchId = $branch?->id;

                    DB::table('fact_rotacion')->insert([
                        'period_id' => $upload->period_id,
                        'report_upload_id' => $upload->id,
                        'sucursal_nombre' => $branchNorm,
                        'branch_id' => $branchId,
                        'mes' => $targetMes,
                        'altas' => $altas,
                        'bajas' => $bajas,
                        'promedio_personal' => $plantilla,
                        'plantilla_maxima' => $plantillaMax,
                        'indice_rotacion' => $indice,
                        'hoja_fuente' => 'PDF',
                        'raw_payload' => json_encode([
                            'altas' => $altas, 'plantilla' => $plantilla, 'plantilla_maxima' => $plantillaMax,
                            'sucursal_raw' => $sucursalRaw,
                        ]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $inserted++;
                } catch (\Throwable $e) {
                    Log::warning('RotacionExcelImportService (PDF): error al insertar fila.', ['error' => $e->getMessage(), 'sucursal' => $branchNorm]);
                    $errors++;
                }
            }
        }

        if ($inserted === 0) {
            throw new \RuntimeException("No se pudo extraer ningún bloque de sucursal del PDF de rotación para el mes {$targetMes}. Verifica que el archivo tenga el formato esperado (ROTACIÓN POR SUCURSALES).");
        }

        $log = sprintf(
            'Rotación importada desde PDF. Mes: %s. Sucursales detectadas: %d, omitidas: %d, errores: %d.',
            $targetMes, $inserted, $skipped, $errors
        );

        if ($progress) {
            $progress([
                'rows_read' => $inserted + $skipped,
                'rows_inserted' => $inserted,
                'rows_skipped' => $skipped,
                'rows_with_errors' => $errors,
                'log' => $log,
            ]);
        }

        return [
            'rows_read' => $inserted + $skipped,
            'rows_inserted' => $inserted,
            'rows_skipped' => $skipped,
            'rows_with_errors' => $errors,
            'log' => $log,
        ];
    }

    /**
     * Indica si el texto es una etiqueta de métrica (ALTAS, BAJAS, PLANTILLA,
     * ROTACIÓN, PROMEDIO, ÍNDICE) o el título del PDF, y por tanto no un nombre de sucursal.
     *
     * @param  string  $value  Texto a evaluar.
     * @return bool true si es etiqueta/título.
     */
    private function isMetricLabel(string $value): bool
    {
        $norm = strtr(mb_strtoupper(trim($value)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
        foreach (self::METRIC_LABEL_PREFIXES as $pfx) {
            if (str_starts_with($norm, $pfx)) {
                return true;
            }
        }
        if (str_contains($norm, 'SUCURSALES') && str_contains($norm, 'ROTACI')) {
            return true;
        } // título del PDF

        return false;
    }

    /**
     * Busca, en las primeras 15 filas, la primera con 2+ celdas que contienen
     * palabras ancla (sucursal, bajas, promedio, rotación, índice, tasa...).
     *
     * @param  array  $rows  Filas de la hoja.
     * @return int|null Índice del encabezado o null.
     */
    private function detectHeaderRow(array $rows): ?int
    {
        $anchors = ['sucursal', 'unidad', 'clasificaci', 'bajas', 'promedio', 'rotaci', 'indice', 'tasa'];
        foreach (array_slice($rows, 0, 15, true) as $idx => $row) {
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
                return (int) $idx;
            }
        }

        return null;
    }

    /**
     * Detecta las columnas de sucursal, altas, bajas, promedio/plantilla e
     * índice/rotación/tasa por palabras contenidas en el encabezado (sucursal = 0 por defecto).
     *
     * @param  array  $headers  Celdas del encabezado.
     * @return array<string,int|null> Campo => índice de columna.
     */
    private function buildColMapRotacion(array $headers): array
    {
        $map = ['sucursal' => 0, 'altas' => null, 'bajas' => null, 'promedio' => null, 'indice' => null];

        foreach ($headers as $idx => $cell) {
            $norm = strtr(mb_strtolower(trim((string) $cell)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
            if (str_contains($norm, 'sucursal') || str_contains($norm, 'unidad') || str_contains($norm, 'clasificaci')) {
                $map['sucursal'] = $idx;
            } elseif ($map['altas'] === null && str_contains($norm, 'alta')) {
                $map['altas'] = $idx;
            } elseif ($map['bajas'] === null && str_contains($norm, 'baja')) {
                $map['bajas'] = $idx;
            } elseif ($map['promedio'] === null && (str_contains($norm, 'promedio') || str_contains($norm, 'personal') || str_contains($norm, 'plantilla'))) {
                $map['promedio'] = $idx;
            } elseif ($map['indice'] === null && (str_contains($norm, 'indice') || str_contains($norm, 'rotaci') || str_contains($norm, 'tasa'))) {
                $map['indice'] = $idx;
            }
        }

        return $map;
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

    /**
     * @param  array  $row  Fila de datos.
     * @param  int|null  $idx  Índice de columna (null si no existe).
     * @return mixed Valor de la celda o null.
     */
    private function col(array $row, ?int $idx): mixed
    {
        return $idx !== null ? ($row[$idx] ?? null) : null;
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
     * Convierte un número o porcentaje en texto (quita %, $, comas, espacios) a float con 4 decimales.
     *
     * @param  mixed  $value  Valor de celda.
     * @return float|null Número o null si no es numérico.
     */
    private function toDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return round((float) $value, 4);
        }
        $clean = str_replace(['%', '$', ',', ' '], '', (string) $value);

        return is_numeric($clean) ? round((float) $clean, 4) : null;
    }
}
