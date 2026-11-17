<?php

namespace App\Services\Api;

use App\Models\Branch;
use App\Models\Period;
use App\Services\EmployeeNameCanonicalizer;
use App\Services\Radiography\BranchRadiographyCalculator;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * API v1 — ACTIVIDAD SEMANAL de un mes (2026-11-17, Seguimiento de MR. LANA PEOPLE).
 * Solo lectura: nunca escribe en BD.
 *
 * Modelo de periodos de Reportería (respetado tal cual, nunca un calendario propio):
 *   - Un periodo `monthly` agrupa sus semanas en `component_period_ids` (4 semanas
 *     normalmente). La pertenencia de una semana al mes es ESA lista — nunca el campo
 *     `month` de la semana (una semana puede llamarse "Semana 22 - Mayo" y pertenecer a
 *     Junio).
 *   - La radiografía y TODOS los fact_* se cargan y etiquetan con el period_id del MES:
 *     las semanas son metadata organizativa (Period::isBase(), canReceiveUploads()).
 *
 * Por eso una cifra semanal NO es otra radiografía: es el MISMO conjunto de filas del
 * mes (dataIds del builder) repartido por la fecha de cada movimiento:
 *   - Colocación: fact_placements.operation_date, SUM(amount) / COUNT(*) — mismo
 *     criterio de gestor que buildEmployeesGestores() (COALESCE(promoter_name, promoter_code)).
 *   - Recuperación: fact_recoveries.payment_date con la regla ÚNICA de
 *     BranchRadiographyCalculator::recoveryExclusionSql()['recovery'] (gestor por
 *     promoter_name, igual que el reporte).
 *   - Las llaves de gestor se pliegan con el MISMO mapa de fusión del reporte
 *     (RadiographySnapshotBuilder::lastGestorMergeMap()) → Σ semanas + fuera de semana
 *     == colocación/recuperación oficial del mes del colaborador, por construcción.
 *   - Movimientos sin fecha o fuera de las semanas del mes van a `outside_weeks`
 *     (auditable), nunca se reparten ni se descartan.
 *
 * Sucursal: atribución por `branch_id` del propio movimiento (`attribution:
 * fact_branch_id`). Los KPIs OFICIALES mensuales por sucursal siguen siendo
 * /periods/{id}/branches (que aplica la atribución de respaldo del reporte).
 *
 * Caché: 12 h por periodo + versión de la radiografía vigente (igual que
 * EmployeePeriodKpiService). Sin radiografía vigente → not_available (nunca ceros).
 */
class PeriodWeeklyActivityService
{
    public const STATUS_OK = 'ok';

    public const STATUS_NOT_AVAILABLE = 'not_available';

    public const ATTRIBUTION = 'fact_branch_id';

    private const CACHE_TTL = 43_200;

    private const CACHE_VERSION = 'v1';

    /** @var array<int, array> memo por petición */
    private array $memo = [];

    public function __construct(
        private readonly RadiographySnapshotBuilder $snapshotBuilder,
        private readonly EmployeePeriodKpiService $employeeKpis,
        private readonly EmployeeNameCanonicalizer $canonicalizer,
    ) {}

    /** ¿El periodo admite desglose semanal? Solo un mes con semanas componentes. */
    public static function supports(Period $period): bool
    {
        return $period->isMonthly() && ! empty($period->component_period_ids);
    }

    /**
     * Semanas componentes del mes, en orden cronológico.
     *
     * @return list<array{id:int, code:?string, label:string, sequence:?int, index:int, start_date:?string, end_date:?string}>
     */
    public static function weeksFor(Period $period): array
    {
        $ids = collect($period->component_period_ids ?? [])->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return [];
        }

        $weeks = Period::query()
            ->whereIn('id', $ids)
            ->where('type', 'weekly')
            ->orderBy('start_date')
            ->get();

        $out = [];
        foreach ($weeks->values() as $i => $week) {
            $out[] = [
                'id' => (int) $week->id,
                'code' => $week->code,
                'label' => $week->label,
                'sequence' => $week->sequence === null ? null : (int) $week->sequence,
                'index' => $i + 1,
                'start_date' => optional($week->start_date)->toDateString(),
                'end_date' => optional($week->end_date)->toDateString(),
            ];
        }

