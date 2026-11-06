<?php

namespace App\Services\EstadoResultados\Comparatives;

use App\Models\Period;
use App\Models\User;
use App\Services\Access\DataScopeService;
use App\Services\EstadoResultados\Reporting\EstadoResultadosAbilities;
use App\Services\EstadoResultados\Support\Money;
use Brick\Math\BigDecimal;

/**
 * FUENTE ÚNICA de las comparativas del Estado de Resultados: web (gráficas), PDF y las
 * gráficas nativas del Excel leen ESTE payload (contrato `er-comparativas/1`, documentado
 * en el reporte de entrega y en ComparisonService::CONTRACT).
 *
 * Toda cifra viene del cálculo central (EstadoResultadosReportResolver): snapshot aprobado
 * para periodos aprobados, cálculo en vivo para los demás (cada periodo declara su origen).
 * Aquí sólo se comparan, suman (acumulado) y ordenan cifras ya calculadas.
 *
 * Modos: anterior · anio_anterior · evolucion · acumulado · sucursales · personalizado.
 */
class ComparisonService
{
    public const CONTRACT_VERSION = 'er-comparativas/1';

    public const MODES = ['anterior', 'anio_anterior', 'evolucion', 'acumulado', 'sucursales', 'personalizado'];

    public const DEFAULT_MODES = ['anterior', 'anio_anterior', 'evolucion', 'acumulado', 'sucursales'];

    public const MODE_LABELS = [
        'anterior' => 'Periodo actual vs periodo anterior',
        'anio_anterior' => 'Periodo actual vs mismo periodo del año anterior',
        'evolucion' => 'Evolución mensual',
        'acumulado' => 'Acumulado anual',
        'sucursales' => 'Comparativo por sucursal',
        'personalizado' => 'Comparación personalizada',
    ];

    public const BRANCH_SORTS = [
        'ingresos_operativos' => 'Ingresos operativos',
        'opex_total' => 'Gasto directo (OPEX sucursal)',
        'ebitda' => 'EBITDA sucursal',
        'margen_ebitda' => 'Margen EBITDA',
    ];

    private const CONSOLIDADO = 'CONSOLIDADO';

    private bool $canNomina = true;

    private bool $canDg = true;

    public function __construct(
        private readonly PeriodSummaryRepository $repo,
        private readonly PeriodCalendar $calendar,
        private readonly PeriodComparability $comparability,
        private readonly DataScopeService $dataScope,
        private readonly EstadoResultadosAbilities $abilities,
    ) {}

