<?php

namespace App\Services\Efectividad;

use App\Enums\ReportUploadStatus;
use App\Models\Period;
use App\Models\Portfolio;
use App\Models\ProcessRun;
use App\Models\Recovery;
use App\Models\ReportUpload;
use App\Services\ColumnMapService;
use App\Services\Imports\LendusIngresosCobranzaImportService;
use App\Services\Imports\LendusSaldosClienteImportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Etapas 1 y 2 del Job de Efectividad de Cobranza: ¿qué archivos de origen se
 * usan y traen los campos que la calculadora aprobada necesita?
 *
 * Fuentes de verdad que REUTILIZA (no inventa reglas nuevas):
 *  - `report_uploads` / `process_runs`: archivo, periodo, fecha, estado y log
 *    de la importación previa (errores ya registrados por los importadores).
 *  - `ColumnMapService`: el mismo mapeo de encabezados autorizado que usan
 *    LendusIngresosCobranzaImportService / LendusSaldosClienteImportService.
 *  - `fact_portfolios` / `fact_recoveries`: conteos agregados (una consulta
 *    por fuente, nunca por fila) para saber si un campo llegó vacío.
 *
 * Esta clase NO decide si el reporte se bloquea: eso lo sigue decidiendo la
 * cobertura del adaptador aprobado (EfectividadCobranzaAprobadaDataAdapter).
 * Aquí solo se diagnostica y se explica.
 */
class EfectividadCobranzaSourceInspector
{
    public const SOURCE_INGRESOS = 'lendus_ingresos_cobranza';

    public const SOURCE_SALDOS = 'lendus_saldos_cliente';

    /**
     * Campos de Ingresos Cobranza que lee el adaptador aprobado.
     * field (ColumnMapService) => [columna en fact_recoveries, etiqueta, crítico, motivo, revisar vacíos en BD]
     */
    private const INGRESOS_FIELDS = [
        'contract' => ['contract', 'Contrato', true, 'Relaciona cada pago con su contrato en la cartera.', true],
        'branch_name_raw' => ['office_name_raw', 'Oficina', false, 'Agrupa la efectividad por oficina.', true],
        'promoter_name' => ['promoter_name', 'Promotor', false, 'Agrupa la efectividad por promotor.', true],
        'operation' => ['operation', 'Operación', true, 'Identifica los pagos válidos (PAGO A CONTRATO / REFINANCIAMIENTO).', true],
        'transaction' => ['transaction', 'Transacción', true, 'Identifica los pagos válidos (PAGO / DESCUENTO).', true],
        'payment_date' => ['payment_date', 'Fecha transacción', true, 'Este campo es necesario para ubicar cada pago dentro del periodo evaluado.', true],
        'installment_due_date' => ['installment_due_date', 'Fecha cuota', true, 'Este campo es necesario para distinguir pagos sanos, atrasados y vencidos.', true],
        'contract_due_date' => ['contract_due_date', 'Fecha vencimiento', false, 'Identifica contratos vencidos.', false],
        'installment_number' => ['installment_number', 'Número de cuota', false, 'Identifica la cuota pagada.', false],
        'capital' => ['capital', 'Capital', true, 'Importe cobrado de capital.', false],
        'interest' => ['interest', 'Interés', false, 'Importe cobrado de interés.', false],
        'tax' => ['tax', 'Impuesto', false, 'Importe cobrado de impuesto.', false],
        'charges' => ['charges', 'Cargos calendario', false, 'Importe cobrado de cargos calendario.', false],
    ];

    /** Campos de Saldos por Cliente (CARTERA) que lee el adaptador aprobado. */
    private const SALDOS_FIELDS = [
        'contract' => ['contract', 'Contrato', true, 'Identifica cada crédito de la cartera.', true],
        'route_name' => ['route_name', 'Oficina', false, 'Agrupa la cartera por oficina.', true],
        'promoter_name' => ['promoter_name', 'Promotor', false, 'Agrupa la cartera por promotor.', true],
        'status' => ['status', 'Estatus', true, 'Excluye créditos cerrados o inactivos.', true],
        'substatus' => ['substatus', 'Substatus', false, 'Excluye créditos inactivos.', true],
        'contract_due_date' => ['contract_due_date', 'Fecha vencimiento', true, 'Distingue cartera vigente de cartera vencida.', true],
        'installment_amount' => ['installment_amount', 'Cuota', true, 'Calcula el monto por cobrar del periodo.', false],
        'num_payments' => ['num_payments', 'Pagos (número de cuotas)', true, 'Calcula el calendario de cuotas del periodo.', false],
        'periodicity' => ['periodicity', 'Periodicidad', true, 'Calcula el calendario de cuotas del periodo.', true],
        'first_payment_date' => ['first_payment_date', 'Fecha primer pago', true, 'Calcula el calendario de cuotas del periodo.', true],
        'next_payment_date' => ['next_payment_date', 'Fecha siguiente pago', false, 'Complementa el calendario de cuotas.', false],
        'total_overdue_raw' => ['total_overdue_raw', 'Total atrasado', true, 'Determina el por cobrar en atraso.', false],
        'overdue_installments' => ['overdue_installments', 'Pagos atrasados', false, 'Determina el por cobrar en atraso.', false],
        'total_pending_raw' => ['total_pending_raw', 'Total pendiente', false, 'Determina el por cobrar vencido.', false],
        'balance' => ['saldo_capital_vencido', 'Saldo capital', false, 'Campo financiero de cartera vencida.', false],
        'saldo_interes_vencido' => ['saldo_interes_vencido', 'Saldo interés', false, 'Campo financiero de cartera vencida.', false],
        'saldo_impuesto_vencido' => ['saldo_impuesto_vencido', 'Saldo impuesto', false, 'Campo financiero de cartera vencida.', false],
    ];

