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
use App\Services\EstadoResultados\Classification\ClassificationService;
use App\Services\EstadoResultados\Classification\ConceptCatalogService;
use App\Services\EstadoResultados\Classification\MovementClassifier;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use App\Services\EstadoResultados\Reporting\PeriodLockGuard;
use App\Services\EstadoResultados\Support\Money;
use App\Services\EstadoResultados\Support\Treatment as T;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * GASTOS FINANCIEROS (entre EBIT y EBT) a partir de las tablas de amortización.
 *
 * Regla: de cada pago de crédito (LEROM diario en días hábiles, BBVA mensual) SÓLO el INTERÉS
 * y la COMISIÓN financiera (LEROM: comisión por apertura prorrateada, concepto
 * comisiones_financieras; criterio pendiente de confirmar por Finanzas, ver config
 * `gastos_financieros.comisiones_en_resultado_financiero`) son gasto financiero del ER; el
 * capital es flujo de financiamiento y nunca entra; el IVA siempre va aparte. El pago bancario
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
 *
 * Además (panel()/allocation()): aportaciones de sucursales (abonos «DEPOSITO LEROM»), pagos al
 * acreedor conciliados renglón por renglón, estado derivado del crédito y prorrateo GERENCIAL
 * del costo financiero por sucursal (analítico; nunca modifica el ER ni Radiografía).
 */
class FinancialExpenseService
{
    /** Alcance donde se aplican los intereses (los créditos son de la empresa, no de sucursal). */
    public const CONSOLIDADO = 'CONSOLIDADO';

    /** Origen del ajuste cuando lo alimenta la tabla de amortización. */
    public const ORIGIN_TABLE = 'tabla_amortizacion';

    /** Origen del ajuste capturado a mano en «Ajustes». */
    public const ORIGIN_MANUAL = 'manual';

    /** Cobertura de la tabla sobre el periodo (ver creditPeriod()). */
    public const COBERTURA_COMPLETA = 'completo';

    public const COBERTURA_PARCIAL = 'parcial';

    public const SIN_TABLA = 'sin_tabla';

    public const FUERA_CALENDARIO = 'fuera_de_calendario';

    /**
     * rule_code de MovementClassifier para aportaciones de sucursales a un crédito. CONTRATO: los
     * usa el filtro «Aportaciones a créditos» del Control de movimientos; no renombrar.
     */
    public const RULE_APORTACION = 'aportacion_credito';

    public const RULE_APORTACION_SUGERIDA = 'aportacion_credito_sugerida';

    /** Llave del cubo «sin asignar» en el prorrateo (siempre al final del desempate). */
    public const SIN_ASIGNAR = '__sin_asignar__';

    private const SIMULACION = AmortizationUploadService::SIMULACION;

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
     * Créditos para MovementClassifier::useCredits() (una consulta por corrida, nunca por movimiento).
     *
     * @return list<array{key:string, name:string, creditor:?string, bank_pattern:?string, contribution_pattern:?string}>
     */
    public function classifierCredits(): array
    {
        if (! self::available()) {
            return [];
        }
        $hasContribution = Schema::hasColumn('estado_resultados_credits', 'contribution_pattern');

        return EstadoResultadosCredit::query()->where('active', true)->orderBy('id')->get()
            ->map(fn (EstadoResultadosCredit $c) => [
                'key' => (string) $c->key, 'name' => (string) $c->name, 'creditor' => $c->creditor,
                'bank_pattern' => $c->bank_pattern, 'contribution_pattern' => $hasContribution ? $c->contribution_pattern : null,
            ])->values()->all();
    }

    /**
     * Cálculo de un crédito en el periodo (sin evidencia bancaria).
     *
     * Cobertura (`coverage.status`):
     *  - completo: la tabla tiene pagos antes/en el inicio y en/después del fin del periodo.
     *  - parcial: la tabla EMPIEZA o TERMINA dentro del periodo (primer / último pago). Es VÁLIDO
     *    (antes o después no hay pagos programados) y se usa igual que «completo».
     *  - sin_tabla: no hay tabla vigente.
     *  - fuera_de_calendario: el periodo queda antes del primer pago o después del último
     *    («sin pagos programados»; NUNCA significa «liquidado»). No alimenta el ER.
     * `covered` (compatibilidad) = completo | parcial.
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
        $coverage = self::coverage($upload, $start, $end);
        $covered = in_array($coverage['status'], [self::COBERTURA_COMPLETA, self::COBERTURA_PARCIAL], true);

        $sum = fn (string $col) => Money::str2(Money::sum($rows->pluck($col)->map(fn ($v) => $v ?? 0)->all()));

        return [
            'credit' => [
                'id' => $credit->id, 'key' => $credit->key, 'name' => $credit->name, 'creditor' => $credit->creditor,
                'periodicity' => $credit->periodicity, 'concept' => $credit->adjustment_concept,
                'concept_label' => AdjustmentService::CONCEPTS[$credit->adjustment_concept]['label'] ?? $credit->adjustment_concept,
                'commission_concept' => $credit->commission_concept ?? null,
                'commission_concept_label' => $credit->commission_concept ? (AdjustmentService::CONCEPTS[$credit->commission_concept]['label'] ?? $credit->commission_concept) : null,
            ],
            'upload' => $upload ? self::uploadPayload($upload) : null,
            'has_table' => $upload !== null,
            'covered' => $covered,
            'coverage' => $coverage,
            'rows' => $rows->map(fn (EstadoResultadosAmortizationRow $r) => [
                'id' => $r->id, 'number' => $r->payment_number, 'date' => $r->payment_date->format('Y-m-d'),
                'payment' => Money::str2($r->payment_total ?? 0), 'principal' => Money::str2($r->principal),
                'interest' => Money::str2($r->interest), 'interest_vat' => $r->interest_vat === null ? null : Money::str2($r->interest_vat),
                'commission' => $r->commission === null ? null : Money::str2($r->commission),
                'commission_vat' => $r->commission_vat === null ? null : Money::str2($r->commission_vat),
                'balance' => $r->balance === null ? null : Money::str2($r->balance), 'origin' => $r->origin, 'source_row' => $r->source_row,
            ])->values()->all(),
            'totals' => [
                'count' => $rows->count(),
                'payment' => $sum('payment_total'),
                'principal' => $sum('principal'),
                'interest' => $sum('interest'),
                'interest_vat' => $sum('interest_vat'),
                'commission' => $sum('commission'),
                'commission_vat' => $sum('commission_vat'),
            ],
            'first_date' => $rows->first()?->payment_date?->format('Y-m-d'),
            'last_date' => $rows->last()?->payment_date?->format('Y-m-d'),
        ];
    }

    /**
     * Cobertura de la tabla vigente sobre el periodo (ver creditPeriod()).
     *
     * @return array{status:string, position:?string, reason:string}
     */
    public static function coverage(?EstadoResultadosAmortizationUpload $u, string $start, string $end): array
    {
        if (! $u || ! $u->first_payment_date || ! $u->last_payment_date) {
            return ['status' => self::SIN_TABLA, 'position' => null, 'reason' => 'No hay tabla de amortización vigente para este crédito.'];
        }
        $first = $u->first_payment_date->format('Y-m-d');
        $last = $u->last_payment_date->format('Y-m-d');
        $fmt = fn (string $d) => CarbonImmutable::parse($d)->format('d/m/Y');
        if ($last < $start) {
            return ['status' => self::FUERA_CALENDARIO, 'position' => 'despues',
                'reason' => 'Sin pagos programados: el último pago de la tabla fue el '.$fmt($last).'. No significa que el crédito esté liquidado.'];
        }
        if ($first > $end) {
            return ['status' => self::FUERA_CALENDARIO, 'position' => 'antes',
                'reason' => 'Sin pagos programados todavía: el primer pago de la tabla es el '.$fmt($first).'.'];
        }
        if ($first <= $start && $last >= $end) {
            return ['status' => self::COBERTURA_COMPLETA, 'position' => null, 'reason' => 'La tabla cubre todo el periodo.'];
        }
        $why = [];
        if ($first > $start) {
            $why[] = 'la tabla inicia el '.$fmt($first).' (primer pago dentro del periodo)';
        }
        if ($last < $end) {
            $why[] = 'la tabla termina el '.$fmt($last).' (último pago dentro del periodo)';
        }

        return ['status' => self::COBERTURA_PARCIAL, 'position' => null,
            'reason' => ucfirst(implode(' y ', $why)).'. Es válido: fuera de esas fechas no hay pagos programados.'];
    }

