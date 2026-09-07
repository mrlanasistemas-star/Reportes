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
        // página ni volver a pedir el snapshot completo.
        $snapshotBuilder->findEmployeeGestorRowByEmployeeId($period, $employee->id);
        $detail = $snapshotBuilder->buildEmployeeExpenseDetail([$employee->id], $period->id, $employee->id);

        return response()->json([
            'saved'  => $saved,
            'detail' => $detail,
        ]);
    }
}
