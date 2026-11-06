<?php

namespace App\Services\EstadoResultados\Reporting;

use App\Models\Period;
use App\Services\EstadoResultados\PeriodDataIds;
use App\Services\EstadoResultados\Reconciliation\ErpExpenseRepository;
use App\Services\EstadoResultados\Support\RadiographySummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Firma de TODOS los insumos que alimentan el dataset del Estado de Resultados de un
 * periodo (fase 2.2). La aprobación la calcula ANTES de construir los datasets y la
 * vuelve a calcular DENTRO de la transacción (con el periodo bloqueado FOR UPDATE):
 * si difiere, algo cambió mientras se aprobaba y la aprobación se rechaza (409) en vez
 * de congelar un dataset que ya no corresponde a los datos.
 *
 * Cubre: cargas bancarias activas, movimientos (importe, proceso, actualización),
 * vínculos y corridas de conciliación, clasificaciones manuales, ajustes contables,
 * firma ERP, consolidado de Radiografía vigente, re-importaciones de cualquier fuente
 * de Radiografía (report_uploads) y los hechos que lee BranchRadiographyCalculator
 * (fact_*: conteo, id máximo, última actualización y sumas de control), sucursales,
 * universo de periodos y versiones de reglas.
 *
 * Límite conocido: un UPDATE directo en SQL que no toque updated_at ni las columnas de
 * control (importe / sucursal / colaborador) no se detecta. Todas las escrituras de la
 * aplicación pasan por Eloquent o por importadores que reescriben filas.
 */
class ApprovalInputSignature
{
    /** tabla => expresiones de control adicionales (además de count, max(id), max(updated_at)). */
    private const FACT_TABLES = [
        'report_uploads' => ['status_ck' => "SUM(id * LENGTH(COALESCE(status, '')))"],
        'fact_expenses' => ['amount_sum' => 'SUM(amount)', 'branch_ck' => 'SUM(id * COALESCE(branch_id, 0))'],
        'fact_noi_movements' => ['amount_sum' => 'SUM(amount)', 'employee_ck' => 'SUM(id * COALESCE(employee_id, 0))'],
        'fact_recoveries' => [],
        'fact_portfolios' => [],
        'fact_placements' => [],
        'fact_imss' => [],
        'employee_branch_assignments' => ['branch_ck' => 'SUM(id * COALESCE(branch_id, 0))'],
    ];

    public function __construct(
        private readonly PeriodDataIds $dataIds,
        private readonly ErpExpenseRepository $erp,
    ) {}

    /** @return array{hash:string, components:array<string,mixed>} */
    public function compute(Period $period): array
    {
        $dataIds = $this->dataIds->for($period);
        sort($dataIds);
        $pid = $period->id;

        $c = [
            'period' => [$pid, (string) $period->start_date?->format('Y-m-d'), (string) $period->end_date?->format('Y-m-d')],
            'data_ids' => $dataIds,
            'rules' => [(string) config('estado_resultados.rules_version'), (string) config('estado_resultados.reconocimiento.version')],
            'uploads' => DB::table('estado_resultados_uploads')->where('period_id', $pid)->orderBy('id')
                ->get(['id', 'file_hash', 'status', 'control_ok', 'updated_at'])->map(fn ($r) => array_values((array) $r))->all(),
            'movements' => $this->aggregate('estado_resultados_movements', fn ($q) => $q->where('period_id', $pid),
                ['cargo_sum' => 'SUM(cargo)', 'abono_sum' => 'SUM(abono)', 'processed_max' => 'MAX(processed_at)', 'applied_sum' => 'SUM(applied_amount)']),
            'links' => $this->aggregate('estado_resultados_reconciliation_links', fn ($q) => $q->where('period_id', $pid), [], false),
            'runs' => DB::table('estado_resultados_runs')->where('period_id', $pid)->orderByDesc('id')->limit(1)
                ->get(['id', 'status', 'input_signature', 'erp_signature'])->map(fn ($r) => array_values((array) $r))->all(),
            'classifications' => $this->aggregate('estado_resultados_classifications', fn ($q) => $q->where('period_id', $pid), [], false),
            'adjustments' => DB::table('estado_resultados_adjustments')->where('period_id', $pid)->orderBy('scope_key')->orderBy('concept')
                ->get(['scope_key', 'concept', 'state', 'amount', 'updated_at'])->map(fn ($r) => array_values((array) $r))->all(),
            'erp_signature' => ErpExpenseRepository::signature($this->erp->forDataIds($dataIds)),
            'radiografia_summary' => (function () use ($pid) {
                $s = RadiographySummary::current($pid);

                return $s ? [(int) $s->id, (string) ($s->updated_at ?? ''), (string) ($s->generated_at ?? '')] : null;
            })(),
            'branches' => $this->aggregate('branches', fn ($q) => $q, []),
            'rotacion' => $this->aggregate('fact_rotacion', fn ($q) => $q->where('period_id', $pid), []),
        ];
        foreach (self::FACT_TABLES as $table => $extra) {
            $c['fact.'.$table] = $this->aggregate($table, fn ($q) => $q->whereIn('period_id', $dataIds ?: [0]), $extra);
        }
        // NOI de corporativo: sólo si el periodo tiene cargas (las firmas de versiones previas no cambian).
        if (Schema::hasTable('estado_resultados_payroll_uploads')) {
            $payroll = DB::table('estado_resultados_payroll_uploads')->where('period_id', $pid)->orderBy('id')
                ->get(['id', 'file_hash', 'status', 'total_percepciones', 'updated_at'])->map(fn ($r) => array_values((array) $r))->all();
            if ($payroll !== []) {
                $c['payroll_uploads'] = $payroll;
            }
        }

        return ['hash' => hash('sha256', json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)), 'components' => $c];
    }

    /** Nombres de los componentes que difieren entre dos firmas (para el mensaje y la bitácora). */
    public static function diff(array $before, array $after): array
    {
        $keys = array_unique(array_merge(array_keys($before['components'] ?? []), array_keys($after['components'] ?? [])));
        $out = [];
        foreach ($keys as $k) {
            if (json_encode($before['components'][$k] ?? null) !== json_encode($after['components'][$k] ?? null)) {
                $out[] = $k;
            }
        }

        return $out;
    }

    private static array $tableCache = [];

    private function aggregate(string $table, callable $where, array $extra, bool $withUpdated = true): ?array
    {
        if (! (self::$tableCache[$table] ??= Schema::hasTable($table))) {
            return null;
        }
        $select = ['COUNT(*) AS n', 'MAX(id) AS max_id'];
        if ($withUpdated) {
            $select[] = 'MAX(updated_at) AS max_updated';
        }
        foreach ($extra as $alias => $expr) {
            $select[] = $expr.' AS '.$alias;
        }
        $row = $where(DB::table($table))->selectRaw(implode(', ', $select))->first();

        return array_map(fn ($v) => $v === null ? null : (string) $v, array_values((array) $row));
    }
}
