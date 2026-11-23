<?php

namespace App\Support;

/**
 * Utilidad para los importadores de Excel/CSV: detecta filas vacías que deben
 * ignorarse (renglones en blanco intermedios o al final de la hoja).
 */
class RowCleaner
{
    /**
     * Indica si todas las celdas de la fila son null o texto vacío tras trim.
     *
     * @param  array  $row  Celdas de una fila leída del archivo.
     * @return bool true si la fila no tiene ningún valor útil.
     */
    public static function isEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (! is_null($value) && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
