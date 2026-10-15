<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Period;
use Illuminate\Support\Facades\DB;

/**
 * Roster canónico de empleados activos por periodo — fuente ÚNICA para derivar
 * Rotación de Personal e IMSS patronal desde NOI Nómina + NOI Nómina Fiscal.
 * NUNCA usa el reporte de empleados Lendus (usuarios irregulares/bajas mal
 * reflejadas). La sucursal se resuelve vía employee_branch_assignments del
 * periodo (ya poblado por EmployeeBranchAutoMatchService antes de que este
 * servicio corra), con fallback a la asignación histórica más reciente.
 *
 * Deduplicación: NOI normal y NOI fiscal frecuentemente crean DOS registros
 * `Employee` distintos para la misma persona real (distinto employee_code por
 * archivo). La identidad real del roster es `nombre_normalizado` — un mismo
 * nombre normalizado con dos employee_id (uno de cada archivo) cuenta como
 * UN solo colaborador, nunca dos. Sin este paso, la plantilla queda inflada
 * artificialmente (confirmado contra Junio 2026: 173 employee_id vs 125
 * nombres únicos).
 *
 * Idempotente: siempre borra el roster del periodo antes de reinsertar, para
 * que reprocesar no duplique filas.
 */
class PeriodEmployeeRosterService
{
    public function __construct(
        private readonly BranchResolverService $branchResolver,
        private readonly PersonIdentityResolverService $identityResolver,
    ) {}

    /**
     * @return array{active_count:int,altas:int,bajas:int,sin_sucursal:int,prev_period_id:?int,duplicados_fusionados:int}
     */
    public function buildForPeriod(Period $period): array
    {
        if (!$period->isMonthly()) {
            throw new \InvalidArgumentException('El roster de empleados solo se construye para periodos mensuales.');
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(empty($weeklyIds) ? [] : $weeklyIds, [$period->id])));

