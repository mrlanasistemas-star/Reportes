<?php

namespace App\Services\EstadoResultados\Import;

use App\Services\EstadoResultados\Support\FolioNormalizer;
use App\Services\EstadoResultados\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lee un estado de cuenta complementario (Excel) y lo convierte a movimientos
 * normalizados SIN tocar la base de datos. Valida la estructura completa: si
 * hay un solo error, se lanza EstadoResultadosImportException y no se guarda nada.
 *
 * Estructuras reconocidas:
 *  - administrativa: FECHA | REFERENCIA BANCA | CARGO | ABONO | SALDO | REQ | CONCEPTO |
 *                    SUCURSAL | BENEFICIARIO | OBSERVACIONES | ESTATUS | TOTAL |
 *                    CONCEPTO GASTO | UUID | COMPLEMENTO PAGO
 *  - excedentes:     FECHA | DESCRIPCION Y REFERENCIA | CARGO | ABONO | SALDO | DETALLE |
 *                    SUCURSAL | OBSERVACIONES
 *
 * Reglas de lectura:
 *  - Fila con CARGO o ABONO = movimiento (fecha obligatoria y válida).
 *  - Fila sin importes pero con OBSERVACIONES/TOTAL/CONCEPTO GASTO/UUID = línea de
 *    detalle de comprobación del movimiento anterior (se conserva para auditoría,
 *    NUNCA se suma).
 *  - "SALDO INICIAL" se registra como saldo de apertura; "TOTAL ..." como total de
 *    control del archivo (se valida contra la suma leída).
 *
 * Seguridad (06-nov-2026, fase 2):
 *  - SpreadsheetSafetyInspector revisa el contenedor ANTES de cargar (bomba ZIP, partes,
 *    macros, vínculos externos).
 *  - Dimensiones (filas/columnas) por hoja con listWorksheetInfo() ANTES de load(), y un
 *    filtro de lectura que nunca carga más allá de esos límites. Sólo datos (sin estilos).
 *  - Fórmulas INTERNAS se calculan (mismo resultado que antes); el valor guardado por Excel
 *    sólo se usa si el cálculo falla, y si difiere del calculado se avisa. Una fórmula que
 *    referencia OTRO libro, una ruta, URL, DDE o
 *    funciones de red (WEBSERVICE, RTD, CALL…) jamás se evalúa: si está en una columna
 *    que se usa ⇒ se rechaza la fila ("pega como valores"); fuera de ellas ⇒ aviso.
 */
class BankStatementParser
{
    public const LAYOUT_ADMINISTRATIVA = 'administrativa';

    public const LAYOUT_EXCEDENTES = 'excedentes';

    /** Límite defensivo de filas por hoja (un estado de cuenta mensual real tiene cientos). */
    public const MAX_ROWS = 50000;

    /** Límite defensivo de columnas por hoja (los formatos reales usan 9 y 16). */
    public const MAX_COLUMNS = 100;

    /** Fórmulas que salen del libro (otro libro, ruta, URL, DDE, funciones de red). */
    private const EXTERNAL_FORMULA = [
        '/\[\d+\]/',                                  // [1]Hoja!A1 (índice de vínculo externo)
        '/\[[^\]]*\.(xl\w*|csv|ods)\]/i',             // [libro.xlsx]Hoja!A1
        '~[a-z]:\\\\|\\\\\\\\|https?://|file:~i',     // rutas locales/UNC o URLs
        '/\b[A-Za-z0-9_.]+\|/',                       // DDE: cmd|' /C calc'!A0
        '/\b(WEBSERVICE|FILTERXML|RTD|CALL|REGISTER\.ID|EXEC|HYPERLINK|IMAGE)\s*\(/i',
    ];

    /** Fórmulas internas cuyo valor guardado difiere del calculado (aviso informativo). */
    private int $staleFormulas = 0;

    public function __construct(private readonly SpreadsheetSafetyInspector $inspector = new SpreadsheetSafetyInspector) {}

    public static function maxRows(): int
    {
        return max(1, (int) config('estado_resultados.import.max_rows', self::MAX_ROWS));
    }

    public static function maxColumns(): int
    {
        return max(1, (int) config('estado_resultados.import.max_columns', self::MAX_COLUMNS));
    }

