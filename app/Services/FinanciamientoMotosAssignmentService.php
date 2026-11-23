<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Period;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Resuelve y persiste employee_id/branch_id en los renglones de fact_expenses de
 * "PAGO FINANCIAMIENTO MOTO" / "COMPRA DE CASCOS" (fuente gastos_lendus_excel; al
 * importar, branch_id siempre queda NULL porque ese archivo no trae columna de sucursal).
 *
 * Regla de negocio: cada renglón debe quedar ligado a un colaborador real y a una de
 * las sucursales operativas — son gastos de personas reales, nunca un bolsón genérico
 * "sin asignar"/"no operativo". La resolución nunca inventa sucursal: solo acepta una
 * sucursal que provenga de una asignación empleado→sucursal confirmada (periodo actual,
 * histórica, duplicado canónico/alias, o directorio de empleados Lendus).
 *
 * Lo usa el pipeline de generación de la radiografía (assignForPeriodOrFail) y los
 * comandos de auditoría/reparación (assignForPeriod, con opción de simulación).
 */
class FinanciamientoMotosAssignmentService
{
    /** Conceptos (en mayúsculas, sin espacios extremos) que cubre este servicio. */
    public const CONCEPTS = ['PAGO FINANCIAMIENTO MOTO', 'COMPRA DE CASCOS'];

    /**
     * @param  PersonIdentityResolverService  $personResolver  Búsqueda de colaboradores por nombre y de sus sucursales.
     * @param  BranchRadiographyCalculator  $branchCalculator  Provee el mapa de sucursales operativas (id => nombre).
     */
    public function __construct(
        private readonly PersonIdentityResolverService $personResolver,
        private readonly BranchRadiographyCalculator $branchCalculator,
    ) {}

    /**
     * Resuelve todos los renglones de Financiamiento de Motos/Cascos de un periodo y
     * persiste employee_id/branch_id en fact_expenses.
     *
     * Solo considera gastos de la fuente gastos_lendus_excel con categoría
     * "Nómina y Capital Humano" y concepto en CONCEPTS. El monto reportado es
     * paid_amount (si no es 0) o amount. Es idempotente: los renglones que ya tienen
     * empleado y sucursal operativa se reportan como "ya_asignado" y no se tocan.
     * El nombre del colaborador se toma del campo observations.
     *
     * Efectos secundarios: actualiza fact_expenses (employee_id, branch_id, updated_at)
     * salvo en modo $dryRun.
     *
     * @param  Period  $period  Periodo que se procesa (no se usa en la consulta; el alcance lo dan $dataIds).
     * @param  array<int>  $dataIds  IDs de periodos cuyos datos se consideran (periodo y semanas base).
     * @param  bool  $dryRun  true = solo calcula, no escribe en BD.
     * @return array<int, array{
     *   fact_expense_id:int, concept:string, amount:float, period_id:int, report_upload_id:int,
     *   nombre_original:string, empleado_encontrado:?string, employee_id:?int,
     *   sucursal:?string, branch_id:?int, metodo:string, confianza:float, estado:string,
     * }>
     */
    public function assignForPeriod(Period $period, array $dataIds, bool $dryRun = false): array
    {
        $operativeMap = $this->branchCalculator->buildBranchMap()['operative'];

        $lendusExcelId = DB::table('data_sources')->where('code', 'gastos_lendus_excel')->value('id');
        if (! $lendusExcelId) {
            return [];
        }

        $rows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $lendusExcelId)
            ->where('e.category', 'Nómina y Capital Humano')
            ->whereIn(DB::raw("UPPER(TRIM(COALESCE(e.concept,'')))"), self::CONCEPTS)
            ->select(
                'e.id', 'e.period_id', 'e.report_upload_id', 'e.employee_id', 'e.branch_id',
                'e.concept', 'e.observations',
                DB::raw('COALESCE(NULLIF(e.paid_amount,0), e.amount) as amount')
            )
            ->get();

        $results = [];

