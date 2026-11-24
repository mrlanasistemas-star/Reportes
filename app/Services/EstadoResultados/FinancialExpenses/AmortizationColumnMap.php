<?php

namespace App\Services\EstadoResultados\FinancialExpenses;

/**
 * ÚNICO lugar con el mapeo de encabezados de la tabla de amortización.
 *
 * Formatos reales confirmados (24-nov-2026, simuladores escaneados; se capturan en Excel/CSV):
 *  - LEROM: Pago · Fecha · Amortización Capital · Importe Comisión · IVA Comisión · Pago Total ·
 *    Saldo Insoluto. NO trae interés: «Importe Comisión» es la comisión por apertura prorrateada
 *    (campo `comision`, NUNCA interés) e «IVA Comisión» su IVA (campo `iva_comision`).
 *  - BBVA: Periodo · Fecha · Pago a Capital · Interés del Periodo · IVA · Pago fijo sin IVA ·
 *    Pago Mensual Total · Saldo Insoluto. «Pago fijo sin IVA» es informativo (`pago_sin_iva`).
 *
 * Normalización (normalize()): mayúsculas, sin acentos, todo lo que no sea letra, número,
 * '#' o '%' se vuelve espacio. Así «Núm.», «No.», «Pago #» y «Interés ordinario» se
 * comparan como «NUM», «NO», «PAGO #» e «INTERES ORDINARIO».
 *
 * Detección: 1) coincidencia EXACTA con un sinónimo. Caso especial «PAGO» a secas: es el NÚMERO
 * de pago cuando la misma fila ya trae otra columna de importe de pago («Pago Total», «Pago
 * Mensual Total»…); si no, es el importe. 2) Si un campo sigue sin columna, coincidencia por
 * PALABRAS (FUZZY, en orden): `any` = alguna palabra, `all` = todas, `none` = ninguna. Así «IVA
 * Comisión» no se toma como IVA de interés ni como comisión, «Pago a capital» es capital y no
 * pago, e «IVA de intereses» no es interés.
 */
final class AmortizationColumnMap
{
    /** Campo => sinónimos (ya normalizados). */
    public const SYNONYMS = [
        'numero' => ['NO', 'NUM', 'NUMERO', 'N', '#', 'NO #', 'PAGO #', 'NO DE PAGO', 'NUM DE PAGO', 'NUMERO DE PAGO', 'PERIODO', 'PARCIALIDAD', 'AMORTIZACION #', 'CUOTA #', 'NO PAGO'],
        'fecha' => ['FECHA', 'FECHA DE PAGO', 'FECHA PAGO', 'VENCIMIENTO', 'FECHA DE VENCIMIENTO', 'FECHA VENCIMIENTO', 'FECHA LIMITE DE PAGO', 'FECHA EXIGIBLE'],
        'pago' => ['PAGO', 'CUOTA', 'PAGO TOTAL', 'MENSUALIDAD', 'IMPORTE', 'IMPORTE PAGO', 'IMPORTE DEL PAGO', 'PAGO SEMANAL', 'PAGO MENSUAL', 'PAGO MENSUAL TOTAL', 'PAGO DIARIO', 'TOTAL A PAGAR', 'TOTAL PAGO', 'MONTO', 'MONTO DEL PAGO', 'PAGO FIJO', 'PAGO FIJO CON IVA'],
        'capital' => ['CAPITAL', 'AMORTIZACION', 'ABONO A CAPITAL', 'ABONO CAPITAL', 'PAGO A CAPITAL', 'PAGO CAPITAL', 'AMORTIZACION DE CAPITAL', 'AMORTIZACION A CAPITAL', 'AMORTIZACION CAPITAL'],
        'interes' => ['INTERES', 'INTERESES', 'INTERES ORDINARIO', 'INTERESES ORDINARIOS', 'INTERES DEL PERIODO', 'INTERESES DEL PERIODO', 'PAGO DE INTERES', 'PAGO DE INTERESES'],
        'iva' => ['IVA', 'IVA INTERES', 'IVA INTERESES', 'IVA DE INTERES', 'IVA DE INTERESES', 'IVA S INTERES', 'IVA SOBRE INTERES', 'IVA SOBRE INTERESES', 'IVA DEL INTERES'],
        'comision' => ['COMISION', 'COMISIONES', 'IMPORTE COMISION', 'IMPORTE DE COMISION', 'IMPORTE DE LA COMISION', 'COMISION POR APERTURA', 'COMISION APERTURA', 'COMISION DE APERTURA'],
        'iva_comision' => ['IVA COMISION', 'IVA COMISIONES', 'IVA DE COMISION', 'IVA DE LA COMISION', 'IVA SOBRE COMISION', 'IVA DE COMISIONES'],
        'pago_sin_iva' => ['PAGO FIJO SIN IVA', 'PAGO SIN IVA', 'PAGO TOTAL SIN IVA', 'PAGO MENSUAL SIN IVA'],
        'saldo' => ['SALDO', 'SALDO INSOLUTO', 'SALDO FINAL', 'SALDO PENDIENTE', 'SALDO CAPITAL', 'SALDO DE CAPITAL', 'SALDO INSOLUTO FINAL', 'CAPITAL INSOLUTO'],
    ];

