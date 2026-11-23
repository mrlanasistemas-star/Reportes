<?php

namespace App\Services\Api;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fase 2.7 — validación estricta de parámetros de query. Antes de esto,
 * `(int) $request->query('employee_id')` convertía silenciosamente "abc" en
 * 0 — un ID válido en la base de datos nunca es 0, pero tampoco se rechazaba
 * la entrada inválida, así que un consumidor podía mandar basura y el sistema
 * simplemente la trataba como "sin filtro" o como el ID 0, en vez de un 422
 * explícito.
 *
 * Todos los métodos son estáticos y los usan los controladores de la API v1;
 * ante un valor inválido lanzan HttpException 422 con mensaje en español.
 */
class ApiValidation
{
    /**
     * ID opcional: ausente → null. Presente pero no numérico/≤0 → 422.
     *
     * Exige que el texto sea exactamente un entero positivo (rechaza "1.0",
     * "01", "abc", "-3").
     *
     * @param  Request  $request  Petición HTTP.
     * @param  string  $key  Nombre del parámetro de query (p. ej. "employee_id").
     * @return int|null El ID como entero, o null si no se envió.
     *
     * @throws HttpException 422 si el valor no es un entero positivo.
     */
    public static function nullableId(Request $request, string $key): ?int
    {
        $raw = $request->query($key);
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_numeric($raw) || (int) $raw <= 0 || (string) (int) $raw !== (string) $raw) {
            throw new HttpException(422, "El parámetro '{$key}' debe ser un entero positivo.");
        }

        return (int) $raw;
    }

    /**
     * ID obligatorio: ausente o inválido → 422.
     *
     * @param  Request  $request  Petición HTTP.
     * @param  string  $key  Nombre del parámetro de query obligatorio.
     * @return int El ID validado.
     *
     * @throws HttpException 422 si falta o no es un entero positivo.
     */
    public static function requiredId(Request $request, string $key): int
    {
        $value = self::nullableId($request, $key);
        if ($value === null) {
            throw new HttpException(422, "El parámetro '{$key}' es obligatorio y debe ser un entero positivo.");
        }

        return $value;
    }

    /**
     * per_page: 1-100, default $default. Valor inválido (no numérico, fuera de rango) → 422.
     *
     * @param  Request  $request  Petición HTTP (lee el parámetro `per_page`).
     * @param  int  $default  Tamaño de página cuando no se envía el parámetro.
     * @param  int  $max  Máximo permitido de registros por página.
     * @return int Tamaño de página validado.
     *
     * @throws HttpException 422 si no es numérico o está fuera de 1..$max.
     */
    public static function perPage(Request $request, int $default = 50, int $max = 100): int
    {
        $raw = $request->query('per_page');
        if ($raw === null || $raw === '') {
            return $default;
        }

        if (! is_numeric($raw) || (int) $raw !== (int) (string) $raw) {
            throw new HttpException(422, "El parámetro 'per_page' debe ser un entero.");
        }

        $value = (int) $raw;
        if ($value < 1 || $value > $max) {
            throw new HttpException(422, "El parámetro 'per_page' debe estar entre 1 y {$max}.");
        }

        return $value;
    }

    /**
     * Valida que un parámetro de query pertenezca a un conjunto cerrado de
     * valores (comparación estricta, sensible a mayúsculas).
     *
     * @param  Request  $request  Petición HTTP.
     * @param  string  $key  Nombre del parámetro de query.
     * @param  array  $allowed  Valores permitidos.
     * @param  string|null  $default  Valor a usar si el parámetro no viene.
     * @return string|null El valor recibido (o el default).
     *
     * @throws HttpException 422 si el valor no está en $allowed.
     */
    public static function enumValue(Request $request, string $key, array $allowed, ?string $default = null): ?string
    {
        $raw = $request->query($key, $default);
        if ($raw === null) {
            return $default;
        }

        if (! in_array($raw, $allowed, true)) {
            throw new HttpException(422, "El parámetro '{$key}' inválido. Valores permitidos: ".implode(', ', $allowed).'.');
        }

        return $raw;
    }
}
