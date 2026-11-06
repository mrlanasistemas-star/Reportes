<?php

namespace App\Services\EstadoResultados\Support;

use App\Services\BranchResolverService;
use Illuminate\Support\Str;

/**
 * Normaliza la columna SUCURSAL de los estados de cuenta complementarios a:
 *   kind = operativa   → una de las 13 sucursales de Radiografía (name = nombre oficial)
 *   kind = corporativo → CORPORATIVO
 *   kind = agrupado    → "SUCURSALES" o varias sucursales en una celda (ORIZABA-ATLACOMULCO-…)
 *   kind = desconocida → texto no reconocido (se conserva, requiere revisión)
 *   kind = vacia       → sin sucursal
 *
 * Reutiliza BranchResolverService (catálogo oficial de rutas) SIN modificarlo.
 */
class SucursalNormalizer
{
    /** Abreviaturas que aparecen en los estados de cuenta y no están en el catálogo de rutas. */
    private const ALIASES = [
        'SLP' => 'SAN LUIS POTOSI',
        'S.L.P.' => 'SAN LUIS POTOSI',
        'SAN LUIS' => 'SAN LUIS POTOSI',
        'TENANGO' => 'TENANGO DEL VALLE',
        'SJR' => 'SAN JUAN DEL RÍO',
        'SAN JUAN DEL RIO' => 'SAN JUAN DEL RÍO',
        'CORP' => 'CORPORATIVO',
        'CORPORATIVO' => 'CORPORATIVO',
        'OFICINA' => 'CORPORATIVO',
    ];

    private array $cache = [];

    public function __construct(private readonly BranchResolverService $resolver) {}

    /** @return array{name: ?string, kind: string} */
    public function normalize(?string $raw): array
    {
        $key = mb_strtoupper(trim((string) $raw));
        if ($key === '') {
            return ['name' => null, 'kind' => 'vacia'];
        }

        return $this->cache[$key] ??= $this->resolve($key);
    }

    private function resolve(string $key): array
    {
        $ascii = mb_strtoupper(Str::ascii($key));
        $ascii = preg_replace('/\s+/', ' ', $ascii) ?? $ascii;

        if (in_array($ascii, ['SUCURSALES', 'TODAS', 'TODAS LAS SUCURSALES', 'VARIAS'], true)) {
            return ['name' => 'SUCURSALES', 'kind' => 'agrupado'];
        }
        if (substr_count($ascii, '-') >= 1 && count(array_filter(explode('-', $ascii), fn ($p) => $this->single(trim($p)) !== null)) >= 2) {
            return ['name' => 'SUCURSALES', 'kind' => 'agrupado'];
        }

        $single = $this->single($ascii);
        if ($single === null) {
            return ['name' => null, 'kind' => 'desconocida'];
        }
        if ($single === 'CORPORATIVO') {
            return ['name' => 'CORPORATIVO', 'kind' => 'corporativo'];
        }
        if ($this->resolver->isSheetBranch($single)) {
            return ['name' => $single, 'kind' => 'operativa'];
        }

        // Sucursal real pero NO operativa en Radiografía (p.ej. TULANCINGO, AGUASCALIENTES).
        return ['name' => $single, 'kind' => 'desconocida'];
    }

    private function single(string $ascii): ?string
    {
        if ($ascii === '') {
            return null;
        }
        if (isset(self::ALIASES[$ascii])) {
            return self::ALIASES[$ascii];
        }
        $real = $this->resolver->resolveRealBranchFromRoute($ascii);
        if ($real) {
            return mb_strtoupper(trim($real)) === 'CORPORATIVO' ? 'CORPORATIVO' : $real;
        }
        if (strlen($ascii) <= 3) {
            return $this->resolver->resolveBranchNameFromCode($ascii);
        }
        foreach ($this->resolver->operativeFinancialBranches() as $branch) {
            if (mb_strtoupper(Str::ascii($branch)) === $ascii) {
                return $branch;
            }
        }

        return null;
    }
}