    /**
     * @param  array{modes?:list<string>, contra?:?Period, meses?:int, desde?:?Period, hasta?:?Period, charts?:bool}  $options
     *
     * @throws ComparisonAccessException alcance fuera del alcance de sucursales del usuario
     * @throws \InvalidArgumentException parámetros no válidos
     */
    public function build(Period $period, ?string $scope, ?User $user, array $options = []): array
    {
        $t0 = microtime(true);
        if ($period->type !== 'monthly') {
            throw new \InvalidArgumentException('Las comparativas se generan sobre periodos mensuales.');
        }
        $this->canNomina = $user === null || $this->abilities->can($user, 'ver_nomina');
        $this->canDg = $user === null || $this->abilities->can($user, 'ver_dg');

        $modes = array_values(array_unique(array_intersect(self::MODES, $options['modes'] ?? self::DEFAULT_MODES)));
        if (! empty($options['contra']) && ! in_array('personalizado', $modes, true)) {
            $modes[] = 'personalizado';
        }
        if (in_array('personalizado', $modes, true) && empty($options['contra'])) {
            throw new \InvalidArgumentException('Para la comparación personalizada indica el periodo «contra».');
        }
        $months = max(1, min(13, (int) ($options['meses'] ?? 6)));

        $bundle = $this->repo->bundle($period);
        $access = $this->access($user, $bundle['scopes']);
        $scope = $this->resolveScope($scope, $access, $bundle['scopes']);
        $current = $this->entryFor($period, $scope, null, true);

        $out = [
            'contract_version' => self::CONTRACT_VERSION,
            'generated_at' => now('America/Mexico_City')->format('d/m/Y H:i'),
            'model' => [
                'key' => 'ebitda_unico',
                'label' => 'Modelo de un solo EBITDA',
                'formulas' => [
                    'EBITDA = Ingresos operativos − OPEX (OPEX incluye nómina, corporativo y gastos operativos de Dirección General)',
                    'EBIT = EBITDA − Depreciación y amortización',
                    'EBT = EBIT + Resultado financiero y no operativo neto (gastos negativos, ingresos positivos)',
                    'Utilidad neta = EBT − ISR (ISR sin información = pendiente, nunca $0)',
                ],
                'branch_note' => 'Por sucursal sólo existe el EBITDA de sucursal (ingresos − OPEX directo de la sucursal). Corporativo y Dirección General son centralizados y nunca se asignan a sucursales.',
            ],
            'period' => $current['period'],
            'scope' => $this->scopeInfo($scope, $bundle['scopes']),
            'access' => $access['public'],
            'concepts' => ComparisonConcepts::catalog(),
            'catalogs' => [
                'status' => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys(PeriodSummaryExtractor::STATUS_LABELS), PeriodSummaryExtractor::STATUS_LABELS),
                'source' => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys(PeriodSummaryExtractor::SOURCE_LABELS), PeriodSummaryExtractor::SOURCE_LABELS),
                'assessment' => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys(ComparisonConcepts::ASSESSMENT_LABELS), ComparisonConcepts::ASSESSMENT_LABELS),
                'modes' => array_map(fn ($k) => ['key' => $k, 'label' => self::MODE_LABELS[$k]], self::MODES),
                'reasons' => array_map(fn ($k, $l) => ['code' => $k, 'message' => $l], array_keys(Figures::REASONS), Figures::REASONS),
            ],
            'sources_note' => 'Cada periodo indica su origen: «Versión aprobada (snapshot inmutable)» si está aprobado, o «Cálculo en vivo» si no. Las cifras en vivo pueden cambiar.',
            'current' => $current,
            'modes' => array_fill_keys(self::MODES, null),
            'charts' => [],
        ];

        foreach ($modes as $mode) {
            $out['modes'][$mode] = match ($mode) {
                'anterior' => $this->pairMode('anterior', $current, $this->calendar->previous($period), $scope, null),
                'anio_anterior' => $this->pairMode('anio_anterior', $current, $this->calendar->sameMonthPreviousYear($period), $scope,
                    ['year' => (int) $period->year - 1, 'month' => (int) $period->month]),
                'personalizado' => $this->pairMode('personalizado', $current, $options['contra'], $scope, null),
                'evolucion' => $this->evolution($period, $scope, $months, $options['desde'] ?? null, $options['hasta'] ?? null),
                'acumulado' => $this->ytd($period, $scope),
                'sucursales' => $this->branches($period, $access),
            };
        }

        if ($options['charts'] ?? true) {
            $out['charts'] = $this->charts($current, $out['modes']['evolucion'], $out['modes']['sucursales']);
        }

        $out['diagnostics'] = [
            'ms' => (int) round((microtime(true) - $t0) * 1000),
            'peak_memory_mb' => (int) round(memory_get_peak_usage(true) / 1048576),
            'periods' => $this->repo->stats,
        ];

        return $out;
    }

    // ── Acceso / alcance ─────────────────────────────────────────────

    private function access(?User $user, array $scopes): array
    {
        $names = $user ? $this->dataScope->allowedBranchNames($user) : null;
        $restricted = $names !== null;
        $visible = array_values(array_filter($scopes, fn ($s) => $s['key'] === self::CONSOLIDADO
            ? ! $restricted
            : (! $restricted || $this->dataScope->allowsBranchName($user, $s['key']))));

        return [
            'restricted' => $restricted,
            'visible' => array_column($visible, 'key'),
            'public' => [
                'restricted' => $restricted,
                'consolidated_allowed' => ! $restricted,
                'allowed_scopes' => $visible,
                'ver_nomina' => $this->canNomina,
                'ver_dg' => $this->canDg,
                'message' => $restricted ? 'Tu alcance está limitado a tus sucursales asignadas: no se muestran totales consolidados ni gastos centralizados.' : null,
            ],
        ];
    }

    private function resolveScope(?string $scope, array $access, array $scopes): string
    {
        if ($scope !== null) {
            $scope = trim($scope) === '' ? null : (mb_strtoupper(trim($scope)) === self::CONSOLIDADO ? self::CONSOLIDADO : trim($scope));
        }
        if ($scope === null) {
            if (! $access['restricted']) {
                return self::CONSOLIDADO;
            }
            if ($access['visible'] === []) {
                throw new ComparisonAccessException('Tu usuario no tiene sucursales asignadas para consultar el Estado de Resultados.');
            }

            return $access['visible'][0];
        }
        if (! in_array($scope, array_column($scopes, 'key'), true)) {
            throw new \InvalidArgumentException('Alcance no válido: '.$scope.'.');
        }
        if (! in_array($scope, $access['visible'], true)) {
            throw new ComparisonAccessException($scope === self::CONSOLIDADO
                ? 'Tu alcance está limitado a tus sucursales asignadas: no puedes consultar el consolidado.'
                : 'No tienes acceso a la sucursal '.$scope.'.');
        }

        return $scope;
    }

    private function scopeInfo(string $scope, array $scopes): array
    {
        $label = collect($scopes)->firstWhere('key', $scope)['label'] ?? $scope;

        return ['key' => $scope, 'label' => $label, 'consolidated' => $scope === self::CONSOLIDADO];
    }

    // ── Entradas por periodo ─────────────────────────────────────────

    /**
     * Entrada pública de un periodo/alcance. $cell describe un mes sin periodo en el sistema.
     */
    private function entryFor(?Period $period, string $scope, ?array $cell, bool $withLines = false): array
    {
        if (! $period) {
            $y = (int) ($cell['year'] ?? 0);
            $m = (int) ($cell['month'] ?? 0);

            return $this->emptyEntry(['id' => null, 'code' => null, 'label' => PeriodCalendar::monthLabel($y, $m), 'start' => null, 'end' => null, 'days' => null, 'year' => $y, 'month' => $m],
                'sin_periodo', 'No existe el periodo '.PeriodCalendar::monthLabel($y, $m).' en el sistema.');
        }
        $p = [
            'id' => $period->id, 'code' => $period->code, 'label' => $period->label,
            'start' => $period->start_date->format('Y-m-d'), 'end' => $period->end_date->format('Y-m-d'),
            'days' => (int) $period->start_date->diffInDays($period->end_date) + 1,
            'year' => (int) $period->year, 'month' => (int) $period->month,
        ];
        $bundle = $this->repo->bundle($period);
        $s = $bundle['summaries'][$scope] ?? null;
        if (! $s) {
            $err = $bundle['errors'][$scope] ?? null;

            return $this->emptyEntry($p, 'alcance_no_disponible', $err ?? 'El alcance '.$scope.' no existe en '.$period->label.'.');
        }

        return $this->publicEntry($s, $p, $bundle['cache'] ?? null, $withLines);
    }

    private function emptyEntry(array $p, string $code, string $message): array
    {
        return [
            'period' => $p,
            'available' => false,
            'status' => 'sin_datos',
            'status_label' => PeriodSummaryExtractor::STATUS_LABELS['sin_datos'],
            'unavailable_reason' => ['code' => $code, 'message' => $message],
            'source' => null, 'source_label' => null, 'cache' => null,
            'report' => null,
            'values' => array_fill_keys(array_keys(ComparisonConcepts::AMOUNTS), null),
            'margins' => array_fill_keys(array_keys(ComparisonConcepts::MARGINS), null),
            'complete' => array_fill_keys(array_keys(ComparisonConcepts::AMOUNTS), false),
            'opex_categories' => [],
            'availability' => null,
            'warnings' => [],
        ];
    }

    private function publicEntry(array $s, array $p, ?string $cache, bool $withLines): array
    {
        $available = $s['status'] !== 'sin_datos';

        return [
            'period' => $p,
            'available' => $available,
            'status' => $s['status'],
            'status_label' => $s['status_label'],
            'unavailable_reason' => $available ? null : ['code' => 'sin_datos', 'message' => 'El periodo no tiene datos cargados (no se presentan como $0.00).'],
            'source' => $s['source'],
            'source_label' => $s['source_label'],
            'cache' => $cache,
            'report' => $s['report'] + ['model' => $s['model']],
            'values' => $s['values'],
            'margins' => $this->margins($s['values'], $s['complete']),
            'complete' => $s['complete'],
            'opex_categories' => array_map(fn ($c) => $this->publicCategory($c, $s['values']['opex_total'], $withLines), $s['opex_categories']),
            'availability' => $s['availability'],
            'warnings' => $s['warnings'],
        ];
    }

    private function publicCategory(array $c, ?string $opexTotal, bool $withLines): array
    {
        $restricted = ($c['sensitive'] === 'nomina' && ! $this->canNomina) || ($c['sensitive'] === 'dg' && ! $this->canDg);
        $out = [
            'key' => $c['key'], 'label' => $c['label'], 'amount' => $c['amount'],
            'pct_of_opex' => Figures::pct($c['amount'], $opexTotal),
            'centralized' => $c['centralized'],
            'restricted' => $restricted,
        ];
        if ($withLines) {
            $out['lines'] = $restricted ? [] : $c['lines'];
        }

        return $out;
    }

    /** Márgenes sobre ingresos operativos (2 decimales); null si falta el importe o los ingresos son 0. */
    private function margins(array $v, array $complete): array
    {
        $out = [];
        foreach (ComparisonConcepts::MARGINS as $k => $m) {
            $out[$k] = Figures::pct($v[$m['of']] ?? null, $v['ingresos_operativos'] ?? null);
        }

        return $out;
    }

    // ── Comparación de pares ─────────────────────────────────────────

    private function pairMode(string $mode, array $current, ?Period $other, string $scope, ?array $cell): array
    {
        if ($other && $other->id === ($current['period']['id'] ?? null)) {
            throw new \InvalidArgumentException('Elige un periodo de comparación distinto al seleccionado.');
        }
        if ($other && $other->type !== 'monthly') {
            throw new \InvalidArgumentException('El periodo de comparación debe ser mensual.');
        }
        $cmp = $other ? $this->entryFor($other, $scope, null) : $this->emptyEntry(
            ['id' => null, 'code' => null, 'label' => $cell ? PeriodCalendar::monthLabel($cell['year'], $cell['month']) : 'Sin periodo anterior', 'start' => null, 'end' => null, 'days' => null, 'year' => $cell['year'] ?? null, 'month' => $cell['month'] ?? null],
            'sin_periodo', $cell ? 'No existe el periodo '.PeriodCalendar::monthLabel($cell['year'], $cell['month']).' en el sistema.' : 'No hay un periodo mensual anterior en el sistema.',
        );

        return $this->pair($mode, self::MODE_LABELS[$mode], $current, $cmp);
    }

    /** Comparación concepto por concepto entre dos entradas (o agregados con la misma forma). */
    private function pair(string $key, string $label, array $current, array $cmp, bool $checkDuration = true): array
    {
        $a = $this->forComparability($current);
        $b = $this->forComparability($cmp);
        if (! $checkDuration) {
            // Acumulados: la equivalencia se valida por MESES incluidos, no por días.
            $a['period']['days'] = $b['period']['days'] = null;
        }
        $c = $this->comparability->evaluate($a, $b);

        $rows = [];
        foreach (ComparisonConcepts::ORDER as $k) {
            $rows[] = $this->row($k, ComparisonConcepts::meta($k), $current, $cmp, $c);
        }
        $cats = [];
        $curCats = array_column($current['opex_categories'], null, 'key');
        $cmpCats = array_column($cmp['opex_categories'], null, 'key');
        foreach (array_unique(array_merge(array_keys($curCats), array_keys($cmpCats))) as $ck) {
            $cur = $curCats[$ck]['amount'] ?? ($current['available'] ? '0.00' : null);
            $prev = $cmpCats[$ck]['amount'] ?? ($cmp['available'] ? '0.00' : null);
            $cats[] = $this->amountRow($ck, $curCats[$ck]['label'] ?? $cmpCats[$ck]['label'], ComparisonConcepts::EXPENSE, $cur, $prev,
                $current['available'], $cmp['available'], true, true, $c)
                + ['centralized' => (bool) ($curCats[$ck]['centralized'] ?? $cmpCats[$ck]['centralized'] ?? false)];
        }

        return [
            'key' => $key,
            'label' => $label,
            'current' => $this->ref($current),
            'comparison' => $this->ref($cmp),
            'available' => $current['available'] && $cmp['available'],
            'unavailable_reason' => ! $cmp['available'] ? $cmp['unavailable_reason'] : (! $current['available'] ? $current['unavailable_reason'] : null),
            'comparability' => $c,
            'rows' => $rows,
            'opex_categories' => $cats,
        ];
    }

    private function forComparability(array $e): array
    {
        return [
            'period' => $e['period'], 'status' => $e['status'], 'status_label' => $e['status_label'],
            'availability' => $e['availability'] ?? [], 'report' => $e['report'] ?? [], 'complete' => $e['complete'],
        ];
    }

    /** Referencia corta de una entrada (sin categorías) para no repetir datos. */
    private function ref(array $e): array
    {
        return array_intersect_key($e, array_flip(['period', 'available', 'status', 'status_label', 'unavailable_reason', 'source', 'source_label', 'report', 'months']));
    }

    private function row(string $k, array $meta, array $cur, array $cmp, array $comp): array
    {
        if ($meta['kind'] === ComparisonConcepts::MARGIN) {
            $of = $meta['of'];
            $a = $cur['margins'][$k] ?? null;
            $b = $cmp['margins'][$k] ?? null;
            $okA = (bool) ($cur['complete'][$of] ?? false);
            $okB = (bool) ($cmp['complete'][$of] ?? false);
            $reason = match (true) {
                ! $cur['available'] => 'sin_datos_actual',
                ! $cmp['available'] => 'sin_datos_anterior',
                $cur['values'][$of] === null => 'pendiente_actual',
                $cmp['values'][$of] === null => 'pendiente_anterior',
                $a === null || $b === null => 'base_ingresos_cero',
                ($comp['level'] ?? (! empty($comp['comparable']) ? 'comparable' : 'no_comparable')) === 'no_comparable' => 'no_comparable',
                ! $okA || ! $okB => 'incompleto',
                default => null,
            };
            $points = $reason === null ? Figures::points($a, $b) : null;
            $reservation = $points !== null ? $this->reservation($k, $comp) : null;
            $x = Figures::assessment($points, $meta['kind']);

            return [
                'key' => $k, 'label' => $meta['label'], 'kind' => $meta['kind'], 'unit' => '%',
                'current' => $a, 'previous' => $b,
                'variation' => $points, 'variation_unit' => 'pp',
                'variation_pct' => null,
                'assessment' => $x,
                'assessment_label' => $x ? ComparisonConcepts::ASSESSMENT_LABELS[$x].($reservation ? ' (con reservas)' : '') : null,
                'reason' => Figures::reason($reason ?? 'margen_en_puntos'),
                'reservation' => $reservation,
                'complete' => ['current' => $okA, 'previous' => $okB],
            ];
        }

        return $this->amountRow($k, $meta['label'], $meta['kind'], $cur['values'][$k] ?? null, $cmp['values'][$k] ?? null,
            $cur['available'], $cmp['available'], (bool) ($cur['complete'][$k] ?? false), (bool) ($cmp['complete'][$k] ?? false), $comp);
    }

    /**
     * «Con reservas» para un concepto: sólo cuando los periodos no comparten fuentes/duración y el
     * concepto depende de esa diferencia (PeriodComparability::$unaffected lista los que no).
     */
    private function reservation(string $k, array $comp): ?array
    {
        if (($comp['level'] ?? null) !== 'con_reservas' || in_array($k, (array) ($comp['unaffected'] ?? []), true)) {
            return null;
        }
        $codes = array_column((array) ($comp['reasons'] ?? []), 'code');

        return Figures::reason(in_array('duracion_distinta', $codes, true) && ! in_array('cobertura_distinta', $codes, true) ? 'con_reservas_duracion' : 'con_reservas');
    }

    private function amountRow(string $k, string $label, string $kind, ?string $a, ?string $b, bool $availA, bool $availB, bool $okA, bool $okB, array $comp): array
    {
        $delta = Figures::sub($a, $b);
        $reason = match (true) {
            ! $availA => 'sin_datos_actual',
            ! $availB => 'sin_datos_anterior',
            $a === null => 'pendiente_actual',
            $b === null => 'pendiente_anterior',
            ($comp['level'] ?? (! empty($comp['comparable']) ? 'comparable' : 'no_comparable')) === 'no_comparable' => 'no_comparable',
            ! $okA || ! $okB => 'incompleto',
            Money::of($b)->isZero() => 'base_cero',
            default => null,
        };
        $assessable = $delta !== null && in_array($reason, [null, 'base_cero'], true);
        $assessment = $assessable ? Figures::assessment($delta, $kind) : null;
        $reservation = $delta !== null && $reason !== 'no_comparable' ? $this->reservation($k, $comp) : null;

        return [
            'key' => $k, 'label' => $label, 'kind' => $kind, 'unit' => 'MXN',
            'current' => $a, 'previous' => $b,
            'variation' => $delta, 'variation_unit' => 'MXN',
            'variation_pct' => $reason === null ? Figures::variationPct($a, $b) : null,
            'assessment' => $assessment,
            'assessment_label' => $assessment ? ComparisonConcepts::ASSESSMENT_LABELS[$assessment].($reservation ? ' (con reservas)' : '') : null,
            'reason' => Figures::reason($reason),
            'reservation' => $reservation,
            'complete' => ['current' => $okA, 'previous' => $okB],
        ];
    }

    // ── Evolución ─────────────────────────────────────────────────────

    private function evolution(Period $period, string $scope, int $months, ?Period $from, ?Period $to): array
    {
        $grid = $from && $to ? $this->calendar->range($from, $to, 24) : $this->calendar->grid($period, $months);
        $entries = array_map(fn ($cell) => $this->entryFor($cell['period'], $scope, $cell), $grid);

        $series = [];
        foreach (array_keys(ComparisonConcepts::AMOUNTS) as $k) {
            $series[$k] = array_map(fn ($e) => $e['values'][$k], $entries);
        }
        foreach (array_keys(ComparisonConcepts::MARGINS) as $k) {
            $series[$k] = array_map(fn ($e) => $e['margins'][$k], $entries);
        }
        $catKeys = [];
        foreach ($entries as $e) {
            foreach ($e['opex_categories'] as $c) {
                $catKeys[$c['key']] ??= ['key' => $c['key'], 'label' => $c['label'], 'centralized' => $c['centralized']];
            }
        }
        $categorySeries = array_values(array_map(fn ($c) => $c + ['data' => array_map(function ($e) use ($c) {
            if (! $e['available']) {
                return null;
            }
            $hit = collect($e['opex_categories'])->firstWhere('key', $c['key']);

            return $hit['amount'] ?? '0.00';
        }, $entries)], $catKeys));

        return [
            'key' => 'evolucion',
            'label' => self::MODE_LABELS['evolucion'].' ('.count($entries).' meses)',
            'months' => count($entries),
            'range' => ['from' => $entries[0]['period']['label'], 'to' => end($entries)['period']['label']],
            'periods' => array_map(fn ($e) => $this->ref($e) + ['values' => $e['values'], 'margins' => $e['margins'], 'complete' => $e['complete']], $entries),
            'labels' => array_map(fn ($e) => $e['period']['label'], $entries),
            'status' => array_map(fn ($e) => $e['status'], $entries),
            'series' => $series,
            'opex_categories' => $categorySeries,
            'with_data' => count(array_filter($entries, fn ($e) => $e['available'])),
            'note' => 'Los meses sin datos se marcan «Sin datos» (null), nunca como $0.00.',
        ];
    }

    // ── Acumulado anual ───────────────────────────────────────────────

    private function ytd(Period $period, string $scope): array
    {
        $year = (int) $period->year;
        $month = (int) $period->month;
        $cur = $this->aggregate($year, $month, null, $scope);
        $prev = $this->aggregate($year - 1, $month, $cur['month_numbers'], $scope);

        $pair = $this->pair('acumulado', 'Acumulado '.$year.' vs acumulado equivalente '.($year - 1), $cur, $prev, false);
        if ($cur['available'] && $prev['available'] && $cur['month_numbers'] !== $prev['month_numbers']) {
            $pair['comparability']['comparable'] = false;
            $pair['comparability']['level'] = 'con_reservas';
            $pair['comparability']['level_label'] = 'Con reservas';
            $pair['comparability']['reasons'][] = ['code' => 'meses_distintos', 'message' => 'El acumulado del año anterior no tiene datos de los mismos meses.'];
            $pair['comparability']['headline'] = 'Comparación con reservas: el acumulado del año anterior no tiene datos de los mismos meses; se muestran las cifras y la variación en pesos, sin porcentaje ni lectura.';
            foreach ($pair['rows'] as &$r) {
                $r['variation_pct'] = null;
                $r['assessment'] = null;
                $r['assessment_label'] = null;
                $r['reservation'] = null;
                $r['reason'] = Figures::reason('meses_distintos');
                if ($r['unit'] === '%') {
                    $r['variation'] = null;
                }
            }
            unset($r);
        }

        return [
            'key' => 'acumulado',
            'label' => $cur['label'],
            'year' => $year,
            'partial' => $cur['partial'],
            'months_included' => $cur['months_included'],
            'months_with_data' => count($cur['month_numbers']),
            'months_total' => 12,
            'current' => $cur,
            'previous_year' => $prev,
            'comparison' => $pair,
        ];
    }

    /**
     * Suma los periodos mensuales del $year con mes ≤ $month (o sólo los meses $only).
     * Concepto con algún periodo pendiente ⇒ null (pendiente). Márgenes recalculados sobre la suma.
     */
    private function aggregate(int $year, int $month, ?array $only, string $scope): array
    {
        $entries = [];
        $missing = [];
        for ($m = 1; $m <= $month; $m++) {
            if ($only !== null && ! in_array($m, $only, true)) {
                continue;
            }
            $p = $this->calendar->byYearMonth($year, $m);
            $e = $this->entryFor($p, $scope, ['year' => $year, 'month' => $m]);
            if ($e['available']) {
                $entries[] = $e;
            } else {
                $missing[] = PeriodCalendar::monthLabel($year, $m);
            }
        }
        $n = count($entries);
        $monthNumbers = array_map(fn ($e) => (int) $e['period']['month'], $entries);
        $values = [];
        $complete = [];
        foreach (array_keys(ComparisonConcepts::AMOUNTS) as $k) {
            $values[$k] = $n ? Figures::sum(array_map(fn ($e) => $e['values'][$k], $entries)) : null;
            $complete[$k] = $n && ! in_array(false, array_map(fn ($e) => (bool) $e['complete'][$k], $entries), true);
        }
        $cats = [];
        foreach ($entries as $e) {
            foreach ($e['opex_categories'] as $c) {
                $cats[$c['key']] ??= ['key' => $c['key'], 'label' => $c['label'], 'centralized' => $c['centralized'], 'restricted' => $c['restricted'], 'amount' => BigDecimal::zero()];
                $cats[$c['key']]['amount'] = $cats[$c['key']]['amount']->plus(Money::of($c['amount']));
            }
        }
        $cats = array_values(array_map(fn ($c) => ['amount' => Money::str2($c['amount']), 'pct_of_opex' => Figures::pct(Money::str2($c['amount']), $values['opex_total'] ?? null)] + $c, $cats));

        $statuses = array_column($entries, 'status');
        $status = match (true) {
            $n === 0 => 'sin_datos',
            $missing !== [] || in_array('parcial', $statuses, true) => 'parcial',
            in_array('preliminar', $statuses, true) => 'preliminar',
            in_array('listo_revision', $statuses, true) => 'listo_revision',
            default => 'aprobado',
        };
        $label = 'Acumulado '.$year.($n > 0
            ? ' ('.($n < 12 ? 'parcial: ' : '').$n.' de 12 meses'.($n ? ', '.$entries[0]['period']['label'].' a '.end($entries)['period']['label'] : '').')'
            : ' (sin datos)');
        $sources = array_values(array_unique(array_filter(array_column($entries, 'source'))));

        return [
            'period' => ['id' => null, 'code' => null, 'label' => $label, 'start' => $entries[0]['period']['start'] ?? null, 'end' => $n ? end($entries)['period']['end'] : null,
                'days' => $n ? array_sum(array_map(fn ($e) => (int) $e['period']['days'], $entries)) : null, 'year' => $year, 'month' => $month],
            'label' => $label,
            'available' => $n > 0,
            'partial' => $n < 12,
            'status' => $status,
            'status_label' => PeriodSummaryExtractor::STATUS_LABELS[$status],
            'unavailable_reason' => $n ? null : ['code' => 'sin_datos', 'message' => 'No hay periodos con datos en '.$year.' hasta '.PeriodCalendar::MONTHS[$month].'.'],
            'source' => count($sources) === 1 ? $sources[0] : ($sources === [] ? null : 'mixto'),
            'source_label' => count($sources) === 1 ? PeriodSummaryExtractor::SOURCE_LABELS[$sources[0]] : ($sources === [] ? null : 'Mixto: periodos aprobados y en vivo'),
            'report' => ['rules_version' => count($rv = array_unique(array_map(fn ($e) => (string) ($e['report']['rules_version'] ?? ''), $entries))) === 1 ? reset($rv) : ''],
            'months_included' => $n,
            'month_numbers' => $monthNumbers,
            'months' => array_map(fn ($e) => ['id' => $e['period']['id'], 'label' => $e['period']['label'], 'status' => $e['status'], 'source' => $e['source']], $entries),
            'missing_months' => $missing,
            'values' => $values,
            'margins' => $this->margins($values, $complete),
            'complete' => $complete,
            'opex_categories' => $cats,
            'availability' => $n ? [
                'erp' => ! in_array(false, array_map(fn ($e) => (bool) $e['availability']['erp'], $entries), true),
                'noi' => ! in_array(false, array_map(fn ($e) => (bool) $e['availability']['noi'], $entries), true),
                'bancos' => ['cargadas' => array_sum(array_map(fn ($e) => $e['availability']['bancos']['cargadas'], $entries)), 'total' => array_sum(array_map(fn ($e) => $e['availability']['bancos']['total'], $entries))],
            ] : null,
        ];
    }

    // ── Sucursales ────────────────────────────────────────────────────

    private function branches(Period $period, array $access): array
    {
        $bundle = $this->repo->bundle($period);
        $prevPeriod = $this->calendar->previous($period);
        $prevBundle = $prevPeriod ? $this->repo->bundle($prevPeriod) : null;
        $restricted = $access['restricted'];
        $cons = $bundle['summaries'][self::CONSOLIDADO] ?? null;
        $consAvailable = ! $restricted && $cons && $cons['status'] !== 'sin_datos';

        $rows = [];
        foreach ($bundle['scopes'] as $s) {
            if ($s['key'] === self::CONSOLIDADO || ! in_array($s['key'], $access['visible'], true)) {
                continue;
            }
            $e = $this->entryFor($period, $s['key'], null);
            $pe = $prevPeriod ? $this->entryFor($prevPeriod, $s['key'], null) : $this->emptyEntry(['id' => null, 'label' => 'Sin periodo anterior'], 'sin_periodo', 'No hay un periodo mensual anterior.');
            $cmp = $this->comparability->evaluate($this->forComparability($e), $this->forComparability($pe));
            $v = $e['values'];
            $variation = [];
            foreach (['ingresos_operativos', 'opex_total', 'ebitda'] as $k) {
                $r = $this->amountRow($k, ComparisonConcepts::meta($k)['label'], ComparisonConcepts::meta($k)['kind'], $v[$k], $pe['values'][$k],
                    $e['available'], $pe['available'], true, true, $cmp);
                $variation[$k] = array_intersect_key($r, array_flip(['previous', 'variation', 'variation_pct', 'assessment', 'assessment_label', 'reason', 'reservation']));
            }
            $variation['margen_ebitda'] = array_intersect_key($this->row('margen_ebitda', ComparisonConcepts::meta('margen_ebitda'), $e, $pe, $cmp),
                array_flip(['previous', 'variation', 'variation_unit', 'assessment', 'assessment_label', 'reason']));

            $rows[] = [
                'key' => $s['key'],
                'label' => $s['label'],
                'available' => $e['available'],
                'status' => $e['status'],
                'status_label' => $e['status_label'],
                'source' => $e['source'],
                'ingresos_operativos' => $v['ingresos_operativos'],
                'opex_total' => $v['opex_total'],
                'ebitda' => $v['ebitda'],
                'margen_ebitda' => $e['margins']['margen_ebitda'],
                'participacion_ingresos' => $consAvailable ? Figures::pct($v['ingresos_operativos'], $cons['values']['ingresos_operativos']) : null,
                'opex_categories' => $e['opex_categories'],
                'previous_period' => $pe['period']['label'] ?? null,
                'comparability' => $cmp,
                'variation' => $variation,
            ];
        }

        $sum = fn (string $k) => Figures::sum(array_map(fn ($r) => $r[$k], array_filter($rows, fn ($r) => $r['available'])));
        $withData = array_filter($rows, fn ($r) => $r['available']);
        $totalEbitda = $withData ? $sum('ebitda') : null;
        $positive = $totalEbitda !== null && Money::of($totalEbitda)->isPositive();
        foreach ($rows as &$r) {
            $r['participacion_ebitda'] = $positive && $r['ebitda'] !== null ? Figures::pct($r['ebitda'], $totalEbitda) : null;
            $r['participacion_reason'] = $restricted ? Figures::reason('sin_consolidado') : (! $positive ? Figures::reason('base_no_positiva') : null);
        }
        unset($r);

        $totals = [
            'sucursales' => $withData ? [
                'label' => $restricted ? 'Total de tus sucursales' : 'Total sucursales',
                'ingresos_operativos' => $sum('ingresos_operativos'),
                'opex_total' => $sum('opex_total'),
                'ebitda' => $totalEbitda,
                'margen_ebitda' => Figures::pct($totalEbitda, $sum('ingresos_operativos')),
            ] : null,
            'consolidado' => null,
            'centralizados' => null,
            'conciliacion' => null,
        ];
        if ($consAvailable) {
            $consEntry = $this->entryFor($period, self::CONSOLIDADO, null);
            $central = array_values(array_filter($consEntry['opex_categories'], fn ($c) => $c['centralized']));
            $centralTotal = Figures::sum(array_column($central, 'amount')) ?? '0.00';
            $totals['consolidado'] = [
                'label' => 'Consolidado',
                'ingresos_operativos' => $consEntry['values']['ingresos_operativos'],
                'opex_total' => $consEntry['values']['opex_total'],
                'ebitda' => $consEntry['values']['ebitda'],
                'margen_ebitda' => $consEntry['margins']['margen_ebitda'],
            ];
            $totals['centralizados'] = [
                'label' => 'Gastos centralizados (Corporativo y Dirección General) — no se asignan a sucursales',
                'total' => $centralTotal,
                'categories' => array_map(fn ($c) => array_intersect_key($c, array_flip(['key', 'label', 'amount', 'restricted'])), $central),
            ];
            if ($totals['sucursales'] !== null) {
                // EBITDA consolidado = Σ EBITDA sucursales − centralizados ± partidas no asignadas a sucursal.
                $expected = Figures::sub($totals['sucursales']['ebitda'], $centralTotal);
                $totals['conciliacion'] = [
                    'suma_ebitda_sucursales' => $totals['sucursales']['ebitda'],
                    'menos_centralizados' => $centralTotal,
                    'ebitda_consolidado' => $consEntry['values']['ebitda'],
                    'no_asignado_a_sucursal' => Figures::sub($consEntry['values']['ebitda'], $expected),
                    'note' => '«No asignado a sucursal» son ingresos/gastos de Radiografía sin sucursal operativa; se muestra para que el total cuadre sin inventar diferencias.',
                ];
            }
        }

        return [
            'key' => 'sucursales',
            'label' => self::MODE_LABELS['sucursales'].' · '.$period->label,
            'period' => ['id' => $period->id, 'label' => $period->label],
            'previous_period' => $prevPeriod ? ['id' => $prevPeriod->id, 'label' => $prevPeriod->label] : null,
            'restricted' => $restricted,
            'consolidated_visible' => $consAvailable,
            'rows' => $rows,
            'totals' => $totals,
            'sort_options' => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys(self::BRANCH_SORTS), self::BRANCH_SORTS),
            'default_sort' => 'ebitda',
            'note' => 'EBITDA de sucursal = ingresos operativos − OPEX directo de la sucursal (incluye su nómina). Corporativo y Dirección General se muestran aparte como centralizados.',
        ];
    }

    // ── Gráficas ─────────────────────────────────────────────────────

    private function charts(array $current, ?array $evo, ?array $branches): array
    {
        $line = fn (array $keys) => $evo ? array_map(fn ($k) => ['key' => $k, 'label' => ComparisonConcepts::meta($k)['label'], 'data' => $evo['series'][$k]], $keys) : [];
        $v = $current['values'];

        // Cascada con importes reales: totales/subtotales del dataset, deltas con su signo.
        $steps = [];
        $running = null;
        $add = function (string $key, string $type, ?string $amount) use (&$steps, &$running) {
            $label = ComparisonConcepts::meta($key)['label'];
            if ($type === 'delta') {
                $start = $running;
                $end = $amount !== null && $running !== null ? Figures::sum([$running, $amount]) : null;
                $steps[] = ['key' => $key, 'label' => $label, 'type' => 'delta', 'amount' => $amount, 'start' => $start, 'end' => $end, 'pending' => $amount === null];
                $running = $end;

                return;
            }
            $steps[] = ['key' => $key, 'label' => $label, 'type' => $type, 'amount' => $amount, 'start' => $amount === null ? null : '0.00', 'end' => $amount, 'pending' => $amount === null];
            $running = $amount;
        };
        $neg = fn (?string $x) => $x === null ? null : Money::str2(Money::of($x)->negated());
        if ($current['available']) {
            $add('ingresos_operativos', 'total', $v['ingresos_operativos']);
            $add('opex_total', 'delta', $neg($v['opex_total']));
            $add('ebitda', 'subtotal', $v['ebitda']);
            $add('depreciacion_amortizacion', 'delta', $neg($v['depreciacion_amortizacion']));
            $add('ebit', 'subtotal', $v['ebit']);
            $add('resultado_financiero_neto', 'delta', $v['resultado_financiero_neto']);
            $add('ebt', 'subtotal', $v['ebt']);
            $add('isr', 'delta', $neg($v['isr']));
            $add('utilidad_neta', 'total', $v['utilidad_neta']);
        }

        return [
            'evolucion_resultados' => [
                'title' => 'Evolución de EBITDA, EBIT, EBT y utilidad neta',
                'type' => 'line', 'unit' => 'MXN',
                'labels' => $evo['labels'] ?? [], 'status' => $evo['status'] ?? [],
                'series' => $line(['ebitda', 'ebit', 'ebt', 'utilidad_neta']),
            ],
            'ingresos_vs_opex' => [
                'title' => 'Ingresos operativos vs OPEX',
                'type' => 'bar', 'unit' => 'MXN',
                'labels' => $evo['labels'] ?? [], 'status' => $evo['status'] ?? [],
                'series' => $line(['ingresos_operativos', 'opex_total']),
                'result_series' => $line(['ebitda'])[0] ?? null,
            ],
            'composicion_opex' => [
                'title' => 'Composición del OPEX · '.$current['period']['label'],
                'type' => 'donut', 'unit' => 'MXN',
                'available' => $current['available'],
                'total' => $v['opex_total'],
                'items' => array_map(fn ($c) => array_intersect_key($c, array_flip(['key', 'label', 'amount', 'pct_of_opex', 'centralized', 'restricted'])), $current['opex_categories']),
                'by_period' => $evo ? ['labels' => $evo['labels'], 'series' => $evo['opex_categories']] : null,
                'note' => 'Categorías tal como las presenta el Estado de Resultados (sin categorías inventadas).',
            ],
            'cascada' => [
                'title' => 'Cascada · '.$current['period']['label'],
                'type' => 'waterfall', 'unit' => 'MXN',
                'available' => $current['available'],
                'steps' => $steps,
                'note' => 'Importes reales del periodo. Un paso «pendiente» (p. ej. ISR sin información) no se presenta como $0.00 y deja pendiente la utilidad neta.',
            ],
            'margenes' => [
                'title' => 'Márgenes EBITDA, EBIT y neto',
                'type' => 'line', 'unit' => '%',
                'labels' => $evo['labels'] ?? [], 'status' => $evo['status'] ?? [],
                'series' => $line(['margen_ebitda', 'margen_ebit', 'margen_neto']),
            ],
            'sucursales' => $branches ? [
                'title' => 'Comparativo por sucursal · '.$current['period']['label'],
                'type' => 'bar', 'unit' => 'MXN',
                'sort_options' => $branches['sort_options'],
                'default_sort' => $branches['default_sort'],
                'rows' => array_map(fn ($r) => array_intersect_key($r, array_flip(['key', 'label', 'available', 'ingresos_operativos', 'opex_total', 'ebitda', 'margen_ebitda'])), $branches['rows']),
                'centralized' => $branches['totals']['centralizados'],
            ] : null,
        ];
    }
}
