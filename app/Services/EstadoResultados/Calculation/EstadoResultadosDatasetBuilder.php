<?php

namespace App\Services\EstadoResultados\Calculation;

use App\Models\EstadoResultados\EstadoResultadosExport;
use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\EstadoResultados\EstadoResultadosReconciliationLink;
use App\Models\EstadoResultados\EstadoResultadosRun;
use App\Models\EstadoResultados\EstadoResultadosUpload;
use App\Models\Period;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\Classification\ClassificationService;
use App\Services\EstadoResultados\Classification\ErpOutsideClassifier;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use App\Services\EstadoResultados\Reconciliation\ErpExpenseRepository;
use App\Services\EstadoResultados\Reconciliation\EstadoResultadosExpenseReconciler as R;
use App\Services\EstadoResultados\Reconciliation\NoiPayrollRepository;
use App\Services\EstadoResultados\Support\Dimensions;
use App\Services\EstadoResultados\Support\Money;
use App\Services\EstadoResultados\Support\Treatment as T;
use App\Services\Radiography\BranchRadiographyCalculator as Radio;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FUENTE ÚNICA DE VERDAD del Estado de Resultados.
 *
 * Construye el dataset canónico que consumen, sin recalcular nada, la web
 * (Inertia/JSON), el Excel y el PDF. Toda cifra sale de aquí como cadena con
 * 2 decimales (redondeo de presentación) calculada internamente con BigDecimal.
 * El dataset lleva un hash (sha256) de sus cifras: Web = Excel = PDF se puede
 * comprobar comparando el hash impreso en los tres.
 *
 * Fórmulas:
 *   INGRESOS            = Σ componentes de ingreso real (BranchRadiographyCalculator::ingresoEbitdaBaseFor)
 *   EBITDA SUCURSALES   = INGRESOS − OPEX sucursales − Nómina y Capital Humano sucursales
 *   EBITDA CORPORATIVO  = EBITDA SUCURSALES − OPEX corporativo − Nómina corporativa
 *   EBITDA FINAL        = EBITDA CORPORATIVO − Gastos DG operativos (incluye remuneración DG)
 *   EBIT                = EBITDA FINAL − Depreciación − Amortización
 *   EBT                 = EBIT − Resultado financiero neto (gastos financieros − ingresos financieros)
 *   UTILIDAD NETA       = EBT − ISR
 *
 * Partición de importes (cada peso de cada fuente queda en EXACTAMENTE un destino):
 *   bruto = conciliado (lo reconoce el ERP/Radiografía) + reconocido (línea del ER)
 *         + excluido con justificación + pendiente + fuera del periodo
 */
class EstadoResultadosDatasetBuilder
{
    public const CONSOLIDADO = 'CONSOLIDADO';

    private const INCOME_FIELDS = [
        'interes_recuperado' => 'Intereses cobrados',
        'impuesto_recuperado' => 'IVA / impuesto cobrado (criterio gerencial)',
        'charges' => 'Multas / moratorios',
        'cargos_adicionales' => 'Cargos adicionales',
        'comision_apertura' => 'Comisión por apertura / cargos al inicio',
        'excedente_recuperado' => 'Excedentes recuperados',
        'seguro_crece_reconocido' => 'Seguro CRECE reconocido (30%)',
    ];

    /** @var list<string> totales (cargos+abonos) de las cargas activas — para la validación de partición */
    private array $activeUploadTotals = [];

    /** @var list<array<string,mixed>> resumen de integridad de las cargas activas */
    private array $activeUploads = [];

    /** NOI del periodo (mismo universo que Radiografía). */
    private array $noiData = [];

    /** Σ importe aplicado según la tabla de vínculos (para el control conciliados ↔ vínculos). */
    private string $linkedApplied = '0.00';

    /** Firma del ERP vigente (para detectar procesamiento desactualizado). */
    private ?string $currentErpSignature = null;

    public function __construct(
        private readonly RadiographyBaseProvider $base,
        private readonly ErpExpenseRepository $erp,
        private readonly ErpOutsideClassifier $erpClassifier,
        private readonly ClassificationService $classifications,
        private readonly AdjustmentService $adjustments,
        private readonly NoiPayrollRepository $noi,
    ) {}

    /** Alcances disponibles: consolidado + sucursales operativas de Radiografía. */
    public function scopes(Period $period): array
    {
        $out = [['key' => self::CONSOLIDADO, 'label' => 'Consolidado MR LANA']];
        foreach (array_keys($this->base->for($period)['branches']) as $name) {
            $out[] = ['key' => $name, 'label' => 'Sucursal '.$name];
        }

        return $out;
    }

    public function build(Period $period, string $scope = self::CONSOLIDADO): array
    {
        $base = $this->base->for($period);
        $consolidated = $scope === self::CONSOLIDADO;
        if (! $consolidated && ! isset($base['branches'][$scope])) {
            throw new \InvalidArgumentException('Alcance no válido: '.$scope);
        }
        $row = $consolidated ? $base['global'] : $base['branches'][$scope];

        $items = $this->items($period, $base['data_ids']);
        $scoped = array_values(array_filter($items, fn ($i) => $this->inScope($i, $scope)));
        $adj = $this->adjustments->forPeriod($period->id, $scope);

        // ── 01 Ingresos ─────────────────────────────────────────────────
        $incomeLines = [];
        foreach (self::INCOME_FIELDS as $field => $label) {
            $incomeLines[] = $this->line($field, $label, Money::of($row[$field] ?? 0), 'Radiografía · Recuperación');
        }
        $ingresos = $this->sumLines($incomeLines);

        // ── 02 OPEX sucursales (Radiografía, mismo desglose gastos_detalle) ──
        $opexLines = [];
        $detalle = (array) ($row['gastos_detalle'] ?? []);
        arsort($detalle);
        foreach ($detalle as $concept => $amount) {
            $opexLines[] = $this->line('opex_'.md5((string) $concept), (string) $concept, Money::of($amount), 'Radiografía · ERP + Lendus');
        }
        $opexRadio = $this->sumLines($opexLines);
        $opexComp = $this->sumTreatment($scoped, [T::OPEX_SUCURSAL]);
        if (! $opexComp->isZero() || $this->countTreatment($scoped, [T::OPEX_SUCURSAL]) > 0) {
            $opexLines[] = $this->line('opex_complementario', 'OPEX complementario de sucursales (bancos, clasificado manualmente)', $opexComp, 'Estados de cuenta · clasificación', 'No está en Radiografía: genera diferencia explicada en la conciliación.');
        }
        $opexSuc = $opexRadio->plus($opexComp);

        // ── 03 Nómina y Capital Humano sucursales ───────────────────────
        $nominaLines = [];
        foreach (Radio::nominaBreakdownFor($row) as $label => $amount) {
            $nominaLines[] = $this->line('nom_'.md5((string) $label), (string) $label, Money::of($amount), 'Radiografía · NOI + IMSS + gasto de empleados');
        }
        $nominaRadio = $this->sumLines($nominaLines);
        $nominaComp = $this->sumTreatment($scoped, [T::NOMINA_SUCURSAL]);
        if (! $nominaComp->isZero() || $this->countTreatment($scoped, [T::NOMINA_SUCURSAL]) > 0) {
            $nominaLines[] = $this->line('nomina_complementaria', 'Nómina complementaria de sucursales (bancos, clasificada manualmente)', $nominaComp, 'Estados de cuenta · clasificación', 'No está en Radiografía: genera diferencia explicada en la conciliación.');
        }
        $nominaSuc = $nominaRadio->plus($nominaComp);

        $ebitdaSuc = $ingresos->minus($opexSuc)->minus($nominaSuc);

        // ── 04/05 Corporativo y 06 DG (sólo consolidado) ───────────────
        $corpLines = [];
        $nomCorpLines = [];
        $dgLines = [];
        if ($consolidated) {
            foreach ($this->groupBySourceCategory($scoped, [T::OPEX_CORPORATIVO]) as $key => [$label, $amount, $source]) {
                $corpLines[] = $this->line('corp_'.md5($key), $label, $amount, $source);
            }
            foreach ($this->groupBySourceCategory($scoped, [T::NOMINA_CORPORATIVA]) as $key => [$label, $amount, $source]) {
                $nomCorpLines[] = $this->line('nomcorp_'.md5($key), $label, $amount, $source);
            }
            $dgLines[] = $this->line('dg_operativo', 'Gastos DG operativos', $this->sumTreatment($scoped, [T::DG_OPERATIVO]), 'Clasificación DG');
            $dgLines[] = $this->line('dg_remuneracion', 'Remuneración laboral DG', $this->sumTreatment($scoped, [T::DG_REMUNERACION]), 'Clasificación DG');
        }
        $opexCorp = $this->sumLines($corpLines);
        $nominaCorp = $this->sumLines($nomCorpLines);
        $dg = $this->sumLines($dgLines);
        $ebitdaCorp = $ebitdaSuc->minus($opexCorp)->minus($nominaCorp);
        $ebitdaFinal = $ebitdaCorp->minus($dg);
        $ebitdaBase = $consolidated ? $ebitdaFinal : $ebitdaSuc;

        // ── 07 D&A ─────────────────────────────────────────────────────
        $daLines = [
            $this->adjLine($adj['depreciacion']),
            $this->adjLine($adj['amortizacion']),
        ];
        $da = $this->sumLines($daLines);
        $ebit = $ebitdaBase->minus($da);

        // ── 08 Resultado financiero ────────────────────────────────────
        $finBancos = $this->sumTreatment($scoped, [T::GASTO_FINANCIERO]);
        $ingNoOpBancos = $this->sumTreatment($scoped, [T::INGRESO_NO_OPERATIVO]);
        $finExpense = [
            $this->adjLine($adj['intereses_bbva']),
            $this->adjLine($adj['intereses_lerom']),
            $this->adjLine($adj['otros_intereses']),
            $this->adjLine($adj['otros_gastos_financieros']),
            $this->line('fin_bancos', 'Gastos financieros clasificados desde bancos', $finBancos, 'Estados de cuenta · clasificación'),
        ];
        $finIncome = [
            $this->adjLine($adj['ingresos_financieros'], '(+) '),
            $this->line('ing_no_operativo_bancos', '(+) Ingresos no operativos clasificados desde bancos', $ingNoOpBancos, 'Estados de cuenta · clasificación'),
        ];
        $finGastos = $this->sumLines($finExpense);
        $finIngresos = $this->sumLines($finIncome);
        $finNeto = $finGastos->minus($finIngresos);
        $ebt = $ebit->minus($finNeto);

        // ── 09 ISR ─────────────────────────────────────────────────────
        $isrLines = [$this->adjLine($adj['isr'])];
        $isr = $this->sumLines($isrLines);
        $utilidad = $ebt->minus($isr);

        $completeness = $this->completeness($adj);

        $results = [
            'ingresos' => $ingresos, 'opex_sucursales' => $opexSuc, 'nomina_sucursales' => $nominaSuc,
            'ebitda_sucursales' => $ebitdaSuc, 'opex_corporativo' => $opexCorp, 'nomina_corporativa' => $nominaCorp,
            'ebitda_corporativo' => $ebitdaCorp, 'dg' => $dg, 'ebitda_final' => $ebitdaFinal,
            'depreciacion_amortizacion' => $da, 'ebit' => $ebit, 'gastos_financieros' => $finGastos,
            'ingresos_financieros' => $finIngresos, 'resultado_financiero_neto' => $finNeto, 'ebt' => $ebt,
            'isr' => $isr, 'utilidad_neta' => $utilidad,
        ];

        // Importe que cada categoría financiera EXCLUSIVA recibe desde movimientos (banco/ERP).
        $categoryTotals = [
            'opex_sucursales' => $opexComp, 'nomina_sucursales' => $nominaComp, 'opex_corporativo' => $opexCorp,
            'nomina_corporativa' => $nominaCorp, 'opex_dg' => $dg, 'financiero' => $finBancos->plus($ingNoOpBancos),
        ];

        $statement = $this->statement($consolidated, $incomeLines, $opexLines, $nominaLines, $corpLines, $nomCorpLines, $dgLines, $daLines, $finExpense, $finIncome, $isrLines, $results, $completeness);

        $radiografiaEbitda = Money::of(Radio::ebitdaFinalFor($row));
        $reconciliation = $this->radiographyReconciliation($row, $base, $consolidated, $ingresos, $opexRadio, $nominaRadio, $opexComp, $nominaComp, $ebitdaSuc, $radiografiaEbitda);

        $conciliation = $this->conciliationSummary($items, $scoped, $consolidated);
        $checks = $this->checks($row, $incomeLines, $opexLines, $nominaLines, $statement, $results, $items, $scoped, $ingresos, $opexRadio, $nominaRadio, $consolidated, $categoryTotals);
        $validation = $this->validation($period, $items, $scoped, $checks, $reconciliation, $consolidated);

        $dataset = [
            'meta' => [
                'period' => [
                    'id' => $period->id, 'code' => $period->code, 'label' => $period->label,
                    'start' => $period->start_date->format('Y-m-d'), 'end' => $period->end_date->format('Y-m-d'),
                ],
                'scope' => ['key' => $scope, 'label' => $consolidated ? 'Consolidado MR LANA' : 'Sucursal '.$scope, 'consolidated' => $consolidated],
                'currency' => 'MXN',
                'rules_version' => (string) config('estado_resultados.rules_version'),
                'tolerance_days' => (int) config('estado_resultados.conciliacion.tolerancia_dias', 3),
                'recognition' => Dimensions::criterion(),
                'dimensions_version' => Dimensions::VERSION,
                'generated_at' => now('America/Mexico_City')->format('d/m/Y H:i'),
            ],
            'results' => array_map(fn ($v) => Money::str2($v), $results),
            'margins' => $this->margins($results, $consolidated),
            'completeness' => $completeness,
            'statement' => $statement,
            'waterfall' => $this->waterfall($results, $consolidated),
            'radiography_reconciliation' => $reconciliation,
            'checks' => $checks,
            'conciliation' => $conciliation,
            'movements' => array_map(fn ($i) => $this->publicItem($i), $items),
            'outside' => $this->outside($items, $adj),
            'indicators' => $this->indicators($row, $base, $results, $consolidated, $scope),
            'adjustments' => array_values($adj),
            'sources' => $this->sources($period, $base, $items, $adj),
            'treatments' => T::catalog(),
            'reconciliation_labels' => R::LABELS,
            'validation' => $validation,
            'dimensions' => Dimensions::catalog(),
        ];
        $dataset['meta']['dataset_hash'] = $this->hash($dataset);

        return $dataset;
    }