    /**
     * Segunda pasada por palabras, en ESTE orden. `any`: contiene alguna; `all`: contiene todas;
     * `none`: no contiene ninguna.
     *
     * @var array<string, array{any?:list<string>, all?:list<string>, none?:list<string>}>
     */
    public const FUZZY = [
        'iva_comision' => ['all' => ['IVA'], 'any' => ['COMISION', 'COMISIONES']],
        'pago_sin_iva' => ['all' => ['SIN', 'IVA'], 'any' => ['PAGO', 'CUOTA', 'MENSUALIDAD', 'IMPORTE']],
        'iva' => ['any' => ['IVA'], 'none' => ['COMISION', 'COMISIONES', 'SIN']],
        'comision' => ['any' => ['COMISION', 'COMISIONES'], 'none' => ['IVA']],
        'saldo' => ['any' => ['SALDO', 'INSOLUTO']],
        'capital' => ['any' => ['CAPITAL', 'AMORTIZACION']],
        'interes' => ['any' => ['INTERES', 'INTERESES'], 'none' => ['IVA']],
        'fecha' => ['any' => ['FECHA', 'VENCIMIENTO']],
        'pago' => ['any' => ['PAGO', 'CUOTA', 'MENSUALIDAD', 'IMPORTE'], 'none' => ['SIN']],
        'numero' => ['any' => ['NUMERO', 'NUM', 'NO']],
    ];

    /**
     * Campos obligatorios para reconocer la fila de encabezados. Además se exige al menos uno de
     * cada grupo de REQUIRED_ANY (ver recognized()).
     */
    public const REQUIRED = ['fecha'];

    /** Grupos «al menos uno»: costo financiero (interés o comisión) y capital o pago. */
    public const REQUIRED_ANY = [['interes', 'comision'], ['capital', 'pago']];

    /** Campos que se leen sólo como dato informativo (no se guardan ni se validan). */
    public const INFORMATIVE = ['pago_sin_iva'];

    /** Etiqueta legible de cada campo (mensajes de error). */
    public const LABELS = [
        'numero' => 'No. de pago', 'fecha' => 'Fecha de pago', 'pago' => 'Pago total', 'capital' => 'Capital',
        'interes' => 'Interés', 'iva' => 'IVA de interés', 'comision' => 'Comisión', 'iva_comision' => 'IVA de comisión',
        'pago_sin_iva' => 'Pago sin IVA (informativo)', 'saldo' => 'Saldo insoluto',
    ];

    /** Encabezados de la plantilla descargable. */
    public const TEMPLATE_HEADERS = ['No. de pago', 'Fecha de pago', 'Pago total', 'Capital', 'Interés', 'IVA de interés', 'Comisión', 'IVA de comisión', 'Saldo insoluto'];

    /**
     * ¿El mapa detectado basta para leer la tabla? Fecha + (interés o comisión) + (capital o pago).
     *
     * @param  array<string,int>  $map  campo ⇒ columna
     */
    public static function recognized(array $map): bool
    {
        if (array_diff(self::REQUIRED, array_keys($map)) !== []) {
            return false;
        }
        foreach (self::REQUIRED_ANY as $group) {
            if (array_intersect($group, array_keys($map)) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normaliza un encabezado para compararlo con los sinónimos: mayúsculas sin acentos, sólo
     * letras/dígitos/#/% y espacios simples.
     *
     * @param  mixed  $label  valor de la celda de encabezado
     * @return string '' si no es texto
     */
    public static function normalize(mixed $label): string
    {
        if ($label === null || is_array($label)) {
            return '';
        }
        $s = mb_strtoupper(trim((string) $label));
        $s = strtr($s, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N', 'º' => '', '°' => '']);
        $s = preg_replace('/[^A-Z0-9#%]+/', ' ', $s) ?? $s;

        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /**
     * Mapea una fila candidata a encabezados: campo => índice de columna (base 0).
     *
     * @param  array<int, mixed>  $row
     * @return array<string,int>
     */
    public static function map(array $row): array
    {
        $labels = [];
        foreach ($row as $col => $cell) {
            $n = self::normalize($cell);
            if ($n !== '' && ! is_numeric($n)) {
                $labels[(int) $col] = $n;
            }
        }
        // «PAGO» a secas es el número de pago si otra columna ya es el importe del pago.
        $otherPayment = array_filter($labels, fn ($l) => $l !== 'PAGO' && in_array($l, self::SYNONYMS['pago'], true));

        $map = [];
        $used = [];
        foreach ($labels as $col => $label) {
            if ($label === 'PAGO' && $otherPayment !== []) {
                if (! isset($map['numero'])) {
                    $map['numero'] = $col;
                    $used[$col] = true;
                }

                continue;
            }
            foreach (self::SYNONYMS as $field => $syn) {
                if (! isset($map[$field]) && in_array($label, $syn, true)) {
                    $map[$field] = $col;
                    $used[$col] = true;
                    break;
                }
            }
        }
        foreach (self::FUZZY as $field => $rule) {
            if (isset($map[$field])) {
                continue;
            }
            foreach ($labels as $col => $label) {
                if (isset($used[$col])) {
                    continue;
                }
                $tokens = explode(' ', $label);
                if ((isset($rule['any']) && array_intersect($rule['any'], $tokens) === [])
                    || (isset($rule['all']) && array_diff($rule['all'], $tokens) !== [])
                    || (isset($rule['none']) && array_intersect($rule['none'], $tokens) !== [])) {
                    continue;
                }
                $map[$field] = $col;
                $used[$col] = true;
                break;
            }
        }

        return $map;
    }
}
