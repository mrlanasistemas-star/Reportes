<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Period;
use App\Services\EmployeePeriodManualExpenseService;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Gasto general por gestor" persistente por (period_id, employee_id) —
 * auditoría 07-sep-2026 (frente 4). Fuente única leída/escrita por
 * EmployeePeriodManualExpenseService; ver su docblock y
 * RadiographySnapshotBuilder::buildEmployeeExpenseDetail().
 */
class EmployeePeriodManualExpenseController extends Controller
{
    public function show(Period $period, Employee $employee, EmployeePeriodManualExpenseService $service): JsonResponse
    {
        return response()->json($service->getForPeriodEmployee($period->id, $employee->id));
    }

    public function store(Period $period, Employee $employee, Request $request, EmployeePeriodManualExpenseService $service, RadiographySnapshotBuilder $snapshotBuilder): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'notes'  => ['nullable', 'string', 'max:2000'],
        ]);

        $saved = $service->upsert(
            $period->id,
            $employee->id,
            (float) $validated['amount'],
            (string) ($validated['notes'] ?? ''),
            auth()->id(),
        );

        // Detalle recalculado (automático + manual recién guardado) para que el
        // frontend refresque la tarjeta OPEX/EBITDA sin necesidad de recargar la
        // página ni volver a pedir el snapshot completo. Auditoría 07-sep-2026
        // (cierre, sección 13): usar $row['_employee_ids'] — cuando la identidad
        // del colaborador agrupa varios employee_id históricos fusionados (NOI
        // normal + fiscal, o duplicados canonizados), el gasto automático de
        // TODOS esos IDs debe reflejarse aquí, exactamente como en
        // applyEmployeeScope() (Web) — antes se descartaba $row y se usaba solo
        // [$employee->id], perdiendo el gasto de los IDs históricos fusionados.
        $row = $snapshotBuilder->findEmployeeGestorRowByEmployeeId($period, $employee->id);
        $employeeIds = !empty($row['_employee_ids'] ?? null) ? $row['_employee_ids'] : [$employee->id];
        $detail = $snapshotBuilder->buildEmployeeExpenseDetail($employeeIds, $period->id, $employee->id);

        return response()->json([
            'saved'  => $saved,
            'detail' => $detail,
        ]);
    }
}
