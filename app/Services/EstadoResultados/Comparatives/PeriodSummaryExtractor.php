<?php

namespace App\Services\EstadoResultados\Comparatives;

use App\Services\EstadoResultados\Support\Money;

/**
 * Reduce un dataset canónico del Estado de Resultados (snapshot aprobado o en vivo,
 * SIN redactar) a un RESUMEN ligero y estable para comparativas: importes del modelo
 * de un solo EBITDA, márgenes, categorías reales de OPEX, disponibilidad de fuentes
 * y estado. No recalcula reglas contables: sólo LEE el dataset.
 *
 * Claves consumidas (en este orden de preferencia):
 *  - Modelo nuevo (Fase 2): results.{ingresos_operativos, opex_total, ebitda, depreciacion_amortizacion,
 *    ebit, resultado_financiero_neto (con signo), ebt, isr (null = pendiente), utilidad_neta}.
 *  - Compatibilidad (datasets/snapshots anteriores): results.{ingresos, ebitda_final|ebitda_sucursales,
 *    depreciacion_amortizacion, ebit, resultado_financiero_neto (como gasto), ebt, isr, utilidad_neta}
 *    + totales de las secciones de OPEX del statement + adjustments[isr].state.
 *  El signo del resultado financiero se verifica contra EBT = EBIT + RFN (y se corrige si viene como gasto).
 *  - statement[] (secciones de gasto) → categorías de OPEX; sources.* → disponibilidad; meta.report → estado.
 *  - Retiros DG y socios (25-nov-2026, sólo consolidado): results.{retiros_dg_socios, resultado_despues_retiros}.
 *    LEGADO: los periodos calculados/aprobados antes traen DG como categoría de OPEX (`opex_dg` en el modelo
 *    nuevo, sección `dg` en el de compatibilidad) y no traen retiros. Para comparar «manzanas con manzanas»
 *    se REEXPRESAN en la lectura (el snapshot no se toca): la categoría DG sale del OPEX y pasa a retiros,
 *    EBITDA/EBIT/EBT/Utilidad suben en ese importe y el Resultado después de retiros queda igual a la
 *    utilidad (o EBT) original. Lo que antes iba «fuera del ER» (personal/socios) no se puede recuperar
 *    del snapshot: esos periodos sólo cuentan su DG operativo/remuneración como retiro (se advierte).
 */
class PeriodSummaryExtractor
{
    public const VERSION = 3; // 2: availability con faltantes de bancos y NOI de corporativo · 3: retiros DG y socios

    /** Secciones del statement que forman el OPEX en el dataset de compatibilidad. */
    private const LEGACY_OPEX_SECTIONS = ['opex_sucursales', 'nomina_sucursales', 'opex_corporativo', 'nomina_corporativa', 'dg'];

    private const DA_ADJ = ['depreciacion', 'amortizacion'];

    private const FIN_ADJ = ['intereses_bbva', 'intereses_lerom', 'otros_intereses', 'otros_gastos_financieros', 'ingresos_financieros'];

    public const STATUS_LABELS = [
        'sin_datos' => 'Sin datos',
        'parcial' => 'Parcial (faltan fuentes)',
        'preliminar' => 'Preliminar',
        'listo_revision' => 'Listo para revisión (no aprobado)',
        'aprobado' => 'Aprobado',
    ];

    public const SOURCE_LABELS = [
        'snapshot' => 'Versión aprobada (snapshot inmutable)',
        'en_vivo' => 'Cálculo en vivo',
    ];

