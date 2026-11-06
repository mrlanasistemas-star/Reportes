<?php

namespace App\Services\EstadoResultados\Comparatives;

/**
 * ¿Dos periodos (resúmenes) son comparables? Se usa en TODA comparación de pares
 * (anterior, mismo periodo del año anterior, personalizado y acumulado vs año anterior).
 *
 * Nivel (`level`) — nunca se responde sólo «no son comparables»:
 *   comparable    → mismas fuentes y misma duración: cifras, variaciones y lectura completas.
 *   con_reservas  → fuentes distintas o distinta duración: se muestran cifras, variación $ y %
 *                   y la lectura favorable/desfavorable, MARCADAS «con reservas» en los conceptos
 *                   que la diferencia de fuentes afecta, y la lista EXACTA de lo que falta
 *                   (`missing`: qué fuente, de qué periodo, dónde se carga).
 *   no_comparable → alguno de los periodos no tiene datos (no hay nada contra qué comparar).
 *
 * Decisión sobre la lectura favorable/desfavorable: se conserva con la advertencia, sólo en los
 * conceptos afectados; los no afectados (p. ej. Ingresos operativos, que vienen de Radiografía y
 * no dependen del ERP ni de bancos) conservan su lectura normal. Ocultarla del todo dejaba al
 * usuario sin ninguna conclusión aunque la mayoría de las cifras sí fueran comparables.
 *
 * `comparable` (bool) se conserva: true sólo en el nivel «comparable».
 *
 *  Motivos (`reasons`, generan «con reservas» o «no comparable»):
 *   - sin_datos_actual / sin_datos_comparacion: alguno de los periodos no tiene datos.
 *   - duracion_distinta: distinta duración en días (no se normaliza a días equivalentes).
 *   - cobertura_distinta: una fuente (ERP, NOI, estados de cuenta, NOI de corporativo) está en un periodo y falta en el otro.
 *  ADVERTENCIAS (no cambian el nivel):
 *   - preliminar, estado_distinto, reglas_distintas, ajustes_distintos.
 */
class PeriodComparability
{
    public const MESSAGES = [
        'sin_datos_actual' => 'El periodo seleccionado no tiene datos.',
        'sin_datos_comparacion' => 'El periodo de comparación no tiene datos.',
        'duracion_distinta' => 'Los periodos tienen distinta duración (%s): las variaciones no son equivalentes.',
        'cobertura_distinta' => 'Las fuentes disponibles no son las mismas: %s.',
        'preliminar' => 'Al menos un periodo no está aprobado (%s): las cifras pueden cambiar.',
        'estado_distinto' => 'Se compara un periodo aprobado contra uno no aprobado.',
        'reglas_distintas' => 'Los periodos se calcularon con versiones de reglas distintas (%s).',
        'ajustes_distintos' => 'Los ajustes contables no tienen el mismo grado de captura: %s.',
    ];

    /** Fuente → dónde se resuelve y qué conceptos de la comparación afecta. */
    public const SOURCES = [
        'erp' => ['label' => 'ERP de gastos', 'where' => 'carga', 'where_label' => 'Carga de archivos → Gastos ERP (y «Cargar registros»)'],
        'noi' => ['label' => 'Nómina NOI de sucursales', 'where' => 'carga', 'where_label' => 'Carga de archivos → NOI Nómina (y «Cargar registros»)'],
        'bancos' => ['label' => 'Estado de cuenta', 'where' => 'er', 'where_label' => 'Estado de Resultados → Fuentes'],
        'noi_corp' => ['label' => 'NOI de corporativo', 'where' => 'er', 'where_label' => 'Estado de Resultados → Fuentes'],
    ];

    /** Conceptos que NO dependen de ERP, NOI, bancos ni NOI de corporativo (vienen de Radiografía o de Ajustes). */
    public const UNAFFECTED_BY_COVERAGE = ['ingresos_operativos', 'depreciacion_amortizacion', 'isr'];

