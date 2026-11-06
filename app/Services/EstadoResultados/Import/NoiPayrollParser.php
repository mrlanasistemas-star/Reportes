<?php

namespace App\Services\EstadoResultados\Import;

use App\Services\EstadoResultados\Support\Money;
use App\Services\EstadoResultados\Support\PersonName;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Lector PURO (sin escribir en la BD) de un archivo NOI para el Estado de Resultados.
 *
 * Supuesto de formato: el NOI de corporativo usa el MISMO layout que el NOI de sucursales que
 * ya importa Carga de archivos (App\Services\Imports\NoiNominaImportService). Este lector
 * REPLICA sus reglas de lectura —no las modifica ni las llama— para que el importador de
 * Radiografía conserve exactamente su comportamiento:
 *   - fila de encabezados = la de mayor puntaje entre las primeras 20 (nombre_del_trabajador,
 *     concepto, acumulado, importe, monto, total);
 *   - alias de columnas: nombre (NOMBRE_TRAB…), concepto (CONCEPTO, DESCRIP_CPTO…), importe
 *     (IMPORTE, MONTO, "Monto del periodo", NETO, TOTAL, ACUMULADO…), tipo (TIPO_CONCEPTO…);
 *   - nombre por encabezado o, si no hay, por posición (columnas B y A); "Clave del trabajador : N"
 *     abre el bloque del colaborador y su nombre/clave se arrastran a los renglones siguientes;
 *   - se ignoran renglones sin nombre, sin concepto, sin importe o en $0, y los conceptos
 *     acumulado/total/subtotal;
 *   - tipo de concepto: columna explícita o inferido (P### percepción, D### deducción, palabras clave);
 *     deducciones D### negativas se guardan en positivo.
 *
 * Diferencia deliberada (para no contar dos veces): además se ignoran los renglones de
 * TOTALES del colaborador ("Total percepciones", "Total deducciones", "Neto a pagar"…), que
 * repetirían importes ya leídos concepto por concepto.
 *
 * Costo de nómina reconocido = Σ PERCEPCIONES (mismo criterio que Radiografía con el NOI de
 * sucursales: concept_type = percepcion). Deducciones y renglones sin tipo se conservan para
 * auditoría pero no se suman.
 */
class NoiPayrollParser
{
    private const MAX_ROWS = 50000;

    private const MAX_COLS = 80;

    public function __construct(private readonly SpreadsheetSafetyInspector $inspector = new SpreadsheetSafetyInspector) {}

    /**
     * @return array{rows_read:int, lines:list<array{source_row:int, employee_code:?string, employee_name:string, name_key:?string, concept:string, concept_type:?string, amount:string}>,
     *               employees:int, total_percepciones:string, total_deducciones:string, warnings:list<array{code:string,severity:string,message:string}>}
     *
     * @throws EstadoResultadosImportException
     */
    public function parse(string $absolutePath, ?string $extension = null): array
    {
        $safety = $this->inspector->inspect($absolutePath, $extension ?: (pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'xlsx'));

        try {
            $reader = IOFactory::createReaderForFile($absolutePath);
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new class(self::MAX_ROWS + 1, self::MAX_COLS) implements IReadFilter
            {
                public function __construct(private int $rows, private int $cols) {}

                public function readCell($columnAddress, $row, $worksheetName = ''): bool
                {
                    return (int) $row <= $this->rows && Coordinate::columnIndexFromString((string) $columnAddress) <= $this->cols;
                }
            });
            $book = $reader->load($absolutePath);
            $sheet = $book->getSheet(0);
            $rows = $sheet->toArray(null, true, false, false);
            $book->disconnectWorksheets();
            unset($book);
        } catch (\Throwable $e) {
            throw new EstadoResultadosImportException('No se pudo leer el archivo como Excel válido (.xlsx/.xls/.xlsm).', [$e->getMessage()]);
        }

        if ($rows === [] || count($rows) > self::MAX_ROWS) {
            throw new EstadoResultadosImportException($rows === [] ? 'El archivo NOI está vacío.' : 'El archivo tiene demasiadas filas para ser un NOI mensual.');
        }

        $headerIndex = $this->detectHeaderRowIndex($rows);
        $map = $this->buildHeaderMap((array) ($rows[$headerIndex] ?? []));
        $missing = array_values(array_diff(['concept', 'amount'], array_keys($map)));
        if ($missing !== []) {
            throw new EstadoResultadosImportException(
                'El archivo no tiene la estructura del NOI: faltan las columnas de '.implode(' e ', array_map(fn ($m) => $m === 'concept' ? 'CONCEPTO' : 'IMPORTE', $missing)).'.',
                ['Sube el reporte de nómina del NOI de corporativo con el mismo formato que el NOI de sucursales (columnas de colaborador, CONCEPTO e IMPORTE/MONTO).'],
            );
        }

        $lines = [];
        $rowsRead = 0;
        $currentName = null;
        $currentCode = null;
        $totalsSkipped = 0;
        $untyped = 0;
        $untypedAmount = Money::zero();
        $perc = Money::zero();
        $ded = Money::zero();
        $employees = [];

        foreach (array_slice($rows, $headerIndex + 1, null, true) as $idx => $row) {
            if (! is_array($row) || $this->isEmptyRow($row)) {
                continue;
            }
            $rowsRead++;
            $m = $this->mapRow($row, $map);
            if ($m['employee_code']) {
                $currentCode = $m['employee_code'];
            }
            if ($this->isValidEmployeeName($m['employee_name'])) {
                $currentName = $m['employee_name'];
            } elseif ($currentName && ($m['concept'] || $m['amount'] !== null)) {
                $m['employee_name'] = $currentName;
            }
            if (! $m['employee_code'] && $currentCode && ($m['concept'] || $m['amount'] !== null)) {
                $m['employee_code'] = $currentCode;
            }
            if (! $this->shouldInsertRow($m)) {
                continue;
            }
            if ($this->isTotalsRow((string) $m['concept'])) {
                $totalsSkipped++;

                continue;
            }

            $type = $m['concept_type'] ? $this->normalizeType($m['concept_type']) : $this->inferConceptType($m['concept']);
            $amount = Money::of($m['amount']);
            if ($type === 'percepcion') {
                $perc = $perc->plus($amount);
            } elseif ($type === 'deduccion') {
                $ded = $ded->plus($amount);
            } else {
                $untyped++;
                $untypedAmount = $untypedAmount->plus($amount);
            }
            $key = PersonName::key($m['employee_name']);
            $employees[$key ?? mb_strtoupper((string) $m['employee_name'])] = true;
            $lines[] = [
                'source_row' => (int) $idx + 1,
                'employee_code' => $m['employee_code'] ? mb_substr($m['employee_code'], 0, 30) : null,
                'employee_name' => mb_substr((string) $m['employee_name'], 0, 255),
                'name_key' => $key !== null ? mb_substr($key, 0, 191) : null,
                'concept' => mb_substr((string) $m['concept'], 0, 255),
                'concept_type' => $type,
                'amount' => Money::str2($amount),
            ];
        }

        if ($lines === []) {
            throw new EstadoResultadosImportException(
                'El archivo no trae renglones de nómina con importe (colaborador, concepto e importe).',
                ['Verifica que sea el reporte de nómina del NOI de corporativo del periodo, no un resumen vacío.'],
            );
        }
        if ($perc->isZero()) {
            throw new EstadoResultadosImportException(
                'El archivo no trae percepciones (conceptos P###, sueldo, bonos…): no hay costo de nómina que reconocer.',
                ['El Estado de Resultados reconoce la suma de percepciones. Verifica que el reporte incluya las percepciones del periodo.'],
            );
        }

        $warnings = $safety['warnings'];
        if ($totalsSkipped > 0) {
            $warnings[] = ['code' => 'totales_ignorados', 'severity' => SpreadsheetSafetyInspector::SEVERITY_INFO,
                'message' => "Se ignoraron {$totalsSkipped} renglón(es) de totales por colaborador (Total percepciones, Neto…) para no contar dos veces."];
        }
        if ($untyped > 0) {
            $warnings[] = ['code' => 'conceptos_sin_tipo', 'severity' => SpreadsheetSafetyInspector::SEVERITY_INFO,
                'message' => "{$untyped} renglón(es) por $".number_format((float) Money::str2($untypedAmount), 2).' no son percepción ni deducción (por ejemplo obligaciones patronales): se conservan pero no se suman.'];
        }

        return [
            'rows_read' => $rowsRead,
            'lines' => $lines,
            'employees' => count($employees),
            'total_percepciones' => Money::str2($perc),
            'total_deducciones' => Money::str2($ded),
            'warnings' => $warnings,
        ];
    }

    // ── Reglas replicadas de NoiNominaImportService (sin modificar el original) ──

    private function detectHeaderRowIndex(array $rows): int
    {
        $bestIndex = 0;
        $bestScore = -1;
        foreach (array_slice($rows, 0, 20, true) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $score = 0;
            foreach ($row as $value) {
                if (in_array($this->normalizeHeader((string) $value), ['nombre_del_trabajador', 'concepto', 'acumulado', 'importe', 'monto', 'total'], true)) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = (int) $index;
            }
        }

        return $bestIndex;
    }

    /** @return array<string,int> */
    private function buildHeaderMap(array $headerRow): array
    {
        $aliases = [
            'employee_name' => ['nombre_trab', 'nombre_trabajador', 'nombre_del_trabajador'],
            'concept' => ['concept', 'concepto', 'descripcion_concepto', 'descrip_cpto', 'descripcion_cpto', 'movimiento', 'descripcion'],
            'concept_type' => ['concept_type', 'tipo_concepto', 'tipo', 'naturaleza', 'tipo_movimiento'],
            'amount' => ['amount', 'importe', 'monto', 'monto_del_periodo', 'neto', 'total', 'importe_neto', 'pago_neto', 'valor', 'acumulado'],
        ];
        $normalized = [];
        foreach ($headerRow as $index => $header) {
            $normalized[(int) $index] = $this->normalizeHeader((string) $header);
        }
        $map = [];
        foreach ($aliases as $field => $options) {
            foreach ($normalized as $index => $h) {
                if (in_array($h, $options, true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    private function mapRow(array $row, array $map): array
    {
        $col0 = $this->clean($row[0] ?? null);
        $col1 = $this->clean($row[1] ?? null);
        $byHeader = isset($map['employee_name']) ? $this->clean($row[$map['employee_name']] ?? null) : null;
        $name = match (true) {
            $this->isValidEmployeeName($byHeader) => $byHeader,
            $this->isValidEmployeeName($col1) => $col1,
            $this->isValidEmployeeName($col0) => $col0,
            default => null,
        };
        $concept = isset($map['concept']) ? $this->clean($row[$map['concept']] ?? null) : null;
        $amount = isset($map['amount']) ? $this->toDecimal($row[$map['amount']] ?? null) : null;
        if ($amount !== null && $concept && preg_match('/^D\d{3}\b/i', trim($concept)) && $amount < 0) {
            $amount = abs($amount);
        }

        return [
            'employee_code' => $this->extractEmployeeCode($col1) ?? $this->extractEmployeeCode($col0),
            'employee_name' => $name,
            'concept' => $concept,
            'concept_type' => isset($map['concept_type']) ? $this->clean($row[$map['concept_type']] ?? null) : null,
            'amount' => $amount,
        ];
    }

    private function shouldInsertRow(array $m): bool
    {
        if (! $this->isValidEmployeeName($m['employee_name']) || ! $m['concept'] || $m['amount'] === null || (float) $m['amount'] == 0.0) {
            return false;
        }

        return ! in_array($this->normalizeText((string) $m['concept']), ['acumulado', 'total', 'totales', 'subtotal', 'sub total'], true);
    }

    private function isTotalsRow(string $concept): bool
    {
        $c = $this->normalizeText($concept);

        return (bool) preg_match('/^(total|totales|sub ?total)\b/', $c) || in_array($c, ['neto', 'neto a pagar', 'neto pagado', 'neto del periodo'], true);
    }

    private function normalizeType(string $type): ?string
    {
        $t = $this->normalizeText($type);

        return match (true) {
            str_starts_with($t, 'percep'), $t === 'p' => 'percepcion',
            str_starts_with($t, 'deduc'), $t === 'd' => 'deduccion',
            default => $this->inferConceptType($type),
        };
    }

    private function inferConceptType(?string $concept): ?string
    {
        if (! $concept) {
            return null;
        }
        $n = strtoupper(trim($concept));
        if (preg_match('/^P\d{3}\b/', $n)) {
            return 'percepcion';
        }
        if (preg_match('/^D\d{3}\b/', $n)) {
            return 'deduccion';
        }
        $l = strtolower($n);
        foreach (['sueldo', 'salario', 'vacacion', 'bono', 'prima', 'compensacion', 'percepcion'] as $w) {
            if (str_contains($l, $w)) {
                return 'percepcion';
            }
        }
        foreach (['descuento', 'prestamo', 'deduccion', 'imss', 'isr', 'impuesto'] as $w) {
            if (str_contains($l, $w)) {
                return 'deduccion';
            }
        }

        return null;
    }

    private function extractEmployeeCode(?string $value): ?string
    {
        return $value && preg_match('/clave\s+del\s+trabajador\s*:\s*(\d+)/i', $value, $mm) ? $mm[1] : null;
    }

    private function isValidEmployeeName(?string $value): bool
    {
        if (! $value) {
            return false;
        }
        $value = trim($value);
        if ($value === '' || $value === '-' || $value === '--' || stripos($value, 'clave del trabajador') !== false) {
            return false;
        }
        $n = $this->normalizeText($value);

        return $n !== '' && ! in_array($n, ['nombre', 'trabajador', 'empleado', 'nombre del trabajador', 'nombre trabajador', 'nombre empleado'], true);
    }

    private function normalizeHeader(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();
    }

    private function normalizeText(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9\s]+/', ' ')->replaceMatches('/\s+/', ' ')->trim()->value();
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string) $value);

        return $v !== '' ? $v : null;
    }

    private function toDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $v = str_replace(['$', ' '], '', (string) $value);
        if (str_contains($v, ',') && str_contains($v, '.')) {
            $v = str_replace(',', '', $v);
        } elseif (str_contains($v, ',')) {
            $v = str_replace(',', '.', $v);
        }

        return is_numeric($v) ? (float) $v : null;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                return false;
            }
        }

        return true;
    }
}
