<?php

namespace App\Http\Middleware;

use App\Services\Access\DataScopeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fase 6.4 — candado de alcance por sucursal en la ENTRADA de cada petición web.
 * Si la petición trae una sucursal (`branch_id` / `manual_branch_id`, en query o
 * cuerpo) y el usuario tiene alcance restringido, la sucursal debe estar entre
 * las suyas; si no, 403 con mensaje claro. Así un cambio manual de parámetros o
 * un enlace directo nunca expone otra sucursal (Dashboard, Reportes, exportes,
 * Colaboradores, OKR...). Usuarios con alcance general no se ven afectados.
 *
 * Lo que no depende de un parámetro (consolidado general, colaborador/gestor,
 * objetos por ID) lo resuelve cada controlador con DataScopeService.
 */
class EnsureBranchScope
{
    private const BRANCH_KEYS = ['branch_id', 'manual_branch_id'];

    public function __construct(private readonly DataScopeService $scope) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $this->scope->isRestricted($user)) {
            foreach (self::BRANCH_KEYS as $key) {
                $value = $request->input($key);
                if (is_array($value) || $value === null || $value === '' || ! is_numeric($value) || (int) $value <= 0) {
                    continue;
                }
                $this->scope->authorizeBranch($user, (int) $value);
            }
        }

        return $next($request);
    }
}