    /**
     * @return array{comparable:bool, level:string, level_label:string, headline:string, reasons:list<array{code:string,message:string}>,
     *               warnings:list<array{code:string,message:string}>, missing:list<array<string,mixed>>, unaffected:list<string>}
     */
    public function evaluate(array $a, array $b): array
    {
        $reasons = [];
        $warnings = [];
        $missing = [];
        $add = function (array &$list, string $code, string ...$args) {
            $list[] = ['code' => $code, 'message' => $args === [] ? self::MESSAGES[$code] : sprintf(self::MESSAGES[$code], ...$args)];
        };
        $la = (string) ($a['period']['label'] ?? 'el periodo seleccionado');
        $lb = (string) ($b['period']['label'] ?? 'el periodo de comparación');

        if (($a['status'] ?? 'sin_datos') === 'sin_datos') {
            $add($reasons, 'sin_datos_actual');
        }
        if (($b['status'] ?? 'sin_datos') === 'sin_datos') {
            $add($reasons, 'sin_datos_comparacion');
        }
        if ($reasons !== []) {
            return $this->result('no_comparable', $reasons, $warnings, [], [], $la, $lb);
        }

        $durationDiffers = false;
        $da = $a['period']['days'] ?? null;
        $db = $b['period']['days'] ?? null;
        if ($da !== null && $db !== null && $da !== $db) {
            $add($reasons, 'duracion_distinta', $da.' vs '.$db.' días');
            $durationDiffers = true;
        }

        $diff = [];
        $av = $a['availability'] ?? [];
        $bv = $b['availability'] ?? [];
        $need = function (string $side, string $source, ?string $detail = null) use (&$missing, $a, $b) {
            $s = $side === 'actual' ? $a : $b;
            $meta = self::SOURCES[$source];
            $label = $meta['label'].($detail ? ' «'.$detail.'»' : '');
            $missing[] = [
                'side' => $side,
                'period_id' => isset($s['period']['id']) ? (int) $s['period']['id'] : null,
                'period_label' => (string) ($s['period']['label'] ?? ''),
                'source' => $source,
                'source_label' => $label,
                'where' => $meta['where'],
                'where_label' => $meta['where_label'],
                'message' => $label.' de '.($s['period']['label'] ?? 'ese periodo').' → '.$meta['where_label'],
            ];
        };
        foreach (['erp' => 'ERP de gastos', 'noi' => 'nómina NOI', 'noi_corp' => 'NOI de corporativo'] as $k => $label) {
            $x = $av[$k] ?? ($k === 'noi_corp' ? null : false);
            $y = $bv[$k] ?? ($k === 'noi_corp' ? null : false);
            if ($x === null || $y === null) {
                continue; // resumen anterior a esa fuente: no se puede afirmar que difiera
            }
            if ((bool) $x !== (bool) $y) {
                $diff[] = $label.' '.($x ? 'sólo en el periodo seleccionado' : 'sólo en el periodo de comparación');
                $need($x ? 'comparacion' : 'actual', $k);
            }
        }
        $ba = (int) ($av['bancos']['cargadas'] ?? 0);
        $bb = (int) ($bv['bancos']['cargadas'] ?? 0);
        if ($ba !== $bb) {
            $diff[] = 'estados de cuenta cargados '.$ba.' vs '.$bb;
            $fa = array_column((array) ($av['bancos']['faltantes'] ?? []), 'label', 'key');
            $fb = array_column((array) ($bv['bancos']['faltantes'] ?? []), 'label', 'key');
            foreach (array_diff_key($fa, $fb) as $label) {
                $need('actual', 'bancos', $label);
            }
            foreach (array_diff_key($fb, $fa) as $label) {
                $need('comparacion', 'bancos', $label);
            }
            if ($fa === [] && $fb === []) { // resúmenes sin detalle de cuentas
                $need($ba < $bb ? 'actual' : 'comparacion', 'bancos');
            }
        }
        if ($diff !== []) {
            $add($reasons, 'cobertura_distinta', implode('; ', $diff));
        }

        $notApproved = array_values(array_filter([$a, $b], fn ($s) => ($s['status'] ?? null) !== 'aprobado'));
        if ($notApproved !== []) {
            $add($warnings, 'preliminar', implode(', ', array_map(fn ($s) => ($s['period']['label'] ?? '').': '.($s['status_label'] ?? ''), $notApproved)));
            if (count($notApproved) === 1) {
                $add($warnings, 'estado_distinto');
            }
        }
        $ra = (string) ($a['report']['rules_version'] ?? '');
        $rb = (string) ($b['report']['rules_version'] ?? '');
        if ($ra !== '' && $rb !== '' && $ra !== $rb) {
            $add($warnings, 'reglas_distintas', $ra.' vs '.$rb);
        }
        $adjDiff = [];
        foreach (['depreciacion_amortizacion' => 'D&A', 'resultado_financiero_neto' => 'resultado financiero', 'isr' => 'ISR'] as $k => $label) {
            if (($a['complete'][$k] ?? true) !== ($b['complete'][$k] ?? true)) {
                $adjDiff[] = $label;
            }
        }
        if ($adjDiff !== []) {
            $add($warnings, 'ajustes_distintos', implode(', ', $adjDiff));
        }

        // Con distinta duración NINGÚN concepto es equivalente; con distinta cobertura sólo los que no dependen de ella.
        $unaffected = $durationDiffers ? [] : self::UNAFFECTED_BY_COVERAGE;

        return $this->result($reasons === [] ? 'comparable' : 'con_reservas', $reasons, $warnings, $missing, $reasons === [] ? [] : $unaffected, $la, $lb);
    }

    private function result(string $level, array $reasons, array $warnings, array $missing, array $unaffected, string $la, string $lb): array
    {
        $headline = match ($level) {
            'comparable' => 'Comparación completa: '.$la.' contra '.$lb.' con las mismas fuentes.',
            'no_comparable' => 'No hay contra qué comparar: '.implode(' ', array_column($reasons, 'message')),
            default => $missing !== []
                ? 'Comparación con reservas: para comparar '.$la.' contra '.$lb.' falta '.implode('; ', array_column($missing, 'message')).'.'
                : 'Comparación con reservas: '.implode(' ', array_column($reasons, 'message')),
        };

        return [
            'comparable' => $level === 'comparable',
            'level' => $level,
            'level_label' => ['comparable' => 'Comparable', 'con_reservas' => 'Con reservas', 'no_comparable' => 'Sin datos para comparar'][$level],
            'headline' => $headline,
            'reasons' => $reasons,
            'warnings' => $warnings,
            'missing' => $missing,
            'unaffected' => array_values($unaffected),
        ];
    }
}
