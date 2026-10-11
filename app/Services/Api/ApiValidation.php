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
 */
class ApiValidation
{
    /** ID opcional: ausente → null. Presente pero no numérico/≤0 → 422. */
    public static function nullableId(Request $request, string $key): ?int
    {
        $raw = $request->query($key);
        if ($raw === null || $raw === '') {
            return null;
        }

        if (!is_numeric($raw) || (int) $raw <= 0 || (string) (int) $raw !== (string) $raw) {
            throw new HttpException(422, "El parámetro '{$key}' debe ser un entero positivo.");
        }

        return (int) $raw;
    }

    /** ID obligatorio: ausente o inválido → 422. */
    public static function requiredId(Request $request, string $key): int
    {
        $value = self::nullableId($request, $key);
        if ($value === null) {
            throw new HttpException(422, "El parámetro '{$key}' es obligatorio y debe ser un entero positivo.");
        }

        return $value;
    }

    /** per_page: 1-100, default $default. Valor inválido (no numérico, fuera de rango) → 422. */
    public static function perPage(Request $request, int $default = 50, int $max = 100): int
    {
        $raw = $request->query('per_page');
        if ($raw === null || $raw === '') {
            return $default;
        }

        if (!is_numeric($raw) || (int) $raw !== (int) (string) $raw) {
            throw new HttpException(422, "El parámetro 'per_page' debe ser un entero.");
        }

        $value = (int) $raw;
        if ($value < 1 || $value > $max) {
            throw new HttpException(422, "El parámetro 'per_page' debe estar entre 1 y {$max}.");
        }

        return $value;
    }

    public static function enumValue(Request $request, string $key, array $allowed, ?string $default = null): ?string
    {
        $raw = $request->query($key, $default);
        if ($raw === null) {
            return $default;
        }

        if (!in_array($raw, $allowed, true)) {
            throw new HttpException(422, "El parámetro '{$key}' inválido. Valores permitidos: " . implode(', ', $allowed) . '.');
        }

        return $raw;
    }
}
