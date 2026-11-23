<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Services\Access\DataScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Acciones manuales sobre colaboradores (empleados) desde la Reportería:
 * asignación manual de sucursal por periodo (individual o en lote, desde el
 * bloque "personas sin sucursal" del reporte mensual) y edición del puesto
 * que usa la Carta Compromiso de OKR.
 *
 * Toda acción respeta el alcance por sucursal del usuario (DataScopeService).
 */
class EmployeeController extends Controller
{
    /**
     * Asigna una sucursal a varios empleados a la vez para un periodo.
     * Se usa cuando "personasSinSucursal" agrupa filas NOI regular + NOI fiscal
     * de una misma persona (varios employee_id que son el mismo colaborador).
     *
     * Hace updateOrCreate en employee_branch_assignments (clave periodo+empleado)
     * con source_type/match_type = manual, confianza 1.00 y
     * was_manual_reviewed = true, para que los cruces automáticos posteriores no
     * la sobrescriban.
     *
     * @param  Request  $request  employee_ids[] (empleados a asignar), branch_id
     *                            (sucursal destino), period_id (periodo) y notes opcional.
     * @return JsonResponse `{ok: true, assigned: N}`.
     *
     * Lanza ValidationException si los datos no son válidos y 403 si algún
     * empleado ya pertenece a una sucursal fuera del alcance del usuario.
     */
    public function batchAssignBranch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['required', 'integer', 'exists:employees,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'period_id' => ['required', 'integer', 'exists:periods,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $branch = Branch::findOrFail($validated['branch_id']);
        $period = Period::findOrFail($validated['period_id']);
        $note = $validated['notes'] ?? "Asignado manualmente a {$branch->name}.";

        // Fase 6.4 — no reasignar personas que hoy pertenecen a una sucursal fuera del alcance.
        $this->authorizeCurrentAssignments($request, $validated['employee_ids'], $period->id);

        foreach ($validated['employee_ids'] as $employeeId) {
            EmployeeBranchAssignment::query()->updateOrCreate(
                ['period_id' => $period->id, 'employee_id' => $employeeId],
                [
                    'branch_id' => $branch->id,
                    'source_type' => 'manual',
                    'source_reference' => "Asignación manual — {$period->label}",
                    'match_type' => 'manual',
                    'confidence' => 1.00,
                    'was_manual_reviewed' => true,
                    'notes' => $note,
                ]
            );
        }

        return response()->json(['ok' => true, 'assigned' => count($validated['employee_ids'])]);
    }

    /**
     * Asignación manual de sucursal a un empleado para un periodo específico.
     * Escribe (updateOrCreate) en employee_branch_assignments y marca
     * was_manual_reviewed = true para que futuros cruces automáticos no la
     * sobrescriban.
     *
     * @param  Employee  $employee  Colaborador a asignar (route model binding).
     * @param  Request  $request  branch_id (sucursal destino), period_id y notes opcional.
     * @return RedirectResponse Regresa a la página anterior con mensaje de éxito.
     *
     * Lanza ValidationException o 403 (si su sucursal actual está fuera del alcance).
     */
    public function assignBranch(Employee $employee, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'period_id' => ['required', 'integer', 'exists:periods,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $branch = Branch::findOrFail($validated['branch_id']);
        $period = Period::findOrFail($validated['period_id']);

        $this->authorizeCurrentAssignments($request, [$employee->id], $period->id);

        EmployeeBranchAssignment::query()->updateOrCreate(
            [
                'period_id' => $period->id,
                'employee_id' => $employee->id,
            ],
            [
                'branch_id' => $branch->id,
                'source_type' => 'manual',
                'source_reference' => "Asignación manual — {$period->label}",
                'match_type' => 'manual',
                'confidence' => 1.00,
                'was_manual_reviewed' => true,
                'notes' => $validated['notes'] ?? "Asignado manualmente a {$branch->name}.",
            ]
        );

        return back()->with('success', "Sucursal asignada: {$branch->name} → {$employee->full_name}.");
    }

    /**
     * Cierre real OKR (04-oct-2026, punto 7) — puesto/cargo del colaborador:
     * ninguna fuente del proyecto (NOI, roster, directorio, asignaciones) lo
     * trae, así que se gestiona aquí como dato persistente mínimo. Una vez
     * guardado, la Carta Compromiso lo precarga automáticamente — nunca se
     * vuelve a escribir a mano por carta.
     *
     * @param  Employee  $employee  Colaborador cuyo puesto se actualiza.
     * @param  Request  $request  `position` (texto hasta 120 caracteres; vacío lo borra a null).
     * @return RedirectResponse Regresa con mensaje de éxito.
     *
     * Escribe employees.position. Lanza 403 si el empleado está fuera del alcance del usuario.
     */
    public function updatePosition(Employee $employee, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'position' => ['nullable', 'string', 'max:120'],
        ]);

        app(DataScopeService::class)->authorizeEmployee($request->user(), $employee->id);

        $employee->update(['position' => $validated['position'] ?: null]);

        return back()->with('success', "Puesto actualizado: {$employee->full_name}.");
    }

    /**
     * Fase 6.4 — un usuario con alcance restringido solo reasigna personas sin
     * sucursal en el periodo o que ya pertenecen a una de SUS sucursales.
     *
     * Usuarios con alcance general pasan sin revisión.
     *
     * @param  Request  $request  Petición actual (de aquí sale el usuario).
     * @param  array<int, int|string>  $employeeIds  Empleados que se van a reasignar.
     * @param  int  $periodId  Periodo cuyas asignaciones vigentes se revisan.
     *
     * Lanza 403 (DataScopeService::authorizeBranch) si alguna asignación actual
     * apunta a una sucursal fuera del alcance. Nota: no valida la sucursal destino.
     */
    private function authorizeCurrentAssignments(Request $request, array $employeeIds, int $periodId): void
    {
        $dataScope = app(DataScopeService::class);
        if (! $dataScope->isRestricted($request->user())) {
            return;
        }

        $current = EmployeeBranchAssignment::query()->where('period_id', $periodId)
            ->whereIn('employee_id', array_map('intval', $employeeIds))
            ->whereNotNull('branch_id')->pluck('branch_id')->unique();

        foreach ($current as $branchId) {
            $dataScope->authorizeBranch($request->user(), (int) $branchId);
        }

        // La sucursal DESTINO también debe estar en su alcance (23-nov-2026): sin esto un
        // usuario restringido podía mover colaboradores propios a una sucursal ajena.
        $dataScope->authorizeBranch($request->user(), $request->integer('branch_id') ?: null);
    }
}
