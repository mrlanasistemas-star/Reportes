<?php

namespace App\Services\Api;

use App\Models\Branch;
use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\EstadoResultados\EstadoResultadosUpload;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\EstadoResultados\Classification\ClassificationService;
use App\Services\EstadoResultados\Import\MovementPeriodAllocator;
use App\Services\EstadoResultados\Support\Treatment;
use App\Services\Radiography\BranchRadiographyCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * API v1 — MES NATURAL (23-nov-2026). Solo lectura: nunca escribe en BD.
 *
 * MR LANA mide su operación en periodos de 4 semanas (lunes a domingo) y TODOS los
 * registros quedan ligados a ese periodo (period_id). Otras áreas necesitan cifras del
 * 1 al último día del mes. Ambas vistas conviven sin tocar nada: aquí NO se crea ni se
 * reasigna ningún periodo; cada movimiento se toma por SU FECHA y se filtra al mes.
 *
 *   - Colocación ........ fact_placements.operation_date  (SUM(amount) / COUNT(*))
 *   - Recuperación ...... fact_recoveries.payment_date    (regla ÚNICA del reporte:
 *                         BranchRadiographyCalculator::recoveryExclusionSql()['recovery'])
 *   - Gastos ERP ........ fact_expenses.expense_date      (monto registrado, sin reglas OPEX)
 *   - Movimientos banco . estado_resultados_movements.movement_date (cargos/abonos por
 *                         tratamiento vigente; copias «duplicado» fuera y una sola vez
 *                         por huella)
 *
 * Un mes natural suele tocar DOS periodos (p. ej. abril 2026 = P04 30-mar→26-abr y P05
 * 27-abr→24-may). Se leen las filas de todos los periodos mensuales cercanos y solo
 * cuentan las que tienen fecha dentro del mes, así que nada se duplica ni se pierde.
 * `coverage` dice qué días del mes están cubiertos por periodos con datos; si falta un
 * tramo (el periodo aún no existe o no se ha cargado) el mes sale `partial`.
 *
 * Colaboradores: se usa el MISMO motor de la actividad semanal (lista de colaboradores
 * y mapa de fusión de la radiografía de cada periodo) con un corte = el mes natural, y se
 * suman los periodos. Periodos sin radiografía vigente no aportan colaboradores (se
 * listan en `employees_missing_periods`), aunque sus filas sí cuentan en los totales.
 */
class NaturalMonthActivityService
{
    public const STATUS_COMPLETE = 'complete';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_NO_DATA = 'no_data';

    private const CACHE_TTL = 900;

    private const CACHE_VERSION = 'v1';

    /** Margen para alcanzar filas de un periodo con fecha ligeramente fuera de su ventana. */
    private const NEIGHBOR_DAYS = 45;

    /**
     * @param  PeriodWeeklyActivityService  $slotActivity  Motor de reparto por cortes de fecha (colaboradores).
     * @param  ClassificationService  $classifications  Tratamientos manuales vigentes de los movimientos bancarios del ER.
     */
    public function __construct(
        private readonly PeriodWeeklyActivityService $slotActivity,
        private readonly ClassificationService $classifications,
    ) {}

    /**
     * Primer y último día (a las 00:00) de un mes natural.
     *
     * @param  int  $year  Año.
     * @param  int  $month  Mes (1-12).
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function bounds(int $year, int $month): array
    {
        $from = CarbonImmutable::create($year, $month, 1)->startOfDay();

        return [$from, $from->endOfMonth()->startOfDay()];
    }

    /**
     * Meses naturales con al menos un día cubierto por un periodo mensual.
     *
     * Recorre mes por mes desde el inicio del primer periodo mensual hasta el
     * fin del último y calcula la cobertura de cada uno (días con un periodo
     * que tiene datos). Devuelve los meses del más reciente al más antiguo,
     * marcados complete o partial. Solo lectura, sin caché.
     *
     * @return list<array{month:string, date_from:string, date_to:string, days:int, covered_days:int, status:string}>
     */
    public function availableMonths(): array
    {
        $periods = Period::query()->where('type', 'monthly')->whereNotNull('start_date')->whereNotNull('end_date')->orderBy('start_date')->get();
        if ($periods->isEmpty()) {
            return [];
        }

        $cursor = CarbonImmutable::parse($periods->first()->start_date)->startOfMonth();
        $last = CarbonImmutable::parse($periods->max('end_date'))->startOfMonth();
        $withData = $this->periodIdsWithData($periods->pluck('id')->all());

        $out = [];
        while ($cursor->lte($last)) {
            [$from, $to] = self::bounds($cursor->year, $cursor->month);
            $coverage = $this->coverage($periods, $from, $to, $withData);
            if ($coverage['covered_days'] > 0) {
                $out[] = [
                    'month' => $cursor->format('Y-m'),
                    'date_from' => $from->toDateString(),
                    'date_to' => $to->toDateString(),
                    'days' => $coverage['days'],
                    'covered_days' => $coverage['covered_days'],
                    'status' => $coverage['covered_days'] === $coverage['days'] ? self::STATUS_COMPLETE : self::STATUS_PARTIAL,
                ];
            }
            $cursor = $cursor->addMonth();
        }

        return array_reverse($out);
    }

