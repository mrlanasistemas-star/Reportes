<?php

namespace App\Support;

/**
 * Normaliza el estatus de un gasto y decide si cuenta como gasto efectivo.
 *
 * Los importadores de gastos (ERP/Lendus) solo consideran "facturables" los
 * movimientos cuyo estatus normalizado está en la lista BILLABLE (autorizado,
 * pagado, por comprobar, comprobado...). Los demás (cancelado, rechazado,
 * pendiente) no entran al OPEX.
 */
class ExpenseStatusNormalizer
{
    private const BILLABLE = [
        'AUTORIZADO',
        'PAGADA',
        'PAGADO',
        'PAGO AUTORIZADO',
        'POR COMPROBAR',
        'COMPROBADA',
        'COMPROBACION ACEPTADA',
    ];

    /**
     * Lleva el estatus a mayúsculas, sin acentos, con guiones/guiones bajos como
     * espacio y espacios colapsados.
     *
     * @param  string  $value  Estatus tal como viene en el archivo.
     * @return string Estatus normalizado para comparación exacta.
     */
    public static function normalize(string $value): string
    {
        $value = mb_strtoupper(trim($value));
        $value = strtr($value, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);
        $value = str_replace(['-', '_'], ' ', $value);

        return (string) preg_replace('/\s+/', ' ', $value);
    }

    /**
     * Indica si el estatus corresponde a un gasto que sí debe contabilizarse.
     *
     * @param  string|null  $raw  Estatus original; null o vacío se considera no facturable.
     * @return bool true si el estatus normalizado coincide exactamente con uno de BILLABLE.
     */
    public static function isBillable(?string $raw): bool
    {
        if ($raw === null || $raw === '') {
            return false;
        }

        $norm = self::normalize($raw);

        foreach (self::BILLABLE as $billable) {
            if ($norm === $billable) {
                return true;
            }
        }

        return false;
    }
}