    /**
     * Superpone las tablas de amortización sobre los ajustes del CONSOLIDADO (lo llama
     * AdjustmentService::forPeriod). En sucursales no hace nada.
     *
     * Cada crédito alimenta DOS conceptos: el INTERÉS va a `adjustment_concept` y la COMISIÓN a
     * `commission_concept` (si lo tiene). Misma precedencia para ambos: la captura manual gana y
     * se informa la diferencia; sin captura y con tabla que cubre (completo/parcial) ⇒ capturado
     * desde la tabla; sin tabla o fuera de calendario ⇒ sigue «sin información».
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

        $calcs = [];
        foreach (self::feeds($credits) as $concept => $feeds) {
            if (! isset($adjustments[$concept])) {
                continue;
            }
            $items = collect($feeds)->map(function (array $f) use (&$calcs, $period) {
                $calcs[$f['credit']->id] ??= $this->creditPeriod($f['credit'], $period);

                return $calcs[$f['credit']->id] + ['field' => $f['field']];
            });
            $withTable = $items->where('has_table', true);
            if ($withTable->isEmpty()) {
                continue;
            }
            $covered = $withTable->where('covered', true);
            $isCommission = $feeds[0]['field'] === 'commission';
            $amount = Money::sum($covered->map(fn ($c) => $c['totals'][$c['field']])->all());
            $info = [
                'kind' => $isCommission ? 'comision' : 'interes',
                'credits' => $withTable->map(fn ($c) => [
                    'key' => $c['credit']['key'], 'name' => $c['credit']['name'], 'covered' => $c['covered'], 'coverage' => $c['coverage']['status'],
                    'file' => $c['upload']['name'], 'uploaded_at' => $c['upload']['uploaded_at'], 'document_kind' => $c['upload']['document_kind'],
                    'payments' => $c['totals']['count'], 'first_date' => $c['first_date'], 'last_date' => $c['last_date'],
                    'payment' => $c['totals']['payment'], 'principal_excluded' => $c['totals']['principal'],
                    'interest' => $c['totals']['interest'], 'interest_vat' => $c['totals']['interest_vat'],
                    'commission' => $c['totals']['commission'], 'commission_vat' => $c['totals']['commission_vat'],
                ])->values()->all(),
                'covered' => $covered->isNotEmpty(),
                'payments' => (int) $covered->sum('totals.count'),
                'payment' => Money::str2(Money::sum($covered->pluck('totals.payment')->all())),
                'principal_excluded' => Money::str2(Money::sum($covered->pluck('totals.principal')->all())),
                'interest' => Money::str2(Money::sum($covered->pluck('totals.interest')->all())),
                'commission' => Money::str2(Money::sum($covered->pluck('totals.commission')->all())),
                'vat_excluded' => Money::str2(Money::sum($covered->map(fn ($c) => Money::of($c['totals'][$c['field'] === 'commission' ? 'commission_vat' : 'interest_vat']))->all())),
                'amount' => Money::str2($amount),
                'difference' => null,
                'applied' => false,
            ];

            $adj = $adjustments[$concept];
            if (($adj['state'] ?? AdjustmentService::SIN_INFORMACION) !== AdjustmentService::SIN_INFORMACION) {
                // La captura manual gana; se muestra la diferencia contra la tabla.
                if ($info['covered']) {
                    $manual = $adj['state'] === AdjustmentService::CAPTURADO ? Money::of($adj['amount']) : BigDecimal::zero();
                    $info['difference'] = Money::str2($manual->minus($amount));
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
            $money = fn (string $v) => '$'.number_format((float) $v, 2);
            $uploads = $covered->pluck('upload');
            $simulation = $uploads->contains(fn ($u) => ($u['document_kind'] ?? self::SIMULACION) === AmortizationUploadService::SIMULACION);
            $notes = $info['payments'] > 0
                ? 'Tabla de amortización: '.$info['payments'].' pago(s) del '.$fmt($first).' al '.$fmt($last).' · '
                    .($isCommission
                        ? 'comisión '.$money($info['commission']).' · IVA de comisión '.$money($info['vat_excluded']).' separado (no es gasto) · criterio pendiente de confirmar por Finanzas'
                        : 'interés '.$money($info['interest']).' · capital excluido '.$money($info['principal_excluded']))
                : 'Tabla de amortización: sin pagos con fecha dentro del periodo ('.($isCommission ? 'comisión' : 'interés').' $0.00).';
            $adjustments[$concept] = array_replace($adj, [
                'state' => AdjustmentService::CAPTURADO,
                'amount' => Money::str2($amount),
                'origin' => self::ORIGIN_TABLE,
                'notes' => $notes.($simulation ? ' · Documento de SIMULACIÓN (no vinculante).' : ''),
                'updated_by' => $uploads->pluck('uploaded_by')->filter()->implode(', ') ?: null,
                'updated_at' => $uploads->pluck('uploaded_at')->filter()->max(),
                'amortization' => $info,
            ]);
        }

        return $adjustments;
    }

    /**
     * Concepto de ajuste ⇒ créditos que lo alimentan y con qué campo de la tabla.
     *
     * @param  Collection<int, EstadoResultadosCredit>  $credits
     * @return array<string, list<array{credit:EstadoResultadosCredit, field:string}>>
     */
    private static function feeds(Collection $credits): array
    {
        $out = [];
        foreach ($credits as $c) {
            $out[$c->adjustment_concept][] = ['credit' => $c, 'field' => 'interest'];
            if (! empty($c->commission_concept)) {
                $out[$c->commission_concept][] = ['credit' => $c, 'field' => 'commission'];
            }
        }

        return $out;
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
     * Todo lo que necesita la pestaña «Gastos financieros» del periodo (consolidado). Contrato
     * documentado en resources/js/components/estado-resultados/financialExpenses.ts.
     *
     * @return array<string,mixed>
     */
    public function panel(Period $period): array
    {
        $ctx = $this->compute($period);
        $adjustments = $ctx['adjustments'];
        $locked = $ctx['locked'];
        $frozen = $locked ? $this->frozenAdjustments($locked->id) : null;

        $credits = [];
        $totals = ['payment' => BigDecimal::zero(), 'principal' => BigDecimal::zero(), 'interest' => BigDecimal::zero(), 'interest_vat' => BigDecimal::zero(),
            'commission' => BigDecimal::zero(), 'commission_vat' => BigDecimal::zero()];
        foreach ($ctx['credits'] as $item) {
            $credit = $item['model'];
            $calc = $item['calc'];
            $calc['bank'] = $this->bankEvidence($credit, $period, $calc); // compatibilidad (UI actual)
            $calc['creditor_payments'] = $item['creditor_payments'];
            $calc['contributions'] = $ctx['contributions']['by_credit'][$credit->key] ?? self::emptyContributions();
            $calc['status'] = $item['status'];
            $calc['recognized'] = $item['recognized'];
            $calc['adjustment'] = self::adjustmentPayload($adjustments[$credit->adjustment_concept] ?? null);
            $calc['commission_adjustment'] = $credit->commission_concept ? self::adjustmentPayload($adjustments[$credit->commission_concept] ?? null) : null;
            if ($frozen !== null) {
                $f = $frozen[$credit->adjustment_concept] ?? null;
                $adj = $adjustments[$credit->adjustment_concept] ?? null;
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
                foreach (array_keys($totals) as $k) {
                    $totals[$k] = $totals[$k]->plus(Money::of($calc['totals'][$k]));
                }
            }
            $credits[] = $calc;
        }

        return [
            'period' => ['id' => $period->id, 'label' => $period->label, 'start' => $period->start_date->format('Y-m-d'), 'end' => $period->end_date->format('Y-m-d')],
            'locked' => $locked ? ['version' => $locked->version] : null,
            'credits' => $credits,
            'totals' => array_map(fn ($v) => Money::str2($v), $totals),
            'summary' => $ctx['summary'],
            'contributions_unassigned' => $ctx['contributions']['unassigned'],
            'allocation' => $ctx['allocation'],
            'rules' => [
                'extensions' => AmortizationUploadService::EXTENSIONS,
                'accept' => '.'.implode(',.', AmortizationUploadService::EXTENSIONS),
                'columns' => AmortizationColumnMap::TEMPLATE_HEADERS,
                'tolerance' => AmortizationScheduleParser::tolerance(),
                'document_kinds' => AmortizationUploadService::DOCUMENT_KINDS,
                'tolerance_days' => self::tolDays(),
                'tolerance_amount' => Money::str2(self::tolAmount()),
                'late_window_days' => self::lateWindow(),
                'commissions_in_financial_result' => self::commissionsInResult(),
                'allocation_method' => self::allocationMethod(),
            ],
        ];
    }

    /**
     * Prorrateo GERENCIAL del costo financiero por sucursal (mismo cálculo que la web; lo usarán
     * PDF/Excel). ANALÍTICO: no crea ajustes ni cambia el ER consolidado ni Radiografía.
     *
     * Por crédito: participación = aportación identificada de la sucursal / total aportado al
     * crédito en el periodo (identificado + pendiente); asignado = (interés + comisión reconocidos)
     * × participación. Reparto exacto a centavos por residuo mayor (desempate: nombre de sucursal;
     * «sin asignar» al final). La parte de aportaciones pendientes, o todo si no hubo aportaciones,
     * queda en `sin_asignar` con motivo. Nunca partes iguales. Invariante: Σ asignado + sin_asignar
     * = reconocido. El capital se atribuye con la misma participación sólo como dato INFORMATIVO.
     *
     * @return array<string,mixed>
     */
    public function allocation(Period $period): array
    {
        return $this->compute($period)['allocation'];
    }

    /**
     * Cálculo compartido por panel() y allocation(): créditos del periodo, pagos al acreedor,
     * aportaciones, estado derivado, importes reconocidos, prorrateo y resumen.
     *
     * @return array<string,mixed>
     */
    private function compute(Period $period): array
    {
        $adjustments = app(AdjustmentService::class)->forPeriod($period->id, self::CONSOLIDADO);
        $locked = app(PeriodLockGuard::class)->lockedVersion($period);
        $credits = $this->credits();
        $movements = $this->periodMovements($period);
        $overrides = app(ClassificationService::class)->effectiveOverrides($period->id);
        $hasBank = EstadoResultadosUpload::query()->active()->where('period_id', $period->id)->exists();
        $contributions = $this->contributions($credits, $movements, $overrides);

        // Concepto ⇒ cuántos créditos lo alimentan (si es uno solo, el ajuste del ER es SU importe).
        $feedCount = array_map('count', self::feeds($credits));
        $commissionsOn = self::commissionsInResult();

        $items = [];
        $summary = ['observed' => BigDecimal::zero(), 'principal' => BigDecimal::zero(), 'interest' => BigDecimal::zero(), 'commission' => BigDecimal::zero(),
            'vat' => BigDecimal::zero(), 'unreconciled_count' => 0, 'unreconciled' => BigDecimal::zero()];
        foreach ($credits as $credit) {
            $calc = $this->creditPeriod($credit, $period);
            $payments = $this->creditorPayments($credit, $calc, $movements, $overrides, $locked !== null);
            $recognized = [
                'interest' => self::recognizedAmount($adjustments[$credit->adjustment_concept] ?? null, $feedCount[$credit->adjustment_concept] ?? 0, $calc, 'interest'),
                'commission' => $credit->commission_concept && $commissionsOn
                    ? self::recognizedAmount($adjustments[$credit->commission_concept] ?? null, $feedCount[$credit->commission_concept] ?? 0, $calc, 'commission')
                    : '0.00',
            ];
            $recognized['total'] = Money::str2(Money::of($recognized['interest'])->plus(Money::of($recognized['commission'])));
            $recognized['commission_informative'] = $credit->commission_concept && ! $commissionsOn ? $calc['totals']['commission'] : null;

            $items[] = [
                'model' => $credit, 'calc' => $calc, 'creditor_payments' => $payments, 'recognized' => $recognized,
                'status' => $this->derivedStatus($credit, $period, $calc, $payments, $hasBank),
            ];
            $summary['observed'] = $summary['observed']->plus(Money::of($payments['totals']['detected']));
            if ($calc['covered']) {
                $summary['principal'] = $summary['principal']->plus(Money::of($calc['totals']['principal']));
                $summary['vat'] = $summary['vat']->plus(Money::of($calc['totals']['interest_vat']))->plus(Money::of($calc['totals']['commission_vat']));
            }
            $summary['interest'] = $summary['interest']->plus(Money::of($recognized['interest']));
            $summary['commission'] = $summary['commission']->plus(Money::of($recognized['commission']));
            $summary['unreconciled_count'] += $payments['totals']['unreconciled_count'];
            $summary['unreconciled'] = $summary['unreconciled']->plus(Money::of($payments['totals']['unreconciled_amount']));
        }

        $allocation = $this->buildAllocation($items, $contributions);
        $cost = $summary['interest']->plus($summary['commission']);

        return [
            'adjustments' => $adjustments,
            'locked' => $locked,
            'credits' => $items,
            'contributions' => $contributions,
            'allocation' => $allocation,
            'summary' => [
                'observed_payment' => Money::str2($summary['observed']),
                'principal_informative' => Money::str2($summary['principal']),
                'interest_recognized' => Money::str2($summary['interest']),
                'commission_recognized' => Money::str2($summary['commission']),
                'vat_separated' => Money::str2($summary['vat']),
                'financial_cost' => Money::str2($cost),
                'ebt_effect' => Money::str2($cost->negated()),
                'unreconciled' => ['count' => $summary['unreconciled_count'], 'amount' => Money::str2($summary['unreconciled'])],
                'contributions_total' => $contributions['total'],
                'contributions_identified' => $contributions['identified'],
                'contributions_pending' => $contributions['pending'],
                'commissions_in_financial_result' => $commissionsOn,
                'commission_criterion' => 'pendiente_confirmacion_finanzas',
                'notes' => [
                    'El pago al acreedor y el capital NO son gasto: sólo interés y comisión reducen el EBT (una vez, en el consolidado).',
                    'El IVA de intereses y comisiones se muestra separado y nunca suma al gasto.',
                    'Tratar la comisión por apertura como gasto financiero del periodo es un criterio pendiente de confirmar por Finanzas.',
                ],
            ],
        ];
    }

    /**
     * Importe reconocido en el ER de un campo (interés/comisión) de un crédito: si el concepto lo
     * alimenta sólo este crédito y tiene dato, es el importe del ajuste (la captura manual gana);
     * si lo comparten varios créditos, el de la tabla del crédito (cubierta) o $0.
     *
     * @param  array<string,mixed>|null  $adj  ajuste del concepto
     * @param  int  $feeders  créditos que alimentan el concepto
     * @param  array<string,mixed>  $calc  creditPeriod()
     * @param  string  $field  interest | commission
     */
    private static function recognizedAmount(?array $adj, int $feeders, array $calc, string $field): string
    {
        if ($adj !== null && $feeders === 1) {
            return match ($adj['state']) {
                AdjustmentService::CAPTURADO => Money::str2($adj['amount']),
                default => '0.00',
            };
        }

        return $calc['covered'] ? $calc['totals'][$field] : '0.00';
    }

    /**
     * Movimientos bancarios del periodo (archivos activos, dentro del periodo) con el texto ya
     * normalizado y la cuenta ENMASCARADA (sólo los últimos 4 dígitos). Una consulta por panel.
     *
     * @return list<array<string,mixed>>
     */
    private function periodMovements(Period $period): array
    {
        $uploads = EstadoResultadosUpload::query()->active()->where('period_id', $period->id)->get(['id', 'account_slot', 'account_label'])->keyBy('id');
        if ($uploads->isEmpty()) {
            return [];
        }

        return EstadoResultadosMovement::query()
            ->where('period_id', $period->id)
            ->where('in_period', true)
            ->whereIn('upload_id', $uploads->keys()->all())
            ->orderBy('movement_date')->orderBy('id')
            ->get(['id', 'upload_id', 'fingerprint', 'movement_date', 'cargo', 'abono', 'concept', 'observations', 'bank_reference', 'account_slot',
                'branch_name', 'branch_kind', 'auto_treatment', 'rule_code'])
            ->map(function (EstadoResultadosMovement $m) use ($uploads) {
                $u = $uploads->get($m->upload_id);

                return [
                    'id' => $m->id, 'fingerprint' => $m->fingerprint, 'date' => $m->movement_date->format('Y-m-d'),
                    'cargo' => Money::str2($m->cargo), 'abono' => Money::str2($m->abono), 'concept' => $m->concept,
                    'text' => ConceptCatalogService::normalize(trim($m->concept.' '.$m->observations.' '.$m->bank_reference)),
                    'account' => self::maskAccount($u?->account_label, $m->account_slot),
                    'branch' => $m->branch_name, 'branch_kind' => $m->branch_kind,
                    'treatment' => $m->auto_treatment, 'rule' => $m->rule_code,
                ];
            })->all();
    }

    /**
     * Cuenta enmascarada: «****1234» con los últimos 4 dígitos; sin dígitos, el nombre de la cuenta.
     */
    public static function maskAccount(?string $label, ?string $slot): string
    {
        $digits = preg_replace('/\D+/', '', (string) $label) ?? '';
        if (strlen($digits) >= 4) {
            return '****'.substr($digits, -4);
        }

        return (string) (EstadoResultadosUploadService::SLOTS[$slot] ?? $slot ?? 'Cuenta');
    }

    /**
     * Regex de BD lista para preg_match sobre texto normalizado (null si vacía o inválida).
     */
    public static function compilePattern(?string $pattern): ?string
    {
        if ($pattern === null || trim($pattern) === '') {
            return null;
        }
        $re = '/'.str_replace('/', '\/', $pattern).'/iu';

        return @preg_match($re, '') === false ? null : $re;
    }

    /**
     * A) APORTACIONES del periodo: abonos de sucursales para el servicio de deuda (rule_code
     * aportacion_credito / aportacion_credito_sugerida, o texto con DEPÓSITO/APORTACIÓN… + el
     * acreedor). Una clasificación manual distinta de «transferencia interna»/«pendiente» la
     * descarta. «identificada» = transferencia interna + un solo crédito + sucursal operativa
     * identificada; lo demás queda «pendiente» (no cuenta para la participación de nadie).
     *
     * @param  Collection<int, EstadoResultadosCredit>  $credits
     * @param  list<array<string,mixed>>  $movements  periodMovements()
     * @param  array<string,array<string,mixed>>  $overrides  ClassificationService::effectiveOverrides()
     * @return array{by_credit:array<string,array<string,mixed>>, unassigned:list<array<string,mixed>>, total:string, identified:string, pending:string}
     */
    private function contributions(Collection $credits, array $movements, array $overrides): array
    {
        $patterns = $credits->mapWithKeys(fn ($c) => [$c->key => self::compilePattern($c->contribution_pattern ?? null)])->filter()->all();
        $byCredit = [];
        $unassigned = [];
        foreach ($movements as $m) {
            if (Money::of($m['abono'])->isZero()) {
                continue;
            }
            $isRule = in_array($m['rule'], [self::RULE_APORTACION, self::RULE_APORTACION_SUGERIDA], true);
            $matched = array_keys(array_filter($patterns, fn ($re) => preg_match($re, $m['text']) === 1));
            if (! $isRule && ($matched === [] || ! preg_match(MovementClassifier::CONTRIBUTION_KEYWORDS, $m['text']))) {
                continue;
            }
            $treatment = $overrides[$m['fingerprint']]['treatment'] ?? $m['treatment'];
            if (! in_array($treatment, [T::TRANSFERENCIA_INTERNA, T::PENDIENTE], true)) {
                continue; // reclasificado a mano como otra cosa: ya no es aportación
            }
            $creditKey = count($matched) === 1 ? $matched[0] : null;
            $branchOk = $m['branch'] && in_array($m['branch_kind'], ['operativa', 'agrupado'], true);
            $identified = $treatment === T::TRANSFERENCIA_INTERNA && $creditKey !== null && $branchOk;
            $entry = [
                'fingerprint' => $m['fingerprint'], 'date' => $m['date'], 'branch' => $m['branch'], 'account' => $m['account'],
                'concept' => $m['concept'], 'amount' => $m['abono'], 'credit_key' => $creditKey, 'rule' => $m['rule'],
                'manual' => isset($overrides[$m['fingerprint']]),
                'status' => $identified ? 'identificada' : 'pendiente',
                'reason' => $identified ? null : match (true) {
                    $creditKey === null && count($matched) > 1 => 'Menciona más de un acreedor: elige a qué crédito corresponde.',
                    $creditKey === null => 'No menciona un acreedor registrado: confirma a qué crédito corresponde.',
                    ! $branchOk => 'Falta identificar la sucursal que hizo la aportación.',
                    default => 'Pendiente de confirmar como aportación (transferencia interna).',
                },
            ];
            if ($creditKey === null) {
                $unassigned[] = $entry;
            } else {
                $byCredit[$creditKey]['items'][] = $entry;
            }
        }

        $all = BigDecimal::zero();
        $identifiedAll = BigDecimal::zero();
        foreach ($byCredit as $key => $group) {
            $branches = [];
            $ident = BigDecimal::zero();
            $pend = BigDecimal::zero();
            foreach ($group['items'] as $e) {
                if ($e['status'] === 'identificada') {
                    $branches[$e['branch']] = ($branches[$e['branch']] ?? BigDecimal::zero())->plus(Money::of($e['amount']));
                    $ident = $ident->plus(Money::of($e['amount']));
                } else {
                    $pend = $pend->plus(Money::of($e['amount']));
                }
            }
            ksort($branches, SORT_STRING);
            $byCredit[$key] = [
                'items' => $group['items'],
                'by_branch' => array_map(fn ($b, $v) => ['branch' => $b, 'amount' => Money::str2($v)], array_keys($branches), array_values($branches)),
                'identified' => Money::str2($ident),
                'pending' => Money::str2($pend),
                'total' => Money::str2($ident->plus($pend)),
                'count' => count($group['items']),
            ];
            $all = $all->plus($ident)->plus($pend);
            $identifiedAll = $identifiedAll->plus($ident);
        }
        $unassignedTotal = Money::sum(array_column($unassigned, 'amount'));

        return [
            'by_credit' => $byCredit,
            'unassigned' => $unassigned,
            'total' => Money::str2($all->plus($unassignedTotal)),
            'identified' => Money::str2($identifiedAll),
            'pending' => Money::str2($all->minus($identifiedAll)->plus($unassignedTotal)),
        ];
    }

    /** Estructura vacía de aportaciones de un crédito. */
    private static function emptyContributions(): array
    {
        return ['items' => [], 'by_branch' => [], 'identified' => '0.00', 'pending' => '0.00', 'total' => '0.00', 'count' => 0];
    }

    /**
     * B) PAGOS AL ACREEDOR: cada renglón previsto del periodo contra los CARGOS bancarios que
     * coinciden con el patrón del crédito (bank_pattern). Una aportación (abono) NUNCA marca un
     * pago como pagado. Estados por renglón:
     *  - conciliado: un cargo (o varios que suman la cuota) a ≤ tolerancia de días e importe;
     *  - parcial: un solo cargo menor a la cuota dentro de la tolerancia de días;
     *  - atrasado / adelantado: cargo por el importe fuera de la tolerancia pero dentro de la
     *    ventana (`ventana_dias_atraso`), después / antes de la fecha prevista;
     *  - no_localizado: sin cargo; si había VARIOS posibles se listan en `candidates` y no se
     *    asocia ninguno en automático (`ambiguous`).
     * Etapa: previsto (sólo tabla) · observado (con cargo) · confirmado (periodo aprobado o todos
     * sus cargos clasificados a mano). Cargos sin renglón ⇒ `no_previsto`; abonos del acreedor
     * (devoluciones/reversos) ⇒ `reversals`.
     *
     * @param  array<string,mixed>  $calc  creditPeriod()
     * @param  list<array<string,mixed>>  $movements  periodMovements()
     * @param  array<string,array<string,mixed>>  $overrides  clasificaciones manuales vigentes
     * @return array<string,mixed>
     */
    private function creditorPayments(EstadoResultadosCredit $credit, array $calc, array $movements, array $overrides, bool $locked): array
    {
        $re = self::compilePattern($credit->bank_pattern);
        $tolDays = self::tolDays();
        $tolAmount = self::tolAmount();
        $window = max($tolDays, self::lateWindow());
        $isContribution = fn ($m) => in_array($m['rule'], [self::RULE_APORTACION, self::RULE_APORTACION_SUGERIDA], true);

        $cargos = [];
        $reversals = [];
        foreach ($movements as $m) {
            if ($re === null || preg_match($re, $m['text']) !== 1 || $isContribution($m) || str_contains($m['text'], 'DEPOSITO')) {
                continue;
            }
            if (Money::of($m['cargo'])->isPositive()) {
                $cargos[$m['id']] = $m;
            } elseif (Money::of($m['abono'])->isPositive()) {
                $reversals[] = self::movementRef($m, $m['abono']);
            }
        }

        $rows = $calc['rows'];
        $days = fn (array $row, array $m) => (int) CarbonImmutable::parse($row['date'])->diffInDays(CarbonImmutable::parse($m['date']), false);
        $sameAmount = fn (array $row, array $m) => ! Money::of($m['cargo'])->minus(Money::of($row['payment']))->abs()->isGreaterThan($tolAmount);
        $assigned = []; // índice renglón ⇒ [status, ids]
        $candidates = [];
        $free = $cargos;

        // 1) Un cargo por el importe exacto, por niveles de distancia en días; sólo pares únicos.
        for ($d = 0; $d <= $tolDays; $d++) {
            do {
                $changed = false;
                $claims = [];
                $byRow = [];
                foreach ($rows as $i => $row) {
                    if (isset($assigned[$i])) {
                        continue;
                    }
                    foreach ($free as $id => $m) {
                        if (abs($days($row, $m)) === $d && $sameAmount($row, $m)) {
                            $byRow[$i][] = $id;
                            $claims[$id][] = $i;
                        }
                    }
                }
                foreach ($byRow as $i => $ids) {
                    if (count($ids) === 1 && count($claims[$ids[0]]) === 1) {
                        $assigned[$i] = ['conciliado', [$ids[0]]];
                        unset($free[$ids[0]], $candidates[$i]);
                        $changed = true;
                    } elseif (! isset($candidates[$i])) {
                        $candidates[$i] = $ids;
                    }
                }
            } while ($changed);
        }
        foreach ($rows as $i => $row) {
            if (isset($assigned[$i]) || isset($candidates[$i])) {
                continue;
            }
            $near = array_filter($free, fn ($m) => abs($days($row, $m)) <= $tolDays && Money::of($m['cargo'])->isLessThan(Money::of($row['payment'])));
            // 2) Varios cargos que juntos suman la cuota (subconjunto único).
            if (count($near) >= 2 && count($near) <= 6) {
                $subsets = self::subsetsSumming($near, Money::of($row['payment']), $tolAmount);
                if (count($subsets) === 1) {
                    $assigned[$i] = ['conciliado', $subsets[0]];
                    foreach ($subsets[0] as $id) {
                        unset($free[$id]);
                    }

                    continue;
                }
                if (count($subsets) > 1) {
                    $candidates[$i] = array_values(array_unique(array_merge(...$subsets)));

                    continue;
                }
            }
            // 3) Pago parcial: un solo cargo menor dentro de la tolerancia de días.
            if (count($near) === 1) {
                $assigned[$i] = ['parcial', [array_key_first($near)]];
                unset($free[array_key_first($near)]);

                continue;
            }
            if (count($near) > 1) {
                $candidates[$i] = array_keys($near);

                continue;
            }
            // 4) Atrasado / adelantado: importe exacto fuera de la tolerancia, dentro de la ventana.
            $late = array_filter($free, fn ($m) => abs($days($row, $m)) > $tolDays && abs($days($row, $m)) <= $window && $sameAmount($row, $m));
            if (count($late) === 1) {
                $id = array_key_first($late);
                $assigned[$i] = [$days($row, $late[$id]) > 0 ? 'atrasado' : 'adelantado', [$id]];
                unset($free[$id]);
            } elseif (count($late) > 1) {
                $candidates[$i] = array_keys($late);
            }
        }

        $today = now('America/Mexico_City')->format('Y-m-d');
        $out = [];
        $detectedTotal = BigDecimal::zero();
        $unreconciled = 0;
        $unreconciledAmount = BigDecimal::zero();
        foreach ($rows as $i => $row) {
            [$status, $ids] = $assigned[$i] ?? ['no_localizado', []];
            $ms = array_map(fn ($id) => $cargos[$id], $ids);
            $detected = Money::sum(array_column($ms, 'cargo'));
            $detectedTotal = $detectedTotal->plus($detected);
            $confirmed = $ms !== [] && ($locked || count(array_filter($ms, fn ($m) => isset($overrides[$m['fingerprint']]))) === count($ms));
            $cands = array_values(array_filter($candidates[$i] ?? [], fn ($id) => isset($free[$id])));
            if ($status !== 'conciliado') {
                $unreconciled++;
                $unreconciledAmount = $unreconciledAmount->plus(Money::of($row['payment'])->minus($detected)->abs());
            }
            $out[] = [
                'row_id' => $row['id'], 'number' => $row['number'], 'scheduled_date' => $row['date'],
                'bank_date' => $ms === [] ? null : max(array_column($ms, 'date')),
                'expected_payment' => $row['payment'], 'detected_payment' => Money::str2($detected),
                'principal' => $row['principal'], 'interest' => $row['interest'], 'commission' => $row['commission'] ?? '0.00',
                'vat' => Money::str2(Money::of($row['interest_vat'] ?? 0)->plus(Money::of($row['commission_vat'] ?? 0))),
                'difference' => Money::str2($detected->minus(Money::of($row['payment']))),
                'status' => $status,
                'stage' => $ms === [] ? 'previsto' : ($confirmed ? 'confirmado' : 'observado'),
                'due' => $row['date'] <= $today,
                'days' => $ms === [] ? null : $days($row, $ms[count($ms) - 1]),
                'movements' => array_map(fn ($m) => self::movementRef($m, $m['cargo']), $ms),
                'ambiguous' => $status === 'no_localizado' && count($cands) > 1,
                'candidates' => $status === 'no_localizado' ? array_map(fn ($id) => self::movementRef($cargos[$id], $cargos[$id]['cargo']), $cands) : [],
            ];
        }
        $extra = array_values(array_map(fn ($m) => self::movementRef($m, $m['cargo']) + ['status' => 'no_previsto'], $free));
        $extraTotal = Money::sum(array_column($extra, 'amount'));
        $expectedTotal = Money::of($calc['totals']['payment']);

        return [
            'pattern' => $credit->bank_pattern,
            'tolerance_days' => $tolDays,
            'tolerance_amount' => Money::str2($tolAmount),
            'rows' => $out,
            'unscheduled' => $extra,
            'reversals' => $reversals,
            'totals' => [
                'expected_count' => count($out),
                'reconciled_count' => count(array_filter($out, fn ($r) => $r['status'] === 'conciliado')),
                'expected' => Money::str2($expectedTotal),
                'detected' => Money::str2($detectedTotal->plus($extraTotal)),
                'detected_scheduled' => Money::str2($detectedTotal),
                'unscheduled' => Money::str2($extraTotal),
                'reversals' => Money::str2(Money::sum(array_column($reversals, 'amount'))),
                'difference' => Money::str2($detectedTotal->plus($extraTotal)->minus($expectedTotal)),
                'unreconciled_count' => $unreconciled + count($extra),
                'unreconciled_amount' => Money::str2($unreconciledAmount->plus($extraTotal)),
            ],
        ];
    }

    /**
     * Subconjuntos (≥2) de cargos cuya suma es la cuota dentro de la tolerancia.
     *
     * @param  array<int,array<string,mixed>>  $cargos  id ⇒ movimiento
     * @return list<list<int>>
     */
    private static function subsetsSumming(array $cargos, BigDecimal $target, BigDecimal $tol): array
    {
        $ids = array_keys($cargos);
        $n = count($ids);
        $out = [];
        for ($mask = 1; $mask < (1 << $n); $mask++) {
            if (substr_count(decbin($mask), '1') < 2) {
                continue;
            }
            $sum = BigDecimal::zero();
            $pick = [];
            for ($b = 0; $b < $n; $b++) {
                if ($mask & (1 << $b)) {
                    $sum = $sum->plus(Money::of($cargos[$ids[$b]]['cargo']));
                    $pick[] = $ids[$b];
                }
            }
            if (! $sum->minus($target)->abs()->isGreaterThan($tol)) {
                $out[] = $pick;
            }
        }

        return $out;
    }

    /** Referencia pública de un movimiento (cuenta enmascarada). */
    private static function movementRef(array $m, string $amount): array
    {
        return ['fingerprint' => $m['fingerprint'], 'date' => $m['date'], 'amount' => Money::str2($amount), 'concept' => $m['concept'],
            'account' => $m['account'], 'branch' => $m['branch']];
    }

    /**
     * Estado DERIVADO del crédito en el periodo (no se guarda): vigente · sin_pagos_programados
     * (fuera de calendario) · posible_liquidacion (ya pasó el último pago previsto y no hay cargos
     * al acreedor; NO es «liquidado», que sólo lo confirma un usuario) · pago_no_localizado (un
     * pago ya vencido sin cargo, cuando el periodo tiene estados de cuenta). Más: último pago
     * previsto, próximo pago, pagos restantes y saldo SEGÚN LA TABLA (no saldo bancario).
     *
     * @return array<string,mixed>
     */
    private function derivedStatus(EstadoResultadosCredit $credit, Period $period, array $calc, array $payments, bool $hasBank): array
    {
        $end = $period->end_date->format('Y-m-d');
        $upload = $credit->currentUpload;
        $all = $upload ? EstadoResultadosAmortizationRow::query()->where('upload_id', $upload->id)->orderBy('payment_date')->orderBy('id')
            ->get(['payment_number', 'payment_date', 'payment_total', 'balance']) : collect();
        $last = $all->last();
        $next = $all->first(fn ($r) => $r->payment_date->format('Y-m-d') > $end);
        $atEnd = $all->filter(fn ($r) => $r->payment_date->format('Y-m-d') <= $end)->last();
        $hasCargos = (float) $payments['totals']['detected'] > 0;
        $coverage = $calc['coverage'];

        [$status, $reason] = match (true) {
            $coverage['status'] === self::FUERA_CALENDARIO && $coverage['position'] === 'despues' && ! $hasCargos => ['posible_liquidacion', 'Ya pasó el último pago previsto ('.$last?->payment_date->format('d/m/Y').') y no hay cargos al acreedor en el periodo. Confirma con el acreedor antes de darlo por liquidado.'],
            $coverage['status'] === self::FUERA_CALENDARIO && $coverage['position'] === 'despues' => ['sin_pagos_programados', 'La tabla ya no tiene pagos programados, pero hay cargos al acreedor en el periodo: revisa si la tabla está completa.'],
            $coverage['status'] === self::FUERA_CALENDARIO => ['sin_pagos_programados', $coverage['reason']],
            $hasBank && collect($payments['rows'])->contains(fn ($r) => $r['due'] && $r['status'] === 'no_localizado') => ['pago_no_localizado', 'Hay pagos vencidos según la tabla sin cargo bancario localizado.'],
            default => ['vigente', $coverage['status'] === self::SIN_TABLA ? 'Sin tabla de amortización cargada.' : 'Crédito con pagos programados.'],
        };

        return [
            'status' => $status,
            'reason' => $reason,
            'last_scheduled' => $last ? ['number' => $last->payment_number, 'date' => $last->payment_date->format('Y-m-d')] : null,
            'next_payment' => $next ? ['number' => $next->payment_number, 'date' => $next->payment_date->format('Y-m-d'), 'payment' => Money::str2($next->payment_total ?? 0)] : null,
            'remaining_payments' => $all->filter(fn ($r) => $r->payment_date->format('Y-m-d') > $end)->count(),
            'document_balance' => $atEnd?->balance === null ? null : Money::str2($atEnd->balance),
            'document_balance_note' => 'Saldo según la tabla del acreedor al cierre del periodo (no es saldo bancario; en LEROM incluye comisión e IVA por pagar).',
            'document_kind' => $calc['upload']['document_kind'] ?? null,
        ];
    }

    /**
     * Prorrateo por crédito (ver allocation()).
     *
     * @param  list<array<string,mixed>>  $items  créditos calculados en compute()
     * @param  array<string,mixed>  $contributions  contributions()
     * @return array<string,mixed>
     */
    private function buildAllocation(array $items, array $contributions): array
    {
        $method = self::allocationMethod();
        $credits = [];
        $byBranch = [];
        $unassignedTotal = 0;
        $recognizedTotal = 0;
        foreach ($items as $item) {
            $key = $item['model']->key;
            $rec = $item['recognized'];
            $recCents = Money::cents($rec['total']);
            $intCents = Money::cents($rec['interest']);
            $capCents = $item['calc']['covered'] ? Money::cents($item['calc']['totals']['principal']) : 0;
            $contrib = $contributions['by_credit'][$key] ?? self::emptyContributions();

            $weights = [];
            foreach ($contrib['by_branch'] as $b) {
                $weights[$b['branch']] = Money::cents($b['amount']);
            }
            $pendingCents = Money::cents($contrib['pending']);
            $reason = null;
            if ($method !== 'aportaciones') {
                $weights = [];
                $reason = 'Método de prorrateo «'.$method.'» no soportado: todo queda sin asignar.';
            } elseif ($weights === [] && $pendingCents === 0) {
                $reason = 'No hay aportaciones de sucursales a este crédito en el periodo: el costo financiero queda sin asignar (nunca se reparte en partes iguales).';
            } elseif ($pendingCents > 0) {
                $reason = 'Parte de las aportaciones está pendiente de identificar (sucursal o crédito): esa proporción queda sin asignar.';
            }
            $w = $weights + ($pendingCents > 0 || $weights === [] ? [self::SIN_ASIGNAR => $weights === [] && $pendingCents === 0 ? 1 : $pendingCents] : []);
            $cost = self::largestRemainder($recCents, $w);
            $interest = self::largestRemainder($intCents, $w);
            $capital = self::largestRemainder($capCents, $w);
            $sumW = array_sum($w);

            $rows = [];
            foreach ($weights as $branch => $cents) {
                $rows[] = [
                    'branch' => $branch,
                    'contributed' => Money::str2(BigDecimal::ofUnscaledValue($cents, 2)),
                    'share' => (string) BigDecimal::of($cents)->dividedBy($sumW, 6, RoundingMode::HALF_UP),
                    'interest' => self::c2($interest[$branch]),
                    'commission' => self::c2($cost[$branch] - $interest[$branch]),
                    'assigned' => self::c2($cost[$branch]),
                    'principal_informative' => self::c2($capital[$branch]),
                ];
                $byBranch[$branch] = ($byBranch[$branch] ?? 0) + $cost[$branch];
            }
            $un = $cost[self::SIN_ASIGNAR] ?? 0;
            $unassignedTotal += $un;
            $recognizedTotal += $recCents;
            $credits[] = [
                'credit_key' => $key,
                'credit_name' => $item['model']->name,
                'recognized' => ['interest' => $rec['interest'], 'commission' => $rec['commission'], 'total' => $rec['total']],
                'principal_informative' => self::c2($capCents),
                'contributions' => ['identified' => $contrib['identified'], 'pending' => $contrib['pending'], 'total' => $contrib['total']],
                'branches' => $rows,
                'unassigned' => [
                    'amount' => self::c2($un),
                    'interest' => self::c2($interest[self::SIN_ASIGNAR] ?? 0),
                    'commission' => self::c2($un - ($interest[self::SIN_ASIGNAR] ?? 0)),
                    'principal_informative' => self::c2($capital[self::SIN_ASIGNAR] ?? 0),
                    'reason' => $reason,
                ],
                'check' => ['assigned_plus_unassigned' => self::c2(array_sum($cost)), 'recognized' => $rec['total'], 'ok' => array_sum($cost) === $recCents],
            ];
        }
        ksort($byBranch, SORT_STRING);

        return [
            'method' => $method,
            'analytic' => true,
            'note' => 'Prorrateo gerencial: no crea ajustes ni modifica el Estado de Resultados consolidado ni Radiografía. El capital atribuido es sólo informativo.',
            'credits' => $credits,
            'by_branch' => array_map(fn ($b, $c) => ['branch' => $b, 'assigned' => self::c2($c)], array_keys($byBranch), array_values($byBranch)),
            'unassigned' => self::c2($unassignedTotal),
            'recognized' => self::c2($recognizedTotal),
        ];
    }

    /**
     * Reparto exacto a centavos por residuo mayor: piso de total·w/Σw y los centavos que faltan
     * a los residuos mayores; desempate por orden de las llaves (sucursales por nombre y
     * «sin asignar» al final). Determinista. Σ resultado = total.
     *
     * @param  int  $total  centavos a repartir (≥ 0)
     * @param  array<string,int>  $weights  llave ⇒ peso en centavos (≥ 0), en orden de desempate
     * @return array<string,int> llave ⇒ centavos
     */
    public static function largestRemainder(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum <= 0) {
            return array_map(fn () => 0, $weights);
        }
        $out = [];
        $rem = [];
        $order = array_flip(array_keys($weights));
        foreach ($weights as $k => $w) {
            $prod = BigInteger::of($total)->multipliedBy($w);
            $out[$k] = $prod->quotient($sum)->toInt();
            $rem[$k] = $prod->remainder($sum)->toInt();
        }
        $left = $total - array_sum($out);
        $keys = array_keys($weights);
        usort($keys, fn ($a, $b) => [$rem[$b], $order[$a]] <=> [$rem[$a], $order[$b]]);
        for ($i = 0; $i < $left; $i++) {
            $out[$keys[$i % count($keys)]]++;
        }

        return $out;
    }

