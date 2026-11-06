<?php

namespace App\Services\EstadoResultados\Export;

use App\Services\EstadoResultados\Comparatives\ComparisonConcepts;
use App\Services\EstadoResultados\Comparatives\Figures;
use App\Services\EstadoResultados\Comparatives\PeriodSummaryExtractor;
use App\Services\EstadoResultados\Reporting\ReportStatus;
use App\Services\EstadoResultados\Support\Money;

/**
 * Modelo de presentación COMPARTIDO por el PDF y el Excel del Estado de Resultados.
 *
 * No calcula reglas contables: lee el dataset resuelto (el mismo que la web, ya redactado)
 * y las comparativas de ComparisonService (contrato er-comparativas/1, las mismas series que
 * las gráficas de la web). Sólo ordena, etiqueta y normaliza:
 *  - modelo de EBITDA único (meta.model) → results.* tal cual;
 *  - snapshot aprobado del modelo anterior (sin meta.model) → se normaliza con el mismo
 *    extractor que usan las comparativas y se marca como «modelo anterior» (legacy).
 *
 * Un importe null es PENDIENTE / SIN DATOS: nunca se convierte en $0.00.
 */
class EstadoResultadosExportContext
{
    public const STEPS = [
        'ingresos_operativos' => ['label' => 'Ingresos operativos', 'kind' => 'income', 'formula' => 'Intereses, IVA (criterio gerencial), moratorios, cargos y comisiones cobrados. Sin capital recuperado.'],
        'opex_total' => ['label' => 'OPEX · gastos operativos', 'kind' => 'expense', 'formula' => 'Gastos y nómina de sucursales, gastos y nómina corporativa y Dirección General.'],
        'ebitda' => ['label' => 'EBITDA', 'kind' => 'result', 'formula' => 'Ingresos operativos − OPEX'],
        'depreciacion_amortizacion' => ['label' => 'Depreciación y amortización', 'kind' => 'expense', 'formula' => 'Activos propios y sistemas capitalizados.'],
        'ebit' => ['label' => 'EBIT', 'kind' => 'result', 'formula' => 'EBITDA − Depreciación y amortización'],
        'resultado_financiero_neto' => ['label' => 'Resultado financiero y no operativo neto', 'kind' => 'signed', 'formula' => 'Ingresos financieros − gastos financieros (gastos con signo negativo). Nunca el capital del préstamo.'],
        'ebt' => ['label' => 'EBT · utilidad antes de impuestos', 'kind' => 'result', 'formula' => 'EBIT + Resultado financiero neto'],
        'isr' => ['label' => 'ISR', 'kind' => 'expense', 'formula' => 'Impuesto sobre la renta del periodo.'],
        'utilidad_neta' => ['label' => 'Utilidad neta', 'kind' => 'result', 'formula' => 'EBT − ISR'],
    ];

    public const RESULT_KEYS = ['ebitda', 'ebit', 'ebt', 'utilidad_neta'];

    public const MARGINS = [
        'ebitda' => 'Margen EBITDA',
        'ebit' => 'Margen EBIT',
        'ebt' => 'Margen antes de impuestos (EBT)',
        'neto' => 'Margen neto',
    ];

    public const VALIDATION_LABELS = [
        'ok' => 'Validado: sin diferencias ni pendientes',
        'con_pendientes' => 'Con pendientes por resolver',
        'con_diferencias' => 'Con diferencias por revisar',
    ];

    public const SOURCE_STATUS = [
        'procesado' => 'Procesado',
        'cargado' => 'Cargado (sin procesar)',
        'pendiente' => 'Pendiente',
        'con_incidencias' => 'Con incidencias',
    ];

    public function __construct(private readonly PeriodSummaryExtractor $extractor) {}

