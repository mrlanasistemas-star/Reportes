<?php

namespace App\Services\EstadoResultados\FinancialExpenses;

use App\Services\EstadoResultados\Import\EstadoResultadosImportException;
use App\Services\EstadoResultados\Import\SpreadsheetSafetyInspector;
use App\Services\EstadoResultados\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Lector TOLERANTE de tablas de amortización (.xlsx / .xlsm / .xls / .csv).
 *
 *  - Seguridad: el contenedor Excel pasa por SpreadsheetSafetyInspector ANTES de cargarse
 *    (bombas ZIP, macros, vínculos externos) y se lee en modo SÓLO DATOS con límites de
 *    filas/columnas. Las fórmulas internas se toman con su valor calculado.
 *  - Encabezados: se busca en las primeras 30 filas de cada hoja la fila que mejor coincide
 *    con los sinónimos de AmortizationColumnMap (fecha + interés obligatorios).
 *  - Renglones: se ignoran filas vacías y de totales. Falta de fecha o de interés (o importes
 *    no numéricos / negativos) ⇒ error POR FILA y se rechaza el archivo completo (nunca
 *    queda una tabla a medias). Interés + capital (+ IVA) ≠ pago ⇒ AVISO por fila.
 *  - Capital ausente: se deduce como pago − interés − IVA. Pago ausente: capital + interés + IVA.
 *
 * El formato real de LEROM/BBVA aún no llega: los sinónimos se ajustan en AmortizationColumnMap.
 */
class AmortizationScheduleParser
{
    /** Filas que se revisan para encontrar el encabezado. */
    private const HEADER_SCAN_ROWS = 30;

    /** Abreviaturas de mes (español e inglés) para fechas en texto. */
    private const MONTHS = [
        'ENE' => 1, 'FEB' => 2, 'MAR' => 3, 'ABR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AGO' => 8,
        'SEP' => 9, 'SET' => 9, 'OCT' => 10, 'NOV' => 11, 'DIC' => 12, 'JAN' => 1, 'APR' => 4, 'AUG' => 8, 'DEC' => 12,
    ];

    /** @param  SpreadsheetSafetyInspector  $inspector  revisión del contenedor Excel */
    public function __construct(private readonly SpreadsheetSafetyInspector $inspector) {}

