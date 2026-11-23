<?php

namespace App\Services\EstadoResultados\FinancialExpenses;

use App\Models\EstadoResultados\EstadoResultadosAmortizationRow;
use App\Models\EstadoResultados\EstadoResultadosAmortizationUpload;
use App\Models\EstadoResultados\EstadoResultadosCredit;
use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\EstadoResultados\EstadoResultadosUpload;
use App\Models\EstadoResultados\EstadoResultadosVersionSnapshot;
use App\Models\Period;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\Reporting\PeriodLockGuard;
use App\Services\EstadoResultados\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * GASTOS FINANCIEROS (entre EBIT y EBT) a partir de las tablas de amortización.
 *
 * Regla: de cada pago de crédito (LEROM semanal, BBVA mensual) SÓLO el INTERÉS es gasto
 * financiero del ER; el capital es flujo de financiamiento y nunca entra. El pago bancario
 * completo sigue clasificado como CAPITAL_DEUDA (fuera del ER) y aquí sólo sirve de
 * EVIDENCIA (pagos esperados según la tabla vs. pagos encontrados en el banco).
 *
 * Interés del periodo de un crédito = Σ interés de los renglones de su tabla VIGENTE cuya
 * fecha de pago cae en period.start_date..end_date (periodos de 4 semanas ⇒ LEROM ~4 pagos).
 *
 * Alimenta el concepto de ajuste del crédito (intereses_lerom / intereses_bbva / otros_intereses)
 * SÓLO en el CONSOLIDADO (los ajustes financieros nunca se asignan a una sucursal):
 *   - captura manual (capturado / confirmado $0)  ⇒ GANA la captura; se informa la diferencia
 *     contra la tabla;
 *   - sin captura y la tabla cubre el periodo     ⇒ estado «capturado» con origen
 *     «tabla_amortizacion» (importe = interés de la tabla, puede ser $0.00 si no hubo pagos);
 *   - sin captura y sin tabla (o la tabla no cubre el periodo) ⇒ sigue «sin información».
 */
class FinancialExpenseService
{
    /** Alcance donde se aplican los intereses (los créditos son de la empresa, no de sucursal). */
    public const CONSOLIDADO = 'CONSOLIDADO';

    /** Origen del ajuste cuando lo alimenta la tabla de amortización. */
    public const ORIGIN_TABLE = 'tabla_amortizacion';

    /** Origen del ajuste capturado a mano en «Ajustes». */
    public const ORIGIN_MANUAL = 'manual';

    /** Memo por proceso de la existencia de la tabla de renglones. */
    private static ?bool $available = null;

    /**
     * ¿Existe la tabla de renglones de amortización? (en pruebas no se memoriza).
     */
    public static function available(): bool
    {
        if (app()->runningUnitTests()) {
            return Schema::hasTable('estado_resultados_amortization_rows');
        }

        return self::$available ??= Schema::hasTable('estado_resultados_amortization_rows');
    }

    /**
     * Créditos activos con su carga vigente (y quién la subió).
     *
     * @return Collection<int, EstadoResultadosCredit>
     */
    public function credits(): Collection
    {
        if (! self::available()) {
            return collect();
        }

        return EstadoResultadosCredit::query()->where('active', true)->orderBy('id')
            ->with(['currentUpload.uploader:id,name'])->get();
    }