    public static function isExternalFormula(string $formula): bool
    {
        foreach (self::EXTERNAL_FORMULA as $re) {
            if (preg_match($re, $formula)) {
                return true;
            }
        }

        return false;
    }

    private const HEADER_ALIASES = [
        'fecha' => ['FECHA', 'FECHA OPERACION', 'FECHA MOVIMIENTO'],
        'referencia' => ['REFERENCIA BANCA', 'REFRENCIA BANCA', 'REFERENCIA BANCARIA', 'REFERENCIA', 'DESCRIPCION Y REFERENCIA', 'DESCRIPCION', 'CONCEPTO BANCO'],
        'cargo' => ['CARGO', 'CARGOS', 'RETIRO', 'RETIROS'],
        'abono' => ['ABONO', 'ABONOS', 'DEPOSITO', 'DEPOSITOS'],
        'saldo' => ['SALDO'],
        'req' => ['REQ', 'FOLIO', 'REQ/FOLIO', 'REQUISICION'],
        'concepto' => ['CONCEPTO', 'DETALLE'],
        'sucursal' => ['SUCURSAL'],
        'beneficiario' => ['BENEFICIARIO'],
        'observaciones' => ['OBSERVACIONES', 'OBSERVACION'],
        'estatus' => ['ESTATUS', 'STATUS'],
        'total' => ['TOTAL'],
        'concepto_gasto' => ['CONCEPTO GASTO'],
        'uuid' => ['UUID', 'FOLIO FISCAL'],
        'complemento_pago' => ['COMPLEMENTO PAGO', 'COMPLEMENTO DE PAGO'],
    ];