    /**
     * Lee la tabla de amortización: elige la hoja/fila de encabezado con mejor coincidencia,
     * lee los renglones y rechaza el archivo completo si hay errores por fila. No toca BD.
     *
     * @param  string  $absolutePath  ruta del archivo
     * @param  string  $extension  extensión (xlsx, xlsm, xls, csv)
     * @return array{
     *   sheet:string, header_row:int, columns:array<string,string>,
     *   rows:list<array{source_row:int, payment_number:?int, payment_date:string, payment_total:string, principal:string, interest:string, interest_vat:?string, balance:?string}>,
     *   warnings:list<array{code:string,severity:string,message:string}>
     * }
     *
     * @throws EstadoResultadosImportException
     */
    public function parse(string $absolutePath, string $extension): array
    {
        $ext = mb_strtolower($extension);
        $warnings = [];
        $maxRows = max(1, (int) config('estado_resultados.import.max_rows', 50000));
        $maxCols = max(1, (int) config('estado_resultados.import.max_columns', 100));

        try {
            if ($ext === 'csv') {
                $reader = new Csv;
                $reader->setInputEncoding(Csv::GUESS_ENCODING);
                $reader->setDelimiter(null); // autodetección (, ; tab |)
            } else {
                $safety = $this->inspector->inspect($absolutePath, $ext);
                $warnings = $safety['warnings'];
                $reader = IOFactory::createReaderForFile($absolutePath);
                $reader->setReadDataOnly(true);
            }
            $reader->setReadFilter(new class($maxRows + 1, $maxCols) implements IReadFilter
            {
                /**
                 * @param  int  $rows  filas máximas a cargar
                 * @param  int  $cols  columnas máximas a cargar
                 */
                public function __construct(private int $rows, private int $cols) {}

                /** Sólo carga celdas dentro de los límites de filas/columnas. */
                public function readCell($columnAddress, $row, $worksheetName = ''): bool
                {
                    return (int) $row <= $this->rows && Coordinate::columnIndexFromString((string) $columnAddress) <= $this->cols;
                }
            });
            $book = $reader->load($absolutePath);
        } catch (EstadoResultadosImportException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new EstadoResultadosImportException('No se pudo leer el archivo como Excel o CSV válido.', [$e->getMessage()]);
        }

        $best = null;
        foreach ($book->getSheetNames() as $index => $name) {
            $sheet = $book->getSheet($index);
            if ($sheet->getHighestDataRow() > $maxRows) {
                throw new EstadoResultadosImportException('La hoja "'.$name.'" tiene más de '.number_format($maxRows).' filas: no parece una tabla de amortización.');
            }
            $values = $sheet->toArray(null, true, false, false);
            foreach (array_slice($values, 0, self::HEADER_SCAN_ROWS, true) as $rowIdx => $row) {
                $map = AmortizationColumnMap::map((array) $row);
                if (array_diff(AmortizationColumnMap::REQUIRED, array_keys($map)) !== []) {
                    continue;
                }
                if ($best === null || count($map) > count($best['map'])) {
                    $best = ['sheet' => $name, 'header_row' => (int) $rowIdx, 'map' => $map, 'values' => $values, 'header' => (array) $row];
                }
            }
            if ($best !== null) {
                break; // primera hoja con encabezados reconocibles
            }
        }
        $book->disconnectWorksheets();
        unset($book);

        if ($best === null) {
            throw new EstadoResultadosImportException(
                'No se encontró la fila de encabezados de la tabla de amortización.',
                [
                    'Se necesitan al menos las columnas de FECHA DE PAGO e INTERÉS (también se leen: No. de pago, Pago total, Capital, IVA de interés y Saldo).',
                    'Nombres aceptados, por ejemplo: «Fecha», «Fecha de pago», «Vencimiento»; «Interés», «Intereses», «Interés ordinario».',
                    'Descarga la plantilla de ejemplo si tu archivo usa otros nombres.',
                ],
            );
        }

        $map = $best['map'];
        if (! isset($map['pago']) && ! isset($map['capital'])) {
            throw new EstadoResultadosImportException(
                'La tabla no tiene columna de PAGO TOTAL ni de CAPITAL: no se puede separar el capital del interés.',
                ['Agrega al menos una de las dos columnas («Pago», «Cuota», «Pago total» o «Capital», «Abono a capital»).'],
            );
        }
        $columns = [];
        foreach ($map as $field => $col) {
            $columns[$field] = Coordinate::stringFromColumnIndex($col + 1).' · '.trim((string) ($best['header'][$col] ?? ''));
        }

        [$rows, $errors, $mismatch] = $this->readRows($best['values'], $best['header_row'], $map);

        if ($errors !== []) {
            throw new EstadoResultadosImportException(
                'La tabla de amortización tiene '.count($errors).' error(es) por renglón. No se cargó nada; corrígelos y vuelve a subirla.',
                array_slice(array_column($errors, 'message'), 0, 50),
                array_slice($errors, 0, 1000),
            );
        }
        if ($rows === []) {
            throw new EstadoResultadosImportException('La tabla de amortización no tiene renglones de pago debajo de los encabezados.');
        }
        if ($mismatch !== []) {
            $warnings[] = ['code' => 'pago_no_cuadra', 'severity' => SpreadsheetSafetyInspector::SEVERITY_INFO,
                'message' => count($mismatch).' renglón(es) donde interés + capital (+ IVA) no suma el pago total (diferencia mayor a $'.self::tolerance().'): '
                    .implode('; ', array_slice($mismatch, 0, 8)).(count($mismatch) > 8 ? '…' : '').'. Se usó el interés de la tabla tal cual.'];
        }
        if (! isset($map['capital'])) {
            $warnings[] = ['code' => 'capital_deducido', 'severity' => SpreadsheetSafetyInspector::SEVERITY_INFO,
                'message' => 'La tabla no trae columna de capital: se calculó como pago − interés − IVA.'];
        }
        $dates = array_column($rows, 'payment_date');
        if (count($dates) !== count(array_unique($dates))) {
            $warnings[] = ['code' => 'fechas_repetidas', 'severity' => SpreadsheetSafetyInspector::SEVERITY_INFO,
                'message' => 'Hay pagos con la misma fecha; revisa que la tabla no esté duplicada.'];
        }

        return ['sheet' => $best['sheet'], 'header_row' => $best['header_row'] + 1, 'columns' => $columns, 'rows' => $rows, 'warnings' => $warnings];
    }