    /**
     * Cálculo de un crédito en el periodo (sin evidencia bancaria).
     *
     * @return array<string,mixed>
     */
    public function creditPeriod(EstadoResultadosCredit $credit, Period $period): array
    {
        $upload = $credit->currentUpload;
        $start = $period->start_date->format('Y-m-d');
        $end = $period->end_date->format('Y-m-d');
        $rows = $upload
            ? EstadoResultadosAmortizationRow::query()->where('upload_id', $upload->id)
                ->whereBetween('payment_date', [$start, $end])->orderBy('payment_date')->orderBy('id')->get()
            : collect();
        $covered = $upload !== null && $upload->first_payment_date && $upload->last_payment_date
            && $upload->first_payment_date->format('Y-m-d') <= $end && $upload->last_payment_date->format('Y-m-d') >= $start;

        $sum = fn (string $col) => Money::str2(Money::sum($rows->pluck($col)->map(fn ($v) => $v ?? 0)->all()));

        return [
            'credit' => [
                'id' => $credit->id, 'key' => $credit->key, 'name' => $credit->name, 'creditor' => $credit->creditor,
                'periodicity' => $credit->periodicity, 'concept' => $credit->adjustment_concept,
                'concept_label' => AdjustmentService::CONCEPTS[$credit->adjustment_concept]['label'] ?? $credit->adjustment_concept,
            ],
            'upload' => $upload ? self::uploadPayload($upload) : null,
            'has_table' => $upload !== null,
            'covered' => $covered,
            'rows' => $rows->map(fn (EstadoResultadosAmortizationRow $r) => [
                'id' => $r->id, 'number' => $r->payment_number, 'date' => $r->payment_date->format('Y-m-d'),
                'payment' => Money::str2($r->payment_total ?? 0), 'principal' => Money::str2($r->principal),
                'interest' => Money::str2($r->interest), 'interest_vat' => $r->interest_vat === null ? null : Money::str2($r->interest_vat),
                'balance' => $r->balance === null ? null : Money::str2($r->balance), 'origin' => $r->origin, 'source_row' => $r->source_row,
            ])->values()->all(),
            'totals' => [
                'count' => $rows->count(),
                'payment' => $sum('payment_total'),
                'principal' => $sum('principal'),
                'interest' => $sum('interest'),
                'interest_vat' => $sum('interest_vat'),
            ],
            'first_date' => $rows->first()?->payment_date?->format('Y-m-d'),
            'last_date' => $rows->last()?->payment_date?->format('Y-m-d'),
        ];
    }

    /**
     * Superpone las tablas de amortización sobre los ajustes del CONSOLIDADO (lo llama
     * AdjustmentService::forPeriod). En sucursales no hace nada.
     *
     * @param  array<string, array<string,mixed>>  $adjustments
     * @return array<string, array<string,mixed>>
     */
    public function overlay(int $periodId, string $scopeKey, array $adjustments): array
    {
        if ($scopeKey !== self::CONSOLIDADO || ! self::available()) {
            return $adjustments;
        }
        $credits = $this->credits();
        if ($credits->isEmpty()) {
            return $adjustments;
        }
        $period = Period::query()->find($periodId);
        if (! $period || ! $period->start_date || ! $period->end_date) {
            return $adjustments;
        }

        foreach ($credits->groupBy('adjustment_concept') as $concept => $group) {
            if (! isset($adjustments[$concept])) {
                continue;
            }
            $calcs = $group->map(fn (EstadoResultadosCredit $c) => $this->creditPeriod($c, $period))->values();
            $withTable = $calcs->where('has_table', true);
            if ($withTable->isEmpty()) {
                continue;
            }
            $covered = $withTable->where('covered', true);
            $interest = Money::sum($covered->pluck('totals.interest')->all());
            $info = [
                'credits' => $withTable->map(fn ($c) => [
                    'key' => $c['credit']['key'], 'name' => $c['credit']['name'], 'covered' => $c['covered'],
                    'file' => $c['upload']['name'], 'uploaded_at' => $c['upload']['uploaded_at'],
                    'payments' => $c['totals']['count'], 'first_date' => $c['first_date'], 'last_date' => $c['last_date'],
                    'payment' => $c['totals']['payment'], 'principal_excluded' => $c['totals']['principal'],
                    'interest' => $c['totals']['interest'], 'interest_vat' => $c['totals']['interest_vat'],
                ])->values()->all(),
                'covered' => $covered->isNotEmpty(),
                'payments' => (int) $covered->sum('totals.count'),
                'payment' => Money::str2(Money::sum($covered->pluck('totals.payment')->all())),
                'principal_excluded' => Money::str2(Money::sum($covered->pluck('totals.principal')->all())),
                'interest' => Money::str2($interest),
                'difference' => null,
                'applied' => false,
            ];

            $adj = $adjustments[$concept];
            if (($adj['state'] ?? AdjustmentService::SIN_INFORMACION) !== AdjustmentService::SIN_INFORMACION) {
                // La captura manual gana; se muestra la diferencia contra la tabla.
                if ($info['covered']) {
                    $manual = $adj['state'] === AdjustmentService::CAPTURADO ? Money::of($adj['amount']) : BigDecimal::zero();
                    $info['difference'] = Money::str2($manual->minus($interest));
                }
                $adjustments[$concept]['origin'] = self::ORIGIN_MANUAL;
                $adjustments[$concept]['amortization'] = $info;

                continue;
            }
            if (! $info['covered']) {
                $adjustments[$concept]['amortization'] = $info; // la tabla no cubre el periodo ⇒ sigue sin información

                continue;
            }
            $info['applied'] = true;
            $first = $covered->pluck('first_date')->filter()->min();
            $last = $covered->pluck('last_date')->filter()->max();
            $fmt = fn (?string $d) => $d ? CarbonImmutable::parse($d)->format('d/m/Y') : '';
            $uploads = $covered->pluck('upload');
            $adjustments[$concept] = array_replace($adj, [
                'state' => AdjustmentService::CAPTURADO,
                'amount' => Money::str2($interest),
                'origin' => self::ORIGIN_TABLE,
                'notes' => $info['payments'] > 0
                    ? 'Tabla de amortización: '.$info['payments'].' pago(s) del '.$fmt($first).' al '.$fmt($last).' · interés $'.number_format((float) $info['interest'], 2).' · capital excluido $'.number_format((float) $info['principal_excluded'], 2)
                    : 'Tabla de amortización: sin pagos con fecha dentro del periodo (interés $0.00).',
                'updated_by' => $uploads->pluck('uploaded_by')->filter()->implode(', ') ?: null,
                'updated_at' => $uploads->pluck('uploaded_at')->filter()->max(),
                'amortization' => $info,
            ]);
        }

        return $adjustments;
    }