    // ───────────────────────────────────────────────────────────────────
    // Items (movimientos bancarios + ERP fuera de Radiografía)
    // ───────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function items(Period $period, array $dataIds): array
    {
        $overrides = $this->classifications->effectiveOverrides($period->id);
        $erpRows = $this->erp->forDataIds($dataIds);
        $erpByFp = [];
        foreach ($erpRows as $e) {
            $erpByFp[$e['fingerprint']] = $e;
        }
        $this->noiData = $this->noi->forDataIds($dataIds);
        $this->currentErpSignature = ErpExpenseRepository::signature($erpRows);

        $uploads = EstadoResultadosUpload::query()->active()->where('period_id', $period->id)->orderBy('id')->get()->keyBy('id');
        $this->activeUploadTotals = $uploads->map(fn ($u) => (string) Money::of($u->total_cargos)->plus(Money::of($u->total_abonos)))->values()->all();
        $this->activeUploads = $uploads->values()->map(fn ($u) => [
            'slot' => $u->account_slot, 'file' => $u->original_name, 'hash' => $u->file_hash, 'control_ok' => $u->control_ok,
            'control_cargos' => $u->control_total_cargos !== null ? Money::str2($u->control_total_cargos) : null,
            'control_abonos' => $u->control_total_abonos !== null ? Money::str2($u->control_total_abonos) : null,
            'read_cargos' => Money::str2($u->total_cargos), 'read_abonos' => Money::str2($u->total_abonos),
            'movements' => (int) $u->movements_count,
        ])->all();
        $movements = EstadoResultadosMovement::query()
            ->where('period_id', $period->id)
            ->whereIn('upload_id', $uploads->keys()->all() ?: [0])
            ->orderBy('movement_date')->orderBy('account_slot')->orderBy('source_row')
            ->get();
        $movByFp = $movements->keyBy('fingerprint');

        // Lectura tolerante: antes de aplicar la migración 2026_11_06_0000A1 no hay vínculos (se ve como "sin procesar").
        $links = Schema::hasTable('estado_resultados_reconciliation_links')
            ? EstadoResultadosReconciliationLink::query()->where('period_id', $period->id)->orderBy('id')->get()
            : collect();
        $linksByMovement = $links->groupBy('movement_fingerprint');
        $erpLinks = $links->where('link_kind', 'conciliacion')->groupBy('target_fingerprint');
        $this->linkedApplied = Money::str2(Money::sum($links->where('link_kind', 'conciliacion')->filter(fn ($l) => $movByFp->has($l->movement_fingerprint))->pluck('applied_amount')->all()));

        $recognitionIds = $movements->pluck('recognition_period_id')->filter()->unique()->all();
        $periodCodes = $recognitionIds ? Period::query()->whereIn('id', $recognitionIds)->pluck('code', 'id')->all() : [];
        $periodCodes[$period->id] = $period->code;

        $items = [];
        foreach ($movements as $m) {
            $processed = $m->processed_at !== null;
            $auto = $processed ? (string) $m->auto_treatment : T::PENDIENTE;
            $ov = $overrides[$m->fingerprint] ?? null;
            $treatment = $ov['treatment'] ?? $auto;
            $erpMatches = (array) ($m->reconciliation_detail['erp'] ?? []);
            $applied = $processed ? Money::str2($m->applied_amount ?? 0) : '0.00';
            $mLinks = $linksByMovement->get($m->fingerprint, collect());
            $conciliacion = $mLinks->where('link_kind', 'conciliacion');
            $hint = match (true) {
                $mLinks->contains('link_kind', 'evidencia_noi') => 'noi',
                $conciliacion->isNotEmpty() => $conciliacion->every(fn ($l) => ($l->evidence['erp_universe'] ?? null) === 'radiografia') ? 'radiografia' : 'erp',
                $treatment === T::CUBIERTO_RADIOGRAFIA => 'radiografia',
                default => null,
            };
            $upload = $uploads[$m->upload_id] ?? null;
            $recPeriodId = $m->recognition_period_id !== null ? (int) $m->recognition_period_id : ($m->in_period ? $period->id : null);

            $items[] = [
                'fingerprint' => $m->fingerprint,
                'source' => 'banco',
                'account_slot' => $m->account_slot,
                'account_label' => $upload->account_label ?? null,
                'file' => $upload->original_name ?? null,
                'source_row' => (int) $m->source_row,
                'date' => $m->movement_date->format('Y-m-d'),
                'reference' => $m->bank_reference,
                'folio' => $m->folio_raw,
                'uuid' => $m->uuid,
                'beneficiary' => $m->beneficiary,
                'concept' => $m->concept,
                'category' => $m->concept_gasto,
                'observations' => $m->observations,
                'sucursal_raw' => $m->sucursal_raw,
                'branch_name' => $m->branch_name,
                'branch_kind' => $m->branch_kind,
                'cargo' => Money::str2($m->cargo),
                'abono' => Money::str2($m->abono),
                'in_period' => (bool) $m->in_period,
                'erp' => $erpMatches,
                'reconciliation_status' => $processed ? $m->reconciliation_status : 'sin_procesar',
                'reconciliation_message' => $m->reconciliation_detail['message'] ?? null,
                'reconciliation_delta_days' => $m->reconciliation_detail['delta_days'] ?? null,
                'auto_treatment' => $auto,
                'suggested_treatment' => $m->suggested_treatment,
                'rule_code' => $processed ? $m->rule_code : 'sin_procesar',
                'rule_reason' => $processed ? $m->reason : 'El periodo tiene archivos cargados sin procesar: presione "Procesar conciliación".',
                'treatment' => $treatment,
                'override' => $ov,
                'needs_review' => $treatment === T::PENDIENTE,
                'transfer_pair' => $m->transfer_pair_key,
                'detail_lines' => count((array) $m->detail_lines),
                'linked_bank' => [],
                // ── Dimensiones / trazabilidad (nuevas) ───────────────────
                'applied' => $applied,
                'match_type' => $processed ? $m->match_type : null,
                'match_group' => $processed ? $m->match_group : null,
                'reversal_pair' => $processed ? $m->reversal_pair_key : null,
                'refund_of' => $processed ? $m->refund_target_fingerprint : null,
                'refund_status' => $processed ? $m->refund_status : null,
                'noi_employee_id' => $processed && $m->noi_employee_id ? (int) $m->noi_employee_id : null,
                'recognizing_hint' => $hint,
                'import_status' => $upload?->control_ok === true ? 'importado' : ($upload?->control_ok === false ? 'control_no_cuadra' : 'sin_control'),
                'recognition' => [
                    'period_id' => $recPeriodId,
                    'period_code' => $recPeriodId !== null ? ($periodCodes[$recPeriodId] ?? null) : null,
                    'basis' => 'fecha_bancaria',
                    'out_of_period' => $processed ? $m->out_of_period_kind : (! $m->in_period ? ($m->movement_date->format('Y-m-d') < $period->start_date->format('Y-m-d') ? 'fecha_anterior_al_periodo' : 'fecha_posterior_al_periodo') : null),
                ],
                'dates' => [
                    'operation' => $m->operation_date?->format('Y-m-d') ?? $m->movement_date->format('Y-m-d'),
                    'bank' => $m->movement_date->format('Y-m-d'),
                    'erp_capture' => $m->erp_capture_date?->format('Y-m-d'),
                    'erp_payment' => $m->erp_payment_date?->format('Y-m-d'),
                    'accrual' => $m->accrual_date?->format('Y-m-d'),
                ],
                'links' => $mLinks->map(fn ($l) => $this->publicLink($l, false))->values()->all(),
            ];
        }

        foreach ($erpRows as $e) {
            if ($e['universe'] === 'radiografia') {
                continue; // ya dentro del EBITDA de sucursales
            }
            $c = $this->erpClassifier->classify($e);
            $noiLink = null;
            // ERP + NOI: una requisición de nómina que nombra a un colaborador NOI podría ya estar en Nómina sucursales.
            if ($c['treatment'] === T::NOMINA_CORPORATIVA || str_contains(mb_strtoupper((string) $e['category']), 'NÓMINA') || str_contains(mb_strtoupper((string) $e['category']), 'NOMINA')) {
                $match = NoiPayrollRepository::match($this->noiData, trim(($e['concept'] ?? '').' '.($e['observations'] ?? '')), true);
                if ($match['status'] !== 'ninguno') {
                    $noiLink = $match['employee'];
                    $c = ['treatment' => T::PENDIENTE, 'suggested' => T::NOMINA_CORPORATIVA, 'rule' => 'erp_nomina_colaborador_noi', 'review' => true,
                        'reason' => 'La requisición ERP de nómina nombra a '.$noiLink['name'].', colaborador NOI del periodo cuya nómina ya está en Nómina sucursales (Radiografía). Confirme que no es el mismo pago antes de sumarlo (NOI, ERP y banco nunca se cuentan dos veces).'];
                }
            }
            $ov = $overrides[$e['fingerprint']] ?? null;
            $treatment = $ov['treatment'] ?? $c['treatment'];
            $bankLinks = $erpLinks->get($e['fingerprint'], collect())->filter(fn ($l) => $movByFp->has($l->movement_fingerprint));
            $paid = Money::sum($bankLinks->pluck('applied_amount')->all());
            if ($bankLinks->isEmpty()) {
                // Compatibilidad: procesamientos previos a la tabla de vínculos.
                foreach ($movements as $m) {
                    if ($m->auto_treatment === T::CONCILIADO_ERP && in_array($e['fingerprint'], array_column((array) ($m->reconciliation_detail['erp'] ?? []), 'fingerprint'), true)) {
                        $paid = $paid->plus(Money::of($m->cargo));
                        $bankLinks->push((object) ['movement_fingerprint' => $m->fingerprint, 'applied_amount' => $m->cargo, 'link_kind' => 'conciliacion', 'target_type' => 'erp', 'target_fingerprint' => $e['fingerprint'], 'match_type' => '1:1', 'group_key' => null, 'movement_pending' => 0, 'target_pending' => 0, 'delta_days' => null, 'evidence' => []]);
                    }
                }
            }
            $erpPending = Money::of($e['amount'])->minus($paid);
            $status = match (true) {
                $bankLinks->isEmpty() => 'erp',
                $erpPending->isGreaterThan(0) => 'erp_pago_parcial',
                default => 'erp_con_pago_bancario',
            };
            $linkedBank = $bankLinks->map(function ($l) use ($movByFp) {
                $bm = $movByFp->get($l->movement_fingerprint);

                return ['row' => $bm?->source_row, 'account' => $bm?->account_slot, 'amount' => Money::str2($l->applied_amount), 'date' => $bm?->movement_date?->format('Y-m-d'), 'fingerprint' => $l->movement_fingerprint, 'match_type' => $l->match_type];
            })->values()->all();
            $bankDates = array_filter(array_column($linkedBank, 'date'));

            $items[] = [
                'fingerprint' => $e['fingerprint'],
                'source' => 'erp',
                'account_slot' => 'erp',
                'account_label' => 'ERP de gastos',
                'file' => null,
                'source_row' => $e['id'],
                'date' => $e['date'],
                'reference' => null,
                'folio' => $e['folio'],
                'uuid' => null,
                'beneficiary' => null,
                'concept' => $e['concept'],
                'category' => $e['category'],
                'observations' => $e['observations'],
                'sucursal_raw' => $e['sucursal_raw'],
                'branch_name' => $e['branch_name'] ?? ($e['universe'] === 'corporativo' ? 'CORPORATIVO' : null),
                'branch_kind' => $e['universe'] === 'corporativo' ? 'corporativo' : 'desconocida',
                'cargo' => $e['amount'],
                'abono' => '0.00',
                'in_period' => true,
                'erp' => [],
                'reconciliation_status' => $status === 'erp_pago_parcial' ? 'erp_con_pago_bancario' : $status,
                'reconciliation_message' => match ($status) {
                    'erp' => 'Registro ERP sin pago bancario identificado en los archivos complementarios.',
                    'erp_pago_parcial' => 'Registro ERP con pago bancario PARCIAL: pagado $'.number_format((float) Money::str2($paid), 2).', saldo pendiente de pago $'.number_format((float) Money::str2($erpPending), 2).'. Se reconoce una sola vez, desde aquí.',
                    default => 'Registro ERP conciliado con '.count($linkedBank).' pago(s) bancario(s): se cuenta una sola vez, desde aquí.',
                },
                'auto_treatment' => $c['treatment'],
                'suggested_treatment' => $c['suggested'],
                'rule_code' => $c['rule'],
                'rule_reason' => $c['reason'],
                'treatment' => $treatment,
                'override' => $ov,
                'needs_review' => $treatment === T::PENDIENTE,
                'transfer_pair' => null,
                'detail_lines' => 0,
                'linked_bank' => $linkedBank,
                'erp_universe' => $e['universe'],
                'applied' => '0.00',
                'match_type' => null,
                'match_group' => null,
                'reversal_pair' => null,
                'refund_of' => null,
                'refund_status' => null,
                'noi_employee_id' => $noiLink['employee_id'] ?? null,
                'recognizing_hint' => null,
                'import_status' => 'erp',
                'erp_payment_status' => $status,
                'erp_payment' => ['amount' => Money::str2($e['amount']), 'applied' => Money::str2($paid), 'pending' => Money::str2($erpPending)],
                'recognition' => ['period_id' => $period->id, 'period_code' => $period->code, 'basis' => 'periodo_erp', 'out_of_period' => null],
                'dates' => [
                    'operation' => $e['fecha_captura'] ?? $e['date'],
                    'bank' => $bankDates !== [] ? min($bankDates) : null,
                    'erp_capture' => $e['fecha_captura'] ?? null,
                    'erp_payment' => $e['fecha_pago'] ?? null,
                    'accrual' => null,
                ],
                'links' => array_merge(
                    $bankLinks->map(fn ($l) => $this->publicLink($l, true))->values()->all(),
                    $noiLink ? [['kind' => 'evidencia_noi', 'kind_label' => Dimensions::LINK_KINDS['evidencia_noi'], 'target_type' => 'noi', 'target' => (string) $noiLink['employee_id'],
                        'match_type' => null, 'group_key' => null, 'applied' => '0.00', 'movement_pending' => '0.00', 'target_pending' => '0.00', 'delta_days' => null,
                        'evidence' => ['employee' => $noiLink['name'], 'branch' => $noiLink['branch'], 'operative' => $noiLink['operative'], 'noi_total' => $noiLink['total']]]] : [],
                ),
            ];
        }

        // Reembolsos vinculados: reducen la MISMA línea del gasto reembolsado (si está reconocido).
        $byFp = [];
        foreach ($items as $idx => $i) {
            $byFp[$i['fingerprint']] = $idx;
        }
        foreach ($items as &$i) {
            $i['treatment_source'] = $i['override'] ? 'manual' : 'regla';
            if ($i['override'] || $i['rule_code'] !== 'reembolso_vinculado' || $i['refund_of'] === null) {
                continue;
            }
            $target = isset($byFp[$i['refund_of']]) ? $items[$byFp[$i['refund_of']]] : null;
            if ($target && $target['in_period'] && T::affectsStatement($target['treatment']) && $target['treatment'] !== T::INGRESO_NO_OPERATIVO) {
                $i['treatment'] = $target['treatment'];
                $i['needs_review'] = false;
                $i['treatment_source'] = 'reembolso';
                $i['rule_reason'] .= ' Reduce: '.T::label($target['treatment']).' (folio '.($target['folio'] ?? '—').').';
                if ($i['branch_name'] === null || $target['source'] === 'erp') {
                    $i['branch_name'] = $target['branch_name'];
                }
            } else {
                $i['rule_reason'] .= ' El gasto vinculado no está reconocido en el ER (pendiente o fuera de él): el reembolso queda pendiente.';
            }
        }
        unset($i);

        foreach ($items as &$i) {
            $i['impact'] = $this->impact($i);
            $i['amounts'] = $this->allocation($i);
            $i['dimensions_raw'] = Dimensions::derive([
                'source' => $i['source'],
                'in_period' => $i['in_period'],
                'treatment' => $i['treatment'],
                'reconciliation_status' => $i['source'] === 'erp' ? $i['erp_payment_status'] : $i['reconciliation_status'],
                'applied' => $i['applied'],
                'classifiable' => $i['amounts']['classifiable'],
                'has_override' => $i['override'] !== null,
                'recognizing_hint' => $i['recognizing_hint'],
                'import' => $i['import_status'],
            ]);
        }
        unset($i);

        return $items;
    }

