<?php

namespace App\Services\EstadoResultados;

use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\EstadoResultados\EstadoResultadosReconciliationLink;
use App\Models\EstadoResultados\EstadoResultadosRun;
use App\Models\EstadoResultados\EstadoResultadosUpload;
use App\Models\Period;
use App\Services\EstadoResultados\Classification\MovementClassifier;
use App\Services\EstadoResultados\Reconciliation\ErpExpenseRepository;
use App\Services\EstadoResultados\Reconciliation\EstadoResultadosExpenseReconciler as R;
use App\Services\EstadoResultados\Reconciliation\NoiPayrollRepository;
use App\Services\EstadoResultados\Reconciliation\RefundMatcher;
use App\Services\EstadoResultados\Reconciliation\ReversalMatcher;
use App\Services\EstadoResultados\Reporting\PeriodLockGuard;
use App\Services\EstadoResultados\Support\Dimensions;
use App\Services\EstadoResultados\Support\Money;
use App\Services\EstadoResultados\Support\ProcessingLockedException;
use App\Services\EstadoResultados\Support\Treatment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Procesamiento (re-ejecutable e idempotente) de un periodo:
 *   1. toma un CANDADO por periodo (dos procesos simultáneos no se pisan),
 *   2. lee los movimientos complementarios ya importados,
 *   3. detecta pares de reverso (cargo + abono que se anulan),
 *   4. concilia los cargos contra el ERP (1:1, 1:N, N:1, N:M, parcial — sólo con evidencia exacta),
 *   5. aplica las reglas automáticas (MovementClassifier) y las de evidencia cruzada:
 *      nómina NOI (no crear un segundo gasto), reembolsos vinculados por folio,
 *   6. empareja traspasos entre cuentas (eliminación interna),
 *   7. guarda en cada movimiento sus DIMENSIONES independientes y regenera los vínculos
 *      (estado_resultados_reconciliation_links) + bitácora de corrida.
 *
 * No inserta ni borra movimientos: reprocesar N veces deja exactamente el mismo
 * estado. Las clasificaciones manuales viven aparte y no se tocan.
 */
class EstadoResultadosProcessor
{
    public function __construct(
        private readonly ErpExpenseRepository $erp,
        private readonly MovementClassifier $classifier,
        private readonly PeriodDataIds $dataIds,
        private readonly NoiPayrollRepository $noi,
    ) {}

    public static function lockKey(int $periodId): string
    {
        return 'estado_resultados:procesar:'.$periodId;
    }