        // ── 1. Empleados (employee_id crudo) con movimiento NOI este periodo, por fuente ──
        $movementRows = DB::table('fact_noi_movements as fnm')
            ->join('report_uploads as ru', 'fnm.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('fnm.period_id', $dataIds)
            ->whereNotNull('fnm.employee_id')
            ->whereIn('ds.code', ['noi_nomina', 'noi_nomina_fiscal'])
            ->select('fnm.employee_id', 'ds.code as source_code')
            ->distinct()
            ->get();

        $byEmployeeId = [];
        foreach ($movementRows as $row) {
            $eid = (int) $row->employee_id;
            $byEmployeeId[$eid] ??= ['normal' => false, 'fiscal' => false];
            if ($row->source_code === 'noi_nomina') {
                $byEmployeeId[$eid]['normal'] = true;
            } elseif ($row->source_code === 'noi_nomina_fiscal') {
                $byEmployeeId[$eid]['fiscal'] = true;
            }
        }

        if (empty($byEmployeeId)) {
            DB::table('period_employee_rosters')->where('period_id', $period->id)->delete();
            return ['active_count' => 0, 'altas' => 0, 'bajas' => 0, 'sin_sucursal' => 0, 'prev_period_id' => null, 'duplicados_fusionados' => 0];
        }

        $rawEmployeeIds = array_keys($byEmployeeId);
        $employees      = Employee::query()->whereIn('id', $rawEmployeeIds)->get()->keyBy('id');

        // ── 2. Fusionar por nombre_normalizado — misma persona real, un solo roster row ──
        $byIdentity = []; // normalized_name => ['employee_ids'=>[], 'normal'=>bool,'fiscal'=>bool,'nombre_original'=>string]
        $duplicadosFusionados = 0;
        foreach ($byEmployeeId as $eid => $flags) {
            $emp = $employees->get($eid);
            if (!$emp) continue;
            $key = $emp->normalized_name ?: $this->identityResolver->normalizePersonName($emp->full_name);
            if ($key === '') $key = 'sin_nombre_' . $eid;

            if (isset($byIdentity[$key])) {
                $duplicadosFusionados++;
            }
            $byIdentity[$key] ??= ['employee_ids' => [], 'normal' => false, 'fiscal' => false, 'nombre_original' => $emp->full_name];
            $byIdentity[$key]['employee_ids'][] = $eid;
            $byIdentity[$key]['normal'] = $byIdentity[$key]['normal'] || $flags['normal'];
            $byIdentity[$key]['fiscal'] = $byIdentity[$key]['fiscal'] || $flags['fiscal'];
        }

        // ── 3. Periodo anterior — roster ya construido (para altas/bajas), por identidad ──
        $prevPeriod = $period->previousMonthly($allPeriods);
        $prevRosterByIdentity = collect();
        if ($prevPeriod) {
            $prevRosterByIdentity = DB::table('period_employee_rosters')
                ->where('period_id', $prevPeriod->id)
                ->where('is_active_for_period', true)
                ->get()
                ->keyBy(fn ($r) => $r->nombre_normalizado);
        }

        // ── 4. Resolver sucursal: cualquier employee_id del grupo, periodo actual → histórico ──
        $currentAssignments = DB::table('employee_branch_assignments')
            ->where('period_id', $period->id)
            ->whereIn('employee_id', $rawEmployeeIds)
            ->whereNotNull('branch_id')
            ->pluck('branch_id', 'employee_id');

        $historicalMap = [];
        $needFallback = array_values(array_diff($rawEmployeeIds, $currentAssignments->keys()->all()));
        if (!empty($needFallback)) {
            $historyRows = DB::table('employee_branch_assignments')
                ->whereIn('employee_id', $needFallback)
                ->whereNotNull('branch_id')
                ->orderByDesc('period_id')
                ->get(['employee_id', 'branch_id']);
            foreach ($historyRows as $hr) {
                $eid = (int) $hr->employee_id;
                if (!isset($historicalMap[$eid])) {
                    $historicalMap[$eid] = (int) $hr->branch_id;
                }
            }
        }

        $allBranchIds = array_values(array_unique(array_filter(array_merge(
            $currentAssignments->values()->all(),
            array_values($historicalMap)
        ))));
        $branches = empty($allBranchIds) ? collect() : Branch::query()->whereIn('id', $allBranchIds)->get()->keyBy('id');

        // ── 5. Construir filas activas (una por identidad única) ──
        $now = now();
        $activeRows  = [];
        $sinSucursal = 0;
        $altas       = 0;

        foreach ($byIdentity as $normalizedName => $data) {
            // Cualquier employee_id del grupo puede traer la asignación de sucursal.
            $branchId = null;
            foreach ($data['employee_ids'] as $eid) {
                if (isset($currentAssignments[$eid])) { $branchId = (int) $currentAssignments[$eid]; break; }
            }
            if ($branchId === null) {
                foreach ($data['employee_ids'] as $eid) {
                    if (isset($historicalMap[$eid])) { $branchId = $historicalMap[$eid]; break; }
                }
            }

            $branch      = $branchId ? $branches->get($branchId) : null;
            $branchName  = $branch?->name;
            $isOperativa = $branchName ? $this->branchResolver->isSheetBranch($branchName) : false;
            if (!$branchId) $sinSucursal++;

            $source = ($data['normal'] && $data['fiscal']) ? 'ambos' : ($data['fiscal'] ? 'noi_nomina_fiscal' : 'noi_nomina');
            $wasActivePrev = $prevRosterByIdentity->has($normalizedName);
            $movement = $wasActivePrev ? 'activo' : 'alta';
            if (!$wasActivePrev) $altas++;

            $primaryEmployeeId = $data['employee_ids'][0];

            $activeRows[] = [
                'period_id'                => $period->id,
                'employee_id'              => $primaryEmployeeId,
                'employee_key'             => $normalizedName,
                'nombre_normalizado'       => $normalizedName,
                'nombre_original'          => $data['nombre_original'],
                'branch_id'                => $branchId,
                'branch_name'              => $branchName,
                'is_branch_operativa'      => $isOperativa,
                'source'                   => $source,
                'appears_in_nomina_normal' => $data['normal'],
                'appears_in_nomina_fiscal' => $data['fiscal'],
                'is_active_for_period'     => true,
                'movement_type'            => $movement,
                // Un activo no necesita reconciliación — null explícito para que las
                // columnas calcen con $bajaRows en el insert() masivo (mismo set de
                // columnas en todas las filas, exigido por el query builder).
                'reconciliation_status'    => null,
                'reconciliation_note'      => null,
                'created_at'               => $now,
                'updated_at'               => $now,
            ];
        }

        // ── 6. Bajas: activos el periodo anterior, ausentes este periodo ──
        // Sección 6 de la auditoría (15-oct-2026): "no aparecer en la nómina de un
        // periodo no demuestra por sí solo una baja laboral". Antes de dar una baja
        // por confirmada se buscan dos señales objetivas, SIN inventar nada y SIN
        // tocar is_active_for_period/movement_type (eso lo sigue leyendo igual
        // RotacionDerivedFromNoiService — cero riesgo de romper el índice de
        // rotación ya correcto):
        //   a) ¿Hay un "alta" de ESTE periodo cuyo nombre normalizado es muy
        //      parecido al de la baja? Puede ser la misma persona con el nombre
        //      capturado distinto entre archivos (típico en NOI) — se marca como
        //      posible coincidencia, nunca se fusiona sola.
        //   b) ¿La plantilla total cayó de forma anómala vs el periodo anterior?
        //      Una caída masiva (>20%) es evidencia de un archivo incompleto ese
        //      mes, no de que todos esos colaboradores renunciaron el mismo día.
        $altaCandidates = [];
        foreach ($byIdentity as $normalizedName => $data) {
            if (!$prevRosterByIdentity->has($normalizedName)) {
                $altaCandidates[$normalizedName] = $data['nombre_original'];
            }
        }

        $bajaCandidates = [];
        foreach ($prevRosterByIdentity as $normalizedName => $prevRow) {
            if (!isset($byIdentity[$normalizedName])) {
                $bajaCandidates[$normalizedName] = $prevRow->nombre_original;
            }
        }

        // Emparejamiento de MEJOR MUTUO, no "cualquiera por encima del umbral":
        // nombres con plantilla compartida ("Empleado Prueba 8/9/10...") hacían que
        // UNA sola alta pareciera parecida a VARIAS bajas distintas a la vez — un
        // falso positivo real detectado al probar este cambio con datos sintéticos.
        // Una baja solo se sugiere emparejada si ES el mejor candidato para esa
        // alta Y esa alta ES el mejor candidato para esa baja, en ambos sentidos.
        $nameMatches = $this->bestMutualNameMatches($bajaCandidates, $altaCandidates);

        $prevActiveCount = $prevRosterByIdentity->count();
        $dropRatio = $prevActiveCount > 0 ? (($prevActiveCount - count($activeRows)) / $prevActiveCount) : 0.0;
        $massDropDetected = $prevActiveCount >= 10 && $dropRatio >= 0.20;

        $bajaRows = [];
        $posiblesCoincidencias = 0;
        foreach ($prevRosterByIdentity as $normalizedName => $prevRow) {
            if (isset($byIdentity[$normalizedName])) continue; // sigue activo

            [$reconciliationStatus, $reconciliationNote] = $this->classifyBaja(
                $prevRow->nombre_original, $nameMatches[$normalizedName] ?? null, $massDropDetected, $dropRatio
            );
            if ($reconciliationStatus === 'posible_coincidencia_nombre') {
                $posiblesCoincidencias++;
            }

            $bajaRows[] = [
                'period_id'                => $period->id,
                'employee_id'              => $prevRow->employee_id,
                'employee_key'             => $normalizedName,
                'nombre_normalizado'       => $normalizedName,
                'nombre_original'          => $prevRow->nombre_original,
                'branch_id'                => $prevRow->branch_id,
                'branch_name'              => $prevRow->branch_name,
                'is_branch_operativa'      => (bool) $prevRow->is_branch_operativa,
                'source'                   => $prevRow->source,
                'appears_in_nomina_normal' => false,
                'appears_in_nomina_fiscal' => false,
                'is_active_for_period'     => false,
                'movement_type'            => 'baja',
                'reconciliation_status'    => $reconciliationStatus,
                'reconciliation_note'      => $reconciliationNote,
                'created_at'               => $now,
                'updated_at'               => $now,
            ];
        }

        // ── 7. Persistir (idempotente: borra+inserta) ──
        DB::table('period_employee_rosters')->where('period_id', $period->id)->delete();
        $allRows = array_merge($activeRows, $bajaRows);
        foreach (array_chunk($allRows, 500) as $chunk) {
            DB::table('period_employee_rosters')->insert($chunk);
        }

        return [
            'active_count'          => count($activeRows),
            'altas'                 => $altas,
            'bajas'                 => count($bajaRows),
            'sin_sucursal'          => $sinSucursal,
            'prev_period_id'        => $prevPeriod?->id,
            'duplicados_fusionados' => $duplicadosFusionados,
            // Sección 6 de la auditoría (15-oct-2026) — nunca se presentan las bajas
            // como un hecho consumado sin que la pantalla pueda explicar el motivo.
            'mass_drop_detected'          => $massDropDetected,
            'drop_ratio'                  => round($dropRatio, 4),
            'posibles_coincidencias_nombre' => $posiblesCoincidencias,
        ];
    }