    private function publicLink(object $l, bool $inverse): array
    {
        $evidence = is_array($l->evidence ?? null) ? $l->evidence : (json_decode((string) ($l->evidence ?? ''), true) ?: []);

        return [
            'kind' => $l->link_kind,
            'kind_label' => Dimensions::LINK_KINDS[$l->link_kind] ?? $l->link_kind,
            'target_type' => $inverse ? 'banco' : $l->target_type,
            'target' => $inverse ? $l->movement_fingerprint : $l->target_fingerprint,
            'match_type' => $l->match_type,
            'group_key' => $l->group_key,
            'applied' => Money::str2($l->applied_amount),
            'movement_pending' => Money::str2($l->movement_pending ?? 0),
            'target_pending' => Money::str2($l->target_pending ?? 0),
            'delta_days' => $l->delta_days !== null ? (int) $l->delta_days : null,
            'evidence' => $evidence,
        ];
    }

    /** Porción de cargo que NO quedó cubierta por la conciliación con ERP (lo único clasificable). */
    private function classifiableCargo(array $item): BigDecimal
    {
        return Money::of($item['cargo'])->minus(Money::of($item['applied'] ?? '0'));
    }

    /** Importe con el que un item afecta su línea (gasto: cargo − abono; ingreso: abono − cargo), sin la parte conciliada. */
    private function signedAmount(array $item): BigDecimal
    {
        $cargo = $this->classifiableCargo($item);
        $abono = Money::of($item['abono']);

        return $item['treatment'] === T::INGRESO_NO_OPERATIVO ? $abono->minus($cargo) : $cargo->minus($abono);
    }

    private function impact(array $item): array
    {
        $t = $item['treatment'];
        $line = T::line($t);
        $classifiable = $this->classifiableCargo($item)->plus(Money::of($item['abono']));
        if ($line !== null && $item['in_period'] && ! Money::isZeroCents($classifiable)) {
            return [
                'included' => true,
                'line' => $line,
                'amount' => Money::str2($this->signedAmount($item)),
                'text' => 'Suma a: '.T::label($t).($item['treatment_source'] === 'reembolso' ? ' (reembolso: reduce la línea)' : ''),
            ];
        }

        return [
            'included' => false,
            'line' => null,
            'amount' => '0.00',
            'text' => $line !== null && $item['in_period']
                ? 'No suma: el importe ya está conciliado con el ERP (se reconoce una sola vez, desde el ERP).'
                : 'No suma: '.T::label($t),
        ];
    }

    /**
     * Partición del importe BRUTO (cargo + abono) del item: cada peso queda en exactamente un destino.
     *
     * @return array{gross:string, conciliated:string, recognized:string, excluded:string, pending:string, out_of_period:string, classifiable:string}
     */
    private function allocation(array $item): array
    {
        $gross = Money::of($item['cargo'])->plus(Money::of($item['abono']));
        $parts = ['conciliated' => BigDecimal::zero(), 'recognized' => BigDecimal::zero(), 'excluded' => BigDecimal::zero(), 'pending' => BigDecimal::zero(), 'out_of_period' => BigDecimal::zero()];
        $classifiable = $gross->minus(Money::of($item['applied'] ?? '0'));
        if (! $item['in_period']) {
            $parts['out_of_period'] = $gross;
        } else {
            $parts['conciliated'] = Money::of($item['applied'] ?? '0');
            $dest = match (true) {
                $item['impact']['included'] => 'recognized',
                $item['treatment'] === T::PENDIENTE => 'pending',
                default => 'excluded',
            };
            $parts[$dest] = $parts[$dest]->plus($classifiable);
        }

        return ['gross' => Money::str2($gross)] + array_map(fn ($v) => Money::str2($v), $parts) + ['classifiable' => Money::str2($classifiable)];
    }

    private function inScope(array $item, string $scope): bool
    {
        if ($scope === self::CONSOLIDADO) {
            return true;
        }

        return in_array($item['treatment'], [T::OPEX_SUCURSAL, T::NOMINA_SUCURSAL], true)
            && mb_strtoupper((string) $item['branch_name']) === mb_strtoupper($scope);
    }