    /**
     * Evidencia bancaria: pagos esperados según la tabla vs. pagos «CREDITO LEROM/BBVA» del
     * banco en el periodo. NO cambia su tratamiento (CAPITAL_DEUDA, fuera del ER).
     *
     * @param  array<string,mixed>  $calc  creditPeriod()
     * @return array<string,mixed>
     */
    public function bankEvidence(EstadoResultadosCredit $credit, Period $period, array $calc): array
    {
        $tolDays = max(0, (int) config('estado_resultados.gastos_financieros.tolerancia_dias_banco', 3));
        $tolAmount = Money::of(config('estado_resultados.gastos_financieros.tolerancia_importe_banco', '1.00'));
        $pattern = $credit->bank_pattern ? '/'.str_replace('/', '\/', $credit->bank_pattern).'/iu' : null;

        $movements = collect();
        if ($pattern !== null && @preg_match($pattern, '') !== false) {
            $movements = EstadoResultadosMovement::query()
                ->where('period_id', $period->id)
                ->where('in_period', true)
                ->whereIn('upload_id', EstadoResultadosUpload::query()->active()->select('id'))
                ->where('cargo', '>', 0)
                ->orderBy('movement_date')->orderBy('id')
                ->get(['id', 'fingerprint', 'movement_date', 'cargo', 'concept', 'account_slot', 'auto_treatment'])
                ->filter(fn ($m) => preg_match($pattern, (string) $m->concept) === 1 && ! preg_match('/DEPOSITO/iu', (string) $m->concept))
                ->values();
        }

        $free = $movements->keyBy('id')->all();
        $expected = [];
        foreach ($calc['rows'] as $row) {
            $date = CarbonImmutable::parse($row['date']);
            $best = null;
            foreach ($free as $id => $m) {
                $diff = abs($date->diffInDays(CarbonImmutable::parse($m->movement_date->format('Y-m-d')), false));
                if ($diff > $tolDays || Money::of($m->cargo)->minus(Money::of($row['payment']))->abs()->isGreaterThan($tolAmount)) {
                    continue;
                }
                if ($best === null || $diff < $best[1]) {
                    $best = [$id, $diff];
                }
            }
            $match = null;
            if ($best !== null) {
                $m = $free[$best[0]];
                unset($free[$best[0]]);
                $match = ['fingerprint' => $m->fingerprint, 'date' => $m->movement_date->format('Y-m-d'), 'amount' => Money::str2($m->cargo),
                    'concept' => $m->concept, 'days' => (int) $best[1], 'difference' => Money::str2(Money::of($m->cargo)->minus(Money::of($row['payment'])))];
            }
            $expected[] = ['row_id' => $row['id'], 'number' => $row['number'], 'date' => $row['date'], 'payment' => $row['payment'],
                'status' => $match ? 'encontrado' : 'no_encontrado', 'match' => $match];
        }
        $unmatched = array_values(array_map(fn ($m) => [
            'fingerprint' => $m->fingerprint, 'date' => $m->movement_date->format('Y-m-d'), 'amount' => Money::str2($m->cargo), 'concept' => $m->concept,
        ], $free));

        $expectedTotal = Money::of($calc['totals']['payment']);
        $foundTotal = Money::sum($movements->pluck('cargo')->all());

        return [
            'pattern' => $credit->bank_pattern,
            'tolerance_days' => $tolDays,
            'tolerance_amount' => Money::str2($tolAmount),
            'expected' => $expected,
            'unmatched_bank' => $unmatched,
            'totals' => [
                'expected_count' => count($expected),
                'found_count' => $movements->count(),
                'matched_count' => count(array_filter($expected, fn ($e) => $e['status'] === 'encontrado')),
                'expected' => Money::str2($expectedTotal),
                'found' => Money::str2($foundTotal),
                'difference' => Money::str2($foundTotal->minus($expectedTotal)),
            ],
        ];
    }

