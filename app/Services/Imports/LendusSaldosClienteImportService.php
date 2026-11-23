<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Portfolio;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Services\ColumnMapService;
use App\Support\ReportUploadStorage;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Importador del reporte "Saldos por Cliente" de Lendus (fuente
 * `lendus_saldos_cliente`): foto de CARTERA y MORA del periodo (fact_portfolios).
 *
 * Flujo: lee la primera hoja, detecta el renglón de encabezados por puntaje,
 * mapea columnas solo con alias autorizados de ColumnMapService, borra la cartera
 * previa del upload e inserta un registro por contrato.
 * Reglas de negocio:
 * - Valor de cartera = "Saldo capital" (balance); préstamo activo = capital_activo.
 * - Mora (past_due_balance) = suma de los componentes autorizados: capital
 *   atrasado + interés e IVA atrasados + interés moratorio e IVA + cargos de
 *   calendario atrasados e IVA.
 * - Se excluyen productos de seguro y filas sin saldo, mora ni capital activo.
 * - Sucursal: prefijo del contrato → prefijo del promotor → ruta/oficina → nombre directo.
 * - También guarda los campos que usa la Efectividad de Cobranza Aprobada
 *   (estatus, substatus, fechas de pago, cuota, saldos vencidos).
 * Lo invoca ReportAnalysisService al procesar un upload de esta fuente.
 */
class LendusSaldosClienteImportService
{
    // Máx 20 chars — columna `importer_version` es VARCHAR(20) en MySQL/MariaDB real
    // (SQLite, usado en tests, no valida longitud y deja pasar strings más largos sin error).
    public const IMPORTER_VERSION = 'ea_aprobada_2026_10';

    /**
     * @param  BranchResolverService  $branchResolver  Resuelve sucursal, normaliza productos y detecta seguros.
     * @param  ColumnMapService  $columnMap  Catálogo de columnas autorizadas por fuente.
     */
    public function __construct(
        private readonly BranchResolverService $branchResolver,
        private readonly ColumnMapService $columnMap,
    ) {}

    /**
     * Importa el archivo del upload a fact_portfolios.
     * Efectos: borra e inserta filas de fact_portfolios del upload, limpia la caché
     * de BranchResolverService y puede crear sucursales.
     *
     * @param  ReportUpload  $upload  Archivo subido a importar.
     * @param  callable|null  $progress  Callback opcional con estadísticas parciales cada 250 filas.
     * @return array<string,mixed> Estadísticas (leídas, insertadas, omitidas, con error) y log.
     *
     * @throws \RuntimeException Si falta el archivo, está vacío, no hay columnas de saldo/mora o no se insertó ninguna fila.
     */
    public function handle(ReportUpload $upload, ?callable $progress = null): array
    {
        if (! $upload->stored_path) {
            throw new \RuntimeException('El archivo de saldos por cliente no tiene ruta de almacenamiento.');
        }

        if (! ReportUploadStorage::exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico de saldos por cliente no existe en storage/public.');
        }

        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);

        $absolutePath = ReportUploadStorage::path($upload->stored_path);
        $sheets = Excel::toArray([], $absolutePath);
        $rows = $sheets[0] ?? [];

        if (empty($rows)) {
            throw new \RuntimeException('El archivo de saldos está vacío o no se pudo leer.');
        }

        $headerIndex = $this->detectHeaderRowIndex($rows);
        $header = $rows[$headerIndex] ?? [];
        $map = $this->buildHeaderMap($header);

        if (! array_key_exists('balance', $map) && ! array_key_exists('capital_due', $map) && ! array_key_exists('capital_activo', $map)) {
            throw new \RuntimeException(
                'El archivo de saldos no contiene columnas reconocibles de saldo/cartera/mora. Encabezados detectados: '
                .implode(', ', array_values(array_filter(array_map(fn ($v) => trim((string) $v), $header))))
            );
        }

        Portfolio::query()
            ->where('report_upload_id', $upload->id)
            ->delete();

        $this->branchResolver->clearCache();

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
                $balance = $this->toDecimal($this->value($row, $map, 'balance')) ?? 0;
                $capitalActivo = $this->toDecimal($this->value($row, $map, 'capital_activo'));
                $pastDue = 0.0; // se calcula abajo con los componentes de mora

                // Contrato/código de cliente — se usa para resolver la sucursal real.
                // Fallback: algunos exportes legacy de Lendus solo traen "Cliente" (= contrato).
                $contract = $this->clean($this->value($row, $map, 'contract'))
                    ?? $this->clean($this->value($row, $map, 'client_code'));