    /** Tolerancia del cuadre interés + capital (+ IVA) = pago, en pesos (config). */
    public static function tolerance(): string
    {
        return Money::str2(config('estado_resultados.gastos_financieros.tolerancia_cuadre', '0.05'));
    }

    /**
     * Lee los renglones bajo el encabezado: ignora vacíos y totales; exige fecha e interés
     * válidos (importes no negativos); deduce capital o pago si faltan; marca descuadres
     * interés + capital (+ IVA) ≠ pago mayores a la tolerancia.
     *
     * @param  array  $values  valores de la hoja
     * @param  int  $headerRow  índice de la fila de encabezado
     * @param  array<string,int>  $map  campo ⇒ índice de columna
     * @return array{0:list<array<string,mixed>>, 1:list<array{row:int,column:?string,message:string}>, 2:list<string>} [renglones, errores, descuadres]
     */
    private function readRows(array $values, int $headerRow, array $map): array
    {
        $rows = [];
        $errors = [];
        $mismatch = [];
        $tol = Money::of(self::tolerance());
        $letter = fn (string $f) => isset($map[$f]) ? Coordinate::stringFromColumnIndex($map[$f] + 1) : null;
        $blank = fn ($v) => $v === null || trim((string) $v) === '';

        foreach ($values as $idx => $row) {
            if ($idx <= $headerRow) {
                continue;
            }
            $row = (array) $row;
            $excelRow = $idx + 1;
            $cell = fn (string $f) => isset($map[$f]) ? ($row[$map[$f]] ?? null) : null;
            $nonEmpty = array_filter($row, fn ($v) => ! $blank($v));
            if ($nonEmpty === []) {
                continue;
            }
            // Renglón de totales o notas al pie: sin fecha y con texto «TOTAL» / sin importes.
            $rawDate = $cell('fecha');
            $text = mb_strtoupper(implode(' ', array_map(fn ($v) => (string) $v, $nonEmpty)));
            if ($blank($rawDate) && (str_contains($text, 'TOTAL') || str_contains($text, 'SUMA'))) {
                continue;
            }
            if ($blank($rawDate) && $blank($cell('interes')) && $blank($cell('pago')) && $blank($cell('capital'))) {
                continue; // texto suelto (notas) sin importes
            }

            $rowErrors = [];
            $date = self::date($rawDate);
            if ($date === null) {
                $rowErrors[] = ['row' => $excelRow, 'column' => $letter('fecha'), 'message' => "Fila {$excelRow}: falta la fecha de pago o no es una fecha válida ('".mb_substr((string) $rawDate, 0, 40)."'). Usa dd/mm/aaaa."];
            }
            $amount = function (string $field, bool $required) use ($cell, $excelRow, $letter, $blank, &$rowErrors): ?BigDecimal {
                $raw = $cell($field);
                $label = AmortizationColumnMap::LABELS[$field];
                if ($blank($raw)) {
                    if ($required) {
                        $rowErrors[] = ['row' => $excelRow, 'column' => $letter($field), 'message' => "Fila {$excelRow}: falta el importe de ".mb_strtolower($label).'.'];
                    }

                    return null;
                }
                $v = Money::tryOf($raw);
                if ($v === null) {
                    $rowErrors[] = ['row' => $excelRow, 'column' => $letter($field), 'message' => "Fila {$excelRow}: {$label} no es un importe válido ('".mb_substr((string) $raw, 0, 40)."')."];

                    return null;
                }
                if ($v->isNegative()) {
                    $rowErrors[] = ['row' => $excelRow, 'column' => $letter($field), 'message' => "Fila {$excelRow}: {$label} no puede ser negativo."];

                    return null;
                }

                return $v->toScale(2, RoundingMode::HALF_UP);
            };
            $interest = $amount('interes', true);
            $pago = $amount('pago', false);
            $capital = $amount('capital', false);
            $iva = $amount('iva', false);
            $saldo = $amount('saldo', false);

            if ($rowErrors !== []) {
                array_push($errors, ...$rowErrors);

                continue;
            }
            $ivaZ = $iva ?? BigDecimal::zero();
            if ($capital === null) {
                $capital = $pago === null ? BigDecimal::zero() : $pago->minus($interest)->minus($ivaZ);
                if ($capital->isNegative()) {
                    $errors[] = ['row' => $excelRow, 'column' => $letter('pago'), 'message' => "Fila {$excelRow}: el interés (".Money::str2($interest).') es mayor que el pago ('.Money::str2($pago).') y no hay columna de capital.'];

                    continue;
                }
            }
            if ($pago === null) {
                $pago = $capital->plus($interest)->plus($ivaZ);
            } else {
                // Se acepta el pago con IVA incluido o sin él (algunas tablas muestran el IVA aparte).
                $withVat = $capital->plus($interest)->plus($ivaZ);
                $withoutVat = $capital->plus($interest);
                if ($withVat->minus($pago)->abs()->isGreaterThan($tol) && $withoutVat->minus($pago)->abs()->isGreaterThan($tol)) {
                    $mismatch[] = "fila {$excelRow} (".Money::str2($withVat).' vs '.Money::str2($pago).')';
                }
            }
            $number = $cell('numero');
            $rows[] = [
                'source_row' => $excelRow,
                'payment_number' => is_numeric($number) && (float) $number >= 0 && (float) $number < 100000 ? (int) $number : null,
                'payment_date' => $date,
                'payment_total' => Money::str2($pago),
                'principal' => Money::str2($capital),
                'interest' => Money::str2($interest),
                'interest_vat' => $iva === null ? null : Money::str2($iva),
                'balance' => $saldo === null ? null : Money::str2($saldo),
            ];
        }

        return [$rows, $errors, $mismatch];
    }