    /**
     * Resultado SIN filtrar por consumidor (el controlador aplica alcance e include).
     *
     * Toma como candidatos los periodos mensuales que se traslapan con el mes
     * ampliado ±45 días (NEIGHBOR_DAYS) y cachea 15 min el resultado de
     * compute() con una firma basada en id/updated_at de los periodos, sus
     * radiografías y el último updated_at de los movimientos bancarios del mes.
     *
     * @param  int  $year  Año del mes natural.
     * @param  int  $month  Mes natural (1-12).
     * @return array<string, mixed> status (complete|partial|no_data), cobertura, totales, diario, sucursales, colaboradores, movimientos bancarios, auditoría y conciliación.
     */
    public function forMonth(int $year, int $month): array
    {
        [$from, $to] = self::bounds($year, $month);

        $candidates = Period::query()
            ->where('type', 'monthly')
            ->whereNotNull('start_date')->whereNotNull('end_date')
            ->where('start_date', '<=', $to->addDays(self::NEIGHBOR_DAYS)->toDateString())
            ->where('end_date', '>=', $from->subDays(self::NEIGHBOR_DAYS)->toDateString())
            ->orderBy('start_date')
            ->get();

        $summaries = PeriodSummary::query()->whereIn('period_id', $candidates->pluck('id'))->get(['id', 'period_id', 'status', 'updated_at'])->keyBy('period_id');
        $signature = $candidates->map(fn (Period $p) => implode('.', [
            $p->id,
            optional($p->updated_at)->timestamp,
            $summaries->get($p->id)?->id,
            optional($summaries->get($p->id)?->updated_at)->timestamp,
        ]))->implode('|');
        $erSignature = Schema::hasTable('estado_resultados_movements')
            ? (string) EstadoResultadosMovement::query()->whereBetween('movement_date', [$from->toDateString(), $to->toDateString()])->max('updated_at')
            : '';

        $key = sprintf('api_v1_natural_month:%s:%04d-%02d:%s', self::CACHE_VERSION, $year, $month, md5($signature.'#'.$erSignature));

        return Cache::remember($key, self::CACHE_TTL, fn () => $this->compute($from, $to, $candidates, $summaries));
    }