    /**
     * @param  array  $d  dataset resuelto (EstadoResultadosReportResolver::resolve)
     * @param  array|null  $cmp  payload de ComparisonService::build (null = no disponible)
     */
    public function make(array $d, ?array $cmp = null, ?string $cmpError = null): array
    {
        $meta = (array) ($d['meta'] ?? []);
        $legacy = ! isset($meta['model']['version']);
        $summary = $this->extractor->extract($d);
        $consolidated = (bool) ($meta['scope']['consolidated'] ?? true);

        $results = $legacy ? $summary['values'] : array_replace(array_fill_keys(array_keys(self::STEPS), null), array_intersect_key((array) ($d['results'] ?? []), self::STEPS));
        $complete = $summary['complete'];
        $margins = $legacy
            ? [
                'ebitda' => Figures::pct($results['ebitda'], $results['ingresos_operativos']),
                'ebit' => Figures::pct($results['ebit'], $results['ingresos_operativos']),
                'ebt' => Figures::pct($results['ebt'], $results['ingresos_operativos']),
                'neto' => Figures::pct($results['utilidad_neta'], $results['ingresos_operativos']),
            ]
            : array_map(fn ($v) => $v === null ? null : (string) $v, array_intersect_key((array) ($d['margins'] ?? []), self::MARGINS) + array_fill_keys(array_keys(self::MARGINS), null));

        $missing = [];
        foreach ((array) ($d['completeness'] ?? []) as $k => $c) {
            $missing[$k] = array_values((array) ($c['missing'] ?? []));
        }
        $steps = [];
        $n = 0;
        foreach (self::STEPS as $key => $s) {
            $n++;
            // Sólo lo que falta en ESTE paso (los resultados heredan el aviso «Incompleto» sin repetir la lista).
            $missingFor = array_values(match ($key) {
                'depreciacion_amortizacion' => $missing['ebit'] ?? [],
                'resultado_financiero_neto' => array_diff($missing['ebt'] ?? [], $missing['ebit'] ?? []),
                'isr' => array_diff($missing['utilidad_neta'] ?? [], $missing['ebt'] ?? []),
                default => [],
            });
            $steps[] = [
                'step' => $n, 'key' => $key, 'label' => $s['label'], 'kind' => $s['kind'], 'formula' => $s['formula'],
                'amount' => $results[$key], 'pending' => $results[$key] === null,
                'complete' => (bool) ($complete[$key] ?? true) && $results[$key] !== null,
                'missing' => $missingFor,
                'is_result' => in_array($key, self::RESULT_KEYS, true),
                'pct' => Figures::pct($results[$key], $results['ingresos_operativos']),
            ];
        }

        $report = $this->report($d);
        $comparisons = $this->comparisons($d, $cmp, $cmpError, $results);

        return [
            'meta' => [
                'period' => (array) ($meta['period'] ?? []) + ['label' => '', 'code' => '', 'start' => null, 'end' => null],
                'scope' => (array) ($meta['scope'] ?? []) + ['key' => 'CONSOLIDADO', 'label' => 'Consolidado', 'consolidated' => true],
                'consolidated' => $consolidated,
                'generated_at' => (string) ($meta['generated_at'] ?? ''),
                'dataset_hash' => (string) ($meta['dataset_hash'] ?? ''),
                'hash_short' => substr((string) ($meta['dataset_hash'] ?? ''), 0, 12),
                'rules_version' => (string) ($meta['rules_version'] ?? ''),
                'tolerance_days' => $meta['tolerance_days'] ?? null,
                'currency' => (string) ($meta['currency'] ?? 'MXN'),
            ],
            'report' => $report,
            'legacy' => $legacy,
            'model_version' => $legacy ? null : (string) $meta['model']['version'],
            'legacy_notice' => $legacy
                ? 'Versión aprobada con el modelo anterior del Estado de Resultados (EBITDA de sucursales, corporativo y final). Se presenta tal como se aprobó; el resumen ejecutivo expresa sus cifras en el modelo vigente (un solo EBITDA = Ingresos − OPEX).'
                : null,
            'results' => $results,
            'complete' => $complete,
            'margins' => $margins,
            'steps' => $steps,
            'opex_categories' => $this->opexCategories($d, $summary, $results['opex_total'], $legacy),
            'statement' => array_values((array) ($d['statement'] ?? [])),
            'comparisons' => $comparisons,
            'cascade' => $comparisons['charts']['cascada']['steps'] ?? $this->cascade($results),
            'sources' => $this->sources($d),
            'validation' => $this->validation($d),
            'redaction_message' => $meta['redactions']['message'] ?? null,
            'restricted' => ['nomina' => (bool) ($meta['redactions']['nomina'] ?? false), 'dg' => (bool) ($meta['redactions']['dg'] ?? false)],
            'warnings' => $summary['warnings'],
        ];
    }

