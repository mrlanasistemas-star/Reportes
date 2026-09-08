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

    /** @return array<int, string> */
    public function names(): array
    {
        return self::NAMES;
    }

    public function isOperative(?string $branchName): bool
    {
        return $branchName !== null && in_array($branchName, self::NAMES, true);
    }
}
