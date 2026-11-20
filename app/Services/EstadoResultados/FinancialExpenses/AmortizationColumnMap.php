<?php

namespace App\Services\EstadoResultados\FinancialExpenses;

/**
 * ÚNICO lugar con el mapeo de encabezados de la tabla de amortización.
 *
 * IMPORTANTE (20-nov-2026): todavía NO tenemos el formato real que entregarán LEROM y BBVA.
 * Estos sinónimos cubren los nombres habituales de una tabla de amortización en español;
 * cuando llegue el archivo real se AJUSTAN AQUÍ (agregar/quitar sinónimos o cambiar la
 * prioridad) sin tocar el importador. La plantilla descargable usa TEMPLATE_HEADERS.
 *
 * Normalización (normalize()): mayúsculas, sin acentos, todo lo que no sea letra, número,
 * '#' o '%' se vuelve espacio. Así «Núm.», «No.», «Pago #» y «Interés ordinario» se
 * comparan como «NUM», «NO», «PAGO #» e «INTERES ORDINARIO».
 *
 * Detección: 1) coincidencia EXACTA con un sinónimo; 2) si un campo sigue sin columna,
 * coincidencia por PALABRAS contenidas en el encabezado (p. ej. «Intereses del periodo»),
 * en el orden de FUZZY_ORDER (IVA antes que interés para que «IVA de intereses» no se
 * tome como interés; capital antes que pago para que «Pago a capital» sea capital).
 */
final class AmortizationColumnMap
{
    /** Campo => sinónimos (ya normalizados). */
    public const SYNONYMS = [
        'numero' => ['NO', 'NUM', 'NUMERO', 'N', '#', 'NO #', 'PAGO #', 'NO DE PAGO', 'NUM DE PAGO', 'NUMERO DE PAGO', 'PERIODO', 'PARCIALIDAD', 'AMORTIZACION #', 'CUOTA #', 'NO PAGO'],
        'fecha' => ['FECHA', 'FECHA DE PAGO', 'FECHA PAGO', 'VENCIMIENTO', 'FECHA DE VENCIMIENTO', 'FECHA VENCIMIENTO', 'FECHA LIMITE DE PAGO', 'FECHA EXIGIBLE'],
        'pago' => ['PAGO', 'CUOTA', 'PAGO TOTAL', 'MENSUALIDAD', 'IMPORTE', 'IMPORTE PAGO', 'IMPORTE DEL PAGO', 'PAGO SEMANAL', 'PAGO MENSUAL', 'TOTAL A PAGAR', 'TOTAL PAGO', 'MONTO', 'MONTO DEL PAGO', 'PAGO FIJO'],
        'capital' => ['CAPITAL', 'AMORTIZACION', 'ABONO A CAPITAL', 'ABONO CAPITAL', 'PAGO A CAPITAL', 'PAGO CAPITAL', 'AMORTIZACION DE CAPITAL', 'AMORTIZACION A CAPITAL'],
        'interes' => ['INTERES', 'INTERESES', 'INTERES ORDINARIO', 'INTERESES ORDINARIOS', 'INTERES DEL PERIODO', 'INTERESES DEL PERIODO', 'PAGO DE INTERES', 'PAGO DE INTERESES'],
        'iva' => ['IVA', 'IVA INTERES', 'IVA INTERESES', 'IVA DE INTERES', 'IVA DE INTERESES', 'IVA S INTERES', 'IVA SOBRE INTERES', 'IVA SOBRE INTERESES', 'IVA DEL INTERES'],
        'saldo' => ['SALDO', 'SALDO INSOLUTO', 'SALDO FINAL', 'SALDO PENDIENTE', 'SALDO CAPITAL', 'SALDO DE CAPITAL', 'SALDO INSOLUTO FINAL', 'CAPITAL INSOLUTO'],
    ];

    /** Palabras para la segunda pasada (encabezado que CONTIENE la palabra), en este orden. */
    public const FUZZY = [
        'iva' => ['IVA'],
        'saldo' => ['SALDO', 'INSOLUTO'],
        'capital' => ['CAPITAL', 'AMORTIZACION'],
        'interes' => ['INTERES', 'INTERESES'],
        'fecha' => ['FECHA', 'VENCIMIENTO'],
        'pago' => ['PAGO', 'CUOTA', 'MENSUALIDAD', 'IMPORTE'],
        'numero' => ['NUMERO', 'NUM', 'NO'],
    ];

    /** Campos obligatorios para reconocer la fila de encabezados. */
    public const REQUIRED = ['fecha', 'interes'];

    /** Etiqueta legible de cada campo (mensajes de error). */
    public const LABELS = [
        'numero' => 'No. de pago', 'fecha' => 'Fecha de pago', 'pago' => 'Pago total', 'capital' => 'Capital',
        'interes' => 'Interés', 'iva' => 'IVA de interés', 'saldo' => 'Saldo insoluto',
    ];

    /** Encabezados de la plantilla descargable. */
    public const TEMPLATE_HEADERS = ['No. de pago', 'Fecha de pago', 'Pago total', 'Capital', 'Interés', 'IVA de interés', 'Saldo insoluto'];

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
        $map = [];
        $used = [];
        foreach ($labels as $col => $label) {
            foreach (self::SYNONYMS as $field => $syn) {
                if (! isset($map[$field]) && in_array($label, $syn, true)) {
                    $map[$field] = $col;
                    $used[$col] = true;
                    break;
                }
            }
        }
        foreach (self::FUZZY as $field => $words) {
            if (isset($map[$field])) {
                continue;
            }
            foreach ($labels as $col => $label) {
                if (isset($used[$col])) {
                    continue;
                }
                $tokens = explode(' ', $label);
                if (array_intersect($words, $tokens) !== []) {
                    $map[$field] = $col;
                    $used[$col] = true;
                    break;
                }
            }
        }

        return $map;
    }
}