        return $out;
    }

    /**
     * Resultado SIN filtrar por consumidor (el controlador aplica alcance e include).
     *
     * @return array{status:string, message:?string, calculated_at:?string, radiography_version:?int, weeks:list<array>, branches:list<array>, unassigned:?array, employees:list<array>, unidentified:list<array>, reconciliation:?array}
     */
    public function forPeriod(Period $period): array
    {
        if (isset($this->memo[$period->id])) {
            return $this->memo[$period->id];
        }

        $weeks = self::weeksFor($period);
        $summary = $this->employeeKpis->currentSummary($period);
        if ($summary === null) {
            return $this->memo[$period->id] = [
                'status' => self::STATUS_NOT_AVAILABLE,
                'message' => "No existe una radiografía vigente para el periodo {$period->label}. Genérala desde el reporte mensual.",
                'calculated_at' => null,
                'radiography_version' => null,
                'weeks' => $weeks,
                'branches' => [],
                'unassigned' => null,
                'employees' => [],
                'unidentified' => [],
                'reconciliation' => null,
            ];
        }

        $key = sprintf(
            'api_v1_period_weekly_activity:%s:%d:%d:%d',
            self::CACHE_VERSION,
            $period->id,
            $summary->id,
            $summary->updated_at?->timestamp ?? 0
        );

        return $this->memo[$period->id] = Cache::remember($key, self::CACHE_TTL, fn () => $this->compute($period, (int) $summary->id, $weeks));
    }

    private function compute(Period $period, int $radiographyVersion, array $weeks): array
    {
        // Filas del reporte + mapa de fusión de ESTA misma construcción.
        $gestorRows = $this->snapshotBuilder->buildAllEmployeeGestorRows($period);
        $mergeMap = $this->snapshotBuilder->lastGestorMergeMap();
        $dataIds = $this->snapshotBuilder->resolveDataIdsPublic($period);
        $kpiRows = collect($this->employeeKpis->forPeriod($period)['rows'])->keyBy(fn ($r) => (int) $r['employee']['id']);

        $rowByNorm = [];
        foreach ($gestorRows as $row) {
            if (isset($row['_norm_key'])) {
                $rowByNorm[(string) $row['_norm_key']] = $row;
            }
        }

        $byGestor = [];   // norm canónico → acumulador
        $nameByNorm = [];
        $byBranch = [];   // branch_id → acumulador
        $unassigned = $this->emptyAccumulator(count($weeks));
        $total = $this->emptyAccumulator(count($weeks));

        // ── Colocación por fecha de operación.
        DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->selectRaw('promoter_name, promoter_code, branch_id, DATE(operation_date) as d, COUNT(*) as operations, SUM(amount) as amount')
            ->groupBy('promoter_name', 'promoter_code', 'branch_id', DB::raw('DATE(operation_date)'))
            ->get()
            ->each(function ($r) use ($weeks, $mergeMap, &$byGestor, &$nameByNorm, &$byBranch, &$unassigned, &$total) {
                $slot = $this->slotFor($weeks, $r->d);
                $amount = (float) $r->amount;
                $ops = (int) $r->operations;

                $this->addPlacement($total, $slot, $amount, $ops);
                if ($r->branch_id === null) {
                    $this->addPlacement($unassigned, $slot, $amount, $ops);
                } else {
                    $byBranch[(int) $r->branch_id] ??= $this->emptyAccumulator(count($weeks));
                    $this->addPlacement($byBranch[(int) $r->branch_id], $slot, $amount, $ops);
                }

                if ($r->promoter_name === null && $r->promoter_code === null) {
                    return;
                }
                $raw = (string) ($r->promoter_name ?? $r->promoter_code);
                $norm = $this->canonicalizer->normalize($raw);
                if ($norm === '') {
                    return;
                }
                $norm = $mergeMap[$norm] ?? $norm;
                $nameByNorm[$norm] ??= mb_strtoupper(trim($raw));
                $byGestor[$norm] ??= $this->emptyAccumulator(count($weeks));
                $this->addPlacement($byGestor[$norm], $slot, $amount, $ops);
            });

        // ── Recuperación por fecha de pago — regla ÚNICA del reporte.
        ['recovery' => $exclRecovery] = BranchRadiographyCalculator::recoveryExclusionSql();
        DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->selectRaw("promoter_name, branch_id, DATE(payment_date) as d, SUM(CASE {$exclRecovery} ELSE total_amount END) as recovery")
            ->groupBy('promoter_name', 'branch_id', DB::raw('DATE(payment_date)'))
            ->get()
            ->each(function ($r) use ($weeks, $mergeMap, &$byGestor, &$nameByNorm, &$byBranch, &$unassigned, &$total) {
                $slot = $this->slotFor($weeks, $r->d);
                $amount = (float) $r->recovery;

                $this->addRecovery($total, $slot, $amount);
                if ($r->branch_id === null) {
                    $this->addRecovery($unassigned, $slot, $amount);
                } else {
                    $byBranch[(int) $r->branch_id] ??= $this->emptyAccumulator(count($weeks));
                    $this->addRecovery($byBranch[(int) $r->branch_id], $slot, $amount);
                }

                if ($r->promoter_name === null) {
                    return;
                }
                $norm = $this->canonicalizer->normalize((string) $r->promoter_name);
                if ($norm === '') {
                    return;
                }
                $norm = $mergeMap[$norm] ?? $norm;
                $nameByNorm[$norm] ??= mb_strtoupper(trim((string) $r->promoter_name));
                $byGestor[$norm] ??= $this->emptyAccumulator(count($weeks));
                $this->addRecovery($byGestor[$norm], $slot, $amount);
            });

        // ── Colaboradores: una fila por fila del reporte con identidad (misma lista que /employees).
        $employees = [];
        $unidentified = [];
        $seen = [];
        foreach ($rowByNorm as $norm => $row) {
            $acc = $byGestor[$norm] ?? $this->emptyAccumulator(count($weeks));
            $seen[$norm] = true;
            $ids = array_values(array_unique(array_map('intval', $row['_employee_ids'] ?? [])));
            $kpi = $ids === [] ? null : $kpiRows->get(min($ids));

            if ($kpi === null) {
                $unidentified[] = [
                    'report_name' => $row['name'] ?? null,
                    'promoter_code' => $row['code'] ?? null,
                    'report_branch_name' => $row['branch'] ?? null,
                    'reason' => 'sin_identidad',
                ] + $this->finalize($acc, $weeks);

                continue;
            }

            $employees[] = [
                'employee' => [
                    'id' => $kpi['employee']['id'],
                    'employee_ids' => $kpi['employee']['employee_ids'],
                    'full_name' => $kpi['employee']['full_name'],
                    'report_name' => $kpi['employee']['report_name'],
                    'employee_code' => $kpi['employee']['employee_code'],
                    'promoter_code' => $kpi['employee']['promoter_code'],
                    'position' => $kpi['employee']['position'],
                    'is_active' => $kpi['employee']['is_active'],
                ],
                'assigned_branch_id' => $kpi['assigned_branch_id'],
                'branch' => $kpi['branch'],
                'branch_status' => $kpi['branch_status'],
                'sucursal' => $kpi['sucursal'],
                'region' => $kpi['region'],
            ] + $this->finalize($acc, $weeks) + [
                'month_official' => [
                    'placement' => [
                        'amount' => (float) $kpi['kpis']['placement']['amount'],
                        'operations' => (int) $kpi['kpis']['placement']['operations'],
                    ],
                    'recovery' => ['total' => (float) $kpi['kpis']['recovery']['total']],
                ],
            ];
        }

        // Llaves con movimientos pero sin fila en el reporte (el reporte tampoco las muestra).
        foreach ($byGestor as $norm => $acc) {
            if (isset($seen[$norm])) {
                continue;
            }
            $unidentified[] = [
                'report_name' => $nameByNorm[$norm] ?? mb_strtoupper($norm),
                'promoter_code' => null,
                'report_branch_name' => null,
                'reason' => 'sin_fila_en_reporte',
            ] + $this->finalize($acc, $weeks);
        }

        usort($employees, fn ($a, $b) => strcmp((string) $a['employee']['full_name'], (string) $b['employee']['full_name']));

        // ── Sucursales (branch del movimiento).
        $branchModels = $byBranch === [] ? collect() : Branch::query()->with('sucursal')->whereIn('id', array_keys($byBranch))->get()->keyBy('id');
        $branches = [];
        foreach ($byBranch as $branchId => $acc) {
            $branch = $branchModels->get($branchId);
            $attribution = $branch?->attributionSummary();
            $branches[] = [
                'branch_id' => $branchId,
                'branch' => $branch === null ? null : ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name],
                'sucursal' => $attribution['sucursal'] ?? null,
                'region' => $attribution['region'] ?? null,
            ] + $this->finalize($acc, $weeks);
        }
        usort($branches, fn ($a, $b) => strcmp((string) ($a['branch']['name'] ?? ''), (string) ($b['branch']['name'] ?? '')));

        $totals = $this->finalize($total, $weeks);

        return [
            'status' => self::STATUS_OK,
            'message' => null,
            'calculated_at' => now('America/Mexico_City')->toIso8601String(),
            'radiography_version' => $radiographyVersion,
            'weeks' => $weeks,
            'branches' => $branches,
            'unassigned' => $this->finalize($unassigned, $weeks),
            'employees' => $employees,
            'unidentified' => $unidentified,
            'reconciliation' => [
                'placement' => [
                    'weeks_sum' => round(array_sum(array_map(fn ($w) => $w['placement']['amount'], $totals['weeks'])), 2),
                    'outside_weeks' => $totals['outside_weeks']['placement']['amount'],
                    'month_total' => $totals['month_total']['placement']['amount'],
                ],
                'recovery' => [
                    'weeks_sum' => round(array_sum(array_map(fn ($w) => $w['recovery']['total'], $totals['weeks'])), 2),
                    'outside_weeks' => $totals['outside_weeks']['recovery']['total'],
                    'month_total' => $totals['month_total']['recovery']['total'],
                ],
            ],
        ];
    }

    // ── Acumuladores ───────────────────────────────────────────────────────

    /** Índice 0-based de la semana que contiene la fecha, o null (sin fecha / fuera del mes). */
    private function slotFor(array $weeks, mixed $date): ?int
    {
        if ($date === null || $date === '') {
            return null;
        }
        $d = substr((string) $date, 0, 10);
        foreach ($weeks as $i => $week) {
            if ($week['start_date'] !== null && $week['end_date'] !== null && $d >= $week['start_date'] && $d <= $week['end_date']) {
                return $i;
            }
        }

        return null;
    }

    private function emptyAccumulator(int $weeks): array
    {
        return [
            'weeks' => array_fill(0, $weeks, ['placement' => 0.0, 'operations' => 0, 'recovery' => 0.0]),
            'outside' => ['placement' => 0.0, 'operations' => 0, 'recovery' => 0.0],
        ];
    }

    private function addPlacement(array &$acc, ?int $slot, float $amount, int $ops): void
    {
        if ($slot === null) {
            $acc['outside']['placement'] += $amount;
            $acc['outside']['operations'] += $ops;
        } else {
            $acc['weeks'][$slot]['placement'] += $amount;
            $acc['weeks'][$slot]['operations'] += $ops;
        }
    }

    private function addRecovery(array &$acc, ?int $slot, float $amount): void
    {
        if ($slot === null) {
            $acc['outside']['recovery'] += $amount;
        } else {
            $acc['weeks'][$slot]['recovery'] += $amount;
        }
    }

    /** @return array{weeks:list<array>, outside_weeks:array, month_total:array} */
    private function finalize(array $acc, array $weeks): array
    {
        $bucket = fn (array $b) => [
            'placement' => ['amount' => round($b['placement'], 2), 'operations' => (int) $b['operations']],
            'recovery' => ['total' => round($b['recovery'], 2)],
        ];

        $sum = $acc['outside'];
        $out = [];
        foreach ($weeks as $i => $week) {
            $b = $acc['weeks'][$i];
            $sum['placement'] += $b['placement'];
            $sum['operations'] += $b['operations'];
            $sum['recovery'] += $b['recovery'];
            $out[] = ['period_id' => $week['id']] + $bucket($b);
        }

        return [
            'weeks' => $out,
            'outside_weeks' => $bucket($acc['outside']),
            'month_total' => $bucket($sum),
        ];
    }
}
