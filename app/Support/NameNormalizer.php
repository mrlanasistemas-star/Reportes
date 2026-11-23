<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normalizador de nombres usado en todo el sistema para comparar personas,
 * sucursales y conceptos provenientes de fuentes distintas (NOI, Lendus, ERP).
 *
 * Convierte a ASCII, mayúsculas, sustituye cualquier carácter que no sea
 * letra/dígito/espacio por espacio y colapsa espacios. El resultado se usa como
 * llave de cruce (p. ej. `normalized_name`), por lo que cambiar este algoritmo
 * alteraría las identidades ya guardadas en BD.
 */
class NameNormalizer
{
    /**
     * Normaliza un texto libre a la forma canónica de comparación.
     *
     * @param  string|null  $value  Texto original (nombre de persona, sucursal o concepto).
     * @return string Texto en mayúsculas ASCII sin signos y con espacios simples; '' si viene vacío.
     */
    public static function normalize(?string $value): string
    {
        if (blank($value)) {
            return '';
        }
        $value = Str::ascii($value);
        $value = mb_strtoupper($value, 'UTF-8');
        $value = preg_replace('/[^A-Z0-9\s]/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    /**
     * Normaliza un nombre de sucursal (mismo algoritmo que normalize()).
     *
     * @param  string|null  $value  Nombre de sucursal tal como viene en la fuente.
     * @return string Nombre normalizado.
     */
    public static function normalizeBranch(?string $value): string
    {
        return self::normalize($value);
    }

    /**
     * Normaliza un concepto de nómina/gasto (mismo algoritmo que normalize()).
     *
     * @param  string|null  $value  Concepto tal como viene en la fuente.
     * @return string Concepto normalizado.
     */
    public static function normalizeConcept(?string $value): string
    {
        return self::normalize($value);
    }
}
