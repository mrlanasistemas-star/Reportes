<?php

namespace App\Services\EstadoResultados\Import;

use App\Services\EstadoResultados\Support\FolioNormalizer;
use App\Services\EstadoResultados\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

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
 */
class BankStatementParser
{
    public const LAYOUT_ADMINISTRATIVA = 'administrativa';

    public const LAYOUT_EXCEDENTES = 'excedentes';

    /** Límite defensivo de filas por hoja (un estado de cuenta mensual real tiene cientos). */
    public const MAX_ROWS = 50000;

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
    public function parse(string $absolutePath): array
    {
        try {
            $reader = IOFactory::createReaderForFile($absolutePath);
            $reader->setReadDataOnly(true);
            $book = $reader->load($absolutePath);
        } catch (\Throwable $e) {
            throw new EstadoResultadosImportException('No se pudo leer el archivo como Excel válido (.xlsx/.xls/.xlsm).', [$e->getMessage()]);
        }

        $detected = null;
        foreach ($book->getSheetNames() as $index => $name) {
            $sheet = $book->getSheet($index);
            if ($sheet->getHighestDataRow() > self::MAX_ROWS) {
                throw new EstadoResultadosImportException(
                    'La hoja "'.$name.'" tiene más de '.number_format(self::MAX_ROWS).' filas: no parece un estado de cuenta mensual.',
                    ['Verifica que el archivo sea el estado de cuenta del periodo y no un acumulado.']
                );
            }
            $rows = $sheet->toArray(null, true, false, false);
            $header = $this->detectHeader($rows);
            if ($header !== null) {
                $detected = ['sheet' => $name, 'rows' => $rows] + $header;
                break;
            }
        }

        if ($detected === null) {
            throw new EstadoResultadosImportException(
                'Estructura no reconocida: no se encontró una fila de encabezados con FECHA, CARGO y ABONO.',
                [
                    'Cuenta administrativa: FECHA, REFERENCIA BANCA, CARGO, ABONO, SALDO, REQ, CONCEPTO, SUCURSAL, BENEFICIARIO, OBSERVACIONES…',
                    'Cuenta de excedentes: FECHA, DESCRIPCION Y REFERENCIA, CARGO, ABONO, SALDO, DETALLE, SUCURSAL, OBSERVACIONES.',
                ]
            );
        }

        return $this->readRows($detected);
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
            $warnings[] = "Se ignoraron {$footerIgnored} fila(s) de pie de archivo posteriores a los totales de control.";
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
            'warnings' => $warnings,
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
