<?php

namespace App\Http\Middleware;

use App\Services\Access\DataScopeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    /** Nombres de parámetro (query o cuerpo) que identifican una sucursal a validar. */
    private const BRANCH_KEYS = ['branch_id', 'manual_branch_id'];

    /**
     * @param  DataScopeService  $scope  Servicio de alcance por sucursal del usuario.
     */
    public function __construct(private readonly DataScopeService $scope) {}

    /**
     * Si el usuario tiene alcance restringido, revisa cada parámetro de
     * BRANCH_KEYS con valor numérico positivo y exige que esa sucursal esté
     * dentro de su alcance (DataScopeService::authorizeBranch aborta con 403
     * en caso contrario). Valores vacíos, arreglos o no numéricos se ignoran.
     * Usuarios con alcance general o invitados pasan sin revisión.
     *
     * @param  Request  $request  Petición entrante.
     * @param  Closure  $next  Siguiente capa del pipeline.
     * @return Response Respuesta de la siguiente capa.
     *
     * @throws HttpException 403 si pide una sucursal fuera de su alcance.
     */
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