    /**
     * Empareja cada baja candidata con, a lo mucho, UNA alta candidata del mismo
     * periodo — solo cuando cada una es el MEJOR candidato de la otra (mutuo), no
     * simplemente "por encima de un umbral". Nombres con plantilla compartida
     * (ej. varios "Empleado Prueba N") pueden superar el umbral de similitud
     * contra la MISMA alta sin ser en realidad la misma persona — exigir que
     * también sea el mejor candidato EN SENTIDO INVERSO filtra esos falsos
     * positivos (confirmado al probar este cambio con datos sintéticos).
     *
     * @param array<string,string> $bajaCandidates normalizedName => nombreOriginal
     * @param array<string,string> $altaCandidates normalizedName => nombreOriginal
     * @return array<string,array{nombre:string,percent:float}> bajaNormalizedName => mejor alta
     */
    private function bestMutualNameMatches(array $bajaCandidates, array $altaCandidates): array
    {
        if (empty($bajaCandidates) || empty($altaCandidates)) {
            return [];
        }

        $bestAltaForBaja = []; // bajaKey => [altaKey, percent]
        $bestBajaForAlta = []; // altaKey => [bajaKey, percent]

        foreach ($bajaCandidates as $bajaKey => $bajaNombre) {
            foreach ($altaCandidates as $altaKey => $altaNombre) {
                similar_text($bajaKey, $altaKey, $percent);

                if (!isset($bestAltaForBaja[$bajaKey]) || $percent > $bestAltaForBaja[$bajaKey][1]) {
                    $bestAltaForBaja[$bajaKey] = [$altaKey, $percent];
                }
                if (!isset($bestBajaForAlta[$altaKey]) || $percent > $bestBajaForAlta[$altaKey][1]) {
                    $bestBajaForAlta[$altaKey] = [$bajaKey, $percent];
                }
            }
        }

        $matches = [];
        foreach ($bestAltaForBaja as $bajaKey => [$altaKey, $percent]) {
            $isMutualBest = ($bestBajaForAlta[$altaKey][0] ?? null) === $bajaKey;
            if ($isMutualBest && $percent >= 85.0) {
                $matches[$bajaKey] = ['nombre' => $altaCandidates[$altaKey], 'percent' => $percent];
            }
        }

        return $matches;
    }