    /**
     * @return array{
     *   layout: string, sheet: string, header_row: int, columns: array<string,int>,
     *   movements: list<array<string,mixed>>, detail_lines: int, rows_read: int,
     *   opening_balance: ?string, control_cargos: ?string, control_abonos: ?string,
     *   warnings: list<string>
     * }
     */
    public function parse(string $absolutePath, ?string $extension = null): array
    {
        $safety = $this->inspector->inspect($absolutePath, $extension ?: (pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'xlsx'));
        $maxRows = self::maxRows();
        $maxCols = self::maxColumns();

        try {
            $reader = IOFactory::createReaderForFile($absolutePath);
            $reader->setReadDataOnly(true);
            $info = $reader->listWorksheetInfo($absolutePath);
        } catch (\Throwable $e) {
            throw new EstadoResultadosImportException('No se pudo leer el archivo como Excel válido (.xlsx/.xls/.xlsm).', [$e->getMessage()]);
        }
        foreach ($info as $sheetInfo) {
            $name = (string) ($sheetInfo['worksheetName'] ?? '');
            if ((int) ($sheetInfo['totalRows'] ?? 0) > $maxRows) {
                throw new EstadoResultadosImportException(
                    'La hoja "'.$name.'" tiene más de '.number_format($maxRows).' filas: no parece un estado de cuenta mensual.',
                    ['Verifica que el archivo sea el estado de cuenta del periodo y no un acumulado.']
                );
            }
            if ((int) ($sheetInfo['totalColumns'] ?? 0) > $maxCols) {
                throw new EstadoResultadosImportException(
                    'La hoja "'.$name.'" tiene '.number_format((int) $sheetInfo['totalColumns']).' columnas (máximo '.number_format($maxCols).'): no parece un estado de cuenta.',
                    ['Elimina las columnas que no forman parte del estado de cuenta y vuelve a cargarlo.']
                );
            }
        }

        try {
            // Nunca se carga más allá de los límites (defensa adicional si listWorksheetInfo subestima).
            $reader->setReadFilter(new class($maxRows + 1, $maxCols) implements IReadFilter
            {
                public function __construct(private int $rows, private int $cols) {}

                public function readCell($columnAddress, $row, $worksheetName = ''): bool
                {
                    return (int) $row <= $this->rows && Coordinate::columnIndexFromString((string) $columnAddress) <= $this->cols;
                }
            });
            $book = $reader->load($absolutePath);
        } catch (\Throwable $e) {
            throw new EstadoResultadosImportException('No se pudo leer el archivo como Excel válido (.xlsx/.xls/.xlsm).', [$e->getMessage()]);
        }

        $warnings = $safety['warnings'];
        $this->staleFormulas = 0;
        $detected = null;
        $externalElsewhere = 0;
        foreach ($book->getSheetNames() as $index => $name) {
            $sheet = $book->getSheet($index);
            if ($sheet->getHighestDataRow() > $maxRows) {
                throw new EstadoResultadosImportException(
                    'La hoja "'.$name.'" tiene más de '.number_format($maxRows).' filas: no parece un estado de cuenta mensual.',
                    ['Verifica que el archivo sea el estado de cuenta del periodo y no un acumulado.']
                );
            }
            [$rows, $external] = $this->sheetValues($sheet);
            $header = $this->detectHeader($rows);
            if ($header !== null) {
                $detected = ['sheet' => $name, 'rows' => $rows, 'external' => $external] + $header;
                break;
            }
            $externalElsewhere += count($external);
        }
        $book->disconnectWorksheets();
        unset($book);

        if ($detected === null) {
            throw new EstadoResultadosImportException(
                'Estructura no reconocida: no se encontró una fila de encabezados con FECHA, CARGO y ABONO.',
                [
                    'Cuenta administrativa: FECHA, REFERENCIA BANCA, CARGO, ABONO, SALDO, REQ, CONCEPTO, SUCURSAL, BENEFICIARIO, OBSERVACIONES…',
                    'Cuenta de excedentes: FECHA, DESCRIPCION Y REFERENCIA, CARGO, ABONO, SALDO, DETALLE, SUCURSAL, OBSERVACIONES.',
                ]
            );
        }

        // Referencias externas: en columnas que se leen ⇒ rechazo; en el resto ⇒ aviso.
        $used = array_flip(array_values($detected['columns']));
        $errors = [];
        foreach ($detected['external'] as [$rowIdx, $colIdx, $formula]) {
            if ($rowIdx > $detected['header_row'] && isset($used[$colIdx])) {
                $letter = Coordinate::stringFromColumnIndex($colIdx + 1);
                $errors[] = ['row' => $rowIdx + 1, 'column' => $letter, 'message' => 'Fila '.($rowIdx + 1).': la celda '.$letter.($rowIdx + 1).' tiene una fórmula que toma datos de otro libro o de fuera del archivo ('.mb_substr($formula, 0, 60).'). Pega la columna como valores y vuelve a cargarlo.'];
            } else {
                $externalElsewhere++;
            }
        }
        if ($errors !== []) {
            throw new EstadoResultadosImportException(
                'El archivo tiene '.count($errors).' celda(s) con fórmulas que dependen de otros libros o de fuentes externas. No se cargó ningún movimiento.',
                array_slice(array_column($errors, 'message'), 0, 50),
                array_slice($errors, 0, 1000),
            );
        }
        if ($externalElsewhere > 0 || $safety['external_links']) {
            $warnings[] = ['code' => 'vinculos_externos', 'severity' => SpreadsheetSafetyInspector::SEVERITY_INFO,
                'message' => 'El libro tiene vínculos a otros archivos'.($externalElsewhere > 0 ? ' ('.$externalElsewhere.' celda(s) fuera de las columnas del estado de cuenta)' : '').': no se evaluaron ni se usan.'];
        }
        if ($this->staleFormulas > 0) {
            $warnings[] = ['code' => 'formulas_recalculadas', 'severity' => SpreadsheetSafetyInspector::SEVERITY_INFO,
                'message' => $this->staleFormulas.' fórmula(s) tenían guardado un valor distinto al que resulta de calcularlas: se usó el valor calculado.'];
        }

        $parsed = $this->readRows($detected);
        $parsed['warnings_detail'] = array_merge($warnings, $parsed['warnings_detail']);
        $parsed['warnings'] = array_column($parsed['warnings_detail'], 'message');
        $parsed['has_macros'] = $safety['has_macros'];
        $parsed['external_links'] = $safety['external_links'] || $externalElsewhere > 0;

        return $parsed;
    }