    public function __construct(
        private readonly ColumnMapService $columnMap,
        private readonly EfectividadCobranzaAprobadaDataAdapter $adapter,
    ) {}

    // ──────────────────────────────────────────────────────────────────────
    // Etapa 1 — archivos de origen
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @return array{sources: array<string,mixed>, issues: array<int,array<string,mixed>>, saldos_upload_id: ?int, ingresos_upload_id: ?int}
     */
    public function inspectSources(Period $period, ?int $branchId): array
    {
        $issues = [];
        $carteraPeriod = $this->adapter->resolveCarteraPeriod($period);

        // ── Saldos por Cliente (snapshot del corte) ─────────────────────────
        $saldosUploads = $carteraPeriod
            ? $this->uploadsQuery(self::SOURCE_SALDOS)->where('period_id', $carteraPeriod->id)->get()
            : collect();

        $saldosRowsByUpload = $carteraPeriod
            ? $this->countByUpload(Portfolio::query()->where('period_id', $carteraPeriod->id), $branchId)
            : [];
        $saldosRows = array_sum($saldosRowsByUpload);

        $saldosSummaries = $saldosUploads->map(fn (ReportUpload $u) => $this->uploadSummary($u, $saldosRowsByUpload[$u->id] ?? 0))->values()->all();
        $saldosPrimary = $saldosUploads->first(fn (ReportUpload $u) => $u->status === ReportUploadStatus::Processed) ?? $saldosUploads->first();

        $carteraLabel = $carteraPeriod?->label ?? $period->label;

        if (! $carteraPeriod) {
            $issues[] = EfectividadCobranzaIssue::make(
                'EC-SRC-SALDOS-SIN-CORTE',
                EfectividadCobranzaIssue::SEVERITY_CRITICAL,
                EfectividadCobranzaIssue::CATEGORY_MISSING_DATA,
                'Este periodo no tiene un corte mensual del cual tomar Saldos por Cliente (los periodos semanales no reciben ese archivo).',
                'Genera el reporte sobre el mes que contiene este periodo.',
                ['source' => 'saldos', 'period' => $period->label],
            );
        } elseif ($saldosUploads->isEmpty() && $saldosRows === 0) {
            $issues[] = EfectividadCobranzaIssue::make(
                'EC-SRC-SALDOS-FALTANTE',
                EfectividadCobranzaIssue::SEVERITY_CRITICAL,
                EfectividadCobranzaIssue::CATEGORY_MISSING_DATA,
                "No existe un archivo Saldos por Cliente procesado y válido para el corte requerido ({$carteraLabel}).",
                'Carga el archivo Saldos por Cliente de ese corte en "Carga de archivos" y espera a que termine de procesarse.',
                ['source' => 'saldos', 'period' => $carteraLabel],
            );
        } elseif ($saldosUploads->isEmpty()) {
            $issues[] = EfectividadCobranzaIssue::make(
                'EC-SRC-SALDOS-SIN-REGISTRO',
                EfectividadCobranzaIssue::SEVERITY_INFO,
                EfectividadCobranzaIssue::CATEGORY_MISSING_DATA,
                "Hay {$saldosRows} registros de cartera para {$carteraLabel}, pero no se encontró el registro del archivo que los originó.",
                'No requiere acción para generar el reporte; si necesitas trazabilidad, vuelve a cargar el archivo de origen.',
                ['source' => 'saldos', 'period' => $carteraLabel, 'record_count' => $saldosRows],
            );
        }

        foreach ($saldosUploads as $upload) {
            $issues = [...$issues, ...$this->uploadIssues($upload, 'saldos', 'Saldos por Cliente', $carteraLabel, $saldosRows)];
        }

        // ── Ingresos Cobranza (histórico completo, archivos del periodo) ────
        $scopeIds = $this->periodScopeIds($period);
        $ingresosUploads = $this->uploadsQuery(self::SOURCE_INGRESOS)->whereIn('period_id', $scopeIds)->get();
        $ingresosRowsByUpload = $this->countByUpload(Recovery::query()->whereIn('period_id', $scopeIds), $branchId);
        $ingresosPeriodRows = array_sum($ingresosRowsByUpload);
        $ingresosSummaries = $ingresosUploads->map(fn (ReportUpload $u) => $this->uploadSummary($u, $ingresosRowsByUpload[$u->id] ?? 0))->values()->all();
        $ingresosPrimary = $ingresosUploads->first(fn (ReportUpload $u) => $u->status === ReportUploadStatus::Processed) ?? $ingresosUploads->first();

        $historyQuery = Recovery::query();
        if ($branchId) {
            $historyQuery->where('branch_id', $branchId);
        }
        $history = $historyQuery->reorder()->selectRaw('COUNT(*) as c, MIN(payment_date) as min_date, MAX(payment_date) as max_date')->first();
        $historyUploads = $this->uploadsQuery(self::SOURCE_INGRESOS)->count();

        if ($ingresosUploads->isEmpty()) {
            $issues[] = EfectividadCobranzaIssue::make(
                'EC-SRC-INGRESOS-SIN-ARCHIVO-PERIODO',
                EfectividadCobranzaIssue::SEVERITY_WARNING,
                EfectividadCobranzaIssue::CATEGORY_PARTIAL_COVERAGE,
                "No se encontró un archivo de Ingresos Cobranza cargado para {$period->label}. Faltan archivos necesarios para cubrir el periodo seleccionado.",
                'Carga el archivo Ingresos Cobranza del periodo en "Carga de archivos". El cálculo usará solo el histórico ya importado.',
                ['source' => 'ingresos', 'period' => $period->label],
            );
        }

        foreach ($ingresosUploads as $upload) {
            $issues = [...$issues, ...$this->uploadIssues($upload, 'ingresos', 'Ingresos Cobranza', $period->label, $ingresosPeriodRows)];
        }

        $maxDate = $history?->max_date ? CarbonImmutable::parse($history->max_date)->startOfDay() : null;
        if ($maxDate && $period->end_date && $maxDate->lt(CarbonImmutable::parse($period->end_date->format('Y-m-d')))) {
            $issues[] = EfectividadCobranzaIssue::make(
                'EC-SRC-INGRESOS-COBERTURA-PARCIAL',
                EfectividadCobranzaIssue::SEVERITY_WARNING,
                EfectividadCobranzaIssue::CATEGORY_PARTIAL_COVERAGE,
                sprintf(
                    'Los movimientos de Ingresos Cobranza importados llegan hasta el %s, pero el periodo termina el %s. Los pagos posteriores no estarán reflejados.',
                    $maxDate->format('d/m/Y'),
                    $period->end_date->format('d/m/Y'),
                ),
                'Carga el archivo Ingresos Cobranza que cubra el periodo completo y vuelve a generar el reporte.',
                ['source' => 'ingresos', 'period' => $period->label],
            );
        }

        return [
            'sources' => [
                'saldos' => [
                    'label' => 'Saldos por Cliente',
                    'period_id' => $carteraPeriod?->id,
                    'period_label' => $carteraLabel,
                    'rows_in_db' => $saldosRows,
                    'uploads' => $saldosSummaries,
                ],
                'ingresos' => [
                    'label' => 'Ingresos Cobranza',
                    'period_label' => $period->label,
                    'rows_in_period' => $ingresosPeriodRows,
                    'uploads' => $ingresosSummaries,
                    'history_rows' => (int) ($history->c ?? 0),
                    'history_uploads' => $historyUploads,
                    'history_from' => $history?->min_date ? CarbonImmutable::parse($history->min_date)->toDateString() : null,
                    'history_to' => $maxDate?->toDateString(),
                ],
            ],
            'issues' => $issues,
            'saldos_upload_id' => $saldosPrimary?->id,
            'ingresos_upload_id' => $ingresosPrimary?->id,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Etapa 2 — columnas
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @return array{columns: array<string,mixed>, issues: array<int,array<string,mixed>>, incompatible_ingresos: bool, reprocess_ingresos_dates: bool}
     */
    public function inspectColumns(Period $period, ?int $branchId, array $sources): array
    {
        $issues = [];
        $columns = [];
        $incompatibleIngresos = false;
        $reprocessIngresosDates = false;

        $carteraPeriodId = $sources['saldos']['period_id'] ?? null;

        foreach ([
            'saldos' => [self::SOURCE_SALDOS, self::SALDOS_FIELDS, 'Saldos por Cliente', $sources['saldos']['uploads'] ?? [], $sources['saldos']['period_label'] ?? $period->label],
            'ingresos' => [self::SOURCE_INGRESOS, self::INGRESOS_FIELDS, 'Ingresos Cobranza', $sources['ingresos']['uploads'] ?? [], $period->label],
        ] as $key => [$sourceCode, $fields, $sourceLabel, $uploads, $periodLabel]) {
            // ── Encabezados del archivo físico (los archivos procesados más recientes) ──
            $headerFindings = [];
            $inspected = collect($uploads)->where('status', ReportUploadStatus::Processed->value)->take(3);

            foreach ($inspected as $upload) {
                $header = $this->readHeader((int) $upload['id']);
                if ($header === null) {
                    continue; // archivo físico perdido o ilegible — ya reportado/reportable en etapa 1
                }

                $resolved = $this->columnMap->resolveHeader($sourceCode, $header['headers']);
                $missing = [];

                foreach ($fields as $field => [$column, $label, $critical, $why]) {
                    if (array_key_exists($field, $resolved['map'])) {
                        $headerFindings[$field] ??= [
                            'original_header' => (string) $header['headers'][$resolved['map'][$field]],
                            'file' => $upload['file'],
                            'sheet' => $header['sheet'],
                        ];

                        continue;
                    }

                    $missing[] = $field;
                    $issues[] = EfectividadCobranzaIssue::make(
                        'EC-COL-FALTANTE',
                        $critical ? EfectividadCobranzaIssue::SEVERITY_WARNING : EfectividadCobranzaIssue::SEVERITY_INFO,
                        EfectividadCobranzaIssue::CATEGORY_MISSING_COLUMNS,
                        "El archivo {$sourceLabel} \"{$upload['file']}\" ({$periodLabel}) no contiene {$label}. {$why}",
                        "Exporta nuevamente el reporte {$sourceLabel} desde Lendus incluyendo la columna \"{$label}\" y vuelve a cargarlo.",
                        [
                            'source' => $key,
                            'file' => $upload['file'],
                            'upload_id' => $upload['id'],
                            'period' => $periodLabel,
                            'sheet' => $header['sheet'],
                            'expected_column' => $label,
                            'field' => $field,
                        ],
                    );
                }

                // Variante incompatible: Ingresos sin ninguna de las dos fechas requeridas
                // (p.ej. exportes que solo traen "Días vencidos pago").
                if ($key === 'ingresos' && in_array('payment_date', $missing, true) && in_array('installment_due_date', $missing, true)) {
                    $incompatibleIngresos = true;
                    $issues[] = EfectividadCobranzaIssue::make(
                        'EC-COL-VARIANTE-INCOMPATIBLE',
                        EfectividadCobranzaIssue::SEVERITY_WARNING,
                        EfectividadCobranzaIssue::CATEGORY_INCOMPATIBLE,
                        "El archivo \"{$upload['file']}\" corresponde a una variante de Ingresos Cobranza que no contiene todos los campos requeridos por la calculadora aprobada (Fecha transacción y Fecha cuota).",
                        'Exporta el reporte Ingresos Cobranza en su versión detallada (con Fecha transacción y Fecha cuota) y vuelve a cargarlo.',
                        ['source' => $key, 'file' => $upload['file'], 'upload_id' => $upload['id'], 'period' => $periodLabel, 'sheet' => $header['sheet']],
                    );
                }
            }

            // ── Datos importados: ¿el campo llegó vacío en TODOS o en ALGUNOS registros? ──
            $scopeQuery = $key === 'saldos'
                ? Portfolio::query()->where('period_id', $carteraPeriodId ?? -1)
                : Recovery::query()->whereIn('period_id', $this->periodScopeIds($period));
            if ($branchId) {
                $scopeQuery->where('branch_id', $branchId);
            }
            $nullCounts = $this->nullCounts(clone $scopeQuery, $fields);
            $total = $nullCounts['__total'];
            $outdatedRows = $this->outdatedImporterRows(clone $scopeQuery, $key);

            // Campos cuyo encabezado SÍ está en el archivo pero llegaron vacíos en TODOS
            // los registros: el archivo es correcto, la importación quedó desactualizada.
            $presentButEmpty = [];

            $fieldReport = [];
            foreach ($fields as $field => [$column, $label, $critical, $why, $checkNulls]) {
                $nulls = $nullCounts[$field] ?? null;
                $headerMissing = collect($issues)->contains(fn ($i) => ($i['field'] ?? null) === $field && $i['source'] === $key && $i['code'] === 'EC-COL-FALTANTE');

                $fieldReport[] = [
                    'field' => $field,
                    'label' => $label,
                    'critical' => $critical,
                    'original_header' => $headerFindings[$field]['original_header'] ?? null,
                    'status' => isset($headerFindings[$field]) ? 'found' : ($headerMissing ? 'missing' : 'unknown'),
                    'empty_rows' => $checkNulls ? $nulls : null,
                    'total_rows' => $total,
                ];

                if (! $checkNulls || $total === 0 || $nulls === null || $nulls === 0 || $headerMissing) {
                    continue;
                }

                if ($nulls === $total && isset($headerFindings[$field])) {
                    $presentButEmpty[$field] = $label;
                } elseif ($nulls === $total) {
                    $issues[] = EfectividadCobranzaIssue::make(
                        'EC-DATA-CAMPO-VACIO',
                        $critical ? EfectividadCobranzaIssue::SEVERITY_WARNING : EfectividadCobranzaIssue::SEVERITY_INFO,
                        EfectividadCobranzaIssue::CATEGORY_MISSING_DATA,
                        "Ninguno de los {$total} registros de {$sourceLabel} importados para {$periodLabel} trae valor en {$label}. {$why}",
                        "Verifica que el archivo {$sourceLabel} incluya la columna \"{$label}\" con datos y vuelve a cargarlo.",
                        ['source' => $key, 'period' => $periodLabel, 'expected_column' => $label, 'record_count' => $nulls, 'field' => $field],
                    );
                } elseif ($critical) {
                    $issues[] = EfectividadCobranzaIssue::make(
                        'EC-DATA-CAMPO-INCOMPLETO',
                        EfectividadCobranzaIssue::SEVERITY_WARNING,
                        EfectividadCobranzaIssue::CATEGORY_MISSING_DATA,
                        sprintf(
                            'Se encontraron %d de %d registros de %s sin %s. Revisa el archivo de origen; esos registros no se usan para no calcular una efectividad engañosa.',
                            $nulls,
                            $total,
                            $sourceLabel,
                            $label,
                        ),
                        "Corrige los registros sin \"{$label}\" en el archivo de origen y vuelve a cargarlo.",
                        ['source' => $key, 'period' => $periodLabel, 'expected_column' => $label, 'record_count' => $nulls, 'field' => $field],
                    );
                }
            }

            if ($presentButEmpty !== []) {
                $file = collect($headerFindings)->only(array_keys($presentButEmpty))->pluck('file')->filter()->first();
                $dateField = collect(['installment_due_date', 'payment_date'])->first(fn ($f) => isset($presentButEmpty[$f]));
                if ($key === 'ingresos' && $dateField) {
                    $reprocessIngresosDates = true;
                }

                $issues[] = EfectividadCobranzaIssue::make(
                    'EC-DATA-REPROCESAR',
                    EfectividadCobranzaIssue::SEVERITY_WARNING,
                    EfectividadCobranzaIssue::CATEGORY_STALE,
                    sprintf(
                        'El archivo %s "%s" sí contiene %s, pero ninguno de los %s registros importados para %s tiene esos datos%s.',
                        $sourceLabel,
                        $file,
                        implode(', ', $presentButEmpty),
                        number_format($total),
                        $periodLabel,
                        $outdatedRows > 0
                            ? sprintf(': %s registros se procesaron con una versión anterior del importador que no leía esas columnas', number_format($outdatedRows))
                            : '',
                    ),
                    "Vuelve a procesar el archivo {$sourceLabel} en \"Carga de archivos\" (no es necesario volver a subirlo) y genera el reporte de nuevo.",
                    [
                        'source' => $key,
                        'file' => $file,
                        'period' => $periodLabel,
                        'expected_column' => implode(', ', $presentButEmpty),
                        'record_count' => $total,
                        'field' => $dateField,
                    ],
                );
            }

            $columns[$key] = ['label' => $sourceLabel, 'total_rows' => $total, 'outdated_rows' => $outdatedRows, 'fields' => $fieldReport];
        }

        return [
            'columns' => $columns,
            'issues' => $issues,
            'incompatible_ingresos' => $incompatibleIngresos,
            'reprocess_ingresos_dates' => $reprocessIngresosDates,
        ];
    }

    /**
     * Registros importados con una versión del importador anterior a la que lee
     * los campos de la Efectividad Aprobada (columna `importer_version`).
     */
    private function outdatedImporterRows($query, string $key): int
    {
        $table = $key === 'saldos' ? 'fact_portfolios' : 'fact_recoveries';
        if (! Schema::hasColumn($table, 'importer_version')) {
            return 0;
        }

        $current = $key === 'saldos'
            ? LendusSaldosClienteImportService::IMPORTER_VERSION
            : LendusIngresosCobranzaImportService::IMPORTER_VERSION;

        return (int) $query->reorder()
            ->where(fn ($q) => $q->whereNull('importer_version')->orWhere('importer_version', '!=', $current))
            ->count();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Tras el adaptador — traducir la cobertura aprobada a incidencias
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $coverage  cobertura del adaptador aprobado
     * @return array{issues: array<int,array<string,mixed>>, failure_kind: ?string}
     */
    public function coverageIssues(array $coverage, Period $period, bool $incompatibleIngresos, bool $reprocessIngresosDates = false): array
    {
        $issues = [];
        $failureKind = null;
        $status = $coverage['status'] ?? 'sin_datos';

        switch ($status) {
            case 'sin_datos':
                $failureKind = 'missing_data';
                $issues[] = EfectividadCobranzaIssue::make(
                    'EC-COB-SIN-DATOS',
                    EfectividadCobranzaIssue::SEVERITY_CRITICAL,
                    EfectividadCobranzaIssue::CATEGORY_PARTIAL_COVERAGE,
                    "Faltan archivos necesarios para cubrir el periodo seleccionado: no hay registros de Saldos por Cliente ni de Ingresos Cobranza importados para {$period->label}.",
                    'Carga y procesa los archivos Saldos por Cliente e Ingresos Cobranza del periodo en "Carga de archivos" y vuelve a generar el reporte.',
                    ['period' => $period->label],
                );
                break;

            case 'sin_cartera':
                $failureKind = 'missing_data';
                $issues[] = EfectividadCobranzaIssue::make(
                    'EC-COB-SIN-CARTERA',
                    EfectividadCobranzaIssue::SEVERITY_CRITICAL,
                    EfectividadCobranzaIssue::CATEGORY_MISSING_DATA,
                    'No existe un archivo Saldos por Cliente procesado y válido para el corte requerido. Sin cartera no se puede calcular el monto por cobrar (Sano, Atraso y Vencido).',
                    'Carga el archivo Saldos por Cliente del corte en "Carga de archivos", espera a que se procese y vuelve a generar el reporte.',
                    ['source' => 'saldos', 'period' => $period->label, 'record_count' => 0],
                );
                break;

            case 'bloqueado_sin_fechas':
                // El archivo de origen SÍ trae las fechas, pero la importación quedó
                // desactualizada → "datos obsoletos" (se corrige reprocesando, no
                // volviendo a exportar desde Lendus).
                if ($reprocessIngresosDates) {
                    $failureKind = 'stale';
                    $issues[] = EfectividadCobranzaIssue::make(
                        'EC-COB-INGRESOS-SIN-FECHAS',
                        EfectividadCobranzaIssue::SEVERITY_CRITICAL,
                        EfectividadCobranzaIssue::CATEGORY_STALE,
                        sprintf(
                            'Los movimientos de Ingresos Cobranza importados no tienen Fecha cuota y Fecha transacción a la vez (%s pagos válidos; %s sin Fecha cuota), aunque el archivo de origen sí incluye esas columnas. Sin ambas fechas no se pueden distinguir pagos sanos, atrasados y vencidos; no se calculará una efectividad engañosa.',
                            number_format($coverage['ingresos_rows_whitelist_pass'] ?? 0),
                            number_format($coverage['ingresos_rows_sin_fecha_cuota'] ?? 0),
                        ),
                        'Vuelve a procesar los archivos de Ingresos Cobranza en "Carga de archivos" (no es necesario volver a subirlos) y genera el reporte de nuevo.',
                        ['source' => 'ingresos', 'period' => $period->label, 'expected_column' => 'Fecha cuota / Fecha transacción', 'record_count' => $coverage['ingresos_rows_whitelist_pass'] ?? null],
                    );
                    break;
                }

                $failureKind = $incompatibleIngresos ? 'incompatible' : 'missing_columns';
                $issues[] = EfectividadCobranzaIssue::make(
                    'EC-COB-INGRESOS-SIN-FECHAS',
                    EfectividadCobranzaIssue::SEVERITY_CRITICAL,
                    $incompatibleIngresos ? EfectividadCobranzaIssue::CATEGORY_INCOMPATIBLE : EfectividadCobranzaIssue::CATEGORY_MISSING_COLUMNS,
                    sprintf(
                        'Los movimientos de Ingresos Cobranza no contienen Fecha cuota y Fecha transacción a la vez (%d pagos válidos; %d sin Fecha transacción, %d sin Fecha cuota). Estos campos son necesarios para distinguir pagos sanos, atrasados y vencidos; no se calculará una efectividad engañosa.',
                        $coverage['ingresos_rows_whitelist_pass'] ?? 0,
                        $coverage['ingresos_rows_sin_fecha_transaccion'] ?? 0,
                        $coverage['ingresos_rows_sin_fecha_cuota'] ?? 0,
                    ),
                    'Exporta Ingresos Cobranza desde Lendus con las columnas "Fecha transacción" y "Fecha cuota", vuelve a cargarlo y genera el reporte de nuevo.',
                    ['source' => 'ingresos', 'period' => $period->label, 'expected_column' => 'Fecha cuota / Fecha transacción', 'record_count' => $coverage['ingresos_rows_whitelist_pass'] ?? null],
                );
                break;

            case 'sin_ingresos_usables':
                $issues[] = EfectividadCobranzaIssue::make(
                    'EC-COB-SIN-PAGOS-USABLES',
                    EfectividadCobranzaIssue::SEVERITY_WARNING,
                    EfectividadCobranzaIssue::CATEGORY_PARTIAL_COVERAGE,
                    'Hay cartera, pero ningún movimiento de Ingresos Cobranza es utilizable para este periodo/sucursal. La cobranza calculada será 0 en todos los grupos.',
                    'Verifica que el archivo Ingresos Cobranza del periodo esté cargado y procesado.',
                    ['source' => 'ingresos', 'period' => $period->label, 'record_count' => $coverage['ingresos_rows_total'] ?? null],
                );
                break;
        }

        if (($coverage['cartera_rows_sin_contrato'] ?? 0) > 0) {
            $issues[] = EfectividadCobranzaIssue::make(
                'EC-COB-CARTERA-SIN-CONTRATO',
                EfectividadCobranzaIssue::SEVERITY_WARNING,
                EfectividadCobranzaIssue::CATEGORY_MISSING_DATA,
                "Se encontraron {$coverage['cartera_rows_sin_contrato']} registros de cartera sin contrato; no se consideran en el cálculo.",
                'Corrige los registros sin contrato en el archivo Saldos por Cliente y vuelve a cargarlo.',
                ['source' => 'saldos', 'period' => $period->label, 'record_count' => $coverage['cartera_rows_sin_contrato'], 'expected_column' => 'Contrato'],
            );
        }

        return ['issues' => $issues, 'failure_kind' => $failureKind];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function uploadsQuery(string $sourceCode)
    {
        return ReportUpload::query()
            ->whereHas('dataSource', fn ($q) => $q->where('code', $sourceCode))
            ->where('status', '!=', ReportUploadStatus::Replaced->value)
            ->latest('id');
    }

    /** @return array<int,int> report_upload_id => filas */
    private function countByUpload($query, ?int $branchId): array
    {
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query->reorder()
            ->selectRaw('report_upload_id, COUNT(*) as c')
            ->groupBy('report_upload_id')
            ->pluck('c', 'report_upload_id')
            ->mapWithKeys(fn ($c, $id) => [(int) $id => (int) $c])
            ->all();
    }

    /** IDs de periodo cuyos registros "pertenecen" al periodo evaluado (él mismo + componentes). */
    private function periodScopeIds(Period $period): array
    {
        return collect([$period->id])
            ->merge($period->component_period_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function uploadSummary(ReportUpload $upload, int $rows): array
    {
        $lastRun = ProcessRun::query()->where('report_upload_id', $upload->id)->latest('id')->first();

        return [
            'id' => $upload->id,
            'file' => $upload->original_name,
            'period_id' => $upload->period_id,
            'uploaded_at' => $upload->uploaded_at?->toISOString() ?? $upload->created_at?->toISOString(),
            'status' => $upload->status?->value,
            'file_exists' => $upload->stored_path ? Storage::disk('public')->exists($upload->stored_path) : false,
            'rows' => $rows,
            'last_process' => $lastRun ? [
                'status' => $lastRun->status?->value,
                'rows_read' => $lastRun->rows_read,
                'rows_inserted' => $lastRun->rows_inserted,
                'finished_at' => $lastRun->finished_at?->toISOString(),
                'log' => $lastRun->log ? Str::limit((string) $lastRun->log, 400) : null,
            ] : null,
        ];
    }

    /** Incidencias que provienen de la importación previa del archivo (reutiliza su estado/log). */
    private function uploadIssues(ReportUpload $upload, string $source, string $sourceLabel, string $periodLabel, int $rowsAvailable): array
    {
        $issues = [];
        $ctx = ['source' => $source, 'file' => $upload->original_name, 'upload_id' => $upload->id, 'period' => $periodLabel];

        if ($upload->status === ReportUploadStatus::Failed) {
            $log = ProcessRun::query()->where('report_upload_id', $upload->id)->latest('id')->value('log');
            $issues[] = EfectividadCobranzaIssue::make(
                'EC-SRC-IMPORTACION-FALLIDA',
                $rowsAvailable > 0 ? EfectividadCobranzaIssue::SEVERITY_WARNING : EfectividadCobranzaIssue::SEVERITY_CRITICAL,
                EfectividadCobranzaIssue::CATEGORY_MISSING_DATA,
                "La importación del archivo {$sourceLabel} \"{$upload->original_name}\" falló"
                    .($log ? ': '.Str::limit((string) $log, 300) : '.'),
                'Revisa el archivo en "Carga de archivos", corrígelo y vuelve a procesarlo.',
                $ctx,
            );
        } elseif (in_array($upload->status, [ReportUploadStatus::Pending, ReportUploadStatus::Processing], true)) {
            $issues[] = EfectividadCobranzaIssue::make(
                'EC-SRC-IMPORTACION-PENDIENTE',
                EfectividadCobranzaIssue::SEVERITY_WARNING,
                EfectividadCobranzaIssue::CATEGORY_PARTIAL_COVERAGE,
                "El archivo {$sourceLabel} \"{$upload->original_name}\" todavía no termina de procesarse; sus registros pueden no estar completos.",
                'Espera a que termine el procesamiento del archivo y vuelve a generar el reporte.',
                $ctx,
            );
        }

        if ($upload->stored_path && ! Storage::disk('public')->exists($upload->stored_path)) {
            $issues[] = EfectividadCobranzaIssue::make(
                'EC-SRC-ARCHIVO-FISICO-PERDIDO',
                EfectividadCobranzaIssue::SEVERITY_INFO,
                EfectividadCobranzaIssue::CATEGORY_FILE_MISSING,
                "El archivo físico \"{$upload->original_name}\" ya no está en el almacenamiento. Se usan los registros ya importados, pero no fue posible revisar sus encabezados originales.",
                'Si necesitas revisar las columnas originales, vuelve a cargar el archivo.',
                $ctx,
            );
        }

        return $issues;
    }

    /**
     * Una sola consulta agregada: total y vacíos por campo.
     *
     * @return array<string,int>
     */
    private function nullCounts($query, array $fields): array
    {
        $grammar = $query->getQuery()->getGrammar();
        $selects = ['COUNT(*) as __total'];
        foreach ($fields as $field => [$column, , , , $checkNulls]) {
            if ($checkNulls) {
                $wrapped = $grammar->wrap($column); // p.ej. `transaction` es palabra reservada
                $selects[] = "SUM(CASE WHEN {$wrapped} IS NULL OR TRIM({$wrapped}) = '' THEN 1 ELSE 0 END) as ".$this->alias($field);
            }
        }

        $row = $query->reorder()->selectRaw(implode(', ', $selects))->first();
        $result = ['__total' => (int) ($row->__total ?? 0)];

        foreach ($fields as $field => [, , , , $checkNulls]) {
            if ($checkNulls) {
                $result[$field] = (int) ($row->{$this->alias($field)} ?? 0);
            }
        }

        return $result;
    }

    private function alias(string $field): string
    {
        return 'n_'.preg_replace('/[^a-z0-9_]/', '', $field);
    }

    /**
     * Lee SOLO las primeras filas del archivo físico (filtro de lectura) y
     * detecta la fila de encabezados con el mismo mapeo de ColumnMapService.
     * Cacheado por upload + fecha/tamaño del archivo (no se relee en cada
     * generación).
     *
     * @return array{sheet:string, header_row:int, headers:array<int,string>}|null
     */
    private function readHeader(int $uploadId): ?array
    {
        $upload = ReportUpload::query()->with('dataSource')->find($uploadId);
        if (! $upload?->stored_path || ! Storage::disk('public')->exists($upload->stored_path)) {
            return null;
        }

        $path = Storage::disk('public')->path($upload->stored_path);
        $sourceCode = $upload->dataSource?->code ?? '';
        $cacheKey = sprintf('efectividad:headers:%d:%s:%s', $upload->id, @filemtime($path) ?: 0, @filesize($path) ?: 0);

        try {
            return Cache::remember($cacheKey, now()->addDays(30), function () use ($path, $sourceCode) {
                [$title, $rows] = $this->firstRows($path, (int) config('efectividad.header_scan_rows', 30));

                $aliases = collect($this->columnMap->getFieldDefinitions($sourceCode))->pluck('aliases')->flatten()->flip();
                $bestIndex = null;
                $bestScore = 0;
                foreach ($rows as $index => $row) {
                    $score = collect($row)->filter(fn ($v) => $aliases->has($this->columnMap->normalizeHeader((string) $v)))->count();
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestIndex = $index;
                    }
                }

                if ($bestIndex === null) {
                    return null;
                }

                return [
                    'sheet' => $title,
                    'header_row' => $bestIndex + 1,
                    'headers' => array_map(fn ($v) => trim((string) $v), $rows[$bestIndex]),
                ];
            });
        } catch (\Throwable $e) {
            Log::warning('Efectividad: no se pudieron leer los encabezados del archivo de origen.', [
                'upload_id' => $upload->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Primeras `$maxRows` filas de la primera hoja.
     *
     * `.xlsx`: OpenSpout en streaming (mismo lector que el importador de
     * Ingresos) — se detiene al llegar a `$maxRows`, sin recorrer el archivo
     * completo (medido: ~1 min con PhpSpreadsheet sobre archivos reales de
     * ~40 mil filas, porque su filtro de lectura igual analiza toda la hoja).
     * Otros formatos (xls/csv): PhpSpreadsheet con filtro de filas.
     *
     * @return array{0:string, 1:array<int,array<int,mixed>>}
     */
    private function firstRows(string $path, int $maxRows): array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xlsx') {
            $reader = new XlsxReader(new XlsxOptions(SHOULD_FORMAT_DATES: false, SHOULD_PRESERVE_EMPTY_ROWS: true));
            $reader->open($path);

            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    $rows = [];
                    foreach ($sheet->getRowIterator() as $row) {
                        $rows[] = array_map(
                            fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v,
                            $row->toArray(),
                        );
                        if (count($rows) >= $maxRows) {
                            break;
                        }
                    }

                    return [$sheet->getName(), $rows];
                }

                return ['', []];
            } finally {
                $reader->close();
            }
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class($maxRows) implements IReadFilter
        {
            public function __construct(private readonly int $maxRow) {}

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row <= $this->maxRow;
            }
        });

        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().min($maxRows, $sheet->getHighestRow()), null, false, false, false);
        $title = $sheet->getTitle();
        $spreadsheet->disconnectWorksheets();

        return [$title, $rows];
    }
}