    /**
     * Fecha de pago a Y-m-d desde DateTime, número de serie de Excel o texto (incluye meses
     * abreviados en español/inglés).
     *
     * @param  mixed  $raw  valor de la celda
     * @return string|null null si no es una fecha válida
     */
    public static function date(mixed $raw): ?string
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }
        try {
            if (is_numeric($raw)) {
                $serial = (float) $raw;
                if ($serial < 30000 || $serial > 80000) { // 1982..2119 — fuera de eso no es una fecha real
                    return null;
                }

                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject($serial))->format('Y-m-d');
            }
            $text = trim(preg_replace('/\s+/', ' ', (string) $raw) ?? (string) $raw);
            $text = preg_replace('/ 00:00(:00)?$/', '', $text) ?? $text;
            foreach (['d/m/Y', 'd/m/y', 'Y-m-d', 'd-m-Y', 'd.m.Y', 'Y/m/d'] as $format) {
                $dt = \DateTimeImmutable::createFromFormat('!'.$format, $text);
                if ($dt !== false && $dt->format($format) === $text) {
                    return $dt->format('Y-m-d');
                }
            }
            // 05-sep-2026 · 5 SEP 2026 · 05/Sep/26
            if (preg_match('/^(\d{1,2})[\s\/\-.]+(\p{L}{3})\p{L}*\.?[\s\/\-.]+(\d{2,4})$/u', $text, $m)) {
                $mon = self::MONTHS[strtr(mb_strtoupper($m[2]), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U'])] ?? null;
                $year = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];
                if ($mon && checkdate($mon, (int) $m[1], $year)) {
                    return sprintf('%04d-%02d-%02d', $year, $mon, (int) $m[1]);
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