    /**
     * Valores de la hoja SIN ejecutar el motor de cálculo cuando Excel guardó el resultado.
     *
     * @return array{0: array<int, array<int, mixed>>, 1: list<array{0:int,1:int,2:string}>} filas (base 0) y fórmulas externas [fila, columna, fórmula]
     */
    private function sheetValues(Worksheet $sheet): array
    {
        $rows = $sheet->toArray(null, false, false, false);
        $external = [];
        foreach ($sheet->getCoordinates() as $coordinate) {
            $cell = $sheet->getCell($coordinate);
            if ($cell->getDataType() !== DataType::TYPE_FORMULA) {
                continue;
            }
            [$col, $row] = Coordinate::indexesFromString($coordinate);
            $formula = (string) $cell->getValue();
            if (self::isExternalFormula($formula)) {
                $external[] = [$row - 1, $col - 1, $formula];
                $value = null;
            } else {
                // Fórmula INTERNA: se calcula (mismo resultado que antes de la fase 2; el motor no
                // tiene acceso a nada fuera del libro una vez excluidas las externas). El valor
                // guardado sólo se usa si el cálculo falla: no es confiable (otras herramientas
                // escriben 0 o nada) y puede estar desactualizado.
                $cached = $cell->getOldCalculatedValue();
                try {
                    $value = $cell->getCalculatedValue();
                } catch (\Throwable) {
                    $value = $cached;
                }
                if (is_numeric($cached) && is_numeric($value) && abs((float) $cached - (float) $value) >= 0.005) {
                    $this->staleFormulas++;
                }
            }
            $rows[$row - 1][$col - 1] = $value;
        }

        return [$rows, $external];
    }

    private function detectHeader(array $rows): ?array
    {
        foreach (array_slice($rows, 0, 20, true) as $rowIndex => $row) {
            $map = [];
            foreach ($row as $col => $cell) {
                $label = $this->headerKey($cell);
                if ($label === '') {
                    continue;
                }
                foreach (self::HEADER_ALIASES as $field => $aliases) {
                    if (! isset($map[$field]) && in_array($label, $aliases, true)) {
                        $map[$field] = (int) $col;
                        break;
                    }
                }
            }
            if (! isset($map['fecha'], $map['cargo'], $map['abono'])) {
                continue;
            }
            // DETALLE se mapea a 'concepto' (misma semántica); la presencia de REQ define la cuenta administrativa.
            $hasDetalle = collect($row)->contains(fn ($c) => $this->headerKey($c) === 'DETALLE');
            $layout = isset($map['req']) ? self::LAYOUT_ADMINISTRATIVA : ($hasDetalle ? self::LAYOUT_EXCEDENTES : null);
            if ($layout === null) {
                continue;
            }
            $missing = array_diff(['referencia', 'concepto', 'sucursal'], array_keys($map));
            if ($missing !== []) {
                throw new EstadoResultadosImportException(
                    'Estructura incompleta: faltan columnas obligatorias en la fila '.($rowIndex + 1).'.',
                    array_map(fn ($m) => 'Falta la columna '.mb_strtoupper(str_replace('_', ' ', $m)).'.', array_values($missing))
                );
            }

            return ['layout' => $layout, 'header_row' => (int) $rowIndex, 'columns' => $map];
        }

        return null;
    }