    /**
     * Todo lo que necesita la pestaña «Gastos financieros» del periodo (consolidado).
     *
     * @return array<string,mixed>
     */
    public function panel(Period $period): array
    {
        $adjustments = app(AdjustmentService::class)->forPeriod($period->id, self::CONSOLIDADO);
        $locked = app(PeriodLockGuard::class)->lockedVersion($period);
        $frozen = $locked ? $this->frozenAdjustments($locked->id) : null;

        $credits = [];
        $totals = ['payment' => BigDecimal::zero(), 'principal' => BigDecimal::zero(), 'interest' => BigDecimal::zero(), 'interest_vat' => BigDecimal::zero()];
        foreach ($this->credits() as $credit) {
            $calc = $this->creditPeriod($credit, $period);
            $calc['bank'] = $this->bankEvidence($credit, $period, $calc);
            $adj = $adjustments[$credit->adjustment_concept] ?? null;
            $calc['adjustment'] = $adj ? [
                'key' => $adj['key'], 'label' => $adj['label'], 'state' => $adj['state'], 'amount' => $adj['amount'],
                'origin' => $adj['origin'] ?? null, 'difference' => $adj['amortization']['difference'] ?? null,
                'updated_by' => $adj['updated_by'] ?? null, 'notes' => $adj['notes'] ?? null,
            ] : null;
            if ($frozen !== null) {
                $f = $frozen[$credit->adjustment_concept] ?? null;
                $approved = $f && $f['state'] !== AdjustmentService::SIN_INFORMACION ? Money::str2($f['amount'] ?? 0) : null;
                $live = $adj && $adj['state'] !== AdjustmentService::SIN_INFORMACION ? Money::str2($adj['amount'] ?? 0) : null;
                $calc['frozen'] = [
                    'version' => $locked->version,
                    'state' => $f['state'] ?? null,
                    'amount' => $approved,
                    'live_amount' => $live,
                    'outdated' => $approved !== $live,
                ];
            }
            $calc['history'] = $credit->uploads()->with('uploader:id,name')->orderByDesc('id')->limit(10)->get()
                ->map(fn ($u) => self::uploadPayload($u))->all();
            if ($calc['covered']) {
                foreach (['payment' => 'payment', 'principal' => 'principal', 'interest' => 'interest', 'interest_vat' => 'interest_vat'] as $k => $t) {
                    $totals[$k] = $totals[$k]->plus(Money::of($calc['totals'][$t]));
                }
            }
            $credits[] = $calc;
        }

        return [
            'period' => ['id' => $period->id, 'label' => $period->label, 'start' => $period->start_date->format('Y-m-d'), 'end' => $period->end_date->format('Y-m-d')],
            'locked' => $locked ? ['version' => $locked->version] : null,
            'credits' => $credits,
            'totals' => array_map(fn ($v) => Money::str2($v), $totals),
            'rules' => [
                'extensions' => AmortizationUploadService::EXTENSIONS,
                'accept' => '.'.implode(',.', AmortizationUploadService::EXTENSIONS),
                'columns' => AmortizationColumnMap::TEMPLATE_HEADERS,
                'tolerance' => AmortizationScheduleParser::tolerance(),
            ],
        ];
    }