                // Campos del promotor
                $promoterCode = $this->clean($this->value($row, $map, 'promoter_code'));
                $promoterName = $this->clean($this->value($row, $map, 'promoter_name'));

                // Si solo hay una columna de promotor y parece código, se intercambia
                if ($promoterName && ! $promoterCode && $this->looksLikeCode($promoterName)) {
                    $promoterCode = $promoterName;
                    $promoterName = null;
                }

                // Nombre de ruta (oficina/ruta — NO es la sucursal real)
                $routeName = $this->clean($this->value($row, $map, 'route_name'));

                // Datos del producto
                $rawProduct = $this->clean($this->value($row, $map, 'product_name'));
                $numPayments = (int) ($this->toDecimal($this->value($row, $map, 'num_payments')) ?? 0) ?: null;
                $periodicity = $this->clean($this->value($row, $map, 'periodicity'));

                $productName = $rawProduct
                    ? $this->branchResolver->normalizeProduct($rawProduct, $numPayments, $periodicity)
                    : null;

                // Excluye seguros y reestructuras de los registros principales
                if ($productName && $this->branchResolver->isInsuranceProduct($productName)) {
                    $stats['rows_skipped']++;

                    continue;
                }

                // Componentes de mora (columnas autorizadas B)
                $capitalDue = $this->toDecimal($this->value($row, $map, 'capital_due'));
                $interesAtrasado = $this->toDecimal($this->value($row, $map, 'interes_atrasado')) ?? 0.0;
                $impuestoAtrasado = $this->toDecimal($this->value($row, $map, 'impuesto_atrasado')) ?? 0.0;
                $saldoInteresMor = $this->toDecimal($this->value($row, $map, 'saldo_interes_moratorio')) ?? 0.0;
                $saldoImpuestoMor = $this->toDecimal($this->value($row, $map, 'saldo_impuesto_interes_moratorio')) ?? 0.0;
                $cargosCalAtrasado = $this->toDecimal($this->value($row, $map, 'cargos_calendario_atrasado')) ?? 0.0;
                $impuestoCargosCalAtr = $this->toDecimal($this->value($row, $map, 'impuesto_cargos_calendario_atrasado')) ?? 0.0;

                // Mora total = suma de todos los componentes autorizados
                $moraFromComponents = ($capitalDue ?? 0.0) + $interesAtrasado + $impuestoAtrasado
                    + $saldoInteresMor + $saldoImpuestoMor + $cargosCalAtrasado + $impuestoCargosCalAtr;
                $pastDue = $moraFromComponents > 0 ? round($moraFromComponents, 2) : 0.0;

                if ($balance <= 0 && $pastDue <= 0 && ($capitalActivo === null || $capitalActivo <= 0)) {
                    $stats['rows_skipped']++;

                    continue;
                }

                // Días de atraso — se usa la columna Días Vencido
                $daysPastDue = (int) ($this->toDecimal($this->value($row, $map, 'days_past_due')) ?? 0);

                // Si capital_due es null pero hay días vencidos, se toma de past_due_balance
                if ($capitalDue === null && $daysPastDue > 0 && $pastDue > 0) {
                    $capitalDue = $pastDue;
                }

                // Sucursal: primero prefijo del contrato → luego código de promotor → luego columna de ruta
                $branch = $this->resolveBranchFromSources($contract, $promoterCode, $routeName);

                $clientName = $this->clean($this->value($row, $map, 'client_name'));

