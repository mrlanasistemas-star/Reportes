<?php

namespace App\Services\Api;

use App\Models\ApiConsumer;
use App\Models\Period;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fase 1.6 / 2.2 — "no permitir consultas generales si solo se autorizó una
 * sucursal" + "no debe ser posible cambiar employee_id manualmente para
 * acceder a otra sucursal" (IDOR). Único punto donde se decide si una
 * petición puede pedir scope=general, una sucursal o un colaborador
 * específico, dado lo que el token del consumidor tiene autorizado.
 *
 * Lo usan los controladores de la API v1 (financieros, KPIs, colaboradores)
 * antes de consultar datos; nunca accede a BD salvo a través de
 * EmployeeBranchHistoryResolver para validar colaboradores.
 */
class ApiScopeResolver
{
    /**
     * @param  EmployeeBranchHistoryResolver  $employeeBranchHistoryResolver  Resuelve la sucursal histórica del colaborador por periodo (control IDOR).
     */
    public function __construct(
        private readonly EmployeeBranchHistoryResolver $employeeBranchHistoryResolver,
    ) {}

    /**
     * Valida y resuelve el alcance (`scope`) solicitado en la query.
     *
     * Reglas:
     *  - `scope` ∈ general|branch|employee (default general).
     *  - general: solo si el consumidor tiene acceso global.
     *  - branch: requiere `branch_id` y que el consumidor tenga esa sucursal.
     *  - employee: requiere `employee_id`; si se indica periodo, valida que la
     *    sucursal HISTÓRICA del colaborador en ese periodo esté autorizada.
     *
     * Lee de BD (employee_branch_assignments) solo en el caso employee con periodo.
     *
     * @param  Request  $request  Petición HTTP con `scope`, `branch_id`, `employee_id`.
     * @param  ApiConsumer  $consumer  Consumidor autenticado por token.
     * @param  Period|null  $period  Periodo consultado; necesario para el control IDOR de colaborador.
     * @return array{type: string, branch_id: ?int, employee_id: ?int} Alcance validado.
     *
     * @throws HttpException 422 por parámetros inválidos/faltantes; 403 si el consumidor no está autorizado.
     */
    public function resolve(Request $request, ApiConsumer $consumer, ?Period $period = null): array
    {
        $scopeType = $request->query('scope', 'general');

        if (! in_array($scopeType, ['general', 'branch', 'employee'], true)) {
            throw new HttpException(422, "Parámetro 'scope' inválido. Valores permitidos: general, branch, employee.");
        }

        $branchId = ApiValidation::nullableId($request, 'branch_id');
        $employeeId = ApiValidation::nullableId($request, 'employee_id');

        if ($scopeType === 'general' && ! $consumer->canAccessGeneralScope()) {
            throw new HttpException(403, 'Este consumidor está restringido a sucursales específicas; scope=general no está autorizado.');
        }

        if ($scopeType === 'branch') {
            if ($branchId === null) {
                throw new HttpException(422, "scope=branch requiere 'branch_id'.");
            }
            if (! $consumer->canAccessBranch($branchId)) {
                throw new HttpException(403, 'Este consumidor no está autorizado para la sucursal solicitada.');
            }
        }

        if ($scopeType === 'employee') {
            if ($employeeId === null) {
                throw new HttpException(422, "scope=employee requiere 'employee_id'.");
            }

            // IDOR (Fase 2.2): la restricción por sucursal del consumidor SIEMPRE se
            // verifica aquí, de forma centralizada, contra la sucursal HISTÓRICA del
            // colaborador en el periodo solicitado (Fase 2.3) — nunca contra su
            // asignación "más reciente", y nunca confiando en que cada controlador la
            // repita por su cuenta.
            if ($period !== null && ! $this->employeeBranchHistoryResolver->isEmployeeAllowedForConsumer($consumer, $employeeId, $period)) {
                throw new HttpException(403, 'Este consumidor no está autorizado para el colaborador solicitado en este periodo.');
            }

            // Defensa en profundidad (23-nov-2026): sin periodo no hay sucursal histórica
            // contra la cual validar; un consumidor restringido nunca pasa a ciegas.
            if ($period === null && ! $consumer->canAccessGeneralScope()) {
                throw new HttpException(403, 'scope=employee requiere un periodo para validar la sucursal del colaborador.');
            }
        }

        return ['type' => $scopeType, 'branch_id' => $branchId, 'employee_id' => $employeeId];
    }

    /**
     * Para endpoints de un recurso ya identificado por ruta (p.ej. /financial/branches/{id}).
     *
     * @param  ApiConsumer  $consumer  Consumidor autenticado.
     * @param  int  $branchId  Sucursal solicitada en la ruta.
     *
     * @throws HttpException 403 si el consumidor no tiene acceso a la sucursal.
     */
    public function assertBranchAllowed(ApiConsumer $consumer, int $branchId): void
    {
        if (! $consumer->canAccessBranch($branchId)) {
            throw new HttpException(403, 'Este consumidor no está autorizado para la sucursal solicitada.');
        }
    }

    /**
     * Fase 2.2 — gate centralizado para endpoints de colaborador identificado por ruta (no por query scope=employee).
     *
     * Consulta la asignación histórica del colaborador en el periodo (BD) y
     * deniega si es desconocida o no autorizada para el consumidor.
     *
     * @param  ApiConsumer  $consumer  Consumidor autenticado.
     * @param  int  $employeeId  Colaborador solicitado en la ruta.
     * @param  Period  $period  Periodo de la consulta.
     *
     * @throws HttpException 403 si el consumidor no puede ver al colaborador en ese periodo.
     */
    public function assertEmployeeAllowedForPeriod(ApiConsumer $consumer, int $employeeId, Period $period): void
    {
        if (! $this->employeeBranchHistoryResolver->isEmployeeAllowedForConsumer($consumer, $employeeId, $period)) {
            throw new HttpException(403, 'Este consumidor no está autorizado para el colaborador solicitado en este periodo.');
        }
    }
}
