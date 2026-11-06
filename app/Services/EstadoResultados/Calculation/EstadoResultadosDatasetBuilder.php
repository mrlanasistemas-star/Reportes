<?php

namespace App\Services\EstadoResultados\Calculation;

use App\Models\EstadoResultados\EstadoResultadosExport;
use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\EstadoResultados\EstadoResultadosRun;
use App\Models\EstadoResultados\EstadoResultadosUpload;
use App\Models\Period;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\Classification\ClassificationService;
use App\Services\EstadoResultados\Classification\ErpOutsideClassifier;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use App\Services\EstadoResultados\Reconciliation\ErpExpenseRepository;
use App\Services\EstadoResultados\Reconciliation\EstadoResultadosExpenseReconciler as R;
use App\Services\EstadoResultados\Support\Money;
use App\Services\EstadoResultados\Support\Treatment as T;
use App\Services\Radiography\BranchRadiographyCalculator as Radio;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

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

    public function __construct(
        private readonly RadiographyBaseProvider $base,
        private readonly ErpExpenseRepository $erp,
        private readonly ErpOutsideClassifier $erpClassifier,
        private readonly ClassificationService $classifications,
        private readonly AdjustmentService $adjustments,
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
        $finExpense = [
            $this->adjLine($adj['intereses_bbva']),
            $this->adjLine($adj['intereses_lerom']),
            $this->adjLine($adj['otros_intereses']),
            $this->adjLine($adj['otros_gastos_financieros']),
            $this->line('fin_bancos', 'Gastos financieros clasificados desde bancos', $this->sumTreatment($scoped, [T::GASTO_FINANCIERO]), 'Estados de cuenta · clasificación'),
        ];
        $finIncome = [
            $this->adjLine($adj['ingresos_financieros'], '(+) '),
            $this->line('ing_no_operativo_bancos', '(+) Ingresos no operativos clasificados desde bancos', $this->sumTreatment($scoped, [T::INGRESO_NO_OPERATIVO]), 'Estados de cuenta · clasificación'),
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

        $statement = $this->statement($consolidated, $incomeLines, $opexLines, $nominaLines, $corpLines, $nomCorpLines, $dgLines, $daLines, $finExpense, $finIncome, $isrLines, $results, $completeness);

        $radiografiaEbitda = Money::of(Radio::ebitdaFinalFor($row));
        $reconciliation = $this->radiographyReconciliation($row, $base, $consolidated, $ingresos, $opexRadio, $nominaRadio, $opexComp, $nominaComp, $ebitdaSuc, $radiografiaEbitda);

        $conciliation = $this->conciliationSummary($items, $scoped, $consolidated);
        $checks = $this->checks($row, $incomeLines, $opexLines, $nominaLines, $statement, $results, $items, $scoped, $ingresos, $opexRadio, $nominaRadio, $consolidated);

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

        $uploads = EstadoResultadosUpload::query()->active()->where('period_id', $period->id)->get()->keyBy('id');
        $this->activeUploadTotals = $uploads->map(fn ($u) => (string) Money::of($u->total_cargos)->plus(Money::of($u->total_abonos)))->values()->all();
        $movements = EstadoResultadosMovement::query()
            ->where('period_id', $period->id)
            ->whereIn('upload_id', $uploads->keys()->all() ?: [0])
            ->orderBy('movement_date')->orderBy('account_slot')->orderBy('source_row')
            ->get();

        $items = [];
        $linksToErp = [];
        foreach ($movements as $m) {
            $processed = $m->processed_at !== null;
            $auto = $processed ? (string) $m->auto_treatment : T::PENDIENTE;
            $ov = $overrides[$m->fingerprint] ?? null;
            $treatment = $ov['treatment'] ?? $auto;
            $erpMatches = (array) ($m->reconciliation_detail['erp'] ?? []);
            if ($auto === T::CONCILIADO_ERP) {
                foreach ($erpMatches as $em) {
                    $linksToErp[$em['fingerprint']][] = ['row' => $m->source_row, 'account' => $m->account_slot, 'amount' => Money::str2($m->cargo), 'date' => $m->movement_date->format('Y-m-d')];
                }
            }
            $items[] = [
                'fingerprint' => $m->fingerprint,
                'source' => 'banco',
                'account_slot' => $m->account_slot,
                'account_label' => $uploads[$m->upload_id]->account_label ?? null,
                'file' => $uploads[$m->upload_id]->original_name ?? null,
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
            ];
        }

        foreach ($erpRows as $e) {
            if ($e['universe'] === 'radiografia') {
                continue; // ya dentro del EBITDA de sucursales
            }
            $c = $this->erpClassifier->classify($e);
            $ov = $overrides[$e['fingerprint']] ?? null;
            $treatment = $ov['treatment'] ?? $c['treatment'];
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
                'reconciliation_status' => isset($linksToErp[$e['fingerprint']]) ? 'erp_con_pago_bancario' : 'erp',
                'reconciliation_message' => isset($linksToErp[$e['fingerprint']])
                    ? 'Registro ERP conciliado con '.count($linksToErp[$e['fingerprint']]).' pago(s) bancario(s): se cuenta una sola vez, desde aquí.'
                    : 'Registro ERP sin pago bancario identificado en los archivos complementarios.',
                'auto_treatment' => $c['treatment'],
                'suggested_treatment' => $c['suggested'],
                'rule_code' => $c['rule'],
                'rule_reason' => $c['reason'],
                'treatment' => $treatment,
                'override' => $ov,
                'needs_review' => $treatment === T::PENDIENTE,
                'transfer_pair' => null,
                'detail_lines' => 0,
                'linked_bank' => $linksToErp[$e['fingerprint']] ?? [],
                'erp_universe' => $e['universe'],
            ];
        }

        foreach ($items as &$i) {
            $i['impact'] = $this->impact($i);
        }
        unset($i);

        return $items;
    }

    /** Importe con el que un item afecta su línea (gasto: cargo − abono; ingreso: abono − cargo). */
    private function signedAmount(array $item): BigDecimal
    {
        $cargo = Money::of($item['cargo']);
        $abono = Money::of($item['abono']);

        return $item['treatment'] === T::INGRESO_NO_OPERATIVO ? $abono->minus($cargo) : $cargo->minus($abono);
    }

    private function impact(array $item): array
    {
        $t = $item['treatment'];
        $line = T::line($t);
        if ($line !== null && $item['in_period']) {
            return [
                'included' => true,
                'line' => $line,
                'amount' => Money::str2($this->signedAmount($item)),
                'text' => 'Suma a: '.T::label($t),
            ];
        }

        return [
            'included' => false,
            'line' => null,
            'amount' => '0.00',
            'text' => 'No suma: '.T::label($t),
        ];
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

        $cargosIn = $where($inPeriod, fn ($i) => (float) $i['cargo'] > 0);
        $conciliados = $where($inPeriod, fn ($i) => in_array($i['reconciliation_status'], R::CONCILIADOS, true));
        $nuevos = $where($cargosIn, fn ($i) => in_array($i['reconciliation_status'], [R::FOLIO_SIN_ERP, R::SIN_FOLIO], true));
        $transfers = $where($inPeriod, fn ($i) => $i['treatment'] === T::TRANSFERENCIA_INTERNA);
        $fuera = $where($inPeriod, fn ($i) => T::group($i['treatment']) === 'fuera' && ! in_array($i['treatment'], [T::CONCILIADO_ERP, T::TRANSFERENCIA_INTERNA], true));
        $pend = $where($items, fn ($i) => $i['in_period'] && $i['treatment'] === T::PENDIENTE);
        $includedBank = $where($inPeriod, fn ($i) => $i['impact']['included']);
        $erp = $where($items, fn ($i) => $i['source'] === 'erp');
        $includedErp = $where($erp, fn ($i) => $i['impact']['included']);

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

        return [
            'movimientos_leidos' => count($bank),
            'movimientos_en_periodo' => count($inPeriod),
            'fuera_periodo' => count($bank) - count($inPeriod),
            'total_cargos' => Money::str2($sum($inPeriod, 'cargo')),
            'total_abonos' => Money::str2($sum($inPeriod, 'abono')),
            'candidatos' => count($cargosIn),
            'conciliados_erp' => ['count' => count($conciliados), 'amount' => Money::str2($amount($conciliados))],
            'nuevos' => ['count' => count($nuevos), 'amount' => Money::str2($amount($nuevos))],
            'transferencias' => ['count' => count($transfers), 'amount' => Money::str2($amount($transfers))],
            'fuera_er' => ['count' => count($fuera), 'amount' => Money::str2($amount($fuera))],
            'pendientes' => ['count' => count($pend), 'amount' => Money::str2($amount($pend))],
            'incorporado_bancos' => Money::str2(Money::sum(array_map(fn ($i) => $i['impact']['amount'], $includedBank))),
            'incorporado_erp_fuera_radiografia' => Money::str2(Money::sum(array_map(fn ($i) => $i['impact']['amount'], $includedErp))),
            'erp_fuera_radiografia' => ['count' => count($erp), 'amount' => Money::str2($sum($erp, 'cargo'))],
            'por_tratamiento' => $byTreatment,
            'por_estado' => $byStatus,
        ];
    }

    private function checks(array $row, array $income, array $opex, array $nomina, array $statement, array $r, array $items, array $scoped, BigDecimal $ingresos, BigDecimal $opexRadio, BigDecimal $nominaRadio, bool $consolidated): array
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

        // Complementarios incorporados = Σ items incluidos que NO están conciliados con ERP.
        $bankIncluded = array_filter($scoped, fn ($i) => $i['source'] === 'banco' && $i['impact']['included']);
        $bankIncludedNotConciliated = array_filter($bankIncluded, fn ($i) => ! in_array($i['reconciliation_status'], R::CONCILIADOS, true));
        $add('complementarios_incorporados', 'Importe incorporado desde complementarios = Σ movimientos incluidos no conciliados con ERP',
            Money::sum(array_map(fn ($i) => $i['impact']['amount'], $bankIncludedNotConciliated)),
            Money::sum(array_map(fn ($i) => $i['impact']['amount'], $bankIncluded)),
            'Si difiere, hay movimientos conciliados con ERP que un usuario reclasificó para sumar: revise que no se dupliquen.');

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

        return $checks;
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
        $i['treatment_source'] = $i['override'] ? 'manual' : 'regla';

        return $i;
    }

    /** Hash de TODAS las cifras del dataset (excluye la hora de generación). */
    private function hash(array $dataset): string
    {
        $payload = [
            'period' => $dataset['meta']['period'],
            'scope' => $dataset['meta']['scope']['key'],
            'results' => $dataset['results'],
            'statement' => $dataset['statement'],
            'checks' => array_map(fn ($c) => [$c['key'], $c['actual'], $c['expected']], $dataset['checks']),
            'conciliation' => array_diff_key($dataset['conciliation'], ['por_estado' => true]),
            'movements' => array_map(fn ($m) => [$m['fingerprint'], $m['treatment'], $m['impact']['amount']], $dataset['movements']),
            'adjustments' => array_map(fn ($a) => [$a['key'], $a['state'], $a['amount']], $dataset['adjustments']),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