        foreach ($rows as $row) {
            $concept = trim((string) $row->concept);
            $amount = (float) $row->amount;

            if ($row->employee_id && $row->branch_id && isset($operativeMap[$row->branch_id])) {
                $employee = Employee::find($row->employee_id);
                $results[] = [
                    'fact_expense_id' => $row->id,
                    'concept' => $concept,
                    'amount' => $amount,
                    'period_id' => $row->period_id,
                    'report_upload_id' => $row->report_upload_id,
                    'nombre_original' => trim((string) $row->observations),
                    'empleado_encontrado' => $employee?->full_name,
                    'employee_id' => $row->employee_id,
                    'sucursal' => $operativeMap[$row->branch_id],
                    'branch_id' => $row->branch_id,
                    'metodo' => 'ya_asignado',
                    'confianza' => 1.0,
                    'estado' => 'ya_asignado',
                ];

                continue;
            }

            $resolution = $this->resolve((string) $row->observations, $dataIds, $operativeMap);

            if ($resolution !== null) {
                if (! $dryRun) {
                    DB::table('fact_expenses')->where('id', $row->id)->update([
                        'employee_id' => $resolution['employee_id'],
                        'branch_id' => $resolution['branch_id'],
                        'updated_at' => now(),
                    ]);
                }
                $results[] = [
                    'fact_expense_id' => $row->id,
                    'concept' => $concept,
                    'amount' => $amount,
                    'period_id' => $row->period_id,
                    'report_upload_id' => $row->report_upload_id,
                    'nombre_original' => trim((string) $row->observations),
                    'empleado_encontrado' => $resolution['employee_name'],
                    'employee_id' => $resolution['employee_id'],
                    'sucursal' => $resolution['branch_name'],
                    'branch_id' => $resolution['branch_id'],
                    'metodo' => $resolution['method'],
                    'confianza' => $resolution['confidence'],
                    'estado' => 'resuelto',
                ];
            } else {
                $results[] = [
                    'fact_expense_id' => $row->id,
                    'concept' => $concept,
                    'amount' => $amount,
                    'period_id' => $row->period_id,
                    'report_upload_id' => $row->report_upload_id,
                    'nombre_original' => trim((string) $row->observations),
                    'empleado_encontrado' => null,
                    'employee_id' => null,
                    'sucursal' => null,
                    'branch_id' => null,
                    'metodo' => 'sin_resolver',
                    'confianza' => 0.0,
                    'estado' => 'sin_resolver',
                ];
            }
        }

