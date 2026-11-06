<?php

namespace App\Services\EstadoResultados\Comparatives;

/**
 * ¿Dos periodos (resúmenes) son comparables? Se usa en TODA comparación de pares
 * (anterior, mismo periodo del año anterior, personalizado y acumulado vs año anterior).
 *
 *  Motivos BLOQUEANTES (comparable = false ⇒ se suprimen % y conclusiones favorable/desfavorable):
 *   - sin_datos_actual / sin_datos_comparacion: alguno de los periodos no tiene datos.
 *   - duracion_distinta: distinta duración en días (no se normaliza a días equivalentes).
 *   - cobertura_distinta: una fuente (ERP, NOI, estados de cuenta) está en un periodo y falta en el otro.
 *  ADVERTENCIAS (no bloquean):
 *   - preliminar: alguno no está aprobado / es preliminar o parcial (cifras pueden cambiar).
 *   - estado_distinto: un periodo aprobado contra otro no aprobado.
 *   - reglas_distintas: versiones de reglas distintas.
 *   - ajustes_distintos: los ajustes contables (D&A, financieros, ISR) están completos en uno y no en el otro
 *     (las filas afectadas se suprimen por concepto).
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

    /** @return array{comparable:bool, reasons:list<array{code:string,message:string}>, warnings:list<array{code:string,message:string}>} */
    public function evaluate(array $a, array $b): array
    {
        $reasons = [];
        $warnings = [];
        $add = function (array &$list, string $code, string ...$args) {
            $list[] = ['code' => $code, 'message' => $args === [] ? self::MESSAGES[$code] : sprintf(self::MESSAGES[$code], ...$args)];
        };

        if (($a['status'] ?? 'sin_datos') === 'sin_datos') {
            $add($reasons, 'sin_datos_actual');
        }
        if (($b['status'] ?? 'sin_datos') === 'sin_datos') {
            $add($reasons, 'sin_datos_comparacion');
        }
        if ($reasons !== []) {
            return ['comparable' => false, 'reasons' => $reasons, 'warnings' => $warnings];
        }

        $da = $a['period']['days'] ?? null;
        $db = $b['period']['days'] ?? null;
        if ($da !== null && $db !== null && $da !== $db) {
            $add($reasons, 'duracion_distinta', $da.' vs '.$db.' días');
        }

        $diff = [];
        $av = $a['availability'] ?? [];
        $bv = $b['availability'] ?? [];
        foreach (['erp' => 'ERP de gastos', 'noi' => 'nómina NOI'] as $k => $label) {
            if ((bool) ($av[$k] ?? false) !== (bool) ($bv[$k] ?? false)) {
                $diff[] = $label.' '.(($av[$k] ?? false) ? 'sólo en el periodo seleccionado' : 'sólo en el periodo de comparación');
            }
        }
        $ba = (int) ($av['bancos']['cargadas'] ?? 0);
        $bb = (int) ($bv['bancos']['cargadas'] ?? 0);
        if ($ba !== $bb) {
            $diff[] = 'estados de cuenta cargados '.$ba.' vs '.$bb;
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

        return ['comparable' => $reasons === [], 'reasons' => $reasons, 'warnings' => $warnings];
    }
}
