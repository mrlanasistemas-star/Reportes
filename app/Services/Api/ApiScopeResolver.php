<?php

namespace App\Services\Api;

use App\Models\ApiConsumer;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fase 1.6 — "no permitir consultas generales si solo se autorizó una
 * sucursal". Único punto donde se decide si una petición puede pedir
 * scope=general o una sucursal/colaborador específico, dado lo que el token
 * del consumidor tiene autorizado (ApiConsumer::allowed_branch_ids).
 */
class ApiScopeResolver
{
    /**
     * @return array{type: string, branch_id: ?int, employee_id: ?int}
     */
    public function resolve(Request $request, ApiConsumer $consumer): array
    {
        $scopeType = $request->query('scope', 'general');

        if (!in_array($scopeType, ['general', 'branch', 'employee'], true)) {
            throw new HttpException(422, "Parámetro 'scope' inválido. Valores permitidos: general, branch, employee.");
        }

        $branchId   = $request->query('branch_id') !== null ? (int) $request->query('branch_id') : null;
        $employeeId = $request->query('employee_id') !== null ? (int) $request->query('employee_id') : null;

        if ($scopeType === 'general' && !$consumer->canAccessGeneralScope()) {
            throw new HttpException(403, 'Este consumidor está restringido a sucursales específicas; scope=general no está autorizado.');
        }

        if ($scopeType === 'branch') {
            if ($branchId === null) {
                throw new HttpException(422, "scope=branch requiere 'branch_id'.");
            }
            if (!$consumer->canAccessBranch($branchId)) {
                throw new HttpException(403, 'Este consumidor no está autorizado para la sucursal solicitada.');
            }
        }

        if ($scopeType === 'employee') {
            if ($employeeId === null) {
                throw new HttpException(422, "scope=employee requiere 'employee_id'.");
            }
            // La restricción por sucursal del consumidor también limita el alcance por
            // colaborador: si está restringido, el colaborador debe pertenecer a una de
            // sus sucursales autorizadas (resuelto por el controlador vía EmployeeBranchAssignment).
        }

        return ['type' => $scopeType, 'branch_id' => $branchId, 'employee_id' => $employeeId];
    }

    /** Para endpoints de un recurso ya identificado por ruta (p.ej. /financial/branches/{id}). */
    public function assertBranchAllowed(ApiConsumer $consumer, int $branchId): void
    {
        if (!$consumer->canAccessBranch($branchId)) {
            throw new HttpException(403, 'Este consumidor no está autorizado para la sucursal solicitada.');
        }
    }
}
