<?php

namespace App\Services;

use App\Models\Branch;

/**
 * Cierre 17-sep-2026 (ronda 2) — FUENTE ÚNICA para el ajuste temporal de OPEX,
 * consumida por Web (RadiographySnapshotBuilder), Excel/PDF individual y de
 * sucursal (RadiografiaExportService) y Excel de colaboradores
 * (EmployeesHistoricoExportService). Antes de esta ronda existían tres
 * implementaciones separadas (manualAmount/manualGeneralAmount/manualAllAmount)
 * con reglas de multiplicación distintas y difíciles de auditar — este
 * servicio es el ÚNICO lugar que sabe "cuánto le toca a esta sucursal/al
 * general/a este colaborador", para que Web/Excel/PDF nunca puedan divergir.
 *
 * 100% EFÍMERO — nunca lee ni escribe BD. `$adjustment` viaja completo en la
 * request de cada consumidor (nunca se persiste).
 *
 * Forma canónica de `$adjustment`:
 *   [
 *     'mode'                 => 'employee' | 'branch_each_employee' | 'all_each_employee',
 *     'employee_id'          => int|null,   // solo mode=employee
 *     'branch_id'            => int|null,   // solo mode=branch_each_employee
 *     'amount_per_employee'  => float,      // SIEMPRE > 0 si el ajuste está activo
 *     'notes'                => string,
 *   ]
 *
 * A6 del cierre: los colaboradores que cuentan son los CANÓNICOS DEL PERIODO —
 * cada método recibe `$empGestoresRows` (RadiographySnapshotBuilder::
 * buildAllEmployeeGestorRows($period), la MISMA fila fusionada — con
 * `_employee_ids`/`branch` — que ya usa Web (sections.employees_gestores),
 * RadiografiaExportService (resolveEmployeeRow) y EmployeesHistoricoExportService
 * para listar colaboradores). Deliberadamente NUNCA se recibe/consulta un
 * `Period` aquí ni se hace una query propia de roster: así es IMPOSIBLE que el
 * conteo usado para multiplicar (sucursal/general) diverja del número de filas
 * que el Excel de colaboradores realmente exporta — misma fuente, un solo
 * cálculo. Nunca `Employee::where('is_active', true)` actual — un reporte de
 * Agosto 2026 nunca debe usar la plantilla de empleados de hoy.
 */
class TemporaryOpexAdjustmentService
{
    /**
     * Claves de identidad CANÓNICAS presentes en `$empGestoresRows` — opcionalmente
     * acotadas a una sucursal (por NOMBRE, resuelto una vez desde $branchId). Un
     * colaborador con varios IDs históricos fusionados en la misma fila
     * (`_employee_ids`) cuenta como UNA sola persona (su ID primario, `[0]`).
     *
     * Bug real confirmado (Orizaba, cierre 17-sep-2026 ronda 3): un gestor puede
     * aparecer en `employees_gestores` (colocación/recuperación por nombre) SIN
     * `_employee_ids` resuelto contra la tabla `employees` — antes esas filas se
     * descartaban EN SILENCIO de este conteo, así que la UI mostraba "Gestores
     * afectados: 28" (cuenta TODAS las filas de la sucursal, sin filtrar por
     * identidad) pero el Excel/PDF multiplicaba solo por 22 (6 gestores sin
     * employee_id resuelto desaparecían) — de ahí el "$22,000 en vez de $28,000"
     * reportado. Ahora cada fila cuenta exactamente una vez, tenga o no
     * `_employee_ids` resuelto, usando una clave sintética estable (posición +
     * nombre) para las que no lo tienen — así el conteo SIEMPRE coincide con
     * `sections.employees_gestores.length` que ve la UI.
     *
     * @param  array<int, array{branch?:string, name?:string, _employee_ids?:int[]}>  $empGestoresRows
     * @return array<int, int|string>
     */
    public function canonicalEmployeeIds(array $empGestoresRows, ?int $branchId = null): array
    {
        $rows = $empGestoresRows;

        if ($branchId !== null) {
            $branchName = Branch::find($branchId)?->name;
            if ($branchName === null) {
                return [];
            }
            $needle = mb_strtoupper(trim($branchName));
            $rows = array_filter($rows, fn ($r) => mb_strtoupper(trim((string) ($r['branch'] ?? ''))) === $needle);
        }

        $ids = [];
        foreach ($rows as $i => $r) {
            $primary = $r['_employee_ids'][0] ?? null;
            $ids[] = $primary
                ? (int) $primary
                : ('__sin_id_' . $i . '_' . mb_strtoupper(trim((string) ($r['name'] ?? ''))));
        }

        return array_values(array_unique($ids));
    }

    public function canonicalEmployeeCount(array $empGestoresRows, ?int $branchId = null): int
    {
        return count($this->canonicalEmployeeIds($empGestoresRows, $branchId));
    }