        return $results;
    }

    /**
     * Igual que assignForPeriod() (siempre escribe en BD), pero lanza excepción con un
     * mensaje accionable (id del registro, texto original, monto, carga) si QUEDA algún
     * renglón sin resolver. La usa el pipeline de generación para detenerse antes de
     * armar el reporte en lugar de mandar el monto en silencio a "sin asignar"/"no operativo".
     *
     * @param  Period  $period  Periodo (su etiqueta se usa en el mensaje de error).
     * @param  array<int>  $dataIds  IDs de periodos cuyos datos se consideran.
     * @return array<int, array<string, mixed>> Mismo resultado que assignForPeriod().
     *
     * @throws \RuntimeException Si uno o más renglones no se pudieron resolver.
     */
    public function assignForPeriodOrFail(Period $period, array $dataIds): array
    {
        $results = $this->assignForPeriod($period, $dataIds);
        $unresolved = array_filter($results, fn (array $r) => $r['estado'] === 'sin_resolver');

        if (! empty($unresolved)) {
            $detail = collect($unresolved)->map(fn (array $r) => sprintf(
                '  - fact_expenses.id=%d | %s | "%s" | $%s | report_upload_id=%d',
                $r['fact_expense_id'], $r['concept'], $r['nombre_original'], number_format($r['amount'], 2), $r['report_upload_id']
            ))->implode("\n");

            throw new \RuntimeException(
                'No se pudo determinar empleado/sucursal para '.count($unresolved).
                " registro(s) de Financiamiento de Motos/Cascos del periodo {$period->label} — ".
                'generación detenida (no se oculta ni se inventa sucursal). Corrige el nombre o crea/asigna '.
                "la sucursal del colaborador en el panel de Asignación de Sucursal y vuelve a generar:\n".$detail
            );
        }

        return $results;
    }

    /**
     * Resuelve colaborador + sucursal operativa a partir del texto crudo de `observations`.
     *
     * Orden de búsqueda sobre cada segmento candidato del texto:
     *  1. Coincidencia exacta de nombre (o mismas palabras reordenadas) → confianza 1.0.
     *  2. Mejor coincidencia difusa (según PersonIdentityResolverService) entre todos
     *     los segmentos, solo si ninguna exacta resolvió sucursal.
     *  3. Directorio de empleados Lendus (exacto o difuso ≥85%).
     * Devuelve null solo si ninguna fuente produce una sucursal operativa conocida.
     *
     * @param  string  $rawObservations  Texto de observations del gasto (puede traer "NOMBRE | nota").
     * @param  array<int>  $dataIds  IDs de periodos para buscar la asignación del periodo actual.
     * @param  array<int, string>|null  $operativeMap  Mapa id=>nombre de sucursales operativas; si es null se calcula.
     * @return array<string, mixed>|null employee_id, employee_name, branch_id, branch_name, method, confidence.
     */
    public function resolve(string $rawObservations, array $dataIds, ?array $operativeMap = null): ?array
    {
        $operativeMap ??= $this->branchCalculator->buildBranchMap()['operative'];
        $candidates = $this->extractNameCandidates($rawObservations);
        if (empty($candidates)) {
            return null;
        }

        // Pass 1: prefer an EXACT (or same-words-reordered) match across every candidate
        // segment — never let a merely-fuzzy match on one segment pre-empt an exact match
        // available on another segment of the same observations string.
        $bestFuzzy = null;
        foreach ($candidates as $name) {
            $match = $this->personResolver->findExistingPersonMatch($name);
            if (! $match) {
                continue;
            }
            if (in_array($match['method'], ['exact_name', 'exact_name_reordered'], true)) {
                $resolved = $this->resolveBranchForEmployee($match['employee'], $dataIds, $operativeMap);
                if ($resolved) {
                    return $resolved + ['method' => $match['method'], 'confidence' => 1.0];
                }
            } elseif ($bestFuzzy === null || $match['score'] > $bestFuzzy['match']['score']) {
                $bestFuzzy = ['name' => $name, 'match' => $match];
            }
        }

        // Pass 2: best fuzzy (≥80%) match across all segments, only if no exact match resolved.
        if ($bestFuzzy) {
            $resolved = $this->resolveBranchForEmployee($bestFuzzy['match']['employee'], $dataIds, $operativeMap);
            if ($resolved) {
                return $resolved + ['method' => 'fuzzy_name', 'confidence' => round($bestFuzzy['match']['score'] / 100, 2)];
            }
        }

        // Pass 3: Lendus employee directory (exact, then fuzzy ≥85%), any segment.
        foreach ($candidates as $name) {
            $resolved = $this->resolveViaLendusDirectory($name, $operativeMap);
            if ($resolved) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Divide "NOMBRE | nota libre" (en cualquier orden) en todos sus segmentos no vacíos,
     * quitando de cada uno los prefijos conocidos del concepto ("PAGO DE FINANCIAMIENTO
     * DE MOTO", "COMPRA DE CASCOS"). El Excel de origen es inconsistente respecto a qué
     * lado del '|' trae el nombre completo, por eso se prueban todos.
     *
     * @param  string  $rawObservations  Texto crudo de observations.
     * @return array<int, string> Segmentos candidatos únicos, en orden de aparición.
     */
    private function extractNameCandidates(string $rawObservations): array
    {
        $segments = array_map('trim', explode('|', $rawObservations));
        $cleaned = [];
        foreach ($segments as $seg) {
            $seg = trim((string) preg_replace(
                '/^(PAGO\s+)?(DE\s+)?FINANCIAMIENTO\s+(DE\s+)?MOTO\s*|^COMPRA\s+DE\s+CASCOS\s*/i',
                '',
                $seg
            ));
            if ($seg !== '') {
                $cleaned[] = $seg;
            }
        }

        return array_values(array_unique($cleaned));
    }

    /**
     * Busca la sucursal operativa de un colaborador ya identificado.
     *
     * Prioridad: 1) su asignación del periodo actual ($dataIds, la más reciente);
     * 2) su asignación histórica más reciente; 3) la sucursal de su registro canónico
     * duplicado o de un alias confirmado. Solo acepta sucursales del mapa operativo.
     *
     * @param  Employee  $employee  Colaborador encontrado por nombre.
     * @param  array<int>  $dataIds  IDs de periodos del periodo actual.
     * @param  array<int, string>  $operativeMap  Mapa id=>nombre de sucursales operativas.
     * @return array{employee_id: int, employee_name: ?string, branch_id: int, branch_name: string}|null
     */
    private function resolveBranchForEmployee(Employee $employee, array $dataIds, array $operativeMap): ?array
    {
        // 1. This employee's own assignment for the current period (confirmed or automatic).
        $branchId = DB::table('employee_branch_assignments')
            ->where('employee_id', $employee->id)
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('branch_id')
            ->whereIn('branch_id', array_keys($operativeMap))
            ->orderByDesc('period_id')
            ->value('branch_id');

        // 2. Most recent historical assignment (any other period).
        $branchId = $branchId ?: $this->personResolver->resolveBranchFromExistingAssignments($employee->id);

        // 3. Canonical duplicate employee record / confirmed alias.
        $branchId = $branchId
            ?: $this->personResolver->resolveBranchFromCanonicalEmployee($employee)
            ?: $this->personResolver->resolveBranchFromAlias($employee);

        if ($branchId && isset($operativeMap[(int) $branchId])) {
            return [
                'employee_id' => $employee->id,
                'employee_name' => $employee->full_name,
                'branch_id' => (int) $branchId,
                'branch_name' => $operativeMap[(int) $branchId],
            ];
        }

        return null;
    }

    /**
     * Último recurso: busca el nombre en el directorio de empleados Lendus operativos.
     *
     * Toma la coincidencia exacta del nombre normalizado o la mejor similitud (mínimo 85%).
     * La sucursal sale de inferred_branch_id o, si falta, del código Lendus resuelto con
     * BranchResolverService y buscado por nombre en branches. Ojo: devuelve employee_id
     * null (el directorio Lendus no está ligado a employees), por lo que el gasto queda
     * con sucursal pero sin colaborador.
     *
     * Consulta todo lendus_employee_directory en cada llamada (sin caché).
     *
     * @param  string  $name  Segmento candidato con el nombre del colaborador.
     * @param  array<int, string>  $operativeMap  Mapa id=>nombre de sucursales operativas.
     * @return array<string, mixed>|null employee_id (null), employee_name, branch_id, branch_name, method, confidence.
     */
    private function resolveViaLendusDirectory(string $name, array $operativeMap): ?array
    {
        $normalized = $this->personResolver->normalizePersonName($name);
        if ($normalized === '') {
            return null;
        }

        $best = null;
        $bestScore = 0.0;
        foreach (DB::table('lendus_employee_directory')
            ->where('is_operational', true)
            ->whereNotNull('normalized_name')
            ->get(['codigo', 'nombre', 'normalized_name', 'inferred_branch_id']) as $rec
        ) {
            if ($rec->normalized_name === $normalized) {
                $best = $rec;
                $bestScore = 100.0;
                break;
            }
            $score = $this->personResolver->scoreNameSimilarity($normalized, (string) $rec->normalized_name);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $rec;
            }
        }

        if (! $best || $bestScore < 85.0) {
            return null;
        }

        $branchId = $best->inferred_branch_id ? (int) $best->inferred_branch_id : null;
        if (! $branchId && $best->codigo) {
            $branchResolver = app(BranchResolverService::class);
            $branchName = $branchResolver->resolveBranchNameFromCode((string) $best->codigo);
            if ($branchName) {
                $branchId = DB::table('branches')
                    ->whereRaw('UPPER(TRIM(name)) = ?', [strtoupper(trim($branchName))])
                    ->value('id');
                $branchId = $branchId ? (int) $branchId : null;
            }
        }

        if ($branchId && isset($operativeMap[$branchId])) {
            return [
                'employee_id' => null,
                'employee_name' => $best->nombre,
                'branch_id' => $branchId,
                'branch_name' => $operativeMap[$branchId],
                'method' => $bestScore >= 100.0 ? 'lendus_directory_exact' : 'lendus_directory_fuzzy',
                'confidence' => round($bestScore / 100, 2),
            ];
        }

        return null;
    }
}
