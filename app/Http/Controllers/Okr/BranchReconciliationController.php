<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Services\Okr\OkrEmployeeBranchResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Prioridad 5 del cierre OKR — conciliación AUTORIZADA de sucursal cuando dos
 * fuentes del mismo periodo la contradicen ("Encontramos información
 * diferente sobre la sucursal de este colaborador."). Solo administradores
 * (Gate okr.admin). Escribe únicamente en okr_branch_reconciliations: nunca
 * toca asignaciones ni cifras de Reportería.
 */
class BranchReconciliationController extends Controller
{
    public function store(Request $request, OkrEmployeeBranchResolver $resolver): JsonResponse
    {
        \Illuminate\Support\Facades\Gate::authorize('okr.admin');
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'employee_id.required' => 'Indica el colaborador a conciliar.',
            'branch_id.required' => 'Elige la sucursal correcta.',
        ]);

        try {
            $rec = $resolver->reconcileBranch((int) $data['employee_id'], (int) $data['branch_id'], $request->user(), $data['notes'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $membership = $resolver->membershipFor((int) $data['employee_id']);

        return response()->json([
            'success' => true,
            'message' => 'Sucursal conciliada: ' . ($membership['branch_name'] ?? 'sucursal elegida') . '. Ya puedes asignarle OKR en esa sucursal.',
            'reconciliation_id' => $rec->id,
            'membership' => $membership,
        ]);
    }
}