    /** Centavos ⇒ «1234.56». */
    private static function c2(int $cents): string
    {
        return Money::str2(BigDecimal::ofUnscaledValue($cents, 2));
    }

    /** Resumen del ajuste del ER de un concepto para el panel. */
    private static function adjustmentPayload(?array $adj): ?array
    {
        return $adj ? [
            'key' => $adj['key'], 'label' => $adj['label'], 'state' => $adj['state'], 'amount' => $adj['amount'],
            'origin' => $adj['origin'] ?? null, 'difference' => $adj['amortization']['difference'] ?? null,
            'updated_by' => $adj['updated_by'] ?? null, 'notes' => $adj['notes'] ?? null,
        ] : null;
    }

    private static function tolDays(): int
    {
        return max(0, (int) config('estado_resultados.gastos_financieros.tolerancia_dias_banco', 3));
    }

    private static function tolAmount(): BigDecimal
    {
        return Money::of(config('estado_resultados.gastos_financieros.tolerancia_importe_banco', '1.00'));
    }

    private static function lateWindow(): int
    {
        return max(0, (int) config('estado_resultados.gastos_financieros.ventana_dias_atraso', 10));
    }

    /** ¿La comisión financiera reduce el EBT? (no: solo intereses, criterio definido 25-nov-2026). */
    public static function commissionsInResult(): bool
    {
        return (bool) config('estado_resultados.gastos_financieros.comisiones_en_resultado_financiero', false);
    }