    /**
     * Cálculo sin caché del mes natural.
     *
     * Lee de los periodos candidatos (y de sus semanas componentes) solo las
     * filas con fecha dentro del mes: colocación por fecha de operación,
     * recuperación por fecha de pago (regla de exclusión del reporte) y gastos
     * ERP por fecha del gasto (monto registrado, sin reglas OPEX). Acumula por
     * sucursal del movimiento, sin sucursal, por día y por periodo de origen;
     * registra como auditoría las filas cuya fecha cae fuera de la ventana de
     * su propio periodo (ya incluidas en los totales). Agrega colaboradores,
     * movimientos bancarios y la conciliación sucursales/diario/colaboradores.
     *
     * Solo lectura sobre BD.
     *
     * @param  CarbonImmutable  $from  Primer día del mes.
     * @param  CarbonImmutable  $to  Último día del mes.
     * @param  Collection  $candidates  Periodos mensuales candidatos (orden cronológico).
     * @param  Collection  $summaries  PeriodSummary indexadas por period_id.
     * @return array<string, mixed>
     */
    private function compute(CarbonImmutable $from, CarbonImmutable $to, $candidates, $summaries): array
    {
        $fromS = $from->toDateString();
        $toS = $to->toDateString();
        $periodIds = $candidates->pluck('id')->map(fn ($v) => (int) $v)->all();
        // Los fact_* van etiquetados con el MES (y, por compatibilidad, pueden traer ids de sus semanas).
        $dataIds = array_values(array_unique(array_merge(
            $periodIds,
            $candidates->flatMap(fn (Period $p) => array_map('intval', $p->component_period_ids ?? []))->all()
        )));
        $windowByDataId = [];
        foreach ($candidates as $p) {
            $win = [optional($p->start_date)->toDateString(), optional($p->end_date)->toDateString()];
            $windowByDataId[(int) $p->id] = $win;
            foreach ($p->component_period_ids ?? [] as $wid) {
                $windowByDataId[(int) $wid] = $win;
            }
        }

        $withData = $this->periodIdsWithData($periodIds);
        $coverage = $this->coverage($candidates, $from, $to, $withData);

        $branches = [];      // branch_id → acumulador
        $unassigned = $this->emptyAcc();
        $total = $this->emptyAcc();
        $daily = [];
        $outsideWindow = ['placement' => ['rows' => 0, 'amount' => 0.0], 'recovery' => ['rows' => 0, 'amount' => 0.0], 'erp_expenses' => ['rows' => 0, 'amount' => 0.0]];
        $byPeriod = [];

        $touch = function (?int $branchId) use (&$branches) {
            if ($branchId !== null) {
                $branches[$branchId] ??= $this->emptyAcc();
            }
        };

        // ── Colocación.
        if ($dataIds !== []) {
            DB::table('fact_placements')
                ->whereIn('period_id', $dataIds)
                ->whereBetween(DB::raw('DATE(operation_date)'), [$fromS, $toS])
                ->selectRaw('period_id, branch_id, DATE(operation_date) as d, COUNT(*) as operations, SUM(amount) as amount')
                ->groupBy('period_id', 'branch_id', DB::raw('DATE(operation_date)'))
                ->get()
                ->each(function ($r) use (&$branches, &$unassigned, &$total, &$daily, &$outsideWindow, &$byPeriod, $windowByDataId, $touch) {
                    $amount = (float) $r->amount;
                    $ops = (int) $r->operations;
                    $branchId = $r->branch_id === null ? null : (int) $r->branch_id;
                    $touch($branchId);
                    $total['placement'] += $amount;
                    $total['operations'] += $ops;
                    if ($branchId === null) {
                        $unassigned['placement'] += $amount;
                        $unassigned['operations'] += $ops;
                    } else {
                        $branches[$branchId]['placement'] += $amount;
                        $branches[$branchId]['operations'] += $ops;
                    }
                    $daily[$r->d] ??= $this->emptyAcc();
                    $daily[$r->d]['placement'] += $amount;
                    $daily[$r->d]['operations'] += $ops;
                    $byPeriod[(int) $r->period_id]['placement'] = ($byPeriod[(int) $r->period_id]['placement'] ?? 0) + $amount;
                    if ($this->outside($windowByDataId[(int) $r->period_id] ?? null, $r->d)) {
                        $outsideWindow['placement']['rows'] += $ops;
                        $outsideWindow['placement']['amount'] += $amount;
                    }
                });

            // ── Recuperación — regla ÚNICA del reporte.
            ['recovery' => $exclRecovery] = BranchRadiographyCalculator::recoveryExclusionSql();
            DB::table('fact_recoveries')
                ->whereIn('period_id', $dataIds)
                ->whereBetween(DB::raw('DATE(payment_date)'), [$fromS, $toS])
                ->selectRaw("period_id, branch_id, DATE(payment_date) as d, COUNT(*) as n, SUM(CASE {$exclRecovery} ELSE total_amount END) as recovery")
                ->groupBy('period_id', 'branch_id', DB::raw('DATE(payment_date)'))
                ->get()
                ->each(function ($r) use (&$branches, &$unassigned, &$total, &$daily, &$outsideWindow, &$byPeriod, $windowByDataId, $touch) {
                    $amount = (float) $r->recovery;
                    $branchId = $r->branch_id === null ? null : (int) $r->branch_id;
                    $touch($branchId);
                    $total['recovery'] += $amount;
                    if ($branchId === null) {
                        $unassigned['recovery'] += $amount;
                    } else {
                        $branches[$branchId]['recovery'] += $amount;
                    }
                    $daily[$r->d] ??= $this->emptyAcc();
                    $daily[$r->d]['recovery'] += $amount;
                    $byPeriod[(int) $r->period_id]['recovery'] = ($byPeriod[(int) $r->period_id]['recovery'] ?? 0) + $amount;
                    if ($this->outside($windowByDataId[(int) $r->period_id] ?? null, $r->d)) {
                        $outsideWindow['recovery']['rows'] += (int) $r->n;
                        $outsideWindow['recovery']['amount'] += $amount;
                    }
                });

            // ── Gastos ERP (monto registrado por fecha del gasto).
            DB::table('fact_expenses')
                ->whereIn('period_id', $dataIds)
                ->whereBetween(DB::raw('DATE(expense_date)'), [$fromS, $toS])
                ->selectRaw('period_id, branch_id, category, DATE(expense_date) as d, COUNT(*) as n, SUM(amount) as amount, SUM(COALESCE(paid_amount, 0)) as paid')
                ->groupBy('period_id', 'branch_id', 'category', DB::raw('DATE(expense_date)'))
                ->get()
                ->each(function ($r) use (&$branches, &$unassigned, &$total, &$daily, &$outsideWindow, &$byPeriod, $windowByDataId, $touch) {
                    $amount = (float) $r->amount;
                    $branchId = $r->branch_id === null ? null : (int) $r->branch_id;
                    $touch($branchId);
                    $category = trim((string) ($r->category ?? '')) ?: 'SIN CATEGORÍA';
                    $total['erp_expenses'] += $amount;
                    $total['erp_paid'] += (float) $r->paid;
                    $total['erp_rows'] += (int) $r->n;
                    $total['erp_by_category'][$category] = ($total['erp_by_category'][$category] ?? 0) + $amount;
                    if ($branchId === null) {
                        $unassigned['erp_expenses'] += $amount;
                        $unassigned['erp_paid'] += (float) $r->paid;
                        $unassigned['erp_rows'] += (int) $r->n;
                    } else {
                        $branches[$branchId]['erp_expenses'] += $amount;
                        $branches[$branchId]['erp_paid'] += (float) $r->paid;
                        $branches[$branchId]['erp_rows'] += (int) $r->n;
                        $branches[$branchId]['erp_by_category'][$category] = ($branches[$branchId]['erp_by_category'][$category] ?? 0) + $amount;
                    }
                    $daily[$r->d] ??= $this->emptyAcc();
                    $daily[$r->d]['erp_expenses'] += $amount;
                    $byPeriod[(int) $r->period_id]['erp_expenses'] = ($byPeriod[(int) $r->period_id]['erp_expenses'] ?? 0) + $amount;
                    if ($this->outside($windowByDataId[(int) $r->period_id] ?? null, $r->d)) {
                        $outsideWindow['erp_expenses']['rows'] += (int) $r->n;
                        $outsideWindow['erp_expenses']['amount'] += $amount;
                    }
                });
        }

        // ── Sucursales.
        $branchModels = $branches === [] ? collect() : Branch::query()->with('sucursal')->whereIn('id', array_keys($branches))->get()->keyBy('id');
        $branchRows = [];
        foreach ($branches as $branchId => $acc) {
            $branch = $branchModels->get($branchId);
            $attribution = $branch?->attributionSummary();
            $branchRows[] = [
                'branch_id' => $branchId,
                'branch' => $branch === null ? null : ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name],
                'sucursal' => $attribution['sucursal'] ?? null,
                'region' => $attribution['region'] ?? null,
            ] + $this->finalize($acc);
        }
        usort($branchRows, fn ($a, $b) => strcmp((string) ($a['branch']['name'] ?? ''), (string) ($b['branch']['name'] ?? '')));