    /** Gobierno del reporte. Un reporte preliminar NUNCA se rotula como aprobado. */
    private function report(array $d): array
    {
        $m = (array) ($d['meta'] ?? []);
        $rep = (array) ($m['report'] ?? []) + [
            'status' => ReportStatus::BORRADOR, 'version' => 1, 'version_id' => null, 'source' => ReportStatus::SOURCE_LIVE,
            'preliminary' => false, 'preliminary_reasons' => [], 'approved_by' => null, 'approved_at' => null, 'snapshot_hash' => null,
            'criterion' => $m['criterion'] ?? ReportStatus::DEFAULT_CRITERION, 'rules_version' => $m['rules_version'] ?? '',
            'issued_at' => now('America/Mexico_City')->format('d/m/Y H:i'), 'reopened' => null,
        ];
        $rep['status_label'] = $rep['status_label'] ?? ReportStatus::label((string) $rep['status']);
        $rep['preliminary'] = (bool) $rep['preliminary'];
        $rep['approved'] = $rep['status'] === ReportStatus::APROBADO && ! $rep['preliminary'];
        $rep['display_status'] = $rep['preliminary'] ? 'PRELIMINAR' : mb_strtoupper((string) $rep['status_label']);
        $rep['tone'] = match (true) {
            $rep['preliminary'] => 'bad',
            $rep['status'] === ReportStatus::APROBADO => 'ok',
            $rep['status'] === ReportStatus::SUSTITUIDO => 'mute',
            default => 'warn',
        };
        $rep['source_label'] = $rep['source'] === ReportStatus::SOURCE_SNAPSHOT ? 'Snapshot aprobado (inmutable)' : 'Cálculo en vivo';

        return $rep;
    }

    /** Categorías reales de OPEX (con líneas) tal como las presenta el statement. */
    private function opexCategories(array $d, array $summary, ?string $opexTotal, bool $legacy): array
    {
        $out = [];
        if (! $legacy) {
            $opex = collect($d['statement'] ?? [])->firstWhere('key', 'opex');
            foreach ((array) ($opex['categories'] ?? []) as $c) {
                $out[] = [
                    'key' => (string) $c['key'], 'label' => (string) $c['label'],
                    'amount' => $c['total']['amount'] ?? null, 'pct' => $c['total']['pct'] ?? null,
                    'pct_of_opex' => Figures::pct($c['total']['amount'] ?? null, $opexTotal),
                    'centralized' => in_array($c['key'], ComparisonConcepts::CENTRALIZED_CATEGORIES, true),
                    'total_label' => (string) ($c['total']['label'] ?? 'Total'),
                    'lines' => array_values((array) ($c['lines'] ?? [])),
                ];
            }

            return $out;
        }
        foreach ($summary['opex_categories'] as $c) {
            $out[] = [
                'key' => $c['key'], 'label' => $c['label'], 'amount' => $c['amount'],
                'pct' => Figures::pct($c['amount'], $summary['values']['ingresos_operativos']),
                'pct_of_opex' => Figures::pct($c['amount'], $opexTotal),
                'centralized' => $c['centralized'], 'total_label' => 'Total '.mb_strtolower($c['label']),
                'lines' => array_map(fn ($l) => $l + ['pct' => null, 'state' => null], $c['lines']),
            ];
        }

        return $out;
    }