                // ── Campos de la macro Efectividad de Cobranza Aprobada ──────
                $status = $this->clean($this->value($row, $map, 'status'));
                $substatus = $this->clean($this->value($row, $map, 'substatus'));
                $contractDueDate = $this->toDate($this->value($row, $map, 'contract_due_date'));
                $firstPaymentDate = $this->toDate($this->value($row, $map, 'first_payment_date'));
                $nextPaymentDate = $this->toDate($this->value($row, $map, 'next_payment_date'));
                $installmentAmount = $this->toDecimal($this->value($row, $map, 'installment_amount'));
                $overdueInstallments = (int) ($this->toDecimal($this->value($row, $map, 'overdue_installments')) ?? 0) ?: null;
                $totalOverdueRaw = $this->toDecimal($this->value($row, $map, 'total_overdue_raw'));
                $totalPendingRaw = $this->toDecimal($this->value($row, $map, 'total_pending_raw'));
                $saldoInteresVencido = $this->toDecimal($this->value($row, $map, 'saldo_interes_vencido'));
                $saldoImpuestoVencido = $this->toDecimal($this->value($row, $map, 'saldo_impuesto_vencido'));
                $saldoCargoCalVencido = $this->toDecimal($this->value($row, $map, 'saldo_cargo_calendario_vencido'));
                $saldoImpuestoCargoCalVenc = $this->toDecimal($this->value($row, $map, 'saldo_impuesto_cargo_calendario_vencido'));
                // "Saldo capital" ya fue resuelto arriba como $balance (alias 'saldo_capital' de
                // ColumnMapService::balance). Se copia aquí para exponerlo con el nombre que
                // espera la macro Efectividad Aprobada, sin tocar el alias de 'balance'.
                $saldoCapitalVencido = $balance > 0 ? $balance : null;

                Portfolio::query()->create([
                    'period_id' => $upload->period_id,
                    'report_upload_id' => $upload->id,
                    'branch_id' => $branch?->id,
                    'client_name' => $clientName,
                    'normalized_client_name' => $this->normalize($clientName),
                    'contract' => $contract,
                    'promoter_name' => $promoterName,
                    'promoter_code' => $promoterCode,
                    'product_name' => $productName,
                    'num_payments' => $numPayments,
                    'periodicity' => $periodicity,
                    'route_name' => $routeName,
                    // D) Valor cartera
                    'balance' => $balance,
                    // C) Préstamo activo
                    'capital_activo' => $capitalActivo,
                    // B) Mora total + componentes
                    'past_due_balance' => $pastDue,
                    'capital_due' => $capitalDue,
                    'interes_atrasado' => $interesAtrasado ?: null,
                    'impuesto_atrasado' => $impuestoAtrasado ?: null,
                    'saldo_interes_moratorio' => $saldoInteresMor ?: null,
                    'saldo_impuesto_interes_moratorio' => $saldoImpuestoMor ?: null,
                    'cargos_calendario_atrasado' => $cargosCalAtrasado ?: null,
                    'impuesto_cargos_calendario_atrasado' => $impuestoCargosCalAtr ?: null,
                    'days_past_due' => $daysPastDue,
                    'portfolio_date' => $this->toDate($this->value($row, $map, 'portfolio_date')),
                    // Campos de la macro Efectividad de Cobranza Aprobada (Fase 3/4/5)
                    'status' => $status,
                    'substatus' => $substatus,
                    'first_payment_date' => $firstPaymentDate,
                    'next_payment_date' => $nextPaymentDate,
                    'contract_due_date' => $contractDueDate,
                    'installment_amount' => $installmentAmount,
                    'overdue_installments' => $overdueInstallments,
                    'total_overdue_raw' => $totalOverdueRaw,
                    'total_pending_raw' => $totalPendingRaw,
                    'saldo_capital_vencido' => $saldoCapitalVencido,
                    'saldo_interes_vencido' => $saldoInteresVencido,
                    'saldo_impuesto_vencido' => $saldoImpuestoVencido,
                    'saldo_cargo_calendario_vencido' => $saldoCargoCalVencido,
                    'saldo_impuesto_cargo_calendario_vencido' => $saldoImpuestoCargoCalVenc,
                    'importer_version' => self::IMPORTER_VERSION,
                    'raw_payload' => null,
                ]);