    /**
     * Reduce un dataset sin redactar a su resumen para comparativas.
     *
     * Reglas: corrige el signo del resultado financiero si el dataset lo trae como gasto (validado
     * contra EBT = EBIT + RFN); ISR «sin información» ⇒ ISR y utilidad neta null (pendiente, nunca
     * $0); sólo ADVIERTE incoherencias (Ingresos − OPEX ≠ EBITDA, Σ categorías ≠ OPEX), nunca ajusta;
     * estado: snapshot ⇒ aprobado, sin datos ⇒ todos los importes null, faltan fuentes ⇒ parcial,
     * preliminar o listo_revision. Función pura.
     *
     * @param  array  $d  dataset canónico (results, statement, adjustments, sources, completeness, meta)
     * @return array<string, mixed> resumen con period, scope, status, source, report, values, complete,
     *                              opex_categories, availability, has_data y warnings
     */
    public function extract(array $d): array
    {
        $r = (array) ($d['results'] ?? []);
        $scope = (array) ($d['meta']['scope'] ?? []);
        $consolidated = (bool) ($scope['consolidated'] ?? (($scope['key'] ?? 'CONSOLIDADO') === 'CONSOLIDADO'));
        $newModel = array_key_exists('opex_total', $r) || array_key_exists('ebitda', $r);
        $adj = [];
        foreach ((array) ($d['adjustments'] ?? []) as $a) {
            if (isset($a['key'])) {
                $adj[$a['key']] = (string) ($a['state'] ?? '');
            }
        }
        $warnings = [];

        $categories = $this->categories($d, $consolidated);
        $catSum = Figures::sum(array_column($categories, 'amount'));

        $ingresos = Figures::amount($r['ingresos_operativos'] ?? $r['ingresos'] ?? null);
        if ($newModel) {
            $opex = Figures::amount($r['opex_total'] ?? null) ?? $catSum;
            $ebitda = Figures::amount($r['ebitda'] ?? null);
        } else {
            $opex = $catSum;
            $ebitda = Figures::amount($consolidated ? ($r['ebitda_final'] ?? null) : ($r['ebitda_sucursales'] ?? null));
        }
        $da = Figures::amount($r['depreciacion_amortizacion'] ?? null);
        $ebit = Figures::amount($r['ebit'] ?? null);
        $ebt = Figures::amount($r['ebt'] ?? null);
        $rfn = Figures::amount($r['resultado_financiero_neto'] ?? null);
        $signCorrected = false;
        if ($rfn !== null && $ebit !== null && $ebt !== null && ! Money::equalsCents(Figures::sum([$ebit, $rfn]), $ebt)
            && Money::equalsCents(Figures::sub($ebit, $rfn), $ebt)) {
            // El dataset expresa el resultado financiero como GASTO (positivo = gasto): se presenta con signo.
            $rfn = Money::str2(Money::of($rfn)->negated());
            $signCorrected = true;
        }

        $isrPending = array_key_exists('isr', $r) && $r['isr'] === null || ($adj['isr'] ?? null) === 'sin_informacion';
        $isr = $isrPending ? null : Figures::amount($r['isr'] ?? null);
        $utilidad = $isr === null ? null : Figures::amount($r['utilidad_neta'] ?? null);

        // Retiros DG y socios (sólo consolidado) y reexpresión de periodos legado (ver docblock).
        $retiros = null;
        $after = null;
        $legacyDg = null;
        if (! array_key_exists('retiros_dg_socios', $r)) {
            $dgCats = array_filter($categories, fn ($c) => in_array($c['key'], ComparisonConcepts::LEGACY_DG_CATEGORIES, true));
            if ($dgCats !== []) {
                $legacyDg = Figures::sum(array_column($dgCats, 'amount'));
                $categories = array_values(array_filter($categories, fn ($c) => ! in_array($c['key'], ComparisonConcepts::LEGACY_DG_CATEGORIES, true)));
                $catSum = Figures::sum(array_column($categories, 'amount'));
                $up = fn (?string $v) => $v === null ? null : Figures::sum([$v, $legacyDg]);
                $opex = Figures::sub($opex, $legacyDg);
                [$ebitda, $ebit, $ebt, $utilidad] = [$up($ebitda), $up($ebit), $up($ebt), $up($utilidad)];
                if (! Money::of($legacyDg)->isZero()) {
                    $warnings[] = 'Periodo calculado antes de separar los retiros: sus $'.$legacyDg.' de Dirección General se muestran como «Retiros DG y socios» (fuera del EBITDA) para poder compararlo.';
                }
            }
            if ($consolidated) {
                $retiros = $legacyDg ?? '0.00';
            }
        } elseif ($consolidated) {
            $retiros = Figures::amount($r['retiros_dg_socios']);
        }
        if ($consolidated && $retiros !== null) {
            $after = array_key_exists('resultado_despues_retiros', $r) && $legacyDg === null && $r['resultado_despues_retiros'] !== null
                ? Figures::amount($r['resultado_despues_retiros'])
                : Figures::sub($utilidad ?? $ebt, $retiros);
        }

        // Coherencia (sólo advierte; nunca "ajusta" cifras).
        if ($ingresos !== null && $opex !== null && $ebitda !== null && ! Money::equalsCents(Figures::sub($ingresos, $opex), $ebitda)) {
            $warnings[] = 'Ingresos − OPEX no coincide con el EBITDA del dataset (diferencia $'.Figures::sub(Figures::sub($ingresos, $opex), $ebitda).').';
        }
        if ($opex !== null && $catSum !== null && $categories !== [] && ! Money::equalsCents($opex, $catSum)) {
            $warnings[] = 'La suma de las categorías de OPEX no coincide con el OPEX total (diferencia $'.Figures::sub($opex, $catSum).').';
        }

        $values = [
            'ingresos_operativos' => $ingresos, 'opex_total' => $opex, 'ebitda' => $ebitda,
            'depreciacion_amortizacion' => $da, 'ebit' => $ebit, 'resultado_financiero_neto' => $rfn,
            'ebt' => $ebt, 'isr' => $isr, 'utilidad_neta' => $utilidad,
            'retiros_dg_socios' => $retiros, 'resultado_despues_retiros' => $after,
        ];

        $completeness = (array) ($d['completeness'] ?? []);
        $missing = fn (array $keys) => $adj !== [] ? array_values(array_filter($keys, fn ($k) => ($adj[$k] ?? null) === 'sin_informacion')) : null;
        $daMissing = $missing(self::DA_ADJ);
        $finMissing = $missing(self::FIN_ADJ);
        $daOk = $daMissing === null ? (bool) ($completeness['ebit']['complete'] ?? true) : $daMissing === [];
        $finOk = $finMissing === null ? (bool) ($completeness['ebt']['complete'] ?? true) : $finMissing === [];
        $complete = [
            'ingresos_operativos' => true, 'opex_total' => true, 'ebitda' => true,
            'depreciacion_amortizacion' => $daOk, 'ebit' => $daOk, 'resultado_financiero_neto' => $finOk,
            'ebt' => $daOk && $finOk, 'isr' => $isr !== null, 'utilidad_neta' => $daOk && $finOk && $isr !== null,
            'retiros_dg_socios' => $retiros !== null, 'resultado_despues_retiros' => $after !== null && $daOk && $finOk,
        ];

        $availability = $this->availability($d, $adj);
        $hasData = ($ingresos !== null && ! Money::of($ingresos)->isZero()) || ($opex !== null && ! Money::of($opex)->isZero())
            || $availability['erp'] || $availability['noi'] || $availability['bancos']['cargadas'] > 0 || $availability['ajustes']['capturado'] > 0;

        $report = (array) ($d['meta']['report'] ?? []);
        $source = (string) ($report['source'] ?? 'en_vivo');
        $status = match (true) {
            $source === 'snapshot' => 'aprobado',
            ! $hasData => 'sin_datos',
            ! $availability['erp'] || ! $availability['noi'] || $availability['bancos']['cargadas'] < $availability['bancos']['total'] => 'parcial',
            (bool) ($report['preliminary'] ?? true) => 'preliminar',
            default => 'listo_revision',
        };
        if ($status === 'sin_datos') {
            // Sin datos ≠ cero: ningún importe se presenta como $0.00.
            $values = array_map(fn () => null, $values);
            $categories = [];
        }

        $period = (array) ($d['meta']['period'] ?? []);

        return [
            'period' => [
                'id' => (int) ($period['id'] ?? 0), 'code' => (string) ($period['code'] ?? ''), 'label' => (string) ($period['label'] ?? ''),
                'start' => (string) ($period['start'] ?? ''), 'end' => (string) ($period['end'] ?? ''),
                'days' => isset($period['start'], $period['end']) ? (int) ((strtotime($period['end']) - strtotime($period['start'])) / 86400) + 1 : null,
            ],
            'scope' => ['key' => (string) ($scope['key'] ?? 'CONSOLIDADO'), 'label' => (string) ($scope['label'] ?? ''), 'consolidated' => $consolidated],
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status],
            'source' => $source,
            'source_label' => self::SOURCE_LABELS[$source] ?? $source,
            'report' => [
                'status' => $report['status'] ?? null,
                'status_label' => $report['status_label'] ?? null,
                'version' => $report['version'] ?? null,
                'preliminary' => (bool) ($report['preliminary'] ?? false),
                'preliminary_reasons' => array_values((array) ($report['preliminary_reasons'] ?? [])),
                'approved_at' => $report['approved_at'] ?? null,
                'rules_version' => (string) ($report['rules_version'] ?? $d['meta']['rules_version'] ?? ''),
                'dataset_hash' => (string) ($d['meta']['dataset_hash'] ?? ''),
            ],
            'model' => $newModel ? 'ebitda_unico' : 'compatibilidad',
            'sign_corrected' => $signCorrected,
            'legacy_dg_restated' => $legacyDg,
            'values' => $values,
            'complete' => $complete,
            'missing_adjustments' => array_values(array_merge($daMissing ?? [], $finMissing ?? [], $isr === null ? ['isr'] : [])),
            'opex_categories' => $categories,
            'availability' => $availability,
            'has_data' => $hasData,
            'warnings' => $warnings,
        ];
    }

    /**
     * Categorías REALES de OPEX tal como las presenta el statement del dataset.
     *  - Modelo nuevo: sección `opex` con `categories` (o `groups`) → cada una es categoría; si sólo trae
     *    `lines`, cada línea es categoría.
     *  - Compatibilidad: secciones opex_sucursales / nomina_sucursales / opex_corporativo / nomina_corporativa / dg.
     *
     * @return list<array{key:string,label:string,amount:string,centralized:bool,sensitive:?string,lines:list<array{key:string,label:string,amount:string}>}>
     */
    private function categories(array $d, bool $consolidated): array
    {
        $sections = [];
        foreach ((array) ($d['statement'] ?? []) as $b) {
            if (($b['type'] ?? null) === 'section' && isset($b['key'])) {
                $sections[$b['key']] = $b;
            }
        }
        $out = [];
        $push = function (string $key, string $label, mixed $amount, array $lines) use (&$out) {
            $out[] = [
                'key' => $key,
                'label' => $label,
                'amount' => Money::str2($amount),
                'centralized' => in_array($key, ComparisonConcepts::CENTRALIZED_CATEGORIES, true),
                'sensitive' => ComparisonConcepts::SENSITIVE[$key] ?? null,
                'lines' => array_values(array_map(fn ($l) => [
                    'key' => (string) ($l['key'] ?? ''), 'label' => (string) ($l['label'] ?? ''), 'amount' => Money::str2($l['amount'] ?? 0),
                ], array_filter((array) $lines, fn ($l) => is_array($l) && isset($l['amount'])))),
            ];
        };

        if (isset($sections['opex'])) {
            $s = $sections['opex'];
            $groups = (array) ($s['categories'] ?? $s['groups'] ?? []);
            if ($groups !== []) {
                foreach ($groups as $g) {
                    $push((string) ($g['key'] ?? ''), (string) ($g['label'] ?? $g['title'] ?? $g['key'] ?? ''), $g['total']['amount'] ?? $g['amount'] ?? 0, (array) ($g['lines'] ?? []));
                }
            } else {
                foreach ((array) ($s['lines'] ?? []) as $l) {
                    $push((string) ($l['key'] ?? ''), (string) ($l['label'] ?? ''), $l['amount'] ?? 0, []);
                }
            }

            return $out;
        }

        foreach (self::LEGACY_OPEX_SECTIONS as $key) {
            $s = $sections[$key] ?? null;
            if (! $s || ! ($s['applies'] ?? true)) {
                continue;
            }
            $label = mb_convert_case(mb_strtolower((string) ($s['title'] ?? $key)), MB_CASE_TITLE);
            $push($key, self::LEGACY_LABELS[$key] ?? $label, $s['total']['amount'] ?? 0, (array) ($s['lines'] ?? []));
        }

        return $out;
    }

    /** Etiquetas de las secciones de OPEX del dataset de compatibilidad. */
    private const LEGACY_LABELS = [
        'opex_sucursales' => 'OPEX sucursales',
        'nomina_sucursales' => 'Nómina y capital humano sucursales',
        'opex_corporativo' => 'OPEX corporativo',
        'nomina_corporativa' => 'Nómina corporativa',
        'dg' => 'Gastos operativos Dirección General',
    ];

    /**
     * Disponibilidad de fuentes del periodo según el bloque sources del dataset: ERP, NOI de
     * sucursales, estados de cuenta (cargadas/total y faltantes), NOI de corporativo y grado de
     * captura de ajustes.
     *
     * @param  array  $d  dataset canónico
     * @param  array<string, string>  $adj  estado por clave de ajuste (capturado, sin_informacion…)
     * @return array<string, mixed>
     */
    private function availability(array $d, array $adj): array
    {
        $s = (array) ($d['sources'] ?? []);
        $cuentas = (array) ($s['cuentas'] ?? []);
        $counts = ['capturado' => 0, 'confirmado_cero' => 0, 'sin_informacion' => 0];
        foreach ($adj as $state) {
            if (isset($counts[$state])) {
                $counts[$state]++;
            }
        }

        return [
            'radiografia' => ($s['radiografia']['status'] ?? null) === 'procesado',
            'erp' => (int) ($s['erp']['count'] ?? 0) > 0,
            'erp_count' => (int) ($s['erp']['count'] ?? 0),
            'noi' => (int) ($s['nomina']['count'] ?? 0) > 0,
            'noi_count' => (int) ($s['nomina']['count'] ?? 0),
            'bancos' => [
                'cargadas' => count(array_filter($cuentas, fn ($c) => ($c['status'] ?? 'pendiente') !== 'pendiente')),
                'total' => count($cuentas),
                // Qué estado de cuenta falta (para decir EXACTAMENTE qué subir al comparar).
                'faltantes' => array_values(array_map(fn ($c) => ['key' => (string) ($c['key'] ?? ''), 'label' => (string) ($c['label'] ?? '')],
                    array_filter($cuentas, fn ($c) => ($c['status'] ?? 'pendiente') === 'pendiente'))),
            ],
            // NOI de corporativo (fuente propia del ER); null = dataset anterior a esta fuente.
            'noi_corp' => array_key_exists('noi_corporativo', $s) ? ! empty($s['noi_corporativo']['upload']) : null,
            'ajustes' => $counts,
            'procesamiento' => (string) ($s['procesamiento']['status'] ?? 'pendiente'),
        ];
    }
}