    /**
     * Clasifica UNA baja candidata — nunca decide por sí sola, solo documenta la
     * confianza para que un humano reconcilie. Dos señales, en orden de prioridad:
     * nombre muy parecido (mutuamente) a un alta del mismo periodo (posible
     * mismatch), o caída masiva de plantilla (posible archivo incompleto). Si
     * ninguna aplica, se deja como "sin_contradiccion" — no hay evidencia de lo
     * contrario, pero tampoco es una confirmación positiva (no existe tal cosa en
     * un roster de nómina sin cruzarlo contra RH).
     *
     * @param array{nombre:string,percent:float}|null $nameMatch
     * @return array{0:string,1:string}
     */
    private function classifyBaja(string $nombreOriginal, ?array $nameMatch, bool $massDropDetected, float $dropRatio): array
    {
        if ($nameMatch !== null) {
            return ['posible_coincidencia_nombre', sprintf(
                '%s dejó de aparecer, pero "%s" es un alta nueva este periodo con un nombre %.0f%% similar — revisar si es la misma persona capturada distinto.',
                $nombreOriginal, $nameMatch['nombre'], $nameMatch['percent']
            )];
        }

        if ($massDropDetected) {
            return ['pendiente_conciliacion', sprintf(
                'La plantilla de este periodo cayó %.0f%% respecto al anterior — una caída así de grande sugiere un archivo NOI incompleto ese mes, no bajas reales simultáneas. Verificar la carga antes de confirmar esta baja.',
                $dropRatio * 100
            )];
        }

        return ['sin_contradiccion', 'No aparece en la nómina de este periodo y no hay una alta similar ni una caída masiva que lo explique — probable baja real, pero verifica con RH antes de tratarla como definitiva.'];
    }