        ksort($daily);
        $dailyRows = [];
        foreach ($daily as $d => $acc) {
            $dailyRows[] = ['date' => $d] + $this->finalize($acc, withCategories: false);
        }

        // ── Colaboradores (motor de la actividad semanal, corte = mes natural).
        [$employees, $unidentified, $employeesMissing] = $this->employees($candidates, $from, $to, $withData);

        $totals = $this->finalize($total);

        return [
            'status' => $coverage['covered_days'] === 0 ? self::STATUS_NO_DATA
                : ($coverage['covered_days'] === $coverage['days'] ? self::STATUS_COMPLETE : self::STATUS_PARTIAL),
            'calculated_at' => now('America/Mexico_City')->toIso8601String(),
            'coverage' => $coverage,
            'source_periods' => $candidates->filter(fn (Period $p) => isset($byPeriod[(int) $p->id]) || $this->overlaps($p, $from, $to))
                ->map(fn (Period $p) => ApiPeriodRange::describe($p) + [
                    'overlap' => $this->overlap($p, $from, $to),
                    'radiography_status' => $summaries->get($p->id)?->status ?? 'missing',
                    'contributed' => [
                        'placement' => round((float) ($byPeriod[(int) $p->id]['placement'] ?? 0), 2),
                        'recovery' => round((float) ($byPeriod[(int) $p->id]['recovery'] ?? 0), 2),
                        'erp_expenses' => round((float) ($byPeriod[(int) $p->id]['erp_expenses'] ?? 0), 2),
                    ],
                ])->values()->all(),
            'totals' => $totals,
            'daily' => $dailyRows,
            'branches' => $branchRows,
            'unassigned' => $this->finalize($unassigned),
            'employees' => $employees,
            'unidentified' => $unidentified,
            'employees_missing_periods' => $employeesMissing,
            'bank_movements' => $this->bankMovements($fromS, $toS),
            'audit' => [
                'outside_own_period_window' => [
                    'placement' => ['rows' => $outsideWindow['placement']['rows'], 'amount' => round($outsideWindow['placement']['amount'], 2)],
                    'recovery' => ['rows' => $outsideWindow['recovery']['rows'], 'amount' => round($outsideWindow['recovery']['amount'], 2)],
                    'erp_expenses' => ['rows' => $outsideWindow['erp_expenses']['rows'], 'amount' => round($outsideWindow['erp_expenses']['amount'], 2)],
                    'note' => 'Filas con fecha dentro del mes pero fuera de la ventana de 4 semanas del periodo donde se cargaron. Ya están incluidas en los totales (decide la fecha); se informan para auditoría.',
                ],
            ],
            'reconciliation' => [
                'placement' => [
                    'branches_sum' => round(array_sum(array_map(fn ($b) => $b['placement']['amount'], $branchRows)), 2),
                    'unassigned' => $this->finalize($unassigned)['placement']['amount'],
                    'total' => $totals['placement']['amount'],
                    'daily_sum' => round(array_sum(array_map(fn ($d) => $d['placement']['amount'], $dailyRows)), 2),
                ],
                'recovery' => [
                    'branches_sum' => round(array_sum(array_map(fn ($b) => $b['recovery']['total'], $branchRows)), 2),
                    'unassigned' => $this->finalize($unassigned)['recovery']['total'],
                    'total' => $totals['recovery']['total'],
                    'daily_sum' => round(array_sum(array_map(fn ($d) => $d['recovery']['total'], $dailyRows)), 2),
                ],
                'employees' => [
                    'placement' => round(array_sum(array_map(fn ($e) => $e['month']['placement']['amount'], array_merge($employees, $unidentified))), 2),
                    'recovery' => round(array_sum(array_map(fn ($e) => $e['month']['recovery']['total'], array_merge($employees, $unidentified))), 2),
                    'note' => 'Σ colaboradores + sin identificar = total del mes cuando todos los periodos que aportan tienen radiografía vigente (ver employees_missing_periods).',
                ],
            ],
        ];
    }

    // ── Colaboradores ─────────────────────────────────────────────────────

    /**
     * Colocación/recuperación del mes natural por colaborador.
     *
     * Para cada periodo candidato que se traslapa con el mes o tiene datos,
     * ejecuta el motor de actividad con un único corte = mes natural
     * (forPeriodSlots, cacheado 12 h) y suma los resultados por colaborador
     * (id canónico) y por no identificado (nombre + motivo). Omite filas en
     * cero. La sucursal del colaborador queda la del último periodo que aporta.
     * Periodos con datos y traslape pero sin radiografía vigente se reportan
     * en la lista de faltantes.
     *
     * @param  Collection  $candidates  Periodos mensuales candidatos.
     * @param  CarbonImmutable  $from  Primer día del mes.
     * @param  CarbonImmutable  $to  Último día del mes.
     * @param  array  $withData  IDs de periodos con al menos una fila de datos.
     * @return array{0: list<array>, 1: list<array>, 2: list<array>} [colaboradores, no identificados, periodos sin radiografía].
     */
    private function employees($candidates, CarbonImmutable $from, CarbonImmutable $to, array $withData): array
    {
        $slot = [['id' => null, 'start_date' => $from->toDateString(), 'end_date' => $to->toDateString()]];
        $employees = [];
        $unidentified = [];
        $missing = [];

        foreach ($candidates as $period) {
            if (! $this->overlaps($period, $from, $to) && ! in_array((int) $period->id, $withData, true)) {
                continue;
            }
            $result = $this->slotActivity->forPeriodSlots($period, $slot);
            if ($result === null) {
                if (in_array((int) $period->id, $withData, true) && $this->overlaps($period, $from, $to)) {
                    $missing[] = ['period_id' => (int) $period->id, 'label' => $period->label, 'reason' => 'sin_radiografia_vigente'];
                }

                continue;
            }

            foreach ($result['employees'] as $row) {
                $bucket = $row['weeks'][0] ?? null;
                if ($bucket === null || ($bucket['placement']['amount'] == 0 && $bucket['placement']['operations'] === 0 && $bucket['recovery']['total'] == 0)) {
                    continue;
                }
                $id = (int) $row['employee']['id'];
                if (! isset($employees[$id])) {
                    $employees[$id] = [
                        'employee' => $row['employee'],
                        // Sucursal asignada en el periodo más reciente que aporta (se sobrescribe en orden cronológico).
                        'assigned_branch_id' => $row['assigned_branch_id'],
                        'branch' => $row['branch'],
                        'branch_status' => $row['branch_status'],
                        'sucursal' => $row['sucursal'],
                        'region' => $row['region'],
                        'by_period' => [],
                        'month' => $this->zeroBucket(),
                    ];
                } else {
                    foreach (['assigned_branch_id', 'branch', 'branch_status', 'sucursal', 'region'] as $k) {
                        $employees[$id][$k] = $row[$k];
                    }
                }
                $employees[$id]['by_period'][] = ['period_id' => (int) $period->id, 'period_label' => $period->label] + $bucket;
                $employees[$id]['month'] = $this->addBucket($employees[$id]['month'], $bucket);
            }

            foreach ($result['unidentified'] as $row) {
                $bucket = $row['weeks'][0] ?? null;
                if ($bucket === null || ($bucket['placement']['amount'] == 0 && $bucket['placement']['operations'] === 0 && $bucket['recovery']['total'] == 0)) {
                    continue;
                }
                $key = mb_strtoupper((string) ($row['report_name'] ?? '')).'|'.$row['reason'];
                $unidentified[$key] ??= [
                    'report_name' => $row['report_name'],
                    'promoter_code' => $row['promoter_code'],
                    'report_branch_name' => $row['report_branch_name'],
                    'reason' => $row['reason'],
                    'month' => $this->zeroBucket(),
                ];
                $unidentified[$key]['month'] = $this->addBucket($unidentified[$key]['month'], $bucket);
            }
        }

        $employees = array_values($employees);
        usort($employees, fn ($a, $b) => strcmp((string) $a['employee']['full_name'], (string) $b['employee']['full_name']));

        return [$employees, array_values($unidentified), $missing];
    }

    // ── Movimientos bancarios del Estado de Resultados ──────────────────

    /**
     * Resumen del flujo bancario del mes (movimientos del Estado de
     * Resultados por fecha del movimiento).
     *
     * Solo considera cargas activas, excluye las copias marcadas «duplicado»
     * y cuenta cada huella (fingerprint) una sola vez. El tratamiento de cada
     * movimiento es el manual vigente del periodo si existe; si no, el
     * automático cuando ya fue procesado; en otro caso PENDIENTE. Agrupa por
     * tratamiento y por sucursal.
     *
     * @param  string  $from  Primer día del mes (Y-m-d).
     * @param  string  $to  Último día del mes (Y-m-d).
     * @return array<string, mixed>|null Resumen, o null si la tabla del ER no existe.
     */
    private function bankMovements(string $from, string $to): ?array
    {
        if (! Schema::hasTable('estado_resultados_movements')) {
            return null;
        }

        $rows = EstadoResultadosMovement::query()
            ->whereIn('upload_id', EstadoResultadosUpload::query()->active()->select('id'))
            ->whereBetween('movement_date', [$from, $to])
            ->where(fn ($q) => $q->whereNull('placement')->orWhere('placement', '!=', MovementPeriodAllocator::DUPLICADO))
            ->orderBy('dedup_key')->orderBy('id')
            ->get(['id', 'period_id', 'fingerprint', 'movement_date', 'account_slot', 'cargo', 'abono', 'branch_name', 'auto_treatment', 'processed_at', 'placement', 'dedup_key']);

        $overrides = [];
        $seen = [];
        $byTreatment = [];
        $byBranch = [];
        $cargos = 0.0;
        $abonos = 0.0;
        $count = 0;
        $duplicatesSkipped = 0;

        foreach ($rows as $m) {
            // Una sola vez por huella (el mismo movimiento en dos archivos/periodos cuenta una vez).
            if (isset($seen[$m->fingerprint])) {
                $duplicatesSkipped++;

                continue;
            }
            $seen[$m->fingerprint] = true;

            $pid = (int) $m->period_id;
            $overrides[$pid] ??= $this->classifications->effectiveOverrides($pid);
            $treatment = $overrides[$pid][$m->fingerprint]['treatment']
                ?? ($m->processed_at !== null ? (string) $m->auto_treatment : Treatment::PENDIENTE);
            if ($treatment === '') {
                $treatment = Treatment::PENDIENTE;
            }

            $cargo = (float) $m->cargo;
            $abono = (float) $m->abono;
            $cargos += $cargo;
            $abonos += $abono;
            $count++;

            $byTreatment[$treatment] ??= [
                'treatment' => $treatment,
                'label' => Treatment::label($treatment),
                'er_line' => Treatment::line($treatment),
                'affects_statement' => Treatment::affectsStatement($treatment),
                'accounting' => Treatment::accounting($treatment),
                'movements' => 0, 'cargos' => 0.0, 'abonos' => 0.0,
            ];
            $byTreatment[$treatment]['movements']++;
            $byTreatment[$treatment]['cargos'] += $cargo;
            $byTreatment[$treatment]['abonos'] += $abono;

            $branch = $m->branch_name ?: 'SIN SUCURSAL';
            $byBranch[$branch] ??= ['branch_name' => $branch, 'movements' => 0, 'cargos' => 0.0, 'abonos' => 0.0];
            $byBranch[$branch]['movements']++;
            $byBranch[$branch]['cargos'] += $cargo;
            $byBranch[$branch]['abonos'] += $abono;
        }

        $round = fn (array $r) => array_merge($r, ['cargos' => round($r['cargos'], 2), 'abonos' => round($r['abonos'], 2)]);
        $byTreatment = array_values(array_map($round, $byTreatment));
        usort($byTreatment, fn ($a, $b) => $b['cargos'] <=> $a['cargos']);
        $byBranch = array_values(array_map($round, $byBranch));
        usort($byBranch, fn ($a, $b) => strcmp($a['branch_name'], $b['branch_name']));

        return [
            'movements' => $count,
            'cargos' => round($cargos, 2),
            'abonos' => round($abonos, 2),
            'duplicates_skipped' => $duplicatesSkipped,
            'by_treatment' => $byTreatment,
            'by_branch' => $byBranch,
            'note' => 'Flujo de banco por fecha del movimiento (cargos = salidas, abonos = entradas), con el tratamiento vigente de cada uno. No es el Estado de Resultados: el ER oficial se mide por periodo de 4 semanas y reconoce los gastos por devengo.',
        ];
    }

    // ── Cobertura ─────────────────────────────────────────────────────────

    /**
     * Filtra los periodos que tienen al menos una fila en fact_placements,
     * fact_recoveries o fact_expenses (solo busca por los ids recibidos; no
     * incluye sus semanas componentes).
     *
     * @param  array  $periodIds  IDs de periodos mensuales.
     * @return list<int> ids de periodos mensuales con al menos una fila de datos.
     */
    private function periodIdsWithData(array $periodIds): array
    {
        if ($periodIds === []) {
            return [];
        }
        $ids = [];
        foreach (['fact_placements', 'fact_recoveries', 'fact_expenses'] as $table) {
            foreach (DB::table($table)->whereIn('period_id', $periodIds)->distinct()->pluck('period_id') as $id) {
                $ids[(int) $id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Cobertura día por día del mes: un día está cubierto si cae dentro de un
     * periodo (el primero que lo contenga) que tiene datos. Devuelve también
     * los rangos sin cobertura y los periodos que existen pero sin datos.
     *
     * @param  Collection  $periods  Periodos mensuales a considerar.
     * @param  CarbonImmutable  $from  Primer día del mes.
     * @param  CarbonImmutable  $to  Último día del mes.
     * @param  array  $withData  IDs de periodos con datos.
     * @return array<string, mixed> month, fechas, days, covered_days, missing_ranges, periods_without_data.
     */
    private function coverage($periods, CarbonImmutable $from, CarbonImmutable $to, array $withData): array
    {
        $days = (int) $from->diffInDays($to) + 1;
        $covered = 0;
        $missing = [];
        $noData = [];
        $gapStart = null;

        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $ds = $d->toDateString();
            $owner = $periods->first(fn (Period $p) => optional($p->start_date)->toDateString() <= $ds && optional($p->end_date)->toDateString() >= $ds);
            $ok = $owner !== null && in_array((int) $owner->id, $withData, true);
            if ($owner !== null && ! $ok) {
                $noData[(int) $owner->id] = $owner->label;
            }
            if ($ok) {
                $covered++;
                if ($gapStart !== null) {
                    $missing[] = ['from' => $gapStart, 'to' => $d->subDay()->toDateString()];
                    $gapStart = null;
                }
            } elseif ($gapStart === null) {
                $gapStart = $ds;
            }
        }
        if ($gapStart !== null) {
            $missing[] = ['from' => $gapStart, 'to' => $to->toDateString()];
        }

        return [
            'month' => $from->format('Y-m'),
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'days' => $days,
            'covered_days' => $covered,
            'missing_ranges' => $missing,
            'periods_without_data' => array_map(fn ($id, $label) => ['period_id' => $id, 'label' => $label], array_keys($noData), array_values($noData)),
        ];
    }

    /**
     * Indica si el periodo (con fechas definidas) se traslapa con el rango.
     *
     * @param  Period  $p  Periodo.
     * @param  CarbonImmutable  $from  Inicio del rango.
     * @param  CarbonImmutable  $to  Fin del rango.
     * @return bool true si hay al menos un día en común.
     */
    private function overlaps(Period $p, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $p->start_date && $p->end_date
            && $p->start_date->toDateString() <= $to->toDateString()
            && $p->end_date->toDateString() >= $from->toDateString();
    }

    /**
     * Tramo de días en común entre el periodo y el rango.
     *
     * @param  Period  $p  Periodo.
     * @param  CarbonImmutable  $from  Inicio del rango.
     * @param  CarbonImmutable  $to  Fin del rango.
     * @return array{from: string, to: string, days: int}|null Tramo, o null si no se traslapan.
     */
    private function overlap(Period $p, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        if (! $this->overlaps($p, $from, $to)) {
            return null;
        }
        $a = max($p->start_date->toDateString(), $from->toDateString());
        $b = min($p->end_date->toDateString(), $to->toDateString());

        return ['from' => $a, 'to' => $b, 'days' => (int) CarbonImmutable::parse($a)->diffInDays(CarbonImmutable::parse($b)) + 1];
    }

    /**
     * Indica si una fecha cae fuera de la ventana [inicio, fin] de su periodo
     * de carga (solo para auditoría). Sin ventana o sin fecha → false.
     *
     * @param  array|null  $window  [start_date, end_date] en Y-m-d.
     * @param  string|null  $date  Fecha del movimiento.
     * @return bool true si la fecha está fuera de la ventana.
     */
    private function outside(?array $window, ?string $date): bool
    {
        if ($window === null || $window[0] === null || $window[1] === null || $date === null) {
            return false;
        }
        $d = substr($date, 0, 10);

        return $d < $window[0] || $d > $window[1];
    }

    // ── Acumuladores ──────────────────────────────────────────────────────

    /**
     * Acumulador vacío de colocación, recuperación y gastos ERP.
     *
     * @return array<string, mixed>
     */
    private function emptyAcc(): array
    {
        return ['placement' => 0.0, 'operations' => 0, 'recovery' => 0.0, 'erp_expenses' => 0.0, 'erp_paid' => 0.0, 'erp_rows' => 0, 'erp_by_category' => []];
    }

    /**
     * Convierte un acumulador en la forma pública (montos redondeados a 2
     * decimales).
     *
     * @param  array  $acc  Acumulador (ver emptyAcc()).
     * @param  bool  $withCategories  Si se incluye el desglose de gastos ERP por categoría (ordenado alfabéticamente).
     * @return array{placement: array, recovery: array, erp_expenses: array}
     */
    private function finalize(array $acc, bool $withCategories = true): array
    {
        $erp = [
            'amount' => round($acc['erp_expenses'], 2),
            'paid_amount' => round($acc['erp_paid'], 2),
            'rows' => (int) $acc['erp_rows'],
        ];
        if ($withCategories) {
            $cats = $acc['erp_by_category'];
            ksort($cats);
            $erp['by_category'] = array_map(fn ($k, $v) => ['category' => $k, 'amount' => round($v, 2)], array_keys($cats), array_values($cats));
        }

        return [
            'placement' => ['amount' => round($acc['placement'], 2), 'operations' => (int) $acc['operations']],
            'recovery' => ['total' => round($acc['recovery'], 2)],
            'erp_expenses' => $erp,
        ];
    }

    /**
     * Bucket en cero de colocación/recuperación por colaborador.
     *
     * @return array{placement: array{amount: float, operations: int}, recovery: array{total: float}}
     */
    private function zeroBucket(): array
    {
        return ['placement' => ['amount' => 0.0, 'operations' => 0], 'recovery' => ['total' => 0.0]];
    }

    /**
     * Suma dos buckets de colocación/recuperación (redondeando montos).
     *
     * @param  array  $a  Bucket acumulado.
     * @param  array  $b  Bucket a sumar.
     * @return array Bucket resultante.
     */
    private function addBucket(array $a, array $b): array
    {
        return [
            'placement' => [
                'amount' => round($a['placement']['amount'] + $b['placement']['amount'], 2),
                'operations' => $a['placement']['operations'] + $b['placement']['operations'],
            ],
            'recovery' => ['total' => round($a['recovery']['total'] + $b['recovery']['total'], 2)],
        ];
    }
}