    private function readRows(array $d): array
    {
        $cols = $d['columns'];
        $movements = [];
        $errors = [];
        $warnings = [];
        $detailLines = 0;
        $rowsRead = 0;
        $opening = null;
        $controlCargos = null;
        $controlAbonos = null;
        $occurrences = [];
        $footer = false;
        $footerIgnored = 0;

        foreach ($d['rows'] as $index => $row) {
            if ($index <= $d['header_row']) {
                continue;
            }
            $rowNumber = $index + 1;
            $cell = fn (string $field) => isset($cols[$field]) ? ($row[$cols[$field]] ?? null) : null;

            if ($this->isEmpty($row)) {
                continue;
            }
            $rowsRead++;

            $reference = $this->text($cell('referencia'));
            $refUpper = mb_strtoupper(Str::ascii((string) $reference));
            $cargoRaw = $cell('cargo');
            $abonoRaw = $cell('abono');

            // Totales de control al pie del archivo ("TOTAL EXCEL:", "TOTAL PDF BBVA:").
            // Sólo es fila de control si NO tiene fecha ("TOTAL PLAY" es un proveedor real).
            $rowHasDate = $this->date($cell('fecha')) !== null;
            if (! $rowHasDate && str_starts_with($refUpper, 'TOTAL')) {
                $footer = true;
                if ($controlCargos === null) {
                    $controlCargos = $this->amountOrNull($cargoRaw);
                    $controlAbonos = $this->amountOrNull($abonoRaw);
                }

                continue;
            }
            // Todo lo que sigue a los totales de control es pie del archivo (p.ej. "OK CUADRA").
            if ($footer) {
                $footerIgnored++;

                continue;
            }
            if (! $rowHasDate && str_contains($refUpper, 'SALDO INICIAL') || str_contains($refUpper, 'SALDOS INICIALES')) {
                $opening = $this->amountOrNull($cell('saldo'));

                continue;
            }

            $cargo = $this->amount($cargoRaw, $rowNumber, 'CARGO', $errors);
            $abono = $this->amount($abonoRaw, $rowNumber, 'ABONO', $errors);
            if ($cargo === null || $abono === null) {
                continue; // error ya registrado
            }

            if ($cargo->isZero() && $abono->isZero()) {
                // Línea de detalle de comprobación (facturas/notas de un viático, etc.).
                $detail = array_filter([
                    'fila' => $rowNumber,
                    'observaciones' => $this->text($cell('observaciones')),
                    'estatus' => $this->text($cell('estatus')),
                    'total' => $this->amountOrNull($cell('total')),
                    'concepto_gasto' => $this->text($cell('concepto_gasto')),
                    'uuid' => $this->text($cell('uuid')),
                ], fn ($v) => $v !== null && $v !== '');
                if (count($detail) > 1 && $movements !== []) {
                    $movements[array_key_last($movements)]['detail_lines'][] = $detail;
                    $detailLines++;
                }

                continue;
            }

            if ($cargo->isNegative() || $abono->isNegative()) {
                $errors[] = ['row' => $rowNumber, 'column' => 'CARGO/ABONO', 'message' => "Fila {$rowNumber}: CARGO/ABONO no pueden ser negativos."];

                continue;
            }
            if (! $cargo->isZero() && ! $abono->isZero()) {
                $errors[] = ['row' => $rowNumber, 'column' => 'CARGO/ABONO', 'message' => "Fila {$rowNumber}: tiene CARGO y ABONO a la vez; un movimiento bancario es uno u otro."];

                continue;
            }

            $date = $this->date($cell('fecha'));
            if ($date === null) {
                $errors[] = ['row' => $rowNumber, 'column' => 'FECHA', 'message' => "Fila {$rowNumber}: FECHA vacía o inválida ('".mb_substr((string) $this->text($cell('fecha')), 0, 60)."') en un movimiento con importe."];

                continue;
            }

            $saldo = $this->amountOrNull($cell('saldo'));
            $folioRaw = $this->text($cell('req'));
            $uuid = $this->text($cell('uuid'));

            $baseKey = implode('|', [
                $date, mb_strtoupper(preg_replace('/\s+/', ' ', (string) $reference) ?? ''),
                Money::cents($cargo), Money::cents($abono), $saldo === null ? '' : Money::cents($saldo),
            ]);
            $occurrences[$baseKey] = ($occurrences[$baseKey] ?? 0) + 1;

            $rawPayload = [];
            foreach ($cols as $field => $col) {
                $rawPayload[$field] = $row[$col] ?? null;
            }

            $movements[] = [
                'source_row' => $rowNumber,
                'fingerprint_base' => $baseKey.'|#'.$occurrences[$baseKey],
                'movement_date' => $date,
                'bank_reference' => $reference,
                'cargo' => (string) $cargo->toScale(2),
                'abono' => (string) $abono->toScale(2),
                'saldo' => $saldo,
                'folio_raw' => $folioRaw !== null ? mb_substr($folioRaw, 0, 60) : null,
                'folio_normalized' => FolioNormalizer::normalize($folioRaw),
                'concept' => $this->limit($this->text($cell('concepto')), 255),
                'sucursal_raw' => $this->limit($this->text($cell('sucursal')), 120),
                'beneficiary' => $this->limit($this->text($cell('beneficiario')), 255),
                'observations' => $this->text($cell('observaciones')),
                'status_raw' => $this->limit($this->text($cell('estatus')), 60),
                'total_raw' => $this->amountOrNull($cell('total')),
                'concept_gasto' => $this->limit($this->text($cell('concepto_gasto')), 120),
                'uuid' => $uuid !== null ? mb_strtoupper(mb_substr(trim($uuid), 0, 60)) : null,
                'complemento_pago' => $this->limit($this->text($cell('complemento_pago')), 120),
                'detail_lines' => [],
                'raw_payload' => $rawPayload,
            ];
        }

        if ($footerIgnored > 0) {
            $warnings[] = ['code' => 'pie_ignorado', 'severity' => SpreadsheetSafetyInspector::SEVERITY_INFO, 'message' => "Se ignoraron {$footerIgnored} fila(s) de pie de archivo posteriores a los totales de control."];
        }
        $repeated = array_sum(array_map(fn ($n) => $n - 1, array_filter($occurrences, fn ($n) => $n > 1)));
        if ($repeated > 0) {
            $warnings[] = ['code' => 'movimientos_repetidos', 'severity' => SpreadsheetSafetyInspector::SEVERITY_INFO,
                'message' => "{$repeated} movimiento(s) repiten exactamente fecha, referencia, importe y saldo de otro renglón del archivo: se cargan como movimientos distintos. Verifica que no sean renglones duplicados por error."];
        }
        if ($errors !== []) {
            throw new EstadoResultadosImportException(
                'El archivo tiene '.count($errors).' fila(s) con error. No se cargó ningún movimiento.',
                array_slice(array_column($errors, 'message'), 0, 50),
                array_slice($errors, 0, 1000),
            );
        }
        if ($movements === []) {
            throw new EstadoResultadosImportException('El archivo no contiene movimientos con CARGO o ABONO.');
        }

        return [
            'layout' => $d['layout'],
            'sheet' => $d['sheet'],
            'header_row' => $d['header_row'] + 1,
            'columns' => $cols,
            'movements' => $movements,
            'detail_lines' => $detailLines,
            'rows_read' => $rowsRead,
            'opening_balance' => $opening,
            'control_cargos' => $controlCargos,
            'control_abonos' => $controlAbonos,
            'warnings' => array_column($warnings, 'message'),
            'warnings_detail' => $warnings,
        ];
    }