                $stats['rows_inserted']++;
            } catch (\Throwable) {
                $stats['rows_with_errors']++;
            }

            if ($progress && $stats['rows_read'] % 250 === 0) {
                $progress($stats + ['log' => "Integrando saldos por cliente... {$stats['rows_read']} filas leídas."]);
            }
        }

        if ($stats['rows_inserted'] <= 0) {
            throw new \RuntimeException(
                'El archivo de saldos fue leído, pero no generó registros útiles. '
                .'Revisa que exista una columna de saldo/cartera con valores mayores a 0. '
                .'Filas leídas: '.$stats['rows_read'].', omitidas: '.$stats['rows_skipped'].'.'
            );
        }

        return $stats + [
            'log' => sprintf(
                'Importación de saldos por cliente finalizada. Leídas: %d, insertadas: %d, omitidas: %d, con error: %d.',
                $stats['rows_read'],
                $stats['rows_inserted'],
                $stats['rows_skipped'],
                $stats['rows_with_errors'],
            ),
        ];
    }

    /**
     * Resuelve la sucursal REAL del contrato en orden de prioridad: prefijo del
     * contrato (p. ej. "ORI09247" → ORIZABA), prefijo del código de promotor, ruta
     * de la oficina convertida a sucursal oficial y, como último recurso, búsqueda
     * directa por nombre.
     *
     * @param  string|null  $contract  Número de contrato (o código de cliente en exportes antiguos).
     * @param  string|null  $promoterCode  Código del promotor.
     * @param  string|null  $routeName  Oficina/ruta (no es la sucursal real).
     * @return Branch|null Sucursal resuelta (puede crearse) o null.
     */
    private function resolveBranchFromSources(
        ?string $contract,
        ?string $promoterCode,
        ?string $routeName,
    ): ?Branch {
        // 1. Prefijo del contrato (p. ej. "ORI09247" → ORIZABA)
        if ($contract) {
            $branch = $this->branchResolver->findOrCreateBranchByCode($contract);
            if ($branch) {
                return $branch;
            }
        }

        // 2. Prefijo del código de promotor (p. ej. "ORI09247" → ORIZABA)
        if ($promoterCode) {
            $branch = $this->branchResolver->findOrCreateBranchByCode($promoterCode);
            if ($branch) {
                return $branch;
            }
        }

        // 3. Respaldo con la columna ruta/oficina: primero sucursal REAL, luego búsqueda en BD
        if ($routeName) {
            $realBranchName = $this->branchResolver->resolveRealBranchFromRoute($routeName);
            if ($realBranchName) {
                return $this->branchResolver->findOrCreateBranchByName($realBranchName);
            }
            // Último recurso: búsqueda directa por nombre (cuando la ruta ES el nombre de la sucursal)
            $normalized = $this->normalize($routeName);

            return Branch::query()
                ->where('normalized_name', $normalized)
                ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($routeName)])
                ->first();
        }

        return null;
    }

    /**
     * Recorre las primeras 80 filas puntuando coincidencias con encabezados conocidos
     * de Saldos por Cliente; devuelve la primera con 5+ coincidencias o la de mayor puntaje.
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
                fn ($v) => $this->normalizeHeader((string) $v),
                $row,
            );

            $score = 0;
            foreach ($normalized as $v) {
                if (in_array($v, [
                    'cliente',
                    'nombre_del_cliente',
                    'oficina',
                    'zona',
                    'promotor',
                    'nombre_promotor',
                    'nombre_promotor2',
                    'nombre_gestor_de_cobranza',
                    'gestor_de_cobranza',
                    'saldo_actual',
                    'saldo_capital',
                    'capital',
                    'total_a_pagar',
                    'total_pagado',
                    'total_atrasado',
                    'estatus',
                    'substatus',
                    'contrato',
                    'no_contrato',
                    'num_contrato',
                    'dias_vencido',
                    'dias_vencidos',
                    'dias_mora',
                    'capital_atrasado',
                    'interes_atrasado',
                    'impuesto_atrasado',
                    'saldo_interes_moratorio',
                    'saldo_impuesto_interes_moratorio',
                    'cargos_calendario_atrasado',
                    'impuesto_cargos_calendario_atrasado',
                    'producto_de_credito',
                    'numero_de_pagos',
                    'pagos',
                    'periodicidad',
                ], true)) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = (int) $index;
            }

            if ($score >= 5) {
                return (int) $index;
            }
        }

        return $bestIndex;
    }

    /**
     * Mapea cada campo autorizado (ColumnMapService, 'lendus_saldos_cliente') a la
     * primera columna cuyo encabezado normalizado coincide exactamente con un alias,
     * sin reutilizar columnas.
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

        // ColumnMapService es la única fuente de verdad de las columnas autorizadas.
        $defs = $this->columnMap->getFieldDefinitions('lendus_saldos_cliente');
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
    private function value(array $row, array $map, string $field): mixed
    {
        if (! array_key_exists($field, $map)) {
            return null;
        }

        return $row[$map[$field]] ?? null;
    }

    /**
     * Indica si el texto parece un código de promotor (2-4 letras seguidas de 4+ dígitos).
     *
     * @param  string  $value  Texto a evaluar.
     * @return bool true si tiene forma de código.
     */
    private function looksLikeCode(string $value): bool
    {
        return (bool) preg_match('/^[A-Z]{2,4}\d{4,}/i', trim($value));
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
     * Normaliza nombres (minúsculas, sin acentos, espacios simples).
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