    /**
     * Normaliza y valida un `$adjustment` crudo (ej. recién armado desde la
     * request) — nunca confía en que el llamador ya lo validó. Devuelve null
     * si no hay ajuste activo (amount_per_employee <= 0, modo desconocido, o
     * falta el identificador que ese modo requiere).
     */
    public function normalize(?array $adjustment): ?array
    {
        if (!is_array($adjustment)) {
            return null;
        }

        $mode = $adjustment['mode'] ?? null;
        if (!in_array($mode, ['employee', 'branch_each_employee', 'all_each_employee'], true)) {
            return null;
        }

        $amount = round(max(0.0, (float) ($adjustment['amount_per_employee'] ?? 0)), 2);
        if ($amount <= 0) {
            return null;
        }

        $notes = trim((string) ($adjustment['notes'] ?? ''));

        if ($mode === 'employee') {
            $employeeId = (int) ($adjustment['employee_id'] ?? 0);
            if (!$employeeId) {
                return null;
            }

            return ['mode' => 'employee', 'employee_id' => $employeeId, 'branch_id' => null, 'amount_per_employee' => $amount, 'notes' => $notes];
        }

        if ($mode === 'branch_each_employee') {
            $branchId = (int) ($adjustment['branch_id'] ?? 0);
            if (!$branchId) {
                return null;
            }

            return ['mode' => 'branch_each_employee', 'employee_id' => null, 'branch_id' => $branchId, 'amount_per_employee' => $amount, 'notes' => $notes];
        }

        // all_each_employee
        return ['mode' => 'all_each_employee', 'employee_id' => null, 'branch_id' => null, 'amount_per_employee' => $amount, 'notes' => $notes];
    }

    /**
     * Monto TOTAL a sumar al agregado OPEX del alcance que se está viendo/exportando
     * (Web/Excel/PDF de ESE alcance) — 0.0 si el ajuste no aplica a este alcance.
     *
     * scope=employee: el monto completo, SOLO si mode=employee y coincide el
     *   employee_id exacto (nunca "de paso" a otro colaborador).
     * scope=branch: amount_per_employee × colaboradores canónicos de ESA sucursal,
     *   SOLO si mode=branch_each_employee y coincide el branch_id exacto.
     * scope=general: amount_per_employee × colaboradores canónicos de TODO el
     *   periodo, SOLO si mode=all_each_employee.
     *
     * @param  array<int, array{branch?:string, _employee_ids?:int[]}>  $empGestoresRows
     */
    public function totalForScope(array $empGestoresRows, ?array $adjustment, string $scopeType, ?int $branchId = null, ?int $employeeId = null): float
    {
        $adjustment = $this->normalize($adjustment);
        if (!$adjustment) {
            return 0.0;
        }

        if ($scopeType === 'employee') {
            if ($adjustment['mode'] === 'employee' && $employeeId !== null && $adjustment['employee_id'] === $employeeId) {
                return $adjustment['amount_per_employee'];
            }

            return 0.0;
        }

        if ($scopeType === 'branch') {
            if ($adjustment['mode'] === 'branch_each_employee' && $branchId !== null && $adjustment['branch_id'] === $branchId) {
                return round($adjustment['amount_per_employee'] * $this->canonicalEmployeeCount($empGestoresRows, $branchId), 2);
            }

            return 0.0;
        }

        // general
        if ($adjustment['mode'] === 'all_each_employee') {
            return round($adjustment['amount_per_employee'] * $this->canonicalEmployeeCount($empGestoresRows), 2);
        }

        return 0.0;
    }

    /**
     * Mapa employee_id (primario, `_employee_ids[0]`) => monto individual —
     * consumido por el Excel de colaboradores (A15): cada colaborador alcanzado
     * por el modo recibe EXACTAMENTE amount_per_employee (nunca repartido, nunca
     * multiplicado de nuevo). Las claves sintéticas de canonicalEmployeeIds()
     * (filas sin employee_id resuelto) también aparecen aquí, pero nunca se
     * consultan por el Excel de colaboradores — esa hoja ya excluye esas filas
     * por no poder resolver su OPEX automático (ver EmployeesHistoricoExportService).
     *
     * @param  array<int, array{branch?:string, _employee_ids?:int[]}>  $empGestoresRows
     * @return array<int|string, float>
     */
    public function perEmployeeAmounts(array $empGestoresRows, ?array $adjustment): array
    {
        $adjustment = $this->normalize($adjustment);
        if (!$adjustment) {
            return [];
        }

        if ($adjustment['mode'] === 'employee') {
            return [$adjustment['employee_id'] => $adjustment['amount_per_employee']];
        }

        if ($adjustment['mode'] === 'branch_each_employee') {
            $ids = $this->canonicalEmployeeIds($empGestoresRows, $adjustment['branch_id']);

            return array_fill_keys($ids, $adjustment['amount_per_employee']);
        }

        // all_each_employee
        $ids = $this->canonicalEmployeeIds($empGestoresRows);

        return array_fill_keys($ids, $adjustment['amount_per_employee']);
    }

    /** Cuántos colaboradores canónicos recibe este ajuste — para la UI ("Gestores afectados: 6"). */
    public function affectedEmployeeCount(array $empGestoresRows, ?array $adjustment): int
    {
        $adjustment = $this->normalize($adjustment);
        if (!$adjustment) {
            return 0;
        }

        if ($adjustment['mode'] === 'employee') {
            return 1;
        }

        return $adjustment['mode'] === 'branch_each_employee'
            ? $this->canonicalEmployeeCount($empGestoresRows, $adjustment['branch_id'])
            : $this->canonicalEmployeeCount($empGestoresRows);
    }
}