    private function headerKey(mixed $cell): string
    {
        $v = mb_strtoupper(Str::ascii(trim((string) $cell)));

        return preg_replace('/\s+/', ' ', $v) ?? $v;
    }

    private function amount(mixed $raw, int $row, string $col, array &$errors): ?BigDecimal
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return BigDecimal::zero();
        }
        $value = Money::tryOf($raw);
        if ($value === null) {
            $errors[] = ['row' => $row, 'column' => $col, 'message' => "Fila {$row}: {$col} no es un importe válido ('".mb_substr((string) $raw, 0, 60)."')."];

            return null;
        }

        return $value;
    }

    private function amountOrNull(mixed $raw): ?string
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        $v = Money::tryOf($raw);

        return $v === null ? null : (string) $v->toScale(2, RoundingMode::HALF_UP);
    }

    private function date(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            if (is_numeric($raw)) {
                $serial = (float) $raw;
                if ($serial < 30000 || $serial > 80000) { // 1982..2119 — fuera de eso no es una fecha real
                    return null;
                }

                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject($serial))->format('Y-m-d');
            }
            $text = trim((string) $raw);
            foreach (['d/m/Y', 'd/m/y', 'Y-m-d', 'd-m-Y', 'd.m.Y'] as $format) {
                $dt = \DateTimeImmutable::createFromFormat('!'.$format, $text);
                if ($dt !== false && $dt->format($format) === $text) {
                    return $dt->format('Y-m-d');
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function text(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? (string) $v);

        return $t === '' ? null : $t;
    }

    private function limit(?string $v, int $max): ?string
    {
        return $v === null ? null : mb_substr($v, 0, $max);
    }

    private function isEmpty(array $row): bool
    {
        foreach ($row as $c) {
            if ($c !== null && trim((string) $c) !== '') {
                return false;
            }
        }

        return true;
    }
}