    private function sumTreatment(array $items, array $treatments): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($items as $i) {
            if ($i['in_period'] && in_array($i['treatment'], $treatments, true)) {
                $total = $total->plus($this->signedAmount($i));
            }
        }

        return $total;
    }

    private function countTreatment(array $items, array $treatments): int
    {
        return count(array_filter($items, fn ($i) => $i['in_period'] && in_array($i['treatment'], $treatments, true)));
    }

    /** @return array<string, array{0:string,1:BigDecimal,2:string}> */
    private function groupBySourceCategory(array $items, array $treatments): array
    {
        $groups = [];
        foreach ($items as $i) {
            if (! $i['in_period'] || ! in_array($i['treatment'], $treatments, true)) {
                continue;
            }
            if ($i['source'] === 'erp') {
                $key = 'erp|'.($i['category'] ?: 'Sin categoría');
                $label = 'ERP · '.($i['category'] ?: 'Sin categoría');
                $source = 'ERP de gastos (sucursal CORPORATIVO)';
            } else {
                $key = 'banco';
                $label = 'Pagos complementarios (estados de cuenta)';
                $source = 'Estados de cuenta complementarios';
            }
            $groups[$key] ??= [$label, BigDecimal::zero(), $source];
            $groups[$key][1] = $groups[$key][1]->plus($this->signedAmount($i));
        }
        uasort($groups, fn ($a, $b) => $b[1]->compareTo($a[1]));

        return $groups;
    }

    // ───────────────────────────────────────────────────────────────────
    // Estructura del estado
    // ───────────────────────────────────────────────────────────────────

    private function line(string $key, string $label, BigDecimal $amount, string $source, ?string $hint = null, ?string $state = null): array
    {
        return ['key' => $key, 'label' => $label, 'amount_raw' => $amount, 'source' => $source, 'hint' => $hint, 'state' => $state];
    }

    private function adjLine(array $adj, string $prefix = ''): array
    {
        $amount = $adj['state'] === AdjustmentService::CAPTURADO ? Money::of($adj['amount']) : BigDecimal::zero();
        $hint = match ($adj['state']) {
            AdjustmentService::SIN_INFORMACION => 'Sin información: no se asume $0. Capture el importe o confirme $0 en Ajustes.',
            AdjustmentService::CONFIRMADO_CERO => 'Confirmado en $0.00'.($adj['updated_by'] ? ' por '.$adj['updated_by'] : '').'.',
            default => 'Capturado'.($adj['updated_by'] ? ' por '.$adj['updated_by'] : '').($adj['notes'] ? ' · '.$adj['notes'] : ''),
        };

        return $this->line('adj_'.$adj['key'], $prefix.$adj['label'], $amount, 'Ajuste contable', $hint, $adj['state']);
    }

    private function sumLines(array $lines): BigDecimal
    {
        $t = BigDecimal::zero();
        foreach ($lines as $l) {
            $t = $t->plus($l['amount_raw']);
        }

        return $t;
    }

    private function completeness(array $adj): array
    {
        $missing = fn (array $keys) => array_values(array_map(fn ($k) => $adj[$k]['label'], array_filter($keys, fn ($k) => $adj[$k]['state'] === AdjustmentService::SIN_INFORMACION)));
        $ebit = $missing(['depreciacion', 'amortizacion']);
        $ebt = array_merge($ebit, $missing(['intereses_bbva', 'intereses_lerom', 'otros_intereses', 'otros_gastos_financieros', 'ingresos_financieros']));
        $neta = array_merge($ebt, $missing(['isr']));

        return [
            'ebit' => ['complete' => $ebit === [], 'missing' => $ebit],
            'ebt' => ['complete' => $ebt === [], 'missing' => $ebt],
            'utilidad_neta' => ['complete' => $neta === [], 'missing' => $neta],
        ];
    }

    private function statement(bool $consolidated, array $income, array $opex, array $nomina, array $corp, array $nomCorp, array $dg, array $da, array $finExp, array $finInc, array $isr, array $r, array $completeness): array
    {
        $base = $r['ingresos'];
        $fmt = function (array $lines) use ($base) {
            return array_map(fn ($l) => [
                'key' => $l['key'], 'label' => $l['label'], 'amount' => Money::str2($l['amount_raw']),
                'pct' => Money::pct($l['amount_raw'], $base), 'source' => $l['source'], 'hint' => $l['hint'], 'state' => $l['state'],
            ], $lines);
        };
        $section = fn (string $key, string $number, string $title, string $kind, array $lines, string $totalLabel, BigDecimal $total, bool $applies = true, ?string $note = null) => [
            'type' => 'section', 'key' => $key, 'number' => $number, 'title' => $title, 'kind' => $kind,
            'applies' => $applies, 'note' => $note,
            'lines' => $fmt($lines),
            'total' => ['label' => $totalLabel, 'amount' => Money::str2($total), 'pct' => Money::pct($total, $base)],
        ];
        $result = fn (string $key, string $label, BigDecimal $amount, string $formula, int $level, ?array $complete = null) => [
            'type' => 'result', 'key' => $key, 'label' => $label, 'amount' => Money::str2($amount),
            'pct' => Money::pct($amount, $base), 'formula' => $formula, 'level' => $level,
            'complete' => $complete['complete'] ?? true, 'missing' => $complete['missing'] ?? [],
        ];
        $na = $consolidated ? null : 'Corporativo y Dirección General sólo se presentan en el consolidado.';

        $blocks = [
            $section('ingresos', '01', 'INGRESOS OPERATIVOS', 'income', $income, 'TOTAL INGRESOS OPERATIVOS', $r['ingresos']),
            $section('opex_sucursales', '02', 'OPEX SUCURSALES', 'expense', $opex, 'TOTAL OPEX SUCURSALES', $r['opex_sucursales']),
            $section('nomina_sucursales', '03', 'NÓMINA Y CAPITAL HUMANO SUCURSALES', 'expense', $nomina, 'TOTAL NÓMINA SUCURSALES', $r['nomina_sucursales']),
            $result('ebitda_sucursales', 'EBITDA SUCURSALES', $r['ebitda_sucursales'], 'Ingresos − OPEX sucursales − Nómina sucursales', 1),
            $section('opex_corporativo', '04', 'OPEX CORPORATIVO', 'expense', $corp, 'TOTAL OPEX CORPORATIVO', $r['opex_corporativo'], $consolidated, $na),
            $section('nomina_corporativa', '05', 'NÓMINA CORPORATIVA', 'expense', $nomCorp, 'TOTAL NÓMINA CORPORATIVA', $r['nomina_corporativa'], $consolidated, $na),
        ];
        if ($consolidated) {
            $blocks[] = $result('ebitda_corporativo', 'EBITDA CORPORATIVO', $r['ebitda_corporativo'], 'EBITDA sucursales − OPEX corporativo − Nómina corporativa', 2);
        }
        $blocks[] = $section('dg', '06', 'GASTOS OPERATIVOS DIRECCIÓN GENERAL', 'expense', $dg, 'TOTAL DG OPERATIVO', $r['dg'], $consolidated, $na);
        if ($consolidated) {
            $blocks[] = $result('ebitda_final', 'EBITDA FINAL', $r['ebitda_final'], 'EBITDA corporativo − Gastos DG operativos', 3);
        }
        $blocks[] = $section('da', '07', 'DEPRECIACIÓN Y AMORTIZACIÓN CONTABLE', 'expense', $da, 'TOTAL D&A', $r['depreciacion_amortizacion']);
        $blocks[] = $result('ebit', 'EBIT', $r['ebit'], ($consolidated ? 'EBITDA final' : 'EBITDA sucursal').' − Depreciación − Amortización', 4, $completeness['ebit']);
        // Los ingresos financieros se presentan en NEGATIVO dentro de una sección expresada como
        // gasto neto: así Σ líneas = resultado financiero neto (validación de sección exacta).
        $finIncNeg = array_map(fn ($l) => ['amount_raw' => $l['amount_raw']->negated(), 'hint' => trim(($l['hint'] ?? '').' Se muestra en negativo: disminuye el gasto financiero neto.')] + $l, $finInc);
        $fin = $section('financiero', '08', 'RESULTADO FINANCIERO Y NO OPERATIVO', 'financial', array_merge($finExp, $finIncNeg), 'RESULTADO FINANCIERO NETO (gasto)', $r['resultado_financiero_neto']);
        $fin['subtotals'] = [
            ['label' => 'Gastos financieros', 'amount' => Money::str2($r['gastos_financieros'])],
            ['label' => '(+) Ingresos financieros / no operativos', 'amount' => Money::str2($r['ingresos_financieros'])],
        ];
        $blocks[] = $fin;
        $blocks[] = $result('ebt', 'EBT · UTILIDAD ANTES DE IMPUESTOS', $r['ebt'], 'EBIT − Resultado financiero neto', 5, $completeness['ebt']);
        $blocks[] = $section('isr', '09', 'IMPUESTO SOBRE LA RENTA', 'expense', $isr, 'TOTAL ISR', $r['isr']);
        $blocks[] = $result('utilidad_neta', 'UTILIDAD NETA', $r['utilidad_neta'], 'EBT − ISR', 6, $completeness['utilidad_neta']);

        return $blocks;
    }

    private function margins(array $r, bool $consolidated): array
    {
        $base = $r['ingresos'];

        return [
            'ebitda_sucursales' => Money::pct($r['ebitda_sucursales'], $base),
            'ebitda_corporativo' => $consolidated ? Money::pct($r['ebitda_corporativo'], $base) : null,
            'ebitda_final' => $consolidated ? Money::pct($r['ebitda_final'], $base) : null,
            'ebit' => Money::pct($r['ebit'], $base),
            'ebt' => Money::pct($r['ebt'], $base),
            'neto' => Money::pct($r['utilidad_neta'], $base),
        ];
    }

    private function waterfall(array $r, bool $consolidated): array
    {
        $steps = [
            ['key' => 'ingresos', 'label' => 'Ingresos operativos', 'amount' => $r['ingresos'], 'type' => 'total', 'level' => 1],
            ['key' => 'opex_sucursales', 'label' => 'OPEX sucursales', 'amount' => $r['opex_sucursales']->negated(), 'type' => 'delta', 'level' => 1],
            ['key' => 'nomina_sucursales', 'label' => 'Nómina sucursales', 'amount' => $r['nomina_sucursales']->negated(), 'type' => 'delta', 'level' => 1],
            ['key' => 'ebitda_sucursales', 'label' => 'EBITDA sucursales', 'amount' => $r['ebitda_sucursales'], 'type' => 'subtotal', 'level' => 1],
        ];
        if ($consolidated) {
            $steps[] = ['key' => 'opex_corporativo', 'label' => 'OPEX corporativo', 'amount' => $r['opex_corporativo']->negated(), 'type' => 'delta', 'level' => 2];
            $steps[] = ['key' => 'nomina_corporativa', 'label' => 'Nómina corporativa', 'amount' => $r['nomina_corporativa']->negated(), 'type' => 'delta', 'level' => 2];
            $steps[] = ['key' => 'ebitda_corporativo', 'label' => 'EBITDA corporativo', 'amount' => $r['ebitda_corporativo'], 'type' => 'subtotal', 'level' => 2];
            $steps[] = ['key' => 'dg', 'label' => 'Gastos DG', 'amount' => $r['dg']->negated(), 'type' => 'delta', 'level' => 3];
            $steps[] = ['key' => 'ebitda_final', 'label' => 'EBITDA final', 'amount' => $r['ebitda_final'], 'type' => 'subtotal', 'level' => 3];
        }
        $steps[] = ['key' => 'depreciacion_amortizacion', 'label' => 'Depreciación y amortización', 'amount' => $r['depreciacion_amortizacion']->negated(), 'type' => 'delta', 'level' => 4];
        $steps[] = ['key' => 'ebit', 'label' => 'EBIT', 'amount' => $r['ebit'], 'type' => 'subtotal', 'level' => 4];
        $steps[] = ['key' => 'resultado_financiero_neto', 'label' => 'Resultado financiero', 'amount' => $r['resultado_financiero_neto']->negated(), 'type' => 'delta', 'level' => 5];
        $steps[] = ['key' => 'ebt', 'label' => 'EBT', 'amount' => $r['ebt'], 'type' => 'subtotal', 'level' => 5];
        $steps[] = ['key' => 'isr', 'label' => 'ISR', 'amount' => $r['isr']->negated(), 'type' => 'delta', 'level' => 6];
        $steps[] = ['key' => 'utilidad_neta', 'label' => 'Utilidad neta', 'amount' => $r['utilidad_neta'], 'type' => 'total', 'level' => 6];

        return array_map(fn ($s) => ['amount' => Money::str2($s['amount'])] + $s, $steps);
    }

    // ───────────────────────────────────────────────────────────────────
    // Conciliación con Radiografía, conciliación bancaria, validaciones
    // ───────────────────────────────────────────────────────────────────

    private function radiographyReconciliation(array $row, array $base, bool $consolidated, BigDecimal $ingresos, BigDecimal $opexRadio, BigDecimal $nominaRadio, BigDecimal $opexComp, BigDecimal $nominaComp, BigDecimal $ebitdaSuc, BigDecimal $radiografiaEbitda): array
    {
        $erBase = $ingresos->minus($opexRadio)->minus($nominaRadio);
        $diffBase = $erBase->minus($radiografiaEbitda);
        $diff = $ebitdaSuc->minus($radiografiaEbitda);

        $explanations = [];
        if (! Money::isZeroCents($opexComp)) {
            $explanations[] = ['label' => 'OPEX complementario de sucursales clasificado manualmente (no existe en Radiografía)', 'amount' => Money::str2($opexComp->negated())];
        }
        if (! Money::isZeroCents($nominaComp)) {
            $explanations[] = ['label' => 'Nómina complementaria de sucursales clasificada manualmente (no existe en Radiografía)', 'amount' => Money::str2($nominaComp->negated())];
        }
        if (! Money::isZeroCents($diffBase)) {
            $explanations[] = ['label' => 'Diferencia NO explicada entre las líneas del ER y el EBITDA canónico de Radiografía', 'amount' => Money::str2($diffBase)];
        }

        $informative = [];
        if ($consolidated) {
            $un = $base['unassigned'];
            $unNomina = Money::of(Radio::nominaTotalFor(array_intersect_key($un, array_flip(['nomina_total', 'comisiones', 'bonos', 'bonos_aceleradores', 'vacaciones', 'prima_vacacional', 'otros_percepciones', 'imss_patronal', 'gastos_empleados_nomina']))));
            if (! $unNomina->isZero()) {
                $informative[] = 'Incluye '.'$'.number_format((float) Money::str2($unNomina), 2).' de nómina de colaboradores sin sucursal operativa: Radiografía los suma al GLOBAL y el ER respeta ese mismo universo.';
            }
        }

        return [
            'er_ebitda_sucursales' => Money::str2($ebitdaSuc),
            'radiografia_ebitda' => Money::str2($radiografiaEbitda),
            'er_ebitda_base_radiografia' => Money::str2($erBase),
            'difference' => Money::str2($diff),
            'difference_base' => Money::str2($diffBase),
            'ok' => Money::isZeroCents($diff),
            'base_ok' => Money::isZeroCents($diffBase),
            'explanations' => $explanations,
            'informative' => $informative,
            'components' => [
                ['label' => 'Ingresos operativos', 'er' => Money::str2($ingresos), 'radiografia' => Money::str2(Radio::ingresoEbitdaBaseFor($row))],
                ['label' => 'OPEX sucursales (Radiografía)', 'er' => Money::str2($opexRadio), 'radiografia' => Money::str2($row['gastos_operativos'] ?? 0)],
                ['label' => 'Nómina y Capital Humano (Radiografía)', 'er' => Money::str2($nominaRadio), 'radiografia' => Money::str2(Radio::nominaTotalFor($row))],
            ],
        ];
    }

    private function conciliationSummary(array $items, array $scoped, bool $consolidated): array
    {
        $bank = array_values(array_filter($items, fn ($i) => $i['source'] === 'banco'));
        $inPeriod = array_values(array_filter($bank, fn ($i) => $i['in_period']));
        $sum = fn (array $list, string $field) => Money::sum(array_column($list, $field));
        $where = fn (array $list, callable $fn) => array_values(array_filter($list, $fn));
        $amount = fn (array $list) => Money::sum(array_map(fn ($i) => Money::of($i['cargo'])->plus(Money::of($i['abono'])), $list));
        $part = fn (array $list, string $key) => Money::sum(array_map(fn ($i) => $i['amounts'][$key], $list));

        $cargosIn = $where($inPeriod, fn ($i) => (float) $i['cargo'] > 0);
        $conciliados = $where($inPeriod, fn ($i) => in_array($i['reconciliation_status'], R::CONCILIADOS, true));
        $nuevos = $where($cargosIn, fn ($i) => in_array($i['reconciliation_status'], [R::FOLIO_SIN_ERP, R::SIN_FOLIO], true));
        $transfers = $where($inPeriod, fn ($i) => $i['treatment'] === T::TRANSFERENCIA_INTERNA);
        $fuera = $where($inPeriod, fn ($i) => T::group($i['treatment']) === 'fuera' && ! in_array($i['treatment'], [T::CONCILIADO_ERP, T::TRANSFERENCIA_INTERNA], true));
        $pend = $where($items, fn ($i) => $i['in_period'] && $i['treatment'] === T::PENDIENTE);
        $includedBank = $where($inPeriod, fn ($i) => $i['impact']['included']);
        $erp = $where($items, fn ($i) => $i['source'] === 'erp');
        $includedErp = $where($erp, fn ($i) => $i['impact']['included']);
        $reversals = $where($inPeriod, fn ($i) => $i['treatment'] === T::REVERSO);
        $refunds = $where($inPeriod, fn ($i) => $i['treatment_source'] === 'reembolso');

        $byTreatment = [];
        foreach ($items as $i) {
            if (! $i['in_period'] && $i['source'] === 'banco') {
                $key = T::FUERA_PERIODO;
            } else {
                $key = $i['treatment'];
            }
            $byTreatment[$key] ??= ['key' => $key, 'label' => T::label($key), 'group' => T::group($key), 'count' => 0, 'cargos' => BigDecimal::zero(), 'abonos' => BigDecimal::zero()];
            $byTreatment[$key]['count']++;
            $byTreatment[$key]['cargos'] = $byTreatment[$key]['cargos']->plus(Money::of($i['cargo']));
            $byTreatment[$key]['abonos'] = $byTreatment[$key]['abonos']->plus(Money::of($i['abono']));
        }
        $byTreatment = array_values(array_map(fn ($t) => ['cargos' => Money::str2($t['cargos']), 'abonos' => Money::str2($t['abonos'])] + $t, $byTreatment));
        usort($byTreatment, fn ($a, $b) => [$a['group'], $a['label']] <=> [$b['group'], $b['label']]);

        $byStatus = [];
        foreach ($bank as $i) {
            $byStatus[$i['reconciliation_status']] = ($byStatus[$i['reconciliation_status']] ?? 0) + 1;
        }
        ksort($byStatus);

        $byMatch = [];
        foreach ($conciliados as $i) {
            $type = $i['match_type'] ?? (R::MATCH_TYPES[$i['reconciliation_status']] ?? '1:1');
            $byMatch[$type] ??= ['key' => $type, 'count' => 0, 'applied' => BigDecimal::zero()];
            $byMatch[$type]['count']++;
            $byMatch[$type]['applied'] = $byMatch[$type]['applied']->plus(Money::of($i['applied']));
        }
        ksort($byMatch);

        return [
            'movimientos_leidos' => count($bank),
            'movimientos_en_periodo' => count($inPeriod),
            'fuera_periodo' => count($bank) - count($inPeriod),
            'total_cargos' => Money::str2($sum($inPeriod, 'cargo')),
            'total_abonos' => Money::str2($sum($inPeriod, 'abono')),
            'candidatos' => count($cargosIn),
            'conciliados_erp' => ['count' => count($conciliados), 'amount' => Money::str2($part($conciliados, 'conciliated'))],
            'nuevos' => ['count' => count($nuevos), 'amount' => Money::str2($amount($nuevos))],
            'transferencias' => ['count' => count($transfers), 'amount' => Money::str2($amount($transfers))],
            'fuera_er' => ['count' => count($fuera), 'amount' => Money::str2($amount($fuera))],
            'pendientes' => ['count' => count($pend), 'amount' => Money::str2($part($pend, 'pending'))],
            'incorporado_bancos' => Money::str2(Money::sum(array_map(fn ($i) => $i['impact']['amount'], $includedBank))),
            'incorporado_erp_fuera_radiografia' => Money::str2(Money::sum(array_map(fn ($i) => $i['impact']['amount'], $includedErp))),
            'erp_fuera_radiografia' => ['count' => count($erp), 'amount' => Money::str2($sum($erp, 'cargo'))],
            'por_tratamiento' => $byTreatment,
            'por_estado' => $byStatus,
            // ── Nuevas claves ─────────────────────────────────────────────
            'por_tipo_emparejamiento' => array_values(array_map(fn ($m) => ['key' => $m['key'], 'count' => $m['count'], 'applied' => Money::str2($m['applied'])], $byMatch)),
            'parciales' => [
                'count' => count($where($conciliados, fn ($i) => $i['reconciliation_status'] === R::PARCIAL)),
                'excedente_pendiente' => Money::str2($part($where($conciliados, fn ($i) => $i['reconciliation_status'] === R::PARCIAL), 'pending')),
            ],
            'erp_saldo_pendiente_pago' => Money::str2(Money::sum(array_map(fn ($i) => $i['erp_payment']['pending'], $where($erp, fn ($i) => $i['erp_payment_status'] === 'erp_pago_parcial')))),
            'reversos' => ['count' => count($reversals), 'amount' => Money::str2($amount($reversals))],
            'reembolsos_vinculados' => ['count' => count($refunds), 'amount' => Money::str2($sum($refunds, 'abono'))],
        ];
    }

    private function checks(array $row, array $income, array $opex, array $nomina, array $statement, array $r, array $items, array $scoped, BigDecimal $ingresos, BigDecimal $opexRadio, BigDecimal $nominaRadio, bool $consolidated, array $categoryTotals = []): array
    {
        $checks = [];
        $add = function (string $key, string $label, BigDecimal $expected, BigDecimal $actual, ?string $explain = null) use (&$checks) {
            $diff = $actual->minus($expected);
            $checks[] = [
                'key' => $key, 'label' => $label, 'expected' => Money::str2($expected), 'actual' => Money::str2($actual),
                'difference' => Money::str2($diff), 'ok' => Money::isZeroCents($diff), 'explain' => $explain,
            ];
        };

        $add('ingresos_componentes', 'Total ingresos = Σ componentes de ingreso', $this->sumLines($income), $r['ingresos']);
        $add('ingresos_radiografia', 'Total ingresos = Ingreso base EBITDA de Radiografía', Money::of(Radio::ingresoEbitdaBaseFor($row)), $ingresos);
        $add('opex_radiografia', 'OPEX sucursales (Σ desglose) = gastos_operativos de Radiografía', Money::of($row['gastos_operativos'] ?? 0), $opexRadio);
        $add('nomina_radiografia', 'Nómina sucursales (Σ desglose) = Nómina y Capital Humano de Radiografía', Money::of(Radio::nominaTotalFor($row)), $nominaRadio);
        $add('ebitda_sucursales_formula', 'EBITDA sucursales = Ingresos − OPEX − Nómina', $r['ingresos']->minus($r['opex_sucursales'])->minus($r['nomina_sucursales']), $r['ebitda_sucursales']);
        $add('ebitda_corporativo_formula', 'EBITDA corporativo = EBITDA sucursales − OPEX corp. − Nómina corp.', $r['ebitda_sucursales']->minus($r['opex_corporativo'])->minus($r['nomina_corporativa']), $r['ebitda_corporativo']);
        $add('ebitda_final_formula', 'EBITDA final = EBITDA corporativo − DG operativo', $r['ebitda_corporativo']->minus($r['dg']), $r['ebitda_final']);
        $ebitdaBase = $consolidated ? $r['ebitda_final'] : $r['ebitda_sucursales'];
        $add('ebit_formula', 'EBIT = EBITDA − Depreciación − Amortización', $ebitdaBase->minus($r['depreciacion_amortizacion']), $r['ebit']);
        $add('ebt_formula', 'EBT = EBIT − Resultado financiero neto', $r['ebit']->minus($r['resultado_financiero_neto']), $r['ebt']);
        $add('utilidad_formula', 'Utilidad neta = EBT − ISR', $r['ebt']->minus($r['isr']), $r['utilidad_neta']);

        foreach ($statement as $block) {
            if ($block['type'] !== 'section') {
                continue;
            }
            $add('seccion_'.$block['key'], 'Total '.$block['title'].' = Σ de sus líneas', Money::sum(array_column($block['lines'], 'amount')), Money::of($block['total']['amount']), 'Comparado a 2 decimales de presentación.');
        }

        // Complementarios incorporados = Σ porción NO conciliada de los movimientos incluidos (la parte conciliada la reconoce el ERP).
        $bankIncluded = array_filter($scoped, fn ($i) => $i['source'] === 'banco' && $i['impact']['included']);
        $fromAllocation = function (array $i): BigDecimal {
            $abono = Money::of($i['abono']);
            $cargoNoConciliado = Money::of($i['amounts']['classifiable'])->minus($abono);

            return $i['treatment'] === T::INGRESO_NO_OPERATIVO ? $abono->minus($cargoNoConciliado) : $cargoNoConciliado->minus($abono);
        };
        $add('complementarios_incorporados', 'Importe incorporado desde complementarios = Σ porción no conciliada de los movimientos incluidos',
            Money::sum(array_map($fromAllocation, $bankIncluded)),
            Money::sum(array_map(fn ($i) => $i['impact']['amount'], $bankIncluded)),
            'Si difiere, alguna parte ya conciliada con el ERP se estaría sumando otra vez.');

        // Cada peso de cada cargo/abono en periodo queda en exactamente un tratamiento (sin exclusiones silenciosas).
        $bank = array_filter($items, fn ($i) => $i['source'] === 'banco');
        $byTreatment = [];
        foreach ($bank as $i) {
            $byTreatment[$i['treatment']] = ($byTreatment[$i['treatment']] ?? BigDecimal::zero())->plus(Money::of($i['cargo']))->plus(Money::of($i['abono']));
        }
        $uploadTotals = BigDecimal::zero();
        foreach ($this->activeUploadTotals as $t) {
            $uploadTotals = $uploadTotals->plus(Money::of($t));
        }
        $add('particion_bancos', 'Σ cargos+abonos importados (archivos) = Σ por tratamiento (nada excluido en silencio)',
            $uploadTotals,
            Money::sum($byTreatment));

        // ── Invariantes de no duplicidad (nuevos) ─────────────────────────
        // Cada importe va a UNA sola categoría exclusiva: Σ impactos de los items = Σ de las líneas que alimentan.
        if ($categoryTotals !== []) {
            $add('exclusividad_categorias', 'Σ importes reconocidos por movimiento = Σ líneas del ER que alimentan (cada peso en una sola categoría)',
                Money::sum(array_map(fn ($i) => $i['impact']['amount'], array_filter($scoped, fn ($i) => $i['impact']['included'] && ($consolidated || in_array($i['treatment'], [T::OPEX_SUCURSAL, T::NOMINA_SUCURSAL, T::GASTO_FINANCIERO, T::INGRESO_NO_OPERATIVO], true))))),
                Money::sum($categoryTotals),
                'Si difiere, algún importe se está contando en dos líneas o en ninguna.');
        }
        $fps = array_column($items, 'fingerprint');
        $dup = count($fps) - count(array_unique($fps));
        $add('huella_unica', 'Cada huella (movimiento/ERP) aparece una sola vez', BigDecimal::zero(), BigDecimal::of($dup), 'Número de huellas repetidas en el dataset.');

        $allGross = Money::sum(array_map(fn ($i) => $i['amounts']['gross'], $items));
        $allParts = Money::sum(array_map(fn ($i) => Money::sum([$i['amounts']['conciliated'], $i['amounts']['recognized'], $i['amounts']['excluded'], $i['amounts']['pending'], $i['amounts']['out_of_period']]), $items));
        $add('particion_total', 'Σ importes de las fuentes = conciliado + reconocido + excluido + pendiente + fuera de periodo', $allGross, $allParts, 'Ningún importe desaparece sin explicación.');

        $add('conciliados_sin_doble_gasto', 'Σ importe aplicado en vínculos ERP = Σ porción conciliada de los movimientos (nunca se reconoce otra vez)',
            Money::of($this->linkedApplied),
            Money::sum(array_map(fn ($i) => $i['amounts']['conciliated'], array_filter($items, fn ($i) => $i['source'] === 'banco'))),
            'La parte conciliada de un pago la reconoce el ERP; el banco nunca crea un segundo gasto.');

        // Clasificación manual contradictoria: un pago conciliado con ERP reclasificado para sumar. No se
        // suma (sería doble conteo), pero tampoco se oculta: queda como diferencia hasta corregirlo.
        $contradictory = array_filter($items, fn ($i) => $i['source'] === 'banco' && $i['override'] !== null && $i['in_period']
            && T::affectsStatement($i['treatment']) && ! Money::isZeroCents($i['amounts']['conciliated']) && Money::isZeroCents($i['amounts']['classifiable']));
        $add('conciliados_reclasificados', 'Pagos conciliados con ERP reclasificados manualmente para sumar (importe que NO se suma otra vez)',
            BigDecimal::zero(),
            Money::sum(array_map(fn ($i) => $i['amounts']['conciliated'], $contradictory)),
            'La parte conciliada ya la reconoce el ERP. Revierta la clasificación manual o revise la conciliación.');

        return $checks;
    }

    /**
     * Calidad financiera del periodo: totales por destino, particiones, diferencias por
     * importe/fecha/folio, diferencia contra Radiografía, integridad de fuentes y
     * eliminaciones internas. Las diferencias se MUESTRAN; nunca se compensan.
     */
    private function validation(Period $period, array $items, array $scoped, array $checks, array $radiography, bool $consolidated): array
    {
        $bank = array_values(array_filter($items, fn ($i) => $i['source'] === 'banco'));
        $erp = array_values(array_filter($items, fn ($i) => $i['source'] === 'erp'));
        $total = fn (array $list, string $key) => Money::str2(Money::sum(array_map(fn ($i) => $i['amounts'][$key], $list)));
        $countWith = fn (array $list, string $key) => count(array_filter($list, fn ($i) => ! Money::isZeroCents($i['amounts'][$key])));
        $bucket = fn (array $list, string $key) => ['count' => $countWith($list, $key), 'amount' => $total($list, $key)];

        $srcCargos = Money::sum(array_column($this->activeUploads, 'read_cargos'));
        $srcAbonos = Money::sum(array_column($this->activeUploads, 'read_abonos'));
        $impCargos = Money::sum(array_column($bank, 'cargo'));
        $impAbonos = Money::sum(array_column($bank, 'abono'));

        $partition = function (array $list) use ($total) {
            $gross = Money::of($total($list, 'gross'));
            $parts = Money::sum([$total($list, 'conciliated'), $total($list, 'recognized'), $total($list, 'excluded'), $total($list, 'pending'), $total($list, 'out_of_period')]);

            return ['total' => Money::str2($gross), 'sum_parts' => Money::str2($parts), 'difference' => Money::str2($parts->minus($gross)), 'ok' => Money::isZeroCents($parts->minus($gross))];
        };

        $diffItem = fn (array $i, ?string $difference = null) => [
            'fingerprint' => $i['fingerprint'], 'date' => $i['date'], 'folio' => $i['folio'], 'concept' => $i['concept'],
            'account_slot' => $i['account_slot'], 'bank_amount' => $i['cargo'],
            'erp_amounts' => array_values(array_map(fn ($e) => $e['amount'], (array) $i['erp'])),
            'difference' => $difference, 'delta_days' => $i['reconciliation_delta_days'] ?? null,
            'status' => $i['reconciliation_status'], 'status_label' => R::LABELS[$i['reconciliation_status']] ?? $i['reconciliation_status'],
            'message' => $i['reconciliation_message'],
        ];
        $byStatus = fn (array $statuses) => array_values(array_filter($bank, fn ($i) => $i['in_period'] && in_array($i['reconciliation_status'], $statuses, true)));
        $group = function (array $list, callable $map) {
            $rows = array_map($map, $list);

            return ['count' => count($rows), 'amount' => Money::str2(Money::sum(array_column($rows, 'bank_amount'))), 'items' => $rows];
        };
        $byAmount = $group($byStatus([R::FOLIO_MONTO_DIFERENTE]), function ($i) use ($diffItem) {
            $erpSum = Money::sum(array_map(fn ($e) => $e['amount'], (array) $i['erp']));

            return $diffItem($i, Money::str2(Money::of($i['cargo'])->minus($erpSum)));
        });
        $byDate = $group($byStatus([R::FOLIO_FECHA_DIFERENTE]), fn ($i) => $diffItem($i));
        $byFolio = $group($byStatus([R::FOLIO_SIN_ERP, R::FOLIO_OTRO_PERIODO, R::FOLIO_YA_CONCILIADO, R::FOLIO_AMBIGUO, R::POSIBLE_DUPLICADO_ERP]), fn ($i) => $diffItem($i) + ['out_of_period' => $i['recognition']['out_of_period']]);

        // ── Eliminaciones internas (consolidación) ────────────────────────
        $inPeriodBank = array_values(array_filter($bank, fn ($i) => $i['in_period']));
        $transfers = array_values(array_filter($inPeriodBank, fn ($i) => $i['treatment'] === T::TRANSFERENCIA_INTERNA));
        $paired = array_values(array_filter($transfers, fn ($i) => $i['transfer_pair'] !== null));
        $unpairedTraspasos = array_values(array_filter($transfers, fn ($i) => $i['transfer_pair'] === null && $i['rule_code'] === 'traspaso_cuentas_propias'));
        $internalFlows = array_values(array_filter($transfers, fn ($i) => $i['rule_code'] !== 'traspaso_cuentas_propias'));
        $gross = fn (array $list) => Money::str2(Money::sum(array_map(fn ($i) => $i['amounts']['gross'], $list)));
        $reversals = array_values(array_filter($inPeriodBank, fn ($i) => $i['treatment'] === T::REVERSO));

        // ── Integridad de fuentes ─────────────────────────────────────────
        $lastRun = EstadoResultadosRun::query()->where('period_id', $period->id)->where('status', 'ok')->latest('id')->first();
        $currentSig = $this->currentErpSignature;
        $uploadsOk = array_reduce($this->activeUploads, fn ($ok, $u) => $ok && $u['control_ok'] !== false, true);
        $importOk = Money::isZeroCents($srcCargos->minus($impCargos)) && Money::isZeroCents($srcAbonos->minus($impAbonos));
        $stale = $lastRun !== null && $currentSig !== null && $lastRun->erp_signature !== $currentSig;

        $partitions = ['bank' => $partition($bank), 'erp' => $partition($erp), 'all' => $partition($items)];
        $failedChecks = array_values(array_map(fn ($c) => ['key' => $c['key'], 'label' => $c['label'], 'difference' => $c['difference']], array_filter($checks, fn ($c) => ! $c['ok'])));

        $unresolved = [];
        $addUnresolved = function (string $key, string $label, int $count, string $amount) use (&$unresolved) {
            if ($count > 0 || ! Money::isZeroCents($amount)) {
                $unresolved[] = ['key' => $key, 'label' => $label, 'count' => $count, 'amount' => $amount];
            }
        };
        $pendingItems = array_values(array_filter($items, fn ($i) => ! Money::isZeroCents($i['amounts']['pending'])));
        $addUnresolved('pendientes', 'Movimientos pendientes de clasificar (no suman ni se excluyen)', count($pendingItems), $total($pendingItems, 'pending'));
        $addUnresolved('diferencia_importe', 'Mismo folio con importe diferente al ERP', $byAmount['count'], $byAmount['amount']);
        $addUnresolved('diferencia_fecha', 'Mismo folio e importe con fecha fuera de tolerancia', $byDate['count'], $byDate['amount']);
        $addUnresolved('diferencia_folio', 'Folio sin ERP, de otro periodo, ya conciliado o ambiguo', $byFolio['count'], $byFolio['amount']);
        $erpPartial = array_values(array_filter($erp, fn ($i) => $i['erp_payment_status'] === 'erp_pago_parcial'));
        $addUnresolved('erp_saldo_pendiente', 'Saldo ERP sin pago bancario identificado (pagos parciales)', count($erpPartial), Money::str2(Money::sum(array_map(fn ($i) => $i['erp_payment']['pending'], $erpPartial))));
        $addUnresolved('traspasos_sin_contraparte', 'Traspasos entre cuentas sin contraparte en la otra cuenta', count($unpairedTraspasos), $gross($unpairedTraspasos));
        if (! ($radiography['base_ok'] ?? true)) {
            $addUnresolved('radiografia_no_explicada', 'Diferencia NO explicada contra Radiografía', 1, (string) $radiography['difference_base']);
        }
        foreach ($failedChecks as $fc) {
            $addUnresolved('control_'.$fc['key'], 'Validación con diferencia: '.$fc['label'], 1, $fc['difference']);
        }

        $noi = $this->noiData;
        $noiLinked = array_values(array_filter($bank, fn ($i) => $i['noi_employee_id'] !== null));

        return [
            'status' => $failedChecks === [] && $partitions['all']['ok'] && $importOk ? ($unresolved === [] ? 'ok' : 'con_pendientes') : 'con_diferencias',
            'scope_note' => $consolidated ? null : 'Las validaciones de partición cubren todos los movimientos del periodo (no sólo los de la sucursal).',
            'criterion' => Dimensions::criterion(),
            'totals' => [
                'source' => ['count' => array_sum(array_column($this->activeUploads, 'movements')), 'cargos' => Money::str2($srcCargos), 'abonos' => Money::str2($srcAbonos), 'total' => Money::str2($srcCargos->plus($srcAbonos))],
                'imported' => ['count' => count($bank), 'cargos' => Money::str2($impCargos), 'abonos' => Money::str2($impAbonos), 'total' => Money::str2($impCargos->plus($impAbonos))],
                'erp_outside' => ['count' => count($erp), 'total' => $total($erp, 'gross')],
                'conciliated' => $bucket($items, 'conciliated'),
                'recognized' => $bucket($items, 'recognized'),
                'excluded_justified' => $bucket($items, 'excluded'),
                'pending' => $bucket($items, 'pending'),
                'out_of_period' => $bucket($items, 'out_of_period'),
            ],
            'partition' => $partitions,
            'differences' => ['by_amount' => $byAmount, 'by_date' => $byDate, 'by_folio' => $byFolio],
            'radiography' => [
                'er' => $radiography['er_ebitda_sucursales'], 'radiografia' => $radiography['radiografia_ebitda'],
                'difference' => $radiography['difference'], 'base_difference' => $radiography['difference_base'],
                'ok' => $radiography['ok'], 'base_ok' => $radiography['base_ok'], 'explained' => $radiography['explanations'],
            ],
            'integrity' => [
                'status' => $uploadsOk && $importOk && ! $stale ? 'ok' : 'con_incidencias',
                'import_matches_source' => $importOk,
                'uploads' => $this->activeUploads,
                'erp_signature' => $currentSig,
                'erp_signature_last_run' => $lastRun?->erp_signature,
                'erp_stale' => $stale,
                'input_signature_last_run' => $lastRun?->input_signature,
                'rules_version_last_run' => $lastRun?->rules_version,
                'recognition_version_last_run' => $lastRun?->recognition_version,
            ],
            'eliminations' => [
                'transfers' => ['pairs' => count(array_unique(array_column($paired, 'transfer_pair'))), 'count' => count($paired), 'amount' => $gross($paired)],
                'unpaired_transfers' => ['count' => count($unpairedTraspasos), 'amount' => $gross($unpairedTraspasos), 'items' => array_map(fn ($i) => ['fingerprint' => $i['fingerprint'], 'date' => $i['date'], 'account_slot' => $i['account_slot'], 'cargo' => $i['cargo'], 'abono' => $i['abono'], 'concept' => $i['concept']], $unpairedTraspasos)],
                'internal_flows' => ['count' => count($internalFlows), 'amount' => $gross($internalFlows)],
                'reversals' => ['pairs' => count(array_unique(array_filter(array_column($reversals, 'reversal_pair')))), 'count' => count($reversals), 'amount' => $gross($reversals)],
                'corporate_allocation' => 'Sin asignación automática de costos corporativos a sucursales: Corporativo y DG sólo se presentan en el consolidado.',
            ],
            'noi' => [
                'employees' => $noi['employees_count'] ?? 0,
                'total' => $noi['total'] ?? '0.00',
                'unassigned_total' => $noi['unassigned_total'] ?? '0.00',
                'unassigned_employees' => $noi['unassigned_employees'] ?? 0,
                'linked_payments' => ['count' => count($noiLinked), 'amount' => Money::str2(Money::sum(array_column($noiLinked, 'cargo')))],
                'recognizing_rule' => 'La nómina de un colaborador NOI la reconoce el NOI (Nómina sucursales, vía Radiografía). El pago bancario y la requisición ERP que lo nombran son evidencia; nunca un segundo gasto.',
            ],
            'unresolved' => $unresolved,
            'failed_checks' => $failedChecks,
        ];
    }

    private function outside(array $items, array $adj): array
    {
        $groups = [];
        foreach ($items as $i) {
            $t = (! $i['in_period']) ? T::FUERA_PERIODO : $i['treatment'];
            if (T::group($t) !== 'fuera' && $t !== T::PENDIENTE) {
                continue;
            }
            $groups[$t] ??= ['key' => $t, 'label' => T::label($t), 'explain' => T::explain($t), 'count' => 0, 'cargos' => BigDecimal::zero(), 'abonos' => BigDecimal::zero()];
            $groups[$t]['count']++;
            $groups[$t]['cargos'] = $groups[$t]['cargos']->plus(Money::of($i['cargo']));
            $groups[$t]['abonos'] = $groups[$t]['abonos']->plus(Money::of($i['abono']));
        }
        $order = [T::CAPEX, T::CAPITAL_DEUDA, T::TRANSFERENCIA_INTERNA, T::PERSONAL_SOCIOS, T::RECUPERABLE, T::BALANCE, T::PUENTE_TERCEROS, T::CUBIERTO_RADIOGRAFIA, T::CONCILIADO_ERP, T::DUPLICADO, T::FUERA_PERIODO, T::PENDIENTE];
        $out = [];
        foreach ($order as $k) {
            if (isset($groups[$k])) {
                $g = $groups[$k];
                $out[] = ['cargos' => Money::str2($g['cargos']), 'abonos' => Money::str2($g['abonos'])] + $g;
            }
        }

        return [
            'groups' => $out,
            'manual_flows' => [
                ['key' => 'capex', 'label' => $adj['capex']['label'], 'state' => $adj['capex']['state'], 'amount' => $adj['capex']['amount'], 'effect' => $adj['capex']['effect']],
                ['key' => 'capital_deuda', 'label' => $adj['capital_deuda']['label'], 'state' => $adj['capital_deuda']['state'], 'amount' => $adj['capital_deuda']['amount'], 'effect' => $adj['capital_deuda']['effect']],
            ],
        ];
    }

    private function indicators(array $row, array $base, array $r, bool $consolidated, string $scope): array
    {
        $ing = $r['ingresos'];
        $opexTotal = $r['opex_sucursales']->plus($r['opex_corporativo']);
        $nominaTotal = $r['nomina_sucursales']->plus($r['nomina_corporativa']);
        $cartera = $consolidated ? Money::of($base['cartera']['valor_cartera'] ?? 0) : Money::of($row['valor_cartera'] ?? 0);
        $mora = $consolidated
            ? Money::of($base['cartera']['cartera_vencida'] ?? 0)
            : Money::sum([$row['mora_0_30'] ?? 0, $row['mora_31_60'] ?? 0, $row['mora_61_90'] ?? 0, $row['mora_91_120'] ?? 0, $row['mora_120_plus'] ?? 0]);
        $rot = $base['rotacion'];
        $rotScope = $consolidated ? $rot : ($rot['por_sucursal'][mb_strtoupper($scope)] ?? null);

        return [
            'financial' => [
                ['key' => 'margen_ebitda_sucursales', 'label' => 'Margen EBITDA sucursales', 'value' => Money::pct($r['ebitda_sucursales'], $ing), 'unit' => '%'],
                ['key' => 'margen_ebitda_corporativo', 'label' => 'Margen EBITDA corporativo', 'value' => $consolidated ? Money::pct($r['ebitda_corporativo'], $ing) : null, 'unit' => '%'],
                ['key' => 'margen_ebitda_final', 'label' => 'Margen EBITDA final', 'value' => $consolidated ? Money::pct($r['ebitda_final'], $ing) : null, 'unit' => '%'],
                ['key' => 'margen_ebit', 'label' => 'Margen EBIT', 'value' => Money::pct($r['ebit'], $ing), 'unit' => '%'],
                ['key' => 'margen_ebt', 'label' => 'Margen antes de impuestos', 'value' => Money::pct($r['ebt'], $ing), 'unit' => '%'],
                ['key' => 'margen_neto', 'label' => 'Margen neto', 'value' => Money::pct($r['utilidad_neta'], $ing), 'unit' => '%'],
                ['key' => 'opex_ingreso', 'label' => 'OPEX / ingreso', 'value' => Money::pct($opexTotal, $ing), 'unit' => '%'],
                ['key' => 'nomina_ingreso', 'label' => 'Nómina / ingreso', 'value' => Money::pct($nominaTotal, $ing), 'unit' => '%'],
                ['key' => 'corporativo_ingreso', 'label' => 'Corporativo / ingreso', 'value' => $consolidated ? Money::pct($r['opex_corporativo']->plus($r['nomina_corporativa']), $ing) : null, 'unit' => '%'],
                ['key' => 'dg_ingreso', 'label' => 'DG / ingreso', 'value' => $consolidated ? Money::pct($r['dg'], $ing) : null, 'unit' => '%'],
            ],
            // Informativos: NO entran a la fórmula del Estado de Resultados.
            'informative' => [
                ['key' => 'cartera', 'label' => 'Valor de cartera', 'value' => Money::str2($cartera), 'unit' => 'MXN'],
                ['key' => 'mora', 'label' => 'Cartera vencida (mora)', 'value' => Money::str2($mora), 'unit' => 'MXN'],
                ['key' => 'mora_pct', 'label' => 'Mora % sobre cartera', 'value' => Money::pct($mora, $cartera), 'unit' => '%'],
                ['key' => 'colocacion', 'label' => 'Colocación', 'value' => Money::str2($row['colocacion'] ?? 0), 'unit' => 'MXN'],
                ['key' => 'creditos_colocados', 'label' => 'Créditos colocados', 'value' => (string) (int) ($row['creditos_colocados'] ?? 0), 'unit' => 'n'],
                ['key' => 'recuperacion', 'label' => 'Recuperación total', 'value' => Money::str2($row['recuperacion_total'] ?? 0), 'unit' => 'MXN'],
                ['key' => 'capital_recuperado', 'label' => 'Capital recuperado (no es ingreso)', 'value' => Money::str2($row['capital_recuperado'] ?? 0), 'unit' => 'MXN'],
                ['key' => 'excedentes', 'label' => 'Excedentes enviados a corporativo', 'value' => Money::str2($row['excedentes'] ?? 0), 'unit' => 'MXN'],
                ['key' => 'fondeo', 'label' => 'Préstamos intersucursales', 'value' => Money::str2($row['prestamos_fondea'] ?? 0), 'unit' => 'MXN'],
                ['key' => 'plantilla', 'label' => 'Plantilla (promedio)', 'value' => $rotScope ? (string) round((float) $rotScope['plantilla'], 2) : null, 'unit' => 'n'],
                ['key' => 'altas_bajas', 'label' => 'Altas / bajas', 'value' => $rotScope ? $rotScope['altas'].' / '.$rotScope['bajas'] : null, 'unit' => 'txt'],
                ['key' => 'rotacion', 'label' => 'Índice de rotación', 'value' => $rotScope && (float) $rotScope['plantilla'] > 0 ? (string) round($rotScope['bajas'] / $rotScope['plantilla'] * 100, 2) : null, 'unit' => '%'],
            ],
        ];
    }

    private function sources(Period $period, array $base, array $items, array $adj): array
    {
        $dataIds = $base['data_ids'];
        $erpSource = DB::table('data_sources')->where('code', 'gastos_erp')->value('id');
        $erpCount = $erpSource ? DB::table('fact_expenses as e')->join('report_uploads as ru', 'ru.id', '=', 'e.report_upload_id')
            ->whereIn('e.period_id', $dataIds)->where('ru.data_source_id', $erpSource)->count() : 0;
        $noiCount = DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)->count();
        $imssCount = DB::table('fact_imss')->whereIn('period_id', $dataIds)->count();
        $summary = DB::table('period_summaries')->where('period_id', $period->id)->where('status', 'generated')->whereNull('invalidated_at')->first();
        $lastRun = EstadoResultadosRun::query()->with('user:id,name')->where('period_id', $period->id)->latest('id')->first();
        $currentSig = ErpExpenseRepository::signature($this->erp->forDataIds($dataIds));

        $uploads = EstadoResultadosUpload::query()->with('uploader:id,name')->where('period_id', $period->id)->orderByDesc('id')->get();
        $slots = [];
        foreach (EstadoResultadosUploadService::SLOTS as $slot => $label) {
            $active = $uploads->first(fn ($u) => $u->account_slot === $slot && $u->status !== EstadoResultadosUpload::STATUS_REPLACED);
            $pendingReview = count(array_filter($items, fn ($i) => $i['account_slot'] === $slot && $i['in_period'] && $i['treatment'] === T::PENDIENTE));
            $status = match (true) {
                ! $active => 'pendiente',
                $active->status === EstadoResultadosUpload::STATUS_LOADED => 'cargado',
                $active->control_ok === false || $pendingReview > 0 => 'con_incidencias',
                default => 'procesado',
            };
            $slots[] = [
                'key' => $slot,
                'label' => $label,
                'status' => $status,
                'upload' => $active ? [
                    'id' => $active->id, 'name' => $active->original_name, 'account' => $active->account_label, 'hash' => $active->file_hash,
                    'movements' => $active->movements_count, 'detail_lines' => $active->detail_lines_count, 'out_of_period' => $active->out_of_period_count,
                    'total_cargos' => Money::str2($active->total_cargos), 'total_abonos' => Money::str2($active->total_abonos),
                    'control_ok' => $active->control_ok, 'warnings' => $active->warnings ?? [],
                    'first_date' => $active->first_movement_date?->format('Y-m-d'), 'last_date' => $active->last_movement_date?->format('Y-m-d'),
                    'uploaded_by' => $active->uploader?->name, 'uploaded_at' => $active->created_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
                ] : null,
                'pending_review' => $pendingReview,
                'history' => $uploads->where('account_slot', $slot)->values()->map(fn ($u) => [
                    'id' => $u->id, 'name' => $u->original_name, 'status' => $u->status, 'hash' => substr($u->file_hash, 0, 12),
                    'movements' => $u->movements_count, 'uploaded_by' => $u->uploader?->name,
                    'uploaded_at' => $u->created_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
                ])->all(),
            ];
        }

        $adjCounts = ['capturado' => 0, 'confirmado_cero' => 0, 'sin_informacion' => 0];
        foreach ($adj as $a) {
            $adjCounts[$a['state']]++;
        }
        $hasBank = $uploads->contains(fn ($u) => $u->status !== EstadoResultadosUpload::STATUS_REPLACED);
        $stale = $lastRun && $lastRun->status === 'ok' && $lastRun->erp_signature !== $currentSig;
        $unprocessed = EstadoResultadosMovement::query()->where('period_id', $period->id)->whereNull('processed_at')->exists();

        return [
            'radiografia' => [
                'label' => 'Radiografía (base EBITDA sucursales)',
                'status' => $summary ? 'procesado' : 'con_incidencias',
                'detail' => $summary ? 'Radiografía generada; el nivel 1 usa sus mismas reglas.' : 'La Radiografía del periodo no está generada; el nivel 1 se calcula en vivo con las mismas reglas.',
            ],
            'erp' => [
                'label' => 'ERP de gastos',
                'status' => $erpCount > 0 ? ($stale ? 'con_incidencias' : 'procesado') : 'pendiente',
                'detail' => $erpCount > 0 ? "{$erpCount} requisiciones del periodo".($stale ? ' · el ERP cambió después del último procesamiento: vuelva a procesar.' : '') : 'Sin ERP cargado para el periodo (se carga en Carga de archivos).',
                'count' => $erpCount,
            ],
            'nomina' => [
                'label' => 'Nómina (NOI + IMSS)',
                'status' => $noiCount > 0 ? 'procesado' : 'pendiente',
                'detail' => $noiCount > 0 ? "{$noiCount} movimientos NOI · {$imssCount} registros IMSS" : 'Sin NOI cargado para el periodo.',
                'count' => $noiCount,
            ],
            'cuentas' => $slots,
            'ajustes' => [
                'label' => 'Ajustes contables',
                'status' => $adjCounts['sin_informacion'] === 0 ? 'procesado' : ($adjCounts['capturado'] + $adjCounts['confirmado_cero'] > 0 ? 'con_incidencias' : 'pendiente'),
                'detail' => "{$adjCounts['capturado']} capturados · {$adjCounts['confirmado_cero']} confirmados en $0 · {$adjCounts['sin_informacion']} sin información",
                'counts' => $adjCounts,
            ],
            'procesamiento' => [
                'label' => 'Conciliación y clasificación',
                'status' => ! $hasBank ? 'pendiente' : ($unprocessed || ! $lastRun ? 'cargado' : ($stale || $lastRun->status !== 'ok' ? 'con_incidencias' : 'procesado')),
                'last_run' => $lastRun ? [
                    'status' => $lastRun->status, 'user' => $lastRun->user?->name,
                    'at' => $lastRun->finished_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
                    'summary' => $lastRun->summary, 'error' => $lastRun->error, 'rules_version' => $lastRun->rules_version,
                ] : null,
                'stale_erp' => $stale,
                'unprocessed' => $unprocessed,
            ],
            'exportaciones' => EstadoResultadosExport::query()->with('user:id,name')
                ->where('period_id', $period->id)->latest('id')->limit(15)->get()
                ->map(fn ($e) => [
                    'format' => $e->format, 'scope' => $e->scope_key, 'hash' => substr($e->dataset_hash, 0, 12),
                    'user' => $e->user?->name, 'at' => $e->created_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
                ])->all(),
        ];
    }

    private function publicItem(array $i): array
    {
        $i['reconciliation_label'] = R::LABELS[$i['reconciliation_status']] ?? match ($i['reconciliation_status']) {
            'erp' => 'Registro ERP',
            'erp_con_pago_bancario' => 'Registro ERP con pago bancario',
            'sin_procesar' => 'Sin procesar',
            default => $i['reconciliation_status'],
        };
        $i['treatment_label'] = T::label($i['treatment']);
        $i['treatment_group'] = T::group($i['treatment']);
        $i['treatment_explain'] = T::explain($i['treatment']);
        $i['suggested_label'] = $i['suggested_treatment'] ? T::label($i['suggested_treatment']) : null;

        $rec = $i['recognition'];
        $i['dimensions'] = Dimensions::present($i['dimensions_raw'], [
            'key' => $rec['out_of_period'] ?? 'en_periodo',
            'label' => $rec['out_of_period'] ? Dimensions::OUT_OF_PERIOD[$rec['out_of_period']] : 'Se reconoce en este periodo',
            'period_id' => $rec['period_id'],
            'period_code' => $rec['period_code'],
            'basis' => $rec['basis'],
            'basis_label' => Dimensions::BASIS[$rec['basis']],
            'out_of_period' => $rec['out_of_period'],
            'out_of_period_label' => $rec['out_of_period'] ? Dimensions::OUT_OF_PERIOD[$rec['out_of_period']] : null,
        ]);
        unset($i['dimensions_raw'], $i['recognizing_hint'], $i['recognition'], $i['import_status']);

        return $i;
    }

    /** Hash de TODAS las cifras del dataset (excluye la hora de generación). */
    private function hash(array $dataset): string
    {
        $v = $dataset['validation'];
        $payload = [
            'period' => $dataset['meta']['period'],
            'scope' => $dataset['meta']['scope']['key'],
            'recognition' => [$dataset['meta']['recognition']['version'], $dataset['meta']['dimensions_version']],
            'results' => $dataset['results'],
            'statement' => $dataset['statement'],
            'checks' => array_map(fn ($c) => [$c['key'], $c['actual'], $c['expected']], $dataset['checks']),
            'conciliation' => array_diff_key($dataset['conciliation'], ['por_estado' => true]),
            'movements' => array_map(fn ($m) => [
                $m['fingerprint'], $m['treatment'], $m['impact']['amount'], $m['dimensions']['inclusion']['key'],
                $m['dimensions']['category']['key'], $m['amounts']['conciliated'], $m['amounts']['pending'],
            ], $dataset['movements']),
            'adjustments' => array_map(fn ($a) => [$a['key'], $a['state'], $a['amount']], $dataset['adjustments']),
            'validation' => [
                $v['status'], $v['totals'], $v['partition'],
                array_map(fn ($d) => [$d['count'], $d['amount']], $v['differences']),
                $v['radiography'], $v['integrity']['status'], $v['eliminations']['transfers'], $v['eliminations']['reversals'],
                $v['unresolved'],
            ],
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
