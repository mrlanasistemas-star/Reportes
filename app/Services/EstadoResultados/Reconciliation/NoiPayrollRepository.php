<?php

namespace App\Services\EstadoResultados\Reconciliation;

use App\Services\EstadoResultados\Support\Money;
use App\Services\EstadoResultados\Support\PersonName;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Lectura de SÓLO LECTURA de la nómina NOI (fact_noi_movements, percepciones) con el
 * MISMO universo que Radiografía (BranchRadiographyCalculator::accumulateNomina):
 * period_id ∈ dataIds, concept_type = percepcion, sucursal vía employee_branch_assignments
 * con match exacto de period_id.
 *
 * Radiografía suma TODO el NOI al GLOBAL (las sucursales operativas por sucursal y el
 * resto — sin asignación o asignado a una sucursal no operativa como CORPORATIVO — en
 * el bucket "unassigned"). Por eso un colaborador NOI ya está reconocido en el nivel 1
 * (Nómina sucursales) del ER y un pago bancario de su nómina NO debe crear otro gasto.
 */
class NoiPayrollRepository
{
    public function __construct(private readonly BranchRadiographyCalculator $branchCalculator) {}

    /**
     * @return array{
     *   employees: array<string, array{employee_id:int, name:string, branch:?string, operative:bool, total:string}>,
     *   ambiguous_keys: list<string>,
     *   total: string,
     *   unassigned_total: string,
     *   unassigned_employees: int,
     *   employees_count: int
     * }
     */
    public function forDataIds(array $dataIds): array
    {
        $empty = ['employees' => [], 'ambiguous_keys' => [], 'total' => '0.00', 'unassigned_total' => '0.00', 'unassigned_employees' => 0, 'employees_count' => 0];
        if ($dataIds === []) {
            return $empty;
        }

        $operative = $this->branchCalculator->buildBranchMap()['operative'];
        $branchNames = DB::table('branches')->pluck('name', 'id')->all();

        $rows = DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', function ($join) {
                $join->on('n.employee_id', '=', 'eba.employee_id')->on('eba.period_id', '=', 'n.period_id');
            })
            ->join('employees as e', 'e.id', '=', 'n.employee_id')
            ->whereIn('n.period_id', $dataIds)
            ->where('n.concept_type', 'percepcion')
            ->groupBy('n.employee_id', 'e.full_name', 'eba.branch_id')
            ->orderBy('n.employee_id')
            ->get(['n.employee_id', 'e.full_name', 'eba.branch_id', DB::raw('SUM(n.amount) as total')]);

        if ($rows->isEmpty()) {
            return $empty;
        }

        $employees = [];
        $ambiguous = [];
        $total = Money::zero();
        $unassigned = Money::zero();
        $unassignedEmployees = [];
        foreach ($rows as $r) {
            $amount = Money::of($r->total);
            $total = $total->plus($amount);
            $branchId = $r->branch_id !== null ? (int) $r->branch_id : null;
            $isOperative = $branchId !== null && isset($operative[$branchId]);
            if (! $isOperative) {
                $unassigned = $unassigned->plus($amount);
                $unassignedEmployees[(int) $r->employee_id] = true;
            }
            $key = PersonName::key($r->full_name);
            if ($key === null) {
                continue;
            }
            if (isset($employees[$key]) && $employees[$key]['employee_id'] !== (int) $r->employee_id) {
                $ambiguous[$key] = true; // dos colaboradores con el mismo nombre: no se usa para vincular
            }
            $prev = $employees[$key]['total'] ?? '0';
            $employees[$key] = [
                'employee_id' => (int) $r->employee_id,
                'name' => (string) $r->full_name,
                'branch' => $isOperative ? $operative[$branchId] : ($branchId !== null ? ($branchNames[$branchId] ?? null) : null),
                'operative' => $isOperative,
                'total' => Money::str2(Money::of($prev)->plus($amount)),
            ];
        }
        ksort($employees, SORT_STRING);
        $ambiguousKeys = array_keys($ambiguous);
        sort($ambiguousKeys, SORT_STRING);

        return [
            'employees' => $employees,
            'ambiguous_keys' => $ambiguousKeys,
            'total' => Money::str2($total),
            'unassigned_total' => Money::str2($unassigned),
            'unassigned_employees' => count($unassignedEmployees),
            'employees_count' => count(array_unique(array_column($employees, 'employee_id'))),
        ];
    }

    /**
     * Colaborador NOI cuyo nombre coincide EXACTAMENTE (llave de palabras) con el texto.
     * $containment = true: el nombre completo (≥ 3 palabras) debe estar contenido en el texto.
     *
     * @return array{status:'unico'|'ambiguo'|'ninguno', employee:?array}
     */
    public static function match(array $noi, ?string $text, bool $containment = false): array
    {
        if ($text === null || trim($text) === '' || $noi['employees'] === []) {
            return ['status' => 'ninguno', 'employee' => null];
        }
        if (! $containment) {
            $key = PersonName::key($text);
            if ($key === null || ! isset($noi['employees'][$key])) {
                return ['status' => 'ninguno', 'employee' => null];
            }

            return in_array($key, $noi['ambiguous_keys'], true)
                ? ['status' => 'ambiguo', 'employee' => $noi['employees'][$key]]
                : ['status' => 'unico', 'employee' => $noi['employees'][$key]];
        }

        $tokens = array_flip(PersonName::tokens($text));
        $found = [];
        foreach ($noi['employees'] as $key => $emp) {
            $parts = explode(' ', $key);
            if (count($parts) < 3) {
                continue;
            }
            if (array_diff_key(array_flip($parts), $tokens) === []) {
                $found[] = $key;
            }
        }
        if ($found === []) {
            return ['status' => 'ninguno', 'employee' => null];
        }
        if (count($found) > 1 || in_array($found[0], $noi['ambiguous_keys'], true)) {
            return ['status' => 'ambiguo', 'employee' => $noi['employees'][$found[0]]];
        }

        return ['status' => 'unico', 'employee' => $noi['employees'][$found[0]]];
    }
}