    private function comparisons(array $d, ?array $cmp, ?string $error, array $results): array
    {
        if ($cmp === null) {
            return ['available' => false, 'error' => $error ?? 'Las comparativas no están disponibles para este reporte.', 'mismatch' => false, 'pairs' => [], 'evolution' => null, 'branches' => null, 'charts' => [], 'notice' => null];
        }
        $hash = (string) ($d['meta']['dataset_hash'] ?? '');
        $cmpHash = (string) ($cmp['current']['report']['dataset_hash'] ?? '');
        $mismatch = $cmpHash !== '' && $hash !== '' && $cmpHash !== $hash;

        $pairs = [];
        foreach (['anterior' => 'Periodo actual vs periodo anterior', 'anio_anterior' => 'Periodo actual vs mismo mes del año anterior'] as $k => $title) {
            if ($m = $cmp['modes'][$k] ?? null) {
                $pairs[$k] = $this->pairView($m, $title, false);
            }
        }
        if ($ytd = $cmp['modes']['acumulado'] ?? null) {
            $view = $this->pairView($ytd['comparison'], 'Acumulado del año (YTD)', (bool) $ytd['partial']);
            $view['current_label'] = (string) $ytd['current']['label'];
            $view['comparison_label'] = (string) $ytd['previous_year']['label'];
            $view['missing_months'] = array_values((array) ($ytd['current']['missing_months'] ?? []));
            $pairs['acumulado'] = $view;
        }

        $branches = $cmp['modes']['sucursales'] ?? null;
        $scope = (string) ($d['meta']['scope']['key'] ?? 'CONSOLIDADO');
        if ($branches && $scope !== 'CONSOLIDADO') {
            // Reporte de una sucursal: sólo su renglón (nunca los totales de las demás).
            $branches['rows'] = array_values(array_filter($branches['rows'], fn ($r) => $r['key'] === $scope));
            $branches['totals'] = ['sucursales' => null, 'consolidado' => null, 'centralizados' => null, 'conciliacion' => null];
        }

        return [
            'available' => true,
            'error' => null,
            'mismatch' => $mismatch,
            'notice' => $mismatch ? 'Las comparativas y gráficas usan la versión vigente del periodo (dataset '.substr($cmpHash, 0, 12).'), distinta de la versión exportada (dataset '.substr($hash, 0, 12).'). Las cifras del estado de resultados son las de la versión exportada.' : null,
            'pairs' => $pairs,
            'evolution' => $cmp['modes']['evolucion'] ?? null,
            'branches' => $branches,
            'charts' => (array) ($cmp['charts'] ?? []),
            'sources_note' => $cmp['sources_note'] ?? null,
            'contract' => $cmp['contract_version'] ?? null,
        ];
    }

    private function pairView(array $m, string $title, bool $partial): array
    {
        $c = (array) ($m['comparability'] ?? []);

        return [
            'key' => (string) $m['key'],
            'title' => $title,
            'label' => (string) ($m['label'] ?? $title),
            'current_label' => (string) ($m['current']['period']['label'] ?? 'Actual'),
            'comparison_label' => (string) ($m['comparison']['period']['label'] ?? 'Comparación'),
            'comparison_source' => $m['comparison']['source_label'] ?? null,
            'comparison_status' => $m['comparison']['status_label'] ?? null,
            'available' => (bool) ($m['available'] ?? false),
            'unavailable' => $m['unavailable_reason']['message'] ?? null,
            'comparable' => (bool) ($c['comparable'] ?? false),
            'reasons' => array_values(array_map(fn ($r) => is_array($r) ? (string) ($r['message'] ?? '') : (string) $r, (array) ($c['reasons'] ?? []))),
            'partial' => $partial,
            'missing_months' => [],
            'rows' => array_values((array) ($m['rows'] ?? [])),
        ];
    }