    private static function allocationMethod(): string
    {
        return (string) config('estado_resultados.gastos_financieros.prorrateo.metodo', 'aportaciones');
    }

    /**
     * Componente de la firma de insumos (ApprovalInputSignature / caché de comparativas):
     * tablas vigentes + renglones del periodo. null si no hay ninguna tabla cargada (las
     * firmas de periodos sin tablas no cambian). La comisión y el concepto de comisión sólo se
     * agregan cuando existen (las firmas previas sin comisiones no cambian por esto).
     */
    public function signature(Period $period): ?array
    {
        if (! self::available() || ! EstadoResultadosAmortizationUpload::query()->exists()) {
            return null;
        }
        $current = EstadoResultadosAmortizationUpload::query()->where('status', EstadoResultadosAmortizationUpload::VIGENTE)
            ->orderBy('credit_id')->orderBy('id')->get(['id', 'credit_id', 'file_hash', 'updated_at']);
        $base = EstadoResultadosAmortizationRow::query()->whereIn('upload_id', $current->pluck('id')->all() ?: [0])
            ->whereBetween('payment_date', [$period->start_date->format('Y-m-d'), $period->end_date->format('Y-m-d')]);
        $rows = (clone $base)->selectRaw('COUNT(*) AS n, MAX(id) AS m, SUM(interest) AS i, SUM(principal) AS c')->first();
        $rowsSig = array_map(fn ($v) => $v === null ? null : (string) $v, array_values((array) $rows?->getAttributes()));
        $withCommission = AmortizationUploadService::hasDocumentKind();
        if ($withCommission) {
            $commission = (clone $base)->sum('commission');
            if ((float) $commission !== 0.0) {
                $rowsSig[] = 'k:'.Money::str2($commission);
            }
        }

        return [
            'uploads' => $current->map(fn ($u) => [$u->id, $u->credit_id, $u->file_hash, (string) $u->updated_at])->all(),
            'rows' => $rowsSig,
            'credits' => EstadoResultadosCredit::query()->orderBy('id')->get()
                ->map(fn ($c) => array_merge([$c->id, $c->adjustment_concept, (int) $c->active, (string) $c->updated_at], empty($c->commission_concept) ? [] : [$c->commission_concept]))->all(),
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
            'total_interest_vat' => Money::str2($u->total_interest_vat ?? 0),
            'total_commission' => Money::str2($u->total_commission ?? 0),
            'total_commission_vat' => Money::str2($u->total_commission_vat ?? 0),
            // simulacion = documento NO vinculante del acreedor · definitivo = tabla confirmada.
            'document_kind' => $u->document_kind ?? AmortizationUploadService::SIMULACION,
            'columns' => (array) ($u->column_map['columns'] ?? []),
            'warnings' => array_values(array_map(fn ($w) => is_array($w) ? (string) ($w['message'] ?? '') : (string) $w, (array) $u->warnings)),
            'uploaded_by' => $u->uploader?->name,
            'uploaded_at' => $u->created_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
            'superseded_at' => $u->superseded_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
        ];
    }
}