    /**
     * Colaboradores válidos y deduplicados para ESTE periodo — fuente única para
     * selectores UI (ej. Etapa 4 "Buscar empleado o gestor"). A diferencia de
     * buildForPeriod() (que persiste en period_employee_rosters y calcula altas/bajas,
     * solo para periodos mensuales), este método funciona para cualquier tipo de
     * periodo y nunca escribe en BD:
     *   - Si el periodo es mensual y ya tiene roster persistido (se construye durante
     *     "Actualizar BD" — ver RotacionDerivedFromNoiService/ImssFromNoiFiscalService),
     *     lo reutiliza tal cual: cero recálculo.
     *   - En cualquier otro caso (periodo semanal, o mensual sin roster todavía)
     *     recalcula en memoria con la MISMA lógica de deduplicación por
     *     nombre_normalizado y resolución de sucursal que buildForPeriod().
     *
     * NUNCA usar Employee::all()/where('is_active',true) para poblar un selector de
     * colaboradores — eso mezcla bajas históricas de cualquier periodo y no deduplica
     * por identidad real (ver auditoría 2026-08-24: Etapa 4 mostraba a la misma
     * persona dos veces con una ruta como si fuera sucursal).
     *
     * @return array{rows: array<int, array{employee_id:int,name:string,branch_id:?int,branch_name:?string,is_branch_operativa:bool}>, sin_sucursal:int}
     */
    public function rosterRowsForSelector(Period $period): array
    {
        if ($period->isMonthly()) {
            $persisted = DB::table('period_employee_rosters')
                ->where('period_id', $period->id)
                ->where('is_active_for_period', true)
                ->orderBy('nombre_normalizado')
                ->get(['employee_id', 'nombre_normalizado', 'nombre_original', 'branch_id', 'branch_name', 'is_branch_operativa']);

            if ($persisted->isNotEmpty()) {
                return $this->formatSelectorRows($persisted->map(fn ($r) => [
                    'employee_id' => (int) $r->employee_id,
                    'name'        => $r->nombre_original,
                    'branch_id'   => $r->branch_id ? (int) $r->branch_id : null,
                    'branch_name' => $r->branch_name,
                    'is_branch_operativa' => (bool) $r->is_branch_operativa,
                ])->all());
            }
        }

        return $this->formatSelectorRows($this->computeRosterRowsOnTheFly($period));
    }