    /** Cascada con la MISMA forma que charts.cascada (respaldo cuando no hay comparativas). */
    private function cascade(array $v): array
    {
        $steps = [];
        $running = null;
        $add = function (string $key, string $type, ?string $amount) use (&$steps, &$running) {
            $label = ComparisonConcepts::meta($key)['label'];
            if ($type === 'delta') {
                $end = $amount !== null && $running !== null ? Figures::sum([$running, $amount]) : null;
                $steps[] = ['key' => $key, 'label' => $label, 'type' => 'delta', 'amount' => $amount, 'start' => $running, 'end' => $end, 'pending' => $amount === null];
                $running = $end;

                return;
            }
            $steps[] = ['key' => $key, 'label' => $label, 'type' => $type, 'amount' => $amount, 'start' => $amount === null ? null : '0.00', 'end' => $amount, 'pending' => $amount === null];
            $running = $amount;
        };
        $neg = fn (?string $x) => $x === null ? null : Money::str2(Money::of($x)->negated());
        $add('ingresos_operativos', 'total', $v['ingresos_operativos']);
        $add('opex_total', 'delta', $neg($v['opex_total']));
        $add('ebitda', 'subtotal', $v['ebitda']);
        $add('depreciacion_amortizacion', 'delta', $neg($v['depreciacion_amortizacion']));
        $add('ebit', 'subtotal', $v['ebit']);
        $add('resultado_financiero_neto', 'delta', $v['resultado_financiero_neto']);
        $add('ebt', 'subtotal', $v['ebt']);
        $add('isr', 'delta', $neg($v['isr']));
        $add('utilidad_neta', 'total', $v['utilidad_neta']);

        return $steps;
    }

    /** Fuentes del periodo (sin números de cuenta completos: nombres de archivo enmascarados). */
    private function sources(array $d): array
    {
        $s = (array) ($d['sources'] ?? []);
        $period = (array) ($d['meta']['period'] ?? []);
        $periodRange = isset($period['start'], $period['end']) ? $period['start'].' a '.$period['end'] : '';
        $rows = [];
        // Fuentes de datos simples: las conocidas primero y luego cualquier otra que agregue el
        // dataset (p. ej. NOI de corporativo) con forma {label, status, detail}. Tolerante a
        // claves nuevas o ausentes y a estados nuevos (se rotulan legibles, sin inventar).
        $special = ['cuentas', 'ajustes', 'procesamiento', 'exportaciones'];
        $simple = array_unique(array_merge(['radiografia', 'erp', 'nomina'], array_keys($s)));
        $simpleRow = fn (array $src) => ['label' => (string) $src['label'], 'status' => (string) ($src['status'] ?? ''),
            'status_label' => (string) ($src['status_label'] ?? self::statusLabel((string) ($src['status'] ?? ''))),
            'period' => is_string($src['period'] ?? null) ? $src['period'] : $periodRange,
            'detail' => (string) ErFormat::mask(is_scalar($src['detail'] ?? null) ? (string) $src['detail'] : '')];
        foreach ($simple as $k) {
            if (in_array($k, $special, true) || ! is_array($s[$k] ?? null)) {
                continue;
            }
            if (isset($s[$k]['label'])) {
                $rows[] = $simpleRow($s[$k]);
            } elseif (array_is_list($s[$k])) {
                // Fuente con varias partes (lista de {label, status, …}).
                foreach ($s[$k] as $part) {
                    if (is_array($part) && isset($part['label'])) {
                        $rows[] = $simpleRow($part);
                    }
                }
            }
        }
        foreach ((array) ($s['cuentas'] ?? []) as $c) {
            $u = $c['upload'] ?? null;
            $detail = 'Sin archivo cargado';
            $range = '';
            if ($u) {
                $control = $u['control_ok'] === null ? 'sin totales de control' : ($u['control_ok'] ? 'cuadra con los totales del banco' : 'NO cuadra con los totales del banco');
                $detail = trim(ErFormat::mask((string) ($u['account'] ?? '')).' · '.ErFormat::mask((string) $u['name']).' · '.(int) $u['movements'].' movimientos · control '.$control, ' ·');
                $range = trim(($u['first_date'] ?? '').' a '.($u['last_date'] ?? ''), ' a');
            }
            $rows[] = ['label' => (string) ($c['label'] ?? 'Cuenta bancaria'), 'status' => (string) ($c['status'] ?? ''), 'status_label' => self::statusLabel((string) ($c['status'] ?? '')), 'period' => $range, 'detail' => $detail];
        }
        if (is_array($s['ajustes'] ?? null)) {
            $a = $s['ajustes'];
            $rows[] = ['label' => (string) ($a['label'] ?? 'Ajustes contables'), 'status' => (string) ($a['status'] ?? ''), 'status_label' => self::statusLabel((string) ($a['status'] ?? '')), 'period' => $periodRange, 'detail' => (string) ($a['detail'] ?? '')];
        }
        if (is_array($s['procesamiento'] ?? null)) {
            $p = $s['procesamiento'];
            $run = $p['last_run'] ?? null;
            $rows[] = ['label' => (string) ($p['label'] ?? 'Conciliación y clasificación'), 'status' => (string) ($p['status'] ?? ''), 'status_label' => self::statusLabel((string) ($p['status'] ?? '')), 'period' => $periodRange,
                'detail' => $run ? 'Último procesamiento '.($run['at'] ?? '—').' por '.($run['user'] ?? '—') : 'Sin procesar'];
        }

        return $rows;
    }

