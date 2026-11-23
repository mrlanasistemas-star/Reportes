<?php

namespace App\Support;

/**
 * Convierte montos que llegan como TEXTO en archivos importados (Lendus, NOI) a número.
 *
 * Reglas (23-nov-2026):
 *  - Valores numéricos se devuelven tal cual.
 *  - Se quitan «$» y espacios.
 *  - Con coma Y punto («1,234.56»): la coma es separador de miles.
 *  - Solo con coma:
 *      · si la coma va seguida de grupos de exactamente 3 dígitos («12,500», «1,250,000»)
 *        es separador de miles → 12500 / 1250000. Antes se leía como decimal (12.5): bug.
 *      · en cualquier otro caso («1,5», «12,50») la coma es decimal → 1.5 / 12.5 (igual que antes).
 *  - Texto que no es número → null.
 */
final class MoneyText
{
    /**
     * @param  mixed  $value  Celda leída del archivo (número, texto o vacío).
     * @return float|null Monto o null si la celda está vacía o no es un número.
     */
    public static function toDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $v = str_replace(['$', ' '], '', (string) $value);

        if (str_contains($v, ',') && str_contains($v, '.')) {
            $v = str_replace(',', '', $v);
        } elseif (str_contains($v, ',')) {
            $v = preg_match('/^-?\d{1,3}(,\d{3})+$/', $v) === 1
                ? str_replace(',', '', $v)
                : str_replace(',', '.', $v);
        }

        return is_numeric($v) ? (float) $v : null;
    }
}
