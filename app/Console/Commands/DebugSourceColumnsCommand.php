<?php

namespace App\Console\Commands;

use App\Models\ReportUpload;
use App\Services\ColumnMapService;
use App\Support\ReportUploadStorage;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Comando `reportes:debug-source-columns` — para cada archivo de Ministraciones, Ingresos/
 * Cobranza y Saldos por cliente del periodo muestra qué columnas coinciden con el mapa
 * autorizado de ColumnMapService (con banderas métrica/interna/requerida), qué campos faltan y
 * qué columnas del archivo se ignoran. Lee solo las primeras 100 filas.
 *
 * Nota: busca las llaves `missing_required`/`missing_optional`, pero resolveHeader() devuelve
 * `missingRequired`/`missingOptional` para fuentes conocidas, por lo que los faltantes no se
 * muestran.
 * Escribe en BD: NO — solo lectura.
 *
 * Ejemplo:
 *   php artisan reportes:debug-source-columns 21
 */
class DebugSourceColumnsCommand extends Command
{
    protected $signature = 'reportes:debug-source-columns {period_id}';

    protected $description = 'Muestra qué columnas del archivo coinciden con el mapa autorizado de cada fuente para un periodo.';

    /**
     * @param  ColumnMapService  $columnMap  Mapa de columnas autorizadas por fuente.
     */
    public function __construct(private readonly ColumnMapService $columnMap)
    {
        parent::__construct();
    }

    /**
     * Recorre los uploads de las tres fuentes Lendus, lee las primeras filas, detecta el
     * encabezado y muestra el mapeo de columnas.
     *
     * @return int 0 al terminar; 1 si no hay archivos para el periodo.
     */
    public function handle(): int
    {
        @ini_set('memory_limit', '512M');
        $periodId = (int) $this->argument('period_id');

        $uploads = ReportUpload::query()
            ->with('dataSource')
            ->where('period_id', $periodId)
            ->whereNotNull('stored_path')
            ->whereHas('dataSource', fn ($q) => $q->whereIn('code', [
                'lendus_ministraciones', 'lendus_ingresos_cobranza', 'lendus_saldos_cliente',
            ]))
            ->get();

        if ($uploads->isEmpty()) {
            $this->error("No se encontraron archivos cargados para el periodo {$periodId}.");

            return 1;
        }

        foreach ($uploads as $upload) {
            $this->line('');
            $this->line(str_repeat('─', 70));
            $sourceCode = $upload->dataSource?->code ?? 'desconocida';
            $this->info("Fuente : {$sourceCode}");
            $this->line("Archivo: {$upload->stored_path}");

            if (! ReportUploadStorage::exists($upload->stored_path)) {
                $this->warn('  ⚠ Archivo físico no encontrado en storage/public.');

                continue;
            }

            try {
                $absolutePath = ReportUploadStorage::path($upload->stored_path);
                $reader = IOFactory::createReaderForFile($absolutePath);
                $reader->setReadDataOnly(true);
                if (method_exists($reader, 'setReadEmptyCells')) {
                    $reader->setReadEmptyCells(false);
                }
                // Only load first 100 rows — enough to detect header and columns
                $reader->setReadFilter(new class implements IReadFilter
                {
                    /**
                     * Filtro de lectura de PhpSpreadsheet: solo deja pasar las primeras 100 filas.
                     *
                     * @param  string  $columnAddress  Columna de la celda.
                     * @param  int  $row  Número de renglón.
                     * @param  string  $worksheetName  Nombre de la hoja.
                     * @return bool true si el renglón es ≤ 100.
                     */
                    public function readCell($columnAddress, $row, $worksheetName = '')
                    {
                        return $row <= 100;
                    }
                });
                $spreadsheet = $reader->load($absolutePath);
                $rows = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);
            } catch (\Throwable $e) {
                $this->warn("  ⚠ No se pudo leer el archivo: {$e->getMessage()}");

                continue;
            }

            $header = $this->detectHeader($rows, $sourceCode);

            if (empty($header)) {
                $this->warn('  ⚠ No se detectó encabezado.');

                continue;
            }

            $result = $this->columnMap->resolveHeader($sourceCode, $header);

            $this->line('');
            $this->line('  COLUMNAS AUTORIZADAS ENCONTRADAS:');
            if (empty($result['map'])) {
                $this->warn('    (ninguna)');
            } else {
                foreach ($result['map'] as $field => $colIndex) {
                    $defs = $this->columnMap->getFieldDefinitions($sourceCode);
                    $def = $defs[$field] ?? [];
                    $flags = [];
                    if ($def['metric'] ?? false) {
                        $flags[] = 'métrica';
                    }
                    if ($def['internal'] ?? false) {
                        $flags[] = 'interna';
                    }
                    if ($def['required'] ?? false) {
                        $flags[] = 'requerida';
                    }
                    $flagStr = $flags ? ' ['.implode(', ', $flags).']' : '';
                    $rawName = trim((string) ($header[$colIndex] ?? ''));
                    $this->line("    ✓ {$field} → col {$colIndex} ('{$rawName}'){$flagStr}");
                }
            }

            if (! empty($result['missing_required'])) {
                $this->line('');
                $this->error('  CAMPOS REQUERIDOS FALTANTES:');
                foreach ($result['missing_required'] as $field) {
                    $this->error("    ✗ {$field}");
                }
            }

            if (! empty($result['missing_optional'])) {
                $this->line('');
                $this->line('  Campos opcionales no encontrados:');
                foreach ($result['missing_optional'] as $field) {
                    $this->line("    – {$field}");
                }
            }

            if (! empty($result['ignored'])) {
                $this->line('');
                $this->line('  Columnas del archivo NO autorizadas (ignoradas):');
                foreach ($result['ignored'] as $normalized => $colIndex) {
                    $rawName = trim((string) ($header[$colIndex] ?? ''));
                    $this->line("    · col {$colIndex}: '{$rawName}' → normalizado: '{$normalized}'");
                }
            }
        }

        $this->line('');

        return 0;
    }

    /**
     * Elige la fila de encabezado entre las leídas según las coincidencias con el mapa de la fuente.
     *
     * @param  array<int,array>  $rows  Filas leídas.
     * @param  string  $sourceCode  Código de la fuente.
     * @return array Fila de encabezado detectada.
     */
    private function detectHeader(array $rows, string $sourceCode): array
    {
        $scoreWords = match ($sourceCode) {
            'lendus_ministraciones' => ['nombre_promotor', 'producto_de_credito', 'monto_desembolsado', 'cliente', 'contrato', 'promotor'],
            'lendus_ingresos_cobranza' => ['promotor', 'contrato', 'operacion', 'concepto', 'capital', 'interes', 'total', 'producto_de_credito'],
            'lendus_saldos_cliente' => ['contrato', 'saldo_actual', 'capital', 'capital_atrasado', 'dias_vencido', 'nombre_promotor', 'producto_de_credito'],
            default => [],
        };

        $bestIndex = 0;
        $bestScore = -1;

        foreach (array_slice($rows, 0, 80, true) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $score = 0;
            foreach ($row as $cell) {
                $normalized = $this->columnMap->normalizeHeader((string) $cell);
                if (in_array($normalized, $scoreWords, true)) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = (int) $index;
            }

            if ($score >= 3) {
                return (array) $rows[$index];
            }
        }

        return isset($rows[$bestIndex]) ? (array) $rows[$bestIndex] : [];
    }
}