    public function process(Period $period, ?int $userId = null): EstadoResultadosRun
    {
        // Un periodo con versión aprobada vigente no se reprocesa (tampoco desde CLI o cola).
        app(PeriodLockGuard::class)->assertUnlocked($period);

        $lock = Cache::lock(self::lockKey($period->id), max(30, (int) config('estado_resultados.procesamiento.lock_segundos', 600)));
        if (! $lock->get()) {
            throw new ProcessingLockedException($period->id);
        }

        try {
            $criterion = Dimensions::criterion();
            $run = EstadoResultadosRun::query()->create([
                'period_id' => $period->id,
                'user_id' => $userId,
                'status' => 'en_proceso',
                'rules_version' => (string) config('estado_resultados.rules_version'),
                'recognition_version' => $criterion['version'],
                'recognition_criterion' => $criterion,
                'started_at' => now(),
            ]);

            try {
                $summary = $this->run($period, $run);
                $run->update([
                    'status' => 'ok', 'summary' => $summary['summary'], 'erp_signature' => $summary['erp_signature'],
                    'input_signature' => $summary['input_signature'], 'finished_at' => now(),
                ]);
            } catch (\Throwable $e) {
                $run->update(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
                throw $e;
            }

            return $run->fresh();
        } finally {
            $lock->release();
        }
    }

    private function run(Period $period, EstadoResultadosRun $run): array
    {
        $dataIds = $this->dataIds->for($period);
        $erpRows = $this->erp->forDataIds($dataIds);
        $erpByFp = [];
        foreach ($erpRows as $e) {
            $erpByFp[$e['fingerprint']] = $e;
        }

        $movements = EstadoResultadosMovement::query()
            ->where('period_id', $period->id)
            ->orderBy('movement_date')->orderBy('account_slot')->orderBy('source_row')
            ->get();
        $uploads = EstadoResultadosUpload::query()->whereIn('id', $movements->pluck('upload_id')->unique()->all() ?: [0])->get()->keyBy('id');

        // ── Reversos (antes de conciliar: un cargo reversado no es gasto) ──
        $reversals = ReversalMatcher::fromConfig()->match(
            $movements->filter(fn ($m) => $m->in_period)->map(fn ($m) => $this->rowInput($m))->values()->all()
        );

        $cargos = [];
        $folios = [];
        foreach ($movements as $m) {
            if ($m->in_period && (float) $m->cargo > 0 && ! isset($reversals[$m->fingerprint])) {
                $cargos[] = $this->movementInput($m);
                if ($m->folio_normalized) {
                    $folios[] = $m->folio_normalized;
                }
            }
        }

        $reconciler = R::fromConfig();
        $erpFolios = array_flip(array_filter(array_column($erpRows, 'folio_normalized')));
        $missingFolios = array_values(array_filter(array_unique($folios), fn ($f) => ! isset($erpFolios[$f])));
        $outside = $this->erp->foliosOutside($dataIds, $missingFolios);
        $rec = $reconciler->reconcile($cargos, $erpRows, $outside);

        $allocByMovement = [];
        foreach ($rec['allocations'] as $a) {
            $allocByMovement[$a['movement']][] = $a;
        }

        $pairs = $this->pairTransfers($movements);
        $noi = $this->noi->forDataIds($dataIds);
        $refunds = RefundMatcher::fromConfig();

        // Gastos candidatos a recibir un reembolso: ERP del periodo + cargos bancarios no conciliados.
        $refundTargets = [];
        foreach ($erpRows as $e) {
            $refundTargets[] = ['fingerprint' => $e['fingerprint'], 'folio_normalized' => $e['folio_normalized'], 'amount' => $e['amount'], 'source' => 'erp', 'universe' => $e['universe']];
        }
        foreach ($cargos as $c) {
            if (! isset($allocByMovement[$c['fingerprint']])) {
                $refundTargets[] = ['fingerprint' => $c['fingerprint'], 'folio_normalized' => $c['folio_normalized'], 'amount' => $c['cargo'], 'source' => 'banco', 'universe' => null];
            }
        }

        $periodStart = $period->start_date->format('Y-m-d');
        $now = now();
        $counts = ['por_tratamiento' => [], 'por_conciliacion' => [], 'por_inclusion' => [], 'por_tipo_emparejamiento' => []];
        $links = [];

        DB::transaction(function () use ($period, $run, $movements, $uploads, $rec, $allocByMovement, $erpByFp, $reversals, $pairs, $noi, $refunds, $refundTargets, $periodStart, $now, &$counts, &$links) {
            // Candado a nivel BD (además del Cache::lock): serializa escrituras del periodo.
            DB::table('periods')->where('id', $period->id)->lockForUpdate()->first();

            $pairMembers = [];
            foreach ($pairs as $id => $key) {
                $pairMembers[$key][] = $id;
            }
            $byId = $movements->keyBy('id');

            foreach ($movements as $m) {
                $fp = $m->fingerprint;
                $input = $this->movementInput($m) + [
                    'concept' => $m->concept, 'observations' => $m->observations, 'bank_reference' => $m->bank_reference,
                    'abono' => $m->abono, 'in_period' => $m->in_period, 'sucursal_raw' => $m->sucursal_raw,
                ];
                $r = $rec['results'][$fp] ?? null;
                $c = $this->classifier->classify($input, $r);
                $conciliated = $r !== null && in_array($r['status'], R::CONCILIADOS, true);
                $noiEmployee = null;
                $refund = null;

                if (isset($reversals[$fp])) {
                    $rv = $reversals[$fp];
                    $c = $this->out(Treatment::REVERSO, null, 'reverso_par', "Par de reverso {$rv['key']} ({$rv['evidence']}): el cargo y el abono se anulan; neto \$0 fuera del ER.");
                } elseif ($m->in_period && (float) $m->cargo > 0 && ! $conciliated && $this->isPayroll($c)) {
                    [$c, $noiEmployee] = $this->applyNoiEvidence($c, $m, $noi);
                } elseif ($m->in_period && (float) $m->abono > 0 && $c['rule'] === 'reembolso_sin_vinculo') {
                    $refund = $refunds->match(['fingerprint' => $fp, 'folio_normalized' => $m->folio_normalized, 'abono' => (string) $m->abono], $refundTargets);
                    $c = $this->applyRefund($c, $refund);
                }

                $recStatus = match (true) {
                    ! $m->in_period => 'fuera_periodo',
                    isset($reversals[$fp]) => 'reverso',
                    $c['treatment'] === Treatment::TRANSFERENCIA_INTERNA && $c['rule'] === 'traspaso_cuentas_propias' => 'transferencia',
                    (float) $m->cargo <= 0 => 'abono',
                    default => $r['status'] ?? R::SIN_FOLIO,
                };

                // ── Fechas y reconocimiento ────────────────────────────────
                $linkedErp = array_map(fn ($a) => $erpByFp[$a['erp']], $allocByMovement[$fp] ?? []);
                $outKind = null;
                if (! $m->in_period) {
                    $outKind = $m->movement_date->format('Y-m-d') < $periodStart ? 'fecha_anterior_al_periodo' : 'fecha_posterior_al_periodo';
                } elseif ($recStatus === R::FOLIO_OTRO_PERIODO) {
                    $starts = array_filter(array_column((array) ($r['erp_other_periods'] ?? []), 'period_start'));
                    $outKind = $starts !== [] && max($starts) < $periodStart ? 'ajuste_periodo_anterior' : 'erp_periodo_posterior';
                    $c['reason'] .= $outKind === 'ajuste_periodo_anterior'
                        ? ' Ajuste de periodo anterior: el gasto ERP de este folio se reconoció en un periodo previo; no se vuelve a reconocer en automático.'
                        : ' El folio ERP pertenece a un periodo posterior; no se adelanta su reconocimiento en automático.';
                }
                $captures = array_filter(array_column($linkedErp, 'fecha_captura'));
                $payments = array_filter(array_column($linkedErp, 'fecha_pago'));

                $applied = $conciliated ? (string) ($r['applied'] ?? '0.00') : '0.00';
                $universes = array_unique(array_column($linkedErp, 'universe'));
                $hint = match (true) {
                    $noiEmployee !== null => 'noi',
                    $linkedErp !== [] => $universes === ['radiografia'] ? 'radiografia' : 'erp',
                    $c['treatment'] === Treatment::CUBIERTO_RADIOGRAFIA => 'radiografia',
                    default => null,
                };
                $upload = $uploads[$m->upload_id] ?? null;
                $dims = Dimensions::derive([
                    'source' => 'banco',
                    'in_period' => (bool) $m->in_period,
                    'treatment' => $c['treatment'],
                    'reconciliation_status' => $recStatus,
                    'applied' => $applied,
                    'has_override' => false,
                    'recognizing_hint' => $hint,
                    'import' => $upload?->control_ok === true ? 'importado' : ($upload?->control_ok === false ? 'control_no_cuadra' : 'sin_control'),
                ]);

                $m->forceFill([
                    'reconciliation_status' => $recStatus,
                    'reconciliation_detail' => $r,
                    'auto_treatment' => $c['treatment'],
                    'suggested_treatment' => $c['suggested'],
                    'rule_code' => $c['rule'],
                    'reason' => $c['reason'],
                    'needs_review' => $c['review'],
                    'transfer_pair_key' => $pairs[$m->id] ?? null,
                    'processed_at' => $now,
                    'import_status' => $dims['import'],
                    'reconciliation_state' => $dims['reconciliation'],
                    'match_type' => $conciliated ? ($r['match_type'] ?? R::MATCH_TYPES[$r['status']] ?? null) : null,
                    'match_group' => $conciliated ? ($r['group_key'] ?? null) : null,
                    'applied_amount' => $applied,
                    'pending_amount' => (float) $m->cargo > 0 && $m->in_period ? Money::str2(Money::of($m->cargo)->minus(Money::of($applied))) : '0.00',
                    'financial_category' => $dims['category'],
                    'accounting_treatment' => $dims['accounting'],
                    'review_status' => $dims['review'],
                    'inclusion_status' => $dims['inclusion'],
                    'recognizing_source' => $dims['source'],
                    'recognition_period_id' => $m->in_period ? $period->id : $this->periodForDate($m->movement_date->format('Y-m-d')),
                    'recognition_basis' => 'fecha_bancaria',
                    'out_of_period_kind' => $outKind,
                    'operation_date' => $m->movement_date->format('Y-m-d'),
                    'erp_capture_date' => $captures !== [] ? min($captures) : null,
                    'erp_payment_date' => $payments !== [] ? min($payments) : null,
                    'accrual_date' => null, // ninguna fuente entrega fecha de devengo
                    'reversal_pair_key' => $reversals[$fp]['key'] ?? null,
                    'refund_target_fingerprint' => $refund['target']['fingerprint'] ?? null,
                    'refund_status' => $refund['status'] ?? null,
                    'noi_employee_id' => $noiEmployee['employee_id'] ?? null,
                    'dimensions_version' => Dimensions::VERSION,
                ])->save();

                // ── Vínculos con evidencia ─────────────────────────────────
                foreach ($allocByMovement[$fp] ?? [] as $a) {
                    $erp = $erpByFp[$a['erp']];
                    $links[] = $this->link($period, $run, $fp, 'erp', $a['erp'], 'conciliacion', $a['match_type'], $a['group_key'], $a['applied'], (string) $m->cargo,
                        $erp['amount'], (string) ($r['pending'] ?? '0.00'), $rec['erp_balances'][$a['erp']]['pending'] ?? '0.00', $a['delta_days'], [
                            'status' => $r['status'], 'folio' => $erp['folio'], 'erp_date' => $erp['date'], 'erp_date_source' => $erp['date_source'],
                            'erp_universe' => $erp['universe'], 'erp_category' => $erp['category'], 'erp_concept' => $erp['concept'],
                        ]);
                }
                if ($noiEmployee !== null) {
                    $links[] = $this->link($period, $run, $fp, 'noi', (string) $noiEmployee['employee_id'], 'evidencia_noi', null, null, (string) $m->cargo, (string) $m->cargo,
                        $noiEmployee['total'], '0.00', '0.00', null, ['employee' => $noiEmployee['name'], 'branch' => $noiEmployee['branch'], 'operative' => $noiEmployee['operative'], 'beneficiary' => $m->beneficiary]);
                }
                if (isset($reversals[$fp])) {
                    $amount = (float) $m->cargo > 0 ? (string) $m->cargo : (string) $m->abono;
                    $links[] = $this->link($period, $run, $fp, 'banco', $reversals[$fp]['counterpart'], 'reverso', null, $reversals[$fp]['key'], $amount, $amount, $amount, '0.00', '0.00', null, ['evidence' => $reversals[$fp]['evidence']]);
                }
                if ($refund !== null && $refund['target'] !== null) {
                    $t = $refund['target'];
                    $links[] = $this->link($period, $run, $fp, $t['source'], $t['fingerprint'], 'reembolso', null, null, (string) $m->abono, (string) $m->abono, $t['amount'], '0.00',
                        Money::str2(Money::of($t['amount'])->minus(Money::of($m->abono))), null, ['status' => $refund['status'], 'folio' => $m->folio_raw]);
                }
                if (isset($pairs[$m->id])) {
                    foreach ($pairMembers[$pairs[$m->id]] as $otherId) {
                        if ($otherId !== $m->id) {
                            $other = $byId[$otherId];
                            $amount = (float) $m->cargo > 0 ? (string) $m->cargo : (string) $m->abono;
                            $links[] = $this->link($period, $run, $fp, 'banco', $other->fingerprint, 'traspaso', null, $pairs[$m->id], $amount, $amount, $amount, '0.00', '0.00', null, ['counter_account' => $other->account_slot]);
                        }
                    }
                }

                $counts['por_tratamiento'][$c['treatment']] = ($counts['por_tratamiento'][$c['treatment']] ?? 0) + 1;
                $counts['por_conciliacion'][$recStatus] = ($counts['por_conciliacion'][$recStatus] ?? 0) + 1;
                $counts['por_inclusion'][$dims['inclusion']] = ($counts['por_inclusion'][$dims['inclusion']] ?? 0) + 1;
                if ($conciliated) {
                    $type = $r['match_type'] ?? R::MATCH_TYPES[$r['status']];
                    $counts['por_tipo_emparejamiento'][$type] = ($counts['por_tipo_emparejamiento'][$type] ?? 0) + 1;
                }
            }

            // Vínculos: se regeneran COMPLETOS (idempotente, sin duplicados).
            EstadoResultadosReconciliationLink::query()->where('period_id', $period->id)->delete();
            foreach (array_chunk($links, 200) as $chunk) {
                EstadoResultadosReconciliationLink::query()->insert($chunk);
            }

            EstadoResultadosUpload::query()->active()->whereIn('id', $movements->pluck('upload_id')->unique()->all())
                ->update(['status' => EstadoResultadosUpload::STATUS_PROCESSED]);
        });

        foreach ($counts as &$group) {
            ksort($group);
        }
        unset($group);

        $erpSignature = ErpExpenseRepository::signature($erpRows);
        $input = $movements->map(fn ($m) => implode('|', [$m->fingerprint, Money::str2($m->cargo), Money::str2($m->abono), (int) $m->in_period]))->sort()->values()->all();

        return [
            'erp_signature' => $erpSignature,
            'input_signature' => hash('sha256', implode(',', $input).'#'.$erpSignature.'#'.config('estado_resultados.rules_version').'#'.config('estado_resultados.reconocimiento.version')),
            'summary' => [
                'movimientos' => $movements->count(),
                'erp_filas' => count($erpRows),
                'erp_vinculadas' => count($rec['erp_links']),
                'tolerancia_dias' => $reconciler->toleranceDays(),
                'pares_traspaso' => count(array_unique($pairs)),
                'pares_reverso' => count(array_unique(array_column($reversals, 'key'))),
                'vinculos' => count($links),
                'noi' => ['colaboradores' => $noi['employees_count'], 'total' => $noi['total'], 'sin_sucursal_operativa' => $noi['unassigned_total']],
                'criterio_reconocimiento' => (string) config('estado_resultados.reconocimiento.version'),
            ] + $counts,
        ];
    }

    /** ¿El resultado de clasificación corresponde a un pago de nómina? */
    private function isPayroll(array $c): bool
    {
        return $c['treatment'] === Treatment::NOMINA_CORPORATIVA
            || ($c['treatment'] === Treatment::CUBIERTO_RADIOGRAFIA && $c['rule'] === 'nomina_sucursal_noi')
            || in_array($c['suggested'], [Treatment::NOMINA_SUCURSAL, Treatment::NOMINA_CORPORATIVA], true);
    }

    /**
     * Nómina NOI: el colaborador ya está reconocido en Nómina sucursales (Radiografía suma TODO el
     * NOI al GLOBAL). Si el beneficiario es un colaborador NOI (llave de nombre exacta), el pago
     * bancario es sólo EVIDENCIA de pago: fuente que reconoce = NOI, no se crea otro gasto.
     *
     * @return array{0:array, 1:?array}
     */
    private function applyNoiEvidence(array $c, EstadoResultadosMovement $m, array $noi): array
    {
        $match = NoiPayrollRepository::match($noi, $m->beneficiary);
        if ($match['status'] === 'unico') {
            $e = $match['employee'];
            $where = $e['operative'] ? 'sucursal '.$e['branch'] : 'sin sucursal operativa'.($e['branch'] ? ' ('.$e['branch'].')' : '');

            return [$this->out(Treatment::CUBIERTO_RADIOGRAFIA, null, 'nomina_noi_beneficiario',
                "Pago a {$e['name']}, colaborador NOI del periodo ({$where}): su nómina ya está en Nómina y Capital Humano (NOI vía Radiografía). El banco es evidencia de pago; no se crea un segundo gasto."), $e];
        }
        if ($match['status'] === 'ambiguo') {
            return [$this->out(Treatment::PENDIENTE, $c['suggested'] ?? $c['treatment'], 'nomina_noi_ambigua',
                'El beneficiario coincide con más de un colaborador NOI del mismo nombre: no se decide en automático si ya está en Nómina sucursales.', true), null];
        }
        if ($c['treatment'] === Treatment::NOMINA_CORPORATIVA && ! Money::isZeroCents($noi['unassigned_total'])) {
            return [$this->out(Treatment::PENDIENTE, Treatment::NOMINA_CORPORATIVA, 'nomina_corporativa_noi_sin_sucursal',
                'El NOI del periodo incluye $'.number_format((float) $noi['unassigned_total'], 2).' de '.$noi['unassigned_employees'].' colaborador(es) sin sucursal operativa (p. ej. Corporativo) que Radiografía ya suma en Nómina sucursales. Confirme que este pago no es esa misma nómina para no contarla dos veces.', true), null];
        }

        return [$c, null];
    }

    private function applyRefund(array $c, array $refund): array
    {
        return match ($refund['status']) {
            'vinculado' => $this->out(Treatment::PENDIENTE, null, 'reembolso_vinculado',
                'Reembolso vinculado por folio con un gasto reconocido ('.($refund['target']['source'] === 'erp' ? 'ERP' : 'banco').'): reduce la MISMA línea de ese gasto. No es ingreso.'),
            'radiografia' => $this->out(Treatment::PENDIENTE, Treatment::OPEX_SUCURSAL, 'reembolso_gasto_radiografia',
                'Reembolso de un gasto de sucursal que ya está en Radiografía: reducirlo cambiaría el nivel 1, por eso requiere clasificación explícita. No es ingreso.', true),
            'ambiguo' => $this->out(Treatment::PENDIENTE, Treatment::RECUPERABLE, 'reembolso_ambiguo',
                'El folio del reembolso coincide con '.count($refund['candidates']).' gastos: no se elige en automático cuál reduce. No es ingreso.', true),
            default => $c,
        };
    }

    private function link(Period $period, EstadoResultadosRun $run, string $mfp, string $targetType, string $target, string $kind, ?string $match, ?string $group, string $applied, string $movementAmount, ?string $targetAmount, string $movementPending, string $targetPending, ?int $delta, array $evidence): array
    {
        $now = now();

        return [
            'period_id' => $period->id, 'run_id' => $run->id, 'movement_fingerprint' => $mfp,
            'target_type' => $targetType, 'target_fingerprint' => $target, 'link_kind' => $kind,
            'match_type' => $match, 'group_key' => $group,
            'applied_amount' => Money::str2($applied), 'movement_amount' => Money::str2($movementAmount),
            'target_amount' => $targetAmount !== null ? Money::str2($targetAmount) : null,
            'movement_pending' => Money::str2($movementPending), 'target_pending' => Money::str2($targetPending),
            'delta_days' => $delta, 'evidence' => json_encode($evidence, JSON_UNESCAPED_UNICODE),
            'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private array $periodCache = [];

    /** Periodo MENSUAL cuyo rango contiene la fecha (para movimientos fuera del periodo); null si no existe. */
    private function periodForDate(string $date): ?int
    {
        if (! array_key_exists($date, $this->periodCache)) {
            $this->periodCache[$date] = Period::query()->where('type', 'monthly')
                ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)
                ->orderBy('id')->value('id');
        }

        return $this->periodCache[$date];
    }

    private function out(string $treatment, ?string $suggested, string $rule, string $reason, bool $review = false): array
    {
        return ['treatment' => $treatment, 'suggested' => $suggested, 'rule' => $rule, 'reason' => $reason, 'review' => $review];
    }

    private function rowInput(EstadoResultadosMovement $m): array
    {
        return [
            'fingerprint' => $m->fingerprint, 'account_slot' => $m->account_slot, 'cargo' => (string) $m->cargo, 'abono' => (string) $m->abono,
            'movement_date' => $m->movement_date->format('Y-m-d'), 'source_row' => (int) $m->source_row,
            'folio_normalized' => $m->folio_normalized, 'concept' => $m->concept, 'bank_reference' => $m->bank_reference,
        ];
    }

    private function movementInput(EstadoResultadosMovement $m): array
    {
        return [
            'fingerprint' => $m->fingerprint,
            'folio_normalized' => $m->folio_normalized,
            'cargo' => (string) $m->cargo,
            'movement_date' => $m->movement_date->format('Y-m-d'),
            'branch_name' => $m->branch_name,
            'branch_kind' => $m->branch_kind,
            'uuid' => $m->uuid,
            'source_row' => (int) $m->source_row,
            'account_slot' => $m->account_slot,
        ];
    }

    /**
     * Empareja traspasos: salida (cargo) de una cuenta ↔ entrada (abono) en la OTRA cuenta,
     * mismo importe exacto y fecha con diferencia ≤ 1 día. Determinista (orden fijo).
     *
     * @return array<int, string> movement id => clave del par
     */
    private function pairTransfers($movements): array
    {
        $isTransfer = function (EstadoResultadosMovement $m): bool {
            $txt = mb_strtoupper(($m->concept ?? '').' '.($m->bank_reference ?? ''));

            return str_contains($txt, 'TRASPASO');
        };
        $outs = $movements->filter(fn ($m) => $m->in_period && (float) $m->cargo > 0 && $isTransfer($m))->values();
        $ins = $movements->filter(fn ($m) => $m->in_period && (float) $m->abono > 0 && $isTransfer($m))->values();

        $pairs = [];
        $used = [];
        $n = 0;
        foreach ($outs as $out) {
            foreach ($ins as $in) {
                if (isset($used[$in->id]) || $in->account_slot === $out->account_slot) {
                    continue;
                }
                if (Money::cents($in->abono) !== Money::cents($out->cargo)) {
                    continue;
                }
                if (abs($in->movement_date->diffInDays($out->movement_date)) > 1) {
                    continue;
                }
                $n++;
                $key = 'TR-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
                $pairs[$out->id] = $key;
                $pairs[$in->id] = $key;
                $used[$in->id] = true;
                break;
            }
        }

        return $pairs;
    }
}