    /** Rótulo legible de un estado de fuente (los estados nuevos se muestran humanizados, nunca vacíos). */
    public static function statusLabel(string $status): string
    {
        return self::SOURCE_STATUS[$status] ?? ($status === '' ? 'Sin estado' : ucfirst(str_replace('_', ' ', $status)));
    }

    private function validation(array $d): array
    {
        $v = (array) ($d['validation'] ?? []);
        $checks = array_values((array) ($d['checks'] ?? []));
        $failed = array_values(array_filter($checks, fn ($c) => ! ($c['ok'] ?? false)));
        $rec = (array) ($d['radiography_reconciliation'] ?? []);
        $status = (string) ($v['status'] ?? ($failed === [] ? 'ok' : 'con_diferencias'));

        return [
            'status' => $status,
            'status_label' => self::VALIDATION_LABELS[$status] ?? $status,
            'checks_total' => count($checks),
            'checks_ok' => count($checks) - count($failed),
            'checks' => $checks,
            'failed' => $failed,
            'partition_ok' => (bool) ($v['partition']['all']['ok'] ?? true),
            'integrity' => (string) ($v['integrity']['status'] ?? ''),
            'unresolved' => array_values(array_map(fn ($u) => ['label' => (string) ($u['label'] ?? ''), 'count' => (int) ($u['count'] ?? 0), 'amount' => $u['amount'] ?? null, 'blocking' => (bool) ($u['blocking'] ?? true)], (array) ($v['unresolved'] ?? []))),
            'radiography' => $rec === [] ? null : [
                'er' => $rec['er_ebitda_sucursales'] ?? null, 'radiografia' => $rec['radiografia_ebitda'] ?? null,
                'difference' => $rec['difference'] ?? null, 'ok' => (bool) ($rec['ok'] ?? false),
                'explanations' => array_values((array) ($rec['explanations'] ?? [])),
                'components' => array_values((array) ($rec['components'] ?? [])),
                'informative' => array_values((array) ($rec['informative'] ?? [])),
            ],
            'conciliation' => (array) ($d['conciliation'] ?? []),
        ];
    }
}
