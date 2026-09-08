<?php

namespace App\Http\Controllers\Okr\Concerns;

use App\Http\Controllers\MonthlyReportController;

/**
 * Módulo OKR (08-sep-2026) — reutiliza la MISMA lista de 13 sucursales
 * operativas que ya usa Reportería (MonthlyReportController::
 * OPERATIVE_BRANCH_NAMES, `private const`) — nunca una lista duplicada.
 * Reflection porque la constante es privada en su clase original (no se
 * cambia su visibilidad para no tocar código ya en producción, "conserva
 * todo lo actual").
 */
trait ResolvesOperativeBranches
{
    /** @return array<int, string> */
    protected function operativeBranchNames(): array
    {
        static $names = null;
        $names ??= (new \ReflectionClass(MonthlyReportController::class))->getConstant('OPERATIVE_BRANCH_NAMES');

        return $names;
    }
}