    private function formatSelectorRows(array $rows): array
    {
        $sinSucursal = 0;
        foreach ($rows as $r) {
            if (!$r['branch_id'] || !$r['is_branch_operativa']) {
                $sinSucursal++;
            }
        }
        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return ['rows' => $rows, 'sin_sucursal' => $sinSucursal];
    }

    /**
     * Misma deduplicación/resolución de sucursal que buildForPeriod(), pero sin
     * persistir y sin calcular altas/bajas — usable para periodos no mensuales.
     */
    private function computeRosterRowsOnTheFly(Period $period): array
    {
        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(empty($weeklyIds) ? [] : $weeklyIds, [$period->id])));

        $movementRows = DB::table('fact_noi_movements as fnm')
            ->whereIn('fnm.period_id', $dataIds)
            ->whereNotNull('fnm.employee_id')
            ->select('fnm.employee_id')
            ->distinct()
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($movementRows->isEmpty()) {
            return [];
        }

        $employees = Employee::query()->whereIn('id', $movementRows)->get()->keyBy('id');

        $byIdentity = [];
        foreach ($movementRows as $eid) {
            $emp = $employees->get($eid);
            if (!$emp) continue;
            $key = $emp->normalized_name ?: $this->identityResolver->normalizePersonName($emp->full_name);
            if ($key === '') $key = 'sin_nombre_' . $eid;
            $byIdentity[$key] ??= ['employee_ids' => [], 'nombre_original' => $emp->full_name];
            $byIdentity[$key]['employee_ids'][] = $eid;
        }

        $currentAssignments = DB::table('employee_branch_assignments')
            ->where('period_id', $period->id)
            ->whereIn('employee_id', $movementRows)
            ->whereNotNull('branch_id')
            ->pluck('branch_id', 'employee_id');

        $needFallback = array_values(array_diff($movementRows->all(), $currentAssignments->keys()->all()));
        $historicalMap = [];
        if (!empty($needFallback)) {
            DB::table('employee_branch_assignments')
                ->whereIn('employee_id', $needFallback)
                ->whereNotNull('branch_id')
                ->orderByDesc('period_id')
                ->get(['employee_id', 'branch_id'])
                ->each(function ($hr) use (&$historicalMap) {
                    $eid = (int) $hr->employee_id;
                    $historicalMap[$eid] ??= (int) $hr->branch_id;
                });
        }

        $allBranchIds = array_values(array_unique(array_filter(array_merge(
            $currentAssignments->values()->all(), array_values($historicalMap)
        ))));
        $branches = empty($allBranchIds) ? collect() : Branch::query()->whereIn('id', $allBranchIds)->get()->keyBy('id');

        $rows = [];
        foreach ($byIdentity as $data) {
            $branchId = null;
            foreach ($data['employee_ids'] as $eid) {
                if (isset($currentAssignments[$eid])) { $branchId = (int) $currentAssignments[$eid]; break; }
            }
            if ($branchId === null) {
                foreach ($data['employee_ids'] as $eid) {
                    if (isset($historicalMap[$eid])) { $branchId = $historicalMap[$eid]; break; }
                }
            }
            $branch     = $branchId ? $branches->get($branchId) : null;
            $branchName = $branch?->name;

            $rows[] = [
                'employee_id' => $data['employee_ids'][0],
                'name'        => $data['nombre_original'],
                'branch_id'   => $branchId,
                'branch_name' => $branchName,
                'is_branch_operativa' => $branchName ? $this->branchResolver->isSheetBranch($branchName) : false,
            ];
        }

        return $rows;
    }
}
