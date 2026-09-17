<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Identidad canónica de colaborador — cierre 17-sep-2026 (ronda 2, C1/C2).
 *
 * Extraído de `OkrEmployeeBranchResolver::canonicalize()` (ronda 1) para que la
 * MISMA regla de agrupación quede en un solo lugar reutilizable — hoy la usa
 * OkrEmployeeBranchResolver; queda disponible para cualquier otro consumidor
 * que necesite "colapsar registros `Employee` duplicados en una sola persona"
 * sin reinventar la regla.
 *
 * REGLA: se agrupa por `normalized_name` EXACTO. Dos registros `Employee` con
 * el mismo `normalized_name` se tratan como la MISMA persona (duplicado
 * histórico — ej. una fuente NOI vs NOI Fiscal que nunca se unificaron). El id
 * canónico expuesto es el MENOR del grupo (el más antiguo/estable) — siempre
 * el mismo para la misma persona en llamadas sucesivas.
 *
 * ESTA ES LA MISMA TÉCNICA QUE YA USA REPORTERÍA EN PRODUCCIÓN — no es una
 * regla nueva inventada para OKR — confirmado leyendo:
 *   - EmployeeBranchAutoMatchService::buildCanonicalIndex() — agrupa
 *     `DB::table('employees')` por `normalized_name` explícitamente para
 *     encontrar "Other Employee rows sharing the same normalized_name
 *     (duplicate records from NOI vs NOI Fiscal that were never unified)".
 *   - RadiographySnapshotBuilder::buildEmployeesGestores() — construye
 *     `$employeeIdsByNorm` agrupando por `EmployeeNameCanonicalizer::normalize()`
 *     del nombre, exactamente el mismo criterio.
 *   - PersonIdentityResolverService::resolveBranchFromCanonicalEmployee() —
 *     "Find all employee IDs with the same normalized_name (canonical
 *     duplicate)".
 *
 * LIMITACIÓN REAL Y CONOCIDA (documentada explícitamente, no oculta): dos
 * personas REALES distintas que compartan el normalized_name EXACTO (mismo
 * nombre completo, letra por letra, tras acentos/mayúsculas) se agruparían
 * como una sola bajo esta regla — Y BAJO LA MISMA REGLA YA VIGENTE EN
 * REPORTERÍA (los 3 servicios de arriba tienen la MISMA limitación; no es un
 * problema exclusivo de OKR). Resolverlo de verdad requeriría una señal de
 * desambiguación que hoy NO existe en el esquema (ej. CURP, fecha de
 * nacimiento, o un ID canónico ya calculado y persistido por Reportería) — no
 * se inventa aquí una heurística nueva (ej. fuzzy-match por similitud de texto)
 * porque eso sería MÁS agresivo, no menos: fusionaría incluso nombres
 * "parecidos", aumentando el riesgo de mezclar personas reales distintas, y
 * DIVERGIRÍA de la regla exacta que ya usa el resto de Reportería. Mientras el
 * dato no exista, "mismo normalized_name" es la regla honesta más estricta
 * disponible — y la única compatible con `findEmployeeGestorRowByEmployeeId()`,
 * que resuelve por PERTENENCIA a un grupo, no por un único ID "verdadero" — así
 * que min(id) es una elección de estabilidad determinista, no una regla que
 * Reportería exija distinta (C4 del cierre: verificado, no existe tal regla).
 *
 * @template TRow of object{id:int, normalized_name:?string}
 */
class CanonicalEmployeeResolver
{
    /**
     * Agrupa filas con `id`/`normalized_name` (ej. `Employee` o cualquier
     * proyección con esos dos campos) en identidades canónicas. Un registro sin
     * normalized_name se trata como su PROPIA identidad — nunca se fusiona a
     * ciegas con otro solo por tener el campo vacío.
     *
     * @param  Collection<int, object{id:int, normalized_name:?string, full_name?:?string}>  $rows
     * @return Collection<int, object{id:int, full_name:?string, employee_ids:int[]}>
     */
    public function canonicalize(Collection $rows): Collection
    {
        $byIdentity = [];
        foreach ($rows as $row) {
            $norm = trim((string) ($row->normalized_name ?? ''));
            $key  = $norm !== '' ? $norm : ('__employee_' . $row->id);

            if (!isset($byIdentity[$key])) {
                $byIdentity[$key] = ['id' => (int) $row->id, 'full_name' => $row->full_name ?? null, 'employee_ids' => []];
            }
            $byIdentity[$key]['id'] = min($byIdentity[$key]['id'], (int) $row->id);
            $byIdentity[$key]['employee_ids'][] = (int) $row->id;
        }

        return collect(array_values($byIdentity))->map(fn ($r) => (object) $r);
    }
}
