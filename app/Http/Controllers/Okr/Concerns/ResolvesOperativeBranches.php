<?php

namespace App\Http\Controllers\Okr\Concerns;

use App\Services\Reporting\OperativeBranchService;

/**
 * Módulo OKR (08-sep-2026) — reutiliza la MISMA lista de 13 sucursales
 * operativas que ya usa Reportería. Antes se leía por ReflectionClass sobre
 * la constante privada de MonthlyReportController (frágil — corregido en la
 * auditoría del 08-sep-2026): ahora ambos leen de
 * App\Services\Reporting\OperativeBranchService::NAMES, la fuente única.
 */
trait ResolvesOperativeBranches
{
    /** @return array<int, string> */
    protected function operativeBranchNames(): array
    {
        return OperativeBranchService::NAMES;
    }
}
