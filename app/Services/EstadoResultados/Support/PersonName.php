<?php

namespace App\Services\EstadoResultados\Support;

use Illuminate\Support\Str;

/**
 * Normalización de nombres de persona para vincular un pago bancario con un
 * colaborador NOI. La llave es el CONJUNTO ordenado de palabras (sin acentos,
 * mayúsculas, sin signos), de modo que "PEREZ LOPEZ JUAN" = "Juan Pérez López".
 * Se exige igualdad exacta de la llave: nunca coincidencias aproximadas.
 */
final class PersonName
{
    public static function key(?string $name): ?string
    {
        $tokens = self::tokens($name);
        if (count($tokens) < 2) {
            return null;
        }
        sort($tokens, SORT_STRING);

        return implode(' ', $tokens);
    }

    /** @return list<string> */
    public static function tokens(?string $text): array
    {
        $v = mb_strtoupper(Str::ascii(trim((string) $text)));
        $v = preg_replace('/[^A-Z0-9 ]+/', ' ', $v) ?? '';
        $out = array_values(array_filter(explode(' ', $v), fn ($t) => mb_strlen($t) >= 2));

        return $out;
    }
}
