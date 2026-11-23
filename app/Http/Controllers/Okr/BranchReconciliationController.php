<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Services\Okr\OkrEmployeeBranchResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Prioridad 5 del cierre OKR — conciliación AUTORIZADA de sucursal cuando dos
 * fuentes del mismo periodo la contradicen ("Encontramos información
 * diferente sobre la sucursal de este colaborador."). Solo administradores
 * (Gate okr.admin). Escribe únicamente en okr_branch_reconciliations: nunca
 * toca asignaciones ni cifras de Reportería.
 */
class BranchReconciliationController extends Controller
{
    /**
     * Registra la sucursal correcta de un colaborador cuando las fuentes del
     * periodo se contradicen.
     *
     * Valida colaborador/sucursal/notas, delega en
     * OkrEmployeeBranchResolver::reconcileBranch() (escribe una fila en
     * okr_branch_reconciliations) y devuelve la pertenencia ya resuelta para
     * que la UI pueda continuar con la asignación de OKR.
     *
     * @param  Request  $request  Campos `employee_id`, `branch_id` y `notes` (opcional).
     * @param  OkrEmployeeBranchResolver  $resolver  Servicio que resuelve/concilia la sucursal del colaborador.
     * @return JsonResponse Éxito con `reconciliation_id` y `membership`, o 422 con el mensaje del RuntimeException.
     *
     * @throws AuthorizationException Si el usuario no tiene `okr.admin`.
     * @throws ValidationException Si los datos no son válidos.
     */
    public function store(Request $request, OkrEmployeeBranchResolver $resolver): JsonResponse
    {
        Gate::authorize('okr.admin');
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
            'message' => 'Sucursal conciliada: '.($membership['branch_name'] ?? 'sucursal elegida').'. Ya puedes asignarle OKR en esa sucursal.',
            'reconciliation_id' => $rec->id,
            'membership' => $membership,
        ]);
    }
}
