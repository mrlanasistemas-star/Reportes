<?php

namespace App\Services\Reporting;

/**
 * Fuente ÚNICA de las 13 sucursales operativas — usada por MonthlyReportController
 * (radiografía/Excel/PDF) y por el módulo OKR. Antes el OKR leía esta lista vía
 * ReflectionClass sobre la constante privada de MonthlyReportController (frágil:
 * cualquier refactor del controlador rompía OKR sin avisar). Ahora ambos leen de
 * AQUÍ — MonthlyReportController::OPERATIVE_BRANCH_NAMES queda definida como
 * `= OperativeBranchService::NAMES` (misma constante, sin duplicar el arreglo).
 */
class OperativeBranchService
{
    public const NAMES = [
        'ATLACOMULCO', 'ATLIXCO', 'CORDOBA', 'CUERNAVACA', 'HUAMANTLA',
        'IXTLAHUACA', 'MIACATLAN', 'ORIZABA', 'SAN JUAN DEL RÍO',
        'SAN LUIS POTOSI', 'TENANGO DEL VALLE', 'TLAXCALA', 'TULA',
    ];

    /**
     * Devuelve la lista oficial de las 13 sucursales operativas (nombres en
     * mayúsculas, tal como se guardan en el catálogo de sucursales).
     *
     * Sin efectos secundarios: solo regresa la constante NAMES.
     *
     * @return array<int, string> Nombres de las sucursales operativas.
     */
    public function names(): array
    {
        return self::NAMES;
    }

    /**
     * Indica si una sucursal forma parte de las 13 operativas oficiales.
     *
     * La comparación es estricta y sensible a mayúsculas/acentos: el nombre
     * debe coincidir exactamente con un elemento de NAMES (p. ej.
     * "SAN JUAN DEL RÍO" con acento). No normaliza el texto recibido.
     *
     * @param  string|null  $branchName  Nombre de la sucursal a evaluar; null se considera no operativa.
     * @return bool true si la sucursal es operativa.
     */
    public function isOperative(?string $branchName): bool
    {
        return $branchName !== null && in_array($branchName, self::NAMES, true);
    }
}