    /**
     * Componente de la firma de insumos (ApprovalInputSignature / caché de comparativas):
     * tablas vigentes + renglones del periodo. null si no hay ninguna tabla cargada (las
     * firmas de periodos sin tablas no cambian).
     */
    public function signature(Period $period): ?array
    {
        if (! self::available() || ! EstadoResultadosAmortizationUpload::query()->exists()) {
            return null;
        }
        $current = EstadoResultadosAmortizationUpload::query()->where('status', EstadoResultadosAmortizationUpload::VIGENTE)
            ->orderBy('credit_id')->orderBy('id')->get(['id', 'credit_id', 'file_hash', 'updated_at']);
        $rows = EstadoResultadosAmortizationRow::query()->whereIn('upload_id', $current->pluck('id')->all() ?: [0])
            ->whereBetween('payment_date', [$period->start_date->format('Y-m-d'), $period->end_date->format('Y-m-d')])
            ->selectRaw('COUNT(*) AS n, MAX(id) AS m, SUM(interest) AS i, SUM(principal) AS c')->first();

        return [
            'uploads' => $current->map(fn ($u) => [$u->id, $u->credit_id, $u->file_hash, (string) $u->updated_at])->all(),
            'rows' => array_map(fn ($v) => $v === null ? null : (string) $v, array_values((array) $rows?->getAttributes())),
            'credits' => EstadoResultadosCredit::query()->orderBy('id')->get(['id', 'adjustment_concept', 'active', 'updated_at'])
                ->map(fn ($c) => [$c->id, $c->adjustment_concept, (int) $c->active, (string) $c->updated_at])->all(),
        ];
    }

    /**
     * Ajustes congelados en el snapshot CONSOLIDADO de una versión (para mostrar lo que quedó
     * aprobado). null si no hay snapshot o no pasa la verificación de hash.
     *
     * @param  int  $versionId  versión aprobada
     * @return array<string, array{state:string, amount:?string}>|null
     */
    private function frozenAdjustments(int $versionId): ?array
    {
        $snapshot = EstadoResultadosVersionSnapshot::query()->where('version_id', $versionId)->where('scope_key', self::CONSOLIDADO)->first();
        if (! $snapshot) {
            return null;
        }
        try {
            $dataset = $snapshot->datasetVerified();
        } catch (\Throwable) {
            return null;
        }
        $out = [];
        foreach ((array) ($dataset['adjustments'] ?? []) as $a) {
            $out[(string) ($a['key'] ?? '')] = ['state' => (string) ($a['state'] ?? ''), 'amount' => $a['amount'] ?? null];
        }

        return $out;
    }

    /**
     * Resumen público de una carga de tabla de amortización (totales, rango de fechas, avisos).
     *
     * @param  EstadoResultadosAmortizationUpload  $u  carga
     * @return array<string,mixed>
     */
    public static function uploadPayload(EstadoResultadosAmortizationUpload $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->original_name,
            'hash' => substr((string) $u->file_hash, 0, 12),
            'status' => $u->status,
            'rows' => $u->rows_count,
            'first_date' => $u->first_payment_date?->format('Y-m-d'),
            'last_date' => $u->last_payment_date?->format('Y-m-d'),
            'total_payment' => Money::str2($u->total_payment),
            'total_principal' => Money::str2($u->total_principal),
            'total_interest' => Money::str2($u->total_interest),
            'columns' => (array) ($u->column_map['columns'] ?? []),
            'warnings' => array_values(array_map(fn ($w) => is_array($w) ? (string) ($w['message'] ?? '') : (string) $w, (array) $u->warnings)),
            'uploaded_by' => $u->uploader?->name,
            'uploaded_at' => $u->created_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
            'superseded_at' => $u->superseded_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
        ];
    }
}
